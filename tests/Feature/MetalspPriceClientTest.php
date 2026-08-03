<?php

namespace Tests\Feature;

use App\Models\PriceSnapshot;
use App\Services\TalaboardClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetalspPriceClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.talaboard.prices_hot_cache_store' => 'array']);
        Cache::clear();
        Cache::store('array')->clear();
    }

    public function test_it_fetches_prices_with_the_single_site_token(): void
    {
        config(['services.talaboard.url' => 'https://site.test', 'services.talaboard.token' => 'site-token']);
        Http::fake(['https://site.test/api/prices' => Http::response(['prices' => [
            'gold_9999_gram' => ['price' => 75_000_000],
            'silver_995_mesghal' => ['last_price' => 5_950_000],
            'full_coin' => ['price' => 850_000_000],
        ]])]);

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('750000000', $prices->get('gold_gram')->price);
        $this->assertSame('59500000', $prices->get('silver_995_mesghal')->price);
        $this->assertSame('8500000000', $prices->get('full_coin')->price);
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

        $this->assertSame('178827780', $prices->get('gold_gram')->price);
        $this->assertSame('774646200', $prices->get('gold_mesghal')->price);
        $this->assertSame('3650000', $prices->get('silver_995_gram')->price);
        $this->assertSame('3750000', $prices->get('silver_9999_gram')->price);
        $this->assertSame('1795083000', $prices->get('full_coin')->price);
        $this->assertSame('921840000', $prices->get('half_coin')->price);
        $this->assertSame('510018000', $prices->get('quarter_coin')->price);
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

        $this->assertSame('178827780', $prices->get('gold_gram')->price);
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

        $this->assertSame('178827780', $prices->get('gold_gram')->price);
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

        $this->assertSame('178827780', $prices->get('gold_gram')->price);
        Http::assertSentCount(2);
    }

    public function test_it_reuses_recent_prices_without_calling_the_upstream_again(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => null,
            'services.talaboard.prices_cache_ttl' => 5,
        ]);
        Http::fake(['https://site.test/api/prices' => Http::response([
            'gold' => ['geram' => 17_882_778],
        ])]);

        $client = app(TalaboardClient::class);
        $first = $client->prices();
        $second = $client->prices();

        $this->assertSame('178827780', $first->get('gold_gram')->price);
        $this->assertSame('178827780', $second->get('gold_gram')->price);
        $this->assertDatabaseCount('price_snapshots', 1);
        Http::assertSentCount(1);
    }

    public function test_a_cold_cache_returns_the_latest_snapshot_before_refreshing_upstream(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => null,
            'services.talaboard.prices_cache_ttl' => 5,
            'services.talaboard.prices_stale_ttl' => 60,
        ]);
        PriceSnapshot::create([
            'symbol' => 'gold_gram',
            'title' => 'گرم طلا',
            'price' => 750_000_000,
            'source_updated_at' => now()->subMinute(),
        ]);
        Http::fake(['https://site.test/api/prices' => Http::response([
            'gold' => ['geram' => 80_000_000],
        ])]);

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('750000000', $prices->get('gold_gram')->price);
        Http::assertNothingSent();
    }

    public function test_forced_refresh_warms_the_shared_price_cache(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => null,
            'services.talaboard.prices_cache_ttl' => 5,
            'services.talaboard.prices_stale_ttl' => 60,
        ]);
        Http::fake(['https://site.test/api/prices' => Http::response([
            'gold' => ['geram' => 80_000_000],
        ])]);

        app(TalaboardClient::class)->refresh();

        $cached = Cache::get('talaboard:prices:v1');
        $this->assertSame('800000000', $cached->get('gold_gram')->price);
        $this->assertNotNull(Cache::get('illuminate:cache:flexible:created:talaboard:prices:v1'));
        Http::assertSentCount(1);
    }

    public function test_local_hot_cache_avoids_a_cold_database_and_upstream_request(): void
    {
        config([
            'cache.stores.hot_prices_test' => ['driver' => 'array', 'serialize' => false],
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => null,
            'services.talaboard.prices_cache_ttl' => 15,
            'services.talaboard.prices_hot_cache_store' => 'hot_prices_test',
        ]);
        Http::fake(['https://site.test/api/prices' => Http::response([
            'gold' => ['geram' => 80_000_000],
        ])]);

        app(TalaboardClient::class)->refresh();
        Cache::clear();
        Http::fake(fn () => throw new \RuntimeException('Upstream must not be called.'));

        $prices = (new TalaboardClient)->prices();

        $this->assertSame('800000000', $prices->get('gold_gram')->price);
    }

    public function test_price_cache_can_be_disabled(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => null,
            'services.talaboard.prices_cache_ttl' => 0,
        ]);
        Http::fake(['https://site.test/api/prices' => Http::response([
            'gold' => ['geram' => 17_882_778],
        ])]);

        $client = app(TalaboardClient::class);
        $client->prices();
        $client->prices();

        $this->assertDatabaseCount('price_snapshots', 2);
        Http::assertSentCount(2);
    }

    public function test_a_cache_failure_does_not_block_live_prices(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => null,
            'services.talaboard.prices_cache_ttl' => 5,
        ]);
        Http::fake(['https://site.test/api/prices' => Http::response([
            'gold' => ['geram' => 17_882_778],
        ])]);
        Cache::partialMock()
            ->shouldReceive('many')
            ->once()
            ->andThrow(new \RuntimeException('Cache is unavailable.'));

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('178827780', $prices->get('gold_gram')->price);
        Http::assertSentCount(1);
    }

    public function test_a_snapshot_storage_failure_does_not_block_live_prices(): void
    {
        config([
            'services.talaboard.url' => 'https://site.test',
            'services.talaboard.token' => null,
            'services.talaboard.prices_cache_ttl' => 0,
        ]);
        Http::fake(['https://site.test/api/prices' => Http::response([
            'gold' => ['geram' => 17_882_778],
        ])]);
        PriceSnapshot::creating(fn () => throw new \RuntimeException('Database is unavailable.'));

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('178827780', $prices->get('gold_gram')->price);
        $this->assertDatabaseCount('price_snapshots', 0);
    }
}
