<?php

namespace Tests\Unit;

use App\Models\Trade;
use PHPUnit\Framework\TestCase;

class TradeMinimumQuantityTest extends TestCase
{
    public function test_silver_trades_must_meet_the_minimum_quantity(): void
    {
        $this->assertFalse(Trade::meetsMinimumQuantity('gram', 9.999, 'silver_995'));
        $this->assertTrue(Trade::meetsMinimumQuantity('gram', 10, 'silver_995'));
        $this->assertFalse(Trade::meetsMinimumQuantity('mesghal', 2.170, 'silver_9999'));
        $this->assertTrue(Trade::meetsMinimumQuantity('mesghal', 2.171, 'silver_999'));
    }

    public function test_gold_has_a_one_gram_minimum(): void
    {
        $this->assertFalse(Trade::meetsMinimumQuantity('gram', 0.999, 'gold'));
        $this->assertTrue(Trade::meetsMinimumQuantity('gram', 1, 'gold'));
        $this->assertTrue(Trade::meetsMinimumQuantity('mesghal', 0.5, 'gold'));
    }

    public function test_coin_trades_are_not_affected_by_the_metal_minimum(): void
    {
        $this->assertTrue(Trade::meetsMinimumQuantity('count', 1, 'full_coin'));
    }
}
