<?php

namespace App\Services\Lazada;

/**
 * Cashback for Lazada order lines — the SAME business rule as ShopeeFood/
 * TikTok (a tier rate derived from the commission ratio over the order amount):
 *
 *   base        = estPayout (REAL total payout base + bonus, straight from API)
 *   orderAmount = orderAmt
 *
 *   commissionRate = base / orderAmount
 *       >= 0.52 -> 70%   |   >= 0.12 -> 60%   |   otherwise -> 50%
 *
 * This calculator only estimates — the wallet is handled by WalletService via
 * the sync service transitions. `estimated_cashback` from Phase 1 link previews
 * is deliberately NOT used here; the credit is always computed from the real
 * payout returned by the conversion report.
 */
class LazadaCashbackCalculator
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