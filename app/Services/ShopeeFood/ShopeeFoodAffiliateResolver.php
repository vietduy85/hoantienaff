<?php

namespace App\Services\ShopeeFood;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ShopeeFoodAffiliateResolver
{
    public const CACHE_TTL_SECONDS = 43200;

    private const SPF_HOST = 'spf.shopee.vn';

    private const TARGET_HOST_SUFFIX = 'shopeefood.vn';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';

    private const MAX_REDIRECTS = 5;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function resolveRestaurantId(?string $affiliateUrl): ?string
    {
        $affiliateUrl = $this->normalizeUrl($affiliateUrl);

        if ($affiliateUrl === null || ! $this->isSpfUrl($affiliateUrl)) {
            return null;
        }

        $hash = $this->shortHash($affiliateUrl);

        if ($hash === null) {
            return null;
        }

        $cacheKey = 'shopeefood:spf:' . $hash;

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $restaurantId = $this->resolve($affiliateUrl, 0);

        if ($restaurantId !== null) {
            Cache::put($cacheKey, $restaurantId, self::CACHE_TTL_SECONDS);
        }

        return $restaurantId;
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

    private function isSpfUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === self::SPF_HOST || str_ends_with($host, '.' . self::SPF_HOST);
    }

    private function shortHash(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $hash = trim(basename($path), '/');

        if ($hash === '' || preg_match('/^[A-Za-z0-9_-]+$/', $hash) !== 1) {
            return null;
        }

        return $hash;
    }

    private function resolve(string $url, int $depth): ?string
    {
        if ($depth > self::MAX_REDIRECTS) {
            Log::warning('[ShopeeFoodResolver] Redirection limit reached', [
                'affiliate_url' => $url,
            ]);

            return null;
        }

        $response = null;

        try {
            $response = Http::withoutRedirecting()
                ->acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('[ShopeeFoodResolver] Resolve timeout/connection failure', [
                'affiliate_url' => $url,
                'error'         => $this->shortMessage($e),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('[ShopeeFoodResolver] Resolve unexpected error', [
                'affiliate_url' => $url,
                'error'         => $this->shortMessage($e),
            ]);

            return null;
        }

        $status = $response->status();

        if (in_array($status, self::REDIRECT_STATUSES, true)) {
            $location = $response->header('Location');

            if ($location === null || $location === '') {
                Log::warning('[ShopeeFoodResolver] Redirect without Location header', [
                    'affiliate_url' => $url,
                    'status'        => $status,
                ]);

                return null;
            }

            $next = $this->absoluteUrl($url, $location);

            if ($next === null) {
                Log::warning('[ShopeeFoodResolver] Invalid redirect Location', [
                    'affiliate_url' => $url,
                    'status'        => $status,
                ]);

                return null;
            }

            return $this->resolve($next, $depth + 1);
        }

        if ($response->failed()) {
            Log::warning('[ShopeeFoodResolver] Resolve HTTP error', [
                'affiliate_url' => $url,
                'status'        => $status,
            ]);

            return null;
        }

        return $this->extractRestaurantId($url);
    }

    private function extractRestaurantId(string $finalUrl): ?string
    {
        $host = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));

        if ($host !== self::TARGET_HOST_SUFFIX && ! str_ends_with($host, '.' . self::TARGET_HOST_SUFFIX)) {
            Log::warning('[ShopeeFoodResolver] Resolved URL is not a ShopeeFood URL', [
                'affiliate_url' => $finalUrl,
                'final_url'     => $finalUrl,
            ]);

            return null;
        }

        $restaurantId = ShopeeFoodUrlParser::restaurantId($finalUrl);

        if ($restaurantId === null) {
            Log::warning('[ShopeeFoodResolver] Resolved ShopeeFood URL has no restaurant id', [
                'affiliate_url' => $finalUrl,
                'final_url'     => $finalUrl,
            ]);
        }

        return $restaurantId;
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