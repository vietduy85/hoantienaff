<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UrlResolverService
{
    private const SHORT_DOMAINS = ['s.shopee.vn', 'vn.shp.ee', 's.shp.ee', 'shope.ee'];

    private const LANDING_HOST = 'shopee.vn';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';

    private const MAX_REDIRS = 10;


    private const TIMEOUT = 6;

    private const CONNECT_TIMEOUT = 4;

    private const CACHE_KEY_PREFIX = 'shopee_resolve:';

    private const CACHE_TTL_SECONDS = 86400;

    private const RETRYABLE_ERRORS = [
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_CONNECT,
        CURLE_PARTIAL_FILE,
        CURLE_OPERATION_TIMEDOUT,
        CURLE_GOT_NOTHING,
        CURLE_SEND_ERROR,
        CURLE_RECV_ERROR,
    ];

    private const NON_RETRYABLE_HTTP = [400, 401, 403, 404, 405, 410, 414, 451];

    private const RETRY_ONCE_HTTP = [500, 502, 503, 504];

    public function resolve(string $url): ?string
    {
        $normalized = $this->normalizeUrl($url);

        if (! $this->needsResolution($normalized)) {
            return $url;
        }

        $cached = $this->getFromCache($normalized);
        if ($cached !== null) {
            return $cached;
        }

        $start = config('app.affiliate_timing') ? microtime(true) : null;

        $expanded = $this->expandShortUrl($normalized);

        if ($start !== null) {
            Log::info('[Resolver] Short Link Resolved', [
                'original' => $url,
                'resolved' => $expanded,
                'total_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
        }

        if ($expanded === null || ! $this->isShopeeLanding($expanded)) {
            return null;
        }

        $this->putCache($normalized, $expanded);

        return $expanded;
    }

    public function isShortLink(string $url): bool
    {
        $host = strtolower(parse_url($this->normalizeUrl($url), PHP_URL_HOST) ?? '');

        foreach (self::SHORT_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    public function isShopeeLanding(string $url): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        return $host === self::LANDING_HOST || str_ends_with($host, '.'.self::LANDING_HOST);
    }

    public function needsResolution(string $url): bool
    {
        $url = $this->normalizeUrl($url);

        if ($this->isShortLink($url)) {
            return true;
        }

        if ($this->isShopeeLanding($url)) {
            return ! $this->isCanonicalProductUrl($url);
        }

        return false;
    }

    private function isCanonicalProductUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $query = parse_url($url, PHP_URL_QUERY) ?? '';

        parse_str($query, $params);

        if (isset($params['item_id']) && ctype_digit((string) $params['item_id'])) {
            return true;
        }

        if (isset($params['itemId']) && ctype_digit((string) $params['itemId'])) {
            return true;
        }

        if (preg_match('#/product/(\d+)/(\d+)#', $path, $m)) {
            return true;
        }

        if (preg_match('#/opaanlp/(\d+)/(\d+)#', $path, $m)) {
            return true;
        }

        if (preg_match('#\-i\.(\d+)\.(\d+)#', $path, $m)) {
            return true;
        }

        return false;
    }

    private function cacheKey(string $url): string
    {
        return self::CACHE_KEY_PREFIX.md5($url);
    }

    private function getFromCache(string $url): ?string
    {
        $value = Cache::get($this->cacheKey($url));

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private function putCache(string $url, string $resolved): void
    {
        Cache::put($this->cacheKey($url), $resolved, self::CACHE_TTL_SECONDS);
    }

    private function normalizeUrl(string $url): string
    {
        if (! preg_match('/^https?:\/\//i', $url)) {
            return 'https://'.$url;
        }

        return $url;
    }

    /**
     * Tự lần theo redirect (thay vì CURLOPT_FOLLOWLOCATION) để:
     *  - DỪNG ngay khi Location đã là URL sản phẩm chuẩn (có shop_id + item_id) — không tải
     *    trang sản phẩm Shopee (tiết kiệm ~0,5s, link rút gọn chỉ có 1 hop 301).
     *  - Dùng CApath (App\Support\CaStore): nạp bundle đầy đủ ~120 CA tốn
     *    ~300ms mỗi lần bắt tay TLS. Lỗi xác thực => thử lại hop đó với bundle mặc định.
     *
     * @return array{0: ?string, 1: int, 2: int, 3: string, 4: int} [url, http, errno, error, redirects]
     */
    private function follow(string $url): array
    {
        $caOptions = \App\Support\CaStore::curlOptions();
        $useSmallCa = $caOptions !== [];
        $httpCode = 0;
        $errno = 0;
        $error = '';

        for ($hop = 0; $hop <= self::MAX_REDIRS; $hop++) {
            $ch = curl_init($url);

            $options = [
                // Trang đích 2xx: ngắt ngay sau header, không tải HTML.
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk): int {
                    $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                    return ($code >= 200 && $code < 300) ? 0 : strlen($chunk);
                },
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
            ];
            if ($useSmallCa) {
                $options += $caOptions;
            }
            curl_setopt_array($ch, $options);

            curl_exec($ch);

            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);

            curl_close($ch);

            if ($useSmallCa && in_array($errno, [60, 77], true)) {
                Log::warning('[Resolver] CApath failed, falling back to default bundle', [
                    'host' => parse_url($url, PHP_URL_HOST),
                    'errno' => $errno,
                ]);
                $useSmallCa = false;
                $hop--;

                continue;
            }

            $isRedirect = $errno === 0 && $httpCode >= 300 && $httpCode < 400 && $location !== '';

            if (! $isRedirect) {
                return [$url, $httpCode, $errno, $error, $hop];
            }

            $url = $location;

            if ($this->isShopeeLanding($url) && $this->isCanonicalProductUrl($url)) {
                return [$url, $httpCode, 0, '', $hop + 1];
            }
        }

        return [null, $httpCode, $errno, 'too many redirects', self::MAX_REDIRS];
    }

    private function expandShortUrl(string $url): ?string
    {
        if (! preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://'.$url;
        }

        $delays = [0, 300000, 500000];
        $attempts = count($delays);
        $retriedHttp5xx = false;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                usleep($delays[$attempt]);
            }

            $attemptStart = config('app.affiliate_timing') ? microtime(true) : null;

            [$finalUrl, $httpCode, $errno, $error, $redirects] = $this->follow($url);

            $elapsed = $attemptStart !== null
                ? (int) ((microtime(true) - $attemptStart) * 1000)
                : null;

            // Success (errno 23 = chủ động ngắt khi đã nhận header 2xx của trang đích)
            $abortedAfterHeaders = $errno === CURLE_WRITE_ERROR && $httpCode >= 200 && $httpCode < 300;
            if (($errno === 0 || $abortedAfterHeaders) && $httpCode >= 200 && $httpCode < 400) {
                if (filter_var($finalUrl, FILTER_VALIDATE_URL)) {
                    if ($attemptStart !== null) {
                        Log::info('[Resolver] Attempt '.($attempt + 1), [
                            'result' => 'success',
                            'ms' => $elapsed,
                            'redirects' => $redirects,
                        ]);
                    }

                    return $finalUrl;
                }
            }

            // Non-retryable HTTP (4xx, etc.)
            if ($errno === 0 && in_array($httpCode, self::NON_RETRYABLE_HTTP, true)) {
                if ($attemptStart !== null) {
                    Log::info('[Resolver] Attempt '.($attempt + 1), [
                        'result' => 'non-retryable-http',
                        'http' => $httpCode,
                        'ms' => $elapsed,
                    ]);
                }

                return null;
            }

            // HTTP 5xx — retry once
            if ($errno === 0 && in_array($httpCode, self::RETRY_ONCE_HTTP, true)) {
                if ($retriedHttp5xx) {
                    if ($attemptStart !== null) {
                        Log::info('[Resolver] Attempt '.($attempt + 1), [
                            'result' => 'non-retryable-http',
                            'http' => $httpCode,
                            'reason' => 'already-retried-5xx',
                            'ms' => $elapsed,
                        ]);
                    }

                    return null;
                }
                $retriedHttp5xx = true;
                if ($attemptStart !== null) {
                    Log::info('[Resolver] Attempt '.($attempt + 1), [
                        'result' => 'retry-5xx',
                        'http' => $httpCode,
                        'ms' => $elapsed,
                    ]);
                }

                continue;
            }

            // Non-retryable cURL error
            if ($errno !== 0 && ! in_array($errno, self::RETRYABLE_ERRORS, true)) {
                if ($attemptStart !== null) {
                    Log::info('[Resolver] Attempt '.($attempt + 1), [
                        'result' => 'non-retryable-curl',
                        'errno' => $errno,
                        'error' => $error,
                        'ms' => $elapsed,
                    ]);
                }

                return null;
            }

            // Retryable error
            if ($attemptStart !== null) {
                $reason = $errno !== 0
                    ? 'curl_errno='.$errno
                    : 'http='.$httpCode;

                Log::info('[Resolver] Attempt '.($attempt + 1), [
                    'result' => 'retry',
                    'reason' => $reason,
                    'ms' => $elapsed,
                ]);
            }
        }

        return null;
    }
}
