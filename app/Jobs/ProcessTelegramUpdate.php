<?php

namespace App\Jobs;

use App\Http\Controllers\TelegramWebhookController;
use App\Services\TalaboardClient;
use App\Services\TelegramConnectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public array $update)
    {
        $this->onConnection((string) config('services.telegram.webhook_queue', 'background'));
        $this->onQueue('telegram');
    }

    public function handle(
        TelegramWebhookController $controller,
        TalaboardClient $prices,
        TelegramConnectionService $connections,
    ): void {
        // This job may run from Laravel's deferred queue after the HTTP
        // response has already been sent. Send Telegram requests directly;
        // nesting another deferred callback can leave them unexecuted.
        config(['services.telegram.defer_sends' => false]);

        $request = Request::create('/api/telegram/webhook', 'POST', $this->update);
        $request->attributes->set('telegram_ingress_verified', true);
        $controller->process($request, $prices, $connections);
    }
}
