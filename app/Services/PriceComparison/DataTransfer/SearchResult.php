<?php

namespace App\Services\PriceComparison\DataTransfer;

use Illuminate\Support\Collection;

/**
 * Normalized search result shared across catalog providers.
 */
final class SearchResult
{
    /**
     * @param  Collection<int, ProductSummary>  $items
     */
    public function __construct(
        public readonly Collection $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pagination' => [
                'page'        => $this->page,
                'per_page'    => $this->perPage,
                'total'       => $this->total,
                'total_pages' => $this->totalPages,
            ],
            'products' => $this->items
                ->map(fn (ProductSummary $product) => $product->toArray())
                ->values()
                ->all(),
        ];
    }
}