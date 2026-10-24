<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateOrderItem;
use App\Services\AddLiveTag\ShopeeApiSyncService;
use App\Services\Backup\BackupStatusService;
use App\Services\Lazada\LazadaException;
use App\Services\Lazada\LazadaOrderSyncService;
use App\Services\Lazada\LazadaSyncResult;
use App\Services\ShopeeFood\ShopeeFoodException;
use App\Services\ShopeeFood\ShopeeFoodOrderSyncService;
use App\Services\ShopeeFood\ShopeeFoodSyncResult;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\TikTok\TikTokServiceException;
use App\Services\TikTok\TikTokSyncResult;
use App\Support\AffiliateOrderUpdateStatus;
use App\Support\AffiliateSyncAttempt;
use App\Support\AffiliateSyncLock;
use App\Support\AffiliateSyncStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        private readonly BackupStatusService $backupStatusService,
    ) {}

    public function index(): View
    {
        $backupStatus = $this->backupStatusService->status();
        $tz = config('app.timezone', 'Asia/Ho_Chi_Minh');
        $now = Carbon::now($tz);
        $todayStart = $now->copy()->startOfDay();
        $yesterdayStart = $now->copy()->subDay()->startOfDay();

        $recentOrders = AffiliateOrderItem::query()
            ->whereIn('platform', ['TikTok', 'Lazada', 'ShopeeFood', 'Shopee'])
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $monthStart->copy();

        $orderCountByPlatform = function (string $platform, Carbon $start, Carbon $end): int {
            return (int) AffiliateOrderItem::query()
                ->where('platform', $platform)
                ->whereNotNull('ordered_at')
                ->where('ordered_at', '>=', $start)
                ->where('ordered_at', '<', $end)
                ->distinct()
                ->count(DB::raw('order_id'));
        };

        $dailyOrders = [
            'tiktok' => [
                'today' => $orderCountByPlatform('TikTok', $todayStart, $todayStart->copy()->addDay()),
                'yesterday' => $orderCountByPlatform('TikTok', $yesterdayStart, $todayStart),
            ],
            'lazada' => [
                'today' => $orderCountByPlatform('Lazada', $todayStart, $todayStart->copy()->addDay()),
                'yesterday' => $orderCountByPlatform('Lazada', $yesterdayStart, $todayStart),
            ],
            'shopeefood' => [
                'today' => $orderCountByPlatform('ShopeeFood', $todayStart, $todayStart->copy()->addDay()),
                'yesterday' => $orderCountByPlatform('ShopeeFood', $yesterdayStart, $todayStart),
            ],
            'shopee' => [
                'today' => $orderCountByPlatform('Shopee', $todayStart, $todayStart->copy()->addDay()),
                'yesterday' => $orderCountByPlatform('Shopee', $yesterdayStart, $todayStart),
            ],
        ];

        foreach ($dailyOrders as $key => $v) {
            $t = (int) $v['today'];
            $y = (int) $v['yesterday'];
            if ($y === 0) {
                $dailyOrders[$key]['diff'] = null;
                $dailyOrders[$key]['pct'] = null;
            } else {
                $diff = $t - $y;
                $pct = round(($diff / $y) * 100, 1);
                $dailyOrders[$key]['diff'] = $diff;
                $dailyOrders[$key]['pct'] = $pct;
            }
        }

        $monthlyOrders = [
            'tiktok' => [
                'this_month' => $orderCountByPlatform('TikTok', $monthStart, $monthStart->copy()->addMonth()),
                'last_month' => $orderCountByPlatform('TikTok', $lastMonthStart, $lastMonthEnd),
            ],
            'lazada' => [
                'this_month' => $orderCountByPlatform('Lazada', $monthStart, $monthStart->copy()->addMonth()),
                'last_month' => $orderCountByPlatform('Lazada', $lastMonthStart, $lastMonthEnd),
            ],
            'shopeefood' => [
                'this_month' => $orderCountByPlatform('ShopeeFood', $monthStart, $monthStart->copy()->addMonth()),
                'last_month' => $orderCountByPlatform('ShopeeFood', $lastMonthStart, $lastMonthEnd),
            ],
            'shopee' => [
                'this_month' => $orderCountByPlatform('Shopee', $monthStart, $monthStart->copy()->addMonth()),
                'last_month' => $orderCountByPlatform('Shopee', $lastMonthStart, $lastMonthEnd),
            ],
        ];

        foreach ($monthlyOrders as $key => $v) {
            $t = (int) $v['this_month'];
            $y = (int) $v['last_month'];
            if ($y === 0) {
                $monthlyOrders[$key]['diff'] = null;
                $monthlyOrders[$key]['pct'] = null;
            } else {
                $diff = $t - $y;
                $pct = round(($diff / $y) * 100, 1);
                $monthlyOrders[$key]['diff'] = $diff;
                $monthlyOrders[$key]['pct'] = $pct;
            }
        }

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

        // "Lần đồng bộ gần nhất" = the last time the platform's sync STEP
        // finished successfully (recorded in `settings`), NOT the last time an
        // order row was written. Rows that are protected/unchanged would
        // otherwise freeze this label (Lazada 720/721/722). For old data that
        // predates the run markers, fall back to the legacy row-write max.
        $lastTikTokSyncAt = $this->lastRunAt(AffiliateSyncStatus::TIKTOK, 'TikTok', 'last_tiktok_sync_at');
        $lastShopeeFoodSyncAt = $this->lastRunAt(AffiliateSyncStatus::SHOPEEFOOD, 'ShopeeFood', 'last_shopeefood_sync_at');
        $lastLazadaSyncAt = $this->lastRunAt(AffiliateSyncStatus::LAZADA, 'Lazada', 'last_lazada_sync_at');
        $lastShopeeSyncAt = $this->lastRunAt(AffiliateSyncStatus::SHOPEE, 'Shopee', 'last_shopee_sync_at');

        // "Đơn cập nhật gần nhất" + 24h warning + last attempt per platform
        // (Shopee / Lazada / TikTok) — ONE aggregate query + settings reads.
        $orderStatuses = AffiliateOrderUpdateStatus::evaluateAll();
        $shopeeAlert = $orderStatuses['shopee'];

        return view('admin.order-sync.index', compact(
            'recentOrders',
            'stats',
            'dailyOrders',
            'monthlyOrders',
            'lastTikTokSyncAt',
            'lastShopeeFoodSyncAt',
            'lastLazadaSyncAt',
            'lastShopeeSyncAt',
            'backupStatus',
            'orderStatuses',
            'shopeeAlert',
            'now',
        ));
    }

    /**
     * Resolve the admin "last sync" time for a platform.
     *
     * Prefers the run marker written by the sync pipeline
     * (App\Support\AffiliateSyncStatus, key `affiliate.last_run.<platform>`);
     * falls back to the legacy MAX(last_<platform>_sync_at) row-write column
     * so historical installs still show something before the first new run.
     */
    private function lastRunAt(string $platform, string $platformLabel, string $column): ?Carbon
    {
        $run = AffiliateSyncStatus::lastRun($platform);

        if ($run !== null) {
            return $run;
        }

        $fallback = AffiliateOrderItem::where('platform', $platformLabel)
            ->whereNotNull($column)
            ->max($column);

        return $fallback ? Carbon::parse($fallback) : null;
    }

    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $lock = Cache::lock(AffiliateSyncLock::KEY, AffiliateSyncLock::SECONDS);

        if (! $lock->get()) {
            // Nothing ran — record every monitored platform as skipped (lock
            // busy) so the 24h warning can explain WHY no order moved this round.
            AffiliateSyncAttempt::record(
                AffiliateSyncAttempt::SHOPEE,
                AffiliateSyncAttempt::STATE_SKIPPED,
                'lock_busy',
            );
            AffiliateSyncAttempt::record(
                AffiliateSyncAttempt::TIKTOK,
                AffiliateSyncAttempt::STATE_SKIPPED,
                'lock_busy',
            );
            AffiliateSyncAttempt::record(
                AffiliateSyncAttempt::LAZADA,
                AffiliateSyncAttempt::STATE_SKIPPED,
                'lock_busy',
            );
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

            if ($result->errors === 0) {
                AffiliateSyncStatus::record(AffiliateSyncStatus::TIKTOK);
                AffiliateSyncAttempt::record(
                    AffiliateSyncAttempt::TIKTOK,
                    AffiliateSyncAttempt::STATE_SUCCESS,
                    'success',
                );
            } else {
                AffiliateSyncAttempt::record(
                    AffiliateSyncAttempt::TIKTOK,
                    AffiliateSyncAttempt::STATE_FAILED,
                    'order_errors',
                );
            }

            $request->session()->flash('tiktok_sync_result', $this->formatTikTokSummary($result));
        } catch (TikTokServiceException $e) {
            AffiliateSyncAttempt::record(
                AffiliateSyncAttempt::TIKTOK,
                AffiliateSyncAttempt::STATE_FAILED,
                'api_error',
            );
            Log::error('[Admin Sync][TikTok] RioHub error', ['error' => $e->getMessage()]);
            $request->session()->flash('tiktok_sync_error', $e->getMessage());
        } catch (\Throwable $e) {
            AffiliateSyncAttempt::record(
                AffiliateSyncAttempt::TIKTOK,
                AffiliateSyncAttempt::STATE_FAILED,
                'exception',
            );

            throw $e;
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

            if ($result->errors === 0) {
                AffiliateSyncStatus::record(AffiliateSyncStatus::SHOPEEFOOD);
            }

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

            if ($result->errors === 0) {
                AffiliateSyncStatus::record(AffiliateSyncStatus::LAZADA);
                AffiliateSyncAttempt::record(
                    AffiliateSyncAttempt::LAZADA,
                    AffiliateSyncAttempt::STATE_SUCCESS,
                    'success',
                );
            } else {
                AffiliateSyncAttempt::record(
                    AffiliateSyncAttempt::LAZADA,
                    AffiliateSyncAttempt::STATE_FAILED,
                    'order_errors',
                );
            }

            $request->session()->flash('lazada_sync_result', $this->formatLazadaSummary($result));
        } catch (LazadaException $e) {
            AffiliateSyncAttempt::record(
                AffiliateSyncAttempt::LAZADA,
                AffiliateSyncAttempt::STATE_FAILED,
                'api_error',
            );
            Log::error('[Admin Sync][Lazada] error', ['error' => $e->getMessage()]);
            $request->session()->flash('lazada_sync_error', $e->getUserMessage());
        } catch (\Throwable $e) {
            AffiliateSyncAttempt::record(
                AffiliateSyncAttempt::LAZADA,
                AffiliateSyncAttempt::STATE_FAILED,
                'exception',
            );

            throw $e;
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
                AffiliateSyncAttempt::record(
                    AffiliateSyncAttempt::SHOPEE,
                    AffiliateSyncAttempt::STATE_FAILED,
                    (string) ($result['sync_state'] ?? 'failed'),
                );
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
                'sync_state' => $result['sync_state'],
                'http_status' => $result['api_http_status'],
                'business_status' => $result['api_business_status'],
                'pages' => $result['api_pages_fetched'],
                'records' => $result['api_records'],
                'raw_items' => $result['raw_items'],
                'orders' => $result['orders'],
                'created' => $result['applied_created'],
                'updated' => $result['applied_updated'],
                'protected' => $result['plan_protected'],
                'credit' => $result['credit'],
                'duration' => $result['duration'],
            ]);

            $syncState = (string) ($result['sync_state'] ?? 'success');

            // 0 records is acceptable inside the 24h freshness window, but a
            // stale Shopee feed + an empty API response is NOT a success: it
            // means no new order was synced. Surface a real error instead of
            // a green "hoàn tất" banner.
            if ($syncState === 'success_zero'
                && AffiliateOrderUpdateStatus::evaluate('shopee')['state'] === 'stale') {
                Log::error('[Admin Sync][Shopee] zero records while Shopee feed is stale', [
                    'sync_state' => $syncState,
                    'http_status' => $result['api_http_status'],
                    'business_status' => $result['api_business_status'],
                    'records' => $result['api_records'],
                    'from' => $result['from'],
                    'to' => $result['to'],
                ]);
                AffiliateSyncAttempt::record(
                    AffiliateSyncAttempt::SHOPEE,
                    AffiliateSyncAttempt::STATE_FAILED,
                    'zero_records_stale',
                );
                $request->session()->flash(
                    'shopee_sync_error',
                    'Đồng bộ Shopee (AddLiveTag API) trả về 0 bản ghi trong khi đã quá 24 giờ không có đơn Shopee nào được cập nhật. Kiểm tra API key và dữ liệu AddLiveTag.',
                );

                return;
            }

            AffiliateSyncStatus::record(AffiliateSyncStatus::SHOPEE);
            AffiliateSyncAttempt::record(
                AffiliateSyncAttempt::SHOPEE,
                AffiliateSyncAttempt::STATE_SUCCESS,
                $syncState,
            );

            $request->session()->flash('shopee_sync_result', $this->formatShopeeSummary($result));
        } catch (\Throwable $e) {
            Log::error('[Admin Sync][Shopee] error', ['error' => $this->maskAddLiveTagSecrets($e->getMessage())]);
            AffiliateSyncAttempt::record(
                AffiliateSyncAttempt::SHOPEE,
                AffiliateSyncAttempt::STATE_FAILED,
                'exception',
            );
            $request->session()->flash(
                'shopee_sync_error',
                'Đồng bộ Shopee (AddLiveTag API) thất bại: '.$this->maskAddLiveTagSecrets($e->getMessage()),
            );
        }
    }

    /** Never let the AddLiveTag API key reach flash messages / logs. */
    private function maskAddLiveTagSecrets(string $message): string
    {
        $key = trim((string) config('services.addlivetag.api_key', ''));

        if ($key !== '' && str_contains($message, $key)) {
            $message = str_replace($key, '****', $message);
        }

        return preg_replace('/([?&]key=)[^&\s]+/i', '$1****', $message) ?? $message;
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
            'sync_state' => (string) ($r['sync_state'] ?? '-'),
            'api_http_status' => $r['api_http_status'] ?? null,
            'api_business_status' => $r['api_business_status'] ?? null,
            'api_records' => (int) ($r['api_records'] ?? 0),
            'api_pages' => (int) ($r['api_pages_fetched'] ?? 0),
            'notice' => $r['notice'] ?? null,
            'from' => $r['from'],
            'to' => $r['to'] ?? '-',
            'raw_items' => $r['raw_items'],
            'orders' => $r['orders'],
            'inserted' => $r['applied_created'],
            'updated' => $r['applied_updated'],
            'orders_written' => (int) ($r['orders_written'] ?? 0),
            'protected' => $r['plan_protected'],
            'user_conflict' => $r['user_conflict'],
            'sub_id_mismatch' => $r['sub_id_mismatch'],
            'wallet_credits' => (int) ($r['credit']['credited'] ?? 0),
            'wallet_errors' => $creditErrors,
            'duration' => $r['duration'],
        ];
    }
}
