<?php

namespace Tests\Unit\Services\TikTok;

use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubException;
use App\Services\RioHub\RioHubResponse;
use App\Services\TikTok\DTOs\TikTokAffiliateLinkDTO;
use App\Services\TikTok\TikTokAffiliateService;
use App\Services\TikTok\TikTokServiceException;
use Tests\TestCase;

class TikTokAffiliateServiceTest extends TestCase
{
    private RioHubClient $mockClient;
    private TikTokAffiliateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = $this->createMock(RioHubClient::class);
        $this->service = new TikTokAffiliateService($this->mockClient);
    }

    // ------------------------------------------------------------------
    //  createAffiliateLink — success
    // ------------------------------------------------------------------

    public function test_create_affiliate_link_success(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'success' => true,
            'data' => [
                'affiliate_url' => 'https://riohub.vn/aff/abc123',
                'product_id' => '12345',
                'product_name' => 'Test Product',
            ],
        ]);

        $this->mockClient
            ->expects($this->once())
            ->method('createAffiliateLink')
            ->with('https://tiktok.com/item/12345', null)
            ->willReturn($riohubResponse);

        $dto = $this->service->createAffiliateLink('https://tiktok.com/item/12345');

        $this->assertInstanceOf(TikTokAffiliateLinkDTO::class, $dto);
        $this->assertEquals('https://riohub.vn/aff/abc123', $dto->getAffiliateUrl());
        $this->assertEquals('12345', $dto->getProductId());
        $this->assertEquals('Test Product', $dto->getProductName());
        $this->assertEquals('https://tiktok.com/item/12345', $dto->getOriginalUrl());
    }

    public function test_create_affiliate_link_with_custom_sub_id(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'success' => true,
            'data' => ['affiliate_url' => 'https://riohub.vn/aff/custom'],
        ]);

        $this->mockClient
            ->expects($this->once())
            ->method('createAffiliateLink')
            ->with('https://tiktok.com/item/1', 'custom-sub')
            ->willReturn($riohubResponse);

        $dto = $this->service->createAffiliateLink('https://tiktok.com/item/1', 'custom-sub');

        $this->assertEquals('https://riohub.vn/aff/custom', $dto->getAffiliateUrl());
    }

    public function test_create_affiliate_link_preserves_raw_data(): void
    {
        $rawData = [
            'affiliate_url' => 'https://riohub.vn/aff/abc',
            'product_id' => '99',
            'extra_field' => 'extra_value',
        ];

        $riohubResponse = new RioHubResponse(200, [
            'success' => true,
            'data' => $rawData,
        ]);

        $this->mockClient
            ->method('createAffiliateLink')
            ->willReturn($riohubResponse);

        $dto = $this->service->createAffiliateLink('https://tiktok.com/item/1');

        $this->assertEquals($rawData, $dto->getRaw());
    }

    // ------------------------------------------------------------------
    //  createAffiliateLink — failure
    // ------------------------------------------------------------------

    public function test_create_affiliate_link_throws_on_riohub_exception(): void
    {
        $exception = RioHubException::class;

        $riohubException = new $exception(
            statusCode: 401,
            message: '[createAffiliateLink] RioHub API returned HTTP 401: Invalid key',
            riohubMessage: 'Invalid key',
        );

        $this->mockClient
            ->method('createAffiliateLink')
            ->willThrowException($riohubException);

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('Invalid key');

        $this->service->createAffiliateLink('https://tiktok.com/item/1');
    }

    public function test_create_affiliate_link_exception_preserves_status_code(): void
    {
        $riohubException = new RioHubException(
            statusCode: 422,
            message: '[createAffiliateLink] validation error',
            riohubMessage: 'validation error',
        );

        $this->mockClient
            ->method('createAffiliateLink')
            ->willThrowException($riohubException);

        try {
            $this->service->createAffiliateLink('https://tiktok.com/item/1');
            $this->fail('Expected TikTokServiceException');
        } catch (TikTokServiceException $e) {
            $this->assertEquals(422, $e->getCode());
            $this->assertEquals('validation error', $e->getRioHubMessage());
            $this->assertInstanceOf(RioHubException::class, $e->getPrevious());
        }
    }

    public function test_create_affiliate_link_throws_on_empty_response(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'success' => true,
            'data' => [],
        ]);

        $this->mockClient
            ->method('createAffiliateLink')
            ->willReturn($riohubResponse);

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('empty affiliate_url');

        $this->service->createAffiliateLink('https://tiktok.com/item/1');
    }

    // ------------------------------------------------------------------
    //  Validation
    // ------------------------------------------------------------------

    public function test_create_affiliate_link_throws_on_empty_url(): void
    {
        $this->mockClient
            ->expects($this->never())
            ->method('createAffiliateLink');

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        $this->service->createAffiliateLink('');
    }

    public function test_create_affiliate_link_throws_on_whitespace_url(): void
    {
        $this->mockClient
            ->expects($this->never())
            ->method('createAffiliateLink');

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('URL cannot be empty');

        $this->service->createAffiliateLink('   ');
    }

    public function test_create_affiliate_link_throws_on_invalid_url(): void
    {
        $this->mockClient
            ->expects($this->never())
            ->method('createAffiliateLink');

        $this->expectException(TikTokServiceException::class);
        $this->expectExceptionMessage('Invalid URL');

        $this->service->createAffiliateLink('not-a-url');
    }

    // ------------------------------------------------------------------
    //  DTO mapping
    // ------------------------------------------------------------------

    public function test_dto_maps_affiliate_url_from_url_key(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'data' => ['url' => 'https://riohub.vn/aff/from-url-key'],
        ]);

        $this->mockClient
            ->method('createAffiliateLink')
            ->willReturn($riohubResponse);

        $dto = $this->service->createAffiliateLink('https://tiktok.com/item/1');

        $this->assertEquals('https://riohub.vn/aff/from-url-key', $dto->getAffiliateUrl());
    }

    public function test_dto_maps_product_id_from_id_key(): void
    {
        $riohubResponse = new RioHubResponse(200, [
            'data' => ['affiliate_url' => 'https://riohub.vn/aff/x', 'id' => 'from-id-key'],
        ]);

        $this->mockClient
            ->method('createAffiliateLink')
            ->willReturn($riohubResponse);

        $dto = $this->service->createAffiliateLink('https://tiktok.com/item/1');

        $this->assertEquals('from-id-key', $dto->getProductId());
    }
}
