<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $query = User::query()
            ->select('users.*')
            ->with('roles');

        $pendingCashbackSub = AffiliateOrderItem::selectRaw('COALESCE(SUM(cashback_amount), 0)')
            ->whereColumn('user_id', 'users.id')
            ->whereNotIn('id', function ($q) {
                $q->select('reference_id')
                    ->from('wallet_transactions')
                    ->where('reference_type', 'affiliate_order_item')
                    ->where('type', WalletTransaction::TYPE_CASHBACK)
                    ->where('status', WalletTransaction::STATUS_COMPLETED);
            });

        $query->selectSub($pendingCashbackSub, 'pending_cashback_amount');

        $totalCompletedSub = WalletTransaction::selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('user_id', 'users.id')
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED);

        $query->selectSub($totalCompletedSub, 'total_completed_amount');

        $ordersCountSub = AffiliateOrderItem::selectRaw('COUNT(*)')
            ->whereColumn('user_id', 'users.id');

        $query->selectSub($ordersCountSub, 'orders_count');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('google_id', 'like', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            $query->whereHas('roles', function ($q) use ($role) {
                $q->where('name', $role);
            });
        }

        $users = $query->latest()->paginate(50)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function show(User $user): View
    {
        $user->load([
            'roles',
        ]);

        $pendingCashbackItems = AffiliateOrderItem::where('user_id', $user->id)
            ->whereNotIn('id', function ($q) use ($user) {
                $q->select('reference_id')
                    ->from('wallet_transactions')
                    ->where('user_id', $user->id)
                    ->where('reference_type', 'affiliate_order_item')
                    ->where('type', WalletTransaction::TYPE_CASHBACK)
                    ->where('status', WalletTransaction::STATUS_COMPLETED);
            })
            ->latest('ordered_at')
            ->limit(20)
            ->get();

        $recentOrders = AffiliateOrderItem::where('user_id', $user->id)
            ->latest('ordered_at')
            ->limit(10)
            ->get();

        $recentWithdrawals = WithdrawRequest::where('user_id', $user->id)
            ->latest()
            ->limit(10)
            ->get();

        $recentTransactions = WalletTransaction::where('user_id', $user->id)
            ->latest()
            ->limit(10)
            ->get();

        $stats = [
            'total_orders' => AffiliateOrderItem::where('user_id', $user->id)->count(),
            'total_order_amount' => (float) AffiliateOrderItem::where('user_id', $user->id)->sum('order_amount'),
            'total_cashback_earned' => (float) WalletTransaction::where('user_id', $user->id)
                ->where('type', WalletTransaction::TYPE_CASHBACK)
                ->where('status', WalletTransaction::STATUS_COMPLETED)
                ->sum('amount'),
            'total_withdrawn' => (float) $user->total_withdrawn,
            'pending_withdrawal' => (float) WithdrawRequest::where('user_id', $user->id)
                ->where('status', WithdrawRequest::STATUS_PENDING)
                ->sum('amount'),
            'pending_cashback' => (float) $pendingCashbackItems->sum('cashback_amount'),
        ];

        return view('admin.users.show', compact('user', 'pendingCashbackItems', 'recentOrders', 'recentWithdrawals', 'recentTransactions', 'stats'));
    }
}
