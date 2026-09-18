<?php

namespace Tests\Fixture\PromotionNews;

/**
 * Structurally faithful homepages for the four audited retailers.
 *
 * They reproduce exactly the shape the providers parse (Pages Router
 * __NEXT_DATA__ for Co.op/WinMart/Kingfoodmart and App Router flight chunks
 * for Bách Hóa Xanh) so provider tests never touch the live network.
 */
class PromotionNewsFixtures
{
    public const COOP_URL = 'https://cooponline.vn/';

    public const BHX_URL = 'https://www.bachhoaxanh.com/';

    public const WINMART_URL = 'https://winmart.vn/';

    public const KINGFOODMART_URL = 'https://kingfoodmart.com/';

    public static function coopHomepage(?array $vouchers = null): string
    {
        $vouchers ??= [
            [
                'title' => 'Giảm 30k - Co.op Online',
                'description' => 'Nhập mã SALE30 cho đơn từ 300k',
                'descriptionExtra' => 'HSD: 23/09/2026',
                'imageUrl' => 'https://lh3.googleusercontent.com/a.jpg',
                'textLinkUrl' => 'https://cooponline.vn/giam-30k',
                'productListPath' => 'https://cooponline.vn/giam-30k',
                'campaignId' => 1001,
                'timeVisibility' => [
                    'startTime' => '2020-01-01T00:00:00.000Z',
                    'endTime' => '2035-12-31T16:59:00.000Z',
                ],
            ],
            [
                'title' => 'Giảm 20% Kinh Đô',
                'description' => 'Áp dụng cho bánh trung thu Kinh Đô',
                'imageUrl' => 'https://lh3.googleusercontent.com/b.jpg',
                'productListPath' => '/c/khuyen-mai-hot',
            ],
            [
                'title' => 'Không có ảnh',
                'imageUrl' => '',
                'productListPath' => '/c/khuyen-mai-hot',
            ],
            [
                'title' => 'Không có link',
                'imageUrl' => 'https://lh3.googleusercontent.com/c.jpg',
            ],
        ];

        $voucherBlock = [
            'customAttributes' => [
                'omni_voucherlist' => [
                    'title' => 'E-voucher khuyến mãi',
                    'items' => $vouchers,
                ],
            ],
        ];

        return self::nextData([
            'props' => [
                'pageProps' => [
                    'pbContent' => [
                        ['customAttributes' => ['other_widget' => ['title' => 'Khác']]],
                        [
                            'customAttributes' => [
                                'omni_bannerslider_2' => [
                                    'title' => 'Tin tức',
                                    'viewMoreUrl' => 'https://cooponline.vn/tin-tuc',
                                    'items' => [
                                        [
                                            'src' => 'https://lh3.googleusercontent.com/news.jpg',
                                            'alt' => 'Tin doanh nghiệp',
                                            'event' => ['link' => ['href' => '/tin-tuc/x']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        $voucherBlock,
                        $voucherBlock,
                    ],
                ],
            ],
        ]);
    }

    public static function bhxHomepage(?array $categories = null): string
    {
        $categories ??= [
            [
                'id' => 111,
                'name' => 'Siêu sale cuối tuần',
                'url' => 'thuong-hieu/sieu-sale-cuoi-tuan-ct111',
                'path' => 'Promotion',
                'icon' => 'https://cdnv2.tgdd.vn/a.gif',
            ],
            [
                'id' => 222,
                'name' => 'Xem tất cả',
                'url' => 'khuyen-mai',
                'path' => 'Promotion',
                'icon' => 'https://cdnv2.tgdd.vn/all.gif',
            ],
            [
                'id' => 333,
                'name' => 'Thịt cá',
                'url' => 'thit-ca',
                'path' => 'GroupV2',
                'icon' => 'https://cdnv2.tgdd.vn/c.gif',
            ],
            [
                'id' => 444,
                'name' => 'Thiếu icon',
                'url' => 'thuong-hieu/thieu-icon-ct444',
                'path' => 'SpecialOffer',
                'icon' => '',
            ],
        ];

        $flight = '2:{"dataHome":true}';
        $flight .= '3:{"listHomeDataInit":[],"topCategoriesInit":{"categories":'.json_encode($categories, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'},"tail":1}';

        return self::flight($flight);
    }

    public static function winmartHomepage(?array $banners = null): string
    {
        $banners ??= [
            [
                'id' => 'wm-1',
                'name' => 'CLEAR BW_Home banner_17 - 23/09',
                'subTitle' => '',
                'url' => 'https://winmart.vn/sieu-sale-thuong-hieu',
                'imageUrl' => 'https://s3-hcmc02.higiocloud.vn/images/a.jpg',
            ],
            [
                'id' => 'wm-2',
                'name' => 'Ưu đãi cuối tuần',
                'subTitle' => '',
                'url' => 'san-pham-khuyen-mai--c14',
                'imageUrl' => 'https://s3-hcmc02.higiocloud.vn/images/b.jpg',
            ],
            [
                'id' => 'wm-3',
                'name' => 'Không có ảnh',
                'subTitle' => '',
                'url' => 'https://winmart.vn/x',
                'imageUrl' => '',
            ],
            [
                'id' => 'wm-4',
                'name' => 'Internal code',
                'subTitle' => 'Tiêu đề hiển thị',
                'url' => 'https://winmart.vn/y',
                'imageUrl' => 'https://s3-hcmc02.higiocloud.vn/images/c.jpg',
            ],
        ];

        return self::nextData([
            'props' => [
                'pageProps' => [
                    'dataHome' => [
                        'headerBanner' => $banners,
                    ],
                ],
            ],
        ]);
    }

    public static function kingfoodmartHomepage(?array $banners = null): string
    {
        $banners ??= [
            [
                'id' => 'kfm-1',
                'ownerName' => 'Hot deal cuối tuần',
                'ownerStatus' => 'running',
                'position' => -1000,
                'subdirectory' => '/hot-deal/promo/kfm-1',
                'externalLink' => '',
                'homeDesktopBannerImageUrl' => 'https://storage.googleapis.com/onelife-public/a.webp',
                'homeMobileBannerImageUrl' => 'https://storage.googleapis.com/onelife-public/a-m.webp',
                'bannerImageUrl' => 'https://storage.googleapis.com/onelife-public/a-2.webp',
            ],
            [
                'id' => 'kfm-2',
                'ownerName' => 'Tạm dừng',
                'ownerStatus' => 'paused',
                'position' => 1,
                'subdirectory' => '/hot-deal/promo/kfm-2',
                'externalLink' => '',
                'homeDesktopBannerImageUrl' => 'https://storage.googleapis.com/onelife-public/b.webp',
            ],
            [
                'id' => 'kfm-3',
                'ownerName' => 'Thiếu ảnh',
                'ownerStatus' => 'running',
                'position' => 2,
                'subdirectory' => '/hot-deal/promo/kfm-3',
                'externalLink' => '',
                'homeDesktopBannerImageUrl' => '',
                'bannerImageUrl' => '',
            ],
            [
                'id' => 'kfm-4',
                'ownerName' => 'Link ngoài',
                'ownerStatus' => 'running',
                'position' => 3,
                'subdirectory' => '',
                'externalLink' => 'https://kingfoodmart.com/uu-dai',
                'homeDesktopBannerImageUrl' => 'https://storage.googleapis.com/onelife-public/d.webp',
            ],
        ];

        return self::nextData([
            'props' => [
                'pageProps' => [
                    'data' => [
                        'banner' => $banners,
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function nextData(array $payload): string
    {
        return '<html><head><script id="__NEXT_DATA__" type="application/json">'
            .json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            .'</script></head><body></body></html>';
    }

    private static function flight(string $text): string
    {
        $chunk = substr(json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);

        return '<html><body><script>self.__next_f.push([1,"'.$chunk.'"])</script></body></html>';
    }
}
