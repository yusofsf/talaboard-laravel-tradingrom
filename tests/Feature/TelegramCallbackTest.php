<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramCallbackTest extends TestCase
{
    public function test_main_menu_places_membership_registration_next_to_trade_channels(): void
    {
        config(['services.telegram.token' => 'test-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 12345],
                'from' => ['id' => 67890],
                'text' => '/start',
            ],
        ])->assertNoContent();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $keyboard = $request['reply_markup']['keyboard'] ?? [];

            return in_array(['کانال‌های خرید و فروش', 'ثبت نام عضویت ویژه'], $keyboard, true)
                && in_array(['کیف پول و دارایی‌ها', 'افزایش موجودی انبار'], $keyboard, true)
                && ! collect($keyboard)->flatten()->contains('وضعیت حساب');
        });
    }

    public function test_membership_registration_option_links_to_the_site_membership_page(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.web_url' => 'https://talaboard.test/membership',
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 12345],
                'from' => ['id' => 67890],
                'text' => 'ثبت نام عضویت ویژه',
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && ($request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === 'ثبت نام عضویت ویژه'
            && ($request['reply_markup']['inline_keyboard'][0][0]['url'] ?? null) === 'https://talaboard.test/membership');
    }

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
                ['id' => 12, 'side' => 'buy', 'asset' => 'gold', 'unit' => 'gram', 'quantity' => 2, 'unit_price' => 18_100_000, 'total_price' => 36_200_000, 'status' => 'accepted'],
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
            && $request['mine'] === true
            && ! isset($request['status']));

        $messages = collect(Http::recorded())->pluck(0)
            ->filter(fn ($request) => str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], '📋 معاملات من'));

        $this->assertCount(2, $messages);
        $this->assertTrue($messages->contains(fn ($request) => str_contains((string) $request['text'], 'خرید طلا')
            && str_contains((string) $request['text'], 'قیمت واحد: 1,800,000 تومان')
            && str_contains((string) $request['text'], 'وضعیت: فعال')
            && ! str_contains((string) $request['text'], '#10')
            && ($request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'trade_delete:10'));
        $this->assertTrue($messages->contains(fn ($request) => str_contains((string) $request['text'], 'فروش نقره ۹۹۵')
            && ($request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === 'حذف'));
        $this->assertFalse($messages->contains(fn ($request) => str_contains((string) $request['text'], 'وضعیت: پذیرفته‌شده')));
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
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === 12345
            && str_contains((string) $request['text'], 'با موفقیت حذف شد'));
    }

    public function test_deleting_my_trade_room_offer_accepts_a_successful_empty_response(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/trade-room/offers/15/cancel' => Http::response(null, 204),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'trade_delete:15',
                'message' => ['message_id' => 77, 'chat' => ['id' => 12345]],
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/deleteMessage')
            && $request['chat_id'] === 12345
            && $request['message_id'] === 77);
    }

    public function test_deleting_my_trade_room_offer_keeps_the_message_when_site_rejects_deletion(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/trade-room/offers/15/cancel' => Http::response(['deleted' => false]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'trade_delete:15',
                'message' => ['message_id' => 77, 'chat' => ['id' => 12345]],
            ],
        ])->assertNoContent();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/deleteMessage'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery')
            && $request['show_alert'] === true);
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
                ['id' => 22, 'side' => 'sell', 'asset' => 'full_coin', 'unit' => 'piece', 'quantity' => 1, 'unit_price' => 700_000_000, 'total_price' => 700_000_000, 'status' => 'cancelled'],
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
            && ! isset($request['status']));

        $messages = collect(Http::recorded())->pluck(0)
            ->filter(fn ($request) => str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], '📋 سوابق من'));

        $this->assertCount(1, $messages);
        $this->assertTrue($messages->contains(fn ($request) => str_contains((string) $request['text'], 'خرید طلا')
            && str_contains((string) $request['text'], 'قیمت واحد: 1,850,000 تومان')
            && str_contains((string) $request['text'], 'وضعیت: پذیرفته‌شده')
            && isset($request['reply_markup']['keyboard'])));
        $this->assertTrue($messages->contains(fn ($request) => str_contains((string) $request['text'], 'فروش تمام سکه')
            && str_contains((string) $request['text'], 'وضعیت: ردشده')));
    }

    public function test_created_trade_is_published_to_its_asset_channel(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
            'services.telegram.channels.gold' => '@gold_room',
        ]);
        Cache::put('telegram-flow:12345', [
            'type' => 'trade', 'stage' => 'price', 'side' => 'sell', 'asset' => 'gold',
            'unit' => 'gram', 'quantity' => 100, 'unit_price' => 18_167_293,
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/member' => Http::response(['linked' => true, 'vip' => true]),
            'https://talaboard.test/api/telegram/trade-room/offers/create' => Http::response([
                'id' => 23, 'side' => 'sell', 'asset' => 'gold', 'unit' => 'gram', 'quantity' => 100,
                'unit_price' => 18_167_293, 'total_price' => 1_816_729_300, 'status' => 'active',
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'flow:trade:price:default',
                'from' => ['id' => 12345],
                'message' => ['message_id' => 55, 'chat' => ['id' => 12345]],
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '@gold_room'
            && str_contains((string) $request['text'], 'نام مستعار: کاربر')
            && str_contains((string) $request['text'], 'قیمت واحد: 1,816,729 تومان')
            && ($request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'trade_accept:full:23'
            && ($request['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? null) === 'trade_accept:partial:23');
    }

    public function test_accept_full_offer_posts_the_acceptance_to_the_site(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Cache::put('telegram-offer-message:23', [
            'channel_id' => '@gold_room', 'message_id' => 77,
            'offer' => ['id' => 23, 'side' => 'sell', 'asset' => 'gold', 'unit' => 'gram', 'quantity' => 100, 'unit_price' => 18_000_000],
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/member' => Http::response(['linked' => true, 'vip' => true]),
            'https://talaboard.test/api/telegram/trade-room/offers/23/accept' => Http::response(['accepted' => true]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'trade_accept:full:23',
                'from' => ['id' => 12345],
                'message' => ['message_id' => 77, 'chat' => ['id' => '@gold_room']],
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === 'https://talaboard.test/api/telegram/trade-room/offers/23/accept'
            && $request['telegram_chat_id'] === '12345'
            && $request['offer_id'] === 23
            && ! isset($request['quantity']));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery')
            && $request['text'] === 'پیامی از طرف ربات برای انجام معامله به خصوصی شما ارسال شد.'
            && $request['show_alert'] === true);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/deleteMessage')
            && $request['chat_id'] === '@gold_room'
            && $request['message_id'] === 77);
    }

    public function test_accept_partial_offer_uses_the_entered_quantity(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Cache::put('telegram-offer-message:23', [
            'channel_id' => '@gold_room', 'message_id' => 77,
            'offer' => ['id' => 23, 'side' => 'sell', 'asset' => 'gold', 'unit' => 'gram', 'quantity' => 250, 'unit_price' => 18_000_000, 'alias' => 'بازرگان'],
        ]);
        config(['services.telegram.channels.gold' => '@gold_room']);
        Http::fake([
            'https://talaboard.test/api/telegram/member' => Http::response(['linked' => true, 'vip' => true]),
            'https://talaboard.test/api/telegram/trade-room/offers/23/accept' => Http::response(['accepted' => true, 'remaining_quantity' => 150]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 88]]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'callback-id',
                'data' => 'trade_accept:partial:23',
                'from' => ['id' => 12345],
                'message' => ['message_id' => 77, 'chat' => ['id' => '@gold_room']],
            ],
        ])->assertNoContent();

        $flow = Cache::get('telegram-flow:12345');
        $this->assertSame('trade_accept', $flow['type']);
        $this->assertSame(23, $flow['offer_id']);
        $this->assertNotEmpty($flow['acceptance_token']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery')
            && $request['text'] === 'پیامی از طرف ربات برای انجام معامله به خصوصی شما ارسال شد.');

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
        Http::assertSent(fn ($request) => str_contains($request->url(), '/deleteMessage') && $request['message_id'] === 77);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '@gold_room'
            && str_contains((string) $request['text'], 'مقدار: 150 گرم'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '12345'
            && str_contains((string) $request['text'], 'بخشی از معامله با موفقیت انجام شد')
            && str_contains((string) $request['text'], 'مانده معامله: 150 گرم'));
    }

    public function test_second_acceptor_is_told_the_offer_is_in_progress(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Cache::put('telegram-linked:22222', true, now()->addMinute());
        Cache::put('telegram-membership:22222', ['linked' => true, 'vip' => true], now()->addMinute());
        Cache::put('telegram-offer-processing:23', 'first-user-token', now()->addMinutes(5));
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'second-callback',
                'data' => 'trade_accept:full:23',
                'from' => ['id' => 22222],
                'message' => ['message_id' => 77, 'chat' => ['id' => '@gold_room']],
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery')
            && $request['text'] === 'معامله در حال انجام است.'
            && $request['show_alert'] === true);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/offers/23/accept'));
    }

    public function test_unconnected_acceptor_is_told_to_register_and_connect_before_accepting(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/member' => Http::response(['linked' => false, 'vip' => false]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'unconnected-acceptor',
                'data' => 'trade_accept:full:23',
                'from' => ['id' => 77777],
                'message' => ['message_id' => 77, 'chat' => ['id' => '@gold_room']],
            ],
        ])->assertNoContent();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/offers/23/accept'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery')
            && str_contains((string) $request['text'], 'ثبت‌نام')
            && str_contains((string) $request['text'], '/connect')
            && $request['show_alert'] === true);
    }

    public function test_connected_non_vip_user_cannot_use_trading(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/member' => Http::response(['linked' => true, 'vip' => false]),
            'https://talaboard.test/api/telegram/trade-room/offers' => Http::response(['offers' => []]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 33333], 'from' => ['id' => 33333], 'text' => 'معاملات من'],
        ])->assertNoContent();

        Http::assertNotSent(fn ($request) => $request->url() === 'https://talaboard.test/api/telegram/trade-room/offers');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage'));
    }

    public function test_user_can_set_trade_alias(): void
    {
        config(['services.telegram.token' => 'test-token']);
        Cache::put('telegram-linked:44444', true, now()->addMinute());
        Cache::put('telegram-membership:44444', ['linked' => true, 'vip' => true], now()->addMinute());
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 44444], 'from' => ['id' => 44444], 'text' => 'نام مستعار'],
        ])->assertNoContent();
        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 44444], 'from' => ['id' => 44444], 'text' => 'بازرگان طلا'],
        ])->assertNoContent();

        $this->assertSame('بازرگان طلا', Cache::get('telegram-trade-alias:44444'));
    }

    public function test_custom_toman_price_is_sent_to_the_site_as_rial(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Cache::put('telegram-linked:55555', true, now()->addMinute());
        Cache::put('telegram-membership:55555', ['linked' => true, 'vip' => true], now()->addMinute());
        Cache::put('telegram-flow:55555', [
            'type' => 'trade', 'stage' => 'custom_price', 'side' => 'sell',
            'asset' => 'gold', 'unit' => 'gram', 'quantity' => 1,
        ]);
        Http::fake([
            'https://talaboard.test/api/telegram/trade-room/offers/create' => Http::response([
                'id' => 30, 'unit_price' => 1_234_560, 'total_price' => 1_234_560,
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 55555], 'from' => ['id' => 55555], 'text' => '123456'],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === 'https://talaboard.test/api/telegram/trade-room/offers/create'
            && $request['unit_price'] === 1_234_560);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'قیمت واحد: 123,456 تومان'));
    }

    public function test_owner_cannot_accept_own_channel_offer(): void
    {
        config([
            'services.telegram.token' => 'test-token',
            'services.membership.url' => 'https://talaboard.test/api/telegram',
            'services.membership.token' => 'shared-secret',
        ]);
        Cache::put('telegram-linked:66666', true, now()->addMinute());
        Cache::put('telegram-membership:66666', ['linked' => true, 'vip' => true], now()->addMinute());
        Cache::put('telegram-offer-message:40', [
            'channel_id' => '@gold_room', 'message_id' => 90,
            'offer' => ['id' => 40, 'asset' => 'gold', 'unit' => 'gram', 'quantity' => 10, 'unit_price' => 1_000_000, 'owner_telegram_chat_id' => '66666'],
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'owner-callback', 'data' => 'trade_accept:full:40',
                'from' => ['id' => 66666],
                'message' => ['message_id' => 90, 'chat' => ['id' => '@gold_room']],
            ],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery')
            && $request['text'] === 'شما نمی‌توانید معامله خودتان را بپذیرید.'
            && $request['show_alert'] === true);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/offers/40/accept'));
    }
}
