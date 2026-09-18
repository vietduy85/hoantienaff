<?php

namespace App\Services\PromotionNews;

use App\Models\PromotionNews;
use App\Services\PromotionNews\Contracts\PromotionNewsProvider;
use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aggregates live provider news and persisted manual news into a single,
 * cached, normalized collection.
 *
 * Failures are isolated per provider: one unreachable retailer never breaks
 * the others, and a failed fetch is never cached (so the next request retries)
 * nor replaced with fabricated data.
 */
class PromotionNewsManager
{
    private const AGGREGATE_TTL_SECONDS = 300;

    private const DEFAULT_PROVIDER_TTL_SECONDS = 1800;

    /**
     * @var array<string, int>
     */
    private const PROVIDER_TTL_SECONDS = [
        'coop' => 1800,
        'bhx' => 3600,
        'winmart' => 3600,
        'kingfoodmart' => 1800,
    ];

    /**
     * @var array<string, PromotionNewsProvider>
     */
    private array $providers = [];

    /**
     * Per-request memoization of the aggregate collection.
     *
     * @var array<int, PromotionNewsData>|null
     */
    private ?array $memo = null;

    /**
     * @param  iterable<PromotionNewsProvider>  $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(PromotionNewsProvider $provider): void
    {
        $this->providers[$provider->source()] = $provider;
    }

    /**
     * @return array<int, string>
     */
    public function sources(): array
    {
        return array_keys($this->providers);
    }

    public function provider(string $source): ?PromotionNewsProvider
    {
        return $this->providers[$source] ?? null;
    }

    /**
     * All currently active news (auto + manual), sorted for display.
     *
     * @return array<int, PromotionNewsData>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = Cache::get($this->aggregateCacheKey());

        if (is_array($cached)) {
            return $this->memo = array_map(
                static fn (array $item) => PromotionNewsData::fromArray($item),
                $cached
            );
        }

        $merged = [];

        foreach ($this->providers as $source => $provider) {
            foreach ($this->fetchFromProvider($source, $provider) as $item) {
                $merged[] = $item;
            }
        }

        foreach ($this->manualNews() as $item) {
            $merged[] = $item;
        }

        $merged = array_values(array_filter(
            $merged,
            static fn (PromotionNewsData $item) => $item->isCurrentlyActive()
        ));

        usort($merged, [$this, 'compare']);

        Cache::put(
            $this->aggregateCacheKey(),
            array_map(static fn (PromotionNewsData $item) => $item->toArray(), $merged),
            self::AGGREGATE_TTL_SECONDS
        );

        return $this->memo = $merged;
    }

    /**
     * At most one card per source, ordered by the preferred retailer order.
     *
     * @return array<int, PromotionNewsData>
     */
    public function representativeNews(int $limit = 4): array
    {
        $bySource = [];

        foreach ($this->all() as $item) {
            if (! isset($bySource[$item->source])) {
                $bySource[$item->source] = $item;
            }
        }

        $preferred = array_flip(PromotionNewsSources::order());

        uksort($bySource, static function (string $a, string $b) use ($preferred): int {
            $orderA = $preferred[$a] ?? PHP_INT_MAX;
            $orderB = $preferred[$b] ?? PHP_INT_MAX;

            return [$orderA, $a] <=> [$orderB, $b];
        });

        return array_slice(array_values($bySource), 0, max(0, $limit));
    }

    /**
     * @return array<int, PromotionNewsData>
     */
    public function forSource(string $source): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (PromotionNewsData $item) => $item->source === $source
        ));
    }

    /**
     * @return array<int, string>
     */
    public function sourcesWithNews(): array
    {
        $sources = [];

        foreach ($this->all() as $item) {
            $sources[$item->source] = true;
        }

        return array_keys($sources);
    }

    /**
     * @return array<int, string>
     */
    public function categoriesWithNews(): array
    {
        $categories = [];

        foreach ($this->all() as $item) {
            $categories[$item->category] = true;
        }

        return array_keys($categories);
    }

    public function flush(): void
    {
        $this->memo = null;

        Cache::forget($this->aggregateCacheKey());

        foreach (array_keys($this->providers) as $source) {
            Cache::forget($this->providerCacheKey($source));
        }
    }

    /**
     * @return array<int, PromotionNewsData>
     */
    private function fetchFromProvider(string $source, PromotionNewsProvider $provider): array
    {
        $cacheKey = $this->providerCacheKey($source);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return array_map(static fn (array $item) => PromotionNewsData::fromArray($item), $cached);
        }

        try {
            $items = $provider->getNews();
        } catch (Throwable $e) {
            Log::warning('PromotionNews provider failed', [
                'source' => $source,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $items = array_values(array_filter(
            $items,
            static fn ($item) => $item instanceof PromotionNewsData
        ));

        Cache::put(
            $cacheKey,
            array_map(static fn (PromotionNewsData $item) => $item->toArray(), $items),
            self::PROVIDER_TTL_SECONDS[$source] ?? self::DEFAULT_PROVIDER_TTL_SECONDS
        );

        return $items;
    }

    /**
     * @return array<int, PromotionNewsData>
     */
    private function manualNews(): array
    {
        return PromotionNews::query()
            ->current()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (PromotionNews $model) => PromotionNewsData::fromModel($model))
            ->all();
    }

    private function compare(PromotionNewsData $a, PromotionNewsData $b): int
    {
        return [$a->sortOrder, $a->source]
            <=> [$b->sortOrder, $b->source];
    }

    private function providerCacheKey(string $source): string
    {
        return "promotion-news:provider:{$source}:v1";
    }

    private function aggregateCacheKey(): string
    {
        return 'promotion-news:aggregate:v1';
    }
}
