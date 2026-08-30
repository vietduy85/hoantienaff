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

        foreach ($orders as $order) {
            $result->ordersFetched++;

            if ($range !== null && !$this->inRange($order, $range)) {
                $result->skipped++;
                continue;
            }

            try {
                DB::transaction(function () use ($order, $normalizer, $wallet, $importBatch, $result, $creditWallet) {
                    $data = $normalizer->normalize($order, $importBatch);

                    $existing = AffiliateOrderItem::query()
                        ->where('platform', 'TikTok')
                        ->where('order_id', $data['order_id'])
                        ->where('item_id', $data['item_id'])
                        ->first();

                    if ($existing) {
                        $oldStatus = $existing->affiliate_status;

                        unset($data['first_imported_at']);

                        $existing->update($data);
                        $item = $existing->fresh();
                        $result->updated++;
                    } else {
                        $oldStatus = null;
                        $item = AffiliateOrderItem::create($data);
                        $result->inserted++;
                    }

                    if ($creditWallet) {
                        $this->applyWalletTransition($wallet, $item, $oldStatus, $result);
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
     * item after it has been upserted.
     *
     *         SETTLED (completed)  -> CREDIT cashback_amount (once)
     *         REFUNDED (cancelled) -> REVERSAL of the originally credited
     *                                 amount if a credit exists (once)
     *
     * Both directions are guarded by the DB unique constraint on
     * (reference_type, reference_id, type) plus the WalletService idempotency
     * checks, so re-syncing any number of times never double-credits or
     * double-reverses.
     */
    private function applyWalletTransition(
        WalletService $wallet,
        AffiliateOrderItem $item,
        ?string $oldStatus,
        TikTokSyncResult $result,
    ): void {
        if ($item->user_id === null) {
            return;
        }

        $completed = AffiliateOrderItem::STATUS_COMPLETED;
        $isCompleted = $item->affiliate_status === $completed;

        // REFUNDED / CANCELLED -> reverse a prior credit, exactly once.
        if (!$isCompleted && $item->affiliate_status === 'Đã hủy') {
            if ($wallet->isCashbackCredited($item) && !$wallet->isCashbackReversed($item)) {
                $reversal = $wallet->reverseCashback($item, throwOnDuplicate: false);
                if ($reversal !== null) {
                    $result->cashbackReversed++;
                    Log::info('[TikTokOrderSync] cashback reversal', $this->auditContext($item, 'REVERSAL'));
                }
            }
            return;
        }

        if (!$isCompleted) {
            return;
        }

        if ((float) $item->cashback_amount <= 0) {
            $result->cashbackSkipped++;
            return;
        }

        // Already credited -> never re-credit. Detect a commission change and
        // BLOCK (log only) rather than silently adjusting the wallet.
        if ($wallet->isCashbackCredited($item)) {
            $credited = $wallet->creditedAmount($item);
            $result->cashbackSkipped++;
            if ($credited !== null && (float) $credited !== (float) $item->cashback_amount) {
                Log::warning('[TikTokOrderSync] commission change BLOCKED (no auto-adjust)', [
                    'affiliate_order_item_id' => $item->id,
                    'order_id' => $item->order_id,
                    'credited_amount' => $credited,
                    'new_cashback' => $item->cashback_amount,
                ]);
            }
            return;
        }

        $transaction = $wallet->creditCashback($item, throwOnDuplicate: false);

        if ($transaction !== null) {
            $result->cashbackCredited++;
            Log::info('[TikTokOrderSync] cashback credited', $this->auditContext($item, 'CREDIT'));
        } else {
            $result->cashbackSkipped++;
        }
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
