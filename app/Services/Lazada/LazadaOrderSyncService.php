<?php

namespace App\Services\Lazada;

use App\Models\AffiliateOrderItem;
use App\Services\Lazada\DTOs\LazadaConversionRecord;
use App\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Lazada order sync from GET /marketing/conversion/report.
 *
 * The API ONLY accepts a single calendar month per request, so any supplied
 * from/to range is split into per-month calls (each bounded by the real month
 * start/end clipped to the requested range). Every page of every month is
 * fetched (limit <= 100, stop when a page returns empty), each conversion
 * record is normalised into one affiliate_order_items line keyed by
 * (platform, lazada_line_key = orderId:subOrderId:sku), users are resolved
 * from subId1 (users.id) cross-checked with subId2 (username), commission is
 * taken from the REAL estPayout (never re-computed from orderAmt), and cashback
 * uses the same 50/60/70 tier rule as ShopeeFood/TikTok.
 *
 * persist=false only reports what WOULD happen (dry-run); persist=true upserts
 * rows inside a transaction and applies the lifecycle — since Phase 3 the sync
 * NEVER credits (finalization is owned by the affiliate:lazada-finalize
 * command); the only wallet action here is an idempotent REVERSAL for a
 * finalized order the platform now reports as returned/rejected/cancelled.
 *
 * Line identity is CLOSED: the business key == (orderId, subOrderId, sku) only.
 * A record missing/empty any of the three is marked INVALID — never guessed —
 * counted as an error, and never persisted.
 *
 * Status mapping (Phase 3): fulfilled/delivered are PENDING (Đang xử lý) with
 * a cashback ESTIMATE — they stay that way until the finalizer command
 * (affiliate:lazada-finalize) credits the wallet once delivered_at is 10 days
 * old. returned/rejected/cancelled map to Đã hủy (0 cashback, never credited).
 * Anything else is treated as Đang xử lý (never credited) and tallied as an
 * UNKNOWN status in the result so the mapping can be finalized once real data
 * flows.
 *
 * Sync NEVER credits: the 10-day finalization is owned exclusively by the
 * finalizer, so callers can re-sync the same window any number of times
 * without moving money. The sync DOES handle one wallet action: idempotent
 * REVERSAL of a genuinely refunded/rejected/cancelled FINALIZED order (see
 * applyLifecycle), which is time-sensitive and belongs to raw API truth.
 * Historical rows (credited before the lifecycle) are never touched, and
 * FINALIZED rows are never bulk-overwritten by sync.
 */
class LazadaOrderSyncService
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 1000;

    private const COMMISSION_MATCH_TOLERANCE = 0.5;

    private const API_NAME = '/marketing/conversion/report';

    public function __construct(
        private readonly LazadaApiClient $client,
        private readonly ?LazadaOrderStatusMapper $statusMapper = null,
        private readonly ?LazadaUserResolver $resolver = null,
        private readonly ?LazadaCashbackCalculator $cashbackCalculator = null,
        private readonly ?LazadaOrderNormalizer $normalizer = null,
        private readonly ?WalletService $walletService = null,
    ) {}

    public function run(
        ?string $from = null,
        ?string $to = null,
        bool $persist = false,
        bool $creditWallet = true,
        ?callable $onProgress = null,
    ): LazadaSyncResult {
        $result = new LazadaSyncResult(startedAt: Carbon::now());

        $this->assertReady();

        $mapper = $this->statusMapper ?? app(LazadaOrderStatusMapper::class);
        $resolver = $this->resolver ?? app(LazadaUserResolver::class);
        $cashback = $this->cashbackCalculator ?? app(LazadaCashbackCalculator::class);
        $normalizer = $this->normalizer ?? app(LazadaOrderNormalizer::class);
        $wallet = $this->walletService ?? app(WalletService::class);

        $ranges = $this->monthRanges($from, $to);
        $importBatch = Carbon::now()->format('Ymd_His');

        foreach ($ranges as $range) {
            $result->monthsFetched++;

            $records = $this->fetchAllRecords($range, $result);

            foreach ($records as $record) {
                $this->processRecord(
                    $record,
                    $mapper,
                    $resolver,
                    $cashback,
                    $normalizer,
                    $wallet,
                    $persist,
                    $creditWallet,
                    $importBatch,
                    $result,
                );

                if ($onProgress !== null) {
                    $onProgress($record, $result);
                }
            }
        }

        $result->finishedAt = Carbon::now();

        return $result;
    }

    // ------------------------------------------------------------------
    //  Readiness guards (fast-fail before any HTTP / persistence)
    // ------------------------------------------------------------------

    private function assertReady(): void
    {
        if (
            config('services.lazada.app_key', '') === ''
            || config('services.lazada.app_secret', '') === ''
            || config('services.lazada.user_token', '') === ''
        ) {
            throw new LazadaException(
                '[Lazada] Credentials are not configured',
                0,
                'Lazada chưa được cấu hình (LAZADA_APP_KEY/APP_SECRET/USER_TOKEN) — không thể đồng bộ đơn hàng Lazada. Các nền tảng khác vẫn chạy bình thường.',
            );
        }

        if (! Schema::hasColumn('affiliate_order_items', 'lazada_line_key')) {
            throw new LazadaException(
                '[Lazada] migration pending',
                0,
                'Cấu trúc DB Lazada chưa sẵn sàng — migration add_lazada_fields_to_affiliate_order_items chưa được chạy. Các nền tảng khác vẫn chạy bình thường.',
            );
        }
    }

    // ------------------------------------------------------------------
    //  Date-range → single-month segments
    // ------------------------------------------------------------------

    /**
     * The API rejects ranges crossing a calendar-month boundary, so we always
     * split into one call per calendar month clipped to the requested bounds.
     *
     * @return array<int, array{dateStart: string, dateEnd: string}>
     */
    private function monthRanges(?string $from, ?string $to): array
    {
        $fromDate = $from !== null
            ? Carbon::parse($from)
            : Carbon::now()->startOfMonth();

        $toDate = $to !== null
            ? Carbon::parse($to)
            : Carbon::now()->endOfMonth();

        if ($fromDate->gt($toDate)) {
            throw new LazadaException(
                '[Lazada] invalid range',
                0,
                'Khoảng ngày không hợp lệ: ngày bắt đầu sau ngày kết thúc.',
            );
        }

        $ranges = [];
        $cursor = $fromDate->copy()->startOfMonth();
        $guard = 0;

        while ($cursor->lte($toDate)) {
            if (++$guard > 1200) {
                throw new LazadaException(
                    '[Lazada] too many months',
                    0,
                    'Khoảng ngày Lazada quá rộng, vui lòng thu hẹp lại.',
                );
            }

            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();

            $ranges[] = [
                'dateStart' => $fromDate->gt($monthStart) ? $fromDate->format('Y-m-d') : $monthStart->format('Y-m-d'),
                'dateEnd' => $toDate->lt($monthEnd) ? $toDate->format('Y-m-d') : $monthEnd->format('Y-m-d'),
            ];

            $cursor->addMonth();
        }

        return $ranges;
    }

    // ------------------------------------------------------------------
    //  Pagination (stop when a page is empty; guarded by MAX_PAGES)
    // ------------------------------------------------------------------

    /**
     * @param  array{dateStart: string, dateEnd: string}  $range
     *
     * @return LazadaConversionRecord[]
     */
    private function fetchAllRecords(array $range, LazadaSyncResult $result): array
    {
        $records = [];
        $page = 1;

        do {
            $payload = $this->client->request(self::API_NAME, [
                'dateStart' => $range['dateStart'],
                'dateEnd'   => $range['dateEnd'],
                'limit'     => (string) self::PAGE_SIZE,
                'page'      => (string) $page,
            ]);

            $result->pagesFetched++;

            $batch = $payload['result']['data'] ?? [];

            if (! is_array($batch)) {
                $batch = [];
            }

            foreach ($batch as $raw) {
                $records[] = LazadaConversionRecord::fromArray($raw);
                $result->recordsFetched++;
            }

            $page++;

            if ($page > self::MAX_PAGES && count($batch) > 0) {
                throw new LazadaException(
                    '[Lazada] max pages reached',
                    0,
                    'Đã vượt quá ' . self::MAX_PAGES . ' trang Lazada — dừng để tránh vòng lặp vô hạn.',
                );
            }
        } while (count($batch) > 0);

        return $records;
    }

    // ------------------------------------------------------------------
    //  Per-record processing
    // ------------------------------------------------------------------

    private function processRecord(
        LazadaConversionRecord $record,
        LazadaOrderStatusMapper $mapper,
        LazadaUserResolver $resolver,
        LazadaCashbackCalculator $cashback,
        LazadaOrderNormalizer $normalizer,
        WalletService $wallet,
        bool $persist,
        bool $creditWallet,
        string $importBatch,
        LazadaSyncResult $result,
    ): void {
        $resolved = $resolver->resolveWithDetail($record);
        $mapped = $mapper->map($record->getStatus());
        $status = $mapped['status'];

        match ($status) {
            LazadaOrderStatusMapper::STATUS_COMPLETED => $result->completed++,
            LazadaOrderStatusMapper::STATUS_CANCELLED => $result->cancelled++,
            default => $result->pending++,
        };

        if ($mapped['unknown']) {
            $result->unknownStatuses++;
            $result->errorsDetail[] = sprintf(
                'Order %s: status "%s" ngoài mapping tài liệu (fulfilled/delivered/returned/rejected/cancelled) — xử lý như %s, không credit, không finalize. Cần chốt mapping khi có dữ liệu thật.',
                $record->getOrderId(),
                $record->getStatus(),
                LazadaOrderStatusMapper::STATUS_PENDING,
            );
        }

        if ($resolved['user_id'] === null) {
            $result->unresolvedUsers++;
        }

        $this->validateCommission($record, $result);

        $line = $this->buildLine($record, $resolved, $cashback, $status, $importBatch, $normalizer);

        if ($line['invalid']) {
            $result->errors++;
            $result->invalidLines++;
            $result->errorsDetail[] = sprintf(
                'Record %s (subOrderId=%s, sku=%s): thiếu orderId/subOrderId/sku — INVALID, không fake key, không persist.',
                $record->getOrderId() !== '' ? $record->getOrderId() : 'unknown',
                $record->getSubOrderId() !== '' ? $record->getSubOrderId() : 'n/a',
                $record->getSku() !== '' ? $record->getSku() : 'n/a',
            );
            $result->lines[] = $line;

            return;
        }

        $result->totalCommission += $line['est_payout'];

        if ($line['cashback_amount'] > 0) {
            $result->cashbackEstimate += $line['cashback_amount'];
            $result->cashbackEligible++;
        }

        $exists = $this->lineExists($line['line_key']);
        $line['would'] = $exists ? 'update' : 'insert';
        if ($exists) {
            $result->wouldUpdate++;
        } else {
            $result->wouldInsert++;
        }

        $result->lines[] = $line;

        if ($persist) {
            $this->persistLine($line, $wallet, $creditWallet, $result);
        }
    }

    /**
     * estPayout must equal basePayout + bonusPayout (the doc's total = base +
     * bonus). Any drift is counted so money anomalies surface instead of being
     * silently stored.
     */
    private function validateCommission(LazadaConversionRecord $record, LazadaSyncResult $result): void
    {
        if (abs($record->getEstPayout() - ($record->getBasePayout() + $record->getBonusPayout())) > self::COMMISSION_MATCH_TOLERANCE) {
            $result->commissionMismatches++;
            $result->errorsDetail[] = sprintf(
                'Record %s: estPayout=%.2f khác basePayout+bonusPayout=%.2f+%.2f',
                $record->getOrderId(),
                $record->getEstPayout(),
                $record->getBasePayout(),
                $record->getBonusPayout(),
            );
            Log::warning('[LazadaOrderSync] commission mismatch', [
                'order_id'        => $record->getOrderId(),
                'est_payout'      => $record->getEstPayout(),
                'base_payout'     => $record->getBasePayout(),
                'bonus_payout'    => $record->getBonusPayout(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    //  Line building
    // ------------------------------------------------------------------

    /**
     * @param  array{user_id: int|null, username: string|null, matched_by: string}  $resolved
     *
     * @return array<string, mixed>
     */
    private function buildLine(
        LazadaConversionRecord $record,
        array $resolved,
        LazadaCashbackCalculator $cashback,
        string $status,
        string $importBatch,
        LazadaOrderNormalizer $normalizer,
    ): array {
        $isCancelled = $status === LazadaOrderStatusMapper::STATUS_CANCELLED;
        $cb = $cashback->calculate($record->getEstPayout(), $record->getOrderAmt(), $isCancelled);

        $lineKey = $this->lineKey($record);

        return [
            'line_key'           => $lineKey,
            'invalid'            => $lineKey === null,
            'order_id'           => $record->getOrderId(),
            'sub_order_id'       => $record->getSubOrderId(),
            'sku'                => $record->getSku(),
            'status'             => $status,
            'raw_status'         => $record->getStatus(),
            'user_id'            => $resolved['user_id'],
            'username'           => $resolved['username'],
            'matched_by'         => $resolved['matched_by'],
            'est_payout'         => $record->getEstPayout(),
            'base_payout'        => $record->getBasePayout(),
            'bonus_payout'       => $record->getBonusPayout(),
            'commission_rate'    => $record->getCommissionRate(),
            'order_amount'       => $record->getOrderAmt(),
            'cashback_rate'      => $cb['cashback_rate'],
            'cashback_amount'    => $cb['cashback_amount'],
            'would'              => 'insert',
            'row'                => $lineKey === null
                ? null
                : $normalizer->normalize($record, $resolved, $status, $importBatch, $lineKey),
        ];
    }

    /**
     * Business key: (orderId, subOrderId, sku) ONLY — deliberately no fallback.
     * A missing/empty any-part yields null, which marks the line INVALID instead
     * of fabricating an identity.
     */
    private function lineKey(LazadaConversionRecord $record): ?string
    {
        $orderId = trim($record->getOrderId());
        $subOrderId = trim($record->getSubOrderId());
        $sku = trim($record->getSku());

        if ($orderId === '' || $subOrderId === '' || $sku === '') {
            return null;
        }

        return $orderId . ':' . $subOrderId . ':' . $sku;
    }

    // ------------------------------------------------------------------
    //  Existing-key lookup (dry-run reports insert vs update)
    // ------------------------------------------------------------------

    private function lineExists(string $lineKey): bool
    {
        return AffiliateOrderItem::query()
            ->where('platform', LazadaOrderNormalizer::PLATFORM)
            ->where('lazada_line_key', $lineKey)
            ->exists();
    }

    // ------------------------------------------------------------------
    //  Persistence + wallet path (persist=true)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $line
     */
    private function persistLine(array $line, WalletService $wallet, bool $creditWallet, LazadaSyncResult $result): void
    {
        try {
            DB::transaction(function () use ($line, $wallet, $creditWallet, $result): void {
                $row = $line['row'];
                $existing = AffiliateOrderItem::query()
                    ->where('platform', LazadaOrderNormalizer::PLATFORM)
                    ->where('lazada_line_key', $line['line_key'])
                    ->first();

                $historical = false;

                if ($existing !== null) {
                    unset($row['first_imported_at']);
                    unset($row['created_at']);

                    // HISTORICAL PROTECTION + TRUE LOCK: rows that already moved
                    // money (credited before the lifecycle OR finalized by the
                    // lifecycle) are NEVER bulk-overwritten by sync. No downgrade,
                    // no recalculation, no snapshot backfill, no status flip.
                    $historical = $existing->hasCompletedCashbackCredit() && ! $existing->isFinalized();
                    $lockedFinalized = $existing->isFinalized();

                    if ($historical || $lockedFinalized) {
                        $item = $existing;
                        $result->protectedSkipped++;
                    } else {
                        $existing->update($row);
                        $item = $existing->fresh();
                        $result->updated++;
                    }
                } else {
                    $item = AffiliateOrderItem::create($row);
                    $result->inserted++;
                }

                // Historical rows are skipped entirely — the lifecycle must
                // never flip-flop them (money already moved).
                if ($creditWallet && ! $historical) {
                    $this->applyLifecycle($wallet, $line, $item, $result);
                }
            });
        } catch (\Throwable $e) {
            $result->errors++;
            $result->errorsDetail[] = sprintf('Line %s: %s', $line['line_key'], $e->getMessage());
            Log::error('[LazadaOrderSync] persist failed', [
                'order_id'   => $line['order_id'],
                'line_key'   => $line['line_key'],
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lifecycle decision for one Lazada order item (persist=true only).
     *
     * Since Phase 3 the sync NEVER credits: 10-day finalization belongs to the
     * affiliate:lazada-finalize command. The one wallet action here is an
     * idempotent REVERSAL when the platform reports a FINALIZED order as
     * returned/rejected/cancelled ($line['status'] === Đã hủy). Everything else
     * is counted as skipped so money never moves through plain sync.
     */
    private function applyLifecycle(WalletService $wallet, array $line, AffiliateOrderItem $item, LazadaSyncResult $result): void
    {
        if ($item->user_id === null) {
            $result->cashbackSkipped++;
            return;
        }

        $isCancelled = $line['status'] === LazadaOrderStatusMapper::STATUS_CANCELLED;

        // 1) Historical protection (money already moved BEFORE the lifecycle).
        if ($item->hasCompletedCashbackCredit() && ! $item->isFinalized()) {
            $result->cashbackSkipped++;
            Log::info('[LazadaOrderSync] historical protected order skipped (money already moved)', $this->auditContext($wallet, $item, 'HISTORICAL_PROTECTED'));
            return;
        }

        // 2) TRUE LOCK: finalized rows cannot be downgraded, recalculated or
        //    double-credited by normal sync — but a genuine platform
        //    returned/rejected/cancelled signal IS reversed (exactly once).
        if ($item->isFinalized()) {
            if ($isCancelled) {
                $this->applyReversal($wallet, $item, $result);
                return;
            }

            if ($this->apiDriftsFromFinalized($line, $item)) {
                Log::warning('[LazadaOrderSync] API drift BLOCKED by lifecycle lock (no downgrade)', array_merge(
                    $this->auditContext($wallet, $item, 'DRIFT_BLOCKED'),
                    ['api_raw_status' => $line['raw_status'], 'stored_raw_status' => $item->lazada_raw_status],
                ));
            }

            $result->cashbackSkipped++;
            return;
        }

        // 3) NEW lifecycle rows: refund/cancel is already stored as Đã hủy / 0
        //    (never credited), delivered/fulfilled stay pending with their
        //    estimate — the finalizer makes the credit decision.
        $result->cashbackSkipped++;
    }

    /**
     * Idempotent reversal for a finalized order that the platform now reports
     * as returned/rejected/cancelled. Reverses exactly the amount originally
     * credited, creates a single refund (debit) transaction, marks the row
     * reversed and clears the display cashback — while deliberately keeping
     * the original cashback transaction and the finalized snapshot for audit.
     */
    private function applyReversal(WalletService $wallet, AffiliateOrderItem $item, LazadaSyncResult $result): void
    {
        if (! $wallet->isCashbackCredited($item)) {
            $result->cashbackSkipped++;
            return;
        }

        $reversal = $wallet->reverseCashback($item, throwOnDuplicate: false);

        if ($reversal === null) {
            $result->cashbackSkipped++;
            Log::info('[LazadaOrderSync] reversal already applied (idempotent skip)', $this->auditContext($wallet, $item, 'REVERSAL_SKIP'));
            return;
        }

        $item->affiliate_status = AffiliateOrderItem::STATUS_CANCELLED;
        $item->cashback_amount = 0.0;
        $item->markReversed();

        $result->cashbackReversed++;
        Log::info('[LazadaOrderSync] cashback reversal applied', $this->auditContext($wallet, $item, 'REVERSAL'));
    }

    /**
     * True when the API now reports a raw status that contradicts the locked
     * raw status (e.g. delivered → fulfilled drift after finalization). Used
     * for audit only — the lock always wins, nothing is written.
     *
     * @param  array<string, mixed>  $line
     */
    private function apiDriftsFromFinalized(array $line, AffiliateOrderItem $item): bool
    {
        return $line['raw_status'] !== $item->lazada_raw_status;
    }

    /**
     * Structured context for lifecycle audit logs.
     *
     * @return array<string, mixed>
     */
    private function auditContext(WalletService $wallet, AffiliateOrderItem $item, string $action): array
    {
        return [
            'action'                    => $action,
            'platform'                  => $item->platform,
            'affiliate_order_item_id'   => $item->id,
            'lazada_line_key'           => $item->lazada_line_key,
            'order_id'                  => $item->order_id,
            'user_id'                   => $item->user_id,
            'affiliate_status'          => $item->affiliate_status,
            'cashback_amount'           => $item->cashback_amount,
            'cashback_credited'         => $wallet->isCashbackCredited($item),
            'finalized_at'              => $item->finalized_at?->toDateTimeString(),
            'final_cashback_amount'     => $item->final_cashback_amount,
            'reversed_at'               => $item->reversed_at?->toDateTimeString(),
        ];
    }
}