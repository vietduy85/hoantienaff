<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Behavioural guarantees from the resolver refactor:
 *  - unsupported / spoof platforms are never reported as `completed`;
 *  - no external API call for spoof hosts;
 *  - Admin short-link never leaves a non-Shopee link stuck in `processing`.
 */
class PlatformResolverIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->user = User::factory()->create(['username' => 'resolver_user']);

        Setting::set('affiliate.dashboard.strategy', 'direct');
        Setting::set('affiliate.direct.shopee_affiliate_id', '12345');
        Setting::set('affiliate.direct.resolve_shortlink', 'false');
    }

    public function test_direct_link_unsupported_platform_is_failed_not_completed(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://tiki.vn/ban-voi-tikivn-p123456',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'platform' => 'Tiki']);

        $link = LinkRequest::latest()->first();
        $this->assertSame('failed', $link->status);
        $this->assertNull($link->affiliate_url);

        Http::assertNothingSent();
    }

    public function test_direct_link_spoof_host_is_failed_and_never_calls_tiktok(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://evil.com/tiktok',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'platform' => 'Khác']);

        $link = LinkRequest::latest()->first();
        $this->assertSame('failed', $link->status);
        $this->assertNull($link->affiliate_url);

        Http::assertNothingSent();
    }

    public function test_direct_link_spoof_shopee_host_is_not_labelled_shopee(): void
    {
        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://shopee.vn.example.com/product/1/2',
        ]);

        $response->assertStatus(422);

        $link = LinkRequest::latest()->first();
        $this->assertNotSame('Shopee', $link->platform);
        $this->assertSame('failed', $link->status);
        $this->assertNull($link->affiliate_url);
    }

    public function test_extension_strategy_marks_non_shopee_failed(): void
    {
        Setting::set(Setting::EXTENSION_SHORTLINK_ENABLED, 'true');
        Setting::set('affiliate.dashboard.strategy', 'extension');

        Http::fake();

        $response = $this->actingAs($this->user)->postJson('/link-requests', [
            'original_url' => 'https://tiktok.com/item/12345',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'platform' => 'TikTok Shop']);

        $link = LinkRequest::latest()->first();
        $this->assertSame('failed', $link->status);
        $this->assertNull($link->affiliate_url);

        Http::assertNothingSent();
    }

    public function test_admin_short_link_non_shopee_does_not_get_stuck_processing(): void
    {
        Role::create(['name' => 'Admin']);
        $admin = User::factory()->create(['username' => 'resolver_admin']);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin)->postJson(route('admin.affiliate-short-link.store'), [
            'original_url' => 'https://www.lazada.vn/products/x-i1-s1.html',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'platform' => 'Lazada']);

        $link = LinkRequest::latest()->first();
        $this->assertSame('failed', $link->status);
        $this->assertNull($link->affiliate_url);
    }
}
