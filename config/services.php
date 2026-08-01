<?php

return [
    'talaboard' => [
        'url' => env('TALABOARD_API_URL'),
        'token' => env('TALABOARD_API_TOKEN'),
        'prices_path' => env('TALABOARD_PRICES_PATH', '/api/prices'),
        'prices_cache_ttl' => env('TALABOARD_PRICES_CACHE_TTL', 5),
        'prices_stale_ttl' => env('TALABOARD_PRICES_STALE_TTL', 60),
        'prices_connect_timeout' => env('TALABOARD_PRICES_CONNECT_TIMEOUT', 2),
        'prices_timeout' => env('TALABOARD_PRICES_TIMEOUT', 4),
        'trades_path' => env('TALABOARD_TRADES_PATH', '/api/trading/trades'),
        'verify_ssl' => env('TALABOARD_VERIFY_SSL', true),
    ],
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'connect_secret' => env('TELEGRAM_CONNECT_SECRET'),
        'defer_sends' => env('TELEGRAM_DEFER_SENDS', env('APP_ENV') !== 'testing'),
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
        // The website membership endpoints live on the same API by default.
        // Dedicated values can still override these when the services are split.
        'url' => env('MEMBERSHIP_API_URL', rtrim((string) env('TALABOARD_API_URL'), '/').'/api/telegram'),
        'token' => env('MEMBERSHIP_API_TOKEN', env('TALABOARD_API_TOKEN')),
        'web_url' => env('MEMBERSHIP_WEB_URL', rtrim((string) env('TALABOARD_API_URL'), '/').'/membership'),
    ],
];
