<?php

namespace App\Services\ShopeeFood;

final class ShopeeFoodUrlParser
{
    public const SHOPEEFOOD_HOST = 'shopeefood.vn';

    public const AFFILIATE_HOST = 'shopeefood.shopee.vn';

    public const SPF_HOST = 'spf.shopee.vn';

    public const VNNOW_HOST = 'shopeefood.shopee.vnnow-food';

    private function __construct()
    {
    }

    public static function restaurantId(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && $path !== '') {
            $segments = array_values(array_filter(
                explode('/', $path),
                static fn (string $segment): bool => $segment !== '',
            ));

            for ($i = count($segments) - 1; $i >= 0; $i--) {
                if (preg_match('/^\d{3,12}$/', $segments[$i]) === 1) {
                    return $segments[$i];
                }
            }
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (is_string($query) && $query !== '') {
            parse_str($query, $params);

            if (isset($params['restaurantId'])
                && preg_match('/^\d{3,12}$/', (string) $params['restaurantId']) === 1) {
                return (string) $params['restaurantId'];
            }
        }

        return null;
    }

    public static function isShopeeFoodUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $url = trim($url);

        if (parse_url($url, PHP_URL_SCHEME) === null) {
            $url = 'https://' . $url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! self::isShopeeFoodHost($host)) {
            return false;
        }

        if ($host === self::VNNOW_HOST || str_ends_with($host, '.' . self::VNNOW_HOST)) {
            return self::normalize($url) !== null;
        }

        return true;
    }

    public static function isShopeeFoodHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        if ($host === self::SHOPEEFOOD_HOST || str_ends_with($host, '.' . self::SHOPEEFOOD_HOST)) {
            return true;
        }

        if ($host === self::SPF_HOST || str_ends_with($host, '.' . self::SPF_HOST)) {
            return true;
        }

        if ($host === self::AFFILIATE_HOST || str_ends_with($host, '.' . self::AFFILIATE_HOST)) {
            return true;
        }

        if ($host === self::VNNOW_HOST || str_ends_with($host, '.' . self::VNNOW_HOST)) {
            return true;
        }

        return false;
    }

    /**
     * Rewrites the short-lived vnnow-food host to its canonical
     * shopeefood.shopee.vn /now-food/ form, preserving path/query/fragment.
     * All other hosts are returned unchanged; invalid URLs return null.
     */
    public static function normalize(?string $url): ?string
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

        if ($host !== self::VNNOW_HOST && ! str_ends_with($host, '.' . self::VNNOW_HOST)) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME)) ?: 'https';
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);

        if ($path === '' || $path === '/') {
            $newPath = '/';
        } elseif (str_starts_with($path, '/now-food')) {
            $newPath = $path;
        } else {
            $newPath = '/now-food' . $path;
        }

        $normalized = $scheme . '://' . self::AFFILIATE_HOST . $newPath;

        if ($query !== '') {
            $normalized .= '?' . $query;
        }

        if ($fragment !== '') {
            $normalized .= '#' . $fragment;
        }

        return filter_var($normalized, FILTER_VALIDATE_URL) ? $normalized : null;
    }
}