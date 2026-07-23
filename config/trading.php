<?php

return [
    'currency' => env('TRADING_CURRENCY', 'IRR'),
    'card_number' => env('DEPOSIT_CARD_NUMBER', '---- ---- ---- ---- ----'),
    'iban' => env('DEPOSIT_IBAN', 'IR------------------------'),
    'timezone' => 'Asia/Tehran',
    // بازار از ابتدای پنج‌شنبه تا ابتدای یکشنبه باز است؛ در .env قابل تغییر است.
    'weekend_open_day' => env('TRADING_OPEN_DAY', 4),
    'weekend_close_day' => env('TRADING_CLOSE_DAY', 0),
];
