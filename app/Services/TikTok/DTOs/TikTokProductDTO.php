<?php

namespace App\Services\TikTok\DTOs;

class TikTokProductDTO
{
    public function __construct(
        private readonly string $productId,
        private readonly ?string $name = null,
        private readonly ?string $imageUrl = null,
        private readonly ?float $price = null,
        private readonly ?string $currency = null,
        private readonly ?float $commissionRatePct = null,
        private readonly ?float $shopAdsCommissionRatePct = null,
        private readonly ?float $observedCommissionRatePct = null,
        private readonly ?string $shopName = null,
        private readonly array $raw = [],
    ) {}

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getShopName(): ?string
    {
        return $this->shopName;
    }

    /**
     * Standard commission rate (%), from commission.rate.
     */
    public function getCommissionRatePct(): ?float
    {
        return $this->commissionRatePct;
    }

    /**
     * Shop ads commission rate (%), from shop_ads_commission.rate.
     */
    public function getShopAdsCommissionRatePct(): ?float
    {
        return $this->shopAdsCommissionRatePct;
    }

    /**
     * Observed total commission rate (%) from the creator's most recent
     * order, from observed_commission.commission_rate. Includes bonus.
     */
    public function getObservedCommissionRatePct(): ?float
    {
        return $this->observedCommissionRatePct;
    }

    /**
     * Effective total commission rate (%) used for the cashback estimate.
     *
     * Priority per RioHub spec:
     *   1. observed_commission.commission_rate (total, includes bonus)
     *   2. commission.rate + shop_ads_commission.rate
     *
     * @return float|null null when no usable rate is available.
     */
    public function getEffectiveCommissionRatePct(): ?float
    {
        if ($this->observedCommissionRatePct !== null && $this->observedCommissionRatePct > 0) {
            return $this->observedCommissionRatePct;
        }

        $base = (float) ($this->commissionRatePct ?? 0);
        $ads = (float) ($this->shopAdsCommissionRatePct ?? 0);

        if ($base + $ads > 0) {
            return $base + $ads;
        }

        return null;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * Build from a RioHub response body (top-level).
     *
     * The `/products` endpoint returns `{ ..., products: [ {...}, ... ] }`
     * and `/product-links` returns `{ ..., product: {...} }`. This extractor
     * accepts either shape and keeps hidden nullable fields intact.
     *
     * @param  array  $data  The top-level API response body.
     */
    public static function fromRioHubResponse(array $data): static
    {
        $product = $data['products'][0] ?? $data['product'] ?? null;

        if (!is_array($product)) {
            $product = [];
        }

        $price = self::extractPrice($product);
        $currency = $product['commission']['currency']
            ?? $product['original_price']['currency']
            ?? $product['sales_price']['currency']
            ?? null;

        return new static(
            productId: (string) ($product['id'] ?? $data['product_id'] ?? $data['id'] ?? ''),
            name: trim((string) ($product['title'] ?? $product['name'] ?? $product['product_name'] ?? '')),
            imageUrl: $product['main_image_url'] ?? $product['image_url'] ?? $product['image'] ?? null,
            price: $price,
            currency: $currency,
            commissionRatePct: isset($product['commission']['rate']) ? (float) $product['commission']['rate'] : null,
            shopAdsCommissionRatePct: isset($product['shop_ads_commission']['rate']) ? (float) $product['shop_ads_commission']['rate'] : null,
            observedCommissionRatePct: isset($product['observed_commission']['commission_rate']) ? (float) $product['observed_commission']['commission_rate'] : null,
            shopName: $product['shop']['name'] ?? null,
            raw: $product,
        );
    }

    /**
     * Pick the display/estimate price: sales_price.minimum falls back to
     * original_price.minimum, then to a flat price field.
     */
    private static function extractPrice(array $product): ?float
    {
        foreach (['sales_price', 'original_price'] as $key) {
            $min = $product[$key]['minimum_amount'] ?? null;
            if (is_numeric($min) && (float) $min > 0) {
                return (float) $min;
            }
        }

        if (isset($product['price']) && is_numeric($product['price'])) {
            return (float) $product['price'];
        }

        return null;
    }
}
