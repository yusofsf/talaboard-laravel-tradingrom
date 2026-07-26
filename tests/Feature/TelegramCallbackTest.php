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

    public function test_delivery_callback_sends_an_inline_keyboard_as_json(): void
    {
        config(['services.telegram.token' => 'test-token']);
        Http::fake();

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'flow:delivery:start',
                'message' => ['chat' => ['id' => 12345]],
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && in_array('application/json', $request->header('Content-Type'), true)
            && ($request->data()['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'flow:delivery:asset:gold');
    }

    public function test_connect_uses_the_website_membership_api_when_configured(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/link' => Http::response(['linked' => true, 'vip' => true]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 12345],
                'from' => ['id' => 67890, 'username' => 'talaboard_user'],
                'text' => '/connect 265395',
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === 'https://talaboard.test/api/telegram/link'
            && $request['code'] === '265395'
            && $request['telegram_user_id'] === '67890'
            && $request['telegram_chat_id'] === '12345');
    }
}
