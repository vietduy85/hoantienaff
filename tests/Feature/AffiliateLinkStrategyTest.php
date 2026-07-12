<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AffiliateLinkService;
use App\Services\Strategies\DirectLinkStrategy;
use App\Services\Strategies\ExtensionStrategy;
use App\Services\UrlResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AffiliateLinkStrategyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'testuser123',
        ]);
    }

    private function createShopeeLink(string $url = 'https://shopee.vn/product/123/456'): LinkRequest
    {
        return LinkRequest::create([
            'user_id'      => $this->user->id,
            'original_url' => $url,
            'platform'     => 'Shopee',
            'status'       => 'processing',
        ]);
    }

    // ─── Test 1: Extension strategy keeps link pending ────────

    public function test_extension_strategy_keeps_link_pending(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'extension');

        $link = $this->createShopeeLink();

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();

        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ─── Test 2: Direct strategy generates correct URL ────────

    public function test_direct_strategy_generates_correct_an_redir_url(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '17342330566');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $link = $this->createShopeeLink('https://shopee.vn/product/123/456');

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();

        $this->assertEquals('completed', $link->status);
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link->affiliate_url);
        $this->assertStringContainsString('affiliate_id=17342330566', $link->affiliate_url);
        $this->assertStringContainsString('sub_id=testuser123', $link->affiliate_url);
        $this->assertStringContainsString('origin_link=', $link->affiliate_url);
    }

    // ─── Test 3: URL with query string → strips query ─────────

    public function test_direct_strategy_strips_query_string(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $link = $this->createShopeeLink('https://shopee.vn/product/123/456?sku=789&var=100');

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();

        $decodedUrl = urldecode(parse_url($link->affiliate_url, PHP_URL_QUERY));
        $this->assertStringNotContainsString('sku=789', $decodedUrl);
        $this->assertStringNotContainsString('var=100', $decodedUrl);
        $this->assertStringContainsString('shopee.vn/product/123/456', $decodedUrl);
    }

    // ─── Test 4: Short link → resolves to original URL ────────

    public function test_direct_strategy_resolves_shortlink(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'true');

        $mockResolver = Mockery::mock(UrlResolverService::class);
        $mockResolver->shouldReceive('resolve')
            ->once()
            ->with('https://s.shopee.vn/short/abc')
            ->andReturn('https://shopee.vn/product/123/456');

        $this->app->instance(UrlResolverService::class, $mockResolver);

        $link = $this->createShopeeLink('https://s.shopee.vn/short/abc');

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();

        $this->assertEquals('completed', $link->status);
        $decodedUrl = urldecode(parse_url($link->affiliate_url, PHP_URL_QUERY));
        $this->assertStringContainsString('shopee.vn/product/123/456', $decodedUrl);
        $this->assertStringNotContainsString('s.shopee.vn', $decodedUrl);
    }

    // ─── Test 5: Resolve fails → fallback to original_url ─────

    public function test_direct_strategy_fallback_on_resolve_failure(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'true');

        $mockResolver = Mockery::mock(UrlResolverService::class);
        $mockResolver->shouldReceive('resolve')
            ->once()
            ->with('https://s.shopee.vn/short/abc')
            ->andReturn(null);

        $this->app->instance(UrlResolverService::class, $mockResolver);

        $link = $this->createShopeeLink('https://s.shopee.vn/short/abc');

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();

        $this->assertEquals('completed', $link->status);
        $decodedUrl = urldecode(parse_url($link->affiliate_url, PHP_URL_QUERY));
        $this->assertStringContainsString('s.shopee.vn/short/abc', $decodedUrl);
    }

    // ─── Test 6: sub_id = username ────────────────────────────

    public function test_direct_strategy_uses_username_as_sub_id(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $link = $this->createShopeeLink();

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();

        $this->assertStringContainsString('sub_id=testuser123', $link->affiliate_url);
    }

    // ─── Test 7: Context-based strategy selection ─────────────

    public function test_context_dashboard_reads_dashboard_strategy(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.admin.strategy', 'extension');
        Setting::set('affiliate.direct.shopee_affiliate_id', '11111');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $link = $this->createShopeeLink();
        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();
        $this->assertEquals('completed', $link->status);
        $this->assertStringContainsString('affiliate_id=11111', $link->affiliate_url);
    }

    public function test_context_admin_reads_admin_strategy(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.admin.strategy', 'extension');
        Setting::set('affiliate.direct.shopee_affiliate_id', '11111');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $link = $this->createShopeeLink();
        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'admin');

        $link->refresh();
        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ─── Test 8: Non-Shopee platforms are untouched ───────────

    public function test_non_shopee_platform_untouched(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');

        $link = LinkRequest::create([
            'user_id'      => $this->user->id,
            'original_url' => 'https://lazada.vn/product/123',
            'platform'     => 'Lazada',
            'status'       => 'completed',
        ]);

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();
        $this->assertEquals('completed', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ─── Test 9: Resolve shortlink disabled ───────────────────

    public function test_direct_strategy_does_not_resolve_when_disabled(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $mockResolver = Mockery::mock(UrlResolverService::class);
        $mockResolver->shouldReceive('resolve')->never();
        $this->app->instance(UrlResolverService::class, $mockResolver);

        $link = $this->createShopeeLink('https://s.shopee.vn/short/abc');

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();
        $decodedUrl = urldecode(parse_url($link->affiliate_url, PHP_URL_QUERY));
        $this->assertStringContainsString('s.shopee.vn/short/abc', $decodedUrl);
    }

    // ─── Test 10: URL encoding ────────────────────────────────

    public function test_direct_strategy_encodes_url_correctly(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $link = $this->createShopeeLink('https://shopee.vn/product/123/456?a=b&c=d');

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();

        $this->assertMatchesRegularExpression('/origin_link=https%3A%2F%2Fshopee\.vn/', $link->affiliate_url);
    }

    // ─── Test 11: Default strategy values ─────────────────────

    public function test_default_dashboard_strategy_is_direct(): void
    {
        $link = $this->createShopeeLink();

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();
        $this->assertEquals('completed', $link->status);
    }

    public function test_default_admin_strategy_is_extension(): void
    {
        $link = $this->createShopeeLink();

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'admin');

        $link->refresh();
        $this->assertEquals('pending', $link->status);
    }

    // ─── Case A: Dashboard + Direct ────────────────────────────
    // create(processing) → DirectStrategy → completed
    // Extension NOT pickup

    public function test_case_a_dashboard_direct_processing_to_completed(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $link = $this->createShopeeLink();

        $this->assertEquals('processing', $link->status);

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();
        $this->assertEquals('completed', $link->status);
        $this->assertNotNull($link->affiliate_url);

        $pendingCount = LinkRequest::where('status', 'pending')->count();
        $this->assertEquals(0, $pendingCount);
    }

    // ─── Case B: Dashboard + Extension ─────────────────────────
    // create(processing) → ExtensionStrategy → pending
    // Extension pickup

    public function test_case_b_dashboard_extension_processing_to_pending(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'extension');

        $link = $this->createShopeeLink();

        $this->assertEquals('processing', $link->status);

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'dashboard');

        $link->refresh();
        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);

        $pendingCount = LinkRequest::where('status', 'pending')->count();
        $this->assertEquals(1, $pendingCount);
    }

    // ─── Case C: Admin + Direct ────────────────────────────────
    // create(processing) → DirectStrategy → completed
    // No pending

    public function test_case_c_admin_direct_processing_to_completed(): void
    {
        Setting::set('affiliate.admin.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');

        $link = $this->createShopeeLink();

        $this->assertEquals('processing', $link->status);

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'admin');

        $link->refresh();
        $this->assertEquals('completed', $link->status);
        $this->assertNotNull($link->affiliate_url);

        $pendingCount = LinkRequest::where('status', 'pending')->count();
        $this->assertEquals(0, $pendingCount);
    }

    // ─── Case D: Admin + Extension ─────────────────────────────
    // create(processing) → ExtensionStrategy → pending
    // Extension pickup

    public function test_case_d_admin_extension_processing_to_pending(): void
    {
        Setting::set('affiliate.admin.strategy', 'extension');

        $link = $this->createShopeeLink();

        $this->assertEquals('processing', $link->status);

        $service = app(AffiliateLinkService::class);
        $service->handle($link, 'admin');

        $link->refresh();
        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);

        $pendingCount = LinkRequest::where('status', 'pending')->count();
        $this->assertEquals(1, $pendingCount);
    }
}
