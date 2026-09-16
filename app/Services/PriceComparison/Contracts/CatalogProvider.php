<?php

namespace App\Services\PriceComparison\Contracts;

use App\Services\PriceComparison\DataTransfer\ProductSummary;
use App\Services\PriceComparison\DataTransfer\SearchResult;

interface CatalogProvider
{
    /**
     * Unique identifier of the retail source, e.g. "coop_online".
     */
    public function source(): string;

    /**
     * Search products by keyword.
     * Never throws for HTTP/response errors; returns an empty result on failure.
     */
    public function search(string $keyword, int $page = 1, int $perPage = 20): SearchResult;

    /**
     * Get a single product by SKU. Returns null when not found or on failure.
     */
    public function getProduct(string $sku): ?ProductSummary;
}