<?php

namespace App\Services\ShopeeFood;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

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

    /**
     * @return array{restaurant_id: string|null, final_url: string|null}
     */
    public function resolvePipeline(?string $url): array
    {
        $restaurantId = null;
        $finalUrl = null;

        $url = $this->normalizeUrl($url);

        if ($url !== null) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            // Foreign Shopee affiliate shortlink (spf.shopee.vn) → resolve the
            // redirect chain through the resolver, which only accepts ShopeeFood
            // destinations. The resolved id is cached under shopeefood:spf:{hash}.
            if ($host === self::SPF_HOST || str_ends_with($host, '.' . self::SPF_HOST)) {
                $restaurantId = $this->affiliateResolver->resolveRestaurantId($url);
                $finalUrl = $this->affiliateResolver->resolveFinalUrl($url);

                if ($restaurantId === null && $finalUrl !== null) {
                    $restaurantId = ShopeeFoodUrlParser::restaurantId($finalUrl);
                }

                return [
                    'restaurant_id' => $restaurantId,
                    'final_url'     => $finalUrl,
                ];
            }

            if (ShopeeFoodUrlParser::isShopeeFoodUrl($url)) {
                return [
                    'restaurant_id' => $this->resolveShopeeFoodRestaurantId($url, $finalUrl),
                    'final_url'     => $finalUrl,
                ];
            }
        }

        return [
            'restaurant_id' => null,
            'final_url'     => null,
        ];
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

        if (ShopeeFoodUrlParser::isShopeeFoodUrl($url)) {
            $finalUrl = null;

            return $this->resolveShopeeFoodRestaurantId($url, $finalUrl);
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

    /**
     * @param string|null $finalUrl by-ref result of the follow, reused by resolvePipeline
     */
    private function resolveShopeeFoodRestaurantId(string $url, ?string &$finalUrl): ?string
    {
        // Direct URL already contains a numeric restaurant id → parse, no HTTP.
        $directId = ShopeeFoodUrlParser::restaurantId($url);

        if ($directId !== null) {
            $finalUrl = $url;

            return $directId;
        }

        // Short URL (/u/{code}) → MUST resolve the redirect chain first.
        // Never guess the id, never extract it from the code itself.
        $resolvedUrl = $this->affiliateResolver->resolveFinalUrl($url);
        $finalUrl = $resolvedUrl;

        if ($resolvedUrl === null) {
            Log::warning('[ShopeeFoodLink] Short URL resolve failed', [
                'url' => $url,
            ]);

            return null;
        }

        $finalId = ShopeeFoodUrlParser::restaurantId($resolvedUrl);

        if ($finalId === null) {
            Log::warning('[ShopeeFoodLink] Resolved URL has no restaurant id', [
                'url'       => $url,
                'final_url' => $resolvedUrl,
            ]);

            return null;
        }

        return $finalId;
    }

    private function normalizeUrl(?string $url): ?string
    {
        return ShopeeFoodUrlParser::normalize($url);
    }
}