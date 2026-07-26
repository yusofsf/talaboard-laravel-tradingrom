<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramCallbackTest extends TestCase
{
    public function test_paid_deposit_callback_starts_the_amount_flow(): void
    {
        config(['services.telegram.token' => 'test-token']);
        Http::fake();

        $response = $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'flow:deposit:paid',
                'message' => ['chat' => ['id' => 12345]],
            ],
        ]);

        $response->assertNoContent();
        $this->assertSame(['type' => 'deposit', 'stage' => 'amount'], Cache::get('telegram-flow:12345'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage'));
    }
}
