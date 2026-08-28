<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AffiliateCacheService;
use App\Services\CashbackCalculator;
use App\Services\ProductDataService;
use App\Services\ProviderFactory;
use App\Services\UrlResolverService;
use App\Services\Providers\TikTokProvider;
use App\Services\Providers\LazadaProvider;
use App\Services\Providers\ShopeeProvider;
use App\Services\TikTok\DTOs\TikTokAffiliateLinkDTO;
use App\Services\TikTok\TikTokAffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDirectLinkTikTokTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'testuser',
        ]);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    // ------------------------------------------------------------------
    //  Test 1: Shopee URL — existing behavior preserved
    // ------------------------------------------------------------------

    public function test_shopee_url_creates_link_with_shopee_status(): void
    {
        $this->mockUrlResolver();
        $this->mockCacheService();
        $this->mockProductDataService(null);

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopee.vn/product/123/456',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('Shopee', $link->platform);
        $this->assertEquals('processing', $link->status);
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link->affiliate_url);
        $this->assertStringContainsString('affiliate_id=12345', $link->affiliate_url);
    }

    // ------------------------------------------------------------------
    //  Test 2: TikTok URL — TikTokProvider called
    // ------------------------------------------------------------------

    public function test_tiktok_url_calls_tiktok_provider(): void
    {
        $mockTiktokService = $this->createMock(TikTokAffiliateService::class);
        $mockTiktokService
            ->expects($this->once())
            ->method('createAffiliateLink')
            ->with('https://tiktok.com/item/12345')
            ->willReturn(new TikTokAffiliateLinkDTO(
                affiliateUrl: 'https://riohub.vn/aff/abc123',
                productId: '12345',
                productName: 'Test TikTok Product',
            ));

        $this->app->instance(TikTokAffiliateService::class, $mockTiktokService);

        $mockProductService = $this->createMock(\App\Services\TikTok\TikTokProductService::class);
        $mockProductService
            ->expects($this->once())
            ->method('getProduct')
            ->with('12345')
            ->willReturn(new \App\Services\TikTok\DTOs\TikTokProductDTO(
                productId: '12345',
                name: 'Test TikTok Product',
                imageUrl: 'https://img.tiktok.com/x.jpg',
                price: 100000,
                currency: 'VND',
                commissionRatePct: 2300,
            ));

        $this->app->instance(\App\Services\TikTok\TikTokProductService::class, $mockProductService);

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://tiktok.com/item/12345',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('TikTok Shop', $link->platform);
        $this->assertEquals('completed', $link->status);
        $this->assertEquals('https://riohub.vn/aff/abc123', $link->affiliate_url);
        $this->assertEquals('Test TikTok Product', $link->product_name);
        $this->assertEquals(23000.00, (float) $link->estimated_cashback); // 100000 * 23%
        $this->assertEquals(0.60, (float) $link->cashback_rate);
        $this->assertEquals(12420.00, (float) $link->user_estimated_cashback); // 23000*0.9=20700*0.6
    }

    // ------------------------------------------------------------------
    //  Test 2b: RioHub HTTP is called exactly once per TikTok request
    //  (POST /links exactly 1, GET /products exactly 1 — no duplicate)
    // ------------------------------------------------------------------

    public function test_tiktok_calls_riohub_links_and_products_exactly_once(): void
    {
        $linkCalls = 0;
        $productCalls = 0;

        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$linkCalls, &$productCalls) {
            $url = (string) $request->url();

            if (str_contains($url, '/partner/tiktok/affiliate/links')) {
                $linkCalls++;
                return \Illuminate\Support\Facades\Http::response([
                    'success' => true,
                    'affiliate_link' => 'https://riohub.vn/aff/once',
                    'product_id' => '515',
                ], 200);
            }

            if (str_contains($url, '/partner/tiktok/affiliate/products')) {
                $productCalls++;
                return \Illuminate\Support\Facades\Http::response([
                    'products' => [[
                        'id' => '515',
                        'title' => 'Once Product',
                        'sales_price' => ['currency' => 'VND', 'minimum_amount' => 200000],
                        'commission' => ['rate' => 1000],
                    ]],
                ], 200);
            }

            return \Illuminate\Support\Facades\Http::response(['success' => true], 200);
        });

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://tiktok.com/item/515',
            ]);

        $response->assertOk();

        $this->assertSame(1, $linkCalls, 'POST /links must be called exactly ONCE per TikTok request. Duplicate detected.');
        $this->assertSame(1, $productCalls, 'GET /products must be called exactly ONCE per TikTok request.');

        $link = LinkRequest::latest()->first();
        $this->assertEquals('https://riohub.vn/aff/once', $link->affiliate_url);
        $this->assertEquals('completed', $link->status);
        $this->assertEquals(200000, $link->product_price);
        $this->assertEquals(20000.00, (float) $link->estimated_cashback); // 200000 * 10%
    }

    // ------------------------------------------------------------------
    //  Test 3: Lazada URL — existing behavior (no provider changes)
    // ------------------------------------------------------------------

    public function test_lazada_url_no_change(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://lazada.vn/product/123',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('Lazada', $link->platform);
        $this->assertEquals('completed', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ------------------------------------------------------------------
    //  Test 4: TikTok provider failure — graceful fallback
    // ------------------------------------------------------------------

    public function test_tiktok_provider_failure_is_graceful(): void
    {
        $mockTiktokService = $this->createMock(TikTokAffiliateService::class);
        $mockTiktokService
            ->method('createAffiliateLink')
            ->willThrowException(
                new \App\Services\TikTok\TikTokServiceException('API error', 500, 'Internal error')
            );

        $this->app->instance(TikTokAffiliateService::class, $mockTiktokService);

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://tiktok.com/item/fail',
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['success', 'error', 'request_id', 'platform']);
        $response->assertJson(['success' => false]);

        $link = LinkRequest::latest()->first();
        $this->assertEquals('TikTok Shop', $link->platform);
        $this->assertEquals('failed', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ------------------------------------------------------------------
    //  Test 5: Shopee flow still creates processing + affiliate_url
    // ------------------------------------------------------------------

    public function test_shopee_flow_generates_affiliate_url(): void
    {
        $this->mockUrlResolver();
        $this->mockCacheService();
        $this->mockProductDataService(null);

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopee.vn/product/100/200?var=1',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link->affiliate_url);
        $this->assertStringContainsString('origin_link=', $link->affiliate_url);
        $this->assertStringContainsString('sub_id=testuser', $link->affiliate_url);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function mockUrlResolver(): void
    {
        $mock = $this->createMock(UrlResolverService::class);
        $mock->method('resolve')->willReturnArgument(0);
        $this->app->instance(UrlResolverService::class, $mock);
    }

    private function mockCacheService(): void
    {
        $mock = $this->createMock(AffiliateCacheService::class);
        $mock->method('extractItemId')->willReturn(null);
        $this->app->instance(AffiliateCacheService::class, $mock);
    }

    private function mockProductDataService(?array $return): void
    {
        $mock = $this->createMock(ProductDataService::class);
        $mock->method('getByUrl')->willReturn($return ?? ['success' => false]);
        $this->app->instance(ProductDataService::class, $mock);
    }
}
