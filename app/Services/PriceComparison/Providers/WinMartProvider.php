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
 * WinMart catalog provider.
 *
 * Verified against the live API (PUBLIC, no authentication / API key required):
 *   POST https://api-crownx.winmart.vn/ss/api/v2/public/winmart/item-search
 *   GET  https://www.winmart.vn/products/{seoName}  (public product page)
 *
 * The search API is store scoped: "storeGroupCode" + "storeNo" drive price,
 * stock and availability. Both are read from config only and never inferred
 * from the visitor (IP/geolocation/browser). "pageSize" is capped at 100 by
 * the API (larger values return HTTP 400).
 *
 * Every returned item is one SKU/UOM row (e.g. "10008453G1" = 1 gói,
 * "10008453T" = thùng 30). Rows are deliberately NOT merged or deduplicated
 * so each UOM is comparable across retailers.
 *
 * There is no public dedicated detail endpoint (the /it/api/web/* endpoints
 * are auth-only and return empty data anonymously). getProduct() therefore
 * resolves via the item-search API using the SKU/itemNo, preferring the
 * smallest unit (quantityPerUnit == 1).
 */
final class WinMartProvider implements CatalogProvider
{
    private const BASE_URL = 'https://api-crownx.winmart.vn';

    private const SEARCH_PATH = '/ss/api/v2/public/winmart/item-search';

    private const PRODUCT_BASE_URL = 'https://www.winmart.vn';

    private const APPLICATION_TYPE = 'Winmart';

    private const TIMEOUT = 10;

    private const CONNECT_TIMEOUT = 5;

    private const SEARCH_CACHE_TTL = 300;

    private const DETAIL_CACHE_TTL = 600;

    private const MAX_PER_PAGE = 100;

    private const SELLER = 'WinMart';

    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function source(): string
    {
        return 'winmart';
    }

    public function search(string $keyword, int $page = 1, int $perPage = 20): SearchResult
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), self::MAX_PER_PAGE);
        $keyword = $this->normalizeKeyword($keyword);

        if ($keyword === '') {
            return $this->emptyResult($page, $perPage);
        }

        $cacheKey = $this->searchCacheKey($keyword, $page, $perPage);

        $cached = Cache::get($cacheKey);

        if ($cached instanceof SearchResult) {
            return $cached;
        }

        $result = $this->requestSearch($keyword, $page, $perPage);

        Cache::put($cacheKey, $result, self::SEARCH_CACHE_TTL);

        return $result;
    }

    /**
     * Resolve a single product through the item-search API.
     *
     * The identifier may be either an itemNo (groups all UOM variants) or a
     * full SKU ("itemNo" + UOM code). An exact SKU row wins; otherwise the
     * smallest unit (quantityPerUnit == 1) is chosen as representative.
     */
    public function getProduct(string $sku): ?ProductSummary
    {
        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        $cacheKey = $this->productCacheKey($sku);

        $cached = Cache::get($cacheKey);

        if ($cached instanceof ProductSummary) {
            return $cached;
        }

        $product = $this->requestProduct($sku);

        if ($product !== null) {
            Cache::put($cacheKey, $product, self::DETAIL_CACHE_TTL);
        }

        return $product;
    }

    private function requestSearch(string $keyword, int $page, int $perPage): SearchResult
    {
        $payload = [
            'keyword' => $keyword,
            'storeNo' => $this->storeNo(),
            'storeGroupCode' => $this->storeGroupCode(),
            'applicationType' => self::APPLICATION_TYPE,
            'pageNumber' => $page,
            'pageSize' => $perPage,
        ];

        $context = [
            'provider' => $this->source(),
            'keyword' => $keyword,
            'page' => $page,
            'per_page' => $perPage,
            'store_group_code' => $this->storeGroupCode(),
            'store_no' => $this->storeNo(),
        ];

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withUserAgent(self::DEFAULT_USER_AGENT)
                ->acceptJson()
                ->post($this->baseUrl().self::SEARCH_PATH, $payload);
        } catch (Throwable $e) {
            Log::warning('PriceComparison: WinMart search request failed (connection/timeout)', $context + ['message' => $e->getMessage()]);

            return $this->emptyResult($page, $perPage);
        }

        if ($response->failed()) {
            Log::warning('PriceComparison: WinMart search HTTP error', $context + ['status' => $response->status()]);

            return $this->emptyResult($page, $perPage);
        }

        $json = $this->decodeJson($response->body());

        if ($json === null) {
            Log::warning('PriceComparison: WinMart search malformed JSON response', $context + ['status' => $response->status()]);

            return $this->emptyResult($page, $perPage);
        }

        return $this->mapSearchResponse($json, $page, $perPage, $context);
    }

    private function requestProduct(string $identifier): ?ProductSummary
    {
        $result = $this->requestSearch($identifier, 1, self::MAX_PER_PAGE);

        if ($result->items->isEmpty()) {
            $itemNo = $this->extractItemNo($identifier);

            if ($itemNo !== null && $itemNo !== $identifier) {
                $result = $this->requestSearch($itemNo, 1, self::MAX_PER_PAGE);
            }
        }

        if ($result->items->isEmpty()) {
            return null;
        }

        $exact = $result->items->first(fn (ProductSummary $item) => $item->sku === $identifier);

        if ($exact !== null) {
            return $exact;
        }

        return $result->items->sortBy(
            fn (ProductSummary $item) => $this->quantityPerUnit($item->rawData) ?? PHP_INT_MAX,
        )->first() ?? $result->items->first();
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $context
     */
    private function mapSearchResponse(array $json, int $page, int $perPage, array $context): SearchResult
    {
        $rawItems = $json['data'] ?? null;

        if (! is_array($rawItems)) {
            Log::warning('PriceComparison: WinMart search response missing data', $context);

            return $this->emptyResult($page, $perPage);
        }

        $items = new Collection;

        foreach ($rawItems as $rawItem) {
            try {
                if (! is_array($rawItem)) {
                    continue;
                }

                $item = $this->mapProduct($rawItem);

                if ($item !== null) {
                    $items->push($item);
                }
            } catch (Throwable $e) {
                Log::warning('PriceComparison: skip WinMart product mapping error', $context + ['message' => $e->getMessage()]);
            }
        }

        $paging = $json['paging'] ?? [];
        $paging = is_array($paging) ? $paging : [];

        $total = (int) ($paging['totalCount'] ?? 0);

        $apiPage = $paging['pageNumber'] ?? null;
        $resultPage = is_numeric($apiPage) ? (int) $apiPage : $page;

        $apiPageSize = $paging['pageSize'] ?? null;
        $resultPerPage = is_numeric($apiPageSize) && (int) $apiPageSize > 0 ? (int) $apiPageSize : $perPage;

        $apiTotalPages = $paging['totalPages'] ?? null;

        if (is_numeric($apiTotalPages)) {
            $totalPages = max(0, (int) $apiTotalPages);
        } else {
            $totalPages = $resultPerPage > 0 ? (int) ceil($total / $resultPerPage) : 0;
        }

        return new SearchResult(
            items: $items,
            page: max(1, $resultPage),
            perPage: $resultPerPage,
            total: $total,
            totalPages: $totalPages,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function mapProduct(array $raw): ?ProductSummary
    {
        $id = $raw['id'] ?? null;

        if ($id === null || $id === '' || is_array($id)) {
            return null;
        }

        $id = (string) $id;
        $sku = isset($raw['sku']) ? (string) $raw['sku'] : '';

        if ($sku === '') {
            return null;
        }

        $price = $raw['price'] ?? [];
        $price = is_array($price) ? $price : [];

        $warehouse = $raw['warehouse'] ?? [];
        $warehouse = is_array($warehouse) ? $warehouse : [];

        $salePrice = $this->numericOrNull($price['salePrice'] ?? null);
        $originPrice = $this->numericOrNull($price['originPrice'] ?? null);

        $discountAmount = null;

        if ($salePrice !== null && $originPrice !== null && $originPrice > $salePrice) {
            $discountAmount = $originPrice - $salePrice;
        }

        $publish = ($price['publish'] ?? false) === true;
        $availableQuantity = $this->intOrNull($warehouse['availableQuantity'] ?? null);

        $slug = $this->stringOrNull($raw['seoName'] ?? null);
        $unit = $this->stringOrNull($raw['uomName'] ?? null)
            ?? $this->stringOrNull($raw['uom'] ?? null);

        return new ProductSummary(
            source: $this->source(),
            sku: $sku,
            skuId: $id,
            name: $this->stringOrNull($raw['description'] ?? null),
            brand: $this->stringOrNull($raw['brandName'] ?? null),
            category: $this->stringOrNull($raw['mch5Name'] ?? null),
            price: $salePrice,
            originalPrice: $originPrice,
            discountAmount: $discountAmount,
            discountPercent: $this->numericOrNull($price['discountRate'] ?? null),
            imageUrl: $this->stringOrNull($raw['image'] ?? null),
            productUrl: $this->buildProductUrl($slug),
            stock: $availableQuantity,
            sellable: $publish && $availableQuantity !== null && $availableQuantity > 0,
            unit: $unit,
            slug: $slug,
            seller: self::SELLER,
            manufacturer: null,
            categories: null,
            rawData: $raw,
        );
    }

    /**
     * Build a safe product page URL including the store query, e.g.
     * https://www.winmart.vn/products/{seoName}?storeGroupCode=1998&storeCode=1535
     * Returns null unless the seoName is a plain path segment, so a
     * compromised API response cannot inject a scheme/domain.
     */
    private function buildProductUrl(?string $slug): ?string
    {
        if ($slug === null || strlen($slug) > 255) {
            return null;
        }

        if (preg_match('#^[A-Za-z0-9._~-]+$#', $slug) !== 1) {
            return null;
        }

        return self::PRODUCT_BASE_URL.'/products/'.$slug
            .'?storeGroupCode='.rawurlencode($this->storeGroupCode())
            .'&storeCode='.rawurlencode($this->storeNo());
    }

    /**
     * @param  array<string, mixed>|null  $rawData
     */
    private function quantityPerUnit(?array $rawData): ?int
    {
        if (! is_array($rawData)) {
            return null;
        }

        $quantity = $rawData['quantityPerUnit'] ?? null;

        if (! is_numeric($quantity) || (int) $quantity <= 0) {
            return null;
        }

        return (int) $quantity;
    }

    /**
     * The SKU is composed of a numeric itemNo plus a UOM code (e.g. "10008453G1").
     * Extract the numeric itemNo prefix to resolve a full SKU back to its item.
     */
    private function extractItemNo(string $identifier): ?string
    {
        if (preg_match('/^(\d{2,})/', $identifier, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.winmart.base_url'), '/');

        return $baseUrl !== '' ? $baseUrl : self::BASE_URL;
    }

    private function storeGroupCode(): string
    {
        $groupCode = (string) config('services.winmart.store_group_code');

        return trim($groupCode) !== '' ? trim($groupCode) : '1998';
    }

    private function storeNo(): string
    {
        $storeNo = (string) config('services.winmart.store_no');

        return trim($storeNo) !== '' ? trim($storeNo) : '1535';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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

        return (string) preg_replace('/\s+/u', ' ', $keyword);
    }

    private function searchCacheKey(string $keyword, int $page, int $perPage): string
    {
        $key = mb_strtolower($keyword);

        if (mb_strlen($key) > 60) {
            $key = md5($key);
        }

        return 'price_comparison:winmart:search:'.$this->storeGroupCode()
            .':'.$this->storeNo()
            .':'.$key.':'.$page.':'.$perPage;
    }

    private function productCacheKey(string $sku): string
    {
        return 'price_comparison:winmart:product:'.$this->storeGroupCode()
            .':'.$this->storeNo()
            .':'.md5(strtolower($sku));
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
