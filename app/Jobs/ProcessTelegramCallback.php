<?php

namespace App\Jobs;

use App\Http\Controllers\TelegramWebhookController;
use App\Services\TalaboardClient;
use App\Services\TelegramConnectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcessTelegramCallback implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public array $update)
    {
        $this->onConnection((string) config('services.telegram.callback_queue_connection', 'database'));
        $this->onQueue((string) config('services.telegram.callback_queue', 'telegram'));
    }

    public function handle(
        TelegramWebhookController $controller,
        TalaboardClient $prices,
        TelegramConnectionService $connections,
    ): void {
        if (filled($this->update['update_id'] ?? null)) {
            $inserted = DB::table('telegram_updates')->insertOrIgnore([
                'update_id' => (int) $this->update['update_id'],
                'processed_at' => now(),
            ]);

            if ($inserted === 0) {
                return;
            }
        }

        // The callback was acknowledged by the webhook response. Everything
        // remaining, including Telegram replies, is performed by this worker.
        config(['services.telegram.defer_sends' => false]);

        $request = Request::create('/api/telegram/webhook', 'POST', $this->update);
        $request->attributes->set('telegram_ingress_verified', true);
        $controller->process($request, $prices, $connections);
    }
}
