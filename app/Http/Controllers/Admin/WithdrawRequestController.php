<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawRequest;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WithdrawRequestController extends Controller
{
    private WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function index(): View
    {
        $requests = WithdrawRequest::with('user')
            ->latest()
            ->paginate(20);

        return view('admin.withdraw-requests.index', compact('requests'));
    }

    public function complete(WithdrawRequest $withdrawRequest): RedirectResponse
    {
        $admin = auth()->user();

        $this->walletService->completeWithdraw($withdrawRequest, $admin);

        return redirect()->route('admin.withdraw-requests.index')
            ->with('success', __('Đã hoàn tất yêu cầu rút tiền #:id.', ['id' => $withdrawRequest->id]));
    }

    public function reject(Request $request, WithdrawRequest $withdrawRequest): RedirectResponse
    {
        $admin = auth()->user();

        $validated = $request->validate([
            'note' => 'required|string|max:500',
        ]);

        $this->walletService->rejectWithdraw($withdrawRequest, $admin, $validated['note']);

        return redirect()->route('admin.withdraw-requests.index')
            ->with('success', __('Đã từ chối yêu cầu rút tiền #:id.', ['id' => $withdrawRequest->id]));
    }
}
