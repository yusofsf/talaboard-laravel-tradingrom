<?php

namespace App\Jobs;

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireTelegramOffer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public int|string $offerId,
        public int|string $channelId,
        public int|string $messageId,
        public string $expiresAt,
    ) {
        $this->onConnection((string) config('services.telegram.offer_expiry_queue_connection', 'database'));
        $this->onQueue((string) config('services.telegram.callback_queue', 'telegram'));
    }

    public function handle(TelegramWebhookController $controller): void
    {
        $controller->expirePublishedOffer(
            $this->offerId,
            $this->channelId,
            $this->messageId,
            $this->expiresAt,
        );
    }
}
