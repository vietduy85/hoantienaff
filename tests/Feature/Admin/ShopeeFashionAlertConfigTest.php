<?php

namespace Tests\Feature\Admin;

use App\Models\AffiliateOrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserShopeeFashionSeenOrder;
use App\Services\ShopeeFashionAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Module Admin "Cập nhật link cào xu Shopee":
 * - URL đích (href) và URL hiển thị lưu độc lập trong bảng `settings`.
 * - Chỉ Admin được cấu hình; người khác bị từ chối.
 * - Chỉ chấp nhận URL HTTPS hợp lệ.
 * - Dashboard render href từ cấu hình, hiển thị URL hiển thị đã cấu hình.
 */
class ShopeeFashionAlertConfigTest extends TestCase
{
    use RefreshDatabase;

    private const OCT_DATE = '2026-10-15 10:00:00';

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Member']);

        $this->admin = User::factory()->create(['username' => 'admin-linkcfg']);
        $this->admin->assignRole('Admin');

        $this->member = User::factory()->create(['username' => 'member-linkcfg']);
        $this->member->assignRole('Member');
    }

    private function makeItem(User $user, array $overrides = []): AffiliateOrderItem
    {
        return AffiliateOrderItem::factory()->create(array_merge([
            'user_id' => $user->id,
            'platform' => 'Shopee',
            'shopee_commission_rate' => ShopeeFashionAlertService::FASHION_COMMISSION_RATE,
            'ordered_at' => self::OCT_DATE,
            'order_id' => 'ORD'.fake()->unique()->numerify('#######'),
            'item_id' => (string) fake()->unique()->numerify('##########'),
        ], $overrides));
    }

    // ─── Phân quyền ──────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.shopee-fashion-alert-config.index'))->assertRedirect(route('login'));
        $this->put(route('admin.shopee-fashion-alert-config.update'), [
            'destination_url' => 'https://shp.ee/abc',
            'display_url' => 'https://s.shopee.vn/abc',
        ])->assertRedirect(route('login'));
    }

    public function test_member_without_admin_role_gets_403(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.shopee-fashion-alert-config.index'))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->put(route('admin.shopee-fashion-alert-config.update'), [
                'destination_url' => 'https://shp.ee/abc',
                'display_url' => 'https://s.shopee.vn/abc',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_open_config_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.shopee-fashion-alert-config.index'))
            ->assertOk()
            ->assertSee('Cập nhật link cào xu Shopee')
            ->assertSee('destination_url')
            ->assertSee(Setting::SHOPEE_FASHION_ALERT_DESTINATION_URL_DEFAULT);
    }

    // ─── Lưu cấu hình ───────────────────────────────────────

    public function test_admin_can_save_valid_https_urls_persistently(): void
    {
        $response = $this->actingAs($this->admin)->put(
            route('admin.shopee-fashion-alert-config.update'),
            [
                'destination_url' => 'https://shp.ee/newcode?utm_source=an_123&utm_medium=aff',
                'display_url' => 'https://s.shopee.vn/NEWCODE',
            ]
        );

        $response->assertRedirect(route('admin.shopee-fashion-alert-config.index'));
        $response->assertSessionHas('success');

        // Lưu bền vững trong DB + giữ nguyên path/query string (không chuẩn hoá).
        $this->assertSame(
            'https://shp.ee/newcode?utm_source=an_123&utm_medium=aff',
            Setting::get(Setting::SHOPEE_FASHION_ALERT_DESTINATION_URL)
        );
        $this->assertSame(
            'https://s.shopee.vn/NEWCODE',
            Setting::get(Setting::SHOPEE_FASHION_ALERT_DISPLAY_URL)
        );
    }

    public function test_invalid_or_non_https_urls_are_rejected(): void
    {
        $invalid = [
            'http://shp.ee/622xrhjpx85',
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'file:///etc/passwd',
            'not-a-url',
        ];

        foreach ($invalid as $destination) {
            $this->actingAs($this->admin)
                ->from(route('admin.shopee-fashion-alert-config.index'))
                ->put(route('admin.shopee-fashion-alert-config.update'), [
                    'destination_url' => $destination,
                    'display_url' => 'https://s.shopee.vn/1BFBNN81J',
                ])
                ->assertRedirect(route('admin.shopee-fashion-alert-config.index'))
                ->assertSessionHasErrors('destination_url');

            $this->assertNull(Setting::get(Setting::SHOPEE_FASHION_ALERT_DESTINATION_URL));
        }

        // display_url cũng phải là HTTPS.
        $this->actingAs($this->admin)
            ->put(route('admin.shopee-fashion-alert-config.update'), [
                'destination_url' => 'https://shp.ee/622xrhjpx85',
                'display_url' => 'http://s.shopee.vn/1BFBNN81J',
            ])
            ->assertSessionHasErrors('display_url');
    }

    // ─── Dashboard dùng cấu hình ────────────────────────────

    public function test_dashboard_uses_default_urls_when_not_configured(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('href="https://shp.ee/622xrhjpx85"', false);
        $response->assertSee('>https://s.shopee.vn/1BFBNN81J</a>', false);
        $response->assertSee('target="_blank"', false);
        $response->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_dashboard_uses_saved_destination_url_without_changing_display_text(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user);

        // Admin chỉ đổi URL đích; URL hiển thị giữ nguyên.
        $this->actingAs($this->admin)->put(
            route('admin.shopee-fashion-alert-config.update'),
            [
                'destination_url' => 'https://shp.ee/NEWDEST',
                'display_url' => 'https://s.shopee.vn/1BFBNN81J',
            ]
        )->assertSessionHasNoErrors();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        // href đã lưu, URL hiển thị vẫn là link cũ, target/rel giữ nguyên.
        $response->assertSee('href="https://shp.ee/NEWDEST"', false);
        $response->assertSee('>https://s.shopee.vn/1BFBNN81J</a>', false);
        $response->assertSee('target="_blank"', false);
        $response->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_dashboard_uses_saved_display_url_when_configured(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user);

        $this->actingAs($this->admin)->put(
            route('admin.shopee-fashion-alert-config.update'),
            [
                'destination_url' => 'https://shp.ee/622xrhjpx85',
                'display_url' => 'https://s.shopee.vn/NEWCODE',
            ]
        )->assertSessionHasNoErrors();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('>https://s.shopee.vn/NEWCODE</a>', false);
    }

    // ─── Không ảnh hưởng chức năng thông báo ────────────────

    public function test_acknowledge_and_disable_still_work_after_config_change(): void
    {
        $user = User::factory()->create();
        $this->makeItem($user, ['order_id' => 'ORD1']);

        $this->actingAs($this->admin)->put(
            route('admin.shopee-fashion-alert-config.update'),
            [
                'destination_url' => 'https://shp.ee/NEWDEST',
                'display_url' => 'https://s.shopee.vn/1BFBNN81J',
            ]
        )->assertSessionHasNoErrors();

        // Acknowledge vẫn hoạt động bình thường.
        $this->actingAs($user)
            ->postJson(route('shopee-fashion-alert.acknowledge'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, UserShopeeFashionSeenOrder::where('user_id', $user->id)->count());

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('cần cào xu');

        // Disable vẫn hoạt động bình thường.
        $this->actingAs($user)
            ->postJson(route('shopee-fashion-alert.disable'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue((bool) $user->fresh()->shopee_fashion_alert_disabled);
    }
}
