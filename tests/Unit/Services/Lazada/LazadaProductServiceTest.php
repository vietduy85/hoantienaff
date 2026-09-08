<?php

namespace Tests\Unit\Services\Lazada;

use App\Services\Lazada\LazadaException;
use App\Services\Lazada\LazadaProductService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LazadaProductServiceTest extends TestCase
{
    private const PRODUCT_URL = 'https://www.lazada.vn/products/ao-so-mi-nam-i123456789-s456.html';
    private const PROMO_LINK = 'https://c.lazada.vn/t/c.ABCDE?subId1=42&subId2=testuser';

    private LazadaProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'services.lazada.app_key' => '105000',
            'services.lazada.app_secret' => 'secret-secret-secret-secret-secret-32',
            'services.lazada.user_token' => 'ffffffffffffffffffffffffffffffff',
            'services.lazada.base_url' => 'https://api.lazada.vn/rest',
        ]);

        $this->service = app(LazadaProductService::class);
    }

    private function getLinkPayload(): array
    {
        return [
            'data' => [
                'urlBatchGetLinkInfoList' => [[
                    'originalUrl' => self::PRODUCT_URL,
                    'productId' => '123456789',
                    'productName' => null,
                    'regularPromotionLink' => self::PROMO_LINK,
                    'regularCommission' => '24.1%',
                ]],
            ],
            'success' => true,
            'error_code' => null,
            'error_msg' => null,
        ];
    }

    private function feedPayload(): array
    {
        return [
            'data' => [
                'totalProducts' => 1,
                'productList' => [[
                    'productId' => '123456789',
                    'productName' => 'Áo sơ mi nam cotton',
                    'pictures' => 'https://img.lazada.vn/p1.jpg,https://img.lazada.vn/p2.jpg',
                    'discountPrice' => '250000',
                    'currency' => 'VND',
                    'totalCommissionRate' => '5.0',
                    'totalCommissionAmount' => '12500',
                ]],
            ],
            'success' => true,
        ];
    }

    public function test_create_link_normalizes_getlink_and_feed(): void
    {
        Http::fake([
            '*/marketing/getlink*' => Http::response($this->getLinkPayload(), 200),
            '*/marketing/product/feed*' => Http::response($this->feedPayload(), 200),
        ]);

        $dto = $this->service->createLink(self::PRODUCT_URL, '42', 'testuser');

        $this->assertSame('123456789', $dto->getProductId());
        $this->assertSame('Áo sơ mi nam cotton', $dto->getProductName());
        $this->assertSame('https://img.lazada.vn/p1.jpg', $dto->getProductImage());
        $this->assertSame(250000.0, $dto->getProductPrice());
        $this->assertSame('VND', $dto->getCurrency());
        $this->assertSame(24.1, $dto->getCommissionRatePct());
        $this->assertSame(12500.0, $dto->getCommissionAmount());
        $this->assertSame(self::PROMO_LINK, $dto->getAffiliateUrl());
    }

    public function test_affiliate_link_returned_verbatim_never_rebuilt(): void
    {
        Http::fake([
            '*/marketing/getlink*' => Http::response($this->getLinkPayload(), 200),
            '*/marketing/product/feed*' => Http::response($this->feedPayload(), 200),
        ]);

        $dto = $this->service->createLink(self::PRODUCT_URL, '42', 'testuser');

        // The subIds are appended by the Lazada API itself; we never touch the link.
        $this->assertSame(self::PROMO_LINK, $dto->getAffiliateUrl());
        $this->assertStringContainsString('subId1=42', $dto->getAffiliateUrl());
        $this->assertStringContainsString('subId2=testuser', $dto->getAffiliateUrl());

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), '/marketing/getlink')
                && str_contains((string) $request->url(), 'inputType=url')
                && str_contains((string) $request->url(), 'subId1=42');
        });
    }

    public function test_item_error_info_list_product_not_found(): void
    {
        $payload = $this->getLinkPayload();
        $payload['data']['urlBatchGetLinkInfoList'][0]['errorInfoList'] = [[
            'inputValue' => self::PRODUCT_URL,
            'errorCode' => '2001',
            'errorMsg' => 'offer not found',
        ]];

        Http::fake([
            '*/marketing/getlink*' => Http::response($payload, 200),
        ]);

        try {
            $this->service->createLink(self::PRODUCT_URL, '42', 'testuser');
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Không tìm thấy sản phẩm Lazada cho link này hoặc sản phẩm không có commission.', $e->getUserMessage());
        }
    }

    public function test_empty_promotion_link_throws(): void
    {
        $payload = $this->getLinkPayload();
        $payload['data']['urlBatchGetLinkInfoList'][0]['regularPromotionLink'] = '';

        Http::fake([
            '*/marketing/getlink*' => Http::response($payload, 200),
        ]);

        $this->expectException(LazadaException::class);

        $this->service->createLink(self::PRODUCT_URL, '42', 'testuser');
    }

    public function test_real_api_response_shape_is_supported(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/marketing/getlink')) {
                return Http::response([
                    'result' => [
                        'data' => [
                            'urlBatchGetLinkInfoList' => [[
                                'regularCommission' => '1%',
                                'productId' => '310626559',
                                'regularPromotionLink' => 'https://s.lazada.vn/s.MvZTS?c=d&t=x&sub_id1=42&sub_id2=testuser',
                                'originalUrl' => self::PRODUCT_URL,
                                'productName' => 'Pond Age Miracle 100G',
                            ]],
                            'errorInfoList' => [],
                            'errorCount' => 0,
                        ],
                        'success' => true,
                    ],
                    'code' => '0',
                    'request_id' => 'r1',
                ], 200);
            }

            return Http::response([
                'result' => [
                    'data' => [[
                        'productId' => '310626559',
                        'productName' => 'Pond Age Miracle 100G',
                        'pictures' => ['https://img.lazada.vn/p1.png'],
                        'discountPrice' => 119000,
                        'currency' => '₫',
                        'totalCommissionAmount' => 1190,
                        'totalCommissionRate' => 0.01,
                    ]],
                    'success' => true,
                ],
                'code' => '0',
            ], 200);
        });

        $dto = $this->service->createLink(self::PRODUCT_URL, '42', 'testuser');

        $this->assertSame('310626559', $dto->getProductId());
        $this->assertSame('Pond Age Miracle 100G', $dto->getProductName());
        $this->assertSame('https://img.lazada.vn/p1.png', $dto->getProductImage());
        $this->assertSame(119000.0, $dto->getProductPrice());
        $this->assertSame('₫', $dto->getCurrency());
        $this->assertSame(1.0, $dto->getCommissionRatePct());
        $this->assertSame(1190.0, $dto->getCommissionAmount());
        $this->assertSame('https://s.lazada.vn/s.MvZTS?c=d&t=x&sub_id1=42&sub_id2=testuser', $dto->getAffiliateUrl());

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return str_contains($url, '/marketing/product/feed')
                && str_contains($url, 'productIds=' . rawurlencode('["310626559"]'));
        });
    }

    public function test_feed_failure_is_best_effort(): void
    {
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), '/marketing/getlink')) {
                return Http::response($this->getLinkPayload(), 200);
            }

            return Http::response(['message' => 'boom'], 500);
        });

        $dto = $this->service->createLink(self::PRODUCT_URL, '42', 'testuser');

        $this->assertSame(self::PROMO_LINK, $dto->getAffiliateUrl());
        $this->assertSame('123456789', $dto->getProductId());
        $this->assertSame(24.1, $dto->getCommissionRatePct());
        $this->assertNull($dto->getProductName());
        $this->assertNull($dto->getProductImage());
        $this->assertNull($dto->getCommissionAmount());
    }

    public function test_parse_percent(): void
    {
        $this->assertSame(24.1, $this->service->parsePercent('24.1%'));
        $this->assertSame(5.0, $this->service->parsePercent('5%'));
        $this->assertSame(12.5, $this->service->parsePercent(12.5));
        $this->assertNull($this->service->parsePercent(null));
        $this->assertNull($this->service->parsePercent(''));
        $this->assertNull($this->service->parsePercent('null'));
        $this->assertNull($this->service->parsePercent('n/a'));
    }
}
