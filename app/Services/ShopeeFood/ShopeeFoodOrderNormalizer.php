<?php

namespace App\Services\ShopeeFood;

use App\Services\ShopeeFood\DTOs\ShopeeFoodCheckout;
use App\Services\ShopeeFood\DTOs\ShopeeFoodOrder;
use App\Services\ShopeeFood\DTOs\ShopeeFoodOrderItem;
use Illuminate\Support\Carbon;

/**
 * Normalises raw ShopeeFood API payloads into the ShopeeFood DTO hierarchy.
 *
 * All unit-sensitive normalisation is centralised HERE:
 *  - money  : raw / 100000  -> VND
 *  - rate   : raw / 1000    -> percent (9000 -> 9%)
 *  - time   : unix seconds (0 == "never") -> Asia/Ho_Chi_Minh datetime || null
 *  - status : conversion_status & affiliate_item_status kept as normalised ints
 *  - utm    : utm_content parsed into sub_id1..5 / content_id (FORMAT A / B)
 *
 * Cashback is intentionally OUT of scope for this phase.
 */
class ShopeeFoodOrderNormalizer
{
    private const TIMEZONE = 'Asia/Ho_Chi_Minh';

    /**
     * Convert a list of raw checkout arrays (data.list[]) into ShopeeFoodCheckout[].
     *
     * @param  array<int, array>  $list
     * @return ShopeeFoodCheckout[]
     */
    public function normalizeCheckouts(array $list): array
    {
        $checkouts = [];

        foreach ($list as $rawCheckout) {
            if (! is_array($rawCheckout)) {
                continue;
            }

            $checkouts[] = $this->normalizeCheckout($rawCheckout);
        }

        return $checkouts;
    }

    public function normalizeCheckout(array $raw): ShopeeFoodCheckout
    {
        $checkoutId = (string) ($raw['checkout_id'] ?? '');
        $utmContent = isset($raw['utm_content']) ? (string) $raw['utm_content'] : null;
        $utm = ShopeeFoodUtmContentParser::parse($utmContent);

        $orders = [];
        foreach ((array) ($raw['orders'] ?? []) as $rawOrder) {
            if (! is_array($rawOrder)) {
                continue;
            }

            $items = [];
            foreach ((array) ($rawOrder['items'] ?? []) as $rawItem) {
                if (is_array($rawItem)) {
                    $items[] = $this->normalizeOrderItem($checkoutId, $rawItem);
                }
            }

            $orders[] = new ShopeeFoodOrder(
                checkoutId: $checkoutId,
                orderSn: isset($rawOrder['order_sn']) && $rawOrder['order_sn'] !== '' ? (string) $rawOrder['order_sn'] : null,
                completedAt: $this->timestamp($rawOrder['complete_time'] ?? null),
                fraudCompletedAt: $this->timestamp($rawOrder['fraud_complete_time'] ?? null),
                items: $items,
                raw: $rawOrder,
            );
        }

        return new ShopeeFoodCheckout(
            checkoutId: $checkoutId,
            conversionStatus: $this->nullableInt($raw['conversion_status'] ?? null),
            checkoutCap: $this->money($raw['checkout_cap'] ?? null),
            isShopeeCapped: $this->toBool($raw['is_shopee_capped'] ?? false),
            cappedCommission: $this->money($raw['capped_commission'] ?? null),
            affiliateNetCommission: $this->money($raw['affiliate_net_commission'] ?? null),
            utmContent: $utmContent,
            utmFormat: $utm['format'],
            subId1: $utm['sub_id1'],
            subId2: $utm['sub_id2'],
            subId3: $utm['sub_id3'],
            subId4: $utm['sub_id4'],
            subId5: $utm['sub_id5'],
            contentId: $utm['content_id'],
            clickedAt: $this->timestamp($raw['click_time'] ?? null),
            purchasedAt: $this->timestamp($raw['purchase_time'] ?? null),
            completedAt: $this->timestamp($raw['checkout_complete_time'] ?? null),
            orders: $orders,
            raw: $raw,
        );
    }

    private function normalizeOrderItem(string $checkoutId, array $raw): ShopeeFoodOrderItem
    {
        return new ShopeeFoodOrderItem(
            checkoutId: $checkoutId,
            promotionId: $this->nullableString($raw['promotion_id'] ?? null),
            itemId: $this->nullableString($raw['item_id'] ?? null),
            itemName: $this->nullableString($raw['item_name'] ?? null),
            shopName: $this->nullableString($raw['shop_name'] ?? null),
            shopId: $this->nullableString($raw['shop_id'] ?? null),
            itemPrice: $this->money($raw['item_price'] ?? null),
            quantity: $this->nullableInt($raw['qty'] ?? $raw['quantity'] ?? null),
            actualAmount: $this->money($raw['actual_amount'] ?? null),
            refundedAmount: $this->money($raw['refunded_amount'] ?? null),
            platformCommissionRate: $this->rate($raw['platform_commission_rate'] ?? null),
            itemCommission: $this->money($raw['item_commission'] ?? null),
            affiliateItemStatus: $this->nullableInt($raw['affiliate_item_status'] ?? null),
            displayItemStatus: $this->nullableString($raw['display_item_status'] ?? null) ?? '',
            settledAt: $this->timestamp($raw['settled_at'] ?? null),
            paidAt: $this->timestamp($raw['paid_at'] ?? null),
            raw: $raw,
        );
    }

    // ------------------------------------------------------------------
    //  Normalisation helpers
    // ------------------------------------------------------------------

    /**
     * Raw monetary value / 100000 -> VND (float). Nullable.
     */
    public function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ((float) $value) / 100000;
    }

    /**
     * Raw commission rate -> percent.
     *
     * API encodes the rate as percent scaled by 1000: 9000 -> 9%, 5000 -> 5%,
     * 25000 -> 25%. Nullable.
     */
    public function rate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ((float) $value) / 1000;
    }

    /**
     * Gross commission for a line from the NORMALISED percent rate:
     *   actual_amount * rate_percent / 100
     *
     * Contract: $ratePercent must ALWAYS be the normalised DTO value
     * (getPlatformCommissionRate(), e.g. 9.0 == raw 9000). Raw API scaling
     * happens exactly once, inside rate(): 9000 -> 9.0. Never pass the raw
     * 9000 here - that would divide by /100000 and produce 1/100 of the true
     * gross. For raw 9000 the equivalent canonical result is
     *   actual_amount * 9000 / 100000 == actual_amount * 9.0 / 100.
     */
    public function grossCommission(?float $actualAmount, ?float $ratePercent): ?float
    {
        if ($actualAmount === null || $ratePercent === null) {
            return null;
        }

        return $actualAmount * $ratePercent / 100;
    }

    /**
     * unix timestamp seconds -> 'Y-m-d H:i:s' in Asia/Ho_Chi_Minh.
     * 0 (or falsy) means "never happened" -> null (never 1970-01-01).
     */
    public function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '' || (float) $value == 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value, self::TIMEZONE)->toDateTimeString();
    }

    /**
     * Cast a value to nullable int, keeping 0 as 0.
     */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * Cast a value to nullable string.
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Truthy for 1/true/'1'/'true' (0/false/null/'' -> false).
     */
    private function toBool(mixed $value): bool
    {
        return (bool) $value || $value === 'true' || $value === 1 || $value === '1';
    }
}
