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
 * Kingfoodmart catalog provider.
 *
 * IMPORTANT: talks to an INTERNAL / UNDOCUMENTED API that was
 * reverse-engineered from the public Next.js bundles. It is NOT a public
 * or partner API and may change or break without notice. Keep this class
 * small and easy to replace.
 *
 * Verified live:
 *   GET https://onelife-api.kingfoodmart.com/v1/products/search
 *       ?type=NORMAL&keyword=...&page=1&limit=30
 *   GET https://onelife-api.kingfoodmart.com/v1/products/variants/{variantId}
 *
 * Search is public server-side: no Authorization header, no cookies and no
 * API key are required. The location/store scoping header
 * (X-Ol-Online-Store-Codes) is intentionally NOT sent in this phase because
 * the UI has no store selector yet; no store code is guessed or hard-coded.
 *
 * Search results map ONE representative variant per product. Cross-retailer
 * and full variant matching are reserved for a later phase.
 */
final class KingfoodmartProvider implements CatalogProvider
{
    private const BASE_URL = 'https://onelife-api.kingfoodmart.com/v1';

    private const SEARCH_PATH = '/products/search';

    private const DETAIL_PATH = '/products/variants/';

    private const PRODUCT_BASE_URL = 'https://kingfoodmart.com';

    private const TIMEOUT = 10;

    private const CONNECT_TIMEOUT = 5;

    private const SEARCH_CACHE_TTL = 300;

    private const DETAIL_CACHE_TTL = 600;

    private const MAX_PER_PAGE = 100;

    private const SEARCH_TYPE = 'NORMAL';

    private const SELLER = 'Kingfoodmart';

    public function source(): string
    {
        return 'kingfoodmart';
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

        if ($result === null) {
            return $this->emptyResult($page, $perPage);
        }

        Cache::put($cacheKey, $result, self::SEARCH_CACHE_TTL);

        return $result;
    }

    /**
     * Resolve a single product. The public identifier mapped into
     * ProductSummary::sku is the variant SKU/EAN, which is NOT a variant id.
     * The detail endpoint only accepts a variant id, so:
     *   1. try the identifier as a variant id (verified by the response), then
     *   2. resolve a variant id by searching for the identifier.
     * Returns null when no variant id can be resolved.
     */
    public function getProduct(string $sku): ?ProductSummary
    {
        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        $cacheKey = 'price_comparison:kingfoodmart:product:'.md5(strtolower($sku));

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

    private function requestSearch(string $keyword, int $page, int $perPage): ?SearchResult
    {
        $context = [
            'provider' => $this->source(),
            'keyword' => $keyword,
            'page' => $page,
            'per_page' => $perPage,
        ];

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->withHeaders(['X-Ol-Tenant' => $this->tenant()])
                ->get($this->baseUrl().self::SEARCH_PATH, [
                    'type' => self::SEARCH_TYPE,
                    'keyword' => $keyword,
                    'page' => $page,
                    'limit' => $perPage,
                ]);
        } catch (Throwable $e) {
            Log::warning('PriceComparison: Kingfoodmart search request failed (connection/timeout)', $context + ['message' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('PriceComparison: Kingfoodmart search HTTP error', $context + ['status' => $response->status()]);

            return null;
        }

        $json = $this->decodeJson($response->body());

        if ($json === null) {
            Log::warning('PriceComparison: Kingfoodmart search malformed JSON response', $context + ['status' => $response->status()]);

            return null;
        }

        return $this->mapSearchResponse($json, $page, $perPage, $context);
    }

    private function requestProduct(string $identifier): ?ProductSummary
    {
        // A variant id is numeric; try it directly first. If the identifier is
        // an EAN/SKU the detail call fails and we fall back to a search lookup.
        if (ctype_digit($identifier)) {
            $product = $this->requestDetail($identifier);

            if ($product !== null) {
                return $product;
            }
        }

        $variantId = $this->resolveVariantId($identifier);

        if ($variantId === null || $variantId === $identifier) {
            return null;
        }

        return $this->requestDetail($variantId);
    }

    private function requestDetail(string $variantId): ?ProductSummary
    {
        $context = [
            'provider' => $this->source(),
            'variant_id' => $variantId,
        ];

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->withHeaders(['X-Ol-Tenant' => $this->tenant()])
                ->get($this->baseUrl().self::DETAIL_PATH.rawurlencode($variantId));
        } catch (Throwable $e) {
            Log::warning('PriceComparison: Kingfoodmart detail request failed (connection/timeout)', $context + ['message' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('PriceComparison: Kingfoodmart detail HTTP error', $context + ['status' => $response->status()]);

            return null;
        }

        $json = $this->decodeJson($response->body());

        if ($json === null) {
            Log::warning('PriceComparison: Kingfoodmart detail malformed JSON response', $context + ['status' => $response->status()]);

            return null;
        }

        try {
            return $this->mapProduct($json);
        } catch (Throwable $e) {
            Log::warning('PriceComparison: Kingfoodmart detail mapping error', $context + ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Resolve a variant id from a SKU/EAN or product identifier by searching.
     */
    private function resolveVariantId(string $identifier): ?string
    {
        $result = $this->requestSearch($identifier, 1, 50);

        if ($result === null) {
            return null;
        }

        foreach ($result->items as $item) {
            $raw = $item->rawData;

            if (! is_array($raw)) {
                continue;
            }

            $variants = $raw['variants'] ?? null;

            if (is_array($variants)) {
                foreach ($variants as $variant) {
                    if (! is_array($variant)) {
                        continue;
                    }

                    if ((string) ($variant['sku'] ?? '') === $identifier) {
                        return $this->stringOrNull($variant['id'] ?? null)
                            ?? $this->stringOrNull($raw['pid'] ?? null);
                    }
                }
            }

            if ((string) ($raw['pid'] ?? '') === $identifier) {
                return $identifier;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $context
     */
    private function mapSearchResponse(array $json, int $page, int $perPage, array $context): SearchResult
    {
        $rawProducts = $json['products'] ?? [];

        if (! is_array($rawProducts)) {
            Log::warning('PriceComparison: Kingfoodmart search response has invalid products', $context);

            $rawProducts = [];
        }

        $responseCategories = $json['categories'] ?? null;
        $responseCategories = is_array($responseCategories) ? $responseCategories : null;

        $items = new Collection;

        foreach ($rawProducts as $rawProduct) {
            try {
                if (! is_array($rawProduct)) {
                    continue;
                }

                $item = $this->mapProduct($rawProduct, $responseCategories);

                if ($item !== null) {
                    $items->push($item);
                }
            } catch (Throwable $e) {
                Log::warning('PriceComparison: skip Kingfoodmart product mapping error', $context + ['message' => $e->getMessage()]);
            }
        }

        $pagination = $json['pagination'] ?? [];
        $pagination = is_array($pagination) ? $pagination : [];

        $total = (int) ($pagination['total'] ?? 0);

        $apiPage = $pagination['currentPage'] ?? null;
        $resultPage = is_numeric($apiPage) ? (int) $apiPage : $page;

        $apiLimit = $pagination['limit'] ?? null;
        $resultPerPage = is_numeric($apiLimit) && (int) $apiLimit > 0 ? (int) $apiLimit : $perPage;

        $apiLastPage = $pagination['lastPage'] ?? null;

        if (is_numeric($apiLastPage)) {
            $totalPages = max(0, (int) $apiLastPage);
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
     * @param  array<int, mixed>|null  $responseCategories
     */
    private function mapProduct(array $raw, ?array $responseCategories = null): ?ProductSummary
    {
        $name = $this->stringOrNull($raw['name'] ?? null);

        if ($name === null) {
            return null;
        }

        $variant = $this->representativeVariant($raw);

        $variantSku = $this->stringOrNull($variant['sku'] ?? null);
        $pid = $this->stringOrNull($raw['pid'] ?? null);
        $id = $this->stringOrNull($raw['id'] ?? null);

        $sku = $variantSku ?? $pid ?? $id;

        if ($sku === null) {
            return null;
        }

        $price = $this->numericOrNull($raw['discountPrice'] ?? null);
        $originalPrice = $this->numericOrNull($raw['originalPrice'] ?? null);

        $discountAmount = null;

        if ($price !== null && $originalPrice !== null) {
            $discountAmount = max(0, $originalPrice - $price);
        }

        $stock = $this->intOrNull($raw['inStock'] ?? null);

        if ($stock === null) {
            $stock = $this->intOrNull($variant['stockItem']['quantity'] ?? null);
        }

        $categories = $raw['categories'] ?? null;

        if (! is_array($categories)) {
            $categories = $responseCategories;
        }

        return new ProductSummary(
            source: $this->source(),
            sku: $sku,
            skuId: $pid ?? $this->stringOrNull($variant['id'] ?? null),
            name: $name,
            barcode: $variantSku,
            brand: $this->brand($raw),
            category: $this->stringOrNull($raw['subCateName'] ?? null) ?? $this->stringOrNull($raw['subCate'] ?? null),
            price: $price,
            originalPrice: $originalPrice,
            discountAmount: $discountAmount,
            discountPercent: $this->numericOrNull($raw['discountPercent'] ?? null),
            imageUrl: $this->imageUrl($raw),
            productUrl: $this->buildProductUrl($raw['subCate'] ?? null, $raw['slug'] ?? null),
            stock: $stock,
            sellable: $this->isSellable($raw, $variant),
            unit: $this->stringOrNull($variant['unit']['name'] ?? null),
            slug: $this->stringOrNull($raw['slug'] ?? null),
            seller: $this->seller($raw),
            manufacturer: $this->manufacturer($raw),
            categories: $categories,
            rawData: $raw,
            promotions: $this->mapPromotions($variant),
        );
    }

    /**
     * Map the customer-facing promotion summaries attached to the given
     * variant into the shared `promotions` contract ([['title' => ...]]).
     *
     * Only the representative variant is inspected (see
     * representativeVariant()): promotions attached exclusively to other
     * variants of the same product are intentionally ignored in this phase.
     *
     * Priority: variants[].promotionInfoItems[].promotionSummary, falling back
     * to variants[].promotionInfo.promotionSummary only when the items list
     * yields no non-empty summary. Summaries are trimmed and de-duplicated;
     * an empty result maps to null.
     *
     * The summary is displayed verbatim: buy/gift quantities are NOT parsed
     * and price discounts are NOT synthesised into promotion text.
     *
     * @param  array<string, mixed>  $variant
     * @return array<int, array{title: string}>|null
     */
    private function mapPromotions(array $variant): ?array
    {
        $summaries = $this->promotionSummaries($variant['promotionInfoItems'] ?? null);

        if ($summaries === []) {
            $info = $variant['promotionInfo'] ?? null;

            if (is_array($info)) {
                $summary = $this->stringOrNull($info['promotionSummary'] ?? null);

                if ($summary !== null) {
                    $summaries[] = $summary;
                }
            }
        }

        if ($summaries === []) {
            return null;
        }

        $unique = array_values(array_unique($summaries));

        return array_map(fn (string $title): array => ['title' => $title], $unique);
    }

    /**
     * Extract non-empty, trimmed promotionSummary values from a
     * promotionInfoItems list.
     *
     * @return array<int, string>
     */
    private function promotionSummaries(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $summaries = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $summary = $this->stringOrNull($item['promotionSummary'] ?? null);

            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * Search results map ONE representative variant per product: the first
     * variant that is on sale, otherwise the first variant. Full variant
     * matching is reserved for a later phase.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function representativeVariant(array $raw): array
    {
        $variants = $raw['variants'] ?? null;

        if (! is_array($variants) || $variants === []) {
            return [];
        }

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            if (($variant['isOnlineSale'] ?? null) === true || ($variant['isSale'] ?? null) === true) {
                return $variant;
            }
        }

        foreach ($variants as $variant) {
            if (is_array($variant)) {
                return $variant;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $variant
     */
    private function isSellable(array $raw, array $variant): bool
    {
        if (($raw['isActive'] ?? false) !== true) {
            return false;
        }

        if (array_key_exists('isOnlineSale', $variant) && $variant['isOnlineSale'] !== true) {
            return false;
        }

        if (array_key_exists('isSale', $variant) && $variant['isSale'] !== true) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function brand(array $raw): ?string
    {
        $direct = $this->stringOrNull($raw['brand'] ?? null);

        if ($direct !== null) {
            return $direct;
        }

        $detail = $raw['brandDetail'] ?? null;

        return is_array($detail) ? $this->stringOrNull($detail['name'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function manufacturer(array $raw): ?string
    {
        $description = $raw['descriptionJson'] ?? null;

        return is_array($description) ? $this->stringOrNull($description['producer'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function seller(array $raw): string
    {
        $currentSeller = $raw['currentSeller'] ?? null;

        if (is_array($currentSeller)) {
            $seller = $this->stringOrNull($currentSeller['name'] ?? null);

            if ($seller !== null) {
                return $seller;
            }
        }

        return $this->stringOrNull($currentSeller) ?? self::SELLER;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function imageUrl(array $raw): ?string
    {
        $thumbnail = $this->stringOrNull($raw['thumbnail'] ?? null);

        if ($thumbnail !== null) {
            return $thumbnail;
        }

        $images = $raw['images'] ?? null;

        if (! is_array($images) || ! isset($images[0])) {
            return null;
        }

        $first = $images[0];

        if (is_string($first)) {
            return $this->stringOrNull($first);
        }

        if (is_array($first)) {
            return $this->stringOrNull($first['url'] ?? $first['thumbnail'] ?? null);
        }

        return null;
    }

    /**
     * Build a safe canonical product URL: /{subCate}/{slug}. The bare
     * /{slug} path is intentionally avoided because it redirects to the
     * homepage. Returns null unless both segments are plain slugs, so a
     * compromised API response cannot inject a scheme/domain.
     */
    private function buildProductUrl(mixed $subCate, mixed $slug): ?string
    {
        $subCateSegment = $this->pathSegment($subCate);
        $slugSegment = $this->pathSegment($slug);

        if ($subCateSegment === null || $slugSegment === null) {
            return null;
        }

        return self::PRODUCT_BASE_URL.'/'.$subCateSegment.'/'.$slugSegment;
    }

    private function pathSegment(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 255) {
            return null;
        }

        if (preg_match('#^[A-Za-z0-9._~-]+$#', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        if ($value === null) {
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

        return 'price_comparison:kingfoodmart:search:'.$key.':'.$page.':'.$perPage;
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

    private function baseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.kingfoodmart.base_url'), '/');

        return $baseUrl !== '' ? $baseUrl : self::BASE_URL;
    }

    private function tenant(): string
    {
        $tenant = trim((string) config('services.kingfoodmart.tenant'));

        return $tenant !== '' ? $tenant : 'kingfood';
    }
}
