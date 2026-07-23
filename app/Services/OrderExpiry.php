<?php
namespace App\Services;
use Carbon\CarbonImmutable;
class OrderExpiry { public function forNow(): CarbonImmutable { $now=CarbonImmutable::now(config('trading.timezone')); $end=$now->endOfDay(); return $now->dayOfWeek===4 ? $now->next(CarbonImmutable::SATURDAY)->endOfDay() : $end; } }
