<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawRequest;
use App\Services\BankExportService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    public function bulkComplete(Request $request, BankExportService $bankExportService): BinaryFileResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:withdraw_requests,id',
        ]);

        $admin = auth()->user();

        $withdrawRequests = WithdrawRequest::whereIn('id', $validated['ids'])
            ->get();

        $nonPending = $withdrawRequests->reject->isPending();

        if ($nonPending->isNotEmpty()) {
            $ids = $nonPending->pluck('id')->implode(', ');

            return redirect()->route('admin.withdraw-requests.index')
                ->with('error', __('Các yêu cầu sau không ở trạng thái chờ xử lý: :ids', ['ids' => $ids]));
        }

        $tempFile = $bankExportService->export('chuyenkhoantheobangke', $withdrawRequests, $admin);

        try {
            DB::transaction(function () use ($withdrawRequests, $admin) {
                foreach ($withdrawRequests as $wr) {
                    $this->walletService->completeWithdraw($wr, $admin);
                }
            });
        } catch (\Exception $e) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }

            throw $e;
        }

        $exporter = $bankExportService->getExporter('chuyenkhoantheobangke');

        return response()->download($tempFile, $exporter->getDownloadFilename(), [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ])->deleteFileAfterSend(true);
    }
}
