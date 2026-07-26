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
        Http::fake(['https://site.test/api/trading/prices' => Http::response(['prices' => [
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
}
