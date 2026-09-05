<?php

namespace App\Services\ShopeeFood\DTOs;

/**
 * A ShopeeFood order within a checkout (orders[]).
 *
 * Per API docs orders[0].order_id == checkout_id and order_sn is always empty,
 * so order identity is derived from checkout_id. Code must still iterate all
 * elements of orders[] rather than assume orders[0].
 */
class ShopeeFoodOrder
{
    public function __construct(
        private readonly string $checkoutId,
        private readonly ?string $orderSn,
        private readonly ?string $completedAt,
        private readonly ?string $fraudCompletedAt,
        private readonly array $items = [],
        private readonly array $raw = [],
    ) {}

    public function getCheckoutId(): string
    {
        return $this->checkoutId;
    }

    public function getOrderSn(): ?string
    {
        return $this->orderSn;
    }

    /**
     * Unix complete_time (Asia/Ho_Chi_Minh) or null when absent/never.
     */
    public function getCompletedAt(): ?string
    {
        return $this->completedAt;
    }

    /**
     * Unix fraud_complete_time (Asia/Ho_Chi_Minh) or null when absent/never.
     */
    public function getFraudCompletedAt(): ?string
    {
        return $this->fraudCompletedAt;
    }

    /**
     * @return ShopeeFoodOrderItem[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}
