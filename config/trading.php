<?php

return [
    'currency' => env('TRADING_CURRENCY', 'IRR'),
    'card_number' => env('DEPOSIT_CARD_NUMBER'),
    'account_number' => env('DEPOSIT_ACCOUNT_NUMBER', '7604343793'),
    'iban' => env('DEPOSIT_IBAN', 'IR89-0120-0100-0000-7604-3437-93'),
    'account_holder' => env('DEPOSIT_ACCOUNT_HOLDER', 'محمود صفرپور'),
    'timezone' => 'Asia/Tehran',
    'log_channel' => env('TRADING_LOG_CHANNEL', 'trading'),
    // بازار از ابتدای پنج‌شنبه تا ابتدای یکشنبه باز است؛ در .env قابل تغییر است.
    'weekend_open_day' => env('TRADING_OPEN_DAY', 4),
    'weekend_close_day' => env('TRADING_CLOSE_DAY', 0),
];
