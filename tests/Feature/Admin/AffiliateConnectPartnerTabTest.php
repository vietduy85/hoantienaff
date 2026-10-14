<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AffiliateConnectPartnerTabTest extends TestCase
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

    public function test_config_page_has_both_tabs(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk()
            ->assertSee('Connect Partner AFF')
            ->assertSee('Extension');
    }

    public function test_partner_tab_lists_all_providers(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk();

        $response->assertSee('Shopee');
        $response->assertSee('TikTok Shop');
        $response->assertSee('Lazada');
        $response->assertSee('ShopeeFood');
        $response->assertSee('AccessTrade');
    }

    public function test_accesstrade_shows_not_connected_when_unconfigured(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk()
            ->assertSee('Chưa kết nối')
            ->assertSee('Chưa cấu hình');
    }

    public function test_unimplemented_methods_are_labelled_not_implemented(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk()
            ->assertSee('Chưa triển khai');
    }

    public function test_extension_tab_keeps_current_behaviour(): void
    {
        Setting::set(Setting::EXTENSION_SHORTLINK_ENABLED, 'true');

        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk()
            ->assertSee('Extension Worker')
            ->assertSee('Đang BẬT');
    }

    public function test_rendering_config_page_makes_no_http_call(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_viewing_partner_tab_does_not_change_config_keys(): void
    {
        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.admin.strategy', 'extension');
        Setting::set('affiliate.direct.shopee_affiliate_id', '99999');
        Setting::set('affiliate.direct.resolve_shortlink', 'true');
        Setting::set(Setting::EXTENSION_SHORTLINK_ENABLED, 'false');

        $before = [
            'affiliate.dashboard.strategy' => Setting::get('affiliate.dashboard.strategy'),
            'affiliate.admin.strategy' => Setting::get('affiliate.admin.strategy'),
            'affiliate.direct.shopee_affiliate_id' => Setting::get('affiliate.direct.shopee_affiliate_id'),
            'affiliate.direct.resolve_shortlink' => Setting::get('affiliate.direct.resolve_shortlink'),
            Setting::EXTENSION_SHORTLINK_ENABLED => Setting::get(Setting::EXTENSION_SHORTLINK_ENABLED),
        ];

        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk();

        foreach ($before as $key => $value) {
            $this->assertSame($value, Setting::get($key), "Setting {$key} must not change when viewing the page");
        }
    }

    public function test_member_without_admin_role_is_forbidden(): void
    {
        Role::create(['name' => 'Member']);
        $member = User::factory()->create(['username' => 'member']);
        $member->assignRole('Member');

        $this->actingAs($member)
            ->get(route('admin.affiliate-config.index'))
            ->assertForbidden();
    }
}
