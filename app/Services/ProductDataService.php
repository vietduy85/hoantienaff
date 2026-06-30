<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductDataService
{
    private const API_URL = 'https://data.addlivetag.com/product-data/product-data.php';

    private const RETRY_TIMES = 2;

    private const TIMEOUT = 10;

    private const CONNECT_TIMEOUT = 5;

    /*
     * TODO: Add local cache layer for frequently accessed products.
     * TODO: Implement Redis cache with 24h TTL (matching AddLiveTag's cache duration).
     * TODO: Replace direct HTTP calls with a Product Repository abstraction.
     */

    public function getByUrl(string $url): array
    {
        $ids = $this->extractProductIds($url);

        if ($ids === null || $ids['item_id'] === null) {
            return ['success' => false];
        }

        return $this->getByItemId($ids['item_id'], $ids['shop_id']);
    }

    private function extractProductIds(string $url): ?array
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $query = parse_url($url, PHP_URL_QUERY) ?? '';

        // ?item_id=... or ?itemId=...
        parse_str($query, $params);

        if (isset($params['item_id']) && ctype_digit((string) $params['item_id'])) {
            return [
                'shop_id' => isset($params['shop_id']) && ctype_digit((string) $params['shop_id'])
                    ? (int) $params['shop_id'] : null,
                'item_id' => (int) $params['item_id'],
            ];
        }

        if (isset($params['itemId']) && ctype_digit((string) $params['itemId'])) {
            return [
                'shop_id' => isset($params['shopId']) && ctype_digit((string) $params['shopId'])
                    ? (int) $params['shopId'] : null,
                'item_id' => (int) $params['itemId'],
            ];
        }

        // /product/<shop>/<item>
        if (preg_match('#/product/(\d+)/(\d+)#', $path, $m)) {
            return [
                'shop_id' => (int) $m[1],
                'item_id' => (int) $m[2],
            ];
        }

        // /opaanlp/<shop>/<item>
        if (preg_match('#/opaanlp/(\d+)/(\d+)#', $path, $m)) {
            return [
                'shop_id' => (int) $m[1],
                'item_id' => (int) $m[2],
            ];
        }

        // -i.<shop>.<item> in path
        if (preg_match('#\-i\.(\d+)\.(\d+)#', $path, $m)) {
            return [
                'shop_id' => (int) $m[1],
                'item_id' => (int) $m[2],
            ];
        }

        return null;
    }

    private function getByItemId(int $itemId, ?int $shopId = null): array
    {
        try {
            $response = Http::retry(self::RETRY_TIMES, 500, function (\Throwable $e) use ($itemId) {
                Log::warning('ProductDataService: retrying after exception', [
                    'item_id' => $itemId,
                    'message' => $e->getMessage(),
                ]);

                return true;
            })
                ->timeout(self::TIMEOUT)
                ->get(self::API_URL, ['item_id' => $itemId]);

            if ($response->failed()) {
                Log::warning('ProductDataService: HTTP error', [
                    'item_id' => $itemId,
                    'status' => $response->status(),
                ]);

                return ['success' => false];
            }

            $json = $response->json();

            if (($json['status'] ?? '') !== 'success') {
                Log::warning('ProductDataService: API returned non-success status', [
                    'item_id' => $itemId,
                    'status' => $json['status'] ?? 'unknown',
                ]);

                return ['success' => false];
            }

            $result = $this->mapResponse($json);

            if (!empty($json['warning'])) {
                Log::info('ProductDataService: API warning', [
                    'item_id' => $itemId,
                    'warning' => $json['warning'],
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('ProductDataService: unrecoverable exception', [
                'item_id' => $itemId,
                'message' => $e->getMessage(),
            ]);

            return ['success' => false];
        }
    }

    private function mapResponse(array $json): array
    {
        $info = $json['productInfo'] ?? [];

        $commission = (int) ($info['commission'] ?? 0);

        return [
            'success'           => true,
            'item_id'           => $info['itemId'] ?? null,
            'shop_id'           => $info['shopId'] ?? null,
            'product_name'      => $info['productName'] ?? null,
            'product_price'     => $info['price'] ?? null,
            'commission'        => $commission,
            'seller_commission' => $info['sellerComFinal'] ?? null,
            'shopee_commission' => $info['shopeeComFinal'] ?? null,
            'rating'            => $info['rating'] ?? null,
            'product_image'     => $info['imageUrl'] ?? null,
            'product_link'      => $info['productLink'] ?? null,
            'shop_name'         => $info['shopName'] ?? null,
            'sales'             => $info['sales'] ?? null,
            'is_xtra'           => !empty($info['isXtra']),
            'data_source'       => $info['dataSource'] ?? null,
        ];
    }
}
