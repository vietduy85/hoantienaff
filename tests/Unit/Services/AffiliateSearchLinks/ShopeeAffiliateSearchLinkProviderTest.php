<?php

namespace Tests\Unit\Services\AffiliateSearchLinks;

use App\Services\AffiliateSearchLinks\Providers\ShopeeAffiliateSearchLinkProvider;
use Tests\TestCase;

class ShopeeAffiliateSearchLinkProviderTest extends TestCase
{
    private ShopeeAffiliateSearchLinkProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new ShopeeAffiliateSearchLinkProvider;
    }

    public function test_platform_is_shopee(): void
    {
        $this->assertSame('shopee', $this->provider->platform());
    }

    public function test_build_search_url_encodes_keyword_properly(): void
    {
        $this->assertSame(
            'https://shopee.vn/search?keyword=vinamilk+%C3%ADt+%C4%91%C6%B0%E1%BB%9Dng',
            $this->provider->buildSearchUrl('vinamilk ít đường'),
        );
    }

    public function test_build_search_url_encodes_vietnamese_characters(): void
    {
        $this->assertSame(
            'https://shopee.vn/search?keyword=m%C3%AC+H%E1%BA%A3o+H%E1%BA%A3o',
            $this->provider->buildSearchUrl('mì Hảo Hảo'),
        );
    }

    public function test_search_url_has_no_fabricated_tracking_parameter(): void
    {
        $url = $this->provider->buildSearchUrl('vinamilk ít đường');

        $this->assertStringNotContainsString('affiliate_id', $url);
        $this->assertStringNotContainsString('spm', $url);
        $this->assertStringNotContainsString('utm_', $url);
    }

    public function test_get_affiliate_search_link_returns_search_url_only(): void
    {
        $result = $this->provider->getAffiliateSearchLink('vinamilk ít đường', 'tester');

        $this->assertSame('https://shopee.vn/search?keyword=vinamilk+%C3%ADt+%C4%91%C6%B0%E1%BB%9Dng', $result['search_url']);
        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
    }

    public function test_anonymous_user_gets_no_tracking_id(): void
    {
        $result = $this->provider->getAffiliateSearchLink('sữa Vinamilk');

        $this->assertNull($result['affiliate_url']);
        $this->assertSame('unavailable', $result['status']);
        $this->assertStringNotContainsString('sub_id', $result['search_url']);
    }

    public function test_keyword_is_trimmed(): void
    {
        $this->assertSame(
            'https://shopee.vn/search?keyword=n%C6%B0%E1%BB%9Bc+m%E1%BA%AFm+Nam+Ng%C6%B0',
            $this->provider->buildSearchUrl('  nước mắm Nam Ngư  '),
        );
    }
}