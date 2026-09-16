<?php

namespace Tests\Unit\Services\AffiliateSearchLinks;

use App\Services\AffiliateSearchLinks\Providers\TikTokAffiliateSearchLinkProvider;
use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubResponse;
use Tests\TestCase;

class TikTokAffiliateSearchLinkProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    private function mockClient(RioHubResponse|callable $behavior): RioHubClient
    {
        $mock = $this->createMock(RioHubClient::class);

        if ($behavior instanceof RioHubResponse) {
            $mock->method('createAffiliateLink')->willReturn($behavior);
        } else {
            $mock->method('createAffiliateLink')->willReturnCallback($behavior);
        }

        return $mock;
    }

    private function provider(RioHubClient $client): TikTokAffiliateSearchLinkProvider
    {
        return new TikTokAffiliateSearchLinkProvider($client);
    }

    public function test_platform_is_tiktok(): void
    {
        $client = $this->createMock(RioHubClient::class);

        $this->assertSame('tiktok', $this->provider($client)->platform());
    }

    public function test_build_search_url_encodes_keyword_properly(): void
    {
        $client = $this->createMock(RioHubClient::class);

        $this->assertSame(
            'https://shop.tiktok.com/vn/search?q=vinamilk+%C3%ADt+%C4%91%C6%B0%E1%BB%9Dng',
            $this->provider($client)->buildSearchUrl('vinamilk ít đường'),
        );
    }

    public function test_search_url_has_no_fabricated_affiliate_parameter(): void
    {
        $client = $this->createMock(RioHubClient::class);

        $url = $this->provider($client)->buildSearchUrl('vinamilk ít đường');

        $this->assertStringNotContainsString('affiliate_id', $url);
        $this->assertStringNotContainsString('creator_id', $url);
        $this->assertStringNotContainsString('utm_', $url);
    }

    public function test_api_supports_search_returns_affiliate_link(): void
    {
        $client = $this->mockClient(new RioHubResponse(200, [
            'affiliate_link' => 'https://riohub.vn/aff/tiktok-search-abc',
        ]));

        $result = $this->provider($client)->getAffiliateSearchLink('vinamilk ít đường', 'tester');

        $this->assertSame('https://riohub.vn/aff/tiktok-search-abc', $result['affiliate_url']);
        $this->assertSame('ready', $result['status']);
    }

    public function test_api_returns_url_field_as_affiliate_link(): void
    {
        $client = $this->mockClient(new RioHubResponse(200, [
            'url' => 'https://riohub.vn/aff/url-field',
        ]));

        $result = $this->provider($client)->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertSame('https://riohub.vn/aff/url-field', $result['affiliate_url']);
        $this->assertSame('ready', $result['status']);
    }

    public function test_empty_affiliate_link_returns_unavailable(): void
    {
        $client = $this->mockClient(new RioHubResponse(200, []));

        $result = $this->provider($client)->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
    }

    public function test_api_rejects_search_url_returns_unavailable(): void
    {
        $client = $this->mockClient(function ($url, $subId) {
            throw new \App\Services\RioHub\RioHubException(
                '[createAffiliateLink] RioHub API returned HTTP 400: invalid product url',
                400,
            );
        });

        $result = $this->provider($client)->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
    }

    public function test_unexpected_exception_returns_unavailable(): void
    {
        $client = $this->mockClient(function () {
            throw new \RuntimeException('boom');
        });

        $result = $this->provider($client)->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
    }

    public function test_username_is_passed_as_sub_id_when_supported(): void
    {
        $captured = [];

        $client = $this->mockClient(function (string $url, ?string $subId) use (&$captured) {
            $captured = ['url' => $url, 'subId' => $subId];

            return new RioHubResponse(200, ['affiliate_link' => 'https://riohub.vn/aff/x']);
        });

        $this->provider($client)->getAffiliateSearchLink('vinamilk ít đường', 'tester');

        $this->assertSame('tester', $captured['subId']);
        $this->assertSame('https://shop.tiktok.com/vn/search?q=vinamilk+%C3%ADt+%C4%91%C6%B0%E1%BB%9Dng', $captured['url']);
    }

    public function test_anonymous_user_passes_null_sub_id_and_no_fake_id_injected(): void
    {
        $captured = [];

        $client = $this->mockClient(function (string $url, ?string $subId) use (&$captured) {
            $captured = ['url' => $url, 'subId' => $subId];

            return new RioHubResponse(200, ['affiliate_link' => 'https://riohub.vn/aff/y']);
        });

        $result = $this->provider($client)->getAffiliateSearchLink('vinamilk ít đường');

        $this->assertNull($captured['subId']);
        $this->assertSame('ready', $result['status']);
        $this->assertStringNotContainsString('sub_id', $result['search_url']);
    }

    public function test_result_matches_normalized_shape(): void
    {
        $client = $this->mockClient(new RioHubResponse(200, [
            'affiliate_link' => 'https://riohub.vn/aff/z',
        ]));

        $result = $this->provider($client)->getAffiliateSearchLink('sữa Vinamilk');

        $this->assertArrayHasKey('search_url', $result);
        $this->assertArrayHasKey('affiliate_url', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertSame('https://shop.tiktok.com/vn/search?q=s%E1%BB%AFa+Vinamilk', $result['search_url']);
    }

    public function test_creator_username_never_exposed_in_result(): void
    {
        config([
            'services.riohub.creator_username' => 'creator-secret-user',
            'services.riohub.api_key' => 'supersecretkey',
        ]);

        $client = $this->mockClient(new RioHubResponse(200, [
            'affiliate_link' => 'https://riohub.vn/aff/safe',
        ]));

        $result = $this->provider($client)->getAffiliateSearchLink('vinamilk ít đường');

        $json = json_encode($result);
        $this->assertStringNotContainsString('creator-secret-user', $json);
        $this->assertStringNotContainsString('supersecretkey', $json);
    }
}