<?php

namespace Tests\Feature;

use App\Services\TalaboardClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetalspPriceClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_metalsp_prices_from_tomans_to_rials(): void
    {
        config(['services.metalsp.username' => 'user', 'services.metalsp.secret' => 'secret']);
        Http::fake(['https://metalsp.ir/api/v1/prices' => Http::response([
            'gold' => ['geram' => 7_500_000, 'mithqal' => 32_500_000, 'bahar' => 85_000_000, 'nim' => 45_000_000, 'rob' => 27_500_000],
            'silver' => ['gram_995' => 137_000, 'mithqal_995' => 595_000, 'gram_999' => 138_500, 'mithqal_999' => 600_000],
        ])]);

        $prices = app(TalaboardClient::class)->prices();

        $this->assertSame('75000000', $prices->get('gold_gram')->price);
        $this->assertSame('5950000', $prices->get('silver_995_mesghal')->price);
        $this->assertSame('850000000', $prices->get('full_coin')->price);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('user:secret')));
    }
}
