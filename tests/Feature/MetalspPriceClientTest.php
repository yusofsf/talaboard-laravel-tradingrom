<?php

namespace Tests\Feature;

use App\Services\TalaboardClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetalspPriceClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fetches_prices_with_the_single_site_token(): void
    {
        config(['services.talaboard.url' => 'https://site.test', 'services.talaboard.token' => 'site-token']);
        Http::fake(['https://site.test/api/prices' => Http::response(['prices' => [
            'gold_9999_gram' => ['price' => 75_000_000],
            'silver_995_mesghal' => ['last_price' => 5_950_000],
            'full_coin' => ['price' => 850_000_000],
        ]])]);

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('75000000', $prices->get('gold_gram')->price);
        $this->assertSame('5950000', $prices->get('silver_995_mesghal')->price);
        $this->assertSame('850000000', $prices->get('full_coin')->price);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer site-token'));
    }

    public function test_it_maps_the_live_metalsp_price_payload(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => 'site-token',
            'services.talaboard.prices_path' => '/api/prices',
        ]);
        Http::fake(['https://site.test/api/prices' => Http::response([
            'gold' => ['mithqal' => 77_464_620, 'geram' => 17_882_778, 'bahar' => 179_508_300, 'nim' => 92_184_000, 'rob' => 51_001_800],
            'silver' => ['mithqal_999' => 1_728_111, 'gram_999' => 375_000, 'mithqal_995' => 1_682_028, 'gram_995' => 365_000],
            'updated_at' => '19:26:41',
        ])]);

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('17882778', $prices->get('gold_gram')->price);
        $this->assertSame('77464620', $prices->get('gold_mesghal')->price);
        $this->assertSame('365000', $prices->get('silver_995_gram')->price);
        $this->assertSame('375000', $prices->get('silver_9999_gram')->price);
        $this->assertSame('179508300', $prices->get('full_coin')->price);
        $this->assertSame('92184000', $prices->get('half_coin')->price);
        $this->assertSame('51001800', $prices->get('quarter_coin')->price);
    }

    public function test_it_fetches_public_prices_without_a_token(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => null,
            'services.talaboard.prices_path' => '/api/prices',
        ]);
        Http::fake(['https://site.test/api/prices' => Http::response([
            'gold' => ['geram' => 17_882_778],
        ])]);

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('17882778', $prices->get('gold_gram')->price);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }

    public function test_it_falls_back_to_the_current_public_price_path(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => 'site-token',
            'services.talaboard.prices_path' => '/api/trading/prices',
        ]);
        Http::fake([
            'https://site.test/api/trading/prices' => Http::response([], 404),
            'https://site.test/api/prices' => Http::response([
                'gold' => ['geram' => 17_882_778],
            ]),
        ]);

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('17882778', $prices->get('gold_gram')->price);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://site.test/api/prices'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_an_invalid_token_does_not_block_the_public_prices_endpoint(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => 'expired-token',
            'services.talaboard.prices_path' => '/api/prices',
        ]);
        Http::fake([
            'https://site.test/api/prices' => Http::sequence()
                ->push(['message' => 'Unauthenticated.'], 401)
                ->push(['gold' => ['geram' => 17_882_778]], 200),
        ]);

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('17882778', $prices->get('gold_gram')->price);
        Http::assertSentCount(2);
    }
}
