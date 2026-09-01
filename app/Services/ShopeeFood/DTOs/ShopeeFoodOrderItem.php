<?php

namespace App\Services\ShopeeFood\DTOs;

/**
 * A single ShopeeFood line item (a row of a dish, possibly with size/topping).
 *
 * Business key is (checkout_id, promotion_id); item_id is NOT a valid key here
 * because it may repeat within one checkout for different variants.
 *
 * All monetary values in this DTO are already normalised to VND (raw / 100000)
 * and rates are already normalised to percent (raw / 100000).
 */
class ShopeeFoodOrderItem
{
    public function __construct(
        private readonly string $checkoutId,
        private readonly ?string $promotionId,
        private readonly ?string $itemId,
        private readonly ?string $itemName,
        private readonly ?string $shopName,
        private readonly ?string $shopId,
        private readonly ?float $itemPrice,
        private readonly ?int $quantity,
        private readonly ?float $actualAmount,
        private readonly ?float $refundedAmount,
        private readonly ?float $platformCommissionRate,
        private readonly ?float $itemCommission,
        private readonly ?int $affiliateItemStatus,
        private readonly ?string $displayItemStatus,
        private readonly ?string $settledAt,
        private readonly ?string $paidAt,
        private readonly array $raw = [],
    ) {}

    public function getCheckoutId(): string
    {
        return $this->checkoutId;
    }

    public function getPromotionId(): ?string
    {
        return $this->promotionId;
    }

    public function getItemId(): ?string
    {
        return $this->itemId;
    }

    public function getItemName(): ?string
    {
        return $this->itemName;
    }

    public function getShopName(): ?string
    {
        return $this->shopName;
    }

    public function getShopId(): ?string
    {
        return $this->shopId;
    }

    public function getItemPrice(): ?float
    {
        return $this->itemPrice;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function getActualAmount(): ?float
    {
        return $this->actualAmount;
    }

    public function getRefundedAmount(): ?float
    {
        return $this->refundedAmount;
    }

    public function getPlatformCommissionRate(): ?float
    {
        return $this->platformCommissionRate;
    }

    public function getItemCommission(): ?float
    {
        return $this->itemCommission;
    }

    public function getAffiliateItemStatus(): ?int
    {
        return $this->affiliateItemStatus;
    }

    public function getDisplayItemStatus(): ?string
    {
        return $this->displayItemStatus;
    }

    public function getSettledAt(): ?string
    {
        return $this->settledAt;
    }

    public function getPaidAt(): ?string
    {
        return $this->paidAt;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * Composite ShopeeFood line key = "checkoutId:promotionId".
     */
    public function getLineKey(): string
    {
        return $this->checkoutId . ':' . (string) ($this->promotionId ?? '');
    }
}
