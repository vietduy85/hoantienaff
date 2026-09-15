<?php

namespace App\Services\ShopeeFood;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates a ShopeeFood affiliate deep link from ANY ShopeeFood source URL.
 *
 * Pipeline:
 *   1. normalize source URL
 *   2. resolve short URLs (/u/{code}, spf.shopee.vn shortlinks) → final URL
 *   3. extract restaurant_id from the final URL
 *   4. build a NEW affiliate URL using the configured Shopee affiliate ID
 *      and the user's own sub_id. Foreign affiliate attribution is dropped.
 *
 * Result: https://shopeefood.shopee.vn/now-food/shop/{restaurant_id}
 *         ?shareChannel=copy_link
 *         &utm_source=an_{affiliate_id}
 *         &utm_medium=affiliate_food
 *         &utm_campaign=-
 *         &utm_content={sub_id1}
 */
final class ShopeeFoodAffiliateLinkService
{
    public const AFFILIATE_HOST = 'shopeefood.shopee.vn';

    public const SHOPEEFOOD_HOST_SUFFIX = 'shopeefood.vn';

    public const SPF_HOST = 'spf.shopee.vn';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';

    private const MAX_REDIRECTS = 5;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly ShopeeFoodAffiliateResolver $affiliateResolver,
    ) {}

    public function generateAffiliateUrl(?string $originalUrl, string|int $subId1): ?string
    {
        $restaurantId = $this->resolveRestaurantId($originalUrl);

        if ($restaurantId === null) {
            Log::warning('[ShopeeFoodLink] Cannot generate affiliate URL', [
                'original_url' => $originalUrl,
                'reason'       => 'restaurant-id-unresolved',
            ]);

            return null;
        }

        return $this->buildAffiliateUrl($restaurantId, $subId1);
    }

    public function resolveRestaurantId(?string $url): ?string
    {
        $url = $this->normalizeUrl($url);

        if ($url === null) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        // Foreign Shopee affiliate shortlink (spf.shopee.vn) → resolve redirect chain
        // through the existing resolver, which only accepts ShopeeFood destinations.
        if ($host === self::SPF_HOST || str_ends_with($host, '.' . self::SPF_HOST)) {
            return $this->affiliateResolver->resolveRestaurantId($url);
        }

        if ($this->isShopeeFoodUrl($url)) {
            return $this->resolveShopeeFoodRestaurantId($url);
        }

        return null;
    }

    public function buildAffiliateUrl(string $restaurantId, string|int $subId1): ?string
    {
        if (preg_match('/^\d{3,12}$/', $restaurantId) !== 1) {
            Log::warning('[ShopeeFoodLink] Invalid restaurant id', [
                'restaurant_id' => $restaurantId,
            ]);

            return null;
        }

        $affiliateId = Setting::get('affiliate.direct.shopee_affiliate_id', '');

        if (! is_string($affiliateId) || trim($affiliateId) === '') {
            Log::warning('[ShopeeFoodLink] Missing affiliate id setting', [
                'setting' => 'affiliate.direct.shopee_affiliate_id',
            ]);

            return null;
        }

        $query = http_build_query([
            'shareChannel' => 'copy_link',
            'utm_source'   => 'an_' . $affiliateId,
            'utm_medium'   => 'affiliate_food',
            'utm_campaign' => '-',
            'utm_content'  => (string) $subId1,
        ]);

        return 'https://' . self::AFFILIATE_HOST . '/now-food/shop/' . $restaurantId . '?' . $query;
    }

    // ─── Internals ─────────────────────────────────────────────────────

    private function resolveShopeeFoodRestaurantId(string $url): ?string
    {
        // Direct URL already contains a numeric restaurant id → parse, no HTTP.
        $directId = ShopeeFoodUrlParser::restaurantId($url);

        if ($directId !== null) {
            return $directId;
        }

        // Short URL (/u/{code}) → MUST resolve the redirect chain first.
        // Never guess the id, never extract it from the code itself.
        $finalUrl = $this->resolveFinalUrl($url);

        if ($finalUrl === null) {
            Log::warning('[ShopeeFoodLink] Short URL resolve failed', [
                'url' => $url,
            ]);

            return null;
        }

        $finalId = ShopeeFoodUrlParser::restaurantId($finalUrl);

        if ($finalId === null) {
            Log::warning('[ShopeeFoodLink] Resolved URL has no restaurant id', [
                'url'       => $url,
                'final_url' => $finalUrl,
            ]);

            return null;
        }

        return $finalId;
    }

    private function resolveFinalUrl(string $url): ?string
    {
        $current = $url;

        for ($depth = 0; $depth <= self::MAX_REDIRECTS; $depth++) {
            try {
                $response = Http::withoutRedirecting()
                    ->acceptJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->get($current);
            } catch (ConnectionException $e) {
                Log::warning('[ShopeeFoodLink] Resolve timeout/connection failure', [
                    'url'   => $current,
                    'error' => $this->shortMessage($e),
                ]);

                return null;
            } catch (Throwable $e) {
                Log::warning('[ShopeeFoodLink] Resolve unexpected error', [
                    'url'   => $current,
                    'error' => $this->shortMessage($e),
                ]);

                return null;
            }

            $status = $response->status();

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                $location = $response->header('Location');

                if ($location === null || $location === '') {
                    return null;
                }

                $next = $this->absoluteUrl($current, $location);

                if ($next === null) {
                    return null;
                }

                $current = $next;

                continue;
            }

            if ($response->failed()) {
                Log::warning('[ShopeeFoodLink] Resolve HTTP error', [
                    'url'    => $current,
                    'status' => $status,
                ]);

                return null;
            }

            return $current;
        }

        return null;
    }

    private function isShopeeFoodUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === self::SHOPEEFOOD_HOST_SUFFIX
            || str_ends_with($host, '.' . self::SHOPEEFOOD_HOST_SUFFIX)
            || $host === self::AFFILIATE_HOST;
    }

    private function normalizeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (parse_url($url, PHP_URL_SCHEME) === null) {
            $url = 'https://' . $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function absoluteUrl(string $base, string $location): ?string
    {
        if (preg_match('/^https?:\/\//i', $location) === 1) {
            return filter_var($location, FILTER_VALIDATE_URL) ? $location : null;
        }

        if ($location === '') {
            return null;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? null;

        if ($host === null) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if ($location[0] === '/') {
            $nextPath = $location;
        } else {
            $dir = preg_replace('#/[^/]*$#', '', $path);
            $nextPath = ($dir !== '' ? $dir : '') . '/' . $location;
        }

        return $scheme . '://' . $host . $nextPath;
    }

    private function shortMessage(Throwable $e): string
    {
        return (new \ReflectionClass($e))->getShortName() . ': ' . substr($e->getMessage(), 0, 120);
    }
}