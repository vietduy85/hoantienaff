<?php

namespace App\Services\ShopeeFood;

use App\Services\ShopeeFood\DTOs\ShopeeFoodCheckout;
use App\Services\ShopeeFood\DTOs\ShopeeFoodOrderItem;
use App\Models\AffiliateOrderItem;
use App\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ShopeeFood order sync.
 *
 * Fetches every page of checkouts (page_size <= 100 bounded by total_count),
 * normalises each checkout -> orders -> items, resolves users, maps status,
 * validates commission and ESTIMATES cashback. persist=false only reports what
 * WOULD happen (dry-run); persist=true upserts rows keyed by
 * (platform, shopee_food_line_key) inside a transaction and applies idempotent
 * wallet transitions (no double-credit on re-sync).
 *
 * Line identity is CLOSED: business key == (checkout_id, promotion_id) only.
 * A line with a missing/empty promotion_id is marked INVALID — never guessed
 * from item_id / item_name — counts as an error, is never persisted, and the
 * remaining lines keep processing.
 */
class ShopeeFoodOrderSyncService
{
    private const PAGE_SIZE = 100;
    private const MAX_PAGES = 1000;
    private const COMMISSION_MATCH_TOLERANCE = 0.5;

    private const STATUS_PENDING = 'Đang xử lý';
    private const STATUS_COMPLETED = 'Hoàn thành';
    private const STATUS_CANCELLED = 'Đã hủy';

    public function __construct(
        private readonly ShopeeFoodClient $client,
        private readonly ?ShopeeFoodOrderNormalizer $normalizer = null,
        private readonly ?ShopeeFoodUserResolver $resolver = null,
        private readonly ?ShopeeFoodCashbackCalculator $cashbackCalculator = null,
        private readonly ?WalletService $walletService = null,
    ) {}

    public function run(
        ?string $from = null,
        ?string $to = null,
        bool $persist = false,
        bool $creditWallet = true,
        ?callable $onProgress = null,
    ): ShopeeFoodSyncResult {
        $result = new ShopeeFoodSyncResult(startedAt: Carbon::now());

        $this->assertReady();

        $resolver = $this->resolver ?? app(ShopeeFoodUserResolver::class);
        $cashback = $this->cashbackCalculator ?? app(ShopeeFoodCashbackCalculator::class);
        $wallet = $this->walletService ?? app(WalletService::class);

        $checkouts = $this->fetchAllCheckouts($from, $to);
        $importBatch = Carbon::now()->format('Ymd_His');

        foreach ($checkouts as $checkout) {
            $result->checkoutsFetched++;

            $resolved = $resolver->resolveWithDetail($checkout);
            $status = $this->mapStatus($checkout->getConversionStatus());

            $checkoutCommissionSum = 0.0;

            foreach ($checkout->getOrders() as $order) {
                $result->ordersFetched++;

                foreach ($order->getItems() as $item) {
                    $result->itemsFetched++;

                    $line = $this->buildLine($checkout, $item, $resolved, $status, $cashback, $importBatch);

                    match ($status) {
                        self::STATUS_COMPLETED => $result->completed++,
                        self::STATUS_CANCELLED => $result->cancelled++,
                        default => $result->pending++,
                    };

                    if ($line['user_id'] === null) {
                        $result->unresolvedUsers++;
                    }

                    // INVALID lines (missing/empty promotion_id) are reported,
                    // counted as errors, and NEVER persisted or wallet-touched.
                    if ($line['invalid']) {
                        $result->errors++;
                        $result->invalidLines++;
                        $result->errorsDetail[] = sprintf(
                            'Checkout %s: line thiếu promotion_id (item_id=%s) — INVALID, không fake key, không persist.',
                            $checkout->getCheckoutId(),
                            $line['item_id'] ?? '?',
                        );
                        $result->lines[] = $line;
                        continue;
                    }

                    $checkoutCommissionSum += $line['item_commission'];
                    $result->totalCommission += $line['item_commission'];

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
                        $this->persistLine(
                            $line,
                            $wallet,
                            $creditWallet,
                            $result,
                        );
                    }

                    if ($onProgress !== null) {
                        $onProgress($checkout, $line, $result);
                    }
                }
            }

            $this->validateCheckoutCommission($checkout, $checkoutCommissionSum, $result);
        }

        $result->finishedAt = Carbon::now();

        return $result;
    }

    // ------------------------------------------------------------------
    //  Readiness guards (fast-fail before any HTTP / persistence)
    // ------------------------------------------------------------------

    private function assertReady(): void
    {
        if ($this->client->getCookie() === null || $this->client->getCookie() === '') {
            throw new ShopeeFoodException(
                'SHOPEEFOOD_COOKIE chưa được cấu hình (config services.shopeefood.cookie) — không thể gọi API ShopeeFood. Đồng bộ TikTok vẫn chạy bình thường.',
                kind: 'config_missing',
            );
        }

        if (! Schema::hasColumn('affiliate_order_items', 'shopee_food_line_key')) {
            throw new ShopeeFoodException(
                'Cấu trúc DB ShopeeFood chưa sẵn sàng — migration add_shopeefood_fields_to_affiliate_order_items chưa được chạy. Đồng bộ TikTok vẫn chạy bình thường.',
                kind: 'migration_pending',
            );
        }
    }

    // ------------------------------------------------------------------
    //  Pagination (bounded by total_count, guarded by MAX_PAGES)
    // ------------------------------------------------------------------

    /**
     * @return ShopeeFoodCheckout[]
     */
    private function fetchAllCheckouts(?string $from, ?string $to): array
    {
        $checkouts = [];
        $page = 1;

        do {
            $response = $this->client->getOrders($from, $to, page: $page, pageSize: self::PAGE_SIZE);

            $batch = $response->getCheckouts();
            $totalCount = $response->getTotalCount();

            foreach ($batch as $checkout) {
                $checkouts[] = $checkout;
            }

            $page++;

            if ($page > self::MAX_PAGES && count($checkouts) < $totalCount) {
                throw new ShopeeFoodException(
                    'Vượt quá ' . self::MAX_PAGES . ' trang ShopeeFood — dừng để tránh vòng lặp vô hạn.',
                    kind: 'max_pages_reached',
                );
            }

            $hasMore = count($batch) > 0 && count($checkouts) < $totalCount;
        } while ($hasMore);

        return $checkouts;
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
        ShopeeFoodCheckout $checkout,
        ShopeeFoodOrderItem $item,
        array $resolved,
        string $status,
        ShopeeFoodCashbackCalculator $cashback,
        string $importBatch,
    ): array {
        $normalizer = $this->normalizer ?? app(ShopeeFoodOrderNormalizer::class);

        $itemCommission = (float) ($item->getItemCommission() ?? 0);
        $actualAmount = (float) ($item->getActualAmount() ?? 0);
        $ratePercent = $item->getPlatformCommissionRate();
        $gross = $normalizer->grossCommission($actualAmount, $ratePercent);

        $isCompleted = $status === self::STATUS_COMPLETED;
        $cb = $cashback->calculate($itemCommission, $actualAmount, $isCompleted);

        $lineKey = $this->lineKey($checkout, $item);

        return [
            'checkout_id'               => $checkout->getCheckoutId(),
            'line_key'                  => $lineKey,
            'invalid'                   => $lineKey === null,
            'promotion_id'              => $item->getPromotionId(),
            'item_id'                   => $item->getItemId(),
            'item_name'                 => $item->getItemName(),
            'shop_name'                 => $item->getShopName(),
            'status'                    => $status,
            'conversion_status'         => $checkout->getConversionStatus(),
            'affiliate_item_status'     => $item->getAffiliateItemStatus(),
            'user_id'                   => $resolved['user_id'],
            'username'                  => $resolved['username'],
            'matched_by'                => $resolved['matched_by'],
            'item_commission'           => $itemCommission,
            'gross_commission'          => $gross ?? 0.0,
            'actual_amount'             => $actualAmount,
            'rate_percent'              => $ratePercent ?? 0.0,
            'is_shopee_capped'          => $checkout->isShopeeCapped(),
            'checkout_cap'              => $checkout->getCheckoutCap(),
            'affiliate_net_commission'  => $checkout->getAffiliateNetCommission(),
            'cashback_rate'             => $cb['cashback_rate'],
            'cashback_amount'           => $cb['cashback_amount'],
            'would'                     => 'insert',
            'row'                       => $lineKey === null
                ? null
                : $this->buildRow($checkout, $item, $resolved, $status, $cb, $importBatch, $lineKey),
        ];
    }

    /**
     * Business key: (checkout_id, promotion_id) ONLY — there is deliberately NO
     * fallback. item_id alone is NOT a valid key (it can repeat within one
     * checkout for different variants); a missing/empty promotion_id yields
     * null, which marks the line INVALID instead of fabricating an identity.
     */
    private function lineKey(ShopeeFoodCheckout $checkout, ShopeeFoodOrderItem $item): ?string
    {
        $promotionId = $item->getPromotionId();

        if ($promotionId === null || $promotionId === '') {
            return null;
        }

        return $checkout->getCheckoutId() . ':' . $promotionId;
    }

    /**
     * Build the DB-ready row (persist=false only reports it; persist=true uses it).
     *
     * @param  array{user_id: int|null, username: string|null, matched_by: string}  $resolved
     * @param  array{cashback_rate: float, cashback_amount: float}  $cashback
     *
     * @return array<string, mixed>
     */
    private function buildRow(
        ShopeeFoodCheckout $checkout,
        ShopeeFoodOrderItem $item,
        array $resolved,
        string $status,
        array $cashback,
        string $importBatch,
        string $lineKey,
    ): array {
        $now = Carbon::now()->toDateTimeString();

        return [
            'order_id'                   => $checkout->getCheckoutId(),
            'order_status'               => $status,
            'checkout_id'                => $checkout->getCheckoutId(),
            'content_id'                 => $checkout->getContentId(),
            'ordered_at'                 => $checkout->getPurchasedAt(),
            'completed_at'               => $checkout->getCompletedAt(),
            'clicked_at'                 => $checkout->getClickedAt(),
            'shop_name'                  => $item->getShopName() ?? '',
            'shop_id'                    => (int) ($item->getShopId() ?? 0),
            'item_id'                    => null,
            'item_name'                  => $item->getItemName() ?? '',
            'model_id'                   => 0,
            'item_price'                 => $item->getItemPrice() ?? 0,
            'quantity'                   => $item->getQuantity() ?? 0,
            'order_amount'               => $item->getActualAmount() ?? 0,
            'refund_amount'              => $item->getRefundedAmount() ?? 0,
            'commission_type'            => '',
            'campaign_partner'           => null,
            'shopee_commission_rate'     => $item->getPlatformCommissionRate() ?? 0,
            'shopee_commission'          => $item->getItemCommission() ?? 0,
            'seller_commission_rate'     => 0,
            'xtra_commission'            => 0,
            'total_product_commission'   => $item->getItemCommission() ?? 0,
            'order_commission_shopee'    => 0,
            'order_commission_seller'    => 0,
            'total_order_commission'     => $checkout->getAffiliateNetCommission() ?? 0,
            'mcn_name'                   => null,
            'mcn_contract_code'          => null,
            'mcn_management_fee_rate'    => 0,
            'mcn_management_fee'         => 0,
            'agreed_commission_rate'     => $item->getPlatformCommissionRate() ?? 0,
            'net_commission'             => $item->getItemCommission() ?? 0,
            'affiliate_status'           => $status,
            'sub_id1'                    => $checkout->getSubId1(),
            'sub_id2'                    => $checkout->getSubId2(),
            'sub_id3'                    => $checkout->getSubId3(),
            'sub_id4'                    => $checkout->getSubId4(),
            'sub_id5'                    => $checkout->getSubId5(),
            'channel'                    => $checkout->getUtmFormat(),
            'platform'                   => 'ShopeeFood',
            'user_id'                    => $resolved['user_id'],
            'username'                   => $resolved['username'],
            'cashback_rate'              => $cashback['cashback_rate'],
            'cashback_amount'            => $cashback['cashback_amount'],
            'import_batch'               => $importBatch,
            'source_file'                => 'shopeefood-api',
            'first_imported_at'          => $now,
            'last_shopeefood_sync_at'    => $now,
            'locked_at'                  => in_array($checkout->getConversionStatus(), [2, 3], true) ? $now : null,
            'shopee_food_line_key'       => $lineKey,
        ];
    }

    private function mapStatus(?int $conversionStatus): string
    {
        return match ($conversionStatus) {
            2 => self::STATUS_COMPLETED,
            3 => self::STATUS_CANCELLED,
            default => self::STATUS_PENDING,
        };
    }

    // ------------------------------------------------------------------
    //  Commission validation (per checkout)
    // ------------------------------------------------------------------

    private function validateCheckoutCommission(ShopeeFoodCheckout $checkout, float $sum, ShopeeFoodSyncResult $result): void
    {
        $net = $checkout->getAffiliateNetCommission();

        if ($net === null) {
            return;
        }

        if (abs($sum - $net) > self::COMMISSION_MATCH_TOLERANCE) {
            $result->commissionMismatches++;
            $result->errorsDetail[] = sprintf(
                'Checkout %s: SUM(item_commission)=%.2f khác affiliate_net_commission=%.2f',
                $checkout->getCheckoutId(),
                $sum,
                $net,
            );
            Log::warning('[ShopeeFoodOrderSync] commission mismatch', [
                'checkout_id'                  => $checkout->getCheckoutId(),
                'sum_item_commission'          => round($sum, 2),
                'affiliate_net_commission'     => round($net, 2),
            ]);
        }
    }

    // ------------------------------------------------------------------
    //  Existing-key lookup (dry-run reports insert vs update)
    // ------------------------------------------------------------------

    private function lineExists(string $lineKey): bool
    {
        return AffiliateOrderItem::query()
            ->where('platform', 'ShopeeFood')
            ->where('shopee_food_line_key', $lineKey)
            ->exists();
    }

    // ------------------------------------------------------------------
    //  Phase 2: persistence + wallet path (persist=true)
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $line
     */
    private function persistLine(array $line, WalletService $wallet, bool $creditWallet, ShopeeFoodSyncResult $result): void
    {
        try {
            DB::transaction(function () use ($line, $wallet, $creditWallet, $result): void {
                $row = $line['row'];
                $existing = AffiliateOrderItem::query()
                    ->where('platform', 'ShopeeFood')
                    ->where('shopee_food_line_key', $line['line_key'])
                    ->first();

                $oldStatus = $existing?->affiliate_status;

                if ($existing !== null) {
                    unset($row['first_imported_at']);
                    unset($row['created_at']);
                    $existing->update($row);
                    $item = $existing->fresh();
                    $result->updated++;
                } else {
                    $item = AffiliateOrderItem::create($row);
                    $result->inserted++;
                }

                if ($creditWallet) {
                    $this->applyWalletTransition($wallet, $item, $oldStatus, $result);
                }
            });
        } catch (\Throwable $e) {
            $result->errors++;
            $result->errorsDetail[] = sprintf('Line %s: %s', $line['line_key'], $e->getMessage());
            Log::error('[ShopeeFoodOrderSync] persist failed', [
                'checkout_id' => $line['checkout_id'],
                'line_key'    => $line['line_key'],
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function applyWalletTransition(WalletService $wallet, AffiliateOrderItem $item, ?string $oldStatus, ShopeeFoodSyncResult $result): void
    {
        if ($item->user_id === null) {
            return;
        }

        if ($item->affiliate_status === self::STATUS_CANCELLED) {
            if ($wallet->isCashbackCredited($item) && ! $wallet->isCashbackReversed($item)) {
                $reversal = $wallet->reverseCashback($item, throwOnDuplicate: false);
                if ($reversal !== null) {
                    $result->cashbackReversed++;
                }
            }

            return;
        }

        if ($item->affiliate_status !== self::STATUS_COMPLETED) {
            return;
        }

        if ((float) $item->cashback_amount <= 0) {
            return;
        }

        if ($wallet->isCashbackCredited($item)) {
            $credited = $wallet->creditedAmount($item);
            $result->cashbackSkipped++;
            if ($credited !== null && (float) $credited !== (float) $item->cashback_amount) {
                Log::warning('[ShopeeFoodOrderSync] commission change BLOCKED (no auto-adjust)', [
                    'line_key'   => $item->shopee_food_line_key,
                    'credited'   => (float) $credited,
                    'new_amount' => (float) $item->cashback_amount,
                ]);
            }

            return;
        }

        $transaction = $wallet->creditCashback($item, throwOnDuplicate: false);
        if ($transaction !== null) {
            $result->cashbackCredited++;
        }
    }
}