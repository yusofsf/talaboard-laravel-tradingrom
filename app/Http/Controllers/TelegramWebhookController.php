<?php

namespace App\Http\Controllers;

use App\Jobs\ExpireTelegramOffer;
use App\Jobs\ProcessTelegramCallback;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\DepositRequest;
use App\Models\TelegramState;
use App\Models\Trade;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\TalaboardClient;
use App\Services\TelegramConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TelegramWebhookController extends Controller
{
    private const MAIN_MENU = [
        ['قیمت لحظه‌ای', 'ثبت معامله'],
        ['واریز وجه', 'معاملات من'],
        ['سوابق من', 'نام مستعار'],
        ['بیعانه دارایی', 'وضعیت عضویت'],
        ['کانال‌های خرید و فروش', 'ثبت نام عضویت ویژه'],
        ['کیف پول و دارایی‌ها', 'افزایش موجودی انبار'],
    ];

    private const WELCOME_MESSAGE = "به ربات معاملات برخط طلا و نقره خوش آمدید.\n\nاگر حساب سایت هنوز متصل نیست، کد اتصال را با این فرمت بفرستید:\n\n/connect CODE";

    private string $traceId = '';

    private ?string $lastSiteError = null;

    private array $membershipByChat = [];

    private bool $callbackPreAnswered = false;

    private int|string|null $callbackChatId = null;

    private bool $captureWebhookReply = false;

    private ?array $webhookReply = null;

    private function fastStartWebhookReply(Request $request): ?array
    {
        if (! config('services.telegram.async_webhook', true)
            || ! config('services.telegram.fast_webhook_reply', true)) {
            return null;
        }

        $text = trim((string) $request->input('message.text', ''));
        $chatId = $request->input('message.chat.id');

        // /start is the bot's cold path. Keep it independent from the
        // database, cache and outbound HTTP so Telegram can deliver the
        // welcome message in the webhook response itself.
        if (! preg_match('/^\/start(?:@[A-Za-z0-9_]+)?$/', $text) || blank($chatId)) {
            return null;
        }

        return [
            'method' => 'sendMessage',
            'chat_id' => $chatId,
            'text' => self::WELCOME_MESSAGE,
            'reply_markup' => [
                'keyboard' => self::MAIN_MENU,
                'resize_keyboard' => true,
            ],
        ];
    }

    private function fastLivePriceWebhookReply(Request $request, TalaboardClient $prices): ?array
    {
        if (! config('services.telegram.async_webhook', true)
            || ! config('services.telegram.fast_webhook_reply', true)
            || trim((string) $request->input('message.text', '')) !== 'قیمت لحظه‌ای') {
            return null;
        }

        $chatId = $request->input('message.chat.id');
        if (blank($chatId)) {
            return null;
        }

        // The public price feed does not need a user or membership lookup.
        // Keeping this path ahead of database-backed ingress bookkeeping also
        // prevents an expired membership cache from delaying the reply.
        return [
            'method' => 'sendMessage',
            'chat_id' => $chatId,
            'text' => $this->livePricesText($prices),
            'reply_markup' => [
                'keyboard' => self::MAIN_MENU,
                'resize_keyboard' => true,
            ],
        ];
    }

    private function captureSendMessage(array $data): bool
    {
        if (! $this->captureWebhookReply || $this->webhookReply !== null) {
            return false;
        }

        $this->webhookReply = [
            'method' => 'sendMessage',
            ...array_filter($data, static fn ($value) => $value !== null),
        ];

        return true;
    }

    private function logger(): mixed
    {
        $channel = (string) config('trading.log_channel', 'trading');

        try {
            if (! config("logging.channels.{$channel}")) {
                $fallback = (string) config('logging.default', 'stack');
                config(["logging.channels.{$channel}" => config("logging.channels.{$fallback}")]);
            }

            return Log::channel($channel);
        } catch (\Throwable) {
            // Logging must never prevent the bot from answering. The log
            // manager can still route this through its emergency logger.
            return Log::getFacadeRoot();
        }
    }

    private function audit(string $event, array $context = [], string $level = 'info'): void
    {
        $safe = static function ($value, $key = '') use (&$safe) {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $childKey => $childValue) {
                    $out[$childKey] = $safe($childValue, (string) $childKey);
                }

                return $out;
            }
            if (preg_match('/token|secret|password|receipt|photo|file_id|authorization/i', $key)) {
                return '[REDACTED]';
            }
            if (is_string($value) && mb_strlen($value) > 300) {
                $value = mb_substr($value, 0, 300).'…';
            }
            if (is_string($value)) {
                $value = preg_replace('/(\/connect\s+)\d{6}/iu', '$1[REDACTED]', $value);
            }

            return $value;
        };

        try {
            $this->logger()->log($level, 'telegram.'.$event, [
                'trace_id' => $this->traceId ?: null,
                ...$safe($context),
            ]);
        } catch (\Throwable $exception) {
            // A filesystem permission or stale logger config must not stop
            // Telegram processing. PHP's error log is the last-resort trace.
            error_log('telegram.'.$event.' logging failed: '.$exception->getMessage());
        }
    }

    private function api(string $method, array $data): array
    {
        // Inline callbacks are acknowledged by the webhook response before
        // their potentially slower work starts. Preserve later error alerts
        // as ordinary chat messages instead of answering a callback twice.
        if ($method === 'answerCallbackQuery' && $this->callbackPreAnswered) {
            if (($data['show_alert'] ?? false) && filled($data['text'] ?? null) && $this->callbackChatId) {
                $this->send($this->callbackChatId, (string) $data['text']);
            }

            return ['ok' => true, 'pre_answered' => true];
        }

        $token = config('services.telegram.token');

        if (! $token) {
            Log::channel(config('trading.log_channel', 'trading'))->warning('Telegram API token is not configured.', ['method' => $method]);

            return [];
        }

        $startedAt = microtime(true);
        $this->audit('api.request', ['method' => $method, 'keys' => array_keys($data)], 'debug');
        try {
            // Telegram expects reply_markup to be a JSON object. When this was
            // sent as form data, nested keyboard arrays were not parsed as a
            // keyboard, so users only saw the "select ..." prompt.
            $options = ['force_ip_resolve' => 'v4'];
            if ($proxy = config('services.telegram.proxy')) {
                $options['proxy'] = $proxy;
            }

            $request = Http::asJson()
                ->connectTimeout((int) config('services.telegram.connect_timeout', 5))
                ->timeout((int) config('services.telegram.timeout', 10))
                ->withOptions($options);
            $attempts = max(1, (int) config('services.telegram.retry_attempts', 1));
            if ($attempts > 1) {
                $request = $request->retry($attempts, (int) config('services.telegram.retry_delay', 100));
            }
            $response = $request->post("https://api.telegram.org/bot{$token}/{$method}", $data);
            $result = $response->json() ?? [];
            Log::channel(config('trading.log_channel', 'trading'))->log(
                $response->successful() && ($result['ok'] ?? true) ? 'debug' : 'warning',
                'Telegram API responded.',
                ['method' => $method, 'status' => $response->status(), 'telegram_ok' => $result['ok'] ?? null]
            );
            $this->audit('api.response', ['method' => $method, 'status' => $response->status(), 'ok' => $result['ok'] ?? null, 'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000)], 'debug');

            return $result;
        } catch (\Throwable $exception) {
            $safeMessage = str_replace((string) $token, '[REDACTED]', $exception->getMessage());
            Log::channel(config('trading.log_channel', 'trading'))->error('Telegram API request failed.', [
                'method' => $method,
                'exception' => $exception::class,
                'message' => $safeMessage,
            ]);
            $this->audit('api.failure', ['method' => $method, 'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'exception' => $exception::class], 'error');

            return [];
        }
    }

    private function send($chat, string $text, array $keyboard = []): void
    {
        $data = [
            'chat_id' => $chat,
            'text' => $text,
            'reply_markup' => $keyboard ? ['keyboard' => $keyboard, 'resize_keyboard' => true] : null,
        ];

        if ($this->captureSendMessage($data)) {
            return;
        }

        if (config('services.telegram.defer_sends', true)) {
            defer(fn () => $this->api('sendMessage', $data));

            return;
        }

        $this->api('sendMessage', $data);
    }

    private function sendInline($chat, string $text, array $keyboard): array
    {
        $data = ['chat_id' => $chat, 'text' => $text, 'reply_markup' => ['inline_keyboard' => $keyboard]];

        if ($this->captureSendMessage($data)) {
            return ['ok' => true, 'webhook_reply' => true];
        }

        if (config('services.telegram.defer_sends', true)) {
            defer(fn () => $this->api('sendMessage', $data));

            return ['ok' => true, 'deferred' => true];
        }

        return $this->api('sendMessage', $data);
    }

    private function sendInlineWithResult($chat, string $text, array $keyboard): array
    {
        return $this->api('sendMessage', [
            'chat_id' => $chat,
            'text' => $text,
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
    }

    private function user(array $chat): User
    {
        if (! Schema::hasTable('telegram_connections')) {
            return new User(['telegram_chat_id' => (string) $chat['id']]);
        }

        return User::query()->with('telegramConnection')->whereHas('telegramConnection', fn ($query) => $query->where('telegram_chat_id', (string) $chat['id']))->first()
            ?? new User(['telegram_chat_id' => (string) $chat['id']]);
    }

    private function callbackUser(array $callback): User
    {
        $telegramUserId = (string) data_get($callback, 'from.id', '');
        if ($telegramUserId === '') {
            return $this->user((array) data_get($callback, 'message.chat', []));
        }
        if ($telegramUserId !== '' && Schema::hasTable('telegram_connections')) {
            $connected = User::query()->with('telegramConnection')->whereHas('telegramConnection', fn ($query) => $query->where('telegram_user_id', $telegramUserId))->first();
            if ($connected) {
                return $connected;
            }
        }

        $privateChatId = Cache::get('telegram-private-chat:'.$telegramUserId, $telegramUserId);

        return new User(['telegram_chat_id' => (string) $privateChatId]);
    }

    private function membershipRequest(string $endpoint, array $payload): ?array
    {
        $this->lastSiteError = null;
        $url = rtrim((string) config('services.membership.url'), '/');
        $token = (string) config('services.membership.token');

        if ($url === '' || $token === '') {
            Log::channel(config('trading.log_channel', 'trading'))->warning('Membership API is not fully configured.', [
                'endpoint' => $endpoint,
                'url_configured' => $url !== '',
                'token_configured' => $token !== '',
            ]);

            return null;
        }

        $startedAt = microtime(true);
        $this->audit('site.request', ['endpoint' => $endpoint, 'payload_keys' => array_keys($payload)], 'debug');
        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout((int) config('services.membership.connect_timeout', 1))
                ->timeout((int) config('services.membership.timeout', 2))
                ->post("{$url}/{$endpoint}", $payload);

            $responsePayload = $response->json();
            Log::channel(config('trading.log_channel', 'trading'))->log(
                $response->successful() ? 'debug' : 'warning',
                'Membership API responded.',
                [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'telegram_chat_id' => $payload['telegram_chat_id'] ?? null,
                    'response_keys' => is_array($responsePayload) ? array_keys($responsePayload) : [],
                ]
            );
            $this->audit('site.response', ['endpoint' => $endpoint, 'status' => $response->status(), 'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'response_keys' => is_array($responsePayload) ? array_keys($responsePayload) : []], $response->successful() ? 'debug' : 'warning');

            if (! $response->successful()) {
                $siteMessage = is_array($responsePayload)
                    ? (string) ($responsePayload['message'] ?? data_get($responsePayload, 'errors.0.0', ''))
                    : '';
                $this->lastSiteError = ($response->serverError() && $siteMessage === '') || in_array(mb_strtolower(trim($siteMessage)), ['server error', 'internal server error'], true)
                    ? 'خطای موقت سایت رخ داد؛ لطفاً چند لحظه دیگر دوباره تلاش کنید.'
                    : ($siteMessage !== '' ? $siteMessage : 'سایت درخواست را نپذیرفت.');

                return null;
            }

            // Successful write endpoints may legitimately return 204 No Content.
            // Keep that distinct from a failed request, which is represented by null.
            return is_array($responsePayload) ? $responsePayload : [];
        } catch (\Throwable $exception) {
            $this->lastSiteError = 'ارتباط با سایت برقرار نشد؛ لطفاً چند لحظه دیگر دوباره تلاش کنید.';
            Log::channel(config('trading.log_channel', 'trading'))->error('Membership API request failed.', [
                'endpoint' => $endpoint,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->audit('site.failure', ['endpoint' => $endpoint, 'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000), 'exception' => $exception::class], 'error');

            return null;
        }
    }

    private function siteRequest(string $endpoint, User $user, array $payload = []): ?array
    {
        return $this->membershipRequest($endpoint, [
            'telegram_chat_id' => $user->telegram_chat_id,
            ...$payload,
        ]);
    }

    private function usesMembershipApi(): bool
    {
        return (string) config('services.membership.url') !== ''
            && (string) config('services.membership.token') !== '';
    }

    private function connectWebsiteAccount(array $message, string $code): ?array
    {
        return $this->membershipRequest('link', [
            'code' => $code,
            'telegram_user_id' => (string) data_get($message, 'from.id'),
            'telegram_chat_id' => (string) data_get($message, 'chat.id'),
            'telegram_username' => data_get($message, 'from.username'),
        ]);
    }

    private function uploadReceiptToSite(User $user, int $depositId, array $file): bool
    {
        $fileInfo = $this->api('getFile', ['file_id' => $file['file_id']]);
        $telegramPath = data_get($fileInfo, 'result.file_path');
        $botToken = config('services.telegram.token');
        $url = rtrim((string) config('services.membership.url'), '/');
        $token = (string) config('services.membership.token');
        if (! $telegramPath || ! $botToken || $url === '' || $token === '') {
            return false;
        }

        try {
            $contents = Http::connectTimeout(1)->timeout(5)
                ->get("https://api.telegram.org/file/bot{$botToken}/{$telegramPath}")->throw()->body();
            $response = Http::withToken($token)->connectTimeout(1)->timeout(5)->attach('receipt', $contents, basename($telegramPath))
                ->post("{$url}/receipts", ['telegram_chat_id' => $user->telegram_chat_id, 'deposit_id' => $depositId]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function siteMembership(User $user): array
    {
        $chatId = (string) $user->telegram_chat_id;
        if (array_key_exists($chatId, $this->membershipByChat)) {
            return $this->membershipByChat[$chatId];
        }

        $key = 'telegram-membership:'.$chatId;
        $missing = new \stdClass;
        $cached = Cache::get($key, $missing);
        if ($cached !== $missing) {
            return $this->membershipByChat[$chatId] = (array) $cached;
        }

        // Keep compatibility with older connections written before the full
        // membership payload was cached.
        $legacyKey = 'telegram-linked:'.$chatId;
        $legacyLinked = Cache::get($legacyKey, $missing);
        if ($legacyLinked !== $missing && ! $this->usesMembershipApi()) {
            return $this->membershipByChat[$chatId] = ['linked' => (bool) $legacyLinked, 'vip' => false, 'membership_status' => 'none'];
        }

        $membership = $this->siteRequest('member', $user) ?? [];
        $linked = (bool) ($membership['linked'] ?? false);
        $vip = (bool) ($membership['vip'] ?? false) || (int) ($membership['membership_level'] ?? 0) >= 2;
        // Membership is checked on almost every click. Cache a non-VIP result
        // long enough that a slow site cannot delay every menu interaction.
        $ttl = $vip ? now()->addDay() : now()->addMinutes(5);
        Cache::put($key, $membership, $ttl);
        Cache::put($legacyKey, $linked, $ttl);

        return $this->membershipByChat[$chatId] = $membership;
    }

    private function hasConnectedAccess(User $user): bool
    {
        return (bool) ($this->siteMembership($user)['linked'] ?? false);
    }

    private function hasVipAccess(User $user): bool
    {
        $membership = $this->siteMembership($user);

        return (bool) ($membership['vip'] ?? false)
            || (int) ($membership['membership_level'] ?? 0) >= 2;
    }

    private function membershipUrl(): string
    {
        return (string) (config('services.membership.web_url') ?: config('services.membership.url'));
    }

    private function sendMembershipPrompt($chat, array $menu): void
    {
        $url = $this->membershipUrl();
        $keyboard = $url !== '' ? [[['text' => 'ثبت نام عضویت ویژه', 'url' => $url]]] : [];
        if ($keyboard) {
            $this->sendInline($chat, 'برای ثبت یا پذیرش معامله، عضویت ویژه سایت لازم است. درخواست خود را در سایت ارسال کنید.', $keyboard);
        } else {
            $this->send($chat, 'برای ثبت یا پذیرش معامله، عضویت ویژه سایت لازم است. تنظیمات لینک عضویت ویژه انجام نشده است.', $menu);
        }
    }

    private function menuForMembership(User $user, array $menu): array
    {
        if (! $this->hasVipAccess($user)) {
            return $menu;
        }

        return array_values(array_filter(array_map(
            static fn (array $row): array => array_values(array_filter($row, static fn (string $item): bool => ! in_array($item, ['ثبت نام عضویت ویژه', 'عضویت ویژه'], true))),
            $menu,
        )));
    }

    private function linkWebsiteAccount(User $user, string $code): ?array
    {
        return $this->membershipRequest('link', [
            'code' => strtoupper($code),
            'telegram_chat_id' => $user->telegram_chat_id,
        ]);
    }

    private function storeTelegramPhoto(array $file): string
    {
        $fileInfo = $this->api('getFile', ['file_id' => $file['file_id']]);
        $telegramPath = data_get($fileInfo, 'result.file_path');
        $token = config('services.telegram.token');

        throw_unless($telegramPath && $token, \RuntimeException::class, 'دریافت فایل فیش از تلگرام ناموفق بود.');

        $contents = Http::get("https://api.telegram.org/file/bot{$token}/{$telegramPath}")->throw()->body();
        $extension = pathinfo($telegramPath, PATHINFO_EXTENSION) ?: 'jpg';
        $path = 'receipts/telegram/'.now()->format('Y/m').'/'.str()->uuid().'.'.$extension;

        Storage::disk('public')->put($path, $contents);

        return $path;
    }

    private function notifyAdmins(DepositRequest $deposit, string $telegramFileId): void
    {
        $caption = "فیش جدید برای تأیید\nکاربر: {$deposit->user->name}\nمبلغ: ".number_format($deposit->amount).' ریال';
        $keyboard = ['inline_keyboard' => [[['text' => '✅ تأیید و شارژ کیف پول', 'callback_data' => "deposit:approve:{$deposit->id}"]]]];

        User::query()->where('is_admin', true)->whereNotNull('telegram_chat_id')->each(function (User $admin) use ($caption, $keyboard, $telegramFileId) {
            $this->api('sendPhoto', [
                'chat_id' => $admin->telegram_chat_id,
                'photo' => $telegramFileId,
                'caption' => $caption,
                'reply_markup' => $keyboard,
            ]);
        });
    }

    private function approveFromTelegram(array $callback): void
    {
        $chat = $callback['message']['chat'] ?? [];
        $admin = $chat ? $this->user($chat) : null;
        $id = (int) last(explode(':', (string) ($callback['data'] ?? '')));

        if (! $admin?->is_admin || ! $id) {
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'اجازهٔ تأیید ندارید.', 'show_alert' => true]);

            return;
        }

        $approved = DB::transaction(function () use ($admin, $id) {
            $deposit = DepositRequest::lockForUpdate()->find($id);
            if (! $deposit || $deposit->status !== 'pending') {
                return false;
            }

            $deposit->update(['status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $user = User::lockForUpdate()->findOrFail($deposit->user_id);
            $user->increment('wallet_balance', $deposit->amount);
            WalletTransaction::create(['user_id' => $user->id, 'amount' => $deposit->amount, 'type' => 'deposit', 'reference_type' => DepositRequest::class, 'reference_id' => $deposit->id, 'description' => 'تأیید فیش واریزی در ربات']);

            return $deposit;
        });

        $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => $approved ? 'فیش تأیید و کیف پول شارژ شد.' : 'این فیش قبلاً بررسی شده است.']);
        if ($approved) {
            $this->send($approved->user->telegram_chat_id, 'فیش واریزی شما تأیید و کیف پول شارژ شد.');
        }
    }

    private function pagination(string $prefix, int $page, bool $hasMore): array
    {
        $buttons = [];
        if ($page > 1) {
            $buttons[] = ['text' => '◀️ قبلی', 'callback_data' => $prefix.':'.($page - 1)];
        }
        if ($hasMore) {
            $buttons[] = ['text' => 'بعدی ▶️', 'callback_data' => $prefix.':'.($page + 1)];
        }

        return $buttons ? [$buttons] : [];
    }

    private function tradeUnit(array $trade): string
    {
        $unit = (string) ($trade['unit'] ?? '');
        if (in_array($unit, ['gram', 'mesghal', 'count'], true)) {
            return $unit;
        }

        $label = (string) ($trade['item_label'] ?? $trade['asset_label'] ?? '');
        if (str_contains($label, 'مثقال')) {
            return 'mesghal';
        }

        return str_contains($label, 'گرم') ? 'gram' : 'count';
    }

    private function livePricesText(TalaboardClient $prices): string
    {
        try {
            $rows = $prices->prices();
        } catch (\Throwable $exception) {
            Log::channel(config('trading.log_channel', 'trading'))->error('Building the live price message failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return 'دریافت قیمت لحظه‌ای از سایت ناموفق بود؛ لطفاً دوباره تلاش کنید.';
        }

        if ($rows->isEmpty()) {
            Log::channel(config('trading.log_channel', 'trading'))->warning('Live price message has no available snapshots.');
        }

        $lines = ['💹 قیمت لحظه‌ای سایت (تومان)', ''];
        foreach (TalaboardClient::PRODUCTS as $symbol => $label) {
            $price = $rows->get($symbol)?->price;
            $formatted = $price ? $this->formatToman((float) $price) : '—';
            $lines[] = (TalaboardClient::PRODUCT_ICONS[$symbol] ?? '▫️').' '.$label.': '.$formatted.' تومان';
        }

        $lines[] = '';
        $lines[] = 'به‌روزرسانی: '.now(config('trading.timezone'))->format('H:i:s');

        return implode("\n", $lines);
    }

    private function tradeList(User $user, string $side, int $page): array
    {
        $perPage = 10;
        $response = $this->siteRequest('trade-room/offers', $user);
        $trades = collect($response['offers'] ?? $response['trades'] ?? $response ?? [])
            ->filter(fn ($trade) => is_array($trade))
            ->filter(fn (array $trade) => ($trade['side'] ?? $trade['type'] ?? null) === $side)
            ->filter(fn (array $trade) => $this->tradeUnit($trade) !== 'mesghal')
            ->filter(fn (array $trade) => Trade::meetsMinimumQuantity($this->tradeUnit($trade), (float) ($trade['quantity'] ?? 0), (string) ($trade['asset'] ?? $trade['item'] ?? '')))
            ->map(fn (array $trade) => $this->normalizeOffer($trade))
            ->values();
        if ($response === null) {
            $trades = Trade::query()->tradable()->where('side', $side)->where('unit', '!=', 'mesghal')->whereIn('status', ['submitted', 'active'])
                ->latest('traded_at')->get()->map(fn (Trade $trade) => $this->normalizeOffer([
                    'id' => $trade->id,
                    'side' => $trade->side,
                    'asset' => $trade->asset,
                    'unit' => $trade->unit,
                    'quantity' => $trade->quantity,
                    'unit_price' => $trade->unit_price,
                    'total_price' => $trade->total_price,
                    'status' => $trade->status,
                ]));
        }
        $hasMore = $trades->count() > $page * $perPage;
        $rows = $trades->slice(($page - 1) * $perPage, $perPage);
        $title = $side === 'buy' ? 'لیست خرید' : 'لیست فروش';

        return [$title, $rows->values(), $this->pagination("trades:{$side}", $page, $hasMore), $page];
    }

    private function normalizeOffer(array $trade): array
    {
        $quantity = (float) ($trade['quantity'] ?? $trade['remaining_quantity'] ?? 0);
        $unitPrice = (float) ($trade['unit_price'] ?? $trade['price_per_unit'] ?? 0);
        $total = (float) ($trade['total'] ?? $trade['total_price'] ?? ($quantity * $unitPrice));
        $asset = (string) ($trade['asset'] ?? $trade['item'] ?? '');
        $assetLabels = [
            'gold' => 'طلا',
            'silver_995' => 'نقره ۹۹۵',
            'silver_999' => 'نقره ۹۹۹.۹',
            'silver_9999' => 'نقره ۹۹۹.۹',
            'full_coin' => 'تمام سکه',
            'half_coin' => 'نیم سکه',
            'quarter_coin' => 'ربع سکه',
        ];
        $unitLabels = ['gram' => 'گرم', 'mesghal' => 'مثقال', 'piece' => 'عدد', 'count' => 'عدد'];
        $statusLabels = [
            'active' => 'فعال', 'submitted' => 'فعال', 'pending' => 'فعال',
            'open' => 'فعال', 'published' => 'فعال', 'available' => 'فعال',
            'accepted' => 'پذیرفته‌شده', 'completed' => 'پذیرفته‌شده',
            'rejected' => 'ردشده', 'cancelled' => 'ردشده', 'canceled' => 'ردشده',
            'expired' => 'ردشده', 'failed' => 'ردشده', 'declined' => 'ردشده',
        ];
        $unit = (string) ($trade['unit'] ?? $this->tradeUnit($trade));

        return [
            ...$trade,
            'id' => $trade['id'] ?? $trade['offer_id'] ?? null,
            'side' => $trade['side'] ?? $trade['type'] ?? null,
            'asset_label' => $assetLabels[$asset] ?? ($trade['item_label'] ?? $trade['asset_label'] ?? ($asset ?: '—')),
            'unit_label' => $unitLabels[$unit] ?? $unit,
            'unit' => $unit,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $total,
            'status_label' => $statusLabels[$trade['status'] ?? 'active'] ?? ($trade['status'] ?? 'فعال'),
        ];
    }

    private function offerText(array $trade, string $title, int $page): string
    {
        return "{$title} — صفحه {$page}\n\n"
            .($trade['asset_label'] ?? '—')."\n"
            .'مقدار: '.$this->formatQuantity((float) ($trade['quantity'] ?? 0)).' '.($trade['unit_label'] ?? '')."\n"
            .'قیمت واحد: '.$this->formatToman((float) ($trade['unit_price'] ?? 0))." تومان\n"
            .'مبلغ کل: '.$this->formatToman((float) ($trade['total'] ?? 0))." تومان\n"
            .'وضعیت: '.($trade['status_label'] ?? 'فعال');
    }

    private function channelOfferText(array $trade): string
    {
        $trade = $this->normalizeOffer($trade);
        $side = ($trade['side'] ?? '') === 'buy' ? 'خرید' : 'فروش';

        return "📣 {$side} ".($trade['asset_label'] ?? '—')."\n\n"
            .'نام مستعار: '.($trade['alias'] ?? 'کاربر')."\n"
            .'مقدار: '.$this->formatQuantity((float) $trade['quantity']).' '.($trade['unit_label'] ?? '')."\n"
            .'قیمت واحد: '.$this->formatToman((float) $trade['unit_price'])." تومان\n"
            .'مبلغ کل: '.$this->formatToman((float) $trade['total'])." تومان\n"
            .'وضعیت: فعال';
    }

    private function channelForAsset(string $asset): int|string|null
    {
        $asset = $asset === 'silver_9999' ? 'silver_999' : $asset;
        $channel = config("services.telegram.channels.{$asset}");

        return filled($channel) ? $channel : null;
    }

    private function tradeChannelsText(): string
    {
        $channels = [
            'اتاق طلا' => config('services.telegram.channels.gold'),
            'اتاق نقره ۹۹۵' => config('services.telegram.channels.silver_995'),
            'اتاق نقره ۹۹۹/۹' => config('services.telegram.channels.silver_999'),
        ];
        $lines = ['📣 کانال‌های خرید و فروش'];
        foreach ($channels as $label => $channel) {
            $value = trim((string) $channel);
            if ($value === '') {
                $lines[] = "{$label}: تنظیم نشده";

                continue;
            }
            $url = str_starts_with($value, '@')
                ? 'https://t.me/'.ltrim($value, '@')
                : (preg_match('/^https?:\\/\\//i', $value) ? $value : null);
            $lines[] = $url ? "{$label}: {$url}" : "{$label}: {$value}";
        }

        return implode("\n", $lines);
    }

    private function publishOfferToChannel(array $trade): bool
    {
        $trade = $this->normalizeOffer($trade);
        if (($trade['unit'] ?? '') === 'mesghal') {
            return false;
        }
        $channel = $this->channelForAsset((string) ($trade['asset'] ?? $trade['item'] ?? ''));
        if (! $channel || empty($trade['id'])) {
            Log::channel(config('trading.log_channel', 'trading'))->warning('Trade offer channel is not configured.', [
                'offer_id' => $trade['id'] ?? null,
                'asset' => $trade['asset'] ?? null,
            ]);

            return false;
        }

        $result = $this->sendInlineWithResult($channel, $this->channelOfferText($trade), $this->offerAcceptKeyboard($trade));
        $messageId = data_get($result, 'result.message_id');
        if (! $messageId) {
            return false;
        }

        Cache::put('telegram-offer-message:'.$trade['id'], [
            'channel_id' => $channel,
            'message_id' => $messageId,
            'offer' => $trade,
        ], now()->addDays(30));
        $expiresAt = Carbon::parse($trade['expires_at'] ?? now()->addMinutes(2));
        ExpireTelegramOffer::dispatch($trade['id'], $channel, $messageId, $expiresAt->toIso8601String())
            ->delay($expiresAt);

        return true;
    }

    public function expirePublishedOffer(
        int|string $offerId,
        int|string $channelId,
        int|string $messageId,
        string $expiresAt,
    ): bool {
        if (Carbon::parse($expiresAt)->isFuture()) {
            return false;
        }

        $cacheKey = 'telegram-offer-message:'.$offerId;
        $message = (array) Cache::get($cacheKey, []);
        if ((string) ($message['channel_id'] ?? '') !== (string) $channelId
            || (string) ($message['message_id'] ?? '') !== (string) $messageId) {
            return false;
        }

        $offer = (array) ($message['offer'] ?? []);
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '' && $offer !== []) {
            $text = $this->channelOfferText($offer);
        }
        if (! str_contains($text, 'معامله منقضی شد')) {
            $text = ($text === '' ? '' : $text."\n\n").'⏱ معامله منقضی شد';
        }

        $result = $this->api('editMessageText', [
            'chat_id' => $channelId,
            'message_id' => $messageId,
            'text' => $text,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
        if (($result['ok'] ?? false) === true) {
            Cache::forget($cacheKey);

            return true;
        }

        return false;
    }

    private function aliasKey(User $user): string
    {
        return 'telegram-trade-alias:'.$this->telegramChatId($user);
    }

    private function tradeAlias(User $user): string
    {
        return (string) Cache::get($this->aliasKey($user), 'کاربر');
    }

    private function telegramChatId(User $user): string
    {
        return (string) ($user->telegram_chat_id
            ?: optional($user->telegramConnection)->telegram_chat_id);
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }

    private function formatToman(float|int $rial): string
    {
        return number_format($rial / 10, 0, '.', ',');
    }

    private function offerAcceptKeyboard(array $trade): array
    {
        if (empty($trade['id'])) {
            return [];
        }

        $buttons = [['text' => 'پذیرفتن کل', 'callback_data' => 'trade_accept:full:'.$trade['id']]];
        if (($trade['allow_partial'] ?? true) !== false) {
            $buttons[] = ['text' => 'پذیرفتن جز', 'callback_data' => 'trade_accept:partial:'.$trade['id']];
        }

        return [$buttons];
    }

    private function offerIsExpired(array $offer): bool
    {
        $expiresAt = $offer['expires_at'] ?? null;
        if ($expiresAt) {
            return Carbon::parse($expiresAt)->isPast();
        }

        $createdAt = $offer['created_at'] ?? $offer['traded_at'] ?? null;

        return $createdAt
            ? Carbon::parse($createdAt)->addMinutes(2)->isPast()
            : false;
    }

    private function sendTradeListMessages(User $user, int|string $chatId, string $side, int $page, array $menu = []): void
    {
        [$title, $rows, $pagination, $page] = $this->tradeList($user, $side, $page);

        if ($rows->isEmpty()) {
            $this->send($chatId, "{$title} — صفحه {$page}\n\nموردی وجود ندارد.", $menu);

            return;
        }

        $rows->each(function (array $trade) use ($chatId, $title, $page) {
            $keyboard = $this->offerAcceptKeyboard($trade);
            $text = $this->offerText($trade, $title, $page);
            $keyboard ? $this->sendInline($chatId, $text, $keyboard) : $this->send($chatId, $text);
        });

        if ($pagination) {
            $this->sendInline($chatId, "{$title} — صفحه {$page}", $pagination);
        }
    }

    private function myTradeRoomList(User $user, int $page, ?array $statuses = null, string $paginationPrefix = 'trades:mine', bool $filterOnSite = true): array
    {
        $perPage = 10;
        $request = ['mine' => true];
        if ($statuses !== null && $filterOnSite) {
            $request['status'] = count($statuses) === 1 ? $statuses[0] : $statuses;
        }
        $response = $this->siteRequest('trade-room/offers', $user, $request);
        // The membership endpoint has returned both `offers`/`trades` and
        // paginated `data` envelopes over time. Accept all of them here so a
        // valid personal offer is not shown as an empty list.
        $payload = is_array($response) ? ($response['offers'] ?? $response['trades'] ?? $response['data'] ?? $response['items'] ?? $response) : [];
        $trades = collect($payload)
            ->filter(fn ($trade) => is_array($trade))
            ->map(fn (array $trade) => $this->normalizeOffer($trade))
            ->when($statuses !== null, fn ($trades) => $trades->filter(fn (array $trade) => $this->tradeStatusMatches($trade['status'] ?? null, $statuses)))
            ->sortByDesc(fn (array $trade) => $trade['created_at'] ?? $trade['traded_at'] ?? $trade['id'] ?? 0)
            ->values();

        // Some deployed website versions returned an empty filtered envelope
        // for `status=accepted` even though the user's completed offers were
        // present. Retry once without the status filter and apply the same
        // filter locally so history remains visible during rollout.
        if ($statuses !== null && $trades->isEmpty() && $response !== null) {
            $fallback = $this->siteRequest('trade-room/offers', $user, ['mine' => true]);
            $fallbackPayload = is_array($fallback) ? ($fallback['offers'] ?? $fallback['trades'] ?? $fallback['data'] ?? $fallback['items'] ?? $fallback) : [];
            $trades = collect($fallbackPayload)
                ->filter(fn ($trade) => is_array($trade))
                ->map(fn (array $trade) => $this->normalizeOffer($trade))
                ->filter(fn (array $trade) => $this->tradeStatusMatches($trade['status'] ?? null, $statuses))
                ->sortByDesc(fn (array $trade) => $trade['created_at'] ?? $trade['traded_at'] ?? $trade['id'] ?? 0)
                ->values();
        }

        if ($response === null && ! $this->usesMembershipApi() && $user->exists) {
            $query = Trade::query()->latest('traded_at');
            if ($statuses !== null) {
                $localStatuses = in_array('active', $statuses, true)
                    ? [...$statuses, 'submitted', 'pending', 'open', 'published', 'available']
                    : $statuses;
                $query->whereIn('status', $localStatuses);
            }
            if ($statuses !== null && in_array('accepted', $statuses, true)) {
                $query->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('accepted_by', $user->id));
            } else {
                $query->where('user_id', $user->id);
            }

            $trades = $query->get()
                ->map(fn (Trade $trade) => $this->normalizeOffer([
                    'id' => $trade->id,
                    'side' => $trade->side,
                    'asset' => $trade->asset,
                    'unit' => $trade->unit,
                    'quantity' => $trade->quantity,
                    'unit_price' => $trade->unit_price,
                    'total_price' => $trade->total_price,
                    'status' => $trade->status,
                    'allow_partial' => $trade->allow_partial,
                    'traded_at' => $trade->traded_at?->toIso8601String(),
                ]));
        }

        $hasMore = $trades->count() > $page * $perPage;
        $rows = $trades->slice(($page - 1) * $perPage, $perPage)->values();

        Log::channel(config('trading.log_channel', 'trading'))->info('Personal trade-room offers displayed.', [
            'telegram_chat_id' => $user->telegram_chat_id,
            'statuses' => $statuses,
            'total_count' => $trades->count(),
            'page' => $page,
        ]);

        return [$rows, $this->pagination($paginationPrefix, $page, $hasMore), $page];
    }

    private function tradeStatusMatches(mixed $status, array $statuses): bool
    {
        $status = strtolower(trim((string) $status));
        $status = match ($status) {
            'pending', 'open', 'published', 'available' => 'active',
            'completed' => 'accepted',
            'cancelled', 'canceled', 'expired', 'failed', 'declined' => 'rejected',
            default => $status,
        };

        return in_array($status, $statuses, true);
    }

    private function myOfferText(array $trade, int $page, string $title = 'معاملات من'): string
    {
        $side = match ($trade['side'] ?? '') {
            'buy' => 'خرید',
            'sell' => 'فروش',
            default => 'معامله',
        };

        return "📋 {$title} — صفحه {$page}\n\n"
            .$side.' '.($trade['asset_label'] ?? '—')."\n"
            .'مقدار: '.$this->formatQuantity((float) ($trade['quantity'] ?? 0)).' '.($trade['unit_label'] ?? '')."\n"
            .'قیمت واحد: '.$this->formatToman((float) ($trade['unit_price'] ?? 0))." تومان\n"
            .'مبلغ کل: '.$this->formatToman((float) ($trade['total'] ?? 0))." تومان\n"
            .'وضعیت: '.($trade['status_label'] ?? 'فعال');
    }

    private function sendMyTradeRoomMessages(User $user, int|string $chatId, int $page, array $menu = []): void
    {
        // Only live offers belong in the trade room. Completed and cancelled
        // offers are shown under "سوابق من" and must not have a delete button.
        [$rows, $pagination, $page] = $this->myTradeRoomList(
            $user,
            $page,
            ['active'],
            'trades:mine',
            false,
        );

        if ($rows->isEmpty()) {
            $this->send($chatId, "📋 معاملات من — صفحه {$page}\n\nمعامله‌ای در اتاق معاملاتی ندارید.", $menu);

            return;
        }

        $rows->each(function (array $trade) use ($chatId, $page) {
            $keyboard = empty($trade['id']) ? [] : [[
                ['text' => 'حذف', 'callback_data' => 'trade_delete:'.$trade['id']],
            ]];
            $text = $this->myOfferText($trade, $page);
            $keyboard ? $this->sendInline($chatId, $text, $keyboard) : $this->send($chatId, $text);
        });

        if ($pagination) {
            $this->sendInline($chatId, "📋 معاملات من — صفحه {$page}", $pagination);
        }
    }

    private function sendMyTradeRoomHistoryMessages(User $user, int|string $chatId, int $page, array $menu = []): void
    {
        [$rows, $pagination, $page] = $this->myTradeRoomList(
            $user,
            $page,
            ['accepted', 'rejected'],
            'trades:history',
            false,
        );

        if ($rows->isEmpty()) {
            $this->send($chatId, "📋 سوابق من — صفحه {$page}\n\nمعامله نهایی‌شده‌ای در اتاق معاملاتی ندارید.", $menu);

            return;
        }

        $text = $rows
            ->map(fn (array $trade) => $this->myOfferText($trade, $page, 'سوابق من'))
            ->join("\n\n────────────\n\n");

        $pagination
            ? $this->sendInline($chatId, $text, $pagination)
            : $this->send($chatId, $text, $menu);
    }

    private function handleTradeDeleteCallback(array $callback, User $user, array $menu): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, 'trade_delete:')) {
            return false;
        }

        $offerId = (int) substr($data, strlen('trade_delete:'));
        $chatId = data_get($callback, 'message.chat.id');
        if (! $offerId || ! $chatId) {
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'درخواست نامعتبر است.', 'show_alert' => true]);

            return true;
        }

        $deleted = $this->siteRequest("trade-room/offers/{$offerId}/cancel", $user, ['offer_id' => $offerId]);
        if ($deleted === null && ! $this->usesMembershipApi() && $user->exists) {
            $trade = $user->trades()->whereKey($offerId)->first();
            if ($trade) {
                $trade->delete();
                $deleted = ['deleted' => true];
            }
        }

        $wasDeleted = $deleted !== null
            && (bool) ($deleted['deleted'] ?? $deleted['cancelled'] ?? $deleted['success'] ?? true);

        Log::channel(config('trading.log_channel', 'trading'))->info('Personal trade-room offer deletion requested from Telegram.', [
            'telegram_chat_id' => $user->telegram_chat_id,
            'offer_id' => $offerId,
            'deleted' => $wasDeleted,
        ]);

        $this->api('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
            'text' => $wasDeleted ? 'معامله حذف شد.' : ($this->lastSiteError ?: 'حذف معامله ناموفق بود.'),
            'show_alert' => ! $wasDeleted,
        ]);

        if ($wasDeleted) {
            $messageId = data_get($callback, 'message.message_id');
            $messageId
                ? $this->api('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId])
                : null;
            $this->send($chatId, 'معامله شما با موفقیت حذف شد.', $menu);
        }

        return true;
    }

    private function acceptOffer(User $user, int|string $chatId, int $offerId, ?float $quantity, array $menu, ?string $token = null): bool
    {
        $processingKey = 'telegram-offer-processing:'.$offerId;
        if ($token === null || ! hash_equals((string) Cache::get($processingKey), $token)) {
            $this->send($chatId, 'زمان پذیرش این معامله تمام شده یا معامله توسط شخص دیگری در حال انجام است.', $menu);

            return false;
        }

        $message = Cache::get('telegram-offer-message:'.$offerId, []);
        $original = (array) ($message['offer'] ?? []);
        if ($quantity !== null && $original && (($original['allow_partial'] ?? true) === false)) {
            Cache::forget($processingKey);
            $this->send($chatId, 'این معامله فقط به‌صورت کامل قابل پذیرش است.', $menu);

            return false;
        }
        if ($quantity !== null && $original) {
            $available = (float) ($original['quantity'] ?? 0);
            $unit = (string) ($original['unit'] ?? 'gram');
            $unit = $unit === 'piece' ? 'count' : $unit;
            $remaining = round($available - $quantity, 3);
            $asset = (string) ($original['asset'] ?? $original['item'] ?? '');
            if ($quantity > $available || ! Trade::meetsMinimumQuantity($unit, $quantity, $asset) || ($remaining > 0 && ! Trade::meetsMinimumQuantity($unit, $remaining, $asset))) {
                Cache::forget($processingKey);
                $this->send($chatId, 'مقدار واردشده معتبر نیست؛ '.Trade::minimumQuantityMessage($asset).' مقدار پذیرش و ماندهٔ معامله باید این حداقل را داشته باشند.', $menu);

                return false;
            }
        }
        $payload = ['offer_id' => $offerId, 'acceptance_token' => $token];
        if ($quantity !== null) {
            $payload['quantity'] = $quantity;
        }

        $accepted = $this->siteRequest("trade-room/offers/{$offerId}/accept", $user, $payload);
        if ($accepted === null && ! $this->usesMembershipApi() && $user->exists) {
            $accepted = DB::transaction(function () use ($offerId, $user, $quantity) {
                $trade = Trade::query()->whereKey($offerId)->lockForUpdate()->first();
                if (! $trade || ! in_array($trade->status, ['submitted', 'active'], true) || ($quantity !== null && ! $trade->allow_partial) || ($user->exists && (int) $trade->user_id === (int) $user->id)) {
                    return null;
                }

                $available = (float) $trade->quantity;
                $acceptQuantity = $quantity ?? $available;
                if ($acceptQuantity <= 0 || $acceptQuantity > $available || ! Trade::meetsMinimumQuantity($trade->unit, $acceptQuantity, $trade->asset)) {
                    return null;
                }

                $acceptedTrade = $trade->replicate(['idempotency_key']);
                $acceptedTrade->quantity = $acceptQuantity;
                $acceptedTrade->total_price = (int) round($acceptQuantity * (float) $trade->unit_price);
                $acceptedTrade->status = 'accepted';
                $acceptedTrade->accepted_by = $user->id;
                $acceptedTrade->idempotency_key = null;

                $remaining = round($available - $acceptQuantity, 3);
                if ($remaining > 0) {
                    if (! Trade::meetsMinimumQuantity($trade->unit, $remaining, $trade->asset)) {
                        return null;
                    }
                    $acceptedTrade->save();
                    $trade->update(['quantity' => $remaining, 'total_price' => (int) round($remaining * (float) $trade->unit_price)]);
                } else {
                    $trade->update(['status' => 'accepted', 'accepted_by' => $user->id]);
                    $acceptedTrade = $trade;
                }

                return ['accepted' => true, 'offer' => $trade->fresh()->toArray(), 'remaining_quantity' => $remaining, 'accepted_offer' => $acceptedTrade->toArray()];
            });
        }

        $success = is_array($accepted) && (($accepted['accepted'] ?? true) !== false);
        Log::channel(config('trading.log_channel', 'trading'))->info('Trade offer accept requested from Telegram.', [
            'telegram_chat_id' => $user->telegram_chat_id,
            'offer_id' => $offerId,
            'quantity' => $quantity,
            'accepted' => $success,
        ]);

        if (! $success) {
            Cache::forget($processingKey);
            $this->send($chatId, 'این معامله دیگر قابل پذیرش نیست یا ثبت آن در سایت ناموفق بود.', $menu);

            return false;
        }

        $resultMessage = 'کل معامله با موفقیت انجام شد.';
        $remainingOffer = null;
        if ($quantity !== null) {
            $responseOffer = (array) ($accepted['remaining_offer'] ?? $accepted['offer'] ?? $original);
            $remaining = (float) ($accepted['remaining_quantity'] ?? $responseOffer['remaining_quantity'] ?? max(0, (float) ($original['quantity'] ?? 0) - $quantity));
            $remainingUnit = (string) ($responseOffer['unit'] ?? $original['unit'] ?? 'gram');
            $remainingAsset = (string) ($responseOffer['asset'] ?? $original['asset'] ?? '');
            $unitLabel = ['gram' => 'گرم', 'mesghal' => 'مثقال', 'piece' => 'عدد', 'count' => 'عدد'];
            $acceptedUnit = $unitLabel[$original['unit'] ?? ''] ?? ($original['unit'] ?? '');
            $remainingUnitLabel = $unitLabel[$responseOffer['unit'] ?? $original['unit'] ?? ''] ?? ($responseOffer['unit'] ?? $original['unit'] ?? '');
            if ($remaining > 0 && Trade::meetsMinimumQuantity($remainingUnit === 'piece' ? 'count' : $remainingUnit, $remaining, $remainingAsset)) {
                $remainingOffer = [
                    ...$original,
                    ...$responseOffer,
                    'id' => $responseOffer['id'] ?? $responseOffer['offer_id'] ?? $offerId,
                    'quantity' => $remaining,
                    'total_price' => (int) round($remaining * (float) ($responseOffer['unit_price'] ?? $original['unit_price'] ?? 0)),
                    'status' => 'active',
                ];
                $resultMessage = 'بخشی از معامله با موفقیت انجام شد.'
                    ."\nمقدار انجام‌شده: {$this->formatQuantity($quantity)} {$acceptedUnit}"
                    ."\nمانده معامله: {$this->formatQuantity($remaining)} {$remainingUnitLabel}";
            } else {
                $resultMessage = 'بخشی از معامله با موفقیت انجام شد و معامله به‌طور کامل به پایان رسید.'
                    ."\nمقدار انجام‌شده: {$this->formatQuantity($quantity)} {$acceptedUnit}";
            }
        }

        Cache::forget('telegram-offer-message:'.$offerId);
        if (! empty($message['channel_id']) && ! empty($message['message_id'])) {
            if ($remainingOffer !== null) {
                $deletedMessage = $this->api('deleteMessage', ['chat_id' => $message['channel_id'], 'message_id' => $message['message_id']]);
                $this->audit('offer.channel_deleted', [
                    'offer_id' => $offerId,
                    'channel_id' => $message['channel_id'],
                    'message_id' => $message['message_id'],
                    'telegram_ok' => $deletedMessage['ok'] ?? null,
                ]);
                $this->publishOfferToChannel($remainingOffer);
            } else {
                $channelText = trim((string) ($message['text'] ?? ''));
                if ($channelText === '' && $original !== []) {
                    $channelText = $this->channelOfferText($original);
                }
                $completedText = ($channelText === '' ? '' : $channelText."\n\n").'✅ معامله کامل شد';
                $editedMessage = $this->api('editMessageText', [
                    'chat_id' => $message['channel_id'],
                    'message_id' => $message['message_id'],
                    'text' => $completedText,
                    'reply_markup' => ['inline_keyboard' => []],
                ]);
                $this->audit('offer.channel_completed', [
                    'offer_id' => $offerId,
                    'channel_id' => $message['channel_id'],
                    'message_id' => $message['message_id'],
                    'telegram_ok' => $editedMessage['ok'] ?? null,
                ]);
            }
        } elseif ($remainingOffer !== null) {
            $this->publishOfferToChannel($remainingOffer);
        }

        Cache::forget($processingKey);
        $this->send($chatId, $resultMessage, $menu);

        return true;
    }

    private function handleTradeAcceptCallback(array $callback, User $user, array $menu): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, 'trade_accept:')) {
            return false;
        }

        $chat = $callback['message']['chat'] ?? [];
        $parts = explode(':', $data);
        $mode = $parts[1] ?? '';
        $offerId = (int) ($parts[2] ?? 0);

        if (! $offerId || ! in_array($mode, ['full', 'partial'], true)) {
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'درخواست نامعتبر است.', 'show_alert' => true]);

            return true;
        }

        if (! $this->hasConnectedAccess($user)) {
            $this->api('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => 'برای پذیرش معامله، ابتدا در سایت ثبت‌نام و عضویت ویژه را فعال کنید. سپس ربات را Start کنید، کد اتصال را از پروفایل سایت بگیرید و با /connect کد را ارسال کنید.',
                'show_alert' => true,
            ]);

            return true;
        }

        if (! $this->hasVipAccess($user)) {
            $this->api('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => 'برای پذیرش معامله، عضویت ویژه سایت لازم است. لینک ثبت‌نام در پیام خصوصی ربات ارسال شد.',
                'show_alert' => true,
            ]);
            $this->sendMembershipPrompt($user->telegram_chat_id, []);

            return true;
        }

        $offerMessage = Cache::get('telegram-offer-message:'.$offerId, []);
        // The callback itself is authoritative for the channel message. Keep
        // it in the offer cache so a full acceptance can always delete the
        // exact message, even if the original cache entry expired.
        $offerMessage = [
            ...((array) $offerMessage),
            'channel_id' => $chat['id'] ?? data_get($offerMessage, 'channel_id'),
            'message_id' => data_get($callback, 'message.message_id', data_get($offerMessage, 'message_id')),
            'text' => data_get($callback, 'message.text', data_get($offerMessage, 'text')),
        ];
        Cache::put('telegram-offer-message:'.$offerId, $offerMessage, now()->addMinutes(10));
        if ($this->offerIsExpired((array) ($offerMessage['offer'] ?? []))) {
            Cache::forget('telegram-offer-processing:'.$offerId);
            $this->api('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => 'زمان معامله منقضی شده است و امکان پذیرش جزئی یا کامل وجود ندارد.',
                'show_alert' => true,
            ]);
            if ($messageId = data_get($callback, 'message.message_id')) {
                $this->expirePublishedOffer(
                    $offerId,
                    $chat['id'] ?? '',
                    $messageId,
                    (string) data_get($offerMessage, 'offer.expires_at', now()->subSecond()->toIso8601String()),
                );
            }
            $this->send($this->telegramChatId($user) ?: data_get($callback, 'from.id'), 'زمان این معامله منقضی شده است؛ پذیرش جزئی یا کامل دیگر امکان‌پذیر نیست.', $menu);

            return true;
        }
        $ownerChatId = (string) data_get($offerMessage, 'offer.owner_telegram_chat_id', '');
        if ($ownerChatId !== '' && hash_equals($ownerChatId, (string) $user->telegram_chat_id)) {
            $this->api('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => 'شما نمی‌توانید معامله خودتان را بپذیرید.',
                'show_alert' => true,
            ]);

            return true;
        }

        $token = (string) str()->uuid();
        if (! Cache::add('telegram-offer-processing:'.$offerId, $token, now()->addMinutes(5))) {
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'معامله در حال انجام است.', 'show_alert' => true]);

            return true;
        }

        $this->api('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
            'text' => 'پیامی از طرف ربات برای انجام معامله به خصوصی شما ارسال شد.',
            'show_alert' => true,
        ]);
        $privateChatId = $user->telegram_chat_id ?: data_get($callback, 'from.id');

        if ($mode === 'full') {
            $this->send($privateChatId, 'درخواست پذیرش کل معامله دریافت شد؛ در حال ثبت معامله در سایت هستیم.', $menu);
            $this->acceptOffer($user, $privateChatId, $offerId, null, $menu, $token);

            return true;
        }

        $this->saveFlow($user, [
            'type' => 'trade_accept',
            'stage' => 'partial_quantity',
            'offer_id' => $offerId,
            'acceptance_token' => $token,
            'unit' => data_get(Cache::get('telegram-offer-message:'.$offerId), 'offer.unit', 'gram'),
            'asset' => data_get(Cache::get('telegram-offer-message:'.$offerId), 'offer.asset'),
            'channel_id' => $chat['id'] ?? null,
            'message_id' => data_get($callback, 'message.message_id'),
        ]);
        $asset = (string) data_get($offerMessage, 'offer.asset', '');
        $this->send($privateChatId, 'مقدار موردنظر برای پذیرش جزئی را وارد کنید. '.Trade::minimumQuantityMessage($asset).' ماندهٔ معامله نیز باید این حداقل را داشته باشد.', $menu);

        return true;
    }

    private function myTradeList(User $user, int $page): array
    {
        $perPage = 10;
        $response = $this->siteRequest('overview', $user);

        if ($response !== null) {
            $tradesPayload = $response['trades']['data'] ?? $response['trades'] ?? [];
            $trades = collect(is_array($tradesPayload) ? $tradesPayload : [])->filter(fn ($trade) => is_array($trade));
            $source = 'membership_api';
        } elseif ($user->exists) {
            $trades = $user->trades()->latest('traded_at')->get()->map(fn (Trade $trade) => [
                'id' => $trade->id,
                'side' => $trade->side,
                'asset' => $trade->asset,
                'unit' => $trade->unit,
                'quantity' => $trade->quantity,
                'unit_price' => $trade->unit_price,
                'total_price' => $trade->total_price,
                'status' => $trade->status,
                'traded_at' => $trade->traded_at?->toIso8601String(),
            ]);
            $source = 'local_database';
        } else {
            $trades = collect();
            $source = 'unavailable';
        }

        $trades = $trades->sortByDesc(fn (array $trade) => $trade['traded_at'] ?? $trade['created_at'] ?? $trade['id'] ?? 0)->values();
        $hasMore = $trades->count() > $page * $perPage;
        $rows = $trades->slice(($page - 1) * $perPage, $perPage);
        $assetLabels = [
            'gold' => 'طلا',
            'silver_995' => 'نقره ۹۹۵',
            'silver_999' => 'نقره ۹۹۹.۹',
            'silver_9999' => 'نقره ۹۹۹.۹',
            'full_coin' => 'تمام سکه',
            'half_coin' => 'نیم سکه',
            'quarter_coin' => 'ربع سکه',
        ];
        $unitLabels = ['gram' => 'گرم', 'mesghal' => 'مثقال', 'piece' => 'عدد', 'count' => 'عدد'];

        $text = "📋 معاملات من — صفحه {$page}\n\n";
        $text .= $rows->isEmpty() ? 'معامله‌ای برای شما ثبت نشده است.' : $rows->map(function (array $trade) use ($assetLabels, $unitLabels) {
            $side = match ($trade['side'] ?? $trade['type'] ?? '') {
                'buy' => 'خرید',
                'sell' => 'فروش',
                default => 'معامله',
            };
            $asset = $assetLabels[$trade['asset'] ?? $trade['item'] ?? ''] ?? ($trade['item_label'] ?? $trade['asset_label'] ?? '—');
            $unit = $unitLabels[$trade['unit'] ?? ''] ?? ($trade['unit'] ?? '');
            $quantity = $trade['quantity'] ?? 0;
            $unitPrice = $trade['unit_price'] ?? $trade['price_per_unit'] ?? 0;
            $total = $trade['total_price'] ?? $trade['total'] ?? 0;
            $status = $trade['status'] ?? '—';

            return '#'.($trade['id'] ?? '—')." | {$side} {$asset}\n"
                ."مقدار: {$quantity} {$unit} | قیمت واحد: ".$this->formatToman((float) $unitPrice)." تومان\n"
                .'مبلغ کل: '.$this->formatToman((float) $total)." تومان | وضعیت: {$status}";
        })->join("\n\n");

        Log::channel(config('trading.log_channel', 'trading'))->info('Personal trades displayed.', [
            'telegram_chat_id' => $user->telegram_chat_id,
            'source' => $source,
            'total_count' => $trades->count(),
            'page' => $page,
        ]);

        return [$text, $this->pagination('trades:mine', $page, $hasMore)];
    }

    private function depositList(User $admin, string $status, int $page): array
    {
        abort_unless($admin->is_admin, 403);
        $perPage = 10;
        $deposits = DepositRequest::with(['user', 'reviewer'])->where('status', $status)->latest()->forPage($page, $perPage + 1)->get();
        $hasMore = $deposits->count() > $perPage;
        $rows = $deposits->take($perPage);
        $title = $status === 'approved' ? 'فیش‌های تأییدشده' : 'فیش‌های در انتظار تأیید';
        $text = "{$title} — صفحه {$page}\n\n";
        $text .= $rows->isEmpty() ? 'موردی وجود ندارد.' : $rows->map(function (DepositRequest $deposit) use ($status) {
            $line = "#{$deposit->id} | کاربر: {$deposit->user->name}\nمبلغ: ".number_format($deposit->amount).' ریال | ثبت: '.$deposit->created_at->timezone(config('trading.timezone'))->format('Y/m/d H:i');

            return $status === 'approved' ? $line."\nتأییدکننده: ".($deposit->reviewer?->name ?? '—') : $line;
        })->join("\n\n");

        return [$text, $this->pagination("deposits:{$status}", $page, $hasMore)];
    }

    private function showCallbackList(array $callback): void
    {
        $chat = $callback['message']['chat'] ?? [];
        $user = $chat ? $this->user($chat) : null;
        $parts = explode(':', (string) ($callback['data'] ?? ''));
        $page = max(1, (int) ($parts[2] ?? 1));

        if (! $user || ! $this->hasConnectedAccess($user)) {
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'ابتدا حساب سایت را با /connect کد_اتصال متصل کنید.', 'show_alert' => true]);

            return;
        }

        if ($parts[0] === 'trades' && ($parts[1] ?? '') === 'mine') {
            $this->sendMyTradeRoomMessages($user, $chat['id'], $page);
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);

            return;
        } elseif ($parts[0] === 'trades' && ($parts[1] ?? '') === 'history') {
            $this->sendMyTradeRoomHistoryMessages($user, $chat['id'], $page);
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);

            return;
        } elseif ($parts[0] === 'deposits' && in_array($parts[1] ?? '', ['pending', 'approved'], true) && $user?->is_admin) {
            [$text, $keyboard] = $this->depositList($user, $parts[1], $page);
        } else {
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'اجازهٔ دسترسی ندارید.', 'show_alert' => true]);

            return;
        }

        $this->api('editMessageText', ['chat_id' => $chat['id'], 'message_id' => $callback['message']['message_id'], 'text' => $text, 'reply_markup' => ['inline_keyboard' => $keyboard]]);
        $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
    }

    private function flowKey(User $user): string
    {
        return 'telegram-flow:'.$user->telegram_chat_id;
    }

    private function flow(User $user): array
    {
        $telegramUserId = $user->exists ? optional($user->telegramConnection)->telegram_user_id : null;
        if (! $telegramUserId) {
            return Cache::get($this->flowKey($user), []);
        }
        $state = TelegramState::where('telegram_user_id', $telegramUserId)->first();
        if ($state?->expires_at?->isPast()) {
            $state->delete();

            return [];
        }

        return $state?->data ?? [];
    }

    private function saveFlow(User $user, array $flow): void
    {
        $telegramUserId = $user->exists ? optional($user->telegramConnection)->telegram_user_id : null;
        if (! $telegramUserId) {
            Cache::put($this->flowKey($user), $flow, now()->addHour());

            return;
        }
        TelegramState::updateOrCreate(['telegram_user_id' => $telegramUserId], ['state' => ($flow['type'] ?? 'flow').'_'.($flow['stage'] ?? 'active'), 'data' => $flow, 'expires_at' => now()->addHour()]);
    }

    private function clearFlow(User $user): void
    {
        $telegramUserId = $user->exists ? optional($user->telegramConnection)->telegram_user_id : null;
        if ($telegramUserId) {
            TelegramState::where('telegram_user_id', $telegramUserId)->delete();
        }
        Cache::forget($this->flowKey($user));
    }

    private function assetKeyboard(string $prefix): array
    {
        return $this->withMainMenuButton([[
            ['text' => 'طلا', 'callback_data' => "$prefix:gold"],
        ], [
            ['text' => 'نقره ۹۹۵', 'callback_data' => "$prefix:silver_995"], ['text' => 'نقره ۹۹۹.۹', 'callback_data' => "$prefix:silver_9999"],
        ], [
            ['text' => 'تمام سکه', 'callback_data' => "$prefix:full_coin"], ['text' => 'نیم سکه', 'callback_data' => "$prefix:half_coin"], ['text' => 'ربع سکه', 'callback_data' => "$prefix:quarter_coin"],
        ]]);
    }

    private function withMainMenuButton(array $keyboard): array
    {
        $keyboard[] = [['text' => '↩️ بازگشت به منوی اصلی', 'callback_data' => 'flow:main_menu']];

        return $keyboard;
    }

    private function flowReplyMenu(array $menu): array
    {
        if (! collect($menu)->flatten()->contains('بازگشت به منوی اصلی')) {
            $menu[] = ['بازگشت به منوی اصلی'];
        }

        return $menu;
    }

    private function isCoin(string $asset): bool
    {
        return in_array($asset, ['full_coin', 'half_coin', 'quarter_coin'], true);
    }

    private function assetLabel(string $asset): string
    {
        return [
            'gold' => 'طلا', 'silver_995' => 'نقره ۹۹۵', 'silver_9999' => 'نقره ۹۹۹.۹',
            'full_coin' => 'تمام سکه', 'half_coin' => 'نیم سکه', 'quarter_coin' => 'ربع سکه',
        ][$asset] ?? $asset;
    }

    private function siteAsset(string $asset): string
    {
        return $asset === 'silver_9999' ? 'silver_999' : $asset;
    }

    private function siteUnit(string $unit): string
    {
        return $unit === 'count' ? 'piece' : $unit;
    }

    private function inventoryQuantity(string $unit, string $asset, float $quantity): float
    {
        // Website inventory stores metals in grams; coins are stored as pieces.
        return ! $this->isCoin($asset) && $unit === 'mesghal'
            ? round($quantity * 4.608, 3)
            : $quantity;
    }

    private function accountSummary(User $user): string
    {
        $overview = $this->siteRequest('overview', $user);
        if (! $overview) {
            return 'دریافت موجودی از سایت ناموفق بود؛ دوباره تلاش کنید.';
        }

        $assets = is_array($overview['assets'] ?? null) ? $overview['assets'] : $overview;
        $quantity = function (string $key) use ($assets, $overview): float {
            $value = $assets[$key] ?? $overview[$key] ?? 0;
            if (is_array($value)) {
                $value = $value['quantity'] ?? $value['balance'] ?? 0;
            }

            return (float) $value;
        };
        $wallet = (int) ($overview['wallet_balance'] ?? 0);
        $metals = [
            'طلا' => ['gold', 'گرم'],
            'نقره ۹۹۵' => ['silver_995', 'گرم'],
            'نقره ۹۹۹/۹' => ['silver_999', 'گرم'],
            'تمام سکه' => ['full_coin', 'عدد'],
            'نیم سکه' => ['half_coin', 'عدد'],
            'ربع سکه' => ['quarter_coin', 'عدد'],
        ];

        $collateral = (int) ($overview['asset_collateral_available'] ?? $overview['collateral_available'] ?? 0);
        $lines = ['💳 کیف پول: '.number_format($wallet).' تومان'];
        if ($collateral > 0) {
            $lines[] = '🧾 اعتبار بیعانه دارایی: '.number_format($collateral).' تومان';
        }
        $lines[] = '';
        $lines[] = '📦 دارایی‌ها:';
        foreach ($metals as $label => [$key, $unit]) {
            $lines[] = $label.': '.rtrim(rtrim(number_format($quantity($key), 3, '.', ''), '0'), '.').' '.$unit;
        }

        return implode("\n", $lines);
    }

    private function meetsMinimumTradeQuantity(string $unit, float $quantity, string $asset): bool
    {
        return Trade::meetsMinimumQuantity($unit, $quantity, $asset);
    }

    private function completeTelegramTrade(User $user, array $flow, array $chat, array $menu): void
    {
        $selection = filled($flow['last_selection'] ?? null) ? $flow['last_selection']."\n\n" : '';

        if (! $this->hasVipAccess($user)) {
            $this->sendMembershipPrompt($chat['id'], $menu);

            return;
        }

        if (($flow['unit'] ?? '') === 'mesghal') {
            $this->clearFlow($user);
            $this->send($chat['id'], $selection.'معامله بر حسب مثقال غیرفعال است؛ معامله جدید را بر حسب گرم ثبت کنید.', $menu);

            return;
        }

        if (! $this->meetsMinimumTradeQuantity($flow['unit'], (float) $flow['quantity'], $flow['asset'])) {
            $this->send($chat['id'], $selection.Trade::minimumQuantityMessage($flow['asset']), $menu);

            return;
        }

        try {
            $alias = $this->tradeAlias($user);
            $siteTrade = $this->siteRequest('trade-room/offers/create', $user, [
                'side' => $flow['side'],
                'asset' => $this->siteAsset($flow['asset']),
                'unit' => $this->siteUnit($flow['unit']),
                'quantity' => $flow['quantity'],
                'unit_price' => $flow['unit_price'],
                'allow_partial' => (bool) ($flow['allow_partial'] ?? true),
                'expires_at' => now()->addMinutes(2)->toIso8601String(),
                'alias' => $alias,
            ]);
            if (! $siteTrade) {
                throw new \RuntimeException($this->lastSiteError ?: 'اعتبار کیف پول یا موجودی دارایی در سایت تأیید نشد.');
            }
            $siteTrade = $siteTrade['offer'] ?? $siteTrade['trade'] ?? $siteTrade;
            $unitPrice = (int) ($siteTrade['unit_price'] ?? $siteTrade['price_per_unit'] ?? $flow['unit_price']);
            $trade = (object) [
                'unit_price' => $unitPrice,
                'total_price' => (int) ($siteTrade['total_price'] ?? $siteTrade['total'] ?? ((float) $flow['quantity'] * $unitPrice)),
            ];
            $channelOffer = [
                ...$siteTrade,
                'id' => $siteTrade['id'] ?? $siteTrade['offer_id'] ?? null,
                'side' => $flow['side'],
                'asset' => $this->siteAsset($flow['asset']),
                'unit' => $this->siteUnit($flow['unit']),
                'quantity' => (float) $flow['quantity'],
                'unit_price' => $unitPrice,
                'total_price' => $trade->total_price,
                'alias' => $alias,
                'owner_telegram_chat_id' => (string) $user->telegram_chat_id,
                'expires_at' => now()->addMinutes(2)->toIso8601String(),
                'status' => 'active',
                'allow_partial' => (bool) ($siteTrade['allow_partial'] ?? $flow['allow_partial'] ?? true),
            ];
            $published = $this->publishOfferToChannel($channelOffer);
            if (! $published) {
                Log::channel(config('trading.log_channel', 'trading'))->warning('Trade was created but could not be published to its Telegram channel.', [
                    'offer_id' => $channelOffer['id'],
                    'asset' => $channelOffer['asset'],
                ]);
            }
            $this->clearFlow($user);
            $status = $published ? 'معامله ثبت و به کانال دارایی ارسال شد.' : 'معامله ثبت شد، اما ارسال به کانال انجام نشد؛ تنظیم کانال را بررسی کنید.';
            $this->send($chat['id'], $selection.$status."\n".'قیمت واحد: '.$this->formatToman($trade->unit_price).' تومان'."\n".'مبلغ کل: '.$this->formatToman($trade->total_price).' تومان', $menu);
        } catch (\Throwable $e) {
            $this->send($chat['id'], $selection.'ثبت معامله انجام نشد: '.$e->getMessage(), $menu);
        }
    }

    private function handleFlowCallback(array $callback, User $user, array $menu): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, 'flow:')) {
            return false;
        }
        $chat = $callback['message']['chat'];
        $parts = explode(':', $data);
        $flow = $this->flow($user);
        $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);

        if ($data === 'flow:main_menu') {
            $this->clearFlow($user);
            $this->send($chat['id'], 'به منوی اصلی برگشتید.', $menu);

            return true;
        }

        // The button label and its side must have the same meaning.
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'asset') {
            $this->saveFlow($user, ['type' => 'trade', 'stage' => 'side', 'asset' => $parts[3]]);
            $this->sendInline($chat['id'], 'دارایی «'.$this->assetLabel((string) ($parts[3] ?? ''))."» انتخاب شد.\n\nنوع معامله را انتخاب کنید:", $this->withMainMenuButton([[
                ['text' => 'فروش', 'callback_data' => 'flow:trade:side:sell'],
                ['text' => 'خرید', 'callback_data' => 'flow:trade:side:buy'],
            ]]));

            return true;
        }

        if ($data === 'flow:deposit:paid') {
            $this->saveFlow($user, ['type' => 'deposit', 'stage' => 'amount']);
            $this->send($chat['id'], "گزینه «واریز کردم» انتخاب شد.\n\nمبلغ واریزی را به ریال وارد کنید.", $this->flowReplyMenu($menu));

            return true;
        }
        if ($data === 'flow:delivery:start') {
            $this->saveFlow($user, ['type' => 'delivery', 'stage' => 'asset']);
            $this->sendInline($chat['id'], "گزینه «تحویل به فروشگاه» انتخاب شد.\n\nدارایی تحویل‌داده‌شده را انتخاب کنید:", $this->assetKeyboard('flow:delivery:asset'));

            return true;
        }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'side' && ($flow['type'] ?? '') === 'trade') {
            $flow['side'] = $parts[3];
            $flow['unit'] = $this->isCoin($flow['asset']) ? 'count' : 'gram';
            $flow['stage'] = 'quantity';
            $this->saveFlow($user, $flow);
            $selected = 'نوع معامله «'.(($parts[3] ?? '') === 'buy' ? 'خرید' : 'فروش').'» انتخاب شد.';
            if ($this->isCoin($flow['asset'])) {
                $this->send($chat['id'], $selected."\n\nتعداد سکه را وارد کنید.", $this->flowReplyMenu($menu));
            } else {
                $this->send($chat['id'], $selected."\n\nمقدار معامله را به گرم وارد کنید.", $this->flowReplyMenu($menu));
            }

            return true;
        }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'unit' && ($flow['type'] ?? '') === 'trade') {
            if (($parts[3] ?? '') !== 'gram') {
                $this->send($chat['id'], 'معامله بر حسب مثقال غیرفعال است؛ مقدار را به گرم وارد کنید.', $this->flowReplyMenu($menu));

                return true;
            }
            $flow['unit'] = 'gram';
            $flow['stage'] = 'quantity';
            $this->saveFlow($user, $flow);
            $this->send($chat['id'], "واحد «گرم» انتخاب شد.\n\nمقدار معامله را وارد کنید.", $this->flowReplyMenu($menu));

            return true;
        }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'price' && ($flow['type'] ?? '') === 'trade') {
            if (($parts[3] ?? '') === 'default') {
                $flow['stage'] = 'partial_mode';
                $this->saveFlow($user, $flow);
                $this->sendInline($chat['id'], "قیمت سایت انتخاب شد.\n\nآیا پذیرش بخشی از این معامله مجاز باشد؟", $this->withMainMenuButton([[['text' => 'بله، جزئی یا کامل', 'callback_data' => 'flow:trade:partial:yes'], ['text' => 'خیر، فقط کامل', 'callback_data' => 'flow:trade:partial:no']]]));
            } else {
                $flow['stage'] = 'custom_price';
                $this->saveFlow($user, $flow);
                $this->send($chat['id'], "ورود قیمت دلخواه انتخاب شد.\n\nقیمت واحد دلخواه را به تومان وارد کنید.", $this->flowReplyMenu($menu));
            }

            return true;
        }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'partial' && ($flow['type'] ?? '') === 'trade') {
            $flow['allow_partial'] = ($parts[3] ?? '') === 'yes';
            $flow['last_selection'] = $flow['allow_partial'] ? 'پذیرش جزئی یا کامل انتخاب شد.' : 'پذیرش فقط به‌صورت کامل انتخاب شد.';
            $this->completeTelegramTrade($user, $flow, $chat, $menu);

            return true;
        }
        if (($parts[1] ?? '') === 'delivery' && ($parts[2] ?? '') === 'asset') {
            $flow = ['type' => 'delivery', 'stage' => 'unit', 'asset' => $parts[3]];
            $selected = 'دارایی «'.$this->assetLabel((string) ($parts[3] ?? '')).'» انتخاب شد.';
            if ($this->isCoin($flow['asset'])) {
                $flow['unit'] = 'count';
                $flow['stage'] = 'quantity';
                $this->saveFlow($user, $flow);
                $this->send($chat['id'], $selected."\n\nتعداد سکه تحویل‌داده‌شده را وارد کنید.", $this->flowReplyMenu($menu));
            } else {
                $this->saveFlow($user, $flow);
                $this->sendInline($chat['id'], $selected."\n\nواحد را انتخاب کنید:", $this->withMainMenuButton([[['text' => 'گرم', 'callback_data' => 'flow:delivery:unit:gram'], ['text' => 'مثقال', 'callback_data' => 'flow:delivery:unit:mesghal']]]));
            }

            return true;
        }
        if (($parts[1] ?? '') === 'delivery' && ($parts[2] ?? '') === 'unit' && ($flow['type'] ?? '') === 'delivery') {
            $flow['unit'] = $parts[3];
            $flow['stage'] = 'quantity';
            $this->saveFlow($user, $flow);
            $unit = ($parts[3] ?? '') === 'gram' ? 'گرم' : 'مثقال';
            $this->send($chat['id'], "واحد «{$unit}» انتخاب شد.\n\nمقدار تحویل‌داده‌شده را وارد کنید.", $this->flowReplyMenu($menu));

            return true;
        }

        return true;
    }

    private function handleDeliveryCallback(array $callback, User $user, array $menu): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, 'flow:delivery:')) {
            return false;
        }

        $chat = $callback['message']['chat'];
        $parts = explode(':', $data);
        $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);

        if ($data === 'flow:delivery:start') {
            $this->saveFlow($user, ['type' => 'delivery', 'stage' => 'asset']);
            $this->sendInline($chat['id'], "گزینه «تحویل به فروشگاه» انتخاب شد.\n\nدارایی تحویلی را انتخاب کنید:", $this->assetKeyboard('flow:delivery:asset'));

            return true;
        }

        if (($parts[2] ?? '') === 'asset') {
            $flow = ['type' => 'delivery', 'stage' => 'unit', 'asset' => $parts[3] ?? ''];
            $selected = 'دارایی «'.$this->assetLabel((string) ($parts[3] ?? '')).'» انتخاب شد.';
            if ($this->isCoin($flow['asset'])) {
                $flow['unit'] = 'count';
                $flow['stage'] = 'quantity';
                $this->saveFlow($user, $flow);
                $this->send($chat['id'], $selected."\n\nتعداد سکهٔ تحویلی را وارد کنید.", $this->flowReplyMenu($menu));
            } else {
                $this->saveFlow($user, $flow);
                $this->sendInline($chat['id'], $selected."\n\nواحد وزن را انتخاب کنید:", $this->withMainMenuButton([[['text' => 'گرم', 'callback_data' => 'flow:delivery:unit:gram'], ['text' => 'مثقال', 'callback_data' => 'flow:delivery:unit:mesghal']]]));
            }

            return true;
        }

        if (($parts[2] ?? '') === 'unit') {
            $flow = $this->flow($user);
            $flow['unit'] = $parts[3] ?? '';
            $flow['stage'] = 'quantity';
            $this->saveFlow($user, $flow);
            $unit = ($parts[3] ?? '') === 'gram' ? 'گرم' : 'مثقال';
            $this->send($chat['id'], "واحد «{$unit}» انتخاب شد.\n\nمقدار وزن تحویلی را وارد کنید.", $this->flowReplyMenu($menu));

            return true;
        }

        return false;
    }

    private function handleAssetCollateralCallback(array $callback, User $user, array $menu): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, 'flow:collateral:')) {
            return false;
        }

        $chat = $callback['message']['chat'];
        $parts = explode(':', $data);
        $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);

        if ($data === 'flow:collateral:start') {
            $this->saveFlow($user, ['type' => 'collateral', 'stage' => 'asset']);
            $this->sendInline($chat['id'], "گزینه «ثبت بیعانه دارایی» انتخاب شد.\n\nدارایی بیعانه را انتخاب کنید:", $this->assetKeyboard('flow:collateral:asset'));

            return true;
        }

        if (($parts[2] ?? '') === 'asset') {
            $flow = ['type' => 'collateral', 'stage' => 'unit', 'asset' => $parts[3] ?? ''];
            $selected = 'دارایی «'.$this->assetLabel((string) ($parts[3] ?? '')).'» انتخاب شد.';
            if ($this->isCoin($flow['asset'])) {
                $flow['unit'] = 'count';
                $flow['stage'] = 'quantity';
                $this->saveFlow($user, $flow);
                $this->send($chat['id'], $selected."\n\nتعداد سکه بیعانه را وارد کنید.", $this->flowReplyMenu($menu));
            } else {
                $this->saveFlow($user, $flow);
                $this->sendInline($chat['id'], $selected."\n\nواحد وزن بیعانه را انتخاب کنید:", $this->withMainMenuButton([[['text' => 'گرم', 'callback_data' => 'flow:collateral:unit:gram'], ['text' => 'مثقال', 'callback_data' => 'flow:collateral:unit:mesghal']]]));
            }

            return true;
        }

        if (($parts[2] ?? '') === 'unit') {
            $flow = $this->flow($user);
            $flow['unit'] = $parts[3] ?? '';
            $flow['stage'] = 'quantity';
            $this->saveFlow($user, $flow);
            $unit = ($parts[3] ?? '') === 'gram' ? 'گرم' : 'مثقال';
            $this->send($chat['id'], "واحد «{$unit}» انتخاب شد.\n\nمقدار بیعانه را وارد کنید.", $this->flowReplyMenu($menu));

            return true;
        }

        return false;
    }

    private function callbackQueueConnection(array $update): string
    {
        $data = (string) data_get($update, 'callback_query.data', '');

        // These callbacks only advance the local trade wizard. Running them
        // immediately after the webhook response avoids the database worker's
        // polling delay between choosing an asset and choosing buy or sell.
        if (preg_match('/^flow:trade:(?:asset|side):/', $data)) {
            return (string) config('services.telegram.fast_callback_queue_connection', 'deferred');
        }

        // Transactional callbacks (creating, accepting or deleting an offer)
        // continue to use the durable worker queue.
        return (string) config('services.telegram.callback_queue_connection', 'database');
    }

    public function __invoke(Request $request, TalaboardClient $prices, TelegramConnectionService $connections)
    {
        $this->traceId = (string) str()->uuid();

        if ($secret = env('TELEGRAM_WEBHOOK_SECRET')) {
            if (! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
                $this->audit('webhook.rejected', ['reason' => 'invalid_secret'], 'warning');
                abort(403);
            }
        }

        if ($reply = $this->fastStartWebhookReply($request)) {
            defer(fn () => $this->audit('webhook.fast_start', [
                'update_id' => $request->input('update_id'),
                'chat_id' => $request->input('message.chat.id'),
            ]));

            return response()->json($reply);
        }

        if ($reply = $this->fastLivePriceWebhookReply($request, $prices)) {
            defer(fn () => $this->audit('webhook.fast_live_prices', [
                'update_id' => $request->input('update_id'),
                'chat_id' => $request->input('message.chat.id'),
            ]));

            return response()->json($reply);
        }

        $this->audit('webhook.accepted', [
            'update_id' => $request->input('update_id'),
            'queue' => config('services.telegram.webhook_queue'),
            'has_message' => (bool) $request->input('message'),
            'has_callback' => (bool) $request->input('callback_query'),
        ]);

        $asyncWebhook = (bool) config('services.telegram.async_webhook', true);
        $update = $request->all();
        $callbackId = data_get($update, 'callback_query.id');

        if ($asyncWebhook && $callbackId) {
            $update['_callback_pre_answered'] = true;
            $callbackQueueConnection = $this->callbackQueueConnection($update);

            try {
                ProcessTelegramCallback::dispatch($update)->onConnection($callbackQueueConnection);
            } catch (\Throwable $exception) {
                $this->audit('callback.dispatch.failed', [
                    'queue_connection' => $callbackQueueConnection,
                    'queue' => config('services.telegram.callback_queue'),
                    'exception' => $exception::class,
                ], 'error');

                return response()->json([
                    'method' => 'answerCallbackQuery',
                    'callback_query_id' => $callbackId,
                    'text' => 'ثبت درخواست ناموفق بود؛ دوباره تلاش کنید.',
                    'show_alert' => true,
                ]);
            }

            return response()->json([
                'method' => 'answerCallbackQuery',
                'callback_query_id' => $callbackId,
                'text' => 'در حال انجام…',
            ]);
        }

        if ($request->filled('update_id')) {
            $inserted = DB::table('telegram_updates')->insertOrIgnore([
                'update_id' => (int) $request->input('update_id'),
                'processed_at' => now(),
            ]);
            if ($inserted === 0) {
                return response()->noContent();
            }
        }

        if ($asyncWebhook) {
            // Message replies can be returned as a Bot API method in the
            // webhook response itself. This bypasses slow/filtered outbound
            // connectivity from the VPS to api.telegram.org.
            if ($request->input('message') && config('services.telegram.fast_webhook_reply', true)) {
                if ($request->input('message.photo')) {
                    ProcessTelegramUpdate::dispatch($update);

                    return response()->json([
                        'method' => 'sendMessage',
                        'chat_id' => $request->input('message.chat.id'),
                        'text' => 'تصویر دریافت شد و در حال پردازش است.',
                    ]);
                }

                // Linking is one of the first interactions a new user has
                // with the bot and requires a round trip to the membership
                // service. Acknowledge it through the webhook immediately,
                // then perform the slow validation after the response.
                $messageText = trim((string) $request->input('message.text', ''));
                if (preg_match('/^\/connect\s+\d{6}$/', $messageText)) {
                    try {
                        ProcessTelegramUpdate::dispatch($update);

                        return response()->json([
                            'method' => 'sendMessage',
                            'chat_id' => $request->input('message.chat.id'),
                            'text' => 'در حال اتصال حساب شما…',
                        ]);
                    } catch (\Throwable $exception) {
                        $this->audit('connect.dispatch.failed', [
                            'queue' => config('services.telegram.webhook_queue'),
                            'exception' => $exception::class,
                        ], 'error');
                    }
                }

                $this->captureWebhookReply = true;
                $request->attributes->set('telegram_ingress_verified', true);
                $processed = $this->process($request, $prices, $connections);

                return $this->webhookReply
                    ? response()->json($this->webhookReply)
                    : $processed;
            }

            try {
                ProcessTelegramUpdate::dispatch($update);
            } catch (\Throwable $exception) {
                $this->audit('dispatch.failed', [
                    'queue' => config('services.telegram.webhook_queue'),
                    'exception' => $exception::class,
                ], 'error');
                $request->attributes->set('telegram_ingress_verified', true);

                return $this->process($request, $prices, $connections);
            }

            return response()->noContent();
        }

        $request->attributes->set('telegram_ingress_verified', true);

        return $this->process($request, $prices, $connections);
    }

    public function process(Request $request, TalaboardClient $prices, TelegramConnectionService $connections)
    {
        $startedAt = microtime(true);
        $this->traceId = (string) str()->uuid();
        $this->callbackPreAnswered = (bool) $request->input('_callback_pre_answered', false);
        $this->callbackChatId = $request->input('callback_query.message.chat.id');
        defer(fn () => $this->audit('update.completed', [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ], 'info'));
        $this->audit('update.received', ['update_id' => $request->input('update_id'), 'has_message' => (bool) $request->input('message'), 'has_callback' => (bool) $request->input('callback_query')], 'info');
        // Keep process() safe when called directly, while avoiding duplicate
        // ingress checks for background jobs that have already passed them.
        if (! $request->attributes->get('telegram_ingress_verified', false)) {
            if ($secret = env('TELEGRAM_WEBHOOK_SECRET')) {
                abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
            }

            if ($request->filled('update_id')) {
                $inserted = DB::table('telegram_updates')->insertOrIgnore([
                    'update_id' => (int) $request->input('update_id'),
                    'processed_at' => now(),
                ]);
                if ($inserted === 0) {
                    return response()->noContent();
                }
            }
        }

        $menu = self::MAIN_MENU;
        if ($callback = $request->input('callback_query')) {
            $chat = $callback['message']['chat'] ?? [];
            $user = $this->callbackUser($callback);
            $callbackData = (string) ($callback['data'] ?? '');
            $this->audit('callback.received', ['callback_id' => $callback['id'] ?? null, 'data' => $callbackData, 'chat_id' => $chat['id'] ?? null, 'from_id' => data_get($callback, 'from.id')]);
            if ($user && $callbackData === 'flow:deposit:paid') {
                $this->saveFlow($user, ['type' => 'deposit', 'stage' => 'amount']);
                $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
                $this->send($chat['id'], "گزینه «واریز کردم» انتخاب شد.\n\nمبلغ واریزی را به ریال وارد کنید.", $this->flowReplyMenu($menu));

                return response()->noContent();
            }
            if (str_starts_with($callbackData, 'trades:mine:') || str_starts_with($callbackData, 'trades:history:') || str_starts_with($callbackData, 'deposits:')) {
                $this->showCallbackList($callback);

                return response()->noContent();
            }
            if ($user && $this->handleTradeAcceptCallback($callback, $user, $menu)) {
                return response()->noContent();
            }
            if ($user && $this->handleTradeDeleteCallback($callback, $user, $menu)) {
                return response()->noContent();
            }
            if ($user && $this->handleDeliveryCallback($callback, $user, $menu)) {
                return response()->noContent();
            }
            if ($user && $this->handleAssetCollateralCallback($callback, $user, $menu)) {
                return response()->noContent();
            }
            if ($user && $this->handleFlowCallback($callback, $user, $menu)) {
                return response()->noContent();
            }
            if (str_starts_with((string) ($callback['data'] ?? ''), 'deposit:approve:')) {
                $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'تأیید فیش فقط از پنل مدیریت سایت انجام می‌شود.', 'show_alert' => true]);
            } elseif (str_starts_with((string) ($callback['data'] ?? ''), 'trades:') || str_starts_with((string) ($callback['data'] ?? ''), 'deposits:')) {
                $this->showCallbackList($callback);
            }

            return response()->noContent();
        }

        $message = $request->input('message');
        if (! $message) {
            return response()->noContent();
        }
        $chat = $message['chat'];
        $user = $this->user($chat);
        $text = trim($message['text'] ?? '');
        $photo = $message['photo'] ?? [];
        $this->audit('message.received', ['chat_id' => $chat['id'] ?? null, 'from_id' => data_get($message, 'from.id'), 'text' => $text, 'has_photo' => (bool) $photo]);
        if (preg_match('/^\/connect\s+(\d{6})$/', $text, $matches)) {
            try {
                if ($this->usesMembershipApi()) {
                    $member = $this->connectWebsiteAccount($message, $matches[1]);
                    if (! $member || ! ($member['linked'] ?? false)) {
                        $this->send($chat['id'], $this->lastSiteError ?: 'کد اتصال نامعتبر است یا اعتبار آن تمام شده است.', $menu);
                    } else {
                        Cache::forever('telegram-private-chat:'.data_get($message, 'from.id'), (string) $chat['id']);
                        Cache::put('telegram-linked:'.$chat['id'], true, now()->addDay());
                        Cache::put('telegram-membership:'.$chat['id'], $member, now()->addDay());
                        $this->membershipByChat[(string) $chat['id']] = $member;
                        $menu = $this->menuForMembership($user, $menu);
                        $this->send($chat['id'], 'حساب سایت شما با موفقیت به تلگرام متصل شد.', $menu);
                    }
                } else {
                    $connections->connect($matches[1], (string) data_get($message, 'from.id'), (string) $chat['id'], data_get($message, 'from.username'));
                    Cache::forever('telegram-private-chat:'.data_get($message, 'from.id'), (string) $chat['id']);
                    Cache::put('telegram-linked:'.$chat['id'], true, now()->addDay());
                    $membership = ['linked' => true, 'vip' => false, 'membership_status' => 'none'];
                    Cache::put('telegram-membership:'.$chat['id'], $membership, now()->addDay());
                    $this->membershipByChat[(string) $chat['id']] = $membership;
                    $this->send($chat['id'], 'حساب سایت شما با موفقیت به تلگرام متصل شد.', $menu);
                }
            } catch (ValidationException $exception) {
                $this->send($chat['id'], $exception->errors()['code'][0] ?? $exception->errors()['telegram_user_id'][0] ?? 'اتصال انجام نشد.', $menu);
            }

            return response()->noContent();
        }
        if ($text === '/start') {
            $welcome = 'به ربات معاملات برخط طلا و نقره خوش آمدید.';

            // Do not make the welcome message wait for an uncached membership
            // API request. A connected local user or a cached remote member is
            // enough to hide the optional connection hint; unknown users can
            // still continue immediately while the membership cache is cold.
            $cachedMembership = Cache::get('telegram-membership:'.(string) $user->telegram_chat_id);
            if (! $user->exists && ! (bool) data_get($cachedMembership, 'linked', false)) {
                $welcome .= "\n\nاگر حساب سایت هنوز متصل نیست، کد اتصال را با این فرمت بفرستید:\n\n/connect CODE";
            }
            $this->send($chat['id'], $welcome, $menu);

            return response()->noContent();
        }
        if (str_starts_with($text, '/connect')) {
            $this->send($chat['id'], "فرمت صحیح: /connect CODE\n\nCODE باید کد ۶ رقمی ساخته‌شده در پروفایل سایت باشد و تا ۱۰ دقیقه اعتبار دارد.", $menu);

            return response()->noContent();
        }
        if (in_array($text, ['ثبت نام عضویت ویژه', 'عضویت ویژه'], true)) {
            $this->sendMembershipPrompt($chat['id'], $menu);

            return response()->noContent();
        }
        if ($text === 'وضعیت من' || $text === 'وضعیت حساب' || $text === 'وضعیت عضویت') {
            $membership = $this->siteMembership($user);
            $menu = $this->menuForMembership($user, $menu);
            $status = (bool) ($membership['vip'] ?? false) || (int) ($membership['membership_level'] ?? 0) >= 2
                ? 'عضو ویژه'
                : (($membership['membership_status'] ?? '') === 'pending' ? 'درخواست عضویت ویژه در حال بررسی' : 'عضو عادی');
            $this->send($chat['id'], "وضعیت عضویت شما: {$status}", $menu);
            if ($status !== 'عضو ویژه') {
                $this->sendMembershipPrompt($chat['id'], $menu);
            }

            return response()->noContent();
        }
        if (! $this->hasConnectedAccess($user)) {
            $this->audit('access.denied', ['reason' => 'not_linked', 'chat_id' => $chat['id'] ?? null, 'text' => $text], 'notice');
            $this->send($chat['id'], "لطفاً کد کانکت را وارد کنید:\n\n/connect CODE", $menu);

            return response()->noContent();
        }
        if (! $this->hasVipAccess($user)) {
            $this->audit('access.denied', ['reason' => 'vip_required', 'chat_id' => $chat['id'] ?? null, 'text' => $text], 'notice');
            $this->sendMembershipPrompt($chat['id'], $menu);

            return response()->noContent();
        }
        $menu = $this->menuForMembership($user, $menu);
        $flow = $this->flow($user);
        if ($text === 'بازگشت به منوی اصلی') {
            $this->clearFlow($user);
            $this->send($chat['id'], 'به منوی اصلی برگشتید.', $menu);

            return response()->noContent();
        }
        if ($text === 'قیمت لحظه‌ای') {
            $this->send($chat['id'], $this->livePricesText($prices), $menu);

            return response()->noContent();
        }
        if (in_array($text, ['افزایش موجودی انبار', 'افزایش موجودی', 'درخواست افزایش موجودی'], true)) {
            $this->sendInline($chat['id'], 'دارایی تحویلی را انتخاب کنید. پس از ثبت، درخواست تحویل به فروشگاه برای تأیید یا رد ادمین ارسال می‌شود.', $this->withMainMenuButton([[['text' => 'تحویل به فروشگاه', 'callback_data' => 'flow:delivery:start']]]));

            return response()->noContent();
        }
        if ($text === 'بیعانه دارایی') {
            $this->sendInline($chat['id'], 'برای بیعانه، دارایی را انتخاب کنید. پس از ثبت، ادمین در سایت سقف مجاز معامله را برای این بیعانه تعیین می‌کند.', $this->withMainMenuButton([[['text' => 'ثبت بیعانه دارایی', 'callback_data' => 'flow:collateral:start']]]));

            return response()->noContent();
        }
        if ($text === 'واریز وجه' || $text === 'شارژ کیف پول') {
            $this->sendInline($chat['id'], 'شماره کارت: '.config('trading.card_number')."\nشماره حساب: ".config('trading.account_number')."\nشماره شبا: ".config('trading.iban')."\nبه نام: ".config('trading.account_holder')."\n\nپس از واریز، گزینهٔ زیر را بزنید تا مبلغ و تصویر فیش را ارسال کنید.", $this->withMainMenuButton([[['text' => 'واریز کردم', 'callback_data' => 'flow:deposit:paid']]]));

            return response()->noContent();
        }
        if ($text === 'کیف پول و دارایی‌ها' || $text === 'وضعیت حساب') {
            $this->send($chat['id'], $this->accountSummary($user), $menu);

            return response()->noContent();
        }
        if ($text === 'کانال‌های خرید و فروش') {
            $this->send($chat['id'], $this->tradeChannelsText(), $menu);

            return response()->noContent();
        }
        if ($text === 'معاملات من') {
            if (! $this->hasVipAccess($user)) {
                $this->sendMembershipPrompt($chat['id'], $menu);

                return response()->noContent();
            }
            $this->sendMyTradeRoomMessages($user, $chat['id'], 1, $menu);

            return response()->noContent();
        }
        if ($text === 'سوابق من') {
            if (! $this->hasVipAccess($user)) {
                $this->sendMembershipPrompt($chat['id'], $menu);

                return response()->noContent();
            }
            $this->sendMyTradeRoomHistoryMessages($user, $chat['id'], 1, $menu);

            return response()->noContent();
        }
        if ($text === 'نام مستعار') {
            $this->saveFlow($user, ['type' => 'alias', 'stage' => 'value']);
            $this->send($chat['id'], 'نام مستعار دلخواه را وارد کنید. این نام روی معاملات ارسالی به کانال نمایش داده می‌شود.', $menu);

            return response()->noContent();
        }
        if ($photo && ($flow['type'] ?? '') === 'deposit' && ($flow['stage'] ?? '') === 'receipt') {
            $ok = $this->uploadReceiptToSite($user, (int) $flow['deposit_id'], end($photo));
            if ($ok) {
                $this->clearFlow($user);
                $this->send($chat['id'], 'فیش در سایت ثبت شد و در انتظار تأیید ادمین است.', $menu);
            } else {
                $this->send($chat['id'], 'ارسال فیش به سایت ناموفق بود؛ دوباره تلاش کنید.', $menu);
            }

            return response()->noContent();
        }
        if (($flow['type'] ?? '') === 'deposit' && ($flow['stage'] ?? '') === 'amount') {
            if (! is_numeric($text) || (int) $text < 10000) {
                $this->send($chat['id'], 'مبلغ معتبر (حداقل ۱۰٬۰۰۰ ریال) را وارد کنید.', $menu);
            } else {
                $deposit = $this->siteRequest('deposits', $user, ['amount' => (int) $text]);
                if ($deposit && isset($deposit['id'])) {
                    $flow['deposit_id'] = $deposit['id'];
                    $flow['stage'] = 'receipt';
                    $this->saveFlow($user, $flow);
                    $this->send($chat['id'], 'اکنون تصویر فیش واریزی را ارسال کنید.', $menu);
                } else {
                    $this->send($chat['id'], 'ثبت درخواست شارژ در سایت ناموفق بود؛ دوباره تلاش کنید.', $menu);
                }
            }

            return response()->noContent();
        }
        if (($flow['type'] ?? '') === 'delivery' && ($flow['stage'] ?? '') === 'quantity') {
            if (! is_numeric($text) || (float) $text <= 0) {
                $this->send($chat['id'], 'مقدار معتبر را وارد کنید.', $menu);
            } else {
                $delivery = $this->siteRequest('inventory-increase', $user, [
                    'item' => $this->siteAsset($flow['asset']),
                    'quantity' => $this->inventoryQuantity($flow['unit'], $flow['asset'], (float) $text),
                ]);
                if ($delivery) {
                    $this->clearFlow($user);
                    $this->send($chat['id'], 'درخواست تحویل دارایی به سایت ارسال شد و در انتظار تأیید فروشگاه است.', $menu);
                } else {
                    $this->send($chat['id'], 'ارسال درخواست تحویل به سایت ناموفق بود؛ دوباره تلاش کنید.', $menu);
                }
            }

            return response()->noContent();
        }
        if (($flow['type'] ?? '') === 'collateral' && ($flow['stage'] ?? '') === 'quantity') {
            if (! is_numeric($text) || (float) $text <= 0) {
                $this->send($chat['id'], 'مقدار معتبر بیعانه را وارد کنید.', $menu);
            } else {
                $collateral = $this->siteRequest('asset-collaterals', $user, [
                    'asset' => $this->siteAsset($flow['asset']),
                    'quantity' => $this->inventoryQuantity($flow['unit'], $flow['asset'], (float) $text),
                ]);
                if ($collateral) {
                    $this->clearFlow($user);
                    $this->send($chat['id'], 'درخواست بیعانه دارایی در سایت ثبت شد. پس از تأیید و تعیین سقف معامله توسط ادمین، برای معاملات قابل استفاده است.', $menu);
                } else {
                    $this->send($chat['id'], 'ثبت درخواست بیعانه در سایت ناموفق بود؛ دوباره تلاش کنید.', $menu);
                }
            }

            return response()->noContent();
        }
        if (($flow['type'] ?? '') === 'trade_accept' && ($flow['stage'] ?? '') === 'partial_quantity') {
            $quantity = is_numeric($text) ? (float) $text : 0;
            $unit = (string) ($flow['unit'] ?? 'gram');
            if (! Trade::meetsMinimumQuantity($unit === 'piece' ? 'count' : $unit, $quantity, (string) ($flow['asset'] ?? ''))) {
                $this->send($chat['id'], Trade::minimumQuantityMessage((string) ($flow['asset'] ?? '')).' مقدار معتبر دیگری وارد کنید.', $menu);
            } else {
                $this->clearFlow($user);
                $this->acceptOffer($user, $chat['id'], (int) ($flow['offer_id'] ?? 0), $quantity, $menu, (string) ($flow['acceptance_token'] ?? ''));
            }

            return response()->noContent();
        }
        if (($flow['type'] ?? '') === 'alias' && ($flow['stage'] ?? '') === 'value') {
            $alias = trim($text);
            if (mb_strlen($alias) < 2 || mb_strlen($alias) > 30) {
                $this->send($chat['id'], 'نام مستعار باید بین ۲ تا ۳۰ نویسه باشد.', $menu);
            } else {
                Cache::forever($this->aliasKey($user), $alias);
                $this->clearFlow($user);
                $this->send($chat['id'], "نام مستعار شما روی «{$alias}» تنظیم شد.", $menu);
            }

            return response()->noContent();
        }
        if (($flow['type'] ?? '') === 'trade' && ($flow['stage'] ?? '') === 'quantity') {
            if (! is_numeric($text) || (float) $text <= 0) {
                $this->send($chat['id'], 'مقدار معتبر را وارد کنید.', $menu);
            } else {
                $flow['quantity'] = $text;
                $symbol = $this->isCoin($flow['asset']) ? $flow['asset'] : ($flow['asset'] === 'gold' ? 'gold_'.$flow['unit'] : $flow['asset'].'_'.$flow['unit']);
                $snapshot = $prices->prices()->get($symbol);
                if ($snapshot) {
                    $flow['unit_price'] = $snapshot->price;
                    $flow['stage'] = 'price';
                    $this->saveFlow($user, $flow);
                    $this->sendInline($chat['id'], 'قیمت پیش‌فرض سایت: '.$this->formatToman($snapshot->price).' تومان. انتخاب کنید:', $this->withMainMenuButton([[['text' => 'تأیید قیمت سایت', 'callback_data' => 'flow:trade:price:default'], ['text' => 'ورود قیمت دیگر', 'callback_data' => 'flow:trade:price:custom']]]));
                } else {
                    $flow['stage'] = 'custom_price';
                    $this->saveFlow($user, $flow);
                    $this->send($chat['id'], 'قیمت سایت در دسترس نیست؛ قیمت واحد را به تومان وارد کنید.', $menu);
                }
            }

            return response()->noContent();
        }
        if (($flow['type'] ?? '') === 'trade' && ($flow['stage'] ?? '') === 'custom_price') {
            if (! is_numeric($text) || (int) $text < 1) {
                $this->send($chat['id'], 'قیمت واحد معتبر را وارد کنید.', $menu);
            } else {
                $flow['unit_price'] = (int) $text * 10;
                $flow['stage'] = 'partial_mode';
                $this->saveFlow($user, $flow);
                $this->sendInline($chat['id'], 'آیا پذیرش بخشی از این معامله مجاز باشد؟', $this->withMainMenuButton([[['text' => 'بله، جزئی یا کامل', 'callback_data' => 'flow:trade:partial:yes'], ['text' => 'خیر، فقط کامل', 'callback_data' => 'flow:trade:partial:no']]]));
            }

            return response()->noContent();
        }
        if ($text === 'قیمت لحظه‌ای') {
            $labels = TalaboardClient::PRODUCTS;
            $rows = $prices->prices();
            $out = "💹 قیمت‌های لحظه‌ای (تومان)\n\n";
            foreach ($labels as $symbol => $label) {
                $out .= ($symbol === 'full_coin' || $symbol === 'half_coin' || $symbol === 'quarter_coin' ? '🪙 ' : '⚖️ ').$label.': '.($rows->get($symbol) ? $this->formatToman($rows->get($symbol)->price) : '—')."\n";
            } $this->send($chat['id'], $out, $menu);

            return response()->noContent();
        }
        if ($text === 'واریز وجه' || $text === 'شارژ کیف پول') {
            $this->sendInline($chat['id'], 'شماره حساب: '.config('trading.account_number')."\nشماره شبا: ".config('trading.iban')."\nبه نام: ".config('trading.account_holder')."\n\nپس از واریز، گزینه زیر را بزنید.", $this->withMainMenuButton([[['text' => 'واریز کردم', 'callback_data' => 'flow:deposit:paid']]]));

            return response()->noContent();
        }
        if ($text === 'افزایش موجودی' || $text === 'درخواست افزایش موجودی') {
            $this->sendInline($chat['id'], 'برای افزایش موجودی، ابتدا دارایی را به فروشگاه تحویل دهید. پس از تحویل، گزینه زیر را بزنید.', $this->withMainMenuButton([[['text' => 'تحویل دادم', 'callback_data' => 'flow:delivery:start']]]));

            return response()->noContent();
        }
        if (str_starts_with($text, '/inventory ')) {
            [, $item, $quantity] = array_pad(explode(' ', $text), 3, null);
            $result = $this->siteRequest('inventory-increase', $user, ['item' => $item, 'quantity' => $quantity]);
            $this->send($chat['id'], $result ? "درخواست {$result['label']} در سایت ثبت شد و در انتظار تأیید ادمین است." : 'ثبت درخواست در سایت ناموفق بود؛ نوع و مقدار را بررسی کنید.', $menu);

            return response()->noContent();
        }
        if (str_starts_with($text, '/deposit ')) {
            $amount = (int) trim(substr($text, 9));
            $deposit = $this->siteRequest('deposits', $user, ['amount' => $amount]);
            if ($deposit) {
                Cache::put('site-deposit:'.$user->telegram_chat_id, $deposit['id'], now()->addHour());
                $this->send($chat['id'], 'درخواست شارژ در سایت ثبت شد؛ اکنون تصویر فیش را ارسال کنید.', $menu);
            } else {
                $this->send($chat['id'], 'ثبت درخواست در سایت ناموفق بود؛ مبلغ را بررسی کنید.', $menu);
            }

            return response()->noContent();
        }
        if ($photo) {
            $depositId = Cache::pull('site-deposit:'.$user->telegram_chat_id);
            $ok = $depositId && $this->uploadReceiptToSite($user, $depositId, end($photo));
            $this->send($chat['id'], $ok ? 'فیش در سایت ذخیره شد و درخواست در انتظار تأیید ادمین است.' : 'ابتدا مبلغ را با /deposit وارد کنید یا دوباره تصویر فیش را ارسال کنید.', $menu);

            return response()->noContent();
        }
        if ($text === 'فیش‌های در انتظار تأیید' || $text === 'فیش‌های تأییدشده') {
            $this->send($chat['id'], 'فیش‌ها و تأیید آن‌ها فقط در پنل مدیریت سایت قابل مشاهده و بررسی هستند.', $menu);

            return response()->noContent();
        }
        if ($text === 'ثبت معامله') {
            if (! $this->hasVipAccess($user)) {
                $this->sendMembershipPrompt($chat['id'], $menu);

                return response()->noContent();
            } $this->saveFlow($user, ['type' => 'trade', 'stage' => 'asset']);
            $this->sendInline($chat['id'], 'دارایی معامله را انتخاب کنید:', $this->assetKeyboard('flow:trade:asset'));

            return response()->noContent();
        }
        if (str_starts_with($text, '/trade ')) {
            if (! $this->hasVipAccess($user)) {
                $this->sendMembershipPrompt($chat['id'], $menu);

                return response()->noContent();
            } [, $side, $unit, $qty] = array_pad(explode(' ', $text), 4, null);
            if ($unit === 'mesghal') {
                $this->send($chat['id'], 'معامله بر حسب مثقال غیرفعال است؛ واحد گرم را وارد کنید.', $menu);

                return response()->noContent();
            }
            $trade = $this->siteRequest('trades', $user, ['side' => $side, 'unit' => $unit, 'quantity' => $qty]);
            $this->send($chat['id'], $trade ? 'معامله در سایت ثبت شد. قیمت واحد: '.$this->formatToman($trade['price_per_unit']).' تومان، مبلغ کل: '.$this->formatToman($trade['total']).' تومان' : 'ثبت معامله در سایت ناموفق بود؛ موجودی و مقدار را بررسی کنید.', $menu);

            return response()->noContent();
        }
        $this->send($chat['id'], 'یکی از گزینه‌های منو را انتخاب کنید.', $menu);

        return response()->noContent();
    }
}
