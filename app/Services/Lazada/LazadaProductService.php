<?php

namespace App\Services\Lazada;

use App\Services\Lazada\DTOs\LazadaProductLinkDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lazada product link + commission preview.
 *
 * Primary API (IN USE per the docs):
 *   /marketing/getlink  (inputType=url) → productId, regularPromotionLink,
 *     regularCommission (CPS rate), subId1..subId6 appended by the API.
 *
 * Supplementary (best-effort, IN USE):
 *   /marketing/product/feed (productIds=...) → productName, pictures,
 *     discountPrice, totalCommissionRate / totalCommissionAmount.
 *
 * The affiliate link is returned verbatim from the API (never rebuilt by string
 * manipulation here). A short result cache avoids hammering the shared getlink
 * QPS pool when the same URL is pasted repeatedly.
 */
class LazadaProductService
{
    public function __construct(private readonly LazadaApiClient $client)
    {
    }

    public function createLink(string $url, ?string $subId1 = null, ?string $subId2 = null): LazadaProductLinkDTO
    {
        $cacheKey = 'lazada:link:v1:' . sha1($url . '|' . ($subId1 ?? '') . '|' . ($subId2 ?? ''));

        return Cache::remember($cacheKey, 600, function () use ($url, $subId1, $subId2) {
            return $this->fetchLink($url, $subId1, $subId2);
        });
    }

    private function fetchLink(string $url, ?string $subId1, ?string $subId2): LazadaProductLinkDTO
    {
        $payload = $this->client->request('marketing/getlink', [
            'inputType'  => 'url',
            'inputValue' => $url,
            'subId1'     => $subId1,
            'subId2'     => $subId2,
        ]);

        $data = $payload['result']['data'] ?? $payload['data'] ?? [];
        $list = $data['urlBatchGetLinkInfoList'] ?? $data['batchGetLinkInfoList'] ?? [];
        $item = is_array($list) ? (reset($list) ?: null) : null;

        if (!is_array($item)) {
            $this->throwIfBatchError($data);

            throw new LazadaException(
                '[LazadaProductService] getlink returned no item for url',
                0,
                'Không thể tạo link Lazada cho sản phẩm này.',
            );
        }

        $this->throwIfItemError($item);

        $affiliateUrl = (string) ($item['regularPromotionLink'] ?? '');
        if ($affiliateUrl === '') {
            throw new LazadaException(
                '[LazadaProductService] getlink returned empty promotion link',
                0,
                'Không thể tạo link Lazada cho sản phẩm này.',
            );
        }

        $productId = ($item['productId'] ?? null) !== null ? (string) $item['productId'] : null;
        $commissionRate = $this->parsePercent($item['regularCommission'] ?? null);

        $details = $this->fetchProductDetails($productId);

        return new LazadaProductLinkDTO(
            productId: $productId,
            productName: $details['name'] ?? ($item['productName'] ?? null),
            productImage: $details['image'] ?? null,
            productPrice: $details['price'],
            currency: $details['currency'],
            commissionRatePct: $commissionRate ?? $details['rate'],
            commissionAmount: $details['amount'],
            affiliateUrl: $affiliateUrl,
        );
    }

    /**
     * Best-effort product feed lookup for name/image/price/commission amount.
     * A failure here must not break link creation.
     *
     * @return array{name: ?string, image: ?string, price: ?float, currency: ?string, rate: ?float, amount: ?float}
     */
    private function fetchProductDetails(?string $productId): array
    {
        $empty = ['name' => null, 'image' => null, 'price' => null, 'currency' => null, 'rate' => null, 'amount' => null];

        if ($productId === null || $productId === '') {
            return $empty;
        }

        try {
            $payload = $this->client->request('marketing/product/feed', [
                'offerType'   => '1',
                'productIds'  => json_encode([$productId]),
                'limit'       => '1',
                'page'        => '1',
            ]);
        } catch (Throwable $e) {
            Log::warning('[LazadaProductService] product feed failed (best-effort)', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);

            return $empty;
        }

        $feedData = $payload['result']['data'] ?? $payload['data'] ?? $payload;
        $list = $feedData['productList'] ?? $feedData['products'] ?? $feedData['list'] ?? $feedData['itemList'] ?? null;

        if ($list === null) {
            $first = is_array($feedData) ? (reset($feedData) ?: null) : null;
            $list = is_array($first) && isset($first['productId']) ? $feedData : [];
        }

        $product = is_array($list) ? (reset($list) ?: null) : null;
        if (!is_array($product)) {
            return $empty;
        }

        $pictures = $product['pictures'] ?? null;
        if (is_string($pictures)) {
            $pictures = array_values(array_filter(array_map('trim', explode(',', $pictures))));
        }
        $image = is_array($pictures) && count($pictures) > 0 && is_string($pictures[0]) && $pictures[0] !== ''
            ? $pictures[0]
            : null;

        $price = isset($product['discountPrice']) && is_numeric($product['discountPrice'])
            ? (float) $product['discountPrice']
            : null;

        return [
            'name'      => isset($product['productName']) ? (string) $product['productName'] : null,
            'image'     => is_string($image) && $image !== '' ? $image : null,
            'price'     => $price,
            'currency'  => isset($product['currency']) ? (string) $product['currency'] : null,
            'rate'      => $this->parsePercent($product['totalCommissionRate'] ?? null),
            'amount'    => isset($product['totalCommissionAmount']) && is_numeric($product['totalCommissionAmount'])
                ? (float) $product['totalCommissionAmount']
                : null,
        ];
    }

    /**
     * When the URL batch comes back empty, the API can still surface per-URL
     * errors in `errorInfoList` at the batch level. Surface a product-not-found
     * message if that is what the error indicates.
     */
    private function throwIfBatchError(array $data): void
    {
        $errorInfo = $data['errorInfoList'] ?? null;
        if (!is_array($errorInfo) || count($errorInfo) === 0) {
            return;
        }

        $entry = reset($errorInfo);
        if (!is_array($entry)) {
            return;
        }

        $code = (string) ($entry['errorCode'] ?? 'UNKNOWN');
        $message = strtolower((string) ($entry['errorMsg'] ?? $entry['error_msg'] ?? ''));

        if ($code === '' || $code === '2001' || $code === '2007' || str_contains($message, 'not found') || str_contains($message, 'invalid product')) {
            throw new LazadaException(
                '[LazadaProductService] getlink batch error (' . $code . ' ' . $message . ')',
                0,
                'Không tìm thấy sản phẩm Lazada cho link này hoặc sản phẩm không có commission.',
            );
        }

        throw new LazadaException(
            '[LazadaProductService] getlink batch error (' . $code . ' ' . $message . ')',
            0,
            'Không thể tạo affiliate link Lazada. Vui lòng thử lại sau.',
        );
    }

    private function throwIfItemError(array $item): void
    {
        $errorEntry = null;
        $errorInfo = $item['errorInfoList'] ?? null;

        if (is_array($errorInfo)) {
            $errorEntry = reset($errorInfo) ?: null;
        } elseif (isset($item['errorCode']) && $item['errorCode'] !== null && $item['errorCode'] !== '') {
            $errorEntry = $item;
        }

        if (!is_array($errorEntry)) {
            return;
        }

        $code = (string) ($errorEntry['errorCode'] ?? 'UNKNOWN');
        $message = strtolower((string) ($errorEntry['errorMsg'] ?? $errorEntry['error_msg'] ?? ''));

        if ($code === '' || $code === '2001' || $code === '2007' || str_contains($message, 'not found') || str_contains($message, 'invalid product')) {
            throw new LazadaException(
                '[LazadaProductService] getlink item error (' . $code . ' ' . $message . ')',
                0,
                'Không tìm thấy sản phẩm Lazada cho link này hoặc sản phẩm không có commission.',
            );
        }

        throw new LazadaException(
            '[LazadaProductService] getlink item error (' . $code . ' ' . $message . ')',
            0,
            'Không thể tạo affiliate link Lazada. Vui lòng thử lại sau.',
        );
    }

    public function parsePercent(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        $clean = trim((string) str_replace('%', '', (string) $value));
        if (!is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }
}