<?php

namespace App\Services;

use App\Models\AffiliateCache;
use Illuminate\Support\Facades\Log;

class AffiliateCacheService
{
    private readonly string $cacheDate;
    private readonly \Illuminate\Support\Carbon $now;

    public function __construct()
    {
        $this->now = now('Asia/Ho_Chi_Minh');
        $this->cacheDate = $this->now->toDateString();
    }

    public function getCacheDate(): string
    {
        return $this->cacheDate;
    }

    public function get(int $itemId, ?string $platform = null): ?AffiliateCache
    {
        $cutoff = $this->now->copy()->startOfDay()->subDays(2);
        $query = AffiliateCache::where('item_id', $itemId)
            ->whereDate('cache_date', '>=', $cutoff->toDateString());
        if ($platform !== null && $platform !== '') {
            $query->where(function ($q) use ($platform) {
                $q->where('platform', $platform)->orWhereNull('platform');
            });
        }
        $cache = $query->orderByRaw("CASE WHEN platform IS NULL THEN 1 ELSE 0 END")
            ->orderByDesc('cache_date')
            ->first();

        if ($cache !== null && $this->isEmptyPlaceholder($cache)) {
            $cache = null;
        }

        if (config('app.affiliate_timing')) {
            if ($cache) {
                Log::info('[CACHE]', [
                    'item_id' => $itemId,
                    'status' => 'HIT',
                    'cache_date' => $cache->cache_date,
                    'platform' => $cache->platform,
                ]);
            }
        }

        return $cache;
    }

    private function isEmptyPlaceholder(AffiliateCache $cache): bool
    {
        return $cache->product_name === null
            && $cache->product_price === null
            && $cache->estimated_cashback === null
            && $cache->user_estimated_cashback === null
            && $cache->shop_id === null;
    }

    public function logMiss(int $itemId): void
    {
        if (config('app.affiliate_timing')) {
            Log::info('[CACHE]', [
                'item_id' => $itemId,
                'status' => 'MISS',
                'cache_date' => $this->cacheDate,
            ]);
        }
    }

    public function put(int $itemId, array $data): AffiliateCache
    {
        $data['item_id'] = $itemId;
        $data['cache_date'] = $this->cacheDate;

        $lookup = ['item_id' => $itemId, 'cache_date' => $this->cacheDate];

        $existing = AffiliateCache::where($lookup)->first();

        if ($existing !== null) {
            AffiliateCache::where($lookup)->update($data);

            return $existing->refresh();
        }

        return AffiliateCache::create($data);
    }

    public function updateAffiliateUrl(int $itemId, string $affiliateUrl): void
    {
        AffiliateCache::where('item_id', $itemId)
            ->whereDate('cache_date', $this->cacheDate)
            ->update([
                'affiliate_url' => $affiliateUrl,
                'last_affiliate_created_at' => now('Asia/Ho_Chi_Minh'),
            ]);
    }

    public function extractItemId(string $url): ?int
    {
        $ids = $this->extractProductIds($url);

        return $ids['item_id'] ?? null;
    }

    private function extractProductIds(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $query = parse_url($url, PHP_URL_QUERY) ?? '';

        parse_str($query, $params);

        if (isset($params['item_id']) && ctype_digit((string) $params['item_id'])) {
            return ['item_id' => (int) $params['item_id']];
        }

        if (isset($params['itemId']) && ctype_digit((string) $params['itemId'])) {
            return ['item_id' => (int) $params['itemId']];
        }

        if (preg_match('#/product/(\d+)/(\d+)#', $path, $m)) {
            return ['item_id' => (int) $m[2]];
        }

        if (preg_match('#/opaanlp/(\d+)/(\d+)#', $path, $m)) {
            return ['item_id' => (int) $m[2]];
        }

        if (preg_match('#\-i\.(\d+)\.(\d+)#', $path, $m)) {
            return ['item_id' => (int) $m[2]];
        }

        return null;
    }
}
