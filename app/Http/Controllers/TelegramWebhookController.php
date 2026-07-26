<?php

namespace App\Http\Controllers;

use App\Models\{DepositRequest, InventoryDelivery, Trade, User, WalletTransaction};
use App\Services\{TalaboardClient, TradeService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http, Storage};

class TelegramWebhookController extends Controller
{
    private function api(string $method, array $data): array
    {
        $token = config('services.telegram.token');

        if (! $token) {
            return [];
        }

        return Http::post("https://api.telegram.org/bot{$token}/{$method}", $data)->json() ?? [];
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
        return User::firstOrCreate(
            ['telegram_chat_id' => (string) $chat['id']],
            ['name' => $chat['first_name'] ?? 'Telegram user', 'email' => 'tg-'.$chat['id'].'@local.invalid', 'password' => bcrypt(str()->random(32))]
        );
    }

    private function membershipRequest(string $endpoint, array $payload): ?array
    {
        $url = rtrim((string) config('services.membership.url'), '/');
        $token = (string) config('services.membership.token');

        if ($url === '' || $token === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()->withToken($token)->timeout(8)->post("{$url}/{$endpoint}", $payload);

            return $response->successful() ? ($response->json() ?? []) : null;
        } catch (\Throwable) {
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
        if ($user->is_admin) {
            return true;
        }

        $member = $this->membershipRequest('member', ['telegram_chat_id' => $user->telegram_chat_id]);

        return (bool) data_get($member, 'linked') && (bool) data_get($member, 'vip');
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

    private function tradeList(User $user, string $side, int $page): array
    {
        $perPage = 10;
        $trades = collect(data_get($this->siteRequest('overview', $user), 'trades', []))
            ->filter(fn (array $trade) => ($trade['type'] ?? null) === $side)
            ->values();
        $hasMore = $trades->count() > $page * $perPage;
        $rows = $trades->slice(($page - 1) * $perPage, $perPage);
        $title = $side === 'buy' ? 'لیست خرید' : 'لیست فروش';
        $text = "{$title} — صفحه {$page}\n\n";
        $text .= $rows->isEmpty() ? 'موردی وجود ندارد.' : $rows->map(fn (array $t) => "#{$t['id']} | {$t['item_label']}\nمقدار: {$t['quantity']} | کل: ".number_format($t['total']).' | وضعیت: '.($t['status'] ?? 'active'))->join("\n\n");

        return [$text, $this->pagination("trades:{$side}", $page, $hasMore)];
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
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'ابتدا حساب ویژهٔ سایت را با /link متصل کنید.', 'show_alert' => true]);
            return;
        }

        if ($parts[0] === 'trades' && in_array($parts[1] ?? '', ['buy', 'sell'], true)) {
            [$text, $keyboard] = $this->tradeList($user, $parts[1], $page);
        } elseif ($parts[0] === 'deposits' && in_array($parts[1] ?? '', ['pending', 'approved'], true) && $user?->is_admin) {
            [$text, $keyboard] = $this->depositList($user, $parts[1], $page);
        } else {
            $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id'], 'text' => 'اجازهٔ دسترسی ندارید.', 'show_alert' => true]);
            return;
        }

        $this->api('editMessageText', ['chat_id' => $chat['id'], 'message_id' => $callback['message']['message_id'], 'text' => $text, 'reply_markup' => ['inline_keyboard' => $keyboard]]);
        $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);
    }

    private function flowKey(User $user): string { return 'telegram-flow:'.$user->id; }
    private function flow(User $user): array { return Cache::get($this->flowKey($user), []); }
    private function saveFlow(User $user, array $flow): void { Cache::put($this->flowKey($user), $flow, now()->addHour()); }
    private function clearFlow(User $user): void { Cache::forget($this->flowKey($user)); }

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

    private function completeTelegramTrade(User $user, array $flow, TradeService $trades, array $chat, array $menu): void
    {
        try {
            $trade = $trades->create($user, ['side' => $flow['side'], 'asset' => $flow['asset'], 'unit' => $flow['unit'], 'quantity' => $flow['quantity'], 'unit_price' => $flow['unit_price']]);
            $this->clearFlow($user);
            $this->send($chat['id'], 'معامله ثبت شد.'."\n".'قیمت واحد: '.number_format($trade->unit_price).' ریال' . "\n" . 'مبلغ کل: '.number_format($trade->total_price).' ریال', $menu);
        } catch (\Throwable $e) {
            $this->send($chat['id'], 'ثبت معامله انجام نشد: '.$e->getMessage(), $menu);
        }
    }

    private function handleFlowCallback(array $callback, User $user, TradeService $trades, array $menu): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, 'flow:')) return false;
        $chat = $callback['message']['chat'];
        $parts = explode(':', $data);
        $flow = $this->flow($user);
        $this->api('answerCallbackQuery', ['callback_query_id' => $callback['id']]);

        if ($data === 'flow:deposit:paid') { $this->saveFlow($user, ['type' => 'deposit', 'stage' => 'amount']); $this->send($chat['id'], 'مبلغ واریزی را به ریال وارد کنید.', $menu); return true; }
        if ($data === 'flow:delivery:start') { $this->saveFlow($user, ['type' => 'delivery', 'stage' => 'asset']); $this->sendInline($chat['id'], 'دارایی تحویل‌داده‌شده را انتخاب کنید:', $this->assetKeyboard('flow:delivery:asset')); return true; }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'asset') { $this->saveFlow($user, ['type' => 'trade', 'stage' => 'side', 'asset' => $parts[3]]); $this->sendInline($chat['id'], 'نوع معامله را انتخاب کنید:', [[['text' => 'خرید از سایت', 'callback_data' => 'flow:trade:side:sell'], ['text' => 'فروش به سایت', 'callback_data' => 'flow:trade:side:buy']]]); return true; }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'side' && ($flow['type'] ?? '') === 'trade') { $flow['side'] = $parts[3]; $flow['stage'] = 'unit'; $this->saveFlow($user, $flow); if ($this->isCoin($flow['asset'])) { $flow['unit'] = 'count'; $flow['stage'] = 'quantity'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'تعداد سکه را وارد کنید.', $menu); } else $this->sendInline($chat['id'], 'واحد را انتخاب کنید:', [[['text' => 'گرم', 'callback_data' => 'flow:trade:unit:gram'], ['text' => 'مثقال', 'callback_data' => 'flow:trade:unit:mesghal']]]); return true; }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'unit' && ($flow['type'] ?? '') === 'trade') { $flow['unit'] = $parts[3]; $flow['stage'] = 'quantity'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'مقدار را وارد کنید.', $menu); return true; }
        if (($parts[1] ?? '') === 'trade' && ($parts[2] ?? '') === 'price' && ($flow['type'] ?? '') === 'trade') { if (($parts[3] ?? '') === 'default') $this->completeTelegramTrade($user, $flow, $trades, $chat, $menu); else { $flow['stage'] = 'custom_price'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'قیمت واحد دلخواه را به ریال وارد کنید.', $menu); } return true; }
        if (($parts[1] ?? '') === 'delivery' && ($parts[2] ?? '') === 'asset') { $flow = ['type' => 'delivery', 'stage' => 'unit', 'asset' => $parts[3]]; if ($this->isCoin($flow['asset'])) { $flow['unit'] = 'count'; $flow['stage'] = 'quantity'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'تعداد سکه تحویل‌داده‌شده را وارد کنید.', $menu); } else { $this->saveFlow($user, $flow); $this->sendInline($chat['id'], 'واحد را انتخاب کنید:', [[['text' => 'گرم', 'callback_data' => 'flow:delivery:unit:gram'], ['text' => 'مثقال', 'callback_data' => 'flow:delivery:unit:mesghal']]]); } return true; }
        if (($parts[1] ?? '') === 'delivery' && ($parts[2] ?? '') === 'unit' && ($flow['type'] ?? '') === 'delivery') { $flow['unit'] = $parts[3]; $flow['stage'] = 'quantity'; $this->saveFlow($user, $flow); $this->send($chat['id'], 'مقدار تحویل‌داده‌شده را وارد کنید.', $menu); return true; }
        return true;
    }

    public function __invoke(Request $request, TalaboardClient $prices, TradeService $trades)
    {
        if ($secret = env('TELEGRAM_WEBHOOK_SECRET')) {
            abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        }

        $menu = [['قیمت لحظه‌ای', 'ثبت معامله'], ['واریز وجه', 'لیست معاملات'], ['افزایش موجودی']];
        if ($callback = $request->input('callback_query')) {
            $chat = $callback['message']['chat'] ?? [];
            $user = $chat ? $this->user($chat) : null;
            if ($user && $this->handleFlowCallback($callback, $user, $trades, $menu)) return response()->noContent();
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
        if ($text === '/start') { $this->send($chat['id'], 'به ربات اتاق معاملات طلای‌برد خوش آمدید. برای استفاده، در سایت وارد حساب ویژه شوید، از پروفایل کد اتصال بسازید و آن را به صورت /link کد برای ربات بفرستید.', $menu); return response()->noContent(); }
        if (preg_match('/^\/link\s+([A-Za-z0-9]{24})$/', $text, $matches)) {
            $member = $this->linkWebsiteAccount($user, $matches[1]);
            $this->send($chat['id'], $member ? 'حساب ویژهٔ سایت با موفقیت به این ربات متصل شد.' : 'اتصال انجام نشد. کد را از پروفایل حساب ویژهٔ سایت دریافت کنید و تا ۱۰ دقیقه استفاده کنید.', $menu);
            return response()->noContent();
        }
        if (str_starts_with($text, '/link')) { $this->send($chat['id'], 'فرمت صحیح: /link کد_اتصال', $menu); return response()->noContent(); }
        if (! $this->hasVipAccess($user)) { $this->send($chat['id'], 'دسترسی ربات فقط برای اعضای ویژه فعال است. وارد سایت شوید، از پروفایل کد اتصال بسازید و آن را با دستور /link کد ارسال کنید.', $menu); return response()->noContent(); }
        $flow = $this->flow($user);
        if ($photo && ($flow['type'] ?? '') === 'deposit' && ($flow['stage'] ?? '') === 'receipt') { try { $telegramPhoto = end($photo); $deposit = DepositRequest::create(['user_id' => $user->id, 'amount' => $flow['amount'], 'receipt_path' => $this->storeTelegramPhoto($telegramPhoto)]); $this->notifyAdmins($deposit->load('user'), $telegramPhoto['file_id']); $this->clearFlow($user); $this->send($chat['id'], 'فیش شما ثبت شد و پس از بررسی، کیف پول شارژ می‌شود.', $menu); } catch (\Throwable $e) { $this->send($chat['id'], 'ذخیره فیش ناموفق بود؛ دوباره تلاش کنید.', $menu); } return response()->noContent(); }
        if (($flow['type'] ?? '') === 'deposit' && ($flow['stage'] ?? '') === 'amount') { if (!is_numeric($text) || (int)$text < 10000) { $this->send($chat['id'], 'مبلغ معتبر (حداقل ۱۰٬۰۰۰ ریال) را وارد کنید.', $menu); } else { $flow['amount']=(int)$text; $flow['stage']='receipt'; $this->saveFlow($user,$flow); $this->send($chat['id'], 'اکنون تصویر فیش واریزی را ارسال کنید.', $menu); } return response()->noContent(); }
        if (($flow['type'] ?? '') === 'delivery' && ($flow['stage'] ?? '') === 'quantity') { if (!is_numeric($text) || (float)$text <= 0) { $this->send($chat['id'], 'مقدار معتبر را وارد کنید.', $menu); } else { InventoryDelivery::create(['user_id'=>$user->id,'asset'=>$flow['asset'],'unit'=>$flow['unit'],'quantity'=>$text]); $this->clearFlow($user); $this->send($chat['id'], 'تحویل دارایی ثبت شد و در انتظار بررسی فروشگاه است.', $menu); } return response()->noContent(); }
        if (($flow['type'] ?? '') === 'trade' && ($flow['stage'] ?? '') === 'quantity') { if (!is_numeric($text) || (float)$text <= 0) { $this->send($chat['id'], 'مقدار معتبر را وارد کنید.', $menu); } else { $flow['quantity']=$text; $symbol=$this->isCoin($flow['asset'])?$flow['asset']:($flow['asset']==='gold' ? 'gold_'.$flow['unit'] : $flow['asset'].'_'.$flow['unit']); $snapshot=$prices->prices()->get($symbol); if ($snapshot) { $flow['unit_price']=$snapshot->price; $flow['stage']='price'; $this->saveFlow($user,$flow); $this->sendInline($chat['id'], 'قیمت پیش‌فرض سایت: '.number_format($snapshot->price).' ریال. انتخاب کنید:', [[['text'=>'تأیید قیمت سایت','callback_data'=>'flow:trade:price:default'],['text'=>'ورود قیمت دیگر','callback_data'=>'flow:trade:price:custom']]]); } else { $flow['stage']='custom_price'; $this->saveFlow($user,$flow); $this->send($chat['id'], 'قیمت سایت در دسترس نیست؛ قیمت واحد را به ریال وارد کنید.', $menu); } } return response()->noContent(); }
        if (($flow['type'] ?? '') === 'trade' && ($flow['stage'] ?? '') === 'custom_price') { if (!is_numeric($text) || (int)$text < 1) $this->send($chat['id'], 'قیمت واحد معتبر را وارد کنید.', $menu); else { $flow['unit_price']=(int)$text; $this->completeTelegramTrade($user,$flow,$trades,$chat,$menu); } return response()->noContent(); }
        if ($text === 'قیمت لحظه‌ای') { $labels=TalaboardClient::PRODUCTS; $rows=$prices->prices(); $out="💹 قیمت‌های لحظه‌ای (ریال)\n\n"; foreach($labels as $symbol=>$label) $out .= ($symbol==='full_coin'||$symbol==='half_coin'||$symbol==='quarter_coin'?'🪙 ':'⚖️ ').$label.': '.($rows->get($symbol)?number_format($rows->get($symbol)->price):'—')."\n"; $this->send($chat['id'], $out, $menu); return response()->noContent(); }
        if ($text === 'واریز وجه' || $text === 'شارژ کیف پول') { $this->sendInline($chat['id'], "شماره حساب: ".config('trading.account_number')."\nشماره شبا: ".config('trading.iban')."\nبه نام: ".config('trading.account_holder')."\n\nپس از واریز، گزینه زیر را بزنید.", [[['text'=>'واریز کردم','callback_data'=>'flow:deposit:paid']]]); return response()->noContent(); }
        if ($text === 'افزایش موجودی' || $text === 'درخواست افزایش موجودی') { $this->sendInline($chat['id'], 'برای افزایش موجودی، ابتدا دارایی را به فروشگاه تحویل دهید. پس از تحویل، گزینه زیر را بزنید.', [[['text'=>'تحویل دادم','callback_data'=>'flow:delivery:start']]]); return response()->noContent(); }
        if (str_starts_with($text, '/inventory ')) { [, $item, $quantity] = array_pad(explode(' ', $text), 3, null); $result = $this->siteRequest('inventory-increase', $user, ['item' => $item, 'quantity' => $quantity]); $this->send($chat['id'], $result ? "درخواست {$result['label']} در سایت ثبت شد و در انتظار تأیید ادمین است." : 'ثبت درخواست در سایت ناموفق بود؛ نوع و مقدار را بررسی کنید.', $menu); return response()->noContent(); }
        if (str_starts_with($text, '/deposit ')) { $amount = (int) trim(substr($text, 9)); $deposit = $this->siteRequest('deposits', $user, ['amount' => $amount]); if ($deposit) { Cache::put('site-deposit:'.$user->id, $deposit['id'], now()->addHour()); $this->send($chat['id'], 'درخواست شارژ در سایت ثبت شد؛ اکنون تصویر فیش را ارسال کنید.', $menu); } else { $this->send($chat['id'], 'ثبت درخواست در سایت ناموفق بود؛ مبلغ را بررسی کنید.', $menu); } return response()->noContent(); }
        if ($photo) { $depositId = Cache::pull('site-deposit:'.$user->id); $ok = $depositId && $this->uploadReceiptToSite($user, $depositId, end($photo)); $this->send($chat['id'], $ok ? 'فیش در سایت ذخیره شد و درخواست در انتظار تأیید ادمین است.' : 'ابتدا مبلغ را با /deposit وارد کنید یا دوباره تصویر فیش را ارسال کنید.', $menu); return response()->noContent(); }
        if ($text === 'لیست معاملات') { $this->sendInline($chat['id'], 'نوع فهرست را انتخاب کنید:', [[['text' => 'لیست خرید', 'callback_data' => 'trades:buy:1'], ['text' => 'لیست فروش', 'callback_data' => 'trades:sell:1']]]); return response()->noContent(); }
        if ($text === 'فیش‌های در انتظار تأیید' || $text === 'فیش‌های تأییدشده') { $this->send($chat['id'], 'فیش‌ها و تأیید آن‌ها فقط در پنل مدیریت سایت قابل مشاهده و بررسی هستند.', $menu); return response()->noContent(); }
        if ($text === 'ثبت معامله') { $this->saveFlow($user, ['type'=>'trade','stage'=>'asset']); $this->sendInline($chat['id'], 'دارایی معامله را انتخاب کنید:', $this->assetKeyboard('flow:trade:asset')); return response()->noContent(); }
        if (str_starts_with($text, '/trade ')) { [, $side, $unit, $qty] = array_pad(explode(' ', $text), 4, null); $trade = $this->siteRequest('trades', $user, ['side' => $side, 'unit' => $unit, 'quantity' => $qty]); $this->send($chat['id'], $trade ? "معامله در سایت ثبت شد. قیمت واحد: ".number_format($trade['price_per_unit']).'، مبلغ کل: '.number_format($trade['total']) : 'ثبت معامله در سایت ناموفق بود؛ موجودی و مقدار را بررسی کنید.', $menu); return response()->noContent(); }
        $this->send($chat['id'], 'یکی از گزینه‌های منو را انتخاب کنید.', $menu); return response()->noContent();
    }
}
