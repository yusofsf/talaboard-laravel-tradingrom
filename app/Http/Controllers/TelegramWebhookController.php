<?php

namespace App\Http\Controllers;

use App\Models\{DepositRequest, InventoryDelivery, TelegramState, TelegramUpdate, Trade, User, WalletTransaction};
use App\Services\{TalaboardClient, TelegramConnectionService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http, Log, Schema, Storage};

class TelegramWebhookController extends Controller
{
    private function api(string $method, array $data): array
    {
        $token = config('services.telegram.token');

        if (! $token) {
            Log::channel(config('trading.log_channel', 'trading'))->warning('Telegram API token is not configured.', ['method' => $method]);
            return [];
        }

        try {
            // Telegram expects reply_markup to be a JSON object. When this was
            // sent as form data, nested keyboard arrays were not parsed as a
            // keyboard, so users only saw the "select ..." prompt.
            $response = Http::asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->post("https://api.telegram.org/bot{$token}/{$method}", $data);
            $result = $response->json() ?? [];
            Log::channel(config('trading.log_channel', 'trading'))->log(
                $response->successful() && ($result['ok'] ?? true) ? 'debug' : 'warning',
                'Telegram API responded.',
                ['method' => $method, 'status' => $response->status(), 'telegram_ok' => $result['ok'] ?? null]
            );

            return $result;
        } catch (\Throwable $exception) {
            Log::channel(config('trading.log_channel', 'trading'))->error('Telegram API request failed.', [
                'method' => $method,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    private function send($chat, string $text, array $keyboard = []): void
    {
        $this->api('sendMessage', [
            'chat_id' => $chat,
            'text' => $text,
            'reply_markup' => $keyboard ? ['keyboard' => $keyboard, 'resize_keyboard' => true] : null,
        ]);
    }

    private function sendInline($chat, string $text, array $keyboard): void
    {
        $this->api('sendMessage', ['chat_id' => $chat, 'text' => $text, 'reply_markup' => ['inline_keyboard' => $keyboard]]);
    }

    private function user(array $chat): User
    {
        if (! Schema::hasTable('telegram_connections')) {
            return new User(['telegram_chat_id' => (string) $chat['id']]);
        }
        return User::query()->whereHas('telegramConnection', fn ($query) => $query->where('telegram_chat_id', (string) $chat['id']))->first()
            ?? new User(['telegram_chat_id' => (string) $chat['id']]);
    }

    private function membershipRequest(string $endpoint, array $payload): ?array
    {
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

        try {
            $response = Http::acceptJson()->withToken($token)->timeout(8)->post("{$url}/{$endpoint}", $payload);

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

            return $response->successful() && is_array($responsePayload) ? $responsePayload : null;
        } catch (\Throwable $exception) {
            Log::channel(config('trading.log_channel', 'trading'))->error('Membership API request failed.', [
                'endpoint' => $endpoint,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
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
        if (! $telegramPath || ! $botToken || $url === '' || $token === '') return false;

        try {
            $contents = Http::get("https://api.telegram.org/file/bot{$botToken}/{$telegramPath}")->throw()->body();
            $response = Http::withToken($token)->attach('receipt', $contents, basename($telegramPath))
                ->post("{$url}/receipts", ['telegram_chat_id' => $user->telegram_chat_id, 'deposit_id' => $depositId]);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasVipAccess(User $user): bool
    {
        if ($user->exists && $user->telegramConnection()->exists()) {
            return true;
        }

        $membership = $this->siteRequest('member', $user);

        return (bool) ($membership['linked'] ?? false) && (bool) ($membership['vip'] ?? false);
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
        if ($page > 1) $buttons[] = ['text' => '◀️ قبلی', 'callback_data' => $prefix.':'.($page - 1)];
        if ($hasMore) $buttons[] = ['text' => 'بعدی ▶️', 'callback_data' => $prefix.':'.($page + 1)];

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

        $lines = ['💹 قیمت لحظه‌ای سایت (ریال)', ''];
        foreach (TalaboardClient::PRODUCTS as $symbol => $label) {
            $price = $rows->get($symbol)?->price;
            $lines[] = (TalaboardClient::PRODUCT_ICONS[$symbol] ?? '▫️').' '.$label.': '.($price ? number_format($price) : '—');
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
            ->filter(fn (array $trade) => Trade::meetsMinimumQuantity($this->tradeUnit($trade), (float) ($trade['quantity'] ?? 0)))
            ->map(fn (array $trade) => $this->normalizeOffer($trade))
            ->values();
        if ($response === null) {
            $trades = Trade::query()->tradable()->where('side', $side)->whereIn('status', ['submitted', 'active'])
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
        $statusLabels = ['active' => 'فعال', 'submitted' => 'فعال', 'accepted' => 'پذیرفته‌شده', 'rejected' => 'ردشده'];
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
            .'قیمت واحد: '.number_format((float) ($trade['unit_price'] ?? 0))." ریال\n"
            .'مبلغ کل: '.number_format((float) ($trade['total'] ?? 0))." ریال\n"
            .'وضعیت: '.($trade['status_label'] ?? 'فعال');
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }

    private function offerAcceptKeyboard(array $trade): array
    {
        if (empty($trade['id'])) {
            return [];
        }

        return [[
            ['text' => 'پذیرفتن کل', 'callback_data' => 'trade_accept:full:'.$trade['id']],
            ['text' => 'پذیرفتن جز', 'callback_data' => 'trade_accept:partial:'.$trade['id']],
        ]];
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

    private function myTradeRoomList(User $user, int $page, array $statuses = ['submitted', 'active'], string $paginationPrefix = 'trades:mine'): array
    {
        $perPage = 10;
        $response = $this->siteRequest('trade-room/offers', $user, ['mine' => true, 'status' => count($statuses) === 1 ? $statuses[0] : $statuses]);
        $trades = collect($response['offers'] ?? $response['trades'] ?? $response ?? [])
            ->filter(fn ($trade) => is_array($trade))
            ->map(fn (array $trade) => $this->normalizeOffer($trade))
            ->filter(fn (array $trade) => in_array((string) ($trade['status'] ?? ''), $statuses, true))
            ->sortByDesc(fn (array $trade) => $trade['created_at'] ?? $trade['traded_at'] ?? $trade['id'] ?? 0)
            ->values();

        if ($response === null && ! $this->usesMembershipApi() && $user->exists) {
            $query = Trade::query()->whereIn('status', $statuses)->latest('traded_at');
            if (in_array('accepted', $statuses, true)) {
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
            .'قیمت واحد: '.number_format((float) ($trade['unit_price'] ?? 0))." ریال\n"
            .'مبلغ کل: '.number_format((float) ($trade['total'] ?? 0))." ریال\n"
            .'وضعیت: '.($trade['status_label'] ?? 'فعال');
    }

    private function sendMyTradeRoomMessages(User $user, int|string $chatId, int $page, array $menu = []): void
    {
        [$rows, $pagination, $page] = $this->myTradeRoomList($user, $page);

        if ($rows->isEmpty()) {
            $this->send($chatId, "📋 معاملات من — صفحه {$page}\n\nمعامله فعالی در اتاق معاملاتی ندارید.", $menu);
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
        [$rows, $pagination, $page] = $this->myTradeRoomList($user, $page, ['accepted'], 'trades:history');

        if ($rows->isEmpty()) {
            $this->send($chatId, "📋 سوابق من — صفحه {$page}\n\nمعامله پذیرفته‌شده‌ای در اتاق معاملاتی ندارید.", $menu);
            return;
        }

        $rows->each(function (array $trade) use ($chatId, $page) {
            $this->send($chatId, $this->myOfferText($trade, $page, 'سوابق من'));
        });

        if ($pagination) {
            $this->sendInline($chatId, "📋 سوابق من — صفحه {$page}", $pagination);
        }
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
            $trade = $user->trades()->whereKey($offerId)->whereIn('status', ['submitted', 'active'])->first();
            if ($trade) {
                $trade->delete();
                $deleted = ['deleted' => true];
            }
        }

        Log::channel(config('trading.log_channel', 'trading'))->info('Personal trade-room offer deletion requested from Telegram.', [
            'telegram_chat_id' => $user->telegram_chat_id,
            'offer_id' => $offerId,
            'deleted' => $deleted !== null,
        ]);

        $this->api('answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
            'text' => $deleted ? 'معامله حذف شد.' : 'حذف معامله ناموفق بود.',
            'show_alert' => $deleted === null,
        ]);

        if ($deleted) {
            $messageId = data_get($callback, 'message.message_id');
            $messageId
                ? $this->api('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId])
                : $this->send($chatId, 'معامله از اتاق معاملاتی حذف شد.', $menu);
        }

        return true;
    }

    private function acceptOffer(User $user, int|string $chatId, int $offerId, ?float $quantity, array $menu): void
    {
        if ($quantity !== null && $quantity <= 0) {
            $this->send($chatId, 'مقدار معتبر را وارد کنید.', $menu);
            return;
        }

        $payload = ['offer_id' => $offerId];
        if ($quantity !== null) {
            $payload['quantity'] = $quantity;
        }

        $accepted = $this->siteRequest("trade-room/offers/{$offerId}/accept", $user, $payload);

        if ($accepted === null && ! $this->usesMembershipApi() && $user->exists) {
            $trade = Trade::query()->whereKey($offerId)->whereIn('status', ['submitted', 'active'])->first();
            if ($trade && ($quantity === null || $quantity <= (float) $trade->quantity) && Trade::meetsMinimumQuantity($trade->unit, $quantity ?? (float) $trade->quantity)) {
                $accepted = DB::transaction(function () use ($trade, $user, $quantity) {
                    $acceptQuantity = $quantity ?? (float) $trade->quantity;
                    $trade->update([
                        'quantity' => $acceptQuantity,
                        'total_price' => (int) round($acceptQuantity * (float) $trade->unit_price),
                        'status' => 'accepted',
                        'accepted_by' => $user->id,
                    ]);

                    return $trade->toArray();
                });
            }
        }

        Log::channel(config('trading.log_channel', 'trading'))->info('Trade offer accept requested from Telegram.', [
            'telegram_chat_id' => $user->telegram_chat_id,
            'offer_id' => $offerId,
            'quantity' => $quantity,
            'accepted' => $accepted !== null,
        ]);

        $this->send($chatId, $accepted ? 'معامله در سایت ثبت شد.' : 'ثبت پذیرش معامله در سایت ناموفق بود؛ دوباره تلاش کنید.', $menu);
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

        $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);

        if ($mode === 'full') {
            $this->acceptOffer($user, $chat['id'], $offerId, null, $menu);
            return true;
        }

        $this->saveFlow($user, ['type' => 'trade_accept', 'stage' => 'partial_quantity', 'offer_id' => $offerId]);
        $this->send($chat['id'], 'مقدار موردنظر برای پذیرش جزئی را وارد کنید. حداقل ۱۰۰ گرم و حداکثر مقدار همین معامله قابل قبول است.', $menu);

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
                ."مقدار: {$quantity} {$unit} | قیمت واحد: ".number_format((float) $unitPrice)."\n"
                .'مبلغ کل: '.number_format((float) $total)." ریال | وضعیت: {$status}";
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

        if (! $user || ! $this->hasVipAccess($user)) {
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'ابتدا حساب ویژهٔ سایت را با /connect کد_اتصال متصل کنید.', 'show_alert' => true]);
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
        } elseif ($parts[0] === 'trades' && in_array($parts[1] ?? '', ['buy', 'sell'], true)) {
            $this->sendTradeListMessages($user, $chat['id'], $parts[1], $page);
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

    private function flowKey(User $user): string { return 'telegram-flow:'.$user->telegram_chat_id; }
    private function flow(User $user): array
    {
        $telegramUserId = $user->exists ? optional($user->telegramConnection)->telegram_user_id : null;
        if (! $telegramUserId) return Cache::get($this->flowKey($user), []);
        $state = TelegramState::where('telegram_user_id', $telegramUserId)->first();
        if ($state?->expires_at?->isPast()) { $state->delete(); return []; }
        return $state?->data ?? [];
    }
    private function saveFlow(User $user, array $flow): void
    {
        $telegramUserId = $user->exists ? optional($user->telegramConnection)->telegram_user_id : null;
        if (! $telegramUserId) { Cache::put($this->flowKey($user), $flow, now()->addHour()); return; }
        TelegramState::updateOrCreate(['telegram_user_id' => $telegramUserId], ['state' => ($flow['type'] ?? 'flow').'_'.($flow['stage'] ?? 'active'), 'data' => $flow, 'expires_at' => now()->addHour()]);
    }
    private function clearFlow(User $user): void
    {
        $telegramUserId = $user->exists ? optional($user->telegramConnection)->telegram_user_id : null;
        if ($telegramUserId) TelegramState::where('telegram_user_id', $telegramUserId)->delete();
        Cache::forget($this->flowKey($user));
    }

    private function assetKeyboard(string $prefix): array
    {
        return [[
            ['text' => 'طلا', 'callback_data' => "$prefix:gold"],
        ], [
            ['text' => 'نقره ۹۹۵', 'callback_data' => "$prefix:silver_995"], ['text' => 'نقره ۹۹۹.۹', 'callback_data' => "$prefix:silver_9999"],
        ], [
            ['text' => 'تمام سکه', 'callback_data' => "$prefix:full_coin"], ['text' => 'نیم سکه', 'callback_data' => "$prefix:half_coin"], ['text' => 'ربع سکه', 'callback_data' => "$prefix:quarter_coin"],
        ]];
    }

    private function isCoin(string $asset): bool { return in_array($asset, ['full_coin', 'half_coin', 'quarter_coin'], true); }

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

        $lines = ['💳 کیف پول: '.number_format($wallet).' تومان', '', '📦 دارایی‌ها:'];
        foreach ($metals as $label => [$key, $unit]) {
            $lines[] = $label.': '.rtrim(rtrim(number_format($quantity($key), 3, '.', ''), '0'), '.').' '.$unit;
        }

        return implode("\n", $lines);
    }

    private function meetsMinimumTradeQuantity(string $unit, float $quantity): bool
    {
        return Trade::meetsMinimumQuantity($unit, $quantity);
    }

    private function completeTelegramTrade(User $user, array $flow, array $chat, array $menu): void
    {
        if (! $this->meetsMinimumTradeQuantity($flow['unit'], (float) $flow['quantity'])) {
            $this->send($chat['id'], 'حداقل مقدار معامله ۱۰۰ گرم یا ۲۱٫۷۰۲ مثقال است.', $menu);
            return;
        }

        try {
            $siteTrade = $this->siteRequest('trade-room/offers/create', $user, [
                'side' => $flow['side'],
                'asset' => $this->siteAsset($flow['asset']),
                'unit' => $this->siteUnit($flow['unit']),
                'quantity' => $flow['quantity'],
                'unit_price' => $flow['unit_price'],
            ]);
            if (! $siteTrade) {
                throw new \RuntimeException('Website wallet or asset balance could not be verified.');
            }
            $unitPrice = (int) ($siteTrade['unit_price'] ?? $siteTrade['price_per_unit'] ?? $flow['unit_price']);
            $trade = (object) [
                'unit_price' => $unitPrice,
                'total_price' => (int) ($siteTrade['total_price'] ?? $siteTrade['total'] ?? ((float) $flow['quantity'] * $unitPrice)),
            ];
            $this->clearFlow($user);
            $this->send($chat['id'], 'معامله ثبت شد.'."\n".'قیمت واحد: '.number_format($trade->unit_price).' ریال' . "\n" . 'مبلغ کل: '.number_format($trade->total_price).' ریال', $menu);
        } catch (\Throwable $e) {
            $this->send($chat['id'], 'ثبت معامله انجام نشد: '.$e->getMessage(), $menu);
        }
    }

    private function handleFlowCallback(array $callback, User $user, array $menu): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, 'flow:')) return false;
        $chat = $callback['message']['chat'];
        $parts = explode(':', $data);
        $flow = $this->flow($user);
        $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);

        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'side') {
            $this->send($chat['id'], 'معامله '.(($parts[3] ?? '') === 'buy' ? 'خرید' : 'فروش').' انتخاب شد.');
        }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'asset') {
            $this->send($chat['id'], 'دارایی «'.$this->assetLabel((string) ($parts[3] ?? '')).'» انتخاب شد.');
        }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'unit') {
            $this->send($chat['id'], 'واحد '.(($parts[3] ?? '') === 'gram' ? 'گرم' : 'مثقال').' انتخاب شد.');
        }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'price') {
            $this->send($chat['id'], (($parts[3] ?? '') === 'default' ? 'قیمت سایت' : 'ورود قیمت دلخواه').' انتخاب شد.');
        }

        // The button label and its side must have the same meaning.
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'asset') {
            $this->saveFlow($user, ['type' => 'trade', 'stage' => 'side', 'asset' => $parts[3]]);
            $this->sendInline($chat['id'], 'نوع معامله را انتخاب کنید:', [[
                ['text' => 'فروش', 'callback_data' => 'flow:trade:side:sell'],
                ['text' => 'خرید', 'callback_data' => 'flow:trade:side:buy'],
            ]]);
            return true;
        }

        if ($data === 'flow:deposit:paid') { $this->saveFlow($user, ['type' => 'deposit', 'stage' => 'amount']); $this->send($chat['id'], 'مبلغ واریزی را به ریال وارد کنید.', $menu); return true; }
        if ($data === 'flow:delivery:start') { $this->saveFlow($user, ['type' => 'delivery', 'stage' => 'asset']); $this->sendInline($chat['id'], 'دارایی تحویل‌داده‌شده را انتخاب کنید:', $this->assetKeyboard('flow:delivery:asset')); return true; }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'asset') { $this->saveFlow($user, ['type' => 'trade', 'stage' => 'side', 'asset' => $parts[3]]); $this->sendInline($chat['id'], 'نوع معامله را انتخاب کنید:', [[['text' => 'فروش', 'callback_data' => 'flow:trade:side:buy'], ['text' => 'خرید', 'callback_data' => 'flow:trade:side:sell']]]); return true; }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'side' && ($flow['type'] ?? '') === 'trade') { $flow['side'] = $parts[3]; $flow['stage'] = 'unit'; $this->saveFlow($user, $flow); if ($this->isCoin($flow['asset'])) { $flow['unit'] = 'count'; $flow['stage'] = 'quantity'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'تعداد سکه را وارد کنید.', $menu); } else $this->sendInline($chat['id'], 'واحد را انتخاب کنید:', [[['text' => 'گرم', 'callback_data' => 'flow:trade:unit:gram'], ['text' => 'مثقال', 'callback_data' => 'flow:trade:unit:mesghal']]]); return true; }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'unit' && ($flow['type'] ?? '') === 'trade') { $flow['unit'] = $parts[3]; $flow['stage'] = 'quantity'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'مقدار را وارد کنید.', $menu); return true; }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'price' && ($flow['type'] ?? '') === 'trade') { if (($parts[3] ?? '') === 'default') $this->completeTelegramTrade($user, $flow, $chat, $menu); else { $flow['stage'] = 'custom_price'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'قیمت واحد دلخواه را به ریال وارد کنید.', $menu); } return true; }
        if (($parts[1] ?? '') === 'delivery' && ($parts[2] ?? '') === 'asset') { $flow = ['type' => 'delivery', 'stage' => 'unit', 'asset' => $parts[3]]; if ($this->isCoin($flow['asset'])) { $flow['unit'] = 'count'; $flow['stage'] = 'quantity'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'تعداد سکه تحویل‌داده‌شده را وارد کنید.', $menu); } else { $this->saveFlow($user, $flow); $this->sendInline($chat['id'], 'واحد را انتخاب کنید:', [[['text' => 'گرم', 'callback_data' => 'flow:delivery:unit:gram'], ['text' => 'مثقال', 'callback_data' => 'flow:delivery:unit:mesghal']]]); } return true; }
        if (($parts[1] ?? '') === 'delivery' && ($parts[2] ?? '') === 'unit' && ($flow['type'] ?? '') === 'delivery') { $flow['unit'] = $parts[3]; $flow['stage'] = 'quantity'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'مقدار تحویل‌داده‌شده را وارد کنید.', $menu); return true; }
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

        if (($parts[2] ?? '') === 'asset') {
            $this->send($chat['id'], 'دارایی «'.$this->assetLabel((string) ($parts[3] ?? '')).'» انتخاب شد.');
        }
        if (($parts[2] ?? '') === 'unit') {
            $this->send($chat['id'], 'واحد '.(($parts[3] ?? '') === 'gram' ? 'گرم' : 'مثقال').' انتخاب شد.');
        }

        if ($data === 'flow:delivery:start') {
            $this->saveFlow($user, ['type' => 'delivery', 'stage' => 'asset']);
            $this->sendInline($chat['id'], 'دارایی تحویلی را انتخاب کنید:', $this->assetKeyboard('flow:delivery:asset'));
            return true;
        }

        if (($parts[2] ?? '') === 'asset') {
            $flow = ['type' => 'delivery', 'stage' => 'unit', 'asset' => $parts[3] ?? ''];
            if ($this->isCoin($flow['asset'])) {
                $flow['unit'] = 'count';
                $flow['stage'] = 'quantity';
                $this->saveFlow($user, $flow);
                $this->send($chat['id'], 'تعداد سکهٔ تحویلی را وارد کنید.', $menu);
            } else {
                $this->saveFlow($user, $flow);
                $this->sendInline($chat['id'], 'واحد وزن را انتخاب کنید:', [[['text' => 'گرم', 'callback_data' => 'flow:delivery:unit:gram'], ['text' => 'مثقال', 'callback_data' => 'flow:delivery:unit:mesghal']]]);
            }
            return true;
        }

        if (($parts[2] ?? '') === 'unit') {
            $flow = $this->flow($user);
            $flow['unit'] = $parts[3] ?? '';
            $flow['stage'] = 'quantity';
            $this->saveFlow($user, $flow);
            $this->send($chat['id'], 'مقدار وزن تحویلی را وارد کنید.', $menu);
            return true;
        }

        return false;
    }

    public function __invoke(Request $request, TalaboardClient $prices, TelegramConnectionService $connections)
    {
        if ($secret = env('TELEGRAM_WEBHOOK_SECRET')) {
            abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        }

        if ($updateId = $request->input('update_id')) {
            if (TelegramUpdate::where('update_id', $updateId)->exists()) return response()->noContent();
            try { TelegramUpdate::create(['update_id' => $updateId, 'processed_at' => now()]); } catch (\Throwable) { return response()->noContent(); }
        }

        $menu = [['قیمت لحظه‌ای', 'ثبت معامله'], ['واریز وجه', 'معاملات من'], ['سوابق من', 'لیست معاملات'], ['کیف پول و دارایی‌ها', 'افزایش موجودی انبار']];
        if ($callback = $request->input('callback_query')) {
            $chat = $callback['message']['chat'] ?? [];
            $user = $chat ? $this->user($chat) : null;
            $callbackData = (string) ($callback['data'] ?? '');
            if ($user && $callbackData === 'flow:deposit:paid') {
                $this->saveFlow($user, ['type' => 'deposit', 'stage' => 'amount']);
                $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
                $this->send($chat['id'], 'گزینه «واریز کردم» انتخاب شد.');
                $this->send($chat['id'], 'مبلغ واریزی را به ریال وارد کنید.', $menu);
                return response()->noContent();
            }
            if (str_starts_with($callbackData, 'trades:') || str_starts_with($callbackData, 'deposits:')) {
                $this->showCallbackList($callback);
                return response()->noContent();
            }
            if ($user && $this->handleTradeAcceptCallback($callback, $user, $menu)) return response()->noContent();
            if ($user && $this->handleTradeDeleteCallback($callback, $user, $menu)) return response()->noContent();
            if ($user && $this->handleDeliveryCallback($callback, $user, $menu)) return response()->noContent();
            if ($user && $this->handleFlowCallback($callback, $user, $menu)) return response()->noContent();
            if (str_starts_with((string) ($callback['data'] ?? ''), 'deposit:approve:')) {
                $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'تأیید فیش فقط از پنل مدیریت سایت انجام می‌شود.', 'show_alert' => true]);
            } elseif (str_starts_with((string) ($callback['data'] ?? ''), 'trades:') || str_starts_with((string) ($callback['data'] ?? ''), 'deposits:')) {
                $this->showCallbackList($callback);
            }
            return response()->noContent();
        }

        $message = $request->input('message');
        if (! $message) return response()->noContent();
        $chat = $message['chat']; $user = $this->user($chat); $text = trim($message['text'] ?? ''); $photo = $message['photo'] ?? [];
        if (preg_match('/^\/connect\s+(\d{6})$/', $text, $matches)) {
            try {
                if ($this->usesMembershipApi()) {
                    $member = $this->connectWebsiteAccount($message, $matches[1]);
                    if (! $member || ! ($member['linked'] ?? false)) {
                        $this->send($chat['id'], 'کد اتصال نامعتبر است یا اعتبار آن تمام شده است.', $menu);
                    } elseif (! ($member['vip'] ?? false)) {
                        $this->send($chat['id'], 'حساب سایت شما متصل شد، اما عضویت ویژهٔ فعال ندارید.', $menu);
                    } else {
                        $this->send($chat['id'], 'حساب سایت شما با موفقیت به تلگرام متصل شد.', $menu);
                    }
                } else {
                    $connections->connect($matches[1], (string) data_get($message, 'from.id'), (string) $chat['id'], data_get($message, 'from.username'));
                    $this->send($chat['id'], 'حساب سایت شما با موفقیت به تلگرام متصل شد.', $menu);
                }
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->send($chat['id'], $exception->errors()['code'][0] ?? $exception->errors()['telegram_user_id'][0] ?? 'اتصال انجام نشد.', $menu);
            }
            return response()->noContent();
        }
        if ($text === '/start') { $this->send($chat['id'], "به ربات اتاق معاملات طلای‌برد خوش آمدید.\n\nبرای استفاده، وارد حساب ویژه خود در سایت شوید و از بخش پروفایل یک کد اتصال بسازید. سپس حداکثر تا ۱۰ دقیقه، آن را با قالب زیر برای ربات ارسال کنید:\n\n/connect CODE", $menu); return response()->noContent(); }
        if (str_starts_with($text, '/connect')) { $this->send($chat['id'], "فرمت صحیح: /connect CODE\n\nCODE باید کد ۶ رقمی ساخته‌شده در پروفایل سایت باشد و تا ۱۰ دقیقه اعتبار دارد.", $menu); return response()->noContent(); }
        if (! $this->hasVipAccess($user)) { $this->send($chat['id'], "دسترسی ربات فقط برای اعضای ویژه فعال است.\n\nوارد سایت شوید، از پروفایل کد اتصال بسازید و آن را حداکثر تا ۱۰ دقیقه با دستور /connect CODE ارسال کنید.", $menu); return response()->noContent(); }
        $flow = $this->flow($user);
        if ($text === 'قیمت لحظه‌ای') { $this->send($chat['id'], $this->livePricesText($prices), $menu); return response()->noContent(); }
        if (in_array($text, ['افزایش موجودی انبار', 'افزایش موجودی', 'درخواست افزایش موجودی'], true)) {
            $this->sendInline($chat['id'], 'دارایی تحویلی را انتخاب کنید. پس از ثبت، درخواست تحویل به فروشگاه برای تأیید یا رد ادمین ارسال می‌شود.', [[['text' => 'تحویل به فروشگاه', 'callback_data' => 'flow:delivery:start']]]);
            return response()->noContent();
        }
        if ($text === 'واریز وجه' || $text === 'شارژ کیف پول') {
            $this->sendInline($chat['id'], "شماره کارت: ".config('trading.card_number')."\nشماره حساب: ".config('trading.account_number')."\nشماره شبا: ".config('trading.iban')."\nبه نام: ".config('trading.account_holder')."\n\nپس از واریز، گزینهٔ زیر را بزنید تا مبلغ و تصویر فیش را ارسال کنید.", [[['text' => 'واریز کردم', 'callback_data' => 'flow:deposit:paid']]]);
            return response()->noContent();
        }
        if ($text === 'کیف پول و دارایی‌ها') { $this->send($chat['id'], $this->accountSummary($user), $menu); return response()->noContent(); }
        if ($text === 'معاملات من') {
            $this->sendMyTradeRoomMessages($user, $chat['id'], 1, $menu);
            return response()->noContent();
        }
        if ($text === 'سوابق من') {
            $this->sendMyTradeRoomHistoryMessages($user, $chat['id'], 1, $menu);
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
        if (($flow['type'] ?? '') === 'trade_accept' && ($flow['stage'] ?? '') === 'partial_quantity') {
            $quantity = is_numeric($text) ? (float) $text : 0;
            if (! Trade::meetsMinimumQuantity('gram', $quantity)) {
                $this->send($chat['id'], 'برای پذیرش جزئی، مقدار معتبر و حداقل ۱۰۰ گرم وارد کنید.', $menu);
            } else {
                $this->clearFlow($user);
                $this->acceptOffer($user, $chat['id'], (int) ($flow['offer_id'] ?? 0), $quantity, $menu);
            }
            return response()->noContent();
        }
        if (($flow['type'] ?? '') === 'trade' && ($flow['stage'] ?? '') === 'quantity') { if (!is_numeric($text) || (float)$text <= 0) { $this->send($chat['id'], 'مقدار معتبر را وارد کنید.', $menu); } else { $flow['quantity']=$text; $symbol=$this->isCoin($flow['asset'])?$flow['asset']:($flow['asset']==='gold' ? 'gold_'.$flow['unit'] : $flow['asset'].'_'.$flow['unit']); $snapshot=$prices->prices()->get($symbol); if ($snapshot) { $flow['unit_price']=$snapshot->price; $flow['stage']='price'; $this->saveFlow($user,$flow); $this->sendInline($chat['id'], 'قیمت پیش‌فرض سایت: '.number_format($snapshot->price).' ریال. انتخاب کنید:', [[['text'=>'تأیید قیمت سایت','callback_data'=>'flow:trade:price:default'],['text'=>'ورود قیمت دیگر','callback_data'=>'flow:trade:price:custom']]]); } else { $flow['stage']='custom_price'; $this->saveFlow($user,$flow); $this->send($chat['id'], 'قیمت سایت در دسترس نیست؛ قیمت واحد را به ریال وارد کنید.', $menu); } } return response()->noContent(); }
        if (($flow['type'] ?? '') === 'trade' && ($flow['stage'] ?? '') === 'custom_price') { if (!is_numeric($text) || (int)$text < 1) $this->send($chat['id'], 'قیمت واحد معتبر را وارد کنید.', $menu); else { $flow['unit_price']=(int)$text; $this->completeTelegramTrade($user,$flow,$chat,$menu); } return response()->noContent(); }
        if ($text === 'قیمت لحظه‌ای') { $labels=TalaboardClient::PRODUCTS; $rows=$prices->prices(); $out="💹 قیمت‌های لحظه‌ای (ریال)\n\n"; foreach($labels as $symbol=>$label) $out .= ($symbol==='full_coin'||$symbol==='half_coin'||$symbol==='quarter_coin'?'🪙 ':'⚖️ ').$label.': '.($rows->get($symbol)?number_format($rows->get($symbol)->price):'—')."\n"; $this->send($chat['id'], $out, $menu); return response()->noContent(); }
        if ($text === 'واریز وجه' || $text === 'شارژ کیف پول') { $this->sendInline($chat['id'], "شماره حساب: ".config('trading.account_number')."\nشماره شبا: ".config('trading.iban')."\nبه نام: ".config('trading.account_holder')."\n\nپس از واریز، گزینه زیر را بزنید.", [[['text'=>'واریز کردم','callback_data'=>'flow:deposit:paid']]]); return response()->noContent(); }
        if ($text === 'افزایش موجودی' || $text === 'درخواست افزایش موجودی') { $this->sendInline($chat['id'], 'برای افزایش موجودی، ابتدا دارایی را به فروشگاه تحویل دهید. پس از تحویل، گزینه زیر را بزنید.', [[['text'=>'تحویل دادم','callback_data'=>'flow:delivery:start']]]); return response()->noContent(); }
        if (str_starts_with($text, '/inventory ')) { [, $item, $quantity] = array_pad(explode(' ', $text), 3, null); $result = $this->siteRequest('inventory-increase', $user, ['item' => $item, 'quantity' => $quantity]); $this->send($chat['id'], $result ? "درخواست {$result['label']} در سایت ثبت شد و در انتظار تأیید ادمین است." : 'ثبت درخواست در سایت ناموفق بود؛ نوع و مقدار را بررسی کنید.', $menu); return response()->noContent(); }
        if (str_starts_with($text, '/deposit ')) { $amount = (int) trim(substr($text, 9)); $deposit = $this->siteRequest('deposits', $user, ['amount' => $amount]); if ($deposit) { Cache::put('site-deposit:'.$user->telegram_chat_id, $deposit['id'], now()->addHour()); $this->send($chat['id'], 'درخواست شارژ در سایت ثبت شد؛ اکنون تصویر فیش را ارسال کنید.', $menu); } else { $this->send($chat['id'], 'ثبت درخواست در سایت ناموفق بود؛ مبلغ را بررسی کنید.', $menu); } return response()->noContent(); }
        if ($photo) { $depositId = Cache::pull('site-deposit:'.$user->telegram_chat_id); $ok = $depositId && $this->uploadReceiptToSite($user, $depositId, end($photo)); $this->send($chat['id'], $ok ? 'فیش در سایت ذخیره شد و درخواست در انتظار تأیید ادمین است.' : 'ابتدا مبلغ را با /deposit وارد کنید یا دوباره تصویر فیش را ارسال کنید.', $menu); return response()->noContent(); }
        if ($text === 'لیست معاملات') { $this->sendInline($chat['id'], 'نوع فهرست را انتخاب کنید:', [[['text' => 'لیست خرید', 'callback_data' => 'trades:buy:1'], ['text' => 'لیست فروش', 'callback_data' => 'trades:sell:1']]]); return response()->noContent(); }
        if ($text === 'فیش‌های در انتظار تأیید' || $text === 'فیش‌های تأییدشده') { $this->send($chat['id'], 'فیش‌ها و تأیید آن‌ها فقط در پنل مدیریت سایت قابل مشاهده و بررسی هستند.', $menu); return response()->noContent(); }
        if ($text === 'ثبت معامله') { $this->saveFlow($user, ['type'=>'trade','stage'=>'asset']); $this->sendInline($chat['id'], 'دارایی معامله را انتخاب کنید:', $this->assetKeyboard('flow:trade:asset')); return response()->noContent(); }
        if (str_starts_with($text, '/trade ')) { [, $side, $unit, $qty] = array_pad(explode(' ', $text), 4, null); $trade = $this->siteRequest('trades', $user, ['side' => $side, 'unit' => $unit, 'quantity' => $qty]); $this->send($chat['id'], $trade ? "معامله در سایت ثبت شد. قیمت واحد: ".number_format($trade['price_per_unit']).'، مبلغ کل: '.number_format($trade['total']) : 'ثبت معامله در سایت ناموفق بود؛ موجودی و مقدار را بررسی کنید.', $menu); return response()->noContent(); }
        $this->send($chat['id'], 'یکی از گزینه‌های منو را انتخاب کنید.', $menu); return response()->noContent();
    }
}
