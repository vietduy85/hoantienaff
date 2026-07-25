<?php

namespace Tests\Unit\Services\Providers;

use App\Enums\Platform;
use App\Services\Providers\TikTokProvider;
use App\Services\TikTok\DTOs\TikTokAffiliateLinkDTO;
use App\Services\TikTok\TikTokAffiliateService;
use App\Services\TikTok\TikTokServiceException;
use Tests\TestCase;

class TikTokProviderTest extends TestCase
{
    private TikTokAffiliateService $mockAffiliateService;
    private TikTokProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAffiliateService = $this->createMock(TikTokAffiliateService::class);
        $this->provider = new TikTokProvider($this->mockAffiliateService);
    }

    // ------------------------------------------------------------------
    //  supportedPlatform
    // ------------------------------------------------------------------

    public function test_supported_platform_is_tiktok(): void
    {
        $this->assertEquals(Platform::TIKTOK, $this->provider->supportedPlatform());
    }

    // ------------------------------------------------------------------
    //  createLink — success
    // ------------------------------------------------------------------

    public function test_create_link_success(): void
    {
        $dto = new TikTokAffiliateLinkDTO(
            affiliateUrl: 'https://riohub.vn/aff/abc123',
            productId: '12345',
            productName: 'Test Product',
            originalUrl: 'https://tiktok.com/item/12345',
        );

        $this->mockAffiliateService
            ->expects($this->once())
            ->method('createAffiliateLink')
            ->with('https://tiktok.com/item/12345')
            ->willReturn($dto);

        $result = $this->provider->createLink('https://tiktok.com/item/12345');

        $this->assertTrue($result['success']);
        $this->assertEquals('https://riohub.vn/aff/abc123', $result['affiliate_url']);
        $this->assertEquals(Platform::TIKTOK, $result['platform']);
        $this->assertNull($result['estimated_cashback']);
        $this->assertEquals('12345', $result['product_id']);
        $this->assertEquals('Test Product', $result['product_name']);
        $this->assertArrayHasKey('message', $result);
    }

    public function test_create_link_success_message(): void
    {
        $dto = new TikTokAffiliateLinkDTO(
            affiliateUrl: 'https://riohub.vn/aff/ok',
        );

        $this->mockAffiliateService
            ->method('createAffiliateLink')
            ->willReturn($dto);

        $result = $this->provider->createLink('https://tiktok.com/item/1');

        $this->assertEquals('Link TikTok Shop đã được tạo thành công.', $result['message']);
    }

    // ------------------------------------------------------------------
    //  createLink — failure
    // ------------------------------------------------------------------

    public function test_create_link_returns_error_on_exception(): void
    {
        $exception = new TikTokServiceException(
            message: '[createAffiliateLink] RioHub API returned HTTP 401: Invalid key',
            code: 401,
            riohubMessage: 'Invalid key',
        );

        $this->mockAffiliateService
            ->method('createAffiliateLink')
            ->willThrowException($exception);

        $result = $this->provider->createLink('https://tiktok.com/item/1');

        $this->assertFalse($result['success']);
        $this->assertNull($result['affiliate_url']);
        $this->assertEquals(Platform::TIKTOK, $result['platform']);
        $this->assertNull($result['estimated_cashback']);
        $this->assertEquals('Invalid key', $result['message']);
    }

    public function test_create_link_error_uses_fallback_message(): void
    {
        $exception = new TikTokServiceException(
            message: '[createAffiliateLink] RioHub returned empty affiliate_url',
            code: 0,
            riohubMessage: null,
        );

        $this->mockAffiliateService
            ->method('createAffiliateLink')
            ->willThrowException($exception);

        $result = $this->provider->createLink('https://tiktok.com/item/1');

        $this->assertFalse($result['success']);
        $this->assertEquals('[createAffiliateLink] RioHub returned empty affiliate_url', $result['message']);
    }

    public function test_create_link_validates_url_before_calling_service(): void
    {
        $this->mockAffiliateService
            ->expects($this->once())
            ->method('createAffiliateLink')
            ->with('https://tiktok.com/item/999')
            ->willReturn(new TikTokAffiliateLinkDTO(affiliateUrl: 'https://ok'));

        $result = $this->provider->createLink('https://tiktok.com/item/999');

        $this->assertTrue($result['success']);
    }

    // ------------------------------------------------------------------
    //  Return structure
    // ------------------------------------------------------------------

    public function test_success_return_has_required_keys(): void
    {
        $dto = new TikTokAffiliateLinkDTO(affiliateUrl: 'https://ok');

        $this->mockAffiliateService
            ->method('createAffiliateLink')
            ->willReturn($dto);

        $result = $this->provider->createLink('https://tiktok.com/item/1');

        $requiredKeys = ['success', 'affiliate_url', 'platform', 'estimated_cashback', 'message'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_error_return_has_required_keys(): void
    {
        $this->mockAffiliateService
            ->method('createAffiliateLink')
            ->willThrowException(new TikTokServiceException('error', 0, 'err'));

        $result = $this->provider->createLink('https://tiktok.com/item/1');

        $requiredKeys = ['success', 'affiliate_url', 'platform', 'estimated_cashback', 'message'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }
}
