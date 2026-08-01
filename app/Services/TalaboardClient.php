<?php

namespace App\Services;

use App\Models\PriceSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $ttl = max(0, (int) config('services.talaboard.prices_cache_ttl', 5));

        if ($ttl === 0) {
            return $this->fetchAndStorePrices();
        }

        return Cache::remember('talaboard:prices:v1', $ttl, fn () => $this->fetchAndStorePrices());
    }

    private function fetchAndStorePrices(): Collection
    {
        $url = config('services.talaboard.url');
        $token = config('services.talaboard.token');
        $log = Log::channel(config('trading.log_channel', 'trading'));

        $log->debug('Live price request started.', [
            'base_url' => $url,
            'configured_path' => config('services.talaboard.prices_path'),
            'token_configured' => filled($token),
        ]);

        if (! $url) {
            $log->warning('Live price API URL is not configured; using stored snapshots.');
            return $this->latestSnapshots();
        }

        try {
            $items = $this->fetchPriceItems($url, $token);
        } catch (\Throwable $exception) {
            $log->warning('Unable to fetch live Talaboard prices; using the latest snapshots.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            // Keep the bot available during a temporary upstream outage.
            return $this->latestSnapshots();
        }

        $savedSymbols = [];
        foreach ($items as $key => $item) {
            if (! is_array($item)) continue;
            $symbol = is_string($key) ? $key : ($item['symbol'] ?? null);
            // نام‌های قدیمی API به دو قیمت عمومی طلا نگاشت می‌شوند.
            if ($symbol === 'gold_9999_gram' || $symbol === 'gold_995_gram') $symbol = 'gold_gram';
            if ($symbol === 'gold_9999_mesghal' || $symbol === 'gold_995_mesghal') $symbol = 'gold_mesghal';
            if (! $symbol || ! isset(self::PRODUCTS[$symbol])) continue;
            $price = $item['price'] ?? $item['last_price'] ?? null;
            if (! is_numeric($price)) continue;
            PriceSnapshot::create([
                'symbol' => $symbol,
                'title' => self::PRODUCTS[$symbol],
                // Metalsp publishes prices in toman, while every trading
                // amount in this application is stored in rial.
                'price' => (int) round((float) $price * 10),
                'source_updated_at' => $item['updated_at'] ?? now(),
            ]);
            $savedSymbols[] = $symbol;
        }

        $snapshots = $this->latestSnapshots();
        $log->info('Live prices processed.', [
            'received_items' => count($items),
            'saved_symbols' => array_values(array_unique($savedSymbols)),
            'available_snapshot_count' => $snapshots->count(),
        ]);

        return $snapshots;
    }

    private function latestSnapshots(): Collection
    {
        return PriceSnapshot::query()
            ->whereIn('id', PriceSnapshot::query()->selectRaw('MAX(id)')->groupBy('symbol'))
            ->get()
            ->keyBy('symbol');
    }

    private function fetchPriceItems(string $url, ?string $token): array
    {
        $configuredPath = config('services.talaboard.prices_path', '/api/prices');
        $attempts = [];

        if ($token) {
            $attempts[] = [$configuredPath, $token];
        } else {
            $attempts[] = [$configuredPath, null];
        }

        // Prices are public. Always retry without Authorization because an old or
        // invalid site token must not prevent the Telegram bot from showing them.
        if ($token || $configuredPath !== '/api/prices') {
            $attempts[] = ['/api/prices', null];
        }

        foreach ($attempts as [$path, $attemptToken]) {
            $request = Http::acceptJson()
                ->withOptions(['verify' => config('services.talaboard.verify_ssl', true)])
                ->timeout(10);

            if ($attemptToken) {
                $request = $request->withToken($attemptToken);
            }

            $endpoint = rtrim($url, '/').$path;
            Log::channel(config('trading.log_channel', 'trading'))->debug('Calling live price endpoint.', [
                'endpoint' => $endpoint,
                'authorization' => $attemptToken ? 'bearer' : 'none',
            ]);
            $response = $request->get($endpoint);
            Log::channel(config('trading.log_channel', 'trading'))->log(
                $response->successful() ? 'info' : 'warning',
                'Live price endpoint responded.',
                [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'content_type' => $response->header('Content-Type'),
                    'body_bytes' => strlen($response->body()),
                ]
            );
            if ($response->successful()) {
                $payload = $response->json();
                if (! is_array($payload)) {
                    throw new \UnexpectedValueException('Live price endpoint did not return a JSON object or array.');
                }

                return $this->normalizePrices($payload);
            }
        }

        $response->throw();
    }

    private function normalizePrices(array $payload): array
    {
        $items = $payload['prices'] ?? $payload;
        if (! is_array($items)) {
            return [];
        }

        if (isset($payload['gold']) || isset($payload['silver'])) {
            return [
                'gold_gram' => ['price' => $payload['gold']['geram'] ?? null, 'updated_at' => $payload['updated_at'] ?? null],
                'gold_mesghal' => ['price' => $payload['gold']['mithqal'] ?? null, 'updated_at' => $payload['updated_at'] ?? null],
                'silver_995_gram' => ['price' => $payload['silver']['gram_995'] ?? null, 'updated_at' => $payload['updated_at'] ?? null],
                'silver_995_mesghal' => ['price' => $payload['silver']['mithqal_995'] ?? null, 'updated_at' => $payload['updated_at'] ?? null],
                'silver_9999_gram' => ['price' => $payload['silver']['gram_999'] ?? null, 'updated_at' => $payload['updated_at'] ?? null],
                'silver_9999_mesghal' => ['price' => $payload['silver']['mithqal_999'] ?? null, 'updated_at' => $payload['updated_at'] ?? null],
                'full_coin' => ['price' => $payload['gold']['bahar'] ?? null, 'updated_at' => $payload['updated_at'] ?? null],
                'half_coin' => ['price' => $payload['gold']['nim'] ?? null, 'updated_at' => $payload['updated_at'] ?? null],
                'quarter_coin' => ['price' => $payload['gold']['rob'] ?? null, 'updated_at' => $payload['updated_at'] ?? null],
            ];
        }

        return $items;
    }

    public function registerTrade(array $payload): ?string
    {
        if (! config('services.talaboard.url')) return null;
        $response = Http::acceptJson()->withToken(config('services.talaboard.token'))
            ->withOptions(['verify' => config('services.talaboard.verify_ssl', true)])
            ->post(rtrim(config('services.talaboard.url'), '/').config('services.talaboard.trades_path'), $payload);
        $response->throw();
        return (string) ($response->json('reference') ?? $response->json('id') ?? '');
    }
}
