<?php

namespace App\Http\Controllers;

use App\Models\{DepositRequest, Trade, User, WalletTransaction};
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
        $trades = Trade::where('user_id', $user->id)->where('side', $side)->latest('traded_at')->forPage($page, $perPage + 1)->get();
        $hasMore = $trades->count() > $perPage;
        $rows = $trades->take($perPage);
        $title = $side === 'buy' ? 'لیست خرید' : 'لیست فروش';
        $text = "{$title} — صفحه {$page}\n\n";
        $text .= $rows->isEmpty() ? 'موردی وجود ندارد.' : $rows->map(fn ($t) => "#{$t->id} | مقدار: {$t->quantity} ".($t->unit === 'gram' ? 'گرم' : 'مثقال')."\nواحد: ".number_format($t->unit_price).' | کل: '.number_format($t->total_price).' | '.\Morilog\Jalali\Jalalian::fromCarbon($t->traded_at->timezone(config('trading.timezone')))->format('Y/m/d H:i'))->join("\n\n");

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

    public function __invoke(Request $request, TalaboardClient $prices, TradeService $trades)
    {
        if ($secret = env('TELEGRAM_WEBHOOK_SECRET')) {
            abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        }

        if ($callback = $request->input('callback_query')) {
            if (str_starts_with((string) ($callback['data'] ?? ''), 'deposit:approve:')) {
                $this->approveFromTelegram($callback);
            } elseif (str_starts_with((string) ($callback['data'] ?? ''), 'trades:') || str_starts_with((string) ($callback['data'] ?? ''), 'deposits:')) {
                $this->showCallbackList($callback);
            }
            return response()->noContent();
        }

        $message = $request->input('message');
        if (! $message) return response()->noContent();
        $chat = $message['chat']; $user = $this->user($chat); $text = trim($message['text'] ?? ''); $photo = $message['photo'] ?? [];
        $menu = [['قیمت لحظه‌ای', 'ثبت معامله'], ['شارژ کیف پول', 'لیست معاملات']];
        if ($user->is_admin) $menu[] = ['فیش‌های در انتظار تأیید', 'فیش‌های تأییدشده'];
        if ($text === '/start') { $this->send($chat['id'], 'به ربات اتاق معاملات طلای‌برد خوش آمدید.', $menu); return response()->noContent(); }
        if ($text === 'قیمت لحظه‌ای') { try { $rows = $prices->prices(); $out = "قیمت‌های لحظه‌ای (ریال)\n"; foreach ($rows as $r) $out .= "{$r->title}: ".number_format($r->price)."\n"; $this->send($chat['id'], $out, $menu); } catch (\Throwable) { $this->send($chat['id'], 'دریافت قیمت از طلای‌برد ناموفق بود.', $menu); } return response()->noContent(); }
        if ($text === 'شارژ کیف پول') { $this->send($chat['id'], "کارت: ".config('trading.card_number')."\nشبا: ".config('trading.iban')."\nابتدا مبلغ را با /deposit 1000000 بفرستید، سپس عکس فیش را ارسال کنید.", $menu); return response()->noContent(); }
        if (str_starts_with($text, '/deposit ')) { $amount = (int) trim(substr($text, 9)); if ($amount < 10000) { $this->send($chat['id'], 'مبلغ باید حداقل ۱۰٬۰۰۰ ریال باشد.', $menu); } else { Cache::put('deposit:'.$user->id, $amount, now()->addHour()); $this->send($chat['id'], 'اکنون تصویر فیش واریزی را ارسال کنید.', $menu); } return response()->noContent(); }
        if ($photo) { $amount = Cache::pull('deposit:'.$user->id); if (! $amount) { $this->send($chat['id'], 'اول مبلغ را با /deposit وارد کنید.', $menu); return response()->noContent(); } try { $file = end($photo); $path = $this->storeTelegramPhoto($file); $deposit = DepositRequest::create(['user_id' => $user->id, 'amount' => $amount, 'receipt_path' => $path]); $deposit->load('user'); $this->notifyAdmins($deposit, $file['file_id']); $this->send($chat['id'], 'فیش روی سرور ذخیره شد و برای تأیید ادمین ارسال شد.', $menu); } catch (\Throwable $e) { report($e); $this->send($chat['id'], 'ذخیره‌سازی فیش ناموفق بود؛ لطفاً دوباره تلاش کنید.', $menu); } return response()->noContent(); }
        if ($text === 'لیست معاملات') { $this->sendInline($chat['id'], 'نوع فهرست را انتخاب کنید:', [[['text' => 'لیست خرید', 'callback_data' => 'trades:buy:1'], ['text' => 'لیست فروش', 'callback_data' => 'trades:sell:1']]]); return response()->noContent(); }
        if ($text === 'فیش‌های در انتظار تأیید' || $text === 'فیش‌های تأییدشده') { if (! $user->is_admin) { $this->send($chat['id'], 'اجازهٔ دسترسی ندارید.', $menu); return response()->noContent(); } $status = $text === 'فیش‌های تأییدشده' ? 'approved' : 'pending'; [$out, $keyboard] = $this->depositList($user, $status, 1); $this->sendInline($chat['id'], $out, $keyboard); return response()->noContent(); }
        if ($text === 'ثبت معامله') { $this->send($chat['id'], 'فرمت ثبت معامله: /trade buy mesghal 1.250 یا /trade sell gram 4.5', $menu); return response()->noContent(); }
        if (str_starts_with($text, '/trade ')) { [, $side, $unit, $qty] = array_pad(explode(' ', $text), 4, null); try { $t = $trades->create($user, ['side' => $side, 'unit' => $unit, 'quantity' => $qty]); $this->send($chat['id'], "معامله ثبت شد. قیمت واحد: ".number_format($t->unit_price).'، مبلغ کل: '.number_format($t->total_price), $menu); } catch (\Throwable $e) { $this->send($chat['id'], 'ثبت معامله ممکن نیست: '.$e->getMessage(), $menu); } return response()->noContent(); }
        $this->send($chat['id'], 'یکی از گزینه‌های منو را انتخاب کنید.', $menu); return response()->noContent();
    }
}
