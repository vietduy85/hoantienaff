<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\AffiliateCacheService;
use App\Services\ProductDataService;
use App\Services\UrlResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // ─── 2. shopeefood.shopee.vn → Direct Link ──────────────────

    public function test_shopeefood_shopee_vn_uses_direct_link_flow(): void
    {
        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.shopee.vn/merchant/123',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('Shopee', $link->platform);
        $this->assertEquals('processing', $link->status);
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link->affiliate_url);
        $this->assertStringContainsString('sub_id=test_user', $link->affiliate_url);
    }

    // ─── 3. shopeefood.vn → Extension Worker (pending) ───────────

    public function test_shopeefood_vn_uses_extension_worker_flow(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/delivery/abc-123',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $link = LinkRequest::latest()->first();
        $this->assertEquals('Shopee', $link->platform);
        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ─── 4. www.shopeefood.vn → Extension Worker (pending) ───────

    public function test_www_shopeefood_vn_uses_extension_worker_flow(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://www.shopeefood.vn/delivery/xyz-789',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ─── 4b. Invariant: shopeefood.vn still Extension Worker even when
    //          affiliate.admin.strategy = direct ──────────────────

    public function test_shopeefood_vn_extension_not_affected_by_admin_direct_setting(): void
    {
        Setting::set('affiliate.admin.strategy', 'direct');

        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/delivery/abc-123',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ─── 4c. Invariant: shopeefood.vn still Extension Worker even when
    //          affiliate.dashboard.strategy = direct ──────────────

    public function test_shopeefood_vn_extension_not_affected_by_dashboard_direct_setting(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');

        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/delivery/abc-123',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertEquals('pending', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    // ─── 5. Worker receives the authenticated user's username ────

    public function test_extension_worker_picks_up_with_user_username(): void
    {
        $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/delivery/abc-123',
            ]);

        $response = $this->getJson('/api/extension/jobs?token=' . $this->extensionToken);
        $response->assertOk();

        $jobs = $response->json('jobs');
        $this->assertCount(1, $jobs);
        $this->assertEquals('test_user', $jobs[0]['username']);
        $this->assertStringContainsString('shopeefood.vn', $jobs[0]['original_url']);

        $link = LinkRequest::latest()->first();
        $this->assertEquals('processing', $link->status);
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

    // ─── 8. shopeefood.vn does NOT call DirectLinkStrategy ───────

    public function test_shopeefood_vn_does_not_produce_direct_an_redir_url(): void
    {
        $this->mockDirectLinkDependencies();

        $response = $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/delivery/abc-123',
            ]);

        $response->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertNull($link->affiliate_url);
        $this->assertEquals('pending', $link->status);
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

    // ─── 10. Extension flow does not duplicate LinkRequest ───────

    public function test_shopeefood_vn_creates_single_link_request(): void
    {
        $this->actingAs($this->user)
            ->postJson('/link-requests', [
                'original_url' => 'https://shopeefood.vn/delivery/abc-123',
            ]);

        $count = LinkRequest::where('original_url', 'https://shopeefood.vn/delivery/abc-123')->count();
        $this->assertEquals(1, $count);
    }
}
