<?php

namespace Tests\Unit\Services;

use App\Services\UrlResolverService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UrlResolverServiceTest extends TestCase
{
    private UrlResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UrlResolverService::class);
    }

    public function test_detects_shopee_short_links(): void
    {
        $shortLinks = [
            'https://vn.shp.ee/yvL5woPp',
            'https://s.shp.ee/abc',
            'https://shope.ee/abc',
            'https://s.shopee.vn/short/abc',
            'https://www.s.shp.ee/abc',
        ];

        foreach ($shortLinks as $url) {
            $this->assertTrue($this->service->isShortLink($url), 'should be short link: '.$url);
        }
    }

    public function test_full_shopee_url_is_not_a_short_link(): void
    {
        $this->assertFalse($this->service->isShortLink('https://shopee.vn/product/59917031/56759033748'));
        $this->assertFalse($this->service->isShortLink('https://www.shopee.vn/product/59917031/56759033748'));
    }

    public function test_detects_shopee_landing_urls(): void
    {
        $this->assertTrue($this->service->isShopeeLanding('https://shopee.vn/product/59917031/56759033748'));
        $this->assertTrue($this->service->isShopeeLanding('https://www.shopee.vn/product/1/2'));
        $this->assertFalse($this->service->isShopeeLanding('https://vn.shp.ee/yvL5woPp'));
        $this->assertFalse($this->service->isShopeeLanding('https://evil.example/x'));
    }

    public function test_needs_resolution_rules(): void
    {
        $this->assertTrue($this->service->needsResolution('https://vn.shp.ee/yvL5woPp'));
        $this->assertTrue($this->service->needsResolution('https://s.shopee.vn/short/abc'));
        $this->assertTrue($this->service->needsResolution('https://shopee.vn/search?keyword=ao'));
        $this->assertTrue($this->service->needsResolution('https://shopee.vn/category/1'));

        $this->assertFalse($this->service->needsResolution('https://shopee.vn/product/59917031/56759033748'));
        $this->assertFalse($this->service->needsResolution('https://shopee.vn/-i.59917031.56759033748'));
    }

    public function test_canonical_product_url_is_returned_unchanged(): void
    {
        $url = 'https://shopee.vn/product/59917031/56759033748?utm_source=x';

        $this->assertSame($url, $this->service->resolve($url));
    }

    public function test_successful_resolution_is_cached_without_network(): void
    {
        $short = 'https://vn.shp.ee/cached123';
        $landing = 'https://shopee.vn/product/59917031/56759033748';

        Cache::put('shopee_resolve:'.md5($short), $landing, 86400);

        $this->assertSame($landing, $this->service->resolve($short));
    }

    public function test_unresolved_short_link_is_not_cached(): void
    {
        $short = 'https://vn.shp.ee/unresolvable';

        $this->assertNull(Cache::get('shopee_resolve:'.md5($short)));
    }
}
