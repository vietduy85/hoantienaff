<?php

namespace App\Services\ShopeeFood;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ShopeeFoodOpenGraphService
{
    public const CACHE_TTL_SECONDS = 43200;

    private const USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Mobile Safari/604.1';

    // ─── Public API ────────────────────────────────────────────────
    //
    // @return array{name: string|null, image: string|null}|null
    public function resolveNameAndImage(?string $url): ?array
    {
        // Direct /now-food/shop/{id} page → fetch the clean URL (no tracking query),
        // cached under shopeefood:og:restaurant:{id}.
        $directId = $this->directRestaurantId($url);

        if ($directId !== null) {
            return $this->fromCacheOrFetch(
                'shopeefood:og:restaurant:' . $directId,
                $this->cleanFetchUrl($url),
                $url,
            );
        }

        $code = $this->shortCode($url);

        if ($code === null) {
            return null;
        }

        return $this->fromCacheOrFetch('shopeefood:u:' . $code, $url, $url);
    }

    public function isShortUrl(?string $url): bool
    {
        return $this->shortCode($url) !== null;
    }

    private function fromCacheOrFetch(string $cacheKey, string $fetchUrl, string $label): ?array
    {
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return [
                'name'  => isset($cached['name']) && $cached['name'] !== '' ? (string) $cached['name'] : null,
                'image' => isset($cached['image']) && $cached['image'] !== '' ? (string) $cached['image'] : null,
            ];
        }

        $result = $this->fetch($fetchUrl, $label);

        if ($result !== null && ($result['name'] !== null || $result['image'] !== null)) {
            Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);
        }

        return $result;
    }

    // ─── URL guards ────────────────────────────────────────────────

    private function directRestaurantId(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (parse_url($url, PHP_URL_SCHEME) === null) {
            $url = 'https://' . $url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host !== 'shopeefood.vn'
            && ! str_ends_with($host, '.shopeefood.vn')
            && $host !== 'shopeefood.shopee.vn') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/now-food/shop/(\d{3,12})$#', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function cleanFetchUrl(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME)) ?: 'https';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $scheme . '://' . $host . $path;
    }

    private function shortCode(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host !== 'shopeefood.vn' && ! str_ends_with($host, '.shopeefood.vn')) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/u/([A-Za-z0-9_-]+)$#', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    // ─── Fetch + parse ─────────────────────────────────────────────

    /**
     * @return array{name: string|null, image: string|null}|null
     */
    private function fetch(string $url, string $code): ?array
    {
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::warning('[ShopeeFoodOpenGraph] Request timeout/connection failure', [
                'short_code' => $code,
                'url'        => $url,
                'error'      => $this->shortMessage($e),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('[ShopeeFoodOpenGraph] Request unexpected error', [
                'short_code' => $code,
                'url'        => $url,
                'error'      => $this->shortMessage($e),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('[ShopeeFoodOpenGraph] HTTP error', [
                'short_code' => $code,
                'url'        => $url,
                'status'     => $response->status(),
            ]);

            return null;
        }

        $name  = $this->extractTitle($response->body());
        $image = $this->extractImage($response->body());

        return [
            'name'  => $name,
            'image' => $image,
        ];
    }

    private function extractTitle(string $html): ?string
    {
        $title = null;

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) === 1) {
            $title = $matches[1];
        }

        if ($title === null) {
            $title = $this->metaContent($html, 'og:title');
        }

        if ($title === null) {
            return null;
        }

        $title = html_entity_decode(trim($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', trim($title));

        return $title === '' ? null : $title;
    }

    private function extractImage(string $html): ?string
    {
        $image = $this->metaContent($html, 'og:image');

        if ($image === null) {
            return null;
        }

        $parts = parse_url($image);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if (! in_array($scheme, ['http', 'https'], true)) {
            Log::warning('[ShopeeFoodOpenGraph] Rejected unsafe image URL', [
                'scheme' => $scheme !== '' ? $scheme : '(none)',
            ]);

            return null;
        }

        return filter_var($image, FILTER_VALIDATE_URL) ? $image : null;
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

    private function shortMessage(Throwable $e): string
    {
        return (new \ReflectionClass($e))->getShortName() . ': ' . substr($e->getMessage(), 0, 120);
    }
}