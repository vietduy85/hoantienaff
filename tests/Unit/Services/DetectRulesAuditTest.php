<?php

namespace Tests\Unit\Services;

use App\Services\Lazada\LazadaUrlParser;
use App\Services\ShopeeFood\ShopeeFoodUrlParser;
use App\Services\UrlResolverService;
use Tests\TestCase;

/**
 * Chứng minh ngữ nghĩa detect THỰC TẾ đang dùng trong code, làm căn cứ cho
 * dữ liệu hiển thị ở tab "Connect Partner AFF":
 *  - rule kiểu substring vs hostname khác nhau thế nào (chống giả mạo),
 *  - ShopeeFood trùng chuỗi "shopee" với Shopee nên phải check trước,
 *  - các hostname short-domain của Shopee/Lazada.
 * Không gọi HTTP.
 */
class DetectRulesAuditTest extends TestCase
{
    public function test_shopee_substring_rule_matches_spoof_but_hostname_rule_rejects_it(): void
    {
        $spoof = 'https://shopee.vn.example.com/product/1';

        // Rule substring (detectPlatform) khớp cả host giả mạo...
        $this->assertTrue(str_contains(strtolower($spoof), 'shopee'));

        // ...nhưng rule hostname của resolver thì từ chối.
        $resolver = new UrlResolverService();
        $this->assertFalse($resolver->isShopeeLanding($spoof));
        $this->assertTrue($resolver->isShopeeLanding('https://shopee.vn/product/1'));
        $this->assertTrue($resolver->isShopeeLanding('https://www.shopee.vn/product/1'));
    }

    public function test_shopee_short_domains_are_recognised_and_spoof_rejected(): void
    {
        $resolver = new UrlResolverService();

        $this->assertTrue($resolver->isShortLink('https://s.shopee.vn/abc'));
        $this->assertTrue($resolver->isShortLink('https://vn.shp.ee/abc'));
        $this->assertTrue($resolver->isShortLink('https://s.shp.ee/abc'));
        $this->assertTrue($resolver->isShortLink('https://shope.ee/abc'));

        $this->assertFalse($resolver->isShortLink('https://shopee.vn/product/1'));
        $this->assertFalse($resolver->isShortLink('https://s.shopee.vn.evil.com/abc'));
    }

    public function test_shopeefood_overlaps_shopee_substring_so_must_be_checked_first(): void
    {
        $shopeeFoodUrl = 'https://shopeefood.vn/now-food/shop/123';

        // Đây chính là lý do phải kiểm tra ShopeeFood TRƯỚC Shopee:
        // chuỗi con "shopee" cũng khớp với "shopeefood".
        $this->assertTrue(str_contains(strtolower($shopeeFoodUrl), 'shopee'));

        // Rule hostname của ShopeeFood nhận đúng nó.
        $this->assertTrue(ShopeeFoodUrlParser::isShopeeFoodUrl($shopeeFoodUrl));
        $this->assertTrue(ShopeeFoodUrlParser::isShopeeFoodUrl('https://www.shopeefood.vn/now-food/shop/123'));
        $this->assertTrue(ShopeeFoodUrlParser::isShopeeFoodUrl('https://spf.shopee.vn/abc'));
        $this->assertTrue(ShopeeFoodUrlParser::isShopeeFoodUrl('https://shopeefood.shopee.vn/now-food/shop/123'));

        // Và KHÔNG nhận nhầm URL Shopee thường.
        $this->assertFalse(ShopeeFoodUrlParser::isShopeeFoodUrl('https://shopee.vn/product/1'));
        $this->assertFalse(ShopeeFoodUrlParser::isShopeeFoodUrl('https://shopeefood.vn.evil.com/now-food/shop/1'));
    }

    public function test_lazada_hostname_rule_accepts_official_domains_and_rejects_spoof(): void
    {
        $parser = new LazadaUrlParser();

        $this->assertTrue($parser->isLazadaHost('lazada.vn'));
        $this->assertTrue($parser->isLazadaHost('www.lazada.sg'));
        $this->assertTrue($parser->isLazadaHost('lazada.com.my'));
        $this->assertTrue($parser->isLazadaHost('lazada.shop'));

        $this->assertFalse($parser->isLazadaHost('lazada.vn.evil.com'));
        $this->assertFalse($parser->isLazadaHost('notlazada.vn'));
    }
}
