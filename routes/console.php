<?php

use App\Services\TalaboardClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('trading:expire-orders')->everyMinute();
Schedule::call(fn () => app(TalaboardClient::class)->refresh(storeSnapshots: false))
    ->name('trading:warm-prices')
    ->everyMinute()
    ->withoutOverlapping();
