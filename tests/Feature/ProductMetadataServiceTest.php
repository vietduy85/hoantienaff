<?php

namespace Tests\Feature;

use App\Services\ProductMetadata\ProductMetadataService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductMetadataServiceTest extends TestCase
{
    private ProductMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep tests hermetic: skip the (network) DNS verification step while
        // still exercising all syntactic + IP-literal SSRF guards.
        config(['services.product_metadata.verify_dns' => false]);

        $this->service = app(ProductMetadataService::class);
    }

    // ─── Extraction ────────────────────────────────────────────────

    public function test_extracts_name_image_and_price_from_open_graph(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response($this->html([
                '<meta property="og:title" content="Laptop Lenovo IdeaPad Slim 3" />',
                '<meta property="og:image" content="https://cdn.cellphones.com.vn/media/lenovo.jpg" />',
                '<meta property="product:price:amount" content="12900000" />',
            ]), 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $result = $this->service->fetch('https://cellphones.com.vn/laptop-lenovo.html');

        $this->assertSame('Laptop Lenovo IdeaPad Slim 3', $result['name']);
        $this->assertSame('https://cdn.cellphones.com.vn/media/lenovo.jpg', $result['image']);
        $this->assertSame(12900000, $result['price']);
    }

    public function test_falls_back_to_title_and_twitter_image(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response($this->html([
                '<title>Điện thoại Samsung Galaxy</title>',
                '<meta name="twitter:image" content="https://cdn.cellphones.com.vn/media/samsung.jpg" />',
            ]), 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->service->fetch('https://cellphones.com.vn/dien-thoai-samsung.html');

        $this->assertSame('Điện thoại Samsung Galaxy', $result['name']);
        $this->assertSame('https://cdn.cellphones.com.vn/media/samsung.jpg', $result['image']);
    }

    public function test_normalizes_relative_image_url_to_absolute(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response($this->html([
                '<meta property="og:title" content="Tai nghe" />',
                '<meta property="og:image" content="/media/products/headphone.jpg" />',
            ]), 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->service->fetch('https://cellphones.com.vn/tai-nghe.html');

        $this->assertSame('https://cellphones.com.vn/media/products/headphone.jpg', $result['image']);
    }

    public function test_parses_dotted_thousands_price(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response($this->html([
                '<meta property="og:title" content="Máy ảnh" />',
                '<meta property="og:price:amount" content="12.900.000" />',
            ]), 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->service->fetch('https://cellphones.com.vn/may-anh.html');

        $this->assertSame(12900000, $result['price']);
    }

    public function test_parses_price_from_json_ld(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response($this->html([
                '<meta property="og:title" content="Đồng hồ" />',
                '<script type="application/ld+json">{"@type":"Product","offers":{"price":"5990000","priceCurrency":"VND"}}</script>',
            ]), 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->service->fetch('https://cellphones.com.vn/dong-ho.html');

        $this->assertSame(5990000, $result['price']);
    }

    public function test_strips_marketing_suffix_from_title(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response($this->html([
                '<meta property="og:title" content="Laptop Lenovo IdeaPad Slim 3 | Giá rẻ, trả góp 0%" />',
            ]), 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->service->fetch('https://cellphones.com.vn/laptop.html');

        $this->assertSame('Laptop Lenovo IdeaPad Slim 3', $result['name']);
    }

    public function test_rejects_unsafe_image_scheme(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response($this->html([
                '<meta property="og:title" content="Sản phẩm" />',
                '<meta property="og:image" content="javascript:alert(1)" />',
            ]), 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->service->fetch('https://cellphones.com.vn/sp.html');

        $this->assertSame('Sản phẩm', $result['name']);
        $this->assertNull($result['image']);
    }

    public function test_returns_null_on_http_error(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response('', 500),
        ]);

        $this->assertNull($this->service->fetch('https://cellphones.com.vn/sp.html'));
    }

    public function test_returns_null_for_non_html_content_type(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response('{"a":1}', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->assertNull($this->service->fetch('https://cellphones.com.vn/api.json'));
    }

    public function test_returns_null_when_no_useful_tags(): void
    {
        Http::fake([
            'cellphones.com.vn/*' => Http::response('<html><head></head><body>nothing</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertNull($this->service->fetch('https://cellphones.com.vn/empty.html'));
    }

    // ─── Redirects ─────────────────────────────────────────────────

    public function test_follows_redirect_and_parses_target(): void
    {
        Http::fake([
            'cellphones.com.vn/old.html' => Http::response('', 302, ['Location' => 'https://cellphones.com.vn/new.html']),
            'cellphones.com.vn/new.html' => Http::response($this->html([
                '<meta property="og:title" content="Sản phẩm mới" />',
            ]), 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->service->fetch('https://cellphones.com.vn/old.html');

        $this->assertSame('Sản phẩm mới', $result['name']);
    }

    public function test_blocks_redirect_to_internal_address(): void
    {
        Http::fake([
            'cellphones.com.vn/old.html' => Http::response('', 302, ['Location' => 'http://127.0.0.1/secret']),
        ]);

        $this->assertNull($this->service->fetch('https://cellphones.com.vn/old.html'));
    }

    // ─── SSRF guard ────────────────────────────────────────────────

    #[DataProvider('unsafeUrls')]
    public function test_rejects_unsafe_urls(string $url): void
    {
        $this->assertFalse($this->service->isSafeUrl($url));
    }

    public static function unsafeUrls(): array
    {
        return [
            'localhost' => ['http://localhost/admin'],
            'localhost subdomain' => ['http://foo.localhost/admin'],
            'loopback ipv4' => ['http://127.0.0.1/'],
            'private 10' => ['http://10.0.0.5/'],
            'private 192.168' => ['http://192.168.1.1/'],
            'private 172.16' => ['http://172.16.0.1/'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback ipv6' => ['http://[::1]/'],
            'local tld' => ['http://server.local/'],
            'internal tld' => ['http://db.internal/'],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://example.com/x'],
            'credentials' => ['http://user:pass@example.com/'],
        ];
    }

    public function test_accepts_public_https_url(): void
    {
        $this->assertTrue($this->service->isSafeUrl('https://cellphones.com.vn/laptop.html'));
    }

    public function test_internal_url_is_never_requested(): void
    {
        Http::fake();

        $this->assertNull($this->service->fetch('http://127.0.0.1/secret'));

        Http::assertNothingSent();
    }

    public function test_invalid_url_returns_null_without_request(): void
    {
        Http::fake();

        $this->assertNull($this->service->fetch('not-a-url'));

        Http::assertNothingSent();
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $head
     */
    private function html(array $head): string
    {
        return '<!doctype html><html><head><meta charset="utf-8">'.implode('', $head).'</head><body></body></html>';
    }
}
