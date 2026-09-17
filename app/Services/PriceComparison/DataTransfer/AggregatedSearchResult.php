<?php

namespace App\Services\PriceComparison\DataTransfer;

use Illuminate\Support\Collection;

/**
 * Aggregated search result that merges listings from every catalog provider.
 *
 * Listings are NOT deduplicated or matched across providers: the same physical
 * product may appear once per provider. `total` reflects how many merged items
 * are actually available for paging, while `sourceTotals` reports how many
 * items each provider claims to have.
 */
final class AggregatedSearchResult
{
    /**
     * @param  Collection<int, ProductSummary>  $items
     * @param  array<string, int>  $sourceTotals  provider-reported total per source
     * @param  array<string, int>  $sourceItemCounts  fetched item count per source
     */
    public function __construct(
        public readonly Collection $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages,
        public readonly array $sourceTotals = [],
        public readonly array $sourceItemCounts = [],
        public readonly bool $isCapped = false,
        public readonly int $promotionsCount = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => 'all',
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'total_pages' => $this->totalPages,
            ],
            'sources' => $this->sourcesArray(),
            'products' => $this->items
                ->map(fn (ProductSummary $product) => $product->toArray())
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourcesArray(): array
    {
        $sources = [];

        foreach ($this->sourceTotals as $source => $total) {
            $sources[] = [
                'source' => $source,
                'total' => $total,
                'fetched' => $this->sourceItemCounts[$source] ?? 0,
            ];
        }

        return $sources;
    }
}
