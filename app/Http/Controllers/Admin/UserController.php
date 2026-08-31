<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $totalCashbackOnlySub = WalletTransaction::selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('user_id', 'users.id')
            ->where('type', WalletTransaction::TYPE_CASHBACK)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED);

        $query->selectSub($totalCashbackOnlySub, 'total_cashback_only');

        $ordersCountSub = AffiliateOrderItem::selectRaw('COUNT(DISTINCT order_id)')
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

        $sort = $request->input('sort');
        $sortByOrderValue = isset($sort) && in_array($sort, ['order_value_desc', 'order_value_asc']);
        $orderValueDirection = $sortByOrderValue ? (str_ends_with($sort, '_asc') ? 'asc' : 'desc') : null;

        if ($sortByOrderValue) {
            $allUsers = $query->get();

            $userIds = $allUsers->pluck('id')->filter()->values();
            $orderTotals = [];
            if ($userIds->isNotEmpty()) {
                $rows = DB::select(
                    'SELECT user_id, SUM(order_amount) AS total FROM ('
                    . 'SELECT DISTINCT user_id, order_id, order_amount FROM affiliate_order_items'
                    . ' WHERE user_id IN (' . $userIds->implode(',') . ')'
                    . ') sub GROUP BY user_id'
                );
                foreach ($rows as $row) {
                    $orderTotals[$row->user_id] = (float) $row->total;
                }
            }

            foreach ($allUsers as $user) {
                $user->total_order_value = $orderTotals[$user->id] ?? 0;
            }

            $sorted = $allUsers->sortBy('total_order_value', SORT_REGULAR, $orderValueDirection === 'asc');
            $page = $request->input('page', 1);
            $perPage = 50;
            $paginated = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

            $users = new \Illuminate\Pagination\LengthAwarePaginator(
                $paginated,
                $allUsers->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $dbSorts = [
                'orders_desc'   => ['column' => 'orders_count', 'direction' => 'desc'],
                'orders_asc'    => ['column' => 'orders_count', 'direction' => 'asc'],
                'cashback_desc' => ['column' => 'total_cashback_only', 'direction' => 'desc'],
                'cashback_asc'  => ['column' => 'total_cashback_only', 'direction' => 'asc'],
            ];

            if ($sort && isset($dbSorts[$sort])) {
                $query->orderBy($dbSorts[$sort]['column'], $dbSorts[$sort]['direction']);
            } else {
                $query->latest();
            }

            $users = $query->paginate(50)->withQueryString();

            $userIds = $users->pluck('id')->filter()->values();
            $orderTotals = [];
            if ($userIds->isNotEmpty()) {
                $rows = DB::select(
                    'SELECT user_id, SUM(order_amount) AS total FROM ('
                    . 'SELECT DISTINCT user_id, order_id, order_amount FROM affiliate_order_items'
                    . ' WHERE user_id IN (' . $userIds->implode(',') . ')'
                    . ') sub GROUP BY user_id'
                );
                foreach ($rows as $row) {
                    $orderTotals[$row->user_id] = (float) $row->total;
                }
            }

            foreach ($users as $user) {
                $user->total_order_value = $orderTotals[$user->id] ?? 0;
            }
        }

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

    public function adjustWallet(Request $request, User $user, WalletService $walletService): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'direction' => ['required', 'in:credit,debit'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $amount = (float) $validated['amount'];
        $direction = $validated['direction'];
        $reason = trim($validated['reason']);

        try {
            $transaction = $walletService->adjust(
                $user,
                $amount,
                $direction,
                $reason,
                auth()->user(),
            );
        } catch (InsufficientBalanceException $e) {
            return redirect()->back()->withInput()
                ->with('error', __('Số dư ví không đủ để thực hiện giao dịch.'));
        }

        $formatted = number_format($amount, 0, ',', '.');
        $message = $direction === 'credit'
            ? __('Đã cộng :amount VNĐ vào ví User.', ['amount' => $formatted])
            : __('Đã trừ :amount VNĐ khỏi ví User.', ['amount' => $formatted]);

        return redirect()->route('admin.users.show', $user)
            ->with('success', $message)
            ->with('adjustment_before', (float) $transaction->balance_before)
            ->with('adjustment_after', (float) $transaction->balance_after);
    }
}
