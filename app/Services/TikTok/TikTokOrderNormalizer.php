<?php

namespace App\Services\TikTok;

use App\Services\TikTok\DTOs\TikTokOrder;

/**
 * Produces the final database-ready array for a TikTok order.
 *
 * Combines the raw RioHub mapping (TikTokOrder::toDatabaseArray) with user
 * resolution and NET cashback calculation, so that the resulting row is ready
 * to be inserted/updated on affiliate_order_items.
 */
class TikTokOrderNormalizer
{
    public function __construct(
        private readonly TikTokUserResolver $resolver,
        private readonly TikTokCashbackCalculator $cashback,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function normalize(TikTokOrder $order, string $importBatch): array
    {
        [$userId, $username] = $this->resolver->resolve($order);

        $data = $order->toDatabaseArray($username, $userId, $importBatch);

        $cashback = $this->cashback->calculate($order);

        $data['cashback_rate']   = $cashback['cashback_rate'];
        $data['cashback_amount'] = $cashback['cashback_amount'];

        return $data;
    }
}
