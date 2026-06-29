<?php

namespace App\Services;

class CashbackCalculator
{
    private const RATE_50 = 0.50;
    private const RATE_60 = 0.60;
    private const RATE_70 = 0.70;

    private const THRESHOLD_60 = 0.12;
    private const THRESHOLD_70 = 0.52;

    public function calculate(float $estimatedCashback, float $productPrice): array
    {
        if ($productPrice <= 0 || $estimatedCashback <= 0) {
            return [
                'cashback_rate' => self::RATE_50,
                'user_estimated_cashback' => 0,
            ];
        }

        $commissionRate = $estimatedCashback / $productPrice;

        $rate = match (true) {
            $commissionRate >= self::THRESHOLD_70 => self::RATE_70,
            $commissionRate >= self::THRESHOLD_60 => self::RATE_60,
            default => self::RATE_50,
        };

        $userCashback = (int) floor($estimatedCashback * $rate);

        return [
            'cashback_rate' => $rate,
            'user_estimated_cashback' => $userCashback,
        ];
    }
}
