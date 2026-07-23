<?php
namespace App\Services;
use Carbon\CarbonImmutable;
class TradingHours
{
    public function isOpen(): bool { return true; }
    public function message(): string { return 'بازار در حال حاضر بسته است.'; }
}
