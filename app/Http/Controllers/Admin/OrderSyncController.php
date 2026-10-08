<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateOrderItem;
use App\Services\AddLiveTag\ShopeeApiSyncService;
use App\Services\Lazada\LazadaException;
use App\Services\Lazada\LazadaOrderSyncService;
use App\Services\Lazada\LazadaSyncResult;
use App\Services\ShopeeFood\ShopeeFoodException;
use App\Services\ShopeeFood\ShopeeFoodOrderSyncService;
use App\Services\ShopeeFood\ShopeeFoodSyncResult;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\TikTok\TikTokServiceException;
use App\Services\TikTok\TikTokSyncResult;
use App\Support\AffiliateSyncLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Unified admin sync page: TikTok, Lazada, ShopeeFood & Shopee (AddLiveTag API).
 *
 * One button calls TikTokOrderSyncService THEN LazadaOrderSyncService THEN
 * ShopeeFoodOrderSyncService THEN ShopeeApiSyncService (the same shared service
 * `affiliate:sync-all` step 5 runs — identical pipeline, identical 14-day
 * window). Each platform runs in its own try/catch so one platform failing
 * (e.g. missing SHOPEEFOOD_COOKIE / Lazada creds / AddLiveTag API error) never
 * loses the other platforms' results. Flash keys are kept separate per
 * platform.
 *
 * Route names and URL are unchanged (admin.tiktok-order-sync.index/.sync,
 * /admin/tiktok-order-sync) — only the implementation broadened.
 */
class OrderSyncController extends Controller
{
    public function __construct(
        private readonly TikTokOrderSyncService $tikTokService,
        private readonly ShopeeFoodOrderSyncService $shopeeFoodService,
        private readonly LazadaOrderSyncService $lazadaService,
        private readonly ShopeeApiSyncService $shopeeService,
    ) {}

    public function index(): View
    {
        $recentOrders = AffiliateOrderItem::query()
            ->whereIn('platform', ['TikTok', 'Lazada', 'ShopeeFood', 'Shopee'])
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $stats = [
            'tiktok' => [
                'total' => AffiliateOrderItem::where('platform', 'TikTok')->count(),
                'settled' => AffiliateOrderItem::where('platform', 'TikTok')->where('affiliate_status', 'Hoàn thành')->count(),
                'refunded' => AffiliateOrderItem::where('platform', 'TikTok')->where('affiliate_status', 'Đã hủy')->count(),
            ],
            'shopeefood' => [
                'total' => AffiliateOrderItem::where('platform', 'ShopeeFood')->count(),
                'settled' => AffiliateOrderItem::where('platform', 'ShopeeFood')->where('affiliate_status', 'Hoàn thành')->count(),
                'refunded' => AffiliateOrderItem::where('platform', 'ShopeeFood')->where('affiliate_status', 'Đã hủy')->count(),
            ],
            'lazada' => [
                'total' => AffiliateOrderItem::where('platform', 'Lazada')->count(),
                'settled' => AffiliateOrderItem::where('platform', 'Lazada')->where('affiliate_status', 'Hoàn thành')->count(),
                'refunded' => AffiliateOrderItem::where('platform', 'Lazada')->where('affiliate_status', 'Đã hủy')->count(),
            ],
            'shopee' => [
                'total' => AffiliateOrderItem::where('platform', 'Shopee')->count(),
                'settled' => AffiliateOrderItem::where('platform', 'Shopee')->where('affiliate_status', 'Hoàn thành')->count(),
                'refunded' => AffiliateOrderItem::where('platform', 'Shopee')->where('affiliate_status', 'Đã hủy')->count(),
            ],
        ];

        $lastTikTokSyncAt = AffiliateOrderItem::where('platform', 'TikTok')
            ->whereNotNull('last_tiktok_sync_at')
            ->max('last_tiktok_sync_at');

        $lastShopeeFoodSyncAt = AffiliateOrderItem::where('platform', 'ShopeeFood')
            ->whereNotNull('last_shopeefood_sync_at')
            ->max('last_shopeefood_sync_at');

        $lastLazadaSyncAt = AffiliateOrderItem::where('platform', 'Lazada')
            ->whereNotNull('last_lazada_sync_at')
            ->max('last_lazada_sync_at');

        // Shopee reuses the SAME tracking column ConversionsImporter::apply()
        // stamps on every created/updated row — no new table / no new mechanism.
        $lastShopeeSyncAt = AffiliateOrderItem::where('platform', 'Shopee')
            ->whereNotNull('last_shopee_sync_at')
            ->max('last_shopee_sync_at');

        return view('admin.order-sync.index', compact(
            'recentOrders',
            'stats',
            'lastTikTokSyncAt',
            'lastShopeeFoodSyncAt',
            'lastLazadaSyncAt',
            'lastShopeeSyncAt',
        ));
    }

    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $lock = Cache::lock(AffiliateSyncLock::KEY, AffiliateSyncLock::SECONDS);

        if (! $lock->get()) {
            $request->session()->flash(
                'tiktok_sync_error',
                'Một phiên đồng bộ đang chạy (TikTok/Lazada/ShopeeFood/Shopee). Vui lòng thử lại sau.',
            );

            return redirect()->route('admin.tiktok-order-sync.index');
        }

        try {
            // Same order as affiliate:sync-all: TikTok → Lazada → ShopeeFood → Shopee.
            $this->runTikTok($request, $validated['from'] ?? null, $validated['to'] ?? null);
            $this->runLazada($request, $validated['from'] ?? null, $validated['to'] ?? null);
            $this->runShopeeFood($request, $validated['from'] ?? null, $validated['to'] ?? null);
            $this->runShopee($request);
        } finally {
            $lock->release();
        }

        return redirect()->route('admin.tiktok-order-sync.index');
    }

    // ------------------------------------------------------------------
    //  Feed runners (each isolated)
    // ------------------------------------------------------------------

    private function runTikTok(Request $request, ?string $from, ?string $to): void
    {
        try {
            $result = $this->tikTokService->run(from: $from, to: $to);

            $syncType = $request->user()->hasRole('Operator') ? 'manual_operator' : 'manual_admin';

            Log::info('[Admin Sync][TikTok] manual sync', array_merge(
                ['sync_type' => $syncType],
                $result->toArray(),
            ));

            $request->session()->flash('tiktok_sync_result', $this->formatTikTokSummary($result));
        } catch (TikTokServiceException $e) {
            Log::error('[Admin Sync][TikTok] RioHub error', ['error' => $e->getMessage()]);
            $request->session()->flash('tiktok_sync_error', $e->getMessage());
        }
    }

    private function runShopeeFood(Request $request, ?string $from, ?string $to): void
    {
        try {
            // Phase 2: REAL import + wallet (idempotent — repeating the sync
            // never double-credits / double-reverses).
            $result = $this->shopeeFoodService->run(from: $from, to: $to, persist: true, creditWallet: true);

            Log::info('[Admin Sync][ShopeeFood] manual sync', [
                'sync_type' => $request->user()->hasRole('Operator') ? 'manual_operator' : 'manual_admin',
                'result' => $result->toArray(),
            ]);

            $request->session()->flash('shopeefood_sync_result', $this->formatShopeeFoodSummary($result));
        } catch (ShopeeFoodException $e) {
            Log::error('[Admin Sync][ShopeeFood] error', ['error' => $e->getMessage(), 'kind' => $e->getKind()]);
            $request->session()->flash('shopeefood_sync_error', $e->getMessage());
        }
    }

    private function runLazada(Request $request, ?string $from, ?string $to): void
    {
        try {
            // REAL import + wallet (idempotent — re-syncing never double-credits /
            // double-reverses). The API only serves one calendar month per
            // request; the service splits the range automatically.
            $result = $this->lazadaService->run(from: $from, to: $to, persist: true, creditWallet: true);

            Log::info('[Admin Sync][Lazada] manual sync', [
                'sync_type' => $request->user()->hasRole('Operator') ? 'manual_operator' : 'manual_admin',
                'result' => $result->toArray(),
            ]);

            $request->session()->flash('lazada_sync_result', $this->formatLazadaSummary($result));
        } catch (LazadaException $e) {
            Log::error('[Admin Sync][Lazada] error', ['error' => $e->getMessage()]);
            $request->session()->flash('lazada_sync_error', $e->getUserMessage());
        }
    }

    private function runShopee(Request $request): void
    {
        try {
            // Phase 3: identical pipeline to affiliate:sync-all step 5 —
            // shared ShopeeApiSyncService (AddLiveTag API, 14-day window,
            // apply rows + wallet credit, idempotent). The form's from/to
            // does NOT change Shopee's own 14-day API lookback (business rule);
            // truncation / API errors report FAIL instead of a fake success.
            $result = $this->shopeeService->run();

            if (! $result['success']) {
                Log::error('[Admin Sync][Shopee] AddLiveTag error', ['error' => $result['error']]);
                $request->session()->flash(
                    'shopee_sync_error',
                    'Đồng bộ Shopee (AddLiveTag API) thất bại: '.$result['error'],
                );

                return;
            }

            Log::info('[Admin Sync][Shopee] manual sync', [
                'sync_type' => $request->user()->hasRole('Operator') ? 'manual_operator' : 'manual_admin',
                'api' => 'AddLiveTag',
                'from' => $result['from'],
                'to' => $result['to'],
                'raw_items' => $result['raw_items'],
                'orders' => $result['orders'],
                'created' => $result['applied_created'],
                'updated' => $result['applied_updated'],
                'protected' => $result['plan_protected'],
                'credit' => $result['credit'],
                'duration' => $result['duration'],
            ]);

            $request->session()->flash('shopee_sync_result', $this->formatShopeeSummary($result));
        } catch (\Throwable $e) {
            Log::error('[Admin Sync][Shopee] error', ['error' => $e->getMessage()]);
            $request->session()->flash(
                'shopee_sync_error',
                'Đồng bộ Shopee (AddLiveTag API) thất bại: '.$e->getMessage(),
            );
        }
    }

    // ------------------------------------------------------------------
    //  Formatters (TikTok keys unchanged)
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function formatTikTokSummary(TikTokSyncResult $result): array
    {
        $data = $result->toArray();

        return [
            'success' => $result->errors === 0,
            'message' => $result->errors > 0 ? 'Đồng bộ TikTok không hoàn toàn' : 'Đồng bộ TikTok hoàn tất',
            'orders_fetched' => $data['orders_fetched'],
            'items_fetched' => $data['items_fetched'],
            'inserted' => $data['inserted'],
            'updated' => $data['updated'],
            'skipped' => $data['skipped'],
            'wallet_credits' => $data['cashback_credited'],
            'wallet_reversals' => $data['cashback_reversed'],
            'errors' => $data['errors'],
            'duration' => round($data['elapsed_seconds'], 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatShopeeFoodSummary(ShopeeFoodSyncResult $result): array
    {
        $data = $result->toArray();

        return [
            'success' => $result->errors === 0,
            'message' => $result->errors > 0 ? 'Đồng bộ ShopeeFood không hoàn toàn' : 'Đồng bộ ShopeeFood hoàn tất',
            'checkouts_fetched' => $result->checkoutsFetched,
            'orders_fetched' => $result->ordersFetched,
            'items_fetched' => $result->itemsFetched,
            'inserted' => $result->inserted,
            'updated' => $result->updated,
            'would_insert' => $result->wouldInsert,
            'would_update' => $result->wouldUpdate,
            'pending' => $result->pending,
            'completed' => $result->completed,
            'cancelled' => $result->cancelled,
            'unresolved_users' => $result->unresolvedUsers,
            'total_commission' => $data['total_commission'],
            'cashback_estimate' => $data['cashback_estimate'],
            'cashback_eligible' => $result->cashbackEligible,
            'wallet_credits' => $result->cashbackCredited,
            'wallet_reversals' => $result->cashbackReversed,
            'cashback_skipped' => $result->cashbackSkipped,
            'commission_mismatches' => $result->commissionMismatches,
            'invalid_lines' => $result->invalidLines,
            'errors' => $result->errors,
            'duration' => round($data['elapsed_seconds'], 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLazadaSummary(LazadaSyncResult $result): array
    {
        $data = $result->toArray();

        return [
            'success' => $result->errors === 0,
            'message' => $result->errors > 0 ? 'Đồng bộ Lazada không hoàn toàn' : 'Đồng bộ Lazada hoàn tất',
            'months_fetched' => $result->monthsFetched,
            'pages_fetched' => $result->pagesFetched,
            'records_fetched' => $result->recordsFetched,
            'inserted' => $result->inserted,
            'updated' => $result->updated,
            'would_insert' => $result->wouldInsert,
            'would_update' => $result->wouldUpdate,
            'pending' => $result->pending,
            'completed' => $result->completed,
            'cancelled' => $result->cancelled,
            'unresolved_users' => $result->unresolvedUsers,
            'total_commission' => $data['total_commission'],
            'cashback_estimate' => $data['cashback_estimate'],
            'cashback_eligible' => $result->cashbackEligible,
            'wallet_credits' => $result->cashbackCredited,
            'wallet_reversals' => $result->cashbackReversed,
            'cashback_skipped' => $result->cashbackSkipped,
            'commission_mismatches' => $result->commissionMismatches,
            'unknown_statuses' => $result->unknownStatuses,
            'invalid_lines' => $result->invalidLines,
            'errors' => $result->errors,
            'duration' => round($data['elapsed_seconds'], 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $r  result array from ShopeeApiSyncService::run()
     * @return array<string, mixed>
     */
    private function formatShopeeSummary(array $r): array
    {
        $creditErrors = (int) ($r['credit']['errors'] ?? 0);

        return [
            'success' => $creditErrors === 0,
            'message' => $creditErrors > 0 ? 'Đồng bộ Shopee không hoàn toàn' : 'Đồng bộ Shopee (AddLiveTag API) hoàn tất',
            'api' => 'AddLiveTag',
            'from' => $r['from'],
            'to' => $r['to'] ?? '-',
            'raw_items' => $r['raw_items'],
            'orders' => $r['orders'],
            'inserted' => $r['applied_created'],
            'updated' => $r['applied_updated'],
            'protected' => $r['plan_protected'],
            'user_conflict' => $r['user_conflict'],
            'sub_id_mismatch' => $r['sub_id_mismatch'],
            'wallet_credits' => (int) ($r['credit']['credited'] ?? 0),
            'wallet_errors' => $creditErrors,
            'duration' => $r['duration'],
        ];
    }
}
