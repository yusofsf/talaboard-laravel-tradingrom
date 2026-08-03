<?php

namespace App\Services;

use App\Models\PriceSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TalaboardClient
{
    private const PRICES_CACHE_KEY = 'talaboard:prices:v1';

    private const PRICES_CACHE_CREATED_KEY = 'illuminate:cache:flexible:created:'.self::PRICES_CACHE_KEY;

    private const PRICES_HOT_CACHE_KEY = 'talaboard:prices:hot:v1';

    private const PRICES_HOT_CACHE_CREATED_KEY = 'talaboard:prices:hot:created:v1';

    private const PRICES_HOT_REFRESH_LOCK = 'talaboard:prices:hot:refresh:v1';

    private ?Collection $resolvedPrices = null;

    private function logger(): mixed
    {
        $channel = (string) config('trading.log_channel', 'trading');

        try {
            if (! config("logging.channels.{$channel}")) {
                $fallback = (string) config('logging.default', 'stack');
                config(["logging.channels.{$channel}" => config("logging.channels.{$fallback}")]);
            }

            return Log::channel($channel);
        } catch (\Throwable) {
            return Log::getFacadeRoot();
        }
    }

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

        if ($this->resolvedPrices !== null) {
            return $this->resolvedPrices;
        }

        $staleTtl = max($ttl, (int) config('services.talaboard.prices_stale_ttl', 60));

        // This local L1 cache deliberately avoids the default cache store.
        // Production commonly uses a database-backed cache, and establishing
        // that connection after an idle period can be slower than the price
        // request itself. A previously fetched price can be returned from the
        // local filesystem immediately while it is refreshed after response.
        if ($hot = $this->hotPrices()) {
            if ($this->hotPricesAreStale($ttl)) {
                defer(fn () => $this->refreshHotPrices(), 'talaboard:prices:hot-refresh');
            }

            return $this->resolvedPrices = $hot;
        }

        try {
            $cached = Cache::many([
                self::PRICES_CACHE_KEY,
                self::PRICES_CACHE_CREATED_KEY,
            ]);

            if (in_array(null, $cached, true)) {
                $snapshots = $this->safeLatestSnapshots();

                if ($snapshots->isNotEmpty()) {
                    // Seed the flexible cache as stale. Laravel will return
                    // this durable value immediately and defer the live HTTP
                    // refresh until after the Telegram response is sent.
                    Cache::putMany([
                        self::PRICES_CACHE_KEY => $snapshots,
                        self::PRICES_CACHE_CREATED_KEY => now()->subSeconds($ttl + 1)->getTimestamp(),
                    ], $staleTtl);
                }
            }

            $prices = Cache::flexible(
                self::PRICES_CACHE_KEY,
                [$ttl, $staleTtl],
                fn () => $this->fetchAndStorePrices(),
            );
            $this->storeHotPrices(
                $prices,
                (int) (Cache::get(self::PRICES_CACHE_CREATED_KEY) ?: now()->getTimestamp()),
            );

            return $this->resolvedPrices = $prices;
        } catch (\Throwable $exception) {
            $this->logger()->warning('Live price cache is unavailable; using durable snapshots.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $snapshots = $this->safeLatestSnapshots();
            if ($snapshots->isNotEmpty()) {
                $this->storeHotPrices($snapshots, now()->subSeconds($ttl + 1)->getTimestamp());
                defer(fn () => $this->refresh(), 'talaboard:prices:cold-refresh');

                return $this->resolvedPrices = $snapshots;
            }

            return $this->resolvedPrices = $this->fetchAndStorePrices();
        }
    }

    /**
     * Refresh the shared price cache without making a Telegram request wait.
     */
    public function refresh(bool $storeSnapshots = true): Collection
    {
        $prices = $this->fetchAndStorePrices($storeSnapshots);
        $this->resolvedPrices = $prices;

        $staleTtl = max(
            1,
            (int) config('services.talaboard.prices_cache_ttl', 5),
            (int) config('services.talaboard.prices_stale_ttl', 60),
        );

        try {
            Cache::putMany([
                self::PRICES_CACHE_KEY => $prices,
                self::PRICES_CACHE_CREATED_KEY => now()->getTimestamp(),
            ], $staleTtl);
        } catch (\Throwable $exception) {
            $this->logger()->warning('Unable to update the shared live price cache.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        $this->storeHotPrices($prices, now()->getTimestamp());

        return $prices;
    }

    private function hotCache(): mixed
    {
        return Cache::store((string) config('services.talaboard.prices_hot_cache_store', 'file'));
    }

    private function hotPrices(): ?Collection
    {
        try {
            $prices = $this->hotCache()->get(self::PRICES_HOT_CACHE_KEY);

            return $prices instanceof Collection && $prices->isNotEmpty() ? $prices : null;
        } catch (\Throwable $exception) {
            $this->logger()->warning('Local live price cache is unavailable.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function hotPricesAreStale(int $ttl): bool
    {
        try {
            $createdAt = (int) $this->hotCache()->get(self::PRICES_HOT_CACHE_CREATED_KEY, 0);

            return $createdAt === 0 || $createdAt <= now()->subSeconds($ttl)->getTimestamp();
        } catch (\Throwable) {
            return true;
        }
    }

    private function storeHotPrices(Collection $prices, int $createdAt): void
    {
        if ($prices->isEmpty()) {
            return;
        }

        try {
            // Keep the last known good value indefinitely. Its timestamp, not
            // cache eviction, determines when an asynchronous refresh is due.
            $this->hotCache()->forever(self::PRICES_HOT_CACHE_KEY, $prices);
            $this->hotCache()->forever(self::PRICES_HOT_CACHE_CREATED_KEY, $createdAt);
        } catch (\Throwable $exception) {
            $this->logger()->warning('Unable to update the local live price cache.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function refreshHotPrices(): void
    {
        try {
            $this->hotCache()->lock(self::PRICES_HOT_REFRESH_LOCK, 30)->get(
                fn () => $this->refresh(),
            );
        } catch (\Throwable $exception) {
            $this->logger()->warning('Unable to refresh the local live price cache.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function fetchAndStorePrices(bool $storeSnapshots = true): Collection
    {
        $url = config('services.talaboard.url');
        $token = config('services.talaboard.token');
        $log = $this->logger();

        $log->debug('Live price request started.', [
            'base_url' => $url,
            'configured_path' => config('services.talaboard.prices_path'),
            'token_configured' => filled($token),
        ]);

        if (! $url) {
            $log->warning('Live price API URL is not configured; using stored snapshots.');

            return $this->safeLatestSnapshots();
        }

        try {
            $items = $this->fetchPriceItems($url, $token);
        } catch (\Throwable $exception) {
            $log->warning('Unable to fetch live Talaboard prices; using the latest snapshots.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            // Keep the bot available during a temporary upstream outage.
            return $this->safeLatestSnapshots();
        }

        $liveSnapshots = collect();
        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }
            $symbol = is_string($key) ? $key : ($item['symbol'] ?? null);
            // نام‌های قدیمی API به دو قیمت عمومی طلا نگاشت می‌شوند.
            if ($symbol === 'gold_9999_gram' || $symbol === 'gold_995_gram') {
                $symbol = 'gold_gram';
            }
            if ($symbol === 'gold_9999_mesghal' || $symbol === 'gold_995_mesghal') {
                $symbol = 'gold_mesghal';
            }
            if (! $symbol || ! isset(self::PRODUCTS[$symbol])) {
                continue;
            }
            $price = $item['price'] ?? $item['last_price'] ?? null;
            if (! is_numeric($price)) {
                continue;
            }
            $liveSnapshots->put($symbol, new PriceSnapshot([
                'symbol' => $symbol,
                'title' => self::PRODUCTS[$symbol],
                // Metalsp publishes prices in toman, while every trading
                // amount in this application is stored in rial.
                'price' => (int) round((float) $price * 10),
                'source_updated_at' => $item['updated_at'] ?? now(),
            ]));
        }

        $savedSymbols = [];
        if ($storeSnapshots) {
            try {
                foreach ($liveSnapshots as $symbol => $snapshot) {
                    $snapshot->save();
                    $savedSymbols[] = $symbol;
                }
            } catch (\Throwable $exception) {
                // Persistence is a fallback for outages and must never prevent a
                // valid upstream response from being shown to the user.
                $log->warning('Unable to store live price snapshots; using in-memory prices.', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        // Fill products omitted by the current response from stored snapshots,
        // while always preferring the just-fetched values.
        $snapshots = $this->safeLatestSnapshots()->toBase()->merge($liveSnapshots);
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

    private function safeLatestSnapshots(): Collection
    {
        try {
            return $this->latestSnapshots();
        } catch (\Throwable $exception) {
            $this->logger()->warning('Stored price snapshots are unavailable.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return collect();
        }
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
                ->connectTimeout((int) config('services.talaboard.prices_connect_timeout', 2))
                ->timeout((int) config('services.talaboard.prices_timeout', 4));

            if ($attemptToken) {
                $request = $request->withToken($attemptToken);
            }

            $endpoint = rtrim($url, '/').$path;
            $this->logger()->debug('Calling live price endpoint.', [
                'endpoint' => $endpoint,
                'authorization' => $attemptToken ? 'bearer' : 'none',
            ]);
            $response = $request->get($endpoint);
            $this->logger()->log(
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
        if (! config('services.talaboard.url')) {
            return null;
        }
        $response = Http::acceptJson()->withToken(config('services.talaboard.token'))
            ->withOptions(['verify' => config('services.talaboard.verify_ssl', true)])
            ->post(rtrim(config('services.talaboard.url'), '/').config('services.talaboard.trades_path'), $payload);
        $response->throw();

        return (string) ($response->json('reference') ?? $response->json('id') ?? '');
    }
}
