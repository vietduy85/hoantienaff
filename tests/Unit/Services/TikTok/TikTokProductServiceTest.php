<?php

namespace Tests\Unit\Services\TikTok;

use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\RioHub\RioHubResponse;
use App\Services\TikTok\DTOs\TikTokProductDTO;
use App\Services\TikTok\TikTokProductService;
use App\Services\TikTok\TikTokServiceException;
use Tests\TestCase;

class TikTokProductServiceTest extends TestCase
{
    private RioHubClient $mockClient;
    private TikTokProductService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = $this->createMock(RioHubClient::class);
        $this->service = new TikTokProductService($this->mockClient);
    }

    // ------------------------------------------------------------------
    //  getProduct — success (top-level products[])
    // ------------------------------------------------------------------

    public function test_get_product_success(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'success' => true,
            'products' => [[
                'id' => '12345',
                'title' => 'Test Product',
                'main_image_url' => 'https://img.tiktok.com/product.jpg',
                'sales_price' => ['currency' => 'VND', 'minimum_amount' => 150000],
                'original_price' => ['currency' => 'VND', 'minimum_amount' => 180000],
                'commission' => ['rate' => 2300, 'amount' => 34500],
                'shop_ads_commission' => ['rate' => 100],
                'observed_commission' => ['commission_rate' => 2500],
                'shop' => ['name' => 'Shop Test'],
            ]],
        ]);

        $this->mockClient
            ->expects($this->once())
            ->method('getProduct')
            ->with('12345')
            ->willReturn($riohubResponse);

        $dto = $this->service->getProduct('12345');

        $this->assertInstanceOf(TikTokProductDTO::class, $dto);
        $this->assertEquals('12345', $dto->getProductId());
        $this->assertEquals('Test Product', $dto->getName());
        $this->assertEquals('https://img.tiktok.com/product.jpg', $dto->getImageUrl());
        $this->assertEquals(150000.0, $dto->getPrice());
        $this->assertEquals('VND', $dto->getCurrency());
        $this->assertEquals('Shop Test', $dto->getShopName());
    }

    public function test_get_product_with_integer_id(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'products' => [['id' => '999', 'title' => 'Product 999']],
        ]);

        $this->mockClient
            ->expects($this->once())
            ->method('getProduct')
            ->with(999)
            ->willReturn($riohubResponse);

        $dto = $this->service->getProduct(999);

        $this->assertEquals('999', $dto->getProductId());
        $this->assertEquals('Product 999', $dto->getName());
    }

    public function test_get_product_supports_single_product_shape(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'product' => ['id' => 'from-product-key', 'title' => 'Single Product'],
        ]);

        $this->mockClient
            ->method('getProduct')
            ->willReturn($riohubResponse);

        $dto = $this->service->getProduct('abc');

        $this->assertEquals('from-product-key', $dto->getProductId());
        $this->assertEquals('Single Product', $dto->getName());
    }

    public function test_get_product_preserves_raw_product_data(): void
    {
        $rawProduct = [
            'id' => '500',
            'title' => 'Raw Test',
            'custom_field' => 'custom_value',
        ];

        $riohubResponse = new RioHubResponse(200, ['products' => [$rawProduct]]);

        $this->mockClient
            ->method('getProduct')
            ->willReturn($riohubResponse);

        $dto = $this->service->getProduct('500');

        $this->assertEquals($rawProduct, $dto->getRaw());
    }

    // ------------------------------------------------------------------
    //  Commission rate resolution
    // ------------------------------------------------------------------

    public function test_effective_rate_prefers_observed_commission(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'products' => [[
                'id' => '1',
                'commission' => ['rate' => 1000],
                'shop_ads_commission' => ['rate' => 200],
                'observed_commission' => ['commission_rate' => 2500],
            ]],
        ]);

        $this->mockClient->method('getProduct')->willReturn($riohubResponse);

        $dto = $this->service->getProduct('1');

        $this->assertEquals(2500.0, $dto->getEffectiveCommissionRatePct());
    }

    public function test_effective_rate_falls_back_to_commission_plus_ads(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'products' => [[
                'id' => '2',
                'commission' => ['rate' => 1000],
                'shop_ads_commission' => ['rate' => 200],
            ]],
        ]);

        $this->mockClient->method('getProduct')->willReturn($riohubResponse);

        $dto = $this->service->getProduct('2');

        $this->assertEquals(1200.0, $dto->getEffectiveCommissionRatePct());
    }

    public function test_effective_rate_with_commission_only(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'products' => [[
                'id' => '3',
                'commission' => ['rate' => 500],
            ]],
        ]);

        $this->mockClient->method('getProduct')->willReturn($riohubResponse);

        $dto = $this->service->getProduct('3');

        $this->assertEquals(500.0, $dto->getEffectiveCommissionRatePct());
    }

    public function test_effective_rate_null_when_no_commission(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'products' => [['id' => '4']],
        ]);

        $this->mockClient->method('getProduct')->willReturn($riohubResponse);

        $dto = $this->service->getProduct('4');

        $this->assertNull($dto->getEffectiveCommissionRatePct());
    }

    public function test_effective_rate_null_when_observed_is_zero(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'products' => [[
                'id' => '5',
                'observed_commission' => ['commission_rate' => 0],
                'commission' => ['rate' => 0],
                'shop_ads_commission' => ['rate' => 0],
            ]],
        ]);

        $this->mockClient->method('getProduct')->willReturn($riohubResponse);

        $dto = $this->service->getProduct('5');

        $this->assertNull($dto->getEffectiveCommissionRatePct());
    }

    // ------------------------------------------------------------------
    //  getProduct — failure
    // ------------------------------------------------------------------

    public function test_get_product_throws_on_riohub_exception(): void
    {
        $riohubException = new RioHubException(
            statusCode: 404,
            message: '[getProduct] RioHub API returned HTTP 404: Not found',
            riohubMessage: 'Not found',
        );

        $this->mockClient
            ->method('getProduct')
            ->willThrowException($riohubException);

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('Not found');

        $this->service->getProduct('99999');
    }

    public function test_get_product_exception_preserves_status_code(): void
    {
        $riohubException = new RioHubException(
            statusCode: 401,
            message: 'unauthorized',
            riohubMessage: 'Invalid API key',
        );

        $this->mockClient
            ->method('getProduct')
            ->willThrowException($riohubException);

        try {
            $this->service->getProduct('123');
            $this->fail('Expected TikTokServiceException');
        } catch (TikTokServiceException $e) {
            $this->assertEquals(401, $e->getCode());
            $this->assertEquals('Invalid API key', $e->getRioHubMessage());
            $this->assertInstanceOf(RioHubException::class, $e->getPrevious());
        }
    }

    public function test_get_product_throws_when_no_product_found(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'success' => true,
            'products' => [],
        ]);

        $this->mockClient
            ->method('getProduct')
            ->willReturn($riohubResponse);

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('No product data returned');

        $this->service->getProduct('123');
    }

    // ------------------------------------------------------------------
    //  Validation
    // ------------------------------------------------------------------

    public function test_get_product_throws_on_empty_id(): void
    {
        $this->mockClient
            ->expects($this->never())
            ->method('getProduct');

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('product_id is required');

        $this->service->getProduct('');
    }

    public function test_get_product_throws_on_zero_id(): void
    {
        $this->mockClient
            ->expects($this->never())
            ->method('getProduct');

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('product_id is required');

        $this->service->getProduct(0);
    }
}
