<?php

namespace App\Services\TikTok;

use App\Services\TikTok\DTOs\TikTokOrder;

/**
 * Cashback calculation for TikTok/RioHub orders.
 *
 * TikTok cashback is based on the NET commission (thực nhận): we use
 * `actual_commission` directly and do NOT apply the extra 10% tax deduction
 * that the Shopee estimator uses. The rate (50/60/70) is derived from the
 * ratio actual_commission / order_amount using the same thresholds as Shopee.
 */
class TikTokCashbackCalculator
{
    private const RATE_50 = 0.50;
    private const RATE_60 = 0.60;
    private const RATE_70 = 0.70;

    private const THRESHOLD_60 = 0.12;
    private const THRESHOLD_70 = 0.52;

    /**
     * @return array{cashback_rate: float, cashback_amount: float}
     */
    public function calculate(TikTokOrder $order): array
    {
        $orderAmount = (float) ($order->getCommissionGmv() ?? 0);
        $actualCommission = $order->getActualCommission();

        // Only settled orders carry a realised NET commission. If the order has
        // not settled (or was refunded) there is nothing to pay out yet.
        if (! $order->isSettled() || $actualCommission === null || $actualCommission <= 0) {
            return [
                'cashback_rate'   => self::RATE_50,
                'cashback_amount' => 0.0,
            ];
        }

        $rate = $this->resolveRate($actualCommission, $orderAmount);
        $amount = (float) floor($actualCommission * $rate);

        return [
            'cashback_rate'   => $rate,
            'cashback_amount' => $amount,
        ];
    }

    private function resolveRate(float $actualCommission, float $orderAmount): float
    {
        if ($orderAmount <= 0) {
            return self::RATE_50;
        }

        $commissionRate = $actualCommission / $orderAmount;

        return match (true) {
            $commissionRate >= self::THRESHOLD_70 => self::RATE_70,
            $commissionRate >= self::THRESHOLD_60 => self::RATE_60,
            default => self::RATE_50,
        };
    }
}
