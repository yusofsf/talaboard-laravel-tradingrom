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

    public function test_my_trades_shows_the_connected_users_buys_and_sells_without_a_side_prompt(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/member' => Http::response(['linked' => true, 'vip' => true]),
            'https://talaboard.test/api/telegram/trade-room/offers' => Http::response(['offers' => [
                ['id' => 10, 'side' => 'buy', 'asset' => 'gold', 'unit' => 'gram', 'quantity' => 100, 'unit_price' => 18_000_000, 'total_price' => 1_800_000_000, 'status' => 'active'],
                ['id' => 11, 'side' => 'sell', 'asset' => 'silver_995', 'unit' => 'gram', 'quantity' => 100, 'unit_price' => 380_000, 'total_price' => 38_000_000, 'status' => 'active'],
            ]]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 12345],
                'from' => ['id' => 67890],
                'text' => 'معاملات من',
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === 'https://talaboard.test/api/telegram/trade-room/offers'
            && $request['telegram_chat_id'] === '12345'
            && $request['mine'] === true);

        $messages = collect(Http::recorded())->pluck(0)
            ->filter(fn ($request) => str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], '📋 معاملات من'));

        $this->assertCount(2, $messages);
        $this->assertTrue($messages->contains(fn ($request) => str_contains((string) $request['text'], 'خرید طلا')
            && str_contains((string) $request['text'], 'قیمت واحد: 18,000,000 ریال')
            && str_contains((string) $request['text'], 'وضعیت: فعال')
            && ! str_contains((string) $request['text'], '#10')
            && ($request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'trade_delete:10'));
        $this->assertTrue($messages->contains(fn ($request) => str_contains((string) $request['text'], 'فروش نقره ۹۹۵')
            && ($request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === 'حذف'));
    }

    public function test_deleting_my_trade_room_offer_cancels_it_on_the_site_and_removes_the_message(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/trade-room/offers/15/cancel' => Http::response(['deleted' => true]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'trade_delete:15',
                'message' => ['message_id' => 77, 'chat' => ['id' => 12345]],
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === 'https://talaboard.test/api/telegram/trade-room/offers/15/cancel'
            && $request['telegram_chat_id'] === '12345'
            && $request['offer_id'] === 15);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/deleteMessage')
            && $request['chat_id'] === 12345
            && $request['message_id'] === 77);
    }

    public function test_my_history_shows_accepted_trade_room_offers_as_separate_messages(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/member' => Http::response(['linked' => true, 'vip' => true]),
            'https://talaboard.test/api/telegram/trade-room/offers' => Http::response(['offers' => [
                ['id' => 20, 'side' => 'buy', 'asset' => 'gold', 'unit' => 'gram', 'quantity' => 150, 'unit_price' => 18_500_000, 'total_price' => 2_775_000_000, 'status' => 'accepted'],
                ['id' => 21, 'side' => 'sell', 'asset' => 'silver_995', 'unit' => 'gram', 'quantity' => 100, 'unit_price' => 390_000, 'total_price' => 39_000_000, 'status' => 'active'],
            ]]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 12345],
                'from' => ['id' => 67890],
                'text' => 'سوابق من',
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === 'https://talaboard.test/api/telegram/trade-room/offers'
            && $request['telegram_chat_id'] === '12345'
            && $request['mine'] === true
            && $request['status'] === 'accepted');

        $messages = collect(Http::recorded())->pluck(0)
            ->filter(fn ($request) => str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], '📋 سوابق من'));

        $this->assertCount(1, $messages);
        $this->assertTrue($messages->contains(fn ($request) => str_contains((string) $request['text'], 'خرید طلا')
            && str_contains((string) $request['text'], 'قیمت واحد: 18,500,000 ریال')
            && str_contains((string) $request['text'], 'وضعیت: پذیرفته‌شده')
            && ! isset($request['reply_markup'])));
    }

    public function test_public_trade_list_sends_each_offer_separately_with_accept_buttons(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/member' => Http::response(['linked' => true, 'vip' => true]),
            'https://talaboard.test/api/telegram/trade-room/offers' => Http::response(['offers' => [
                ['id' => 23, 'side' => 'sell', 'asset' => 'gold', 'unit' => 'gram', 'quantity' => 100, 'unit_price' => 18_167_293, 'total_price' => 1_816_729_300, 'status' => 'active'],
                ['id' => 24, 'side' => 'sell', 'asset' => 'silver_995', 'unit' => 'gram', 'quantity' => 200, 'unit_price' => 390_000, 'total_price' => 78_000_000, 'status' => 'active'],
            ]]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'trades:sell:1',
                'message' => ['message_id' => 55, 'chat' => ['id' => 12345]],
            ],
        ])->assertNoContent();

        $offerMessages = collect(Http::recorded())
            ->pluck(0)
            ->filter(fn ($request) => str_contains($request->url(), '/sendMessage') && str_contains((string) $request['text'], 'لیست فروش — صفحه 1'));

        $this->assertCount(2, $offerMessages);
        $this->assertTrue($offerMessages->contains(fn ($request) => str_contains((string) $request['text'], "طلا\n")
            && ! str_contains((string) $request['text'], '#23')
            && str_contains((string) $request['text'], 'قیمت واحد: 18,167,293 ریال')
            && ($request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'trade_accept:full:23'
            && ($request['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? null) === 'trade_accept:partial:23'));
    }

    public function test_accept_full_offer_posts_the_acceptance_to_the_site(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/trade-room/offers/23/accept' => Http::response(['accepted' => true]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'trade_accept:full:23',
                'message' => ['chat' => ['id' => 12345]],
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === 'https://talaboard.test/api/telegram/trade-room/offers/23/accept'
            && $request['telegram_chat_id'] === '12345'
            && $request['offer_id'] === 23
            && ! isset($request['quantity']));
    }

    public function test_accept_partial_offer_uses_the_entered_quantity(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/member' => Http::response(['linked' => true, 'vip' => true]),
            'https://talaboard.test/api/telegram/trade-room/offers/23/accept' => Http::response(['accepted' => true]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'trade_accept:partial:23',
                'message' => ['chat' => ['id' => 12345]],
            ],
        ])->assertNoContent();

        $this->assertSame(['type' => 'trade_accept', 'stage' => 'partial_quantity', 'offer_id' => 23], Cache::get('telegram-flow:12345'));

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 12345],
                'from' => ['id' => 67890],
                'text' => '100',
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === 'https://talaboard.test/api/telegram/trade-room/offers/23/accept'
            && $request['telegram_chat_id'] === '12345'
            && $request['offer_id'] === 23
            && $request['quantity'] === 100.0);
    }
}
