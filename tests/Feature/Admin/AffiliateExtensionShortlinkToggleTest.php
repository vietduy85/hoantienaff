<?php

namespace Tests\Feature\Admin;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AffiliateExtensionShortlinkToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');

        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    private function enableExtension(): void
    {
        Setting::set(Setting::EXTENSION_SHORTLINK_ENABLED, 'true');
    }

    private function disableExtension(): void
    {
        Setting::set(Setting::EXTENSION_SHORTLINK_ENABLED, 'false');
    }

    // ─── Config page / persistence ────────────────────────────

    public function test_config_page_defaults_to_extension_disabled(): void
    {
        $this->assertFalse(Setting::extensionShortlinkEnabled());

        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk()
            ->assertSee('Extension Worker')
            ->assertSee('Đang TẮT');
    }

    public function test_admin_can_enable_extension(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.affiliate-config.update'), [
            'extension_enabled' => 'true',
            'dashboard_strategy' => 'extension',
            'admin_strategy' => 'extension',
            'affiliate_id' => '12345',
            'resolve' => 'false',
        ]);

        $response->assertRedirect(route('admin.affiliate-config.index'));

        $this->assertTrue(Setting::extensionShortlinkEnabled());
        $this->assertSame('extension', Setting::get('affiliate.dashboard.strategy'));
        $this->assertSame('extension', Setting::get('affiliate.admin.strategy'));

        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk()
            ->assertSee('Đang BẬT');
    }

    public function test_admin_disabling_extension_forces_direct_strategies(): void
    {
        $this->enableExtension();
        Setting::set('affiliate.dashboard.strategy', 'extension');
        Setting::set('affiliate.admin.strategy', 'extension');

        $this->actingAs($this->admin)->put(route('admin.affiliate-config.update'), [
            'extension_enabled' => 'false',
            'dashboard_strategy' => 'extension',
            'admin_strategy' => 'extension',
            'affiliate_id' => '12345',
            'resolve' => 'false',
        ])->assertRedirect(route('admin.affiliate-config.index'));

        $this->assertFalse(Setting::extensionShortlinkEnabled());
        $this->assertSame('direct', Setting::get('affiliate.dashboard.strategy'));
        $this->assertSame('direct', Setting::get('affiliate.admin.strategy'));
    }

    public function test_invalid_toggle_value_is_rejected(): void
    {
        $this->actingAs($this->admin)->put(route('admin.affiliate-config.update'), [
            'extension_enabled' => 'maybe',
            'dashboard_strategy' => 'direct',
            'admin_strategy' => 'direct',
            'resolve' => 'false',
        ])->assertSessionHasErrors('extension_enabled');
    }

    // ─── OFF: no extension flow, Direct Link fallback ─────────

    public function test_dashboard_flow_falls_back_to_direct_when_disabled(): void
    {
        $this->disableExtension();
        Setting::set('affiliate.dashboard.strategy', 'extension');

        $this->actingAs($this->admin)
            ->postJson('/link-requests', ['original_url' => 'https://shopee.vn/product/123/456'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $link = LinkRequest::latest()->first();
        $this->assertSame('completed', $link->status);
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link->affiliate_url);
        $this->assertSame(0, LinkRequest::where('status', 'pending')->count());
    }

    public function test_admin_short_link_falls_back_to_direct_when_disabled(): void
    {
        $this->disableExtension();
        Setting::set('affiliate.admin.strategy', 'extension');

        $this->actingAs($this->admin)
            ->postJson(route('admin.affiliate-short-link.store'), [
                'original_url' => 'https://shopee.vn/product/1/2',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $link = LinkRequest::latest()->first();
        $this->assertSame('completed', $link->status);
        $this->assertStringStartsWith('https://s.shopee.vn/an_redir?', $link->affiliate_url);
        $this->assertSame(0, LinkRequest::where('status', 'pending')->count());
    }

    // ─── ON: extension flow works again ───────────────────────

    public function test_dashboard_extension_flow_pending_when_enabled(): void
    {
        $this->enableExtension();
        Setting::set('affiliate.dashboard.strategy', 'extension');

        $this->actingAs($this->admin)
            ->postJson('/link-requests', ['original_url' => 'https://shopee.vn/product/123/456'])
            ->assertOk();

        $link = LinkRequest::latest()->first();
        $this->assertSame('pending', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    public function test_extension_endpoint_serves_and_marks_processing_when_enabled(): void
    {
        $this->enableExtension();
        config(['services.affiliate_extension.token' => 'test-token']);

        $link = LinkRequest::create([
            'user_id' => $this->admin->id,
            'original_url' => 'https://shopee.vn/product/9/9',
            'platform' => 'Shopee',
            'status' => 'pending',
        ]);

        $this->getJson('/api/extension/jobs?token=test-token')
            ->assertOk()
            ->assertJsonPath('jobs.0.id', $link->id);

        $this->assertSame('processing', $link->fresh()->status);
    }
}
