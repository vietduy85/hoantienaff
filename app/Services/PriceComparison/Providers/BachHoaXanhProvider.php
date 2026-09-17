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
 * Bách Hóa Xanh catalog provider.
 *
 * IMPORTANT: This talks to an INTERNAL / UNDOCUMENTED API that was
 * reverse-engineered from the public website bundles. It is NOT a public
 * or partner API and may change or break without notice. Keep this class
 * small and easy to replace.
 *
 * Verified live:
 *   POST https://api.bachhoaxanh.com/gw/search/v2/DataSearch
 *   body {"keywords":"...","pageIndex":0,"pageSize":20,"storeId":2546}
 *
 * The API is location dependent: "storeId" is required and drives price,
 * stock and availability. It is read from config only (BHX_STORE_ID) and
 * never inferred from the visitor (IP/geolocation/browser).
 *
 * The search response is the richest source for price/stock/image. The
 * product detail endpoint returns metadata/promotions only (no price,
 * stock, image or specifications), so it is intentionally not used here.
 */
final class BachHoaXanhProvider implements CatalogProvider
{
    private const PRODUCT_BASE_URL = 'https://www.bachhoaxanh.com';

    private const SEARCH_PATH = '/search/v2/DataSearch';

    private const TIMEOUT = 10;

    private const CONNECT_TIMEOUT = 5;

    private const SEARCH_CACHE_TTL = 300;

    private const DETAIL_CACHE_TTL = 600;

    private const MAX_PER_PAGE = 50;

    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    private const SELLER = 'Bách Hóa Xanh';

    public function source(): string
    {
        return 'bach_hoa_xanh';
    }

    /**
     * Whether the provider has enough configuration to call the API.
     * Without a store id the API returns no products, so we refuse to call.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->storeId() !== null;
    }

    /**
     * @param  array<string, mixed>  $filters  optional brand_ids, category_ids, sort(_str), priority_category_id, province_id, ward_id
     */
    public function search(string $keyword, int $page = 1, int $perPage = 20, array $filters = []): SearchResult
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), self::MAX_PER_PAGE);
        $keyword = $this->normalizeKeyword($keyword);

        if ($keyword === '') {
            return $this->emptyResult($page, $perPage);
        }

        if (! $this->isConfigured()) {
            Log::warning('PriceComparison: BHX provider is not configured (missing store id)', [
                'provider' => $this->source(),
                'keyword'  => $keyword,
            ]);

            return $this->emptyResult($page, $perPage);
        }

        $cacheKey = $this->searchCacheKey($keyword, $page, $perPage, $filters);

        $cached = Cache::get($cacheKey);

        if ($cached instanceof SearchResult) {
            return $cached;
        }

        $result = $this->requestSearch($keyword, $page, $perPage, $filters);

        if ($result === null) {
            return $this->emptyResult($page, $perPage);
        }

        Cache::put($cacheKey, $result, self::SEARCH_CACHE_TTL);

        return $result;
    }

    public function getProduct(string $sku): ?ProductSummary
    {
        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        if (! $this->isConfigured()) {
            Log::warning('PriceComparison: BHX provider is not configured (missing store id)', [
                'provider' => $this->source(),
                'sku'      => $sku,
            ]);

            return null;
        }

        $cacheKey = 'price_comparison:bhx:product:'.$this->storeId().':'.md5(strtolower($sku));

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

    private function requestSearch(string $keyword, int $page, int $perPage, array $filters): ?SearchResult
    {
        $payload = [
            'keywords'  => $keyword,
            'pageIndex' => $page - 1,
            'pageSize'  => $perPage,
            'storeId'   => $this->storeId(),
        ];

        foreach ($this->optionalFields($filters) as $field => $value) {
            $payload[$field] = $value;
        }

        $context = [
            'provider' => $this->source(),
            'keyword'  => $keyword,
            'page'     => $page,
            'per_page' => $perPage,
        ];

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withUserAgent($this->userAgent())
                ->acceptJson()
                ->post($this->baseUrl().self::SEARCH_PATH, $payload);
        } catch (Throwable $e) {
            Log::warning('PriceComparison: BHX search request failed (connection/timeout)', $context + ['message' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('PriceComparison: BHX search HTTP error', $context + ['status' => $response->status()]);

            return null;
        }

        $json = $this->decodeJson($response->body());

        if ($json === null) {
            Log::warning('PriceComparison: BHX search malformed JSON response', $context + ['status' => $response->status()]);

            return null;
        }

        if (! $this->isSuccessCode($json['code'] ?? null)) {
            Log::warning('PriceComparison: BHX search returned a non-success code', $context + ['code' => $json['code'] ?? null]);

            return null;
        }

        $data = $json['data'] ?? null;

        if (! is_array($data)) {
            Log::warning('PriceComparison: BHX search response missing data', $context);

            return null;
        }

        $rawProducts = $data['products'] ?? [];

        if (! is_array($rawProducts)) {
            Log::warning('PriceComparison: BHX search response has invalid products', $context);

            $rawProducts = [];
        }

        $items = new Collection;

        foreach ($rawProducts as $rawProduct) {
            try {
                if (! is_array($rawProduct)) {
                    continue;
                }

                $item = $this->mapProduct($rawProduct);

                if ($item !== null) {
                    $items->push($item);
                }
            } catch (Throwable $e) {
                Log::warning('PriceComparison: skip BHX product mapping error', $context + ['message' => $e->getMessage()]);
            }
        }

        $total = (int) ($data['total'] ?? 0);

        return new SearchResult(
            items: $items,
            page: $page,
            perPage: $perPage,
            total: $total,
            totalPages: $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        );
    }

    private function requestProduct(string $sku): ?ProductSummary
    {
        // The detail endpoint does not return price/stock/image, so we look the
        // product up through search, which is the richer source.
        $result = $this->requestSearch($sku, 1, self::MAX_PER_PAGE, []);

        if ($result === null) {
            return null;
        }

        foreach ($result->items as $item) {
            if ($item->sku === $sku) {
                return $item;
            }
        }

        return null;
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
        $price = $raw['productPrices'][0] ?? null;
        $price = is_array($price) ? $price : [];

        $priceValue = $this->numericOrNull($price['price'] ?? null);
        $originalPrice = $this->numericOrNull($price['sysPrice'] ?? null);

        $discountAmount = null;

        if ($priceValue !== null && $originalPrice !== null && $originalPrice > $priceValue) {
            $discountAmount = $originalPrice - $priceValue;
        }

        $category = $raw['category'] ?? null;
        $category = is_array($category) ? $category : null;

        return new ProductSummary(
            source: $this->source(),
            sku: $id,
            name: $this->stringOrNull($raw['name'] ?? null),
            barcode: $this->stringOrNull($raw['productCode'] ?? null),
            brand: $this->stringOrNull($raw['brandName'] ?? null),
            category: $this->stringOrNull($category['name'] ?? null),
            price: $priceValue,
            originalPrice: $originalPrice,
            discountAmount: $discountAmount,
            discountPercent: $this->numericOrNull($price['discountPercent'] ?? null),
            imageUrl: $this->stringOrNull($raw['avatar'] ?? null),
            productUrl: $this->buildProductUrl($raw['url'] ?? null),
            stock: $this->intOrNull($price['quantity'] ?? null),
            sellable: (bool) ($price['isCanBuy'] ?? false) && (int) ($price['status'] ?? 0) === 1,
            unit: $this->stringOrNull($raw['unit'] ?? null),
            slug: $this->slugFromUrl($raw['url'] ?? null),
            seller: self::SELLER,
            manufacturer: null,
            categories: $category !== null ? [$category] : null,
            rawData: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function optionalFields(array $filters): array
    {
        $candidates = [
            'provinceId'         => $filters['province_id'] ?? config('services.bachhoaxanh.province_id'),
            'wardId'             => $filters['ward_id'] ?? config('services.bachhoaxanh.ward_id'),
            'brandIds'           => $filters['brand_ids'] ?? null,
            'categoryIds'        => $filters['category_ids'] ?? null,
            'sortStr'            => $filters['sort_str'] ?? $filters['sort'] ?? null,
            'priorityCategoryId' => $filters['priority_category_id'] ?? null,
        ];

        $fields = [];

        foreach ($candidates as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(',', array_filter(array_map('strval', $value), fn (string $v): bool => $v !== ''));

                if ($value === '') {
                    continue;
                }
            }

            $fields[$field] = is_numeric($value) ? (int) $value : (string) $value;
        }

        return $fields;
    }

    /**
     * Build a safe product URL from the API-provided relative path.
     * Returns null when the value is not a plain relative path, so a
     * compromised API response cannot inject an arbitrary scheme/domain.
     */
    private function buildProductUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '//') || ! str_starts_with($url, '/')) {
            return null;
        }

        return rtrim(self::PRODUCT_BASE_URL, '/').$url;
    }

    private function slugFromUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $path = parse_url(trim($url), PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            $path = trim($url);
        }

        $path = rtrim($path, '/');
        $segment = substr($path, (int) strrpos($path, '/') + 1);
        $segment = trim($segment);

        return $segment !== '' ? $segment : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
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

    private function isSuccessCode(mixed $code): bool
    {
        if ($code === null) {
            return true;
        }

        if (is_numeric($code)) {
            return (int) $code === 0;
        }

        return false;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.bachhoaxanh.base_url'), '/');
    }

    private function storeId(): int|string|null
    {
        $store = config('services.bachhoaxanh.store_id');

        if ($store === null || $store === '') {
            return null;
        }

        return is_numeric($store) ? (int) $store : (string) $store;
    }

    private function userAgent(): string
    {
        $userAgent = trim((string) config('services.bachhoaxanh.user_agent'));

        return $userAgent !== '' ? $userAgent : self::DEFAULT_USER_AGENT;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function searchCacheKey(string $keyword, int $page, int $perPage, array $filters): string
    {
        $filterHash = md5((string) json_encode($this->optionalFields($filters)));

        return 'price_comparison:bhx:search:'.$this->storeId()
            .':'.md5(mb_strtolower($keyword))
            .':'.$page.':'.$perPage.':'.$filterHash;
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
