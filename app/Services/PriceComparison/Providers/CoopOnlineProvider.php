<?php

namespace App\Services\PriceComparison\Providers;

use App\Services\PriceComparison\Contracts\CatalogProvider;
use App\Services\PriceComparison\DataTransfer\ProductSummary;
use App\Services\PriceComparison\DataTransfer\SearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Co.op Online (Teko discovery API) catalog provider.
 *
 * Verified against the live API (does not require API key / token / cookie):
 *   POST https://discovery.tekoapis.com/api/v1/search
 *   GET  https://discovery.tekoapis.com/api/v1/product?sku=...&terminalId=26607
 *   GET  https://discovery.tekoapis.com/api/v1/products?skus=...&terminalId=26607
 */
final class CoopOnlineProvider implements CatalogProvider
{
    private const BASE_URL = 'https://discovery.tekoapis.com/api/v1';

    private const TERMINAL_ID = 26607;

    private const TIMEOUT = 10;

    private const CONNECT_TIMEOUT = 5;

    private const SEARCH_CACHE_TTL = 300;

    private const DETAIL_CACHE_TTL = 600;

    private const MAX_PER_PAGE = 100;

    private const PRODUCT_URL_TEMPLATE = 'https://cooponline.vn/products/%s';

    public function source(): string
    {
        return 'coop_online';
    }

    public function search(string $keyword, int $page = 1, int $perPage = 20): SearchResult
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), self::MAX_PER_PAGE);
        $keyword = $this->normalizeKeyword($keyword);

        if ($keyword === '') {
            return $this->emptyResult($page, $perPage);
        }

        return Cache::remember(
            $this->searchCacheKey($keyword, $page, $perPage),
            self::SEARCH_CACHE_TTL,
            fn () => $this->requestSearch($keyword, $page, $perPage),
        );
    }

    public function getProduct(string $sku): ?ProductSummary
    {
        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        $cacheKey = 'price_comparison:coop:product:'.md5(strtolower($sku));

        return Cache::remember(
            $cacheKey,
            self::DETAIL_CACHE_TTL,
            fn () => $this->requestProduct($sku),
        );
    }

    private function requestSearch(string $keyword, int $page, int $perPage): SearchResult
    {
        $payload = [
            'filter'           => new \stdClass,
            'pagination'       => ['pageNumber' => $page, 'itemsPerPage' => $perPage],
            'query'            => $keyword,
            'sorting'          => new \stdClass,
            'returnFilterable' => [],
            'block'            => new \stdClass,
            'slug'             => '',
            'terminalId'       => self::TERMINAL_ID,
            'fieldMask'        => [],
        ];

        $context = [
            'provider' => $this->source(),
            'keyword'  => $keyword,
            'page'     => $page,
            'per_page' => $perPage,
        ];

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->post(self::BASE_URL.'/search', $payload);
        } catch (Throwable $e) {
            Log::warning('PriceComparison: Co.op search request failed (connection/timeout)', $context + ['message' => $e->getMessage()]);

            return $this->emptyResult($page, $perPage);
        }

        if ($response->failed()) {
            Log::warning('PriceComparison: Co.op search HTTP error', $context + ['status' => $response->status()]);

            return $this->emptyResult($page, $perPage);
        }

        $json = $this->decodeJson($response->body());

        if ($json === null) {
            Log::warning('PriceComparison: Co.op search malformed JSON response', $context + ['status' => $response->status()]);

            return $this->emptyResult($page, $perPage);
        }

        return $this->mapSearchResponse($json, $page, $perPage, $context);
    }

    private function requestProduct(string $sku): ?ProductSummary
    {
        $context = [
            'provider' => $this->source(),
            'sku'      => $sku,
        ];

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->get(self::BASE_URL.'/product', [
                    'sku'        => $sku,
                    'terminalId' => self::TERMINAL_ID,
                ]);
        } catch (Throwable $e) {
            Log::warning('PriceComparison: Co.op product request failed (connection/timeout)', $context + ['message' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('PriceComparison: Co.op product HTTP error', $context + ['status' => $response->status()]);

            return null;
        }

        $json = $this->decodeJson($response->body());

        if ($json === null) {
            Log::warning('PriceComparison: Co.op product malformed JSON response', $context + ['status' => $response->status()]);

            return null;
        }

        $rawProduct = $json['result']['product'] ?? null;

        if (! is_array($rawProduct)) {
            Log::info('PriceComparison: Co.op product not found in response', $context);

            return null;
        }

        try {
            return $this->mapProduct($rawProduct);
        } catch (Throwable $e) {
            Log::warning('PriceComparison: Co.op product mapping error', $context + ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $context
     */
    private function mapSearchResponse(array $json, int $page, int $perPage, array $context): SearchResult
    {
        $rawProducts = $json['result']['products'] ?? null;

        if (! is_array($rawProducts)) {
            Log::warning('PriceComparison: Co.op search response missing result.products', $context);

            return $this->emptyResult($page, $perPage);
        }

        $items = new Collection;

        foreach ($rawProducts as $rawProduct) {
            try {
                $item = $rawProduct !== null ? $this->mapProduct($rawProduct) : null;

                if ($item !== null) {
                    $items->push($item);
                }
            } catch (Throwable $e) {
                Log::warning('PriceComparison: skip Co.op product mapping error', $context + ['message' => $e->getMessage()]);
            }
        }

        return new SearchResult(
            items: $items,
            page: $page,
            perPage: $perPage,
            total: (int) ($json['pagination']['totalItems'] ?? 0),
            totalPages: (int) ($json['pagination']['totalPages'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function mapProduct(array $raw): ?ProductSummary
    {
        $info = $raw['productInfo'] ?? null;

        if (! is_array($info)) {
            return null;
        }

        $sku = isset($info['sku']) ? (string) $info['sku'] : '';

        if ($sku === '') {
            return null;
        }

        $price = $raw['prices'][0] ?? [];
        $price = is_array($price) ? $price : [];
        $status = $raw['status'] ?? [];
        $status = is_array($status) ? $status : [];

        $categories = $info['categories'] ?? null;
        $categories = is_array($categories) ? $categories : null;

        $category = $this->firstCategoryName($categories);
        $brand = $this->extractName($info['brand'] ?? null);
        $seller = $this->extractName($info['seller'] ?? null);

        return new ProductSummary(
            source: $this->source(),
            sku: $sku,
            skuId: isset($info['skuId']) ? (string) $info['skuId'] : null,
            name: isset($info['name']) ? (string) $info['name'] : null,
            barcode: isset($info['barcode']) ? (string) $info['barcode'] : null,
            brand: $brand,
            category: $category,
            price: $this->numericOrNull($price['latestPrice'] ?? null),
            originalPrice: $this->numericOrNull($price['sellPrice'] ?? null),
            supplierRetailPrice: $this->numericOrNull($price['supplierRetailPrice'] ?? null),
            discountAmount: $this->numericOrNull($price['discountAmount'] ?? null),
            discountPercent: $this->numericOrNull($price['discountPercent'] ?? null),
            imageUrl: isset($info['imageUrl']) ? (string) $info['imageUrl'] : null,
            productUrl: sprintf(self::PRODUCT_URL_TEMPLATE, $sku),
            stock: $this->intOrNull($raw['totalAvailable'] ?? null),
            sellable: (bool) ($status['sellable'] ?? false),
            unit: isset($info['uomName']) ? (string) $info['uomName'] : null,
            slug: isset($info['slug']) ? (string) $info['slug'] : null,
            seller: $seller,
            manufacturer: isset($info['manufacturer']) ? (string) $info['manufacturer'] : null,
            categories: $categories,
            rawData: $raw,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $categories
     */
    private function firstCategoryName(?array $categories): ?string
    {
        if ($categories === null || ! isset($categories[0]) || ! is_array($categories[0])) {
            return null;
        }

        $name = $categories[0]['name'] ?? null;

        if ($name !== null && $name !== '') {
            return (string) $name;
        }

        return isset($categories[0]['code']) ? (string) $categories[0]['code'] : null;
    }

    private function extractName(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            foreach (['name', 'displayName', 'display_name'] as $key) {
                if (isset($value[$key]) && $value[$key] !== '') {
                    return (string) $value[$key];
                }
            }

            return null;
        }

        return (string) $value;
    }

    private function numericOrNull(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            $float = (float) $value;

            return $float === floor($float) ? (int) $float : $float;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        $numeric = $this->numericOrNull($value);

        return $numeric !== null ? (int) $numeric : null;
    }

    private function normalizeKeyword(string $keyword): string
    {
        $keyword = trim($keyword);
        $keyword = (string) preg_replace('/\s+/u', ' ', $keyword);

        return $keyword;
    }

    private function searchCacheKey(string $keyword, int $page, int $perPage): string
    {
        $key = mb_strtolower($keyword);

        if (mb_strlen($key) > 60) {
            $key = md5($key);
        }

        return 'price_comparison:coop:search:'.$key.':'.$page.':'.$perPage;
    }

    private function emptyResult(int $page, int $perPage): SearchResult
    {
        return new SearchResult(new Collection, $page, $perPage, 0, 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $body): ?array
    {
        $json = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return is_array($json) ? $json : null;
    }
}