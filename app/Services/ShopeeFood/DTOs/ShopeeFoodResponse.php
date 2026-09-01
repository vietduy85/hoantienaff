<?php

namespace App\Services\ShopeeFood\DTOs;

/**
 * Wraps one page of the ShopeeFood orders API response plus pagination metadata.
 *
 * The API exposes data.total_count and data.list[]; page_size is capped at 100,
 * so a sync loop must iterate pages until it has covered total_count.
 */
class ShopeeFoodResponse
{
    /**
     * @param  ShopeeFoodCheckout[]  $checkouts
     */
    public function __construct(
        private readonly int $totalCount,
        private readonly int $page,
        private readonly int $pageSize,
        private readonly array $checkouts,
    ) {}

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * @return ShopeeFoodCheckout[]
     */
    public function getCheckouts(): array
    {
        return $this->checkouts;
    }

    public function hasMore(): bool
    {
        return $this->page * $this->pageSize < $this->totalCount;
    }
}
