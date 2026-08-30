<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TikTokOrderSyncAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private User $operator;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Operator']);
        Role::create(['name' => 'Member']);

        $this->member = User::factory()->create(['username' => 'member']);
        $this->member->assignRole('Member');

        $this->operator = User::factory()->create(['username' => 'operator']);
        $this->operator->assignRole('Operator');

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.tiktok-order-sync.index'))
            ->assertRedirect(route('login'));

        $this->post(route('admin.tiktok-order-sync.sync'))
            ->assertRedirect(route('login'));
    }

    public function test_member_without_operator_or_admin_role_gets_403(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.tiktok-order-sync.index'))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->post(route('admin.tiktok-order-sync.sync'))
            ->assertForbidden();
    }

    public function test_operator_can_access_sync_index(): void
    {
        $this->actingAs($this->operator)
            ->get(route('admin.tiktok-order-sync.index'))
            ->assertOk()
            ->assertSee('Đồng bộ đơn hàng TikTok');
    }

    public function test_admin_can_access_sync_index(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.tiktok-order-sync.index'))
            ->assertOk()
            ->assertSee('Đồng bộ đơn hàng TikTok');
    }
}