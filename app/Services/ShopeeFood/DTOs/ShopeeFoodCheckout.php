<?php

namespace App\Services\ShopeeFood\DTOs;

/**
 * A ShopeeFood checkout (one element of data.list[]).
 *
 * Carries checkout-level fields (conversion_status, cap/capped commission, net
 * commission, utm_content and its parsed sub_ids / content_id) plus nested
 * orders[]. Each order contains items[].
 *
 * UTM fields are pre-parsed here so a Phase 3 sync can read sub_id1..5 and
 * content_id directly without re-parsing utm_content. The raw utm_content is
 * still preserved on `utmContent`.
 *
 * All monetary values are already normalised to VND (raw / 100000).
 */
class ShopeeFoodCheckout
{
    /**
     * @param  ShopeeFoodOrder[]  $orders
     */
    public function __construct(
        private readonly string $checkoutId,
        private readonly ?int $conversionStatus,
        private readonly ?float $checkoutCap,
        private readonly bool $isShopeeCapped,
        private readonly ?float $cappedCommission,
        private readonly ?float $affiliateNetCommission,
        private readonly ?string $utmContent,
        private readonly ?string $utmFormat,
        private readonly string $subId1,
        private readonly string $subId2,
        private readonly string $subId3,
        private readonly string $subId4,
        private readonly string $subId5,
        private readonly ?string $contentId,
        private readonly ?string $clickedAt,
        private readonly ?string $purchasedAt,
        private readonly ?string $completedAt,
        private readonly array $orders = [],
        private readonly array $raw = [],
    ) {}

    public function getCheckoutId(): string
    {
        return $this->checkoutId;
    }

    public function getConversionStatus(): ?int
    {
        return $this->conversionStatus;
    }

    public function getCheckoutCap(): ?float
    {
        return $this->checkoutCap;
    }

    public function isShopeeCapped(): bool
    {
        return $this->isShopeeCapped;
    }

    public function getCappedCommission(): ?float
    {
        return $this->cappedCommission;
    }

    public function getAffiliateNetCommission(): ?float
    {
        return $this->affiliateNetCommission;
    }

    /**
     * Raw utm_content preserved verbatim (may be null).
     */
    public function getUtmContent(): ?string
    {
        return $this->utmContent;
    }

    /**
     * 'A', 'B' or null (when utm_content is absent/empty).
     */
    public function getUtmFormat(): ?string
    {
        return $this->utmFormat;
    }

    public function getSubId1(): string
    {
        return $this->subId1;
    }

    public function getSubId2(): string
    {
        return $this->subId2;
    }

    public function getSubId3(): string
    {
        return $this->subId3;
    }

    public function getSubId4(): string
    {
        return $this->subId4;
    }

    public function getSubId5(): string
    {
        return $this->subId5;
    }

    /**
     * content_id as STRING (never cast to int), or null when absent / FORMAT A.
     */
    public function getContentId(): ?string
    {
        return $this->contentId;
    }

    /**
     * Unix click_time (Asia/Ho_Chi_Minh) or null when absent/never.
     */
    public function getClickedAt(): ?string
    {
        return $this->clickedAt;
    }

    /**
     * Unix purchase_time (Asia/Ho_Chi_Minh) or null when absent/never.
     */
    public function getPurchasedAt(): ?string
    {
        return $this->purchasedAt;
    }

    /**
     * Unix checkout_complete_time (Asia/Ho_Chi_Minh) or null when absent/never.
     */
    public function getCompletedAt(): ?string
    {
        return $this->completedAt;
    }

    /**
     * @return ShopeeFoodOrder[]
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}
