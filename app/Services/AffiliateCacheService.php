<?php

namespace App\Services;

use App\Models\AffiliateCache;
use Illuminate\Support\Facades\Log;

class AffiliateCacheService
{
    private readonly string $cacheDate;

    private const SHORT_DOMAINS = ['s.shopee.vn', 'vn.shp.ee'];

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';

    private const MAX_REDIRS = 15;

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
        $start = microtime(true);

        $cache = AffiliateCache::where('item_id', $itemId)
            ->whereDate('cache_date', $this->cacheDate)
            ->first();

        $elapsed = (int) ((microtime(true) - $start) * 1000);

        Log::info('[CACHE-Timing] Cache Lookup', [
            'item_id' => $itemId,
            'elapsed_ms' => $elapsed,
        ]);

        if ($cache) {
            Log::info('[CACHE]', [
                'item_id' => $itemId,
                'status' => 'HIT',
                'cache_date' => $this->cacheDate,
            ]);

            Log::info('[CACHE-Timing] Cache Hit Return', [
                'item_id' => $itemId,
                'elapsed_ms' => $elapsed,
            ]);
        }

        return $cache;
    }

    public function logMiss(int $itemId): void
    {
        Log::info('[CACHE]', [
            'item_id' => $itemId,
            'status' => 'MISS',
            'cache_date' => $this->cacheDate,
        ]);
    }

    public function put(int $itemId, array $data): AffiliateCache
    {
        $start = microtime(true);
        $data['item_id'] = $itemId;
        $data['cache_date'] = $this->cacheDate;

        $result = AffiliateCache::updateOrCreate(
            ['item_id' => $itemId],
            $data
        );

        $elapsed = (int) ((microtime(true) - $start) * 1000);

        Log::info('[CACHE-Timing] Cache Save', [
            'item_id' => $itemId,
            'elapsed_ms' => $elapsed,
        ]);

        return $result;
    }

    public function updateAffiliateUrl(int $itemId, string $affiliateUrl): void
    {
        $start = microtime(true);

        AffiliateCache::where('item_id', $itemId)
            ->whereDate('cache_date', $this->cacheDate)
            ->update([
                'affiliate_url' => $affiliateUrl,
                'last_affiliate_created_at' => now('Asia/Ho_Chi_Minh'),
            ]);

        $elapsed = (int) ((microtime(true) - $start) * 1000);

        Log::info('[CACHE-Timing] Affiliate Cache Update', [
            'item_id' => $itemId,
            'elapsed_ms' => $elapsed,
        ]);
    }

    public function extractItemId(string $url): ?int
    {
        $ids = $this->extractProductIds($url);

        if ($ids !== null && $ids['item_id'] !== null) {
            return $ids['item_id'];
        }

        if ($this->isShortLink($url)) {
            $expanded = $this->expandShortUrl($url);
            if ($expanded === null) {
                return null;
            }
            $ids = $this->extractProductIds($expanded);
            return $ids['item_id'] ?? null;
        }

        return null;
    }

    private function isShortLink(string $url): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        foreach (self::SHORT_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function expandShortUrl(string $url): ?string
    {
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRS,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
            CURLOPT_NOBODY         => false,
        ]);

        curl_exec($ch);

        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($error !== '') {
            return null;
        }

        return filter_var($finalUrl, FILTER_VALIDATE_URL) ? $finalUrl : null;
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
