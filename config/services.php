<?php

return [
    'talaboard' => [
        'url' => env('TALABOARD_API_URL'),
        'token' => env('TALABOARD_API_TOKEN'),
        'prices_path' => env('TALABOARD_PRICES_PATH', '/api/prices'),
        'trades_path' => env('TALABOARD_TRADES_PATH', '/api/trading/trades'),
        'verify_ssl' => env('TALABOARD_VERIFY_SSL', true),
    ],
    'telegram' => ['token' => env('TELEGRAM_BOT_TOKEN'), 'connect_secret' => env('TELEGRAM_CONNECT_SECRET')],
    'membership' => [
        'url' => env('MEMBERSHIP_API_URL'),
        'token' => env('MEMBERSHIP_API_TOKEN'),
    ],
];
