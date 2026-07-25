<?php

namespace App\Services\TikTok\DTOs;

class TikTokAffiliateLinkDTO
{
    public function __construct(
        private readonly string $affiliateUrl,
        private readonly ?string $productId = null,
        private readonly ?string $productName = null,
        private readonly ?string $originalUrl = null,
        private readonly array $raw = [],
    ) {}

    public function getAffiliateUrl(): string
    {
        return $this->affiliateUrl;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function getOriginalUrl(): ?string
    {
        return $this->originalUrl;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public static function fromRioHubResponse(array $data, ?string $originalUrl = null): static
    {
        return new static(
            affiliateUrl: $data['affiliate_url'] ?? $data['url'] ?? '',
            productId: $data['product_id'] ?? $data['id'] ?? null,
            productName: $data['product_name'] ?? $data['name'] ?? null,
            originalUrl: $originalUrl,
            raw: $data,
        );
    }
}
