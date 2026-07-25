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
    //  getProduct — success
    // ------------------------------------------------------------------

    public function test_get_product_success(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'data' => [
                'product_id' => '12345',
                'product_name' => 'Test Product',
                'image_url' => 'https://img.tiktok.com/product.jpg',
                'price' => 150000,
                'currency' => 'VND',
                'commission_rate' => 0.10,
                'product_url' => 'https://tiktok.com/item/12345',
            ],
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
        $this->assertEquals(0.10, $dto->getCommissionRate());
        $this->assertEquals('https://tiktok.com/item/12345', $dto->getProductUrl());
    }

    public function test_get_product_with_integer_id(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'data' => ['product_id' => '999', 'product_name' => 'Product 999'],
        ]);

        $this->mockClient
            ->expects($this->once())
            ->method('getProduct')
            ->with(999)
            ->willReturn($riohubResponse);

        $dto = $this->service->getProduct(999);

        $this->assertEquals('999', $dto->getProductId());
    }

    public function test_get_product_maps_from_id_key(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'data' => ['id' => 'from-id-key', 'name' => 'From ID Key'],
        ]);

        $this->mockClient
            ->method('getProduct')
            ->willReturn($riohubResponse);

        $dto = $this->service->getProduct('abc');

        $this->assertEquals('from-id-key', $dto->getProductId());
        $this->assertEquals('From ID Key', $dto->getName());
    }

    public function test_get_product_preserves_raw_data(): void
    {
        $rawData = [
            'product_id' => '500',
            'product_name' => 'Raw Test',
            'custom_field' => 'custom_value',
        ];

        $riohubResponse = new RioHubResponse(200, ['data' => $rawData]);

        $this->mockClient
            ->method('getProduct')
            ->willReturn($riohubResponse);

        $dto = $this->service->getProduct('500');

        $this->assertEquals($rawData, $dto->getRaw());
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

    public function test_get_product_throws_on_empty_data(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'data' => [],
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
