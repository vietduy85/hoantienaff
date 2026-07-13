<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FinanceService
{
    private const ORDER_STATUS_COMPLETED = 'Hoàn thành';
    private const WALLET_TYPE_CASHBACK = 'cashback';
    private const WALLET_DIRECTION_CREDIT = 'credit';
    private const WALLET_STATUS_COMPLETED = 'completed';
    private const SAFE_LIMIT = 70.00;

    public static function dashboard(): array
    {
        $totalCommission = (float) DB::table('affiliate_order_items')
            ->where('order_status', self::ORDER_STATUS_COMPLETED)
            ->sum('total_product_commission');

        $totalWallet = (float) DB::table('wallet_transactions')
            ->where('type', self::WALLET_TYPE_CASHBACK)
            ->where('direction', self::WALLET_DIRECTION_CREDIT)
            ->where('status', self::WALLET_STATUS_COMPLETED)
            ->sum('amount');

        $totalOrders = (int) DB::table('affiliate_order_items')
            ->where('order_status', self::ORDER_STATUS_COMPLETED)
            ->count();

        $remaining = $totalCommission - $totalWallet;

        $cashbackRate = $totalCommission > 0
            ? round($totalWallet / $totalCommission * 100, 2)
            : 0.00;

        $safeLimit = self::SAFE_LIMIT;

        $isSafe = $cashbackRate <= $safeLimit;

        return compact(
            'totalCommission',
            'totalWallet',
            'totalOrders',
            'remaining',
            'cashbackRate',
            'safeLimit',
            'isSafe',
        );
    }
}
