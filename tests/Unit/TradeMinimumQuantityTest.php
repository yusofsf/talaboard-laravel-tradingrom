<?php

namespace Tests\Unit;

use App\Models\Trade;
use PHPUnit\Framework\TestCase;

class TradeMinimumQuantityTest extends TestCase
{
    public function test_metal_trades_must_meet_the_minimum_quantity(): void
    {
        $this->assertFalse(Trade::meetsMinimumQuantity('gram', 99.999));
        $this->assertTrue(Trade::meetsMinimumQuantity('gram', 100));
        $this->assertFalse(Trade::meetsMinimumQuantity('mesghal', 21.701));
        $this->assertTrue(Trade::meetsMinimumQuantity('mesghal', 21.702));
    }

    public function test_coin_trades_are_not_affected_by_the_metal_minimum(): void
    {
        $this->assertTrue(Trade::meetsMinimumQuantity('count', 1));
    }
}
