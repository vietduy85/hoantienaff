<?php

namespace App\Services\Lazada\DTOs;

class LazadaProductLinkDTO
{
    public function __construct(
        private readonly ?string $productId,
        private readonly ?string $productName,
        private readonly ?string $productImage,
        private readonly ?float $productPrice,
        private readonly ?string $currency,
        private readonly ?float $commissionRatePct,
        private readonly ?float $commissionAmount,
        private readonly string $affiliateUrl,
    ) {}

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function getProductImage(): ?string
    {
        return $this->productImage;
    }

    public function getProductPrice(): ?float
    {
        return $this->productPrice;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getCommissionRatePct(): ?float
    {
        return $this->commissionRatePct;
    }

    public function getCommissionAmount(): ?float
    {
        return $this->commissionAmount;
    }

    public function getAffiliateUrl(): string
    {
        return $this->affiliateUrl;
    }
}