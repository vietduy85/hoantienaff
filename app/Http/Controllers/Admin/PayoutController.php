<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\InvalidWithdrawException;
use App\Http\Controllers\Controller;
use App\Models\WithdrawRequest;
use App\Services\Payout\VietQrPayload;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trang duyệt rút tiền trên điện thoại: mỗi yêu cầu có VietQR + nút sao chép,
 * admin chuyển khoản bằng app ngân hàng rồi bấm "Đã chuyển".
 */
class PayoutController extends Controller
{
    public function __construct(private WalletService $walletService) {}

    public function index(): View
    {
        $requests = WithdrawRequest::pending()->oldest()->get()->map(function (WithdrawRequest $request) {
            $payload = VietQrPayload::forWithdraw($request);

            return [
                'model' => $request,
                'qr_svg' => $payload ? VietQrPayload::svg($payload) : null,
                'note' => VietQrPayload::transferNote($request),
                'deeplink' => VietQrPayload::deeplink($request, route('admin.payouts.index')),
            ];
        });

        return view('admin.payouts.index', [
            'requests' => $requests,
            'apps' => config('banks.payout_apps'),
            'paidToday' => WithdrawRequest::paid()->whereDate('processed_at', today())->count(),
        ]);
    }

    public function complete(WithdrawRequest $withdrawRequest): RedirectResponse
    {
        try {
            $this->walletService->completeWithdraw($withdrawRequest, auth()->user());
        } catch (InvalidWithdrawException|InsufficientBalanceException $e) {
            // Bấm 2 lần / admin khác đã xử lý / số dư lệch: báo lỗi gọn thay vì trang 500 trên điện thoại.
            return redirect()->route('admin.payouts.index')->withErrors(['payout' => $e->getMessage()]);
        }

        return redirect()->route('admin.payouts.index')
            ->with('success', "Đã đánh dấu chuyển xong #{$withdrawRequest->id} ({$withdrawRequest->username}).");
    }

    public function reject(Request $request, WithdrawRequest $withdrawRequest): RedirectResponse
    {
        $validated = $request->validate(['note' => 'required|string|max:500']);

        try {
            $this->walletService->rejectWithdraw($withdrawRequest, auth()->user(), $validated['note']);
        } catch (InvalidWithdrawException $e) {
            return redirect()->route('admin.payouts.index')->withErrors(['payout' => $e->getMessage()]);
        }

        return redirect()->route('admin.payouts.index')
            ->with('success', "Đã từ chối #{$withdrawRequest->id} ({$withdrawRequest->username}).");
    }
}
