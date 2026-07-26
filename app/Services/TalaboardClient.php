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

    public function prices(): Collection
    {
        $url = config('services.talaboard.url');
        if (! $url) return PriceSnapshot::latest()->get()->unique('symbol')->keyBy('symbol');
        $response = Http::acceptJson()->withToken(config('services.talaboard.token'))
            ->get(rtrim($url, '/').config('services.talaboard.prices_path'));
        $response->throw();
        foreach ($response->json('prices', $response->json()) as $key => $item) {
            $symbol = is_string($key) ? $key : ($item['symbol'] ?? null);
            // نام‌های قدیمی API به دو قیمت عمومی طلا نگاشت می‌شوند.
            if ($symbol === 'gold_9999_gram' || $symbol === 'gold_995_gram') $symbol = 'gold_gram';
            if ($symbol === 'gold_9999_mesghal' || $symbol === 'gold_995_mesghal') $symbol = 'gold_mesghal';
            if (! $symbol || ! isset(self::PRODUCTS[$symbol])) continue;
            PriceSnapshot::create(['symbol' => $symbol, 'title' => self::PRODUCTS[$symbol], 'price' => $item['price'] ?? $item['last_price'], 'source_updated_at' => $item['updated_at'] ?? now()]);
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
