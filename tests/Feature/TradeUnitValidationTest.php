<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class TradeUnitValidationTest extends TestCase
{
    public function test_a_trade_cannot_be_created_in_mesghal(): void
    {
        $response = $this->actingAs(User::factory()->make())->postJson('/api/trades', [
            'side' => 'buy',
            'asset' => 'gold',
            'unit' => 'mesghal',
            'quantity' => 1,
            'unit_price' => 1_000_000,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('unit');
    }
}
