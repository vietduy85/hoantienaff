<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ReferralService;
use Illuminate\Http\Request;
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

    public function statistics(Request $request): View
    {
        $search = trim((string) $request->query('user', ''));

        $query = User::query()
            ->join('referrals', 'referrals.referrer_id', '=', 'users.id')
            ->selectRaw('
                users.id as user_id,
                users.username as username,
                users.name as name,
                COUNT(referrals.id) as total_referrals,
                SUM(CASE WHEN referrals.status = ? THEN 1 ELSE 0 END) as completed_referrals,
                SUM(CASE WHEN referrals.status = ? THEN 1 ELSE 0 END) as pending_referrals
            ', [Referral::STATUS_COMPLETED, Referral::STATUS_PENDING])
            ->groupBy('users.id', 'users.username', 'users.name')
            ->havingRaw('COUNT(referrals.id) > 0');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('users.username', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%");
            });
        }

        $rows = $query
            ->orderByDesc('total_referrals')
            ->orderByDesc('users.id')
            ->paginate(25)
            ->withQueryString();

        foreach ($rows as $row) {
            $row->total_referrals = (int) $row->total_referrals;
            $row->completed_referrals = (int) $row->completed_referrals;
            $row->pending_referrals = (int) $row->pending_referrals;
        }

        $summary = [
            'referrers' => (int) Referral::distinct()->count('referrer_id'),
            'total_referrals' => (int) Referral::count(),
            'completed_referrals' => (int) Referral::where('status', Referral::STATUS_COMPLETED)->count(),
        ];

        return view('referrals.statistics', [
            'rows' => $rows,
            'search' => $search,
            'summary' => $summary,
            'statusCompleted' => Referral::STATUS_COMPLETED,
            'statusPending' => Referral::STATUS_PENDING,
        ]);
    }
}