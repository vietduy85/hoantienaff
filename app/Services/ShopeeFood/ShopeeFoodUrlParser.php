<?php

namespace App\Services\ShopeeFood;

final class ShopeeFoodUrlParser
{
    private function __construct()
    {
    }

    public static function restaurantId(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '',
        ));

        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if (preg_match('/^\d{3,12}$/', $segments[$i]) === 1) {
                return $segments[$i];
            }
        }

        return null;
    }
}