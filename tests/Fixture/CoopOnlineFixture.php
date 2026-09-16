<?php

namespace Tests\Fixture;

/**
 * Representative response fixtures from the audited Co.op Online
 * (Teko discovery) API. Trimmed to the fields needed for mapping.
 *
 * Verified live on 2026-09-16:
 *   POST https://discovery.tekoapis.com/api/v1/search
 *   GET  https://discovery.tekoapis.com/api/v1/product?sku=...&terminalId=26607
 */
class CoopOnlineFixture
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function searchResponse(array $overrides = []): array
    {
        return array_replace_recursive(self::searchBase(), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function searchBase(): array
    {
        return [
            'code'       => '0',
            'message'    => 'success',
            'pagination' => [
                'totalItems' => 36,
                'totalPages' => 2,
            ],
            'result' => [
                'products' => [
                    self::products()[0],
                    self::products()[1],
                    self::products()[2],
                    self::products()[3],
                ],
                'filter' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function detailResponse(array $overrides = []): array
    {
        return array_replace_recursive(self::detailBase(), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function detailBase(): array
    {
        return [
            'code'    => '0',
            'message' => 'success',
            'result'  => [
                'product' => self::products()[0],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function products(): array
    {
        return [
            [
                'productInfo' => [
                    'sku'          => '250100313',
                    'skuId'        => '250100313',
                    'name'         => 'Mì Hảo Hảo vị gà vang thùng 30 x 74g',
                    'imageUrl'     => 'https://lh3.googleusercontent.com/9cvptfwJcjmim6OvSyfE2WTzb6oplDApMf_JGYZcVkrETQMayQ4Kqp7ac5m1NPPDmzDVlfdEf9QAQ4VCoi9GgNzaE_eilUaR',
                    'slug'         => 'mi-hao-hao-vi-mi-ga-vang-thung-30-x-74g',
                    'barcode'      => '2000130544250',
                    'uomName'      => 'Thùng',
                    'uomCode'      => '52',
                    'manufacturer' => 'Acecook Việt Nam',
                    'brand'        => ['code' => 'hao-hao', 'name' => 'Hảo Hảo'],
                    'seller'       => ['id' => 2724, 'name' => 'Saigon Co.op'],
                    'categories'   => [
                        ['code' => 'NH07', 'name' => 'Gia vị, gạo, thực phẩm khô', 'id' => 396528],
                        ['code' => 'NH07-01-02', 'name' => 'Mì gói ăn liền', 'id' => 396531],
                    ],
                ],
                'prices' => [
                    [
                        'supplierRetailPrice' => '131000',
                        'terminalPrice'       => '122500',
                        'latestPrice'         => '122500',
                        'discountAmount'      => '8500',
                        'discountPercent'     => 6,
                        'sellPrice'           => '131000',
                        'minLatestPrice'      => '122500',
                        'maxLatestPrice'      => '122500',
                    ],
                ],
                'totalAvailable' => 12,
                'status'         => ['sellingCode' => '', 'sellable' => true],
            ],
            [
                'productInfo' => [
                    'sku'        => '250100218',
                    'skuId'      => '250100218',
                    'name'       => 'Mì Hảo Hảo vị gà vàng gói 74g',
                    'imageUrl'   => 'https://lh3.googleusercontent.com/sample-250100218',
                    'slug'       => 'mi-hao-hao-vi-ga-vang-goi-74g',
                    'barcode'    => '8934565037216',
                    'uomName'    => 'Gói',
                    'brand'      => ['code' => 'hao-hao', 'name' => 'Hảo Hảo'],
                    'categories' => [
                        ['code' => 'NH07-01-02', 'name' => 'Mì gói ăn liền', 'id' => 396531],
                    ],
                ],
                'prices' => [
                    [
                        'latestPrice'     => '3500',
                        'sellPrice'       => '3500',
                        'discountAmount'  => '0',
                        'discountPercent' => 0,
                    ],
                ],
                'totalAvailable' => 373,
                'status'         => ['sellingCode' => 'SGC01', 'sellable' => true],
            ],
            [
                'productInfo' => [
                    'sku'   => '999999999',
                    'name'  => 'Sản phẩm thiếu dữ liệu',
                ],
                'prices' => [],
            ],
            [],
        ];
    }
}