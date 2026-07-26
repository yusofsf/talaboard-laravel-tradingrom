<?php

namespace App\Services;

use App\Models\PriceSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class TalaboardClient
{
    public const PRODUCTS = [
        'gold_gram' => 'گرم طلا', 'gold_mesghal' => 'مثقال طلا',
        'silver_995_gram' => 'گرم نقره ۹۹۵', 'silver_995_mesghal' => 'مثقال نقره ۹۹۵',
        'silver_9999_gram' => 'گرم نقره ۹۹۹.۹', 'silver_9999_mesghal' => 'مثقال نقره ۹۹۹.۹',
        'full_coin' => 'تمام سکه', 'half_coin' => 'نیم سکه', 'quarter_coin' => 'ربع سکه',
    ];

    public const PRODUCT_ICONS = [
        'gold_gram' => '🥇', 'gold_mesghal' => '⚖️',
        'silver_995_gram' => '🥈', 'silver_995_mesghal' => '⚖️',
        'silver_9999_gram' => '🌕', 'silver_9999_mesghal' => '⚖️',
        'full_coin' => '🪙', 'half_coin' => '🪙', 'quarter_coin' => '🪙',
    ];

    public function prices(): Collection
    {
        $username = config('services.metalsp.username');
        $secret = config('services.metalsp.secret');

        if (! $username || ! $secret) {
            return PriceSnapshot::latest()->get()->unique('symbol')->keyBy('symbol');
        }

        try {
            $response = Http::acceptJson()->withBasicAuth($username, $secret)
                ->timeout(10)->get(config('services.metalsp.prices_url'));
            $response->throw();
            $payload = $response->json();
        } catch (\Throwable) {
            // Keep the bot available during a temporary upstream outage.
            return PriceSnapshot::latest()->get()->unique('symbol')->keyBy('symbol');
        }
        $prices = [
            'gold_gram' => data_get($payload, 'gold.geram'),
            'gold_mesghal' => data_get($payload, 'gold.mithqal'),
            'silver_995_gram' => data_get($payload, 'silver.gram_995'),
            'silver_995_mesghal' => data_get($payload, 'silver.mithqal_995'),
            'silver_9999_gram' => data_get($payload, 'silver.gram_999'),
            'silver_9999_mesghal' => data_get($payload, 'silver.mithqal_999'),
            'full_coin' => data_get($payload, 'gold.bahar'),
            'half_coin' => data_get($payload, 'gold.nim'),
            'quarter_coin' => data_get($payload, 'gold.rob'),
        ];

        foreach ($prices as $symbol => $price) {
            if (! is_numeric($price)) continue;
            PriceSnapshot::create([
                'symbol' => $symbol,
                'title' => self::PRODUCTS[$symbol],
                'price' => (int) round($price * 10),
                'source_updated_at' => now(),
            ]);
        }

        return PriceSnapshot::latest()->get()->unique('symbol')->keyBy('symbol');
    }

    public function registerTrade(array $payload): ?string
    {
        if (! config('services.talaboard.url')) return null;
        $response = Http::acceptJson()->withToken(config('services.talaboard.token'))->post(rtrim(config('services.talaboard.url'), '/').config('services.talaboard.trades_path'), $payload);
        $response->throw();
        return (string) ($response->json('reference') ?? $response->json('id') ?? '');
    }
}
