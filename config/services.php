<?php

return [
    'talaboard' => [
        'url' => env('TALABOARD_API_URL'),
        'token' => env('TALABOARD_API_TOKEN'),
        'prices_path' => env('TALABOARD_PRICES_PATH', '/api/prices'),
        'prices_cache_ttl' => env('TALABOARD_PRICES_CACHE_TTL', 15),
        'prices_stale_ttl' => env('TALABOARD_PRICES_STALE_TTL', 3600),
        'prices_hot_cache_store' => env('TALABOARD_PRICES_HOT_CACHE_STORE', 'file'),
        'prices_connect_timeout' => env('TALABOARD_PRICES_CONNECT_TIMEOUT', 1),
        'prices_timeout' => env('TALABOARD_PRICES_TIMEOUT', 1),
        'trades_path' => env('TALABOARD_TRADES_PATH', '/api/trading/trades'),
        'verify_ssl' => env('TALABOARD_VERIFY_SSL', true),
    ],
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'connect_secret' => env('TELEGRAM_CONNECT_SECRET'),
        'defer_sends' => env('TELEGRAM_DEFER_SENDS', env('APP_ENV') !== 'testing'),
        'async_webhook' => env('TELEGRAM_ASYNC_WEBHOOK', env('APP_ENV') !== 'testing'),
        'fast_webhook_reply' => env('TELEGRAM_FAST_WEBHOOK_REPLY', env('APP_ENV') !== 'testing'),
        'webhook_queue' => env('TELEGRAM_WEBHOOK_QUEUE', 'deferred'),
        'callback_queue_connection' => env('TELEGRAM_CALLBACK_QUEUE_CONNECTION', 'database'),
        'callback_queue' => env('TELEGRAM_CALLBACK_QUEUE', 'telegram'),
        'connect_timeout' => env('TELEGRAM_CONNECT_TIMEOUT', 5),
        'timeout' => env('TELEGRAM_TIMEOUT', 10),
        'retry_attempts' => env('TELEGRAM_RETRY_ATTEMPTS', 1),
        'retry_delay' => env('TELEGRAM_RETRY_DELAY', 100),
        'proxy' => env('TELEGRAM_PROXY'),
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
        'connect_timeout' => env('MEMBERSHIP_CONNECT_TIMEOUT', 1),
        'timeout' => env('MEMBERSHIP_TIMEOUT', 2),
    ],
];
