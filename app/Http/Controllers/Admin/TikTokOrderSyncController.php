<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateOrderItem;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\TikTok\TikTokServiceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TikTokOrderSyncController extends Controller
{
    public function __construct(
        private readonly TikTokOrderSyncService $syncService,
    ) {}

    public function index(): View
    {
        $recentOrders = AffiliateOrderItem::query()
            ->where('platform', 'TikTok')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $stats = [
            'total'    => AffiliateOrderItem::where('platform', 'TikTok')->count(),
            'settled'  => AffiliateOrderItem::where('platform', 'TikTok')->where('affiliate_status', 'Hoàn thành')->count(),
            'refunded' => AffiliateOrderItem::where('platform', 'TikTok')->where('affiliate_status', 'Đã hủy')->count(),
        ];

        return view('admin.tiktok-order-sync.index', compact('recentOrders', 'stats'));
    }

    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        try {
            $result = $this->syncService->run(
                from: $validated['from'] ?? null,
                to: $validated['to'] ?? null,
            );

            $request->session()->flash(
                'tiktok_sync_result',
                $this->formatSummary($result),
            );
        } catch (TikTokServiceException $e) {
            Log::error('[Admin TikTok Sync] RioHub error', ['error' => $e->getMessage()]);
            $request->session()->flash('tiktok_sync_error', $e->getMessage());
        }

        return redirect()->route('admin.tiktok-order-sync.index');
    }

    private function formatSummary(\App\Services\TikTok\TikTokSyncResult $result): string
    {
        $data = $result->toArray();

        return sprintf(
            'Đã đồng bộ: %d đơn (%d dòng) | Thêm mới: %d | Cập nhật: %d | Bỏ qua: %d | Lỗi: %d | Hoa hồng đã tính: %d (bỏ: %d) | Thời gian: %s giây',
            $data['orders_fetched'],
            $data['items_fetched'],
            $data['inserted'],
            $data['updated'],
            $data['skipped'],
            $data['errors'],
            $data['cashback_credited'],
            $data['cashback_skipped'],
            $data['elapsed_seconds'],
        );
    }
}
