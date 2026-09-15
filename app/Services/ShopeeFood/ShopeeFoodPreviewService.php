<?php

namespace App\Services\ShopeeFood;

use App\Models\LinkRequest;
use Illuminate\Support\Facades\Log;

final class ShopeeFoodPreviewService
{
    public const FALLBACK_STORE_NAME = 'ShopeeFood';

    public function __construct(
        private readonly ShopeeFoodStoreService $storeService,
        private readonly ShopeeFoodAffiliateResolver $affiliateResolver,
        private readonly ShopeeFoodOpenGraphService $openGraphService,
    ) {}

    public function preview(LinkRequest $link, string $originalUrl, ?string $restaurantId = null): void
    {
        $openGraph = $this->openGraphService->resolveNameAndImage(
            $this->openGraphUrl($originalUrl, $restaurantId),
        );

        $storeName = $restaurantId !== null
            ? $this->storeService->storeName($restaurantId)
            : null;

        if ($storeName === null) {
            $parsedId = ShopeeFoodUrlParser::restaurantId($originalUrl);
            $storeName = $parsedId !== null ? $this->storeService->storeName($parsedId) : null;
        }

        $name = null;
        $dataSource = 'shopeefood-fallback';

        $hasOgName = is_array($openGraph) && $this->isPresent($openGraph['name'] ?? null);

        if ($hasOgName) {
            $name = $openGraph['name'];
            $dataSource = 'shopeefood-open-graph';
        } elseif ($this->isPresent($storeName)) {
            $name = $storeName;
            $dataSource = 'shopeefood-store-api';
        }

        $link->update([
            'product_image' => is_array($openGraph) && $this->isPresent($openGraph['image'] ?? null)
                ? $openGraph['image']
                : asset(ShopeeFoodStoreService::DEFAULT_PLATFORM_IMAGE),
            'product_name'  => $name !== null ? $name : self::FALLBACK_STORE_NAME,
            'data_source'   => $dataSource,
        ]);
    }

    public function enrichFromAffiliateUrl(LinkRequest $link, ?string $affiliateUrl): void
    {
        if (strtolower((string) $link->platform) !== 'shopeefood') {
            return;
        }

        $openGraph = $this->openGraphService->resolveNameAndImage((string) $link->original_url);

        if (is_array($openGraph) && ($this->isPresent($openGraph['name'] ?? null) || $this->isPresent($openGraph['image'] ?? null))) {
            $hasName = $this->isPresent($openGraph['name'] ?? null);

            $link->update([
                'product_image' => $this->isPresent($openGraph['image'] ?? null)
                    ? $openGraph['image']
                    : asset(ShopeeFoodStoreService::DEFAULT_PLATFORM_IMAGE),
                'product_name'  => $hasName ? $openGraph['name'] : self::FALLBACK_STORE_NAME,
                'data_source'   => $hasName ? 'shopeefood-open-graph' : 'shopeefood-fallback',
            ]);

            return;
        }

        $restaurantId = $this->affiliateResolver->resolveRestaurantId($affiliateUrl);

        if ($restaurantId === null) {
            Log::warning('[ShopeeFoodPreview] Affiliate enrichment skipped', [
                'link_request_id' => $link->id,
                'affiliate_url'   => $affiliateUrl,
                'reason'          => 'resolve-failed',
            ]);

            return;
        }

        $name = $this->storeService->storeName($restaurantId);

        if ($name === null || trim($name) === '') {
            Log::warning('[ShopeeFoodPreview] Affiliate enrichment skipped', [
                'link_request_id' => $link->id,
                'affiliate_url'   => $affiliateUrl,
                'restaurant_id'   => $restaurantId,
                'reason'          => 'store-name-unavailable',
            ]);

            return;
        }

        $link->update([
            'product_image' => asset(ShopeeFoodStoreService::DEFAULT_PLATFORM_IMAGE),
            'product_name'  => $name,
            'data_source'   => 'shopeefood-store-api',
        ]);
    }

    private function openGraphUrl(string $originalUrl, ?string $restaurantId): ?string
    {
        // Short /u/{code} links render with Open Graph tags → fetch the raw URL.
        if ($this->openGraphService->isShortUrl($originalUrl)) {
            return $originalUrl;
        }

        // Everything else: fetch the clean restaurant page so the metadata is
        // taken from the FINAL page, not from a raw URL that carries tracking.
        if ($restaurantId !== null) {
            return 'https://shopeefood.vn/now-food/shop/' . $restaurantId;
        }

        return $originalUrl;
    }

    private function isPresent(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}