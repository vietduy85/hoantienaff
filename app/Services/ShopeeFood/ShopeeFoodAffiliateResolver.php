<?php

namespace App\Services\ShopeeFood;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ShopeeFoodAffiliateResolver
{
    public const CACHE_TTL_SECONDS = 43200;

    private const SPF_HOST = 'spf.shopee.vn';

    private const TARGET_HOST_SUFFIX = 'shopeefood.vn';

    private const AFFILIATE_HOST = 'shopeefood.shopee.vn';

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

        $finalUrl = $this->resolveFinalUrl($affiliateUrl);

        $restaurantId = $finalUrl !== null ? $this->extractRestaurantId($finalUrl) : null;

        if ($restaurantId !== null) {
            Cache::put($cacheKey, $restaurantId, self::CACHE_TTL_SECONDS);
        }

        return $restaurantId;
    }

    /**
     * Follows redirects and, on a rendered (200) page, hops to the real
     * restaurant URL surfaced through canonical/og:url/menu anchors.
     * Never fabricates a destination — returns null when no target is found.
     */
    public function resolveFinalUrl(?string $url): ?string
    {
        $current = $this->normalizeUrl($url);

        if ($current === null) {
            return null;
        }

        $visited = [];

        for ($depth = 0; $depth <= self::MAX_REDIRECTS; $depth++) {
            $clean = $this->stripTracking($current);

            if (isset($visited[$clean])) {
                Log::warning('[ShopeeFoodResolver] Redirection loop detected', [
                    'url' => $current,
                ]);

                return null;
            }

            $visited[$clean] = true;

            $response = $this->request($current);

            if ($response === null) {
                return null;
            }

            $status = $response->status();

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                $location = $response->header('Location');

                if ($location === null || $location === '') {
                    Log::warning('[ShopeeFoodResolver] Redirect without Location header', [
                        'url'    => $current,
                        'status' => $status,
                    ]);

                    return null;
                }

                $next = $this->absoluteUrl($current, $location);

                if ($next === null) {
                    Log::warning('[ShopeeFoodResolver] Invalid redirect Location', [
                        'url'    => $current,
                        'status' => $status,
                    ]);

                    return null;
                }

                $current = $next;

                continue;
            }

            if ($response->failed()) {
                Log::warning('[ShopeeFoodResolver] Resolve HTTP error', [
                    'url'    => $current,
                    'status' => $status,
                ]);

                return null;
            }

            $target = $this->extractTargetFromBody($response->body(), $current);

            if ($target === null) {
                return $current;
            }

            $current = $target;
        }

        Log::warning('[ShopeeFoodResolver] Redirection limit reached', [
            'url' => $current,
        ]);

        return null;
    }

    public function extractRestaurantId(string $finalUrl): ?string
    {
        $host = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));

        if ($host !== self::TARGET_HOST_SUFFIX && ! str_ends_with($host, '.' . self::TARGET_HOST_SUFFIX)) {
            Log::warning('[ShopeeFoodResolver] Resolved URL is not a ShopeeFood URL', [
                'final_url' => $finalUrl,
            ]);

            return null;
        }

        $restaurantId = ShopeeFoodUrlParser::restaurantId($finalUrl);

        if ($restaurantId === null) {
            Log::warning('[ShopeeFoodResolver] Resolved ShopeeFood URL has no restaurant id', [
                'final_url' => $finalUrl,
            ]);
        }

        return $restaurantId;
    }

    // ─── Internals ───────────────────────────────────────────────

    private function normalizeUrl(?string $url): ?string
    {
        return ShopeeFoodUrlParser::normalize($url);
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

    private function request(string $url): ?Response
    {
        try {
            return Http::withoutRedirecting()
                ->acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('[ShopeeFoodResolver] Resolve timeout/connection failure', [
                'url'   => $url,
                'error' => $this->shortMessage($e),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('[ShopeeFoodResolver] Resolve unexpected error', [
                'url'   => $url,
                'error' => $this->shortMessage($e),
            ]);

            return null;
        }
    }

    private function extractTargetFromBody(string $html, string $currentUrl): ?string
    {
        foreach ($this->bodyCandidates($html, $currentUrl) as $candidate) {
            $absolute = $this->absoluteUrl($currentUrl, $candidate);

            if ($absolute === null) {
                continue;
            }

            $host = strtolower((string) parse_url($absolute, PHP_URL_HOST));

            if ($host === self::SPF_HOST || str_ends_with($host, '.' . self::SPF_HOST)) {
                continue;
            }

            if ($host !== self::TARGET_HOST_SUFFIX
                && ! str_ends_with($host, '.' . self::TARGET_HOST_SUFFIX)
                && $host !== self::AFFILIATE_HOST) {
                continue;
            }

            $path = (string) parse_url($absolute, PHP_URL_PATH);

            if (! str_contains($path, '/now-food/')) {
                continue;
            }

            if ($this->stripTracking($absolute) === $this->stripTracking($currentUrl)) {
                continue;
            }

            return $absolute;
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function bodyCandidates(string $html, string $currentUrl): array
    {
        $candidates = [];

        $canonical = $this->linkRelHref($html, 'canonical');

        if ($canonical !== null) {
            $candidates[] = $canonical;
        }

        $ogUrl = $this->metaContent($html, 'og:url');

        if ($ogUrl !== null) {
            $candidates[] = $ogUrl;
        }

        if (preg_match_all('/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i', $html, $matches) >= 1) {
            foreach ($matches[1] as $href) {
                if (trim($href) !== '') {
                    $candidates[] = $href;
                }
            }
        }

        return $candidates;
    }

    private function linkRelHref(string $html, string $rel): ?string
    {
        $quoted = preg_quote($rel, '/');

        $patterns = [
            '/<link\b[^>]*\brel=["\']' . $quoted . '["\'][^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i',
            '/<link\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*\brel=["\']' . $quoted . '["\'][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                $value = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function metaContent(string $html, string $property): ?string
    {
        $quoted = preg_quote($property, '/');

        $patterns = [
            '/<meta[^>]*\bproperty=["\']' . $quoted . '["\'][^>]*\bcontent=["\']([^"\']*)["\'][^>]*>/i',
            '/<meta[^>]*\bcontent=["\']([^"\']*)["\'][^>]*\bproperty=["\']' . $quoted . '["\'][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                $value = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function stripTracking(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME)) ?: 'https';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $scheme . '://' . $host . $path;
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