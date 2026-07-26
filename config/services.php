<?php

return [
    'talaboard' => [
        'url' => env('TALABOARD_API_URL'),
        'token' => env('TALABOARD_API_TOKEN'),
        'prices_path' => env('TALABOARD_PRICES_PATH', '/api/trading/prices'),
        'trades_path' => env('TALABOARD_TRADES_PATH', '/api/trading/trades'),
    ],
    'metalsp' => [
        'prices_url' => env('METALSP_PRICES_URL', 'https://metalsp.ir/api/v1/prices'),
        'username' => env('METALSP_API_USERNAME'),
        'secret' => env('METALSP_API_SECRET'),
    ],
    'telegram' => ['token' => env('TELEGRAM_BOT_TOKEN')],
    'membership' => [
        'url' => env('MEMBERSHIP_API_URL'),
        'token' => env('MEMBERSHIP_API_TOKEN'),
    ],
];
