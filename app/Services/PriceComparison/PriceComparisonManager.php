<?php

namespace App\Services\PriceComparison;

use App\Services\PriceComparison\Contracts\CatalogProvider;
use App\Services\PriceComparison\DataTransfer\AggregatedSearchResult;
use App\Services\PriceComparison\DataTransfer\ProductSummary;
use App\Services\PriceComparison\DataTransfer\SearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Registry of catalog providers keyed by their source identifier.
 * Register providers via the "catalog-providers" container tag.
 */
final class PriceComparisonManager
{
    /**
     * Page size requested from each provider when aggregating.
     */
    private const SOURCE_PAGE_SIZE = 50;

    /**
     * Upper bound on provider pages fetched per source while aggregating.
     */
    private const MAX_PAGES_PER_SOURCE = 4;

    /**
     * Upper bound on items collected per source while aggregating.
     */
    private const MAX_ITEMS_PER_SOURCE = 200;

    /**
     * TTL for the cached aggregated counts used to render retailer tab totals.
     */
    private const COUNTS_CACHE_TTL = 300;

    /**
     * @var array<string, CatalogProvider>
     */
    private array $providers = [];

    /**
     * @param  iterable<CatalogProvider>  $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(CatalogProvider $provider): void
    {
        $this->providers[$provider->source()] = $provider;
    }

    public function forSource(string $source): CatalogProvider
    {
        return $this->providers[$source]
            ?? throw new InvalidArgumentException("Unsupported catalog source: {$source}");
    }

    /**
     * @return array<string, CatalogProvider>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Search a single registered provider.
     */
    public function searchSource(string $source, string $keyword, int $page = 1, int $perPage = 20): SearchResult
    {
        return $this->forSource($source)->search($keyword, $page, $perPage);
    }

    /**
     * Merge listings from every registered provider.
     *
     * This is aggregation only: items coming from different providers are never
     * deduplicated, matched or otherwise combined. Failures of a single provider
     * are isolated and never take down the other providers.
     *
     * @param  array<int, string>|null  $sources  optional subset of source identifiers
     * @param  bool  $promotionsOnly  restrict the merged results to products that carry a promotion
     */
    public function searchAll(
        string $keyword,
        int $page = 1,
        int $perPage = 20,
        string $sort = 'relevance',
        ?array $sources = null,
        bool $promotionsOnly = false,
    ): AggregatedSearchResult {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $providers = $this->all();

        if ($sources !== null) {
            $providers = array_intersect_key($providers, array_flip($sources));
        }

        $sourceItems = [];
        $sourceTotals = [];
        $sourceItemCounts = [];
        $isCapped = false;

        foreach ($providers as $source => $provider) {
            try {
                $collected = $this->collectSource($provider, $keyword);
            } catch (Throwable $e) {
                Log::warning('PriceComparison: aggregate search provider failed', [
                    'provider' => $source,
                    'keyword' => $keyword,
                    'message' => $e->getMessage(),
                ]);

                $collected = ['items' => [], 'total' => 0, 'capped' => false];
            }

            $sourceItems[$source] = $collected['items'];
            $sourceTotals[$source] = $collected['total'];
            $sourceItemCounts[$source] = count($collected['items']);
            $isCapped = $isCapped || $collected['capped'];
        }

        $merged = $this->mergeItems($sourceItems, $sort);

        if ($promotionsOnly) {
            $merged = array_values(array_filter(
                $merged,
                fn (ProductSummary $product): bool => $product->hasPromotion(),
            ));
        }

        $promotionsCount = count(array_filter(
            $merged,
            fn (ProductSummary $product): bool => $product->hasPromotion(),
        ));

        $total = count($merged);
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        $offset = ($page - 1) * $perPage;

        if (! $promotionsOnly) {
            Cache::put($this->countsCacheKey($keyword), [
                'total' => $total,
                'sources' => $sourceTotals,
                'promotions' => $promotionsCount,
            ], self::COUNTS_CACHE_TTL);
        }

        return new AggregatedSearchResult(
            items: new Collection(array_slice($merged, $offset, $perPage)),
            page: $page,
            perPage: $perPage,
            total: $total,
            totalPages: $totalPages,
            sourceTotals: $sourceTotals,
            sourceItemCounts: $sourceItemCounts,
            isCapped: $isCapped,
            promotionsCount: $promotionsCount,
        );
    }

    /**
     * Cached aggregated totals from the last "all retailers" search for the
     * given keyword. Lets retailer tab counts survive switching tabs without
     * re-querying every provider. `promotions` is the number of products in
     * the fetched dataset that carry a promotion.
     *
     * @return array{total: int, sources: array<string, int>, promotions: int}|null
     */
    public function aggregatedCounts(string $keyword): ?array
    {
        $counts = Cache::get($this->countsCacheKey($keyword));

        if (! is_array($counts)) {
            return null;
        }

        return [
            'total' => (int) ($counts['total'] ?? 0),
            'sources' => array_map(
                'intval',
                (array) ($counts['sources'] ?? []),
            ),
            'promotions' => (int) ($counts['promotions'] ?? 0),
        ];
    }

    private function countsCacheKey(string $keyword): string
    {
        return 'price-comparison:counts:'.md5(mb_strtolower(trim($keyword)));
    }

    /**
     * Fetch up to MAX_ITEMS_PER_SOURCE items from a single provider.
     *
     * @return array{items: array<int, ProductSummary>, total: int, capped: bool}
     */
    private function collectSource(CatalogProvider $provider, string $keyword): array
    {
        $items = [];
        $total = 0;
        $capped = false;

        for ($page = 1; $page <= self::MAX_PAGES_PER_SOURCE; $page++) {
            $result = $provider->search($keyword, $page, self::SOURCE_PAGE_SIZE);

            if ($page === 1) {
                $total = max(0, $result->total);
            }

            $batch = $result->items->all();

            if ($batch === []) {
                break;
            }

            foreach ($batch as $item) {
                $items[] = $item;

                if (count($items) >= self::MAX_ITEMS_PER_SOURCE) {
                    $capped = true;
                    break 2;
                }
            }

            if ($total > 0 && count($items) >= $total) {
                break;
            }

            if (count($batch) < self::SOURCE_PAGE_SIZE) {
                break;
            }
        }

        return ['items' => $items, 'total' => $total, 'capped' => $capped];
    }

    /**
     * @param  array<string, array<int, ProductSummary>>  $sourceItems
     * @return array<int, ProductSummary>
     */
    private function mergeItems(array $sourceItems, string $sort): array
    {
        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $flat = [];

            foreach ($sourceItems as $items) {
                foreach ($items as $item) {
                    $flat[] = $item;
                }
            }

            usort($flat, $sort === 'price_asc'
                ? fn (ProductSummary $a, ProductSummary $b) => $this->comparePrice($a, $b)
                : fn (ProductSummary $a, ProductSummary $b) => $this->comparePriceDesc($a, $b));

            return $flat;
        }

        // Relevance: round-robin across providers so no source can dominate.
        $queues = array_map(fn (array $items) => array_values($items), $sourceItems);
        $merged = [];

        while (true) {
            $added = false;

            foreach ($queues as $source => $items) {
                if ($items === []) {
                    continue;
                }

                $merged[] = array_shift($queues[$source]);
                $added = true;
            }

            if (! $added) {
                break;
            }
        }

        return $merged;
    }

    private function comparePrice(ProductSummary $a, ProductSummary $b): int
    {
        $priceA = $a->price;
        $priceB = $b->price;

        if ($priceA === null && $priceB === null) {
            return 0;
        }

        if ($priceA === null) {
            return 1;
        }

        if ($priceB === null) {
            return -1;
        }

        return $priceA <=> $priceB;
    }

    private function comparePriceDesc(ProductSummary $a, ProductSummary $b): int
    {
        $priceA = $a->price;
        $priceB = $b->price;

        if ($priceA === null && $priceB === null) {
            return 0;
        }

        if ($priceA === null) {
            return 1;
        }

        if ($priceB === null) {
            return -1;
        }

        return $priceB <=> $priceA;
    }
}
