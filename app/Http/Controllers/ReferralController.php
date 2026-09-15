<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\WalletTransaction;
use App\Services\ReferralService;
use Illuminate\View\View;

class ReferralController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $referrals = Referral::with('referredUser')
            ->where('referrer_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $invitedCount = $referrals->count();

        $receivedAmount = (float) WalletTransaction::where('user_id', $user->id)
            ->where('type', WalletTransaction::TYPE_REFERRAL)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->sum('amount');

        $referralLink = app(ReferralService::class)->referralLink($user);

        return view('referrals.index', [
            'user' => $user,
            'referrals' => $referrals,
            'invitedCount' => $invitedCount,
            'receivedAmount' => $receivedAmount,
            'referralLink' => $referralLink,
            'rewardAmount' => Referral::REWARD_AMOUNT,
            'requiredOrders' => Referral::REQUIRED_COMPLETED_ORDERS,
        ]);
    }
}