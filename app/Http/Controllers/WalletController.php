<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWithdrawRequest;
use App\Models\AffiliateOrderItem;
use App\Models\WithdrawRequest;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->where('affiliate_status', 'Đang chờ xử lý')
            ->sum('cashback_amount');

        $paid = (float) $user->total_withdrawn;

        $hasBankInfo = $user->bank_name && $user->bank_account_name && $user->bank_account_number;

        $withdrawRequests = WithdrawRequest::where('user_id', $user->id)
            ->latest()
            ->limit(10)
            ->get();

        return view('wallet.index', [
            'available' => $available,
            'pending' => $pending,
            'paid' => $paid,
            'hasBankInfo' => $hasBankInfo,
            'bankName' => $user->bank_name,
            'accountName' => $user->bank_account_name,
            'accountNumber' => $user->bank_account_number,
            'withdrawRequests' => $withdrawRequests,
        ]);
    }

    public function withdraw(StoreWithdrawRequest $request)
    {
        $user = auth()->user();
        $amount = (float) $request->input('amount');

        $this->walletService->createWithdrawRequest($user, $amount);

        return redirect()->route('wallet.index')
            ->with('success', __('Đã tạo yêu cầu rút tiền.'));
    }
}
