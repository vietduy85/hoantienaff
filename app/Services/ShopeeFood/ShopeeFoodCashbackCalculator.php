<?php

namespace App\Services\ShopeeFood;

/**
 * Cashback ESTIMATION for ShopeeFood order lines.
 *
 * Phase 1 ONLY ESTIMATES — this class never touches the wallet.
 *
 * The business rule is the same tier rule TikTok/RioHub uses (a rate derived
 * from the commission ratio over the order amount):
 *   base        = item_commission (the NET line commission, cap-inclusive)
 *   orderAmount = item actual_amount
 *
 *   commissionRate = base / orderAmount
 *       >= 0.52 -> 70%   |   >= 0.12 -> 60%   |   otherwise -> 50%
 *
 * Why a separate calculator (not the shared one):
 *   - `TikTokCashbackCalculator` is coupled to the TikTokOrder DTO signature,
 *     so ShopeeFood lines cannot be fed into it directly.
 *   - the shared `CashbackCalculator` is the *Shopee CSV* estimator and applies
 *     the extra 10% tax deduction — a different pipeline with a different rule.
 *   - mirroring the per-platform TikTok calculator keeps the proven TikTok code
 *     untouched and keeps the tier thresholds next to the platform they serve.
 */
class ShopeeFoodCashbackCalculator
{
    private const RATE_50 = 0.50;
    private const RATE_60 = 0.60;
    private const RATE_70 = 0.70;

    private const THRESHOLD_60 = 0.12;
    private const THRESHOLD_70 = 0.52;

    /**
     * @return array{cashback_rate: float, cashback_amount: float}
     */
    public function calculate(float $commissionBase, float $orderAmount, bool $isCompleted): array
    {
        if (! $isCompleted || $commissionBase <= 0) {
            return [
                'cashback_rate'   => self::RATE_50,
                'cashback_amount' => 0.0,
            ];
        }

        $rate = $this->resolveRate($commissionBase, $orderAmount);
        $amount = (float) floor($commissionBase * $rate);

        return [
            'cashback_rate'   => $rate,
            'cashback_amount' => $amount,
        ];
    }

    public function resolveRate(float $commissionBase, float $orderAmount): float
    {
        if ($orderAmount <= 0) {
            return self::RATE_50;
        }

        $commissionRate = $commissionBase / $orderAmount;

        if ($commissionRate >= self::THRESHOLD_70) {
            return self::RATE_70;
        }

        if ($commissionRate >= self::THRESHOLD_60) {
            return self::RATE_60;
        }

        return self::RATE_50;
    }
}