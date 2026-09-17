<?php

namespace Tests\Fixture;

/**
 * Representative response fixtures from the audited WinMart catalog API.
 * Trimmed to the fields needed for mapping, kept structurally faithful to
 * the live payload.
 *
 * Verified live on 2026-09-17:
 *   POST https://api-crownx.winmart.vn/ss/api/v2/public/winmart/item-search
 *   body {"keyword":"mì Hảo Hảo","storeNo":"1535","storeGroupCode":"1998",
 *         "applicationType":"Winmart","pageNumber":1,"pageSize":20}
 *
 * The API is PUBLIC (no auth / API key). Each item is one SKU/UOM row and
 * rows of the same itemNo (e.g. "10008453G1" gói vs "10008453T" thùng) are
 * deliberately kept separate.
 */
class WinMartFixture
{
    public const STORE_GROUP_CODE = '1998';

    public const STORE_NO = '1535';

    public const ITEM_NO = '10008453';

    public const SKU_GOI = '10008453G1';

    public const SKU_THUNG = '10008453T';

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
            'message' => null,
            'developerMessage' => null,
            'data' => self::items(),
            'paging' => [
                'totalCount' => 249,
                'pageNumber' => 1,
                'pageSize' => 20,
                'totalPages' => 13,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function items(): array
    {
        return [
            self::goi(),
            self::thung(),
            self::outOfStock(),
            self::notPublished(),
            ['name' => 'Thiếu id và sku'],
            [],
        ];
    }

    /**
     * No discount, stock available, published.
     *
     * @return array<string, mixed>
     */
    public static function goi(): array
    {
        return [
            'id' => '6aab0b97a5c85c542c4e545f',
            'itemNo' => self::ITEM_NO,
            'uomId' => 'db5981c8-0444-40a0-8706-e6b38abe2fb4',
            'uom' => 'G1',
            'uomName' => 'Gói',
            'sku' => self::SKU_GOI,
            'quantityPerUnit' => 1,
            'description' => 'Mì Vị Mì Hảo Hảo Mì gà vàng 74g',
            'longDescription' => 'Mỳ gói ăn liền vị gà vàng Hảo Hảo 74gx30',
            'itemType' => 'ZTRD',
            'seoName' => 'hao-hao-mi-vi-mi-ga-vang-74g--s10008453',
            'brandCode' => '15372',
            'brandName' => 'HẢO HẢO',
            'mch1Name' => 'Thực phẩm',
            'mch5Name' => 'Mì ăn liền',
            'image' => 'https://s3-hcmc02.higiocloud.vn/images/2024/11/10008453-20241119120220.jpg',
            'price' => [
                'site' => self::STORE_GROUP_CODE,
                'originPrice' => 4700,
                'salePrice' => 4700,
                'publish' => true,
                'discountRate' => 0,
                'scaleType' => null,
                'scaleQuantity' => 0,
            ],
            'warehouse' => [
                'availableQuantity' => 386,
                'minVariant' => null,
                'storeNo' => self::STORE_NO,
            ],
            'alcohol' => false,
        ];
    }

    /**
     * Same itemNo, second UOM: discount row.
     *
     * @return array<string, mixed>
     */
    public static function thung(): array
    {
        return [
            'id' => '6aab0b97a5c85c542c4e5457',
            'itemNo' => self::ITEM_NO,
            'uomId' => 'f5e23d03-58a5-4424-b106-6fd688d67cbd',
            'uom' => 'T',
            'uomName' => 'Thùng',
            'sku' => self::SKU_THUNG,
            'quantityPerUnit' => 30,
            'description' => 'Thùng 30 gói mì ăn liền vị gà vàng Hảo Hảo 74g',
            'longDescription' => 'Mỳ gói ăn liền vị gà vàng Hảo Hảo 74gx30',
            'itemType' => 'ZTRD',
            'seoName' => 'hao-hao-mi-vi-mi-ga-vang-74g--s10008453',
            'brandCode' => '15372',
            'brandName' => 'HẢO HẢO',
            'mch5Name' => 'Mì ăn liền',
            'image' => 'https://s3-hcmc02.higiocloud.vn/images/2024/11/10008453-1--20241119120240.jpg',
            'price' => [
                'site' => self::STORE_GROUP_CODE,
                'originPrice' => 135700,
                'salePrice' => 129000,
                'publish' => true,
                'discountRate' => 5,
            ],
            'warehouse' => [
                'availableQuantity' => 12,
                'storeNo' => self::STORE_NO,
            ],
            'alcohol' => false,
        ];
    }

    /**
     * Published but no stock -> sellable false.
     *
     * @return array<string, mixed>
     */
    public static function outOfStock(): array
    {
        return [
            'id' => '0aab0b97a5c85c542c4e5555',
            'itemNo' => '10008452',
            'uom' => 'G1',
            'uomName' => 'Gói',
            'sku' => '10008452G1',
            'quantityPerUnit' => 1,
            'description' => 'Sản phẩm hết hàng',
            'seoName' => 'san-pham-het-hang-s10008452',
            'brandName' => 'HẢO HẢO',
            'mch5Name' => 'Mì ăn liền',
            'image' => 'https://s3-hcmc02.higiocloud.vn/images/2024/11/10008452.jpg',
            'price' => [
                'originPrice' => 5000,
                'salePrice' => 5000,
                'publish' => true,
                'discountRate' => 0,
            ],
            'warehouse' => [
                'availableQuantity' => 0,
                'storeNo' => self::STORE_NO,
            ],
            'alcohol' => false,
        ];
    }

    /**
     * Stock available but not published -> sellable false.
     *
     * @return array<string, mixed>
     */
    public static function notPublished(): array
    {
        return [
            'id' => '0aab0b97a5c85c542c4e5566',
            'itemNo' => '10008451',
            'uom' => 'G1',
            'uomName' => 'Gói',
            'sku' => '10008451G1',
            'quantityPerUnit' => 1,
            'description' => 'Sản phẩm chưa lên kệ',
            'seoName' => 'san-pham-chua-len-ke-s10008451',
            'brandName' => 'HẢO HẢO',
            'image' => 'https://s3-hcmc02.higiocloud.vn/images/2024/11/10008451.jpg',
            'price' => [
                'originPrice' => 6000,
                'salePrice' => 6000,
                'publish' => false,
                'discountRate' => 0,
            ],
            'warehouse' => [
                'availableQuantity' => 5,
                'storeNo' => self::STORE_NO,
            ],
            'alcohol' => false,
        ];
    }
}
