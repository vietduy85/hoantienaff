<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class UrlResolverService
{
    private const SHORT_DOMAINS = ['s.shopee.vn', 'vn.shp.ee'];
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
    private const MAX_REDIRS = 10;
    private const TIMEOUT = 2;
    private const CONNECT_TIMEOUT = 1;

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
        if (!$this->isShortLink($url)) {
            return $url;
        }

        $start = config('app.affiliate_timing') ? microtime(true) : null;

        $expanded = $this->expandShortUrl($url);

        if ($start !== null) {
            Log::info('[Resolver] Short Link Resolved', [
                'original' => $url,
                'resolved' => $expanded,
                'total_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
        }

        return $expanded;
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

        $delays = [0, 300000, 500000];
        $attempts = count($delays);
        $retriedHttp5xx = false;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                usleep($delays[$attempt]);
            }

            $attemptStart = config('app.affiliate_timing') ? microtime(true) : null;

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => self::MAX_REDIRS,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
                CURLOPT_NOBODY         => false,
            ]);

            curl_exec($ch);

            $finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno     = curl_errno($ch);
            $error     = curl_error($ch);
            $redirects = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);

            curl_close($ch);

            $elapsed = $attemptStart !== null
                ? (int) ((microtime(true) - $attemptStart) * 1000)
                : null;

            // Success
            if ($errno === 0 && $httpCode >= 200 && $httpCode < 400) {
                if (filter_var($finalUrl, FILTER_VALIDATE_URL)) {
                    if ($attemptStart !== null) {
                        Log::info('[Resolver] Attempt ' . ($attempt + 1), [
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
                    Log::info('[Resolver] Attempt ' . ($attempt + 1), [
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
                        Log::info('[Resolver] Attempt ' . ($attempt + 1), [
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
                    Log::info('[Resolver] Attempt ' . ($attempt + 1), [
                        'result' => 'retry-5xx',
                        'http' => $httpCode,
                        'ms' => $elapsed,
                    ]);
                }
                continue;
            }

            // Non-retryable cURL error
            if ($errno !== 0 && !in_array($errno, self::RETRYABLE_ERRORS, true)) {
                if ($attemptStart !== null) {
                    Log::info('[Resolver] Attempt ' . ($attempt + 1), [
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
                    ? 'curl_errno=' . $errno
                    : 'http=' . $httpCode;

                Log::info('[Resolver] Attempt ' . ($attempt + 1), [
                    'result' => 'retry',
                    'reason' => $reason,
                    'ms' => $elapsed,
                ]);
            }
        }

        return null;
    }
}
