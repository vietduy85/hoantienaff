<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AffiliateCacheService;
use App\Services\ProductDataService;
use App\Services\ShopeeFood\ShopeeFoodStoreService;
use App\Services\UrlResolverService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeFoodLinkPreviewTest extends TestCase
{
    use RefreshDatabase;

    private string $extensionToken = 'test-extension-token';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.affiliate_extension.token' => $this->extensionToken]);

        $this->user = User::factory()->create([
            'username' => 'test_user',
        ]);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.admin.strategy', 'extension');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    // ─── 1. Deep link affiliate URL is generated at creation ────────

    public function test_deep_link_affiliate_url_generated_with_preview(): void
    {
        $this->fakeStoreApi(['status' => 'ok', 'data' => [['name' => 'Quán Ngon']]]);

        $link = $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133');

        $this->assertSame('ShopeeFood', $link->platform);
        $this->assertSame('completed', $link->status);
        $this->assertSame($this->expectedDeepLink(1258133, 'test_user'), $link->affiliate_url);
        $this->assertSame('Quán Ngon', $link->product_name);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
        $this->assertSame('shopeefood-store-api', $link->data_source);
    }

    // ─── 2. Store API name is used for the preview ─────────────────

    public function test_preview_uses_store_api_name(): void
    {
        $this->fakeStoreApi(['status' => 'ok', 'data' => [['name' => 'Ốc Ngon Corner']]]);

        $link = $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133');

        $this->assertSame('Ốc Ngon Corner', $link->product_name);
    }

    // ─── 3. Shared placeholder image ───────────────────────────────

    public function test_preview_uses_shared_placeholder_image(): void
    {
        $this->fakeStoreApi(['status' => 'ok', 'data' => [['name' => 'Quán Ngon']]]);

        $link = $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133');

        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
    }

    // ─── 4. Exact estimate text ────────────────────────────────────

    public function test_preview_uses_exact_estimate_text(): void
    {
        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Lên đến 4% tổng bill', false);

        $this->assertStringNotContainsString('Lên đến 4$', $response->getContent());
    }

    // ─── 5. Cache hit does not call the Store API again ────────────

    public function test_store_api_is_cached_and_not_called_twice(): void
    {
        $this->fakeStoreApi(['status' => 'ok', 'data' => [['name' => 'Quán Ngon']]]);

        $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133');
        $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133?ref=abc');

        $this->assertCount(1, Http::recorded(
            fn ($request) => str_contains($request->url(), 'store.php'),
        ));

        $this->assertSame('Quán Ngon', Cache::get('shopeefood:store:1258133'));
        $this->assertSame('Quán Ngon', LinkRequest::latest()->first()->product_name);
    }

    // ─── 6. Cache TTL is 12 hours ──────────────────────────────────

    public function test_store_cache_ttl_is_twelve_hours(): void
    {
        $cacheKey = 'shopeefood:store:1258133';

        Http::fake([
            'data.addlivetag.com/shopeefood/store.php*' => Http::response(
                ['status' => 'ok', 'data' => [['name' => 'Quán Ngon']]],
                200,
            ),
        ]);

        Cache::shouldReceive('get')->once()->with($cacheKey)->andReturn(null);
        Cache::shouldReceive('put')->once()->with($cacheKey, 'Quán Ngon', 43200);

        $service = app(ShopeeFoodStoreService::class);

        $this->assertSame('Quán Ngon', $service->storeName('1258133'));
    }

    // ─── 7. HTTP 500 does not break link creation ──────────────────

    public function test_store_api_http_500_does_not_break_link_creation(): void
    {
        $this->fakeStoreApi(['status' => 'error'], 500);

        $link = $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133');

        $this->assertSame('completed', $link->status);
        $this->assertSame($this->expectedDeepLink(1258133, 'test_user'), $link->affiliate_url);
        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
        $this->assertSame('shopeefood-fallback', $link->data_source);
    }

    // ─── 8. Timeout does not break link creation ───────────────────

    public function test_store_api_timeout_does_not_break_link_creation(): void
    {
        Http::fake([
            'data.addlivetag.com/shopeefood/store.php*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $link = $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133');

        $this->assertSame('completed', $link->status);
        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
        $this->assertSame('shopeefood-fallback', $link->data_source);
    }

    // ─── 9. Empty data → fallback ──────────────────────────────────

    public function test_store_api_empty_data_falls_back(): void
    {
        $this->fakeStoreApi(['status' => 'ok', 'data' => []]);

        $link = $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133');

        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertSame('shopeefood-fallback', $link->data_source);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
    }

    // ─── 10. null/empty name → fallback ────────────────────────────

    public function test_store_api_null_name_falls_back(): void
    {
        $this->fakeStoreApi(['status' => 'ok', 'data' => [['name' => null]]]);

        $link = $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133');

        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertSame('shopeefood-fallback', $link->data_source);
    }

    // ─── 11. Missing restaurant_id → failed, safe fallback ─────────

    public function test_missing_restaurant_id_is_safe(): void
    {
        Http::fake(); // all requests → 200 empty, no redirect, no restaurant id

        $link = $this->createShopeeFoodLink('https://shopeefood.vn/delivery/abc-123');

        $this->assertSame('failed', $link->status);
        $this->assertNull($link->affiliate_url);
        $this->assertSame('ShopeeFood', $link->product_name);
        $this->assertStringContainsString('CuaHangShopeeFood.png', $link->product_image);
        $this->assertSame('shopeefood-fallback', $link->data_source);
        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), 'store.php')));
    }

    // ─── 11b. SPF preview metadata comes from the FINAL URL ─────────

    public function test_spf_preview_uses_final_url_open_graph(): void
    {
        Http::fake([
            'spf.shopee.vn/QweRty123*' => Http::response('', 301, [
                'Location' => 'https://shopeefood.vn/now-food/shop/1258133?utm_source=an_17343840387&utm_content=tintuctonghop103----',
            ]),
            'shopeefood.vn/now-food/shop/1258133*' => Http::response(
                $this->ogPage('Quán Từ Final URL', 'https://img.example/final.jpg'),
                200,
            ),
        ]);

        $link = $this->createShopeeFoodLink('https://spf.shopee.vn/QweRty123');

        $this->assertSame('Quán Từ Final URL', $link->product_name);
        $this->assertSame('https://img.example/final.jpg', $link->product_image);
        $this->assertSame('shopeefood-open-graph', $link->data_source);
        $this->assertSame($this->expectedDeepLink(1258133, 'test_user'), $link->affiliate_url);
    }

    // ─── 12 & 13. Copy / Mua ngay buttons use the real affiliate URL

    public function test_copy_and_mua_ngay_buttons_use_actual_affiliate_url(): void
    {
        $this->fakeStoreApi(['status' => 'ok', 'data' => [['name' => 'Quán Ngon']]]);

        $link = $this->createShopeeFoodLink('https://shopeefood.vn/now-food/shop/1258133');

        $apiResponse = $this->actingAs($this->user)->getJson('/api/link-request/' . $link->id);
        $apiResponse->assertOk();
        $apiResponse->assertJsonPath('status', 'completed');
        $apiResponse->assertJsonPath('affiliate_url', $this->expectedDeepLink(1258133, 'test_user'));

        $dashboardHtml = $this->actingAs($this->user)->get('/dashboard')->getContent();

        $this->assertStringContainsString('x-bind:href="result.affiliate_url"', $dashboardHtml);
        $this->assertEquals(2, substr_count($dashboardHtml, 'x-bind:href="result.affiliate_url"'));
    }

    // ─── 14. Regression: Shopee/TikTok/Lazada preview unchanged ────

    public function test_shopee_direct_preview_unaffected(): void
    {
        $this->mockDirectLinkDependencies();

        $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopee.vn/product/123/456',
            ])
            ->assertOk();

        $link = LinkRequest::latest()->first();

        $this->assertSame('Shopee', $link->platform);
        $this->assertSame('processing', $link->status);
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link->affiliate_url);
        $this->assertNull($link->product_image);
        $this->assertNull($link->product_name);
        $this->assertNull($link->data_source);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function createShopeeFoodLink(string $url): LinkRequest
    {
        $this->actingAs($this->user)
            ->postJson('/link-requests', ['original_url' => $url])
            ->assertOk();

        return LinkRequest::latest()->first();
    }

    private function expectedDeepLink(int $restaurantId, string $username): string
    {
        return 'https://shopeefood.shopee.vn/now-food/shop/' . $restaurantId
            . '?shareChannel=copy_link'
            . '&utm_source=an_12345'
            . '&utm_medium=affiliate_food'
            . '&utm_campaign=-'
            . '&utm_content=' . rawurlencode($username);
    }

    private function fakeStoreApi(array $body, int $status = 200): void
    {
        Http::fake([
            'data.addlivetag.com/shopeefood/store.php*' => Http::response($body, $status),
        ]);
    }

    private function ogPage(string $title, string $image): string
    {
        return '<html><head><title>' . $title . '</title>'
            . '<meta property="og:title" content="' . $title . '" />'
            . '<meta property="og:image" content="' . $image . '" /></head><body></body></html>';
    }

    private function mockDirectLinkDependencies(): void
    {
        $resolver = $this->createMock(UrlResolverService::class);
        $resolver->method('resolve')->willReturnArgument(0);
        $this->app->instance(UrlResolverService::class, $resolver);

        $cache = $this->createMock(AffiliateCacheService::class);
        $cache->method('extractItemId')->willReturn(null);
        $this->app->instance(AffiliateCacheService::class, $cache);

        $productData = $this->createMock(ProductDataService::class);
        $productData->method('getByUrl')->willReturn(['success' => false]);
        $this->app->instance(ProductDataService::class, $productData);
    }
}