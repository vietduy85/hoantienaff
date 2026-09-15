<?php

namespace App\Services\ShopeeFood;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ShopeeFoodStoreService
{
    public const CACHE_TTL_SECONDS = 43200;

    public const DEFAULT_PLATFORM_IMAGE = 'images/CuaHangShopeeFood.png';

    private const FALLBACK_BASE_URL = 'https://data.addlivetag.com/shopeefood';

    public function storeName(?string $restaurantId): ?string
    {
        if ($restaurantId === null || trim($restaurantId) === '') {
            return null;
        }

        $restaurantId = trim($restaurantId);
        $cacheKey = 'shopeefood:store:' . $restaurantId;

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $name = $this->fetchStoreName($restaurantId);

        if ($name !== null && $name !== '') {
            Cache::put($cacheKey, $name, self::CACHE_TTL_SECONDS);
        }

        return $name;
    }

    private function fetchStoreName(string $restaurantId): ?string
    {
        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->get($this->storeEndpoint(), ['restaurant_id' => $restaurantId]);
        } catch (ConnectionException $e) {
            Log::warning('[ShopeeFoodStore] Store API timeout/connection failure', [
                'restaurant_id' => $restaurantId,
                'error'         => $this->shortMessage($e),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('[ShopeeFoodStore] Store API unexpected error', [
                'restaurant_id' => $restaurantId,
                'error'         => $this->shortMessage($e),
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('[ShopeeFoodStore] Store API HTTP error', [
                'restaurant_id' => $restaurantId,
                'status'        => $response->status(),
            ]);

            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'ok') {
            Log::warning('[ShopeeFoodStore] Store API invalid status', [
                'restaurant_id' => $restaurantId,
                'status'        => $response->status(),
            ]);

            return null;
        }

        $data = $body['data'] ?? [];
        $name = is_array($data) && isset($data[0]) ? ($data[0]['name'] ?? null) : null;

        if (! is_string($name) || trim($name) === '') {
            Log::warning('[ShopeeFoodStore] Store API empty store name', [
                'restaurant_id' => $restaurantId,
                'status'        => $response->status(),
            ]);

            return null;
        }

        return trim($name);
    }

    private function storeEndpoint(): string
    {
        $baseUrl = rtrim((string) config('services.shopeefood.base_url', self::FALLBACK_BASE_URL), '/');

        return $baseUrl . '/store.php';
    }

    private function shortMessage(Throwable $e): string
    {
        return (new \ReflectionClass($e))->getShortName() . ': ' . substr($e->getMessage(), 0, 120);
    }
}