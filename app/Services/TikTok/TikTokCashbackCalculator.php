<?php

namespace App\Services\TikTok;

use App\Services\TikTok\DTOs\TikTokOrder;

/**
 * Cashback calculation for TikTok/RioHub orders.
 *
 * TikTok cashback is based on the NET commission (thực nhận): once an order is
 * SETTLED we use `actual_commission` directly and do NOT apply the extra 10%
 * tax deduction that the Shopee estimator uses. The rate (50/60/70) is derived
 * from the ratio commission / order_amount using the same thresholds as Shopee.
 *
 * For PENDING (not yet settled) orders we still store a cashback_amount so the
 * UI can show "Cashback dự kiến" — it is computed from `est_commission` with
 * the same formula, but the wallet is only ever credited when the row's
 * affiliate_status is "Hoàn thành".
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

        // Refunded / cancelled orders are never paid out.
        if ($order->isRefunded()) {
            return [
                'cashback_rate'   => self::RATE_50,
                'cashback_amount' => 0.0,
            ];
        }

        // Settled orders carry a realised NET commission and may be credited.
        // If the actual commission is still missing there is nothing creditable
        // yet — never fall back to the estimate here (a "Hoàn thành" row with a
        // non-zero cashback would be credited by the wallet transition).
        if ($order->isSettled()) {
            if ($actualCommission !== null && $actualCommission > 0) {
                return $this->calculateFromCommission((float) $actualCommission, $orderAmount);
            }

            return [
                'cashback_rate'   => self::RATE_50,
                'cashback_amount' => 0.0,
            ];
        }

        // Pending / not settled yet: display an ESTIMATE from the estimated
        // commission. This is display-only — the wallet transition only credits
        // rows whose affiliate_status is "Hoàn thành".
        $estCommission = $order->getEstCommission();
        if ($estCommission !== null && $estCommission > 0) {
            return $this->calculateFromCommission((float) $estCommission, $orderAmount);
        }

        return [
            'cashback_rate'   => self::RATE_50,
            'cashback_amount' => 0.0,
        ];
    }

    /**
     * Reusable rate + amount from a raw commission and order amount. This is the
     * canonical formula used both for order credits and link estimates, so an
     * estimate of a given commission always equals the wallet credit for the
     * same commission/tier (no extra 10% deduction).
     *
     * @return array{cashback_rate: float, cashback_amount: float}
     */
    public function calculateFromCommission(float $commission, float $orderAmount): array
    {
        if ($commission <= 0) {
            return [
                'cashback_rate'   => self::RATE_50,
                'cashback_amount' => 0.0,
            ];
        }

        $rate = $this->resolveRate($commission, $orderAmount);
        $amount = (float) floor($commission * $rate);

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
