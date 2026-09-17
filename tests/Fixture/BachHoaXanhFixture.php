<?php

namespace Tests\Fixture;

/**
 * Representative response fixtures from the audited Bách Hóa Xanh
 * (api.bachhoaxanh.com/gw) search API. Trimmed to the fields needed
 * for mapping.
 *
 * Verified live on 2026-09-17:
 *   POST https://api.bachhoaxanh.com/gw/search/v2/DataSearch
 *   body {"keywords":"vinamilk","pageIndex":0,"pageSize":20,"storeId":2546}
 *
 * NOTE: internal/undocumented API. Values here are illustrative.
 */
class BachHoaXanhFixture
{
    public const STORE_ID = 2546;

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
            'code' => 0,
            'serverName' => 'bhx-search',
            'requestId' => 'test-request-id',
            'data' => [
                'productIds' => [235865, 198354, 77619, 5368272, 70001],
                'products' => self::products(),
                'total' => 99,
                'searchKeyword' => 'vinamilk',
                'pageIndex' => 0,
                'pageSize' => 20,
                'isShowPopupWarningBeer' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function products(): array
    {
        return [
            self::vinamilkGreenFarm(),
            self::vinamilkItDuong(),
            self::haoHao(),
            self::namNgu(),
            self::outOfStock(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function vinamilkGreenFarm(): array
    {
        return [
            'id' => 235865,
            'name' => 'Lốc 4 hộp sữa tươi tiệt trùng ít đường Vinamilk Green Farm 180ml',
            'fullName' => 'Lốc 4 hộp sữa tươi tiệt trùng ít đường Vinamilk Green Farm 180ml',
            'url' => '/sua-tuoi/loc-4-hop-sua-tuoi-tiet-trung-it-duong-vinamilk-green-farm-180ml',
            'avatar' => 'https://cdnv2.tgdd.vn/mwg-static/bhx/Products/Images/235865/235865.jpg',
            'unit' => 'Lốc',
            'exchangeQuantity' => 4,
            'provinceId' => 0,
            'productCode' => '1053141000391',
            'brandName' => 'Vinamilk',
            'category' => ['id' => 2386, 'name' => 'Sữa tươi', 'url' => '/sua-tuoi'],
            'productPrices' => [
                [
                    'price' => 42000,
                    'sysPrice' => 42000,
                    'discountPercent' => 0,
                    'quantity' => 200,
                    'status' => 1,
                    'isCanBuy' => true,
                    'storeId' => self::STORE_ID,
                ],
            ],
            'specifications' => [
                ['propertyID' => 1, 'propertyName' => 'Dung tích', 'propValue' => '180ml'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function vinamilkItDuong(): array
    {
        return [
            'id' => 198354,
            'name' => 'Thùng 48 hộp sữa tươi tiệt trùng ít đường Vinamilk 110ml',
            'fullName' => 'Thùng 48 hộp sữa tươi tiệt trùng ít đường Vinamilk 110ml',
            'url' => '/sua-tuoi/thung-48-hop-sua-tuoi-tiet-trung-it-duong-vinamilk-110ml',
            'avatar' => 'https://cdnv2.tgdd.vn/mwg-static/bhx/Products/Images/198354/198354.jpg',
            'unit' => 'Thùng',
            'productCode' => '1053141000216',
            'brandName' => 'Vinamilk',
            'category' => ['id' => 2386, 'name' => 'Sữa tươi', 'url' => '/sua-tuoi'],
            'productPrices' => [
                [
                    'price' => 260000,
                    'sysPrice' => 260000,
                    'discountPercent' => 0,
                    'quantity' => 200,
                    'status' => 1,
                    'isCanBuy' => true,
                    'storeId' => self::STORE_ID,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function haoHao(): array
    {
        return [
            'id' => 77619,
            'name' => 'Mì Hảo Hảo gà vàng gói 74g',
            'fullName' => 'Mì Hảo Hảo gà vàng gói 74g',
            'url' => '/mi/mi-hao-hao-ga-vang-goi-74g',
            'avatar' => 'https://cdnv2.tgdd.vn/mwg-static/bhx/Products/Images/77619/77619.jpg',
            'unit' => 'Gói',
            'productCode' => '8934563184148',
            'brandName' => 'Hảo Hảo',
            'category' => ['id' => 1745, 'name' => 'Mì ăn liền', 'url' => '/mi'],
            'promotionText' => 'MUA 5 TẶNG 1',
            'promotionTextFS' => '',
            'productPrices' => [
                [
                    'price' => 3700,
                    'sysPrice' => 4600,
                    'discountPercent' => 20,
                    'quantity' => 200,
                    'status' => 1,
                    'isCanBuy' => true,
                    'storeId' => self::STORE_ID,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function namNgu(): array
    {
        return [
            'id' => 5368272,
            'name' => 'Nước mắm Nam Ngư Phú Quốc đậm đặc 32 độ đạm chai 500ml',
            'fullName' => 'Nước mắm Nam Ngư Phú Quốc đậm đặc 32 độ đạm chai 500ml',
            'url' => '/nuoc-mam/nuoc-mam-nam-ngu-phu-quoc-dam-dac-32-do-dam-chai-500ml',
            'avatar' => 'https://cdnv2.tgdd.vn/mwg-static/bhx/Products/Images/5368272/5368272.jpg',
            'unit' => 'Chai',
            'productCode' => '1053058000030',
            'brandName' => 'Nam Ngư',
            'category' => ['id' => 2045, 'name' => 'Nước mắm', 'url' => '/nuoc-mam'],
            'productPrices' => [
                [
                    'price' => 71000,
                    'sysPrice' => 71000,
                    'discountPercent' => 0,
                    'quantity' => 200,
                    'status' => 1,
                    'isCanBuy' => true,
                    'storeId' => self::STORE_ID,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outOfStock(): array
    {
        return [
            'id' => 70001,
            'name' => 'Sản phẩm tạm hết hàng',
            'url' => '/khac/san-pham-tam-het-hang',
            'avatar' => 'https://cdnv2.tgdd.vn/mwg-static/bhx/Products/Images/70001/70001.jpg',
            'unit' => 'Hộp',
            'productCode' => '1053000000000',
            'brandName' => 'Test',
            'category' => ['id' => 1, 'name' => 'Khác', 'url' => '/khac'],
            'productPrices' => [
                [
                    'price' => 15000,
                    'sysPrice' => 20000,
                    'discountPercent' => 25,
                    'quantity' => 0,
                    'status' => 1,
                    'isCanBuy' => false,
                    'storeId' => self::STORE_ID,
                ],
            ],
        ];
    }
}
