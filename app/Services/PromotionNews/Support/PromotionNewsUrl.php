<?php

namespace App\Services\PromotionNews\Support;

/**
 * URL hardening shared by providers and admin validation.
 *
 * Only absolute http/https URLs without control characters or CRLF are ever
 * accepted. Protocol-relative/relative URLs are resolved against a trusted
 * retailer base URL before validation.
 */
final class PromotionNewsUrl
{
    public static function isValid(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        return $parts['host'] !== '';
    }

    public static function resolve(?string $url, string $base): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            $resolved = $scheme.':'.$url;
        } elseif (preg_match('#^https?://#i', $url)) {
            $resolved = $url;
        } elseif (str_starts_with($url, '/')) {
            $resolved = rtrim($base, '/').$url;
        } else {
            $resolved = rtrim($base, '/').'/'.ltrim($url, '/');
        }

        return self::isValid($resolved) ? $resolved : null;
    }
}
