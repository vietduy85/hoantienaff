<?php

namespace App\Services;

use App\Models\AffiliateCache;
use Illuminate\Support\Facades\Log;

class AffiliateCacheService
{
    private readonly string $cacheDate;

    public function __construct()
    {
        $this->cacheDate = now('Asia/Ho_Chi_Minh')->toDateString();
    }

    public function getCacheDate(): string
    {
        return $this->cacheDate;
    }

    public function get(int $itemId): ?AffiliateCache
    {
        $cache = AffiliateCache::where('item_id', $itemId)
            ->whereDate('cache_date', $this->cacheDate)
            ->first();

        if (config('app.affiliate_timing')) {
            if ($cache) {
                Log::info('[CACHE]', [
                    'item_id' => $itemId,
                    'status' => 'HIT',
                    'cache_date' => $this->cacheDate,
                ]);
            }
        }

        return $cache;
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

        $result = AffiliateCache::updateOrCreate(
            ['item_id' => $itemId, 'cache_date' => $this->cacheDate],
            $data
        );

        return $result;
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
