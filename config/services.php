<?php

return [
    'talaboard' => [
        'url' => env('TALABOARD_API_URL'),
        'token' => env('TALABOARD_API_TOKEN'),
        'prices_path' => env('TALABOARD_PRICES_PATH', '/api/prices'),
        'trades_path' => env('TALABOARD_TRADES_PATH', '/api/trading/trades'),
        'verify_ssl' => env('TALABOARD_VERIFY_SSL', true),
    ],
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'connect_secret' => env('TELEGRAM_CONNECT_SECRET'),
        'channels' => [
            'gold' => env('TELEGRAM_CHANNEL_GOLD'),
            'silver_995' => env('TELEGRAM_CHANNEL_SILVER_995'),
            'silver_999' => env('TELEGRAM_CHANNEL_SILVER_9999'),
            'silver_9999' => env('TELEGRAM_CHANNEL_SILVER_9999'),
            'full_coin' => env('TELEGRAM_CHANNEL_FULL_COIN'),
            'half_coin' => env('TELEGRAM_CHANNEL_HALF_COIN'),
            'quarter_coin' => env('TELEGRAM_CHANNEL_QUARTER_COIN'),
        ],
    ],
    'membership' => [
        'url' => env('MEMBERSHIP_API_URL'),
        'token' => env('MEMBERSHIP_API_TOKEN'),
    ],
];
