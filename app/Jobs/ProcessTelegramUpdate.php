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

    public int $timeout = 30;

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
        $request = Request::create('/api/telegram/webhook', 'POST', $this->update);
        $request->attributes->set('telegram_ingress_verified', true);
        $controller->process($request, $prices, $connections);
    }
}
