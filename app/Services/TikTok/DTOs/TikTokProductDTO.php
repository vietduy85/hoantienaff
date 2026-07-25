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
        private readonly ?float $commissionRate = null,
        private readonly ?string $productUrl = null,
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

    public function getCommissionRate(): ?float
    {
        return $this->commissionRate;
    }

    public function getProductUrl(): ?string
    {
        return $this->productUrl;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public static function fromRioHubResponse(array $data): static
    {
        return new static(
            productId: (string) ($data['product_id'] ?? $data['id'] ?? ''),
            name: $data['product_name'] ?? $data['name'] ?? null,
            imageUrl: $data['image_url'] ?? $data['image'] ?? null,
            price: isset($data['price']) ? (float) $data['price'] : null,
            currency: $data['currency'] ?? null,
            commissionRate: isset($data['commission_rate']) ? (float) $data['commission_rate'] : null,
            productUrl: $data['product_url'] ?? $data['url'] ?? null,
            raw: $data,
        );
    }
}
