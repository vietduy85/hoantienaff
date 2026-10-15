<?php

namespace Tests\Unit\Services\Url;

use App\Services\Url\PlatformResolver;
use App\Services\Url\ResolvedTarget;
use Tests\TestCase;

/**
 * PlatformResolver — hostname-based, single source of truth for URL detection.
 * Proves spoof hosts are rejected and ShopeeFood wins over Shopee.
 * No HTTP, no DB.
 */
class PlatformResolverTest extends TestCase
{
    private PlatformResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new PlatformResolver;
    }

    public function test_shopee_landing_and_subdomains_are_recognised(): void
    {
        foreach ([
            'https://shopee.vn/product/1/2',
            'https://www.shopee.vn/product/1/2',
            'https://m.shopee.vn/product/1/2',
            'https://WWW.SHOPEE.VN:443/product/1/2?x=y#z',
        ] as $url) {
            $target = $this->resolver->resolve($url);
            $this->assertTrue($target->isShopee(), $url);
            $this->assertSame('Shopee', $target->platform);
        }
    }

    public function test_shopee_short_links_are_recognised(): void
    {
        foreach ([
            'https://s.shopee.vn/short/abc',
            'https://vn.shp.ee/abc',
            'https://s.shp.ee/abc',
            'https://shope.ee/abc',
        ] as $url) {
            $target = $this->resolver->resolve($url);
            $this->assertTrue($target->isShopee(), $url);
        }
    }

    public function test_shopeefood_wins_over_shopee(): void
    {
        foreach ([
            'https://shopeefood.vn/now-food/shop/123',
            'https://spf.shopee.vn/abc',
            'https://shopeefood.shopee.vn/now-food/shop/123',
            'https://shopeefood.shopee.vnnow-food/shop/123',
        ] as $url) {
            $target = $this->resolver->resolve($url);
            $this->assertTrue($target->isShopeeFood(), $url);
            $this->assertFalse($target->isShopee(), $url);
            $this->assertSame('ShopeeFood', $target->platform);
        }
    }

    public function test_lazada_domains_are_recognised(): void
    {
        foreach ([
            'https://www.lazada.vn/products/x-i1-s1.html',
            'https://lazada.sg/products/x',
            'https://lazada.co.th/products/x',
        ] as $url) {
            $target = $this->resolver->resolve($url);
            $this->assertTrue($target->isLazada(), $url);
            $this->assertSame('Lazada', $target->platform);
        }
    }

    public function test_tiktok_hosts_are_recognised(): void
    {
        foreach ([
            'https://tiktok.com/item/123',
            'https://vt.tiktok.com/abc',
            'https://www.tiktokshop.com/x',
        ] as $url) {
            $target = $this->resolver->resolve($url);
            $this->assertTrue($target->isTikTok(), $url);
            $this->assertSame('TikTok Shop', $target->platform);
        }
    }

    public function test_tiki_is_recognised_without_handler(): void
    {
        $target = $this->resolver->resolve('https://tiki.vn/p/123');

        $this->assertTrue($target->matched);
        $this->assertSame('Tiki', $target->platform);
        $this->assertSame(ResolvedTarget::STRATEGY_UNSUPPORTED, $target->strategy);
        $this->assertFalse($target->hasHandler());
    }

    public function test_spoof_hosts_are_not_recognised(): void
    {
        foreach ([
            'https://shopee.vn.example.com/product/1',
            'https://evil.com/tiktok',
            'https://lazada.evil.com/x',
            'https://vnexpress.net/shopee-khuyen-mai',
            'https://shopeefood.vn.evil.com/now-food/shop/1',
        ] as $url) {
            $target = $this->resolver->resolve($url);
            $this->assertFalse($target->isShopee(), $url);
            $this->assertFalse($target->isTikTok(), $url);
            $this->assertFalse($target->isLazada(), $url);
            $this->assertFalse($target->isShopeeFood(), $url);
            $this->assertSame('Khác', $target->platform, $url);
            $this->assertSame(ResolvedTarget::STRATEGY_UNSUPPORTED, $target->strategy, $url);
            $this->assertFalse($target->hasHandler(), $url);
        }
    }

    public function test_unknown_and_invalid_urls_are_unsupported(): void
    {
        $unknown = $this->resolver->resolve('https://example.com/x');
        $this->assertSame('Khác', $unknown->platform);
        $this->assertSame('unmapped', $unknown->reason);

        $invalid = $this->resolver->resolve('not a url');
        $this->assertSame(ResolvedTarget::STRATEGY_INVALID, $invalid->strategy);
        $this->assertFalse($invalid->matched);
    }
}
