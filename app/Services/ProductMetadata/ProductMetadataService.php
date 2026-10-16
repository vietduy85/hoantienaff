<?php

namespace App\Services\ProductMetadata;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort product metadata (name / image / price) scraper based on Open
 * Graph and standard meta tags.
 *
 * Used to enrich AccessTrade links (e.g. CellphoneS) where the affiliate API
 * only returns a tracking link and no product information.
 *
 * Design rules:
 *  - It NEVER throws: any failure returns null so link creation is unaffected.
 *  - Every fetched URL (including redirects) passes {@see isSafeUrl()}, an
 *    SSRF guard that rejects non-http(s) schemes, credentials in the URL,
 *    localhost/.local/.internal hostnames, and private/reserved IP addresses.
 *  - Responses are time-bounded and size-capped.
 *  - It never invents data: missing tags yield null fields.
 */
class ProductMetadataService
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /**
     * @return array{name: string|null, image: string|null, price: int|null}|null
     */
    public function fetch(?string $url): ?array
    {
        if (! (bool) config('services.product_metadata.enabled', true)) {
            return null;
        }

        $url = $this->normalize($url);

        if ($url === null) {
            return null;
        }

        try {
            $html = $this->download($url);
        } catch (Throwable $e) {
            Log::warning('[ProductMetadata] fetch failed', [
                'host' => $this->host($url),
                'error' => class_basename($e),
            ]);

            return null;
        }

        if ($html === null) {
            return null;
        }

        $name = $this->extractTitle($html);
        $image = $this->extractImage($html, $url);
        $price = $this->extractPrice($html);

        if ($name === null && $image === null && $price === null) {
            return null;
        }

        return [
            'name' => $name,
            'image' => $image,
            'price' => $price,
        ];
    }

    // ─── Download (with manual, validated redirects) ───────────────

    private function download(string $url): ?string
    {
        $current = $url;
        $maxRedirects = max(0, (int) config('services.product_metadata.max_redirects', 3));

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            if (! $this->isSafeUrl($current)) {
                Log::warning('[ProductMetadata] blocked unsafe URL', [
                    'host' => $this->host($current),
                ]);

                return null;
            }

            $response = Http::timeout((int) config('services.product_metadata.timeout', 8))
                ->connectTimeout((int) config('services.product_metadata.connect_timeout', 4))
                ->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($current);

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $location = trim((string) $response->header('Location'));

                if ($location === '') {
                    return null;
                }

                $current = $this->resolveRedirect($current, $location);

                if ($current === null) {
                    return null;
                }

                continue;
            }

            if ($response->failed()) {
                Log::warning('[ProductMetadata] HTTP error', [
                    'host' => $this->host($current),
                    'status' => $status,
                ]);

                return null;
            }

            $contentType = strtolower((string) $response->header('Content-Type'));

            if ($contentType !== '' && ! str_contains($contentType, 'html')) {
                return null;
            }

            $body = (string) $response->body();
            $maxBytes = (int) config('services.product_metadata.max_bytes', 524288);

            if ($maxBytes > 0 && strlen($body) > $maxBytes) {
                $body = substr($body, 0, $maxBytes);
            }

            return $body;
        }

        return null;
    }

    // ─── SSRF guard ────────────────────────────────────────────────

    /**
     * A URL is safe only when it is a plain http(s) URL without credentials,
     * whose host is not a name/address pointing at the local machine or a
     * private/reserved network range.
     */
    public function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim((string) $parts['host'], " \t\n\r\0\x0B[]"));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        foreach (['.local', '.internal', '.home', '.lan'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        if (! (bool) config('services.product_metadata.verify_dns', true)) {
            return true;
        }

        // Best-effort DNS check: reject hostnames that resolve to a private or
        // reserved address. When DNS cannot resolve, the request itself will
        // fail, so the syntactic checks above are enough.
        foreach ($this->resolveHost($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @return array<int, string>
     */
    private function resolveHost(string $host): array
    {
        try {
            $ips = gethostbynamel($host);
        } catch (Throwable) {
            return [];
        }

        return is_array($ips) ? $ips : [];
    }

    private function resolveRedirect(string $base, string $location): ?string
    {
        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        $baseParts = parse_url($base);

        if ($baseParts === false || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'].':'.$location;
        }

        $authority = $baseParts['scheme'].'://'.$baseParts['host'];

        if (isset($baseParts['port'])) {
            $authority .= ':'.$baseParts['port'];
        }

        if (str_starts_with($location, '/')) {
            return $authority.$location;
        }

        $path = (string) ($baseParts['path'] ?? '/');
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $authority.$directory.$location;
    }

    // ─── Parsing ───────────────────────────────────────────────────

    private function extractTitle(string $html): ?string
    {
        $title = $this->metaContent($html, 'og:title');

        if ($title === null) {
            $title = $this->metaContent($html, 'twitter:title');
        }

        if ($title === null && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) === 1) {
            $title = $matches[1];
        }

        if ($title === null) {
            return null;
        }

        $title = html_entity_decode(trim($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', $title);
        $title = is_string($title) ? $this->cleanTitle($title) : null;

        return $title === null || $title === '' ? null : $title;
    }

    /**
     * E-commerce pages append a marketing suffix after a " | " separator
     * (e.g. "Product | Giá rẻ, trả góp 0%"). Keep only the product part.
     */
    private function cleanTitle(string $title): string
    {
        $parts = preg_split('/\s\|\s/u', $title);

        if (is_array($parts) && count($parts) > 1) {
            $title = trim((string) $parts[0]);
        }

        $title = preg_replace('/\s+-\s+CellphoneS(\.com\.vn)?$/i', '', $title) ?? $title;

        return trim($title);
    }

    private function extractImage(string $html, string $baseUrl): ?string
    {
        $image = $this->metaContent($html, 'og:image');

        if ($image === null) {
            $image = $this->metaContent($html, 'twitter:image');
        }

        if ($image === null) {
            return null;
        }

        $absolute = $this->absolutize($baseUrl, $image);

        if ($absolute === null) {
            return null;
        }

        $parts = parse_url($absolute);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if (! in_array($scheme, ['http', 'https'], true)) {
            Log::warning('[ProductMetadata] rejected unsafe image URL', [
                'scheme' => $scheme !== '' ? $scheme : '(none)',
            ]);

            return null;
        }

        return $absolute;
    }

    private function extractPrice(string $html): ?int
    {
        foreach (['product:price:amount', 'og:price:amount'] as $property) {
            $value = $this->metaContent($html, $property);
            $price = $this->toVnd($value);

            if ($price !== null) {
                return $price;
            }
        }

        if (preg_match('/"price"\s*:\s*"?([0-9][0-9.,]*)"?/i', $html, $matches) === 1) {
            $price = $this->toVnd($matches[1]);

            if ($price !== null) {
                return $price;
            }
        }

        $itemprop = $this->metaContent($html, 'price');

        return $this->toVnd($itemprop);
    }

    private function absolutize(string $base, string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // Any explicit scheme must be http(s) — rejects javascript:, data:, etc.
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $matches) === 1) {
            $scheme = strtolower($matches[1]);

            return in_array($scheme, ['http', 'https'], true) ? $url : null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }

        $baseParts = parse_url($base);

        if ($baseParts === false || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return $baseParts['scheme'].':'.$url;
        }

        $authority = $baseParts['scheme'].'://'.$baseParts['host'];

        if (isset($baseParts['port'])) {
            $authority .= ':'.$baseParts['port'];
        }

        if (str_starts_with($url, '/')) {
            return $authority.$url;
        }

        $path = (string) ($baseParts['path'] ?? '/');
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $authority.$directory.$url;
    }

    private function metaContent(string $html, string $property): ?string
    {
        $quoted = preg_quote($property, '/');

        $patterns = [
            '/<meta[^>]*\b(?:property|name|itemprop)=["\']'.$quoted.'["\'][^>]*\bcontent=["\']([^"\']*)["\'][^>]*>/i',
            '/<meta[^>]*\bcontent=["\']([^"\']*)["\'][^>]*\b(?:property|name|itemprop)=["\']'.$quoted.'["\'][^>]*>/i',
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

    private function toVnd(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], '', trim($value));

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        $price = (int) round((float) $value);

        return $price > 0 ? $price : null;
    }

    private function normalize(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
