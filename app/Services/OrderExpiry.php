<?php
namespace App\Services;
use Carbon\CarbonImmutable;
class OrderExpiry { public function forNow(): CarbonImmutable { return CarbonImmutable::now(config('trading.timezone'))->addMinutes(2); } }
