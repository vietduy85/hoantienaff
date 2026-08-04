<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShortLinkOperatorAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private User $operator;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create(['name' => 'withdrawals.view']);

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Operator']);
        Role::create(['name' => 'Member']);

        $this->member = User::factory()->create(['username' => 'member']);
        $this->member->assignRole('Member');

        $this->operator = User::factory()->create(['username' => 'operator']);
        $this->operator->assignRole('Operator');

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('withdrawals.view');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.affiliate-short-link.index'))
            ->assertRedirect(route('login'));

        $this->get(route('admin.affiliate-config.index'))
            ->assertRedirect(route('login'));
    }

    public function test_member_without_operator_or_admin_role_gets_403(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.affiliate-short-link.index'))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->post(route('admin.affiliate-short-link.store'), ['original_url' => 'https://shopee.vn/product/1'])
            ->assertForbidden();

        $this->actingAs($this->member)
            ->get(route('admin.affiliate-config.index'))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->put(route('admin.affiliate-config.update'), [])
            ->assertForbidden();
    }

    public function test_operator_can_access_shortlink_routes(): void
    {
        $this->actingAs($this->operator)
            ->get(route('admin.affiliate-short-link.index'))
            ->assertOk();

        $this->actingAs($this->operator)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk();
    }

    public function test_admin_can_access_shortlink_routes(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-short-link.index'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-config.index'))
            ->assertOk();
    }

    public function test_operator_sees_shortlink_menu_but_not_withdraw_menu(): void
    {
        $this->actingAs($this->operator)
            ->get(route('admin.affiliate-short-link.index'))
            ->assertOk()
            ->assertSee('Tạo Short Link Affiliate')
            ->assertDontSee('Quản lý rút tiền');
    }

    public function test_admin_sees_both_menus(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.affiliate-short-link.index'))
            ->assertOk()
            ->assertSee('Tạo Short Link Affiliate')
            ->assertSee('Quản lý rút tiền');
    }
}
