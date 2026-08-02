<?php

namespace Tests\Feature;

use App\Http\Controllers\TelegramWebhookController;
use App\Jobs\ProcessTelegramCallback;
use App\Services\TalaboardClient;
use App\Services\TelegramConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramCallbackJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_webhook_defers_update_deduplication_to_the_callback_job(): void
    {
        config([
            'services.telegram.async_webhook' => true,
            'services.telegram.callback_queue_connection' => 'database',
            'services.telegram.callback_queue' => 'telegram',
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 987654,
            'callback_query' => [
                'id' => 'queued-callback-id',
                'data' => 'unknown:callback',
                'from' => ['id' => 67890],
                'message' => ['chat' => ['id' => 12345]],
            ],
        ])->assertOk()->assertJsonPath('method', 'answerCallbackQuery');

        $this->assertDatabaseCount('telegram_updates', 0);
        $this->assertDatabaseHas('jobs', ['queue' => 'telegram']);
    }

    public function test_the_callback_job_deduplicates_updates_inside_the_worker(): void
    {
        $update = [
            'update_id' => 987654,
            '_callback_pre_answered' => true,
            'callback_query' => [
                'id' => 'queued-callback-id',
                'data' => 'unknown:callback',
                'from' => ['id' => 67890],
                'message' => ['chat' => ['id' => 12345]],
            ],
        ];

        $job = new ProcessTelegramCallback($update);
        $controller = app(TelegramWebhookController::class);
        $prices = app(TalaboardClient::class);
        $connections = app(TelegramConnectionService::class);

        $job->handle($controller, $prices, $connections);
        $job->handle($controller, $prices, $connections);

        $this->assertDatabaseCount('telegram_updates', 1);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 987654]);
    }
}
