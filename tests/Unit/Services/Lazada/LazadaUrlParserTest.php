<?php

namespace Tests\Unit\Services\Lazada;

use App\Services\Lazada\LazadaException;
use App\Services\Lazada\LazadaUrlParser;
use Tests\TestCase;

class LazadaUrlParserTest extends TestCase
{
    private LazadaUrlParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LazadaUrlParser();
    }

    public function test_accepts_canonical_lazada_product_url(): void
    {
        $url = 'https://www.lazada.vn/products/ao-so-mi-nam-i123-s456.html';

        $this->assertSame($url, $this->parser->assertLazadaUrl($url));
    }

    public function test_accepts_lazada_url_with_query_parameters(): void
    {
        $url = 'https://www.lazada.vn/products/ao-i123.html?spm=a2o4n.10431750.0.0';

        $this->assertSame($url, $this->parser->assertLazadaUrl($url));
    }

    public function test_accepts_other_official_lazada_country_domains(): void
    {
        foreach (['https://www.lazada.sg/products/x-i1.html', 'https://www.lazada.com.my/products/x-i1-s1.html', 'https://www.lazada.co.th/products/x-i1-s1.html', 'https://www.lazada.com.ph/products/x-i1.html', 'https://www.lazada.co.id/products/x-i1.html'] as $url) {
            $this->assertSame($url, $this->parser->assertLazadaUrl($url), "should accept $url");
        }
    }

    public function test_rejects_non_lazada_url(): void
    {
        try {
            $this->parser->assertLazadaUrl('https://example.com/products/1');
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Chỉ hỗ trợ link sản phẩm Lazada. Vui lòng dán đúng link Lazada.', $e->getUserMessage());
        }
    }

    public function test_rejects_url_that_only_contains_lazada_in_path(): void
    {
        try {
            $this->parser->assertLazadaUrl('https://shopee.vn/lazada-deal');
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Chỉ hỗ trợ link sản phẩm Lazada. Vui lòng dán đúng link Lazada.', $e->getUserMessage());
        }
    }

    public function test_rejects_invalid_url(): void
    {
        try {
            $this->parser->assertLazadaUrl('not a url');
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Link không hợp lệ. Vui lòng dán một link sản phẩm Lazada hợp lệ.', $e->getUserMessage());
        }
    }

    public function test_rejects_non_http_scheme(): void
    {
        try {
            $this->parser->assertLazadaUrl('ftp://www.lazada.vn/products/x-i1.html');
            $this->fail('Expected LazadaException');
        } catch (LazadaException $e) {
            $this->assertSame('Link không hợp lệ. Vui lòng dán một link sản phẩm Lazada hợp lệ.', $e->getUserMessage());
        }
    }

    public function test_is_lazada_host(): void
    {
        $this->assertTrue($this->parser->isLazadaHost('www.lazada.vn'));
        $this->assertTrue($this->parser->isLazadaHost('lazada.co.th'));
        $this->assertTrue($this->parser->isLazadaHost('sub.lazada.vn'));
        $this->assertFalse($this->parser->isLazadaHost('lazada.com.evil.com'));
        $this->assertFalse($this->parser->isLazadaHost('shopee.vn'));
    }
}