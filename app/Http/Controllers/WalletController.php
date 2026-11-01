<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWithdrawRequest;
use App\Models\AffiliateOrderItem;
use App\Models\WalletTransaction;
use App\Services\Payout\WithdrawAdminNotifier;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WalletController extends Controller
{
    private WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function index(Request $request): View
    {
        $user = auth()->user();

        $available = $this->walletService->getAvailableBalance($user);

        $pending = (float) AffiliateOrderItem::query()
            ->where('user_id', $user->id)
            ->whereIn('affiliate_status', ['Đang chờ xử lý', 'Đang xử lý'])
            ->sum('cashback_amount');

        $paid = (float) $user->total_withdrawn;

        $hasBankInfo = $user->bank_name && $user->bank_account_name && $user->bank_account_number;

        $transactions = WalletTransaction::where('user_id', $user->id)
            ->orderByRaw('COALESCE(completed_at, created_at) DESC')
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();

        return view('wallet.index', [
            'available' => $available,
            'pending' => $pending,
            'paid' => $paid,
            'hasBankInfo' => $hasBankInfo,
            'bankName' => $user->bank_name,
            'accountName' => $user->bank_account_name,
            'accountNumber' => $user->bank_account_number,
            'transactions' => $transactions,
        ]);
    }

    public function withdraw(StoreWithdrawRequest $request)
    {
        $user = auth()->user();
        $amount = (float) $request->input('amount');

        $withdrawRequest = $this->walletService->createWithdrawRequest($user, $amount);

        // Báo admin qua Zalo sau khi đã trả trang cho user — không chờ, không cần queue worker.
        dispatch(fn () => app(WithdrawAdminNotifier::class)->notifyNew($withdrawRequest))->afterResponse();

        return redirect()->route('wallet.index')
            ->with('success', __('Đã tạo yêu cầu rút tiền.'));
    }
}
