<?php

namespace App\Services\TikTok;

use App\Models\AffiliateOrderItem;
use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\TikTok\DTOs\TikTokOrder;
use App\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TikTokOrderSyncService
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly RioHubClient $client,
        private readonly ?TikTokOrderNormalizer $normalizer = null,
        private readonly ?WalletService $walletService = null,
    ) {}

    /**
     * Fetch orders from RioHub and map to TikTokOrder DTOs.
     *
     * @param  array{page?: int, per_page?: int, status?: string}  $filters
     *
     * @return Collection<int, TikTokOrder>
     *
     * @throws TikTokServiceException On API errors.
     */
    public function sync(array $filters = []): Collection
    {
        try {
            $response = $this->client->getOrders($filters);
        } catch (RioHubException $e) {
            throw TikTokServiceException::fromRioHubException($e, 'sync');
        }

        $orders = $response->getData()['orders'] ?? null;

        if (!is_array($orders)) {
            return collect();
        }

        return collect($orders)
            ->map(fn (array $order) => TikTokOrder::fromArray($order))
            ->values();
    }

    /**
     * Full API -> database sync cycle.
     *
     * Fetches every page of orders from RioHub (the API has no server-side
     * date filter, so any supplied range is applied client-side against
     * the order creation time), normalizes each order, upserts it onto
     * affiliate_order_items keyed by (platform, order_id, item_id) and
     * credits cashback for newly settled orders.
     *
     * @param  string|null  $from  Inclusive start (Y-m-d or Y-m-d H:i:s).
     * @param  string|null  $to    Exclusive end (Y-m-d or Y-m-d H:i:s).
     * @param  array  $filters  Extra RioHub query params (e.g. status => 2).
     * @param  callable|null  $onProgress  Called after each processed order.
     * @param  bool  $creditWallet  When false, rows are written but WalletService
     *                              is never called (Phase 2.2 import mode).
     *
     * @throws TikTokServiceException On API errors.
     */
    public function run(
        ?string $from = null,
        ?string $to = null,
        array $filters = [],
        ?callable $onProgress = null,
        bool $creditWallet = true,
    ): TikTokSyncResult {
        $result = new TikTokSyncResult(startedAt: Carbon::now());

        $normalizer = $this->normalizer ?? app(TikTokOrderNormalizer::class);
        $wallet = $this->walletService ?? app(WalletService::class);

        $orders = $this->fetchAllOrders($filters, $result);

        $range = $this->buildRange($from, $to);
        $importBatch = Carbon::now()->format('Ymd_His');
        $cashback = new TikTokCashbackCalculator();

        foreach ($orders as $order) {
            $result->ordersFetched++;

            if ($range !== null && !$this->inRange($order, $range)) {
                $result->skipped++;
                continue;
            }

            try {
                DB::transaction(function () use ($order, $normalizer, $cashback, $wallet, $importBatch, $result, $creditWallet) {
                    $data = $normalizer->normalize($order, $importBatch);

                    // Belt eligibility decides the stored state for NEW lifecycle
                    // orders. Refunded/cancelled orders are terminal (Đã hủy, 0).
                    // Belt-incomplete orders stay PENDING with an ESTIMATE — they
                    // are never locked, credited or finalized.
                    if ($order->isRefundOrCancel()) {
                        $data['affiliate_status'] = AffiliateOrderItem::STATUS_CANCELLED;
                        $data['cashback_amount'] = 0.0;
                    } elseif (! $order->passesFinalizeBelt()) {
                        $estimate = $cashback->calculateEstimate($order);
                        $data['cashback_amount'] = $estimate['cashback_amount'];
                        $data['cashback_rate'] = $estimate['cashback_rate'];
                        $data['affiliate_status'] = AffiliateOrderItem::STATUS_PENDING;
                    }

                    $existing = AffiliateOrderItem::query()
                        ->where('platform', 'TikTok')
                        ->where('order_id', $data['order_id'])
                        ->where('item_id', $data['item_id'])
                        ->first();

                    $historical = false;

                    if ($existing) {
                        unset($data['first_imported_at']);

                        // HISTORICAL PROTECTION + TRUE LOCK: rows that already
                        // moved money (credited before the lifecycle OR finalized
                        // by the lifecycle) are NEVER bulk-overwritten. No
                        // downgrade, no recalculation, no snapshot backfill.
                        $historical = $existing->hasCompletedCashbackCredit() && ! $existing->isFinalized();
                        $lockedFinalized = $existing->isFinalized();

                        if ($historical || $lockedFinalized) {
                            $item = $existing;
                            $result->protectedSkipped++;
                        } else {
                            $existing->update($data);
                            $item = $existing->fresh();
                            $result->updated++;
                        }
                    } else {
                        $item = AffiliateOrderItem::create($data);
                        $result->inserted++;
                    }

                    if ($creditWallet && ! $historical) {
                        $this->applyLifecycle($wallet, $order, $item, $result);
                    }
                });
            } catch (\Throwable $e) {
                $result->errors++;
                $result->errorsDetail[] = sprintf(
                    'Order %s: %s',
                    $order->getOrderId(),
                    $e->getMessage(),
                );
                Log::error('[TikTokOrderSync] failed for order', [
                    'order_id' => $order->getOrderId(),
                    'error'    => $e->getMessage(),
                ]);
            }

            if ($onProgress !== null) {
                $onProgress($order, $result);
            }
        }

        $result->finishedAt = Carbon::now();

        return $result;
    }

    /**
     * @return array<int, TikTokOrder>
     */
    private function fetchAllOrders(array $filters, TikTokSyncResult $result): array
    {
        $orders = [];
        $page = 1;
        $batch = [];

        do {
            try {
                $response = $this->client->getOrders(array_merge($filters, [
                    'page'      => $page,
                    'page_size' => self::PAGE_SIZE,
                ]));
            } catch (RioHubException $e) {
                throw TikTokServiceException::fromRioHubException($e, 'sync');
            }

            $data = $response->getData();

            $batch = $data['orders'] ?? [];

            if (is_array($batch)) {
                foreach ($batch as $raw) {
                    $orders[] = TikTokOrder::fromArray($raw);
                    $result->itemsFetched++;
                }
            }

            $total = (int) ($data['total'] ?? count($orders));
            $fetched = count($orders);

            $page++;
        } while ($fetched < $total && is_array($batch) && count($batch) > 0);

        return $orders;
    }

    /**
     * @return array{from: ?Carbon, to: ?Carbon}|null
     */
    private function buildRange(?string $from, ?string $to): ?array
    {
        if ($from === null && $to === null) {
            return null;
        }

        return [
            'from' => $from !== null ? Carbon::parse($from) : null,
            'to'   => $to !== null
                ? (str_contains($to, ':') ? Carbon::parse($to) : Carbon::parse($to)->endOfDay())
                : null,
        ];
    }

    /**
     * @param  array{from: ?Carbon, to: ?Carbon}  $range
     */
    private function inRange(TikTokOrder $order, array $range): bool
    {
        $created = $order->getTimeCreated();

        if ($created === null && $order->getCreateTime() !== null) {
            $created = date('Y-m-d H:i:s', $order->getCreateTime());
        }

        if ($created === null) {
            return true;
        }

        $date = Carbon::parse($created);

        if ($range['from'] !== null && $date->lt($range['from'])) {
            return false;
        }

        if ($range['to'] !== null && $date->gte($range['to'])) {
            return false;
        }

        return true;
    }

    /**
     * Evaluate and execute the correct idempotent wallet action for one order
     * item, honouring the TikTok lifecycle rules:
     *
     *  1. HISTORICAL ORDERS MUST NEVER BE TOUCHED — any row with a completed
     *     cashback credit that was NOT lifecycle-finalized predates the
     *     lifecycle. It is skipped entirely: no reversal, no recalculation, no
     *     downgrade, no finalized snapshot.
     *
     *  2. TRUE LOCK — finalized rows are never overwritten by normal sync. Only
     *     a genuine REFUNDED/CANCELLED event triggers the idempotent reversal
     *     flow (the lock must never block a real reversal).
     *
     *  3. NEW orders finalize ONLY when the SETTLED belt passes
     *     (status=2 + settlement_status=SETTLED + tt_order_status=103 +
     *     actual_commission==est_commission). Credit happens first; ONLY after a
     *     successful credit is the order marked finalized (never
     *     "finalized but wallet not credited").
     */
    private function applyLifecycle(
        WalletService $wallet,
        TikTokOrder $order,
        AffiliateOrderItem $item,
        TikTokSyncResult $result,
    ): void {
        if ($item->user_id === null) {
            $result->cashbackSkipped++;
            return;
        }

        // 1) Historical protection (money already moved BEFORE the lifecycle).
        if ($item->hasCompletedCashbackCredit() && ! $item->isFinalized()) {
            $result->cashbackSkipped++;
            Log::info('[TikTokOrderSync] historical protected order skipped (money already moved)', $this->auditContext($item, 'HISTORICAL_PROTECTED'));
            return;
        }

        // 2) TRUE LOCK: finalized rows cannot be downgraded, recalculated or
        //    double-credited by normal sync.
        if ($item->isFinalized()) {
            if ($order->isRefundOrCancel()) {
                $this->applyReversal($wallet, $item, $result);

                return;
            }

            if ($this->apiDriftsFromFinalized($order, $item)) {
                Log::warning('[TikTokOrderSync] API drift BLOCKED by lifecycle lock (no downgrade)', array_merge(
                    $this->auditContext($item, 'DRIFT_BLOCKED'),
                    ['api_affiliate_status' => $order->mappedAffiliateStatus()],
                ));
            }

            $result->cashbackSkipped++;

            return;
        }

        // 3) NEW lifecycle orders.
        if ($order->isRefundOrCancel()) {
            $result->cashbackSkipped++;
            return;
        }

        if (! $order->passesFinalizeBelt()) {
            $result->cashbackSkipped++;
            return;
        }

        $amount = (float) $item->cashback_amount;

        if ($amount <= 0) {
            $result->cashbackSkipped++;
            return;
        }

        // Inconsistent state (credited but not lifecycle-finalized): snapshot
        // the lock, but never re-credit and never auto-adjust a shifted amount.
        if ($wallet->isCashbackCredited($item)) {
            $credited = $wallet->creditedAmount($item);
            if ($credited !== null && abs($credited - $amount) > 0.005) {
                Log::warning('[TikTokOrderSync] commission change BLOCKED (no auto-adjust)', [
                    'affiliate_order_item_id' => $item->id,
                    'order_id' => $item->order_id,
                    'credited_amount' => $credited,
                    'new_cashback' => $amount,
                ]);
                $result->cashbackSkipped++;
                return;
            }

            $item->markFinalized(AffiliateOrderItem::FINALIZE_GATE_TIKTOK_SETTLED);
            $result->cashbackSkipped++;
            return;
        }

        $transaction = $wallet->creditCashback($item, throwOnDuplicate: false);

        if ($transaction === null) {
            $result->cashbackSkipped++;
            return;
        }

        // Wallet order of operations: ONLY after the credit succeeds do we
        // snapshot final_cashback_amount + finalized_at + finalize_gate.
        $item->markFinalized(AffiliateOrderItem::FINALIZE_GATE_TIKTOK_SETTLED);

        $result->cashbackCredited++;
        Log::info('[TikTokOrderSync] cashback credited + order finalized', $this->auditContext($item, 'CREDIT'));
    }

    /**
     * Idempotent reversal for a finalized order that the platform now reports
     * as refunded/cancelled. Reverses exactly the amount originally credited,
     * creates a single refund (debit) transaction, marks the row reversed and
     * clears the display cashback — while deliberately keeping the original
     * cashback transaction and the finalized snapshot for audit.
     */
    private function applyReversal(
        WalletService $wallet,
        AffiliateOrderItem $item,
        TikTokSyncResult $result,
    ): void {
        if (! $wallet->isCashbackCredited($item)) {
            $result->cashbackSkipped++;
            return;
        }

        $reversal = $wallet->reverseCashback($item, throwOnDuplicate: false);

        if ($reversal === null) {
            $result->cashbackSkipped++;
            Log::info('[TikTokOrderSync] reversal already applied (idempotent skip)', $this->auditContext($item, 'REVERSAL_SKIP'));
            return;
        }

        $item->affiliate_status = AffiliateOrderItem::STATUS_CANCELLED;
        $item->cashback_amount = 0.0;
        $item->markReversed();

        $result->cashbackReversed++;
        Log::info('[TikTokOrderSync] cashback reversal applied', $this->auditContext($item, 'REVERSAL'));
    }

    /**
     * Whether the current API state would describe the finalized row
     * differently (status or finalization belt) — i.e. a drift that the lock
     * must neutralize.
     */
    private function apiDriftsFromFinalized(TikTokOrder $order, AffiliateOrderItem $item): bool
    {
        return $order->mappedAffiliateStatus() !== $item->affiliate_status
            || ! $order->passesFinalizeBelt();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditContext(AffiliateOrderItem $item, string $action): array
    {
        return [
            'platform' => $item->platform,
            'order_id' => $item->order_id,
            'affiliate_order_item_id' => $item->id,
            'user_id' => $item->user_id,
            'username' => $item->username,
            'amount' => $item->cashback_amount,
            'action' => $action,
        ];
    }
}
