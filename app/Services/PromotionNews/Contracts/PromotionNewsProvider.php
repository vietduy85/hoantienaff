<?php

namespace App\Services\PromotionNews\Contracts;

use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use App\Services\PromotionNews\Exceptions\PromotionNewsProviderException;

interface PromotionNewsProvider
{
    /**
     * Stable, lowercase identifier used in URLs and cache keys (e.g. "coop").
     */
    public function source(): string;

    /**
     * Human readable retailer label (e.g. "Co.op Online").
     */
    public function label(): string;

    /**
     * Default category applied to every entry coming from this provider.
     */
    public function category(): string;

    /**
     * Fetch and normalize the current promotion/news entries.
     *
     * Implementations MUST throw on a network/parse failure so the manager can
     * isolate the failure and fall back to the other providers. They MUST NOT
     * catch and silently return fabricated data.
     *
     * @return array<int, PromotionNewsData>
     *
     * @throws PromotionNewsProviderException
     */
    public function getNews(): array;
}
