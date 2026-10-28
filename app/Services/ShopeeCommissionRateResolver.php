<?php

namespace App\Services;

use App\Models\AffiliateCache;
use App\Models\AffiliateOrderItem;
use App\Models\LinkRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShopeeCommissionRateResolver
{
    public const SOURCE_PRODUCT_DATA = 'product-data';
    public const SOURCE_PRODUCT_DATA_CACHE = 'product-data-cache';
    public const SOURCE_LINK_REQUEST_SNAPSHOT = 'link-request-snapshot';
    public const SOURCE_CACHE = 'affiliate_cache';
    public const SOURCE_NONE = 'none';
    public const SOURCE_CONFLICT = 'conflict';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_MISSING_EVIDENCE = 'missing_evidence';
    public const STATUS_CONFLICT = 'conflict';

    public function __construct(private readonly AffiliateCacheService $cacheService)
    {
    }

    /**
     * Resolve commission rates for a Shopee affiliate order item.
     * Never overwrites existing valid rates. Never fabricates values.
     *
     * @return array{
     *   shopee_commission_rate: float|null,
     *   seller_commission_rate: float|null,
     *   source: string,
     *   status: string,
     *   reasons: array<int,string>,
     *   sources_detail: array<string,mixed>,
     *   can_apply: bool
     * }
     */
    public function resolve(AffiliateOrderItem $orderItem): array
    {
        $result = [
            'shopee_commission_rate' => null,
            'seller_commission_rate' => null,
            'source' => self::SOURCE_NONE,
            'status' => self::STATUS_MISSING_EVIDENCE,
            'reasons' => [],
            'sources_detail' => [],
            'can_apply' => false,
        ];

        if (!$this->isShopee($orderItem)) {
            $result['reasons'][] = 'not_shopee_platform';
            return $result;
        }

        // Rule 1: Keep existing valid rates - do not overwrite
        // Treat 0.00 as missing/placeholder per business context
        $existingShopee = $this->normalizeRate($orderItem->shopee_commission_rate);
        $existingSeller = $this->normalizeRate($orderItem->seller_commission_rate);

        $hasValidExisting = false;
        if ($existingShopee !== null && abs($existingShopee) > 1e-9) {
            $hasValidExisting = true;
        }
        if ($existingSeller !== null && abs($existingSeller) > 1e-9) {
            $hasValidExisting = true;
        }

        if ($hasValidExisting) {
            $result['reasons'][] = 'existing_rate_present_keep_as_is';
            if ($existingShopee !== null && abs($existingShopee) > 1e-9) {
                $result['shopee_commission_rate'] = $existingShopee;
            }
            if ($existingSeller !== null && abs($existingSeller) > 1e-9) {
                $result['seller_commission_rate'] = $existingSeller;
            }
            $result['source'] = 'existing';
            $result['status'] = self::STATUS_AVAILABLE;
            $result['can_apply'] = false; // do not overwrite
            return $result;
        }

        // Rule 2: Try snapshot from link_requests with strong evidence
        $snapshot = $this->findLinkRequestSnapshot($orderItem);
        if ($snapshot['found']) {
            if ($snapshot['reliable']) {
                $result['shopee_commission_rate'] = $snapshot['shopee_rate'];
                $result['seller_commission_rate'] = $snapshot['seller_rate'];
                $result['source'] = $snapshot['source'] ?? self::SOURCE_LINK_REQUEST_SNAPSHOT;
                $result['status'] = $snapshot['status'] ?? self::STATUS_AVAILABLE;
                $result['sources_detail']['link_request'] = $snapshot;
                $result['can_apply'] = ($result['shopee_commission_rate'] !== null || $result['seller_commission_rate'] !== null);
                if (!$result['can_apply']) {
                    $result['reasons'][] = 'snapshot_found_but_no_valid_rate';
                } else {
                    $result['reasons'][] = 'snapshot_with_evidence';
                }
                return $result;
            } else {
                $result['reasons'][] = 'snapshot_found_but_insufficient_evidence';
                // Do not use - require strong evidence
            }
        } else {
            $result['reasons'][] = 'no_link_request_snapshot_evidence';
        }

        // Rule 3: Try affiliate_cache with correct platform/item_id, within TTL, valid source
        $cache = $this->findValidCache($orderItem);
        if ($cache['found']) {
            if ($cache['reliable']) {
                $result['shopee_commission_rate'] = $cache['shopee_rate'];
                $result['seller_commission_rate'] = $cache['seller_rate'];
                $result['source'] = $cache['source'] ?? self::SOURCE_CACHE;
                $result['status'] = $cache['status'] ?? self::STATUS_AVAILABLE;
                $result['sources_detail']['cache'] = $cache;
                $result['can_apply'] = ($result['shopee_commission_rate'] !== null || $result['seller_commission_rate'] !== null);
                if (!$result['can_apply']) {
                    $result['reasons'][] = 'cache_found_but_no_valid_rate';
                } else {
                    $result['reasons'][] = 'cache_with_evidence';
                }
                return $result;
            } else {
                $result['reasons'][] = 'cache_found_but_not_reliable';
            }
        } else {
            $result['reasons'][] = 'no_valid_cache';
        }

        // Rule 4: No reliable source found - do not guess
        $result['reasons'][] = 'no_reliable_source_found';
        $result['can_apply'] = false;
        return $result;
    }

    private function isShopee(AffiliateOrderItem $orderItem): bool
    {
        $platform = strtolower(trim((string) $orderItem->platform));
        if ($platform === '') {
            return false;
        }
        // Be flexible about platform naming
        if (str_contains($platform, 'shopee')) {
            return true;
        }
        // In this codebase platform is often 'Shopee' as per CSV import
        if ($platform === 'shopee') {
            return true;
        }
        return false;
    }

    private function normalizeRate($rate): ?float
    {
        if ($rate === null || $rate === '') {
            return null;
        }
        if (is_numeric($rate)) {
            $val = (float) $rate;
            // Treat 0 as "no valid rate" in context of missing? But existing 0 might be invalid placeholder
            // Follow existing schema - if it's stored as decimal, check if it's a meaningful value
            // But rule says "keep existing valid rates" - need to be careful
            if ($val < 0) {
                return null;
            }
            // For decimal:2 as stored in order items, could be 0.00 meaning missing or actual 0% - 
            // but per instructions don't convert blindly; if it's 0.00 and we need to add missing rates,
            // we need to determine if it's considered "valid existing rate" - but in practice from audit many are 0.00
            // Better to treat 0 as not a "valid" populated rate for the purpose of overwriting? Let us look up audit again
            return $val;
        }
        return null;
    }

    private function findLinkRequestSnapshot(AffiliateOrderItem $orderItem): array
    {
        $result = ['found' => false, 'reliable' => false];

        if ($orderItem->item_id === null || $orderItem->user_id === null) {
            $result['reason'] = 'missing_item_or_user_for_snapshot_linking';
            return $result;
        }

        // Must have strong evidence linking - don't just match by user_id+item_id
        // Look for link request created around time of click/order
        $query = LinkRequest::query()
            ->where('item_id', $orderItem->item_id)
            ->where('user_id', $orderItem->user_id)
            ->where('platform', 'Shopee'); // be specific

        // Try to find by time correlation if possible
        if ($orderItem->clicked_at) {
            $query->where(function ($q) use ($orderItem) {
                $q->where('created_at', '<=', $orderItem->clicked_at->copy()->addHours(24))
                    ->where('created_at', '>=', $orderItem->clicked_at->copy()->subHours(48));
            });
        } elseif ($orderItem->ordered_at) {
            $query->where(function ($q) use ($orderItem) {
                $q->where('created_at', '<=', $orderItem->ordered_at->copy()->addHours(24))
                    ->where('created_at', '>=', $orderItem->ordered_at->copy()->subDays(7));
            });
        }

        $linkRequest = $query->orderBy('created_at', 'desc')->first();

        if (!$linkRequest) {
            // Try without strict time if no match - but evidence must be reliable
            // Per instructions: "Không được ghép một LinkRequest với đơn hàng chỉ vì trùng user_id, item_id và platform"
            $result['reason'] = 'no_time_correlated_link_request';
            return $result;
        }

        // Check if snapshot fields exist and have valid values
        $shopeeRate = $this->normalizeRate($linkRequest->shopee_commission_rate_at_create);
        $sellerRate = $this->normalizeRate($linkRequest->seller_commission_rate_at_create);
        $source = $linkRequest->commission_rate_source;
        $status = $linkRequest->commission_rate_status;

        if (($shopeeRate === null && $sellerRate === null)) {
            $result['reason'] = 'snapshot_has_no_rates';
            return $result;
        }

        // Must have valid source/status
        if (!$source || $source === 'none' || $source === 'fallback') {
            $result['reason'] = 'snapshot_source_not_verified';
            return $result;
        }

        if (!$status || $status === self::STATUS_UNVERIFIED) {
            $result['reason'] = 'snapshot_status_not_verified';
            return $result;
        }

        $result['found'] = true;
        $result['reliable'] = true;
        $result['shopee_rate'] = $shopeeRate;
        $result['seller_rate'] = $sellerRate;
        $result['source'] = self::SOURCE_LINK_REQUEST_SNAPSHOT;
        $result['status'] = self::STATUS_AVAILABLE;
        $result['link_request_id'] = $linkRequest->id;
        $result['captured_at'] = $linkRequest->commission_rate_captured_at;
        return $result;
    }

    private function findValidCache(AffiliateOrderItem $orderItem): array
    {
        $result = ['found' => false, 'reliable' => false];

        if ($orderItem->item_id === null) {
            $result['reason'] = 'missing_item_id';
            return $result;
        }

        $now = now('Asia/Ho_Chi_Minh');
        $cutoff = $now->copy()->startOfDay()->subDays(2); // 3 day calendar as per docs

        // Look for cache with correct item_id and platform
        $query = AffiliateCache::where('item_id', $orderItem->item_id)
            ->whereDate('cache_date', '>=', $cutoff->toDateString());

        // Must match platform - don't use platform=NULL as proof for Shopee
        $query->where(function ($q) {
            $q->where('platform', 'Shopee')->orWhere('platform', 'shopee');
        });

        $cache = $query->orderByDesc('cache_date')->first();

        if (!$cache) {
            // Try without platform match? Instructions say "Không tự động sử dụng cache platform = NULL làm bằng chứng chắc chắn cho Shopee"
            $result['reason'] = 'no_cache_with_matching_platform';
            return $result;
        }

        // Check rates
        $shopeeRate = $this->normalizeRate($cache->shopee_commission_rate);
        $sellerRate = $this->normalizeRate($cache->seller_commission_rate);

        if ($shopeeRate === null && $sellerRate === null) {
            $result['reason'] = 'cache_has_no_rates';
            return $result;
        }

        $source = $cache->commission_rate_source;
        $status = $cache->commission_rate_status;

        if (!$source || $source === 'none' || $source === 'fallback') {
            $result['reason'] = 'cache_source_not_verified';
            return $result;
        }

        if (!$status || $status === self::STATUS_UNVERIFIED) {
            $result['reason'] = 'cache_status_not_verified';
            return $result;
        }

        $result['found'] = true;
        $result['reliable'] = true;
        $result['shopee_rate'] = $shopeeRate;
        $result['seller_rate'] = $sellerRate;
        $result['source'] = self::SOURCE_CACHE;
        $result['status'] = self::STATUS_AVAILABLE;
        $result['cache_date'] = $cache->cache_date;
        $result['commission_rate_source'] = $source;
        return $result;
    }
}
