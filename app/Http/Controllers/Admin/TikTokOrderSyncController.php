<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateOrderItem;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\TikTok\TikTokServiceException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TikTokOrderSyncController extends Controller
{
    private const SYNC_LOCK_KEY = 'affiliate-tiktok-sync:lock';

    private const SYNC_LOCK_SECONDS = 1800;

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

        $lock = Cache::lock(self::SYNC_LOCK_KEY, self::SYNC_LOCK_SECONDS);

        if (! $lock->get()) {
            $request->session()->flash(
                'tiktok_sync_error',
                'Một phiên đồng bộ TikTok khác đang chạy (scheduler hoặc thủ công). Vui lòng thử lại sau.',
            );
            return redirect()->route('admin.tiktok-order-sync.index');
        }

        try {
            $result = $this->syncService->run(
                from: $validated['from'] ?? null,
                to: $validated['to'] ?? null,
            );

            $syncType = $request->user()->hasRole('Operator') ? 'manual_operator' : 'manual_admin';

            Log::info('[Admin TikTok Sync] manual sync', array_merge(
                ['sync_type' => $syncType],
                $result->toArray(),
            ));

            $request->session()->flash(
                'tiktok_sync_result',
                $this->formatSummary($result),
            );
        } catch (TikTokServiceException $e) {
            Log::error('[Admin TikTok Sync] RioHub error', ['error' => $e->getMessage()]);
            $request->session()->flash('tiktok_sync_error', $e->getMessage());
        } finally {
            $lock->release();
        }

        return redirect()->route('admin.tiktok-order-sync.index');
    }

    private function formatSummary(\App\Services\TikTok\TikTokSyncResult $result): string
    {
        $data = $result->toArray();

        return sprintf(
            'Đồng bộ %s: %d đơn (%d dòng) | Thêm mới: %d | Cập nhật: %d | Bỏ qua: %d | Lỗi: %d | Wallet credits: %d | Reversals: %d | Thời gian: %s giây',
            $result->errors > 0 ? 'KHÔNG HOÀN TOÀN' : 'thành công',
            $data['orders_fetched'],
            $data['items_fetched'],
            $data['inserted'],
            $data['updated'],
            $data['skipped'],
            $data['errors'],
            $data['cashback_credited'],
            $data['cashback_reversed'],
            $data['elapsed_seconds'],
        );
    }
}
