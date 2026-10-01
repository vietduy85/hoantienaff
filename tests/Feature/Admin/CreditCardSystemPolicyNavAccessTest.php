<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Lối vào UI "🎯 Quản lý chính sách hoàn tiền" trong dropdown tài khoản (góc phải trên).
 *
 * Bảo vệ cốt lõi:
 *   1. Menu chỉ render khi user có permission `credit-cards.view` (cơ chế @can hiện tại).
 *   2. Dù user không thấy menu, URL `/admin/credit-card/policies` vẫn bị chặn 403 bởi
 *      permission middleware của route — UI chỉ là ngụy trang, backend là hàng rào thật.
 */
class CreditCardSystemPolicyNavAccessTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $viewer;

    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'credit-cards.view']);
        Permission::create(['name' => 'credit-cards.manage']);

        $admin = Role::create(['name' => 'Admin']);
        $admin->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);

        $this->viewer = User::factory()->create()->givePermissionTo('credit-cards.view');
        $this->member = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function user_with_credit_cards_view_permission_sees_policy_menu_in_account_dropdown(): void
    {
        $html = $this->actingAs($this->viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('🎯 Quản lý chính sách hoàn tiền', $html);
        $this->assertStringContainsString('href="'.route('admin.credit-card-policies.index').'"', $html);
    }

    #[Test]
    public function user_without_permission_does_not_see_policy_menu(): void
    {
        $html = $this->actingAs($this->member)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Quản lý chính sách hoàn tiền', $html);
        $this->assertStringNotContainsString('href="'.route('admin.credit-card-policies.index').'"', $html);
    }

    #[Test]
    public function policy_menu_points_to_admin_policy_area(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('admin.credit-card-policies.index'), false);

        // Đường dẫn đúng khu vực quản trị, không phải trang cấu hình user.
        $this->assertMatchesRegularExpression(
            '#/admin/credit-card/policies#',
            route('admin.credit-card-policies.index')
        );
    }

    #[Test]
    public function user_without_permission_is_still_blocked_on_direct_url(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.credit-card-policies.index'))
            ->assertForbidden();
    }

    #[Test]
    public function admin_on_policy_index_keeps_menu_and_mobile_active_state(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.credit-card-policies.index'))
            ->assertOk()
            ->getContent();

        // Dropdown tài khoản vẫn hiển thị menu khi đang trong khu vực chính sách.
        $this->assertStringContainsString('🎯 Quản lý chính sách hoàn tiền', $html);

        // Responsive nav (mobile) có HỖ TRỢ active state → link policies được highlight.
        $this->assertMatchesRegularExpression(
            '/<a(?=[^>]*href="'.preg_quote(route('admin.credit-card-policies.index'), '/').'")(?=[^>]*border-indigo-400)[^>]*>/',
            $html
        );
    }

    #[Test]
    public function sidebar_credit_card_menu_is_not_touched(): void
    {
        // Menu admin chỉ nằm trong dropdown tài khoản — không xuất hiện trong sidebar
        // thẻ tín dụng (sidebar giữ nguyên nội dung cũ: không có "Quản lý chính sách").
        $html = $this->actingAs($this->viewer)
            ->get(route('credit-cards.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('💳 Thẻ tín dụng', $html);
    }
}
