<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AffiliateCacheService;
use App\Services\ProductDataService;
use App\Services\UrlResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShopeeFoodRoutingTest extends TestCase
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

        Http::fake([
            'data.addlivetag.com/*' => Http::response(['status' => 'ok', 'data' => []], 200),
        ]);
    }

    private function mockDirectLinkDependencies(): void
    {
        $resolver = $this->createMock(UrlResolverService::class);
        $resolver->method('resolve')->willReturnArgument(0);
        $resolver->method('isShopeeLanding')->willReturn(true);
        $this->app->instance(UrlResolverService::class, $resolver);

        $cache = $this->createMock(AffiliateCacheService::class);
        $cache->method('extractItemId')->willReturn(null);
        $this->app->instance(AffiliateCacheService::class, $cache);

        $productData = $this->createMock(ProductDataService::class);
        $productData->method('getByUrl')->willReturn(['success' => false]);
        $this->app->instance(ProductDataService::class, $productData);
    }

    private function expectedShopeeFoodDeepLink(int $restaurantId, string $username): string
    {
        return 'https://shopeefood.shopee.vn/now-food/shop/' . $restaurantId
            . '?shareChannel=copy_link'
            . '&utm_source=an_12345'
            . '&utm_medium=affiliate_food'
            . '&utm_campaign=-'
            . '&utm_content=' . rawurlencode($username);
    }

    // ─── 1. shopee.vn → Direct Link ─────────────────────────────

    public function test_shopee_vn_uses_direct_link_flow(): void
    {
        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopee.vn/product/123/456',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('Shopee', $link->platform);
        $this->assertEquals('processing', $link->status);
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link->affiliate_url);
        $this->assertStringContainsString('sub_id=test_user', $link->affiliate_url);
    }

    // ─── 2. shopeefood.shopee.vn → ShopeeFood Deep Link ──────────

    public function test_shopeefood_shopee_vn_uses_deep_link_flow(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.shopee.vn/now-food/shop/757850',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('ShopeeFood', $link->platform);
        $this->assertEquals('completed', $link->status);
        $this->assertSame($this->expectedShopeeFoodDeepLink(757850, 'test_user'), $link->affiliate_url);
    }

    // ─── 3. shopeefood.vn → ShopeeFood Deep Link ─────────────────

    public function test_shopeefood_vn_uses_deep_link_flow(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/now-food/shop/757850',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $link = LinkRequest::latest()->first();
        $this->assertEquals('ShopeeFood', $link->platform);
        $this->assertEquals('completed', $link->status);
        $this->assertSame($this->expectedShopeeFoodDeepLink(757850, 'test_user'), $link->affiliate_url);
    }

    // ─── 4. www.shopeefood.vn → ShopeeFood Deep Link ─────────────

    public function test_www_shopeefood_vn_uses_deep_link_flow(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://www.shopeefood.vn/now-food/shop/1284425',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('completed', $link->status);
        $this->assertSame($this->expectedShopeeFoodDeepLink(1284425, 'test_user'), $link->affiliate_url);
    }

    // ─── 4b. Invariant: shopeefood.vn Deep Link unaffected by
    //          affiliate.admin.strategy = direct ─────────────────

    public function test_shopeefood_vn_deep_link_not_affected_by_admin_direct_setting(): void
    {
        Setting::set('affiliate.admin.strategy', 'direct');

        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/now-food/shop/757850',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('completed', $link->status);
        $this->assertSame($this->expectedShopeeFoodDeepLink(757850, 'test_user'), $link->affiliate_url);
    }

    // ─── 4c. Invariant: shopeefood.vn Deep Link unaffected by
    //          affiliate.dashboard.strategy = extension ──────────

    public function test_shopeefood_vn_deep_link_not_affected_by_dashboard_extension_setting(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'extension');

        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/now-food/shop/757850',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('completed', $link->status);
        $this->assertSame($this->expectedShopeeFoodDeepLink(757850, 'test_user'), $link->affiliate_url);
    }

    // ─── 5. Deep link carries the authenticated user's username ──

    public function test_deep_link_uses_user_username_as_utm_content(): void
    {
        $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/now-food/shop/757850',
            ]);

        $link = LinkRequest::latest()->first();
        $this->assertEquals('completed', $link->status);
        $this->assertStringContainsString('utm_content=test_user', $link->affiliate_url);

        $pendingCount = LinkRequest::where('status', 'pending')->count();
        $this->assertEquals(0, $pendingCount);
    }

    // ─── 6. Admin flow unchanged ─────────────────────────────────

    public function test_admin_flow_unchanged(): void
    {
        Role::create(['name' => 'Admin']);
        Permission::create(['name' => 'withdrawals.view']);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');

        $response = $this->actingAs($this->admin)
            ->postJson('/admin/affiliate-short-link', [
                'original_url' => 'https://shopee.vn/product/1/2',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ─── 7. Operator flow unchanged ──────────────────────────────

    public function test_operator_flow_unchanged(): void
    {
        Role::create(['name' => 'Operator']);

        $operator = User::factory()->create(['username' => 'operator']);
        $operator->assignRole('Operator');

        $response = $this->actingAs($operator)
            ->postJson('/admin/affiliate-short-link', [
                'original_url' => 'https://shopee.vn/product/3/4',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ─── 8. shopeefood.vn does NOT produce an_redir URL ──────────

    public function test_shopeefood_vn_does_not_produce_direct_an_redir_url(): void
    {
        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/now-food/shop/757850',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertNotNull($link->affiliate_url);
        $this->assertStringStartsWith('https://shopeefood.shopee.vn/now-food/shop/', $link->affiliate_url);
        $this->assertStringNotContainsString('an_redir', $link->affiliate_url);
    }

    // ─── 9. shopee.vn still calls DirectLinkStrategy ─────────────

    public function test_shopee_vn_still_calls_direct_link_strategy(): void
    {
        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopee.vn/product/10/20',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('processing', $link->status);
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link->affiliate_url);
    }

    // ─── 10. Deep link flow creates a single LinkRequest ─────────

    public function test_shopeefood_vn_creates_single_link_request(): void
    {
        $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/now-food/shop/757850',
            ]);

        $count = LinkRequest::where('original_url', 'https://shopeefood.vn/now-food/shop/757850')->count();
        $this->assertEquals(1, $count);
    }

    // ─── 11. Unresolvable ShopeeFood URL → failed, no fake URL ───

    public function test_invalid_shopeefood_url_marks_failed(): void
    {
        Http::fake([
            'shopeefood.vn/*' => Http::response('', 404),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/u/not-a-real-code',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('failed', $link->status);
        $this->assertNull($link->affiliate_url);
        $this->assertEquals('https://shopeefood.vn/u/not-a-real-code', $link->original_url);
    }

    // ─── CASE C — vnnow-food host routes to the deep link flow ───────

    public function test_vn_now_food_host_uses_deep_link_flow(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.shopee.vnnow-food/shop/1284425',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('ShopeeFood', $link->platform);
        $this->assertEquals('completed', $link->status);
        $this->assertSame($this->expectedShopeeFoodDeepLink(1284425, 'test_user'), $link->affiliate_url);
        $this->assertSame('https://shopeefood.shopee.vnnow-food/shop/1284425', $link->original_url);
        $this->assertStringNotContainsString('an_redir', $link->affiliate_url);
    }

    // ─── CASE D — lookalike host is NOT the ShopeeFood flow ──────────

    public function test_fake_shopeefood_vn_host_does_not_use_deep_link_flow(): void
    {
        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://fake-shopeefood.vn/now-food/shop/757850',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertNotEquals('ShopeeFood', $link->platform);
    }

    // ─── CASE G — real affiliate id + username, no foreign/fabricated ──

    public function test_deep_link_uses_real_affiliate_id_and_username_without_foreign_params(): void
    {
        Setting::set('affiliate.direct.shopee_affiliate_id', '17342330566');

        Http::fake([
            'spf.shopee.vn/4qFce98g0F*' => Http::response('', 301, [
                'Location' => 'https://shopeefood.vn/now-food/shop/757850'
                    . '?utm_source=an_17343840387'
                    . '&utm_medium=affiliate_food'
                    . '&utm_campaign=-'
                    . '&utm_content=tintuctonghop103----',
            ]),
            'shopeefood.vn/now-food/shop/757850*' => Http::response('', 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://spf.shopee.vn/4qFce98g0F',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('completed', $link->status);
        $this->assertStringStartsWith('https://shopeefood.shopee.vn/now-food/shop/757850', $link->affiliate_url);
        $this->assertStringContainsString('utm_source=an_17342330566', $link->affiliate_url);
        $this->assertStringContainsString('utm_content=test_user', $link->affiliate_url);
        $this->assertStringNotContainsString('17343840387', $link->affiliate_url);
        $this->assertStringNotContainsString('17310770298', $link->affiliate_url);
        $this->assertStringNotContainsString('utm_term', $link->affiliate_url);
        $this->assertStringNotContainsString('uls_trackid', $link->affiliate_url);
        $this->assertStringNotContainsString('an_redir', $link->affiliate_url);
        $this->assertStringNotContainsString('tintuctonghop103----', $link->affiliate_url);
    }
}