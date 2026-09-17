<?php

namespace Tests\Fixture;

/**
 * Representative response fixtures from the audited Kingfoodmart
 * (onelife-api.kingfoodmart.com/v1) search API. Trimmed to the fields
 * needed for mapping, but kept structurally faithful to the live payload.
 *
 * Verified live on 2026-09-17:
 *   GET https://onelife-api.kingfoodmart.com/v1/products/search
 *       ?type=NORMAL&keyword=mì Hảo Hảo&page=1&limit=30
 *   GET https://onelife-api.kingfoodmart.com/v1/products/variants/{variantId}
 *
 * NOTE: internal/undocumented API. Values here are illustrative.
 */
class KingfoodmartFixture
{
    /**
     * Variant id (pid) of the first product. The detail endpoint accepts this.
     */
    public const VARIANT_ID = '172611155049579442';

    /**
     * Variant SKU / EAN-13 of the first product.
     */
    public const SKU = '8934563185152';

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
            'categories' => [
                ['id' => '1356', 'name' => 'Mì ăn liền', 'slug' => 'mi-an-lien-1356'],
            ],
            'pagination' => [
                'total' => 137,
                'currentPage' => 1,
                'lastPage' => 5,
                'limit' => 30,
                'shown' => 2,
                'hasMore' => true,
            ],
            'products' => self::products(),
            'rid' => 'test-rid-kingfoodmart',
        ];
    }

    /**
     * The detail endpoint returns the product object at the top level.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function detailResponse(array $overrides = []): array
    {
        return array_replace_recursive(self::haoHaoKimChi(), $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function products(): array
    {
        return [
            self::haoHaoKimChi(),
            self::haoHaoThung30(),
            ['name' => ''],
            'garbage',
            [],
        ];
    }

    /**
     * No discount, single variant on sale.
     *
     * @return array<string, mixed>
     */
    public static function haoHaoKimChi(): array
    {
        return [
            'id' => '158018347907154377-'.self::VARIANT_ID,
            'pid' => self::VARIANT_ID,
            'name' => 'Mì Hảo Hảo Acecook hương vị lẩu kim chi Hàn Quốc gói 75g',
            'slug' => 'mi-hao-hao-huong-vi-lau-kim-chi-han-quoc-acecook-75g-1-goi',
            'subCate' => 'mi-an-lien-1356',
            'subCateName' => 'Mì ăn liền',
            'discountPrice' => 4700,
            'originalPrice' => 4700,
            'discountPercent' => 0,
            'inStock' => 7463,
            'isActive' => true,
            'productType' => 'good',
            'thumbnail' => 'https://img.onelife.vn/rs:fit:300:300:1/hao-hao-kim-chi.webp',
            'images' => [
                'https://img.onelife.vn/rs:fit:300:300:1/hao-hao-kim-chi.webp',
                'https://img.onelife.vn/rs:fit:300:300:1/hao-hao-kim-chi-2.webp',
            ],
            'badges' => [
                ['type' => 'shipping', 'value' => 'sameday', 'label' => 'Giao trong 2h'],
            ],
            'tickers' => ['discount'],
            'isAlcohol' => false,
            'partnerId' => '158018347907154377',
            'currentSeller' => null,
            'descriptionJson' => [
                'producer' => 'Acecook Việt Nam',
                'brand' => 'Hảo Hảo',
            ],
            'variants' => [
                [
                    'id' => self::VARIANT_ID,
                    'productId' => '158018347907154377',
                    'name' => 'Mì Hảo Hảo Acecook hương vị lẩu kim chi Hàn Quốc gói 75g',
                    'price' => 4700,
                    'originalPrice' => 4700,
                    'discountPrice' => 4700,
                    'discountPercent' => 0,
                    'sku' => self::SKU,
                    'unit' => ['id' => '252749990135333638', 'name' => '1 Gói'],
                    'unitConversion' => [
                        'isBaseVariant' => false,
                        'baseUnitName' => 'GÓI',
                        'pricePerBaseUnit' => 4700,
                        'conversion' => 1,
                    ],
                    'stockItem' => ['quantity' => 7463, 'maxSaleQuantity' => 1000, 'minSaleQuantity' => 1],
                    'isOnlineSale' => true,
                    'isSale' => true,
                    'isOrdered' => false,
                ],
            ],
        ];
    }

    /**
     * Has a discount and two variants: the first is not on sale, the second
     * is. The provider must map the on-sale variant as the representative.
     *
     * @return array<string, mixed>
     */
    public static function haoHaoThung30(): array
    {
        return [
            'id' => '158018347907154378-172611155049579455',
            'pid' => '172611155049579455',
            'name' => 'Thùng 30 gói mì Hảo Hảo vị tôm chua cay Acecook 75g',
            'slug' => 'thung-30-goi-mi-hao-hao-vi-tom-chua-cay-acecook-75g',
            'subCate' => 'mi-an-lien-1356',
            'subCateName' => 'Mì ăn liền',
            'discountPrice' => 99000,
            'originalPrice' => 120000,
            'discountPercent' => 17,
            'inStock' => 305,
            'isActive' => true,
            'productType' => 'good',
            'thumbnail' => 'https://img.onelife.vn/rs:fit:300:300:1/hao-hao-thung30.webp',
            'images' => [
                'https://img.onelife.vn/rs:fit:300:300:1/hao-hao-thung30.webp',
            ],
            'badges' => [],
            'tickers' => ['discount'],
            'isAlcohol' => false,
            'partnerId' => '158018347907154378',
            'currentSeller' => ['id' => '99', 'name' => 'Kingfoodmart Online'],
            'variants' => [
                [
                    'id' => '172611155049579454',
                    'sku' => '8934563184155',
                    'unit' => ['id' => '252749990135333600', 'name' => 'Thùng'],
                    'stockItem' => ['quantity' => 0, 'maxSaleQuantity' => 1000, 'minSaleQuantity' => 1],
                    'isOnlineSale' => false,
                    'isSale' => false,
                ],
                [
                    'id' => '172611155049579455',
                    'productId' => '158018347907154378',
                    'name' => 'Thùng 30 gói mì Hảo Hảo vị tôm chua cay Acecook 75g',
                    'price' => 99000,
                    'originalPrice' => 120000,
                    'discountPrice' => 99000,
                    'discountPercent' => 17,
                    'sku' => '8934563184162',
                    'unit' => ['id' => '252749990135333601', 'name' => 'Thùng 30 gói'],
                    'stockItem' => ['quantity' => 305, 'maxSaleQuantity' => 1000, 'minSaleQuantity' => 1],
                    'isOnlineSale' => true,
                    'isSale' => true,
                    'isOrdered' => false,
                ],
            ],
        ];
    }
}
