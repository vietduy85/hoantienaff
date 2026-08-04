<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private User $viewer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create(['name' => 'users.view']);

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Member']);

        $this->member = User::factory()->create(['username' => 'member']);
        $this->member->assignRole('Member');

        $this->viewer = User::factory()->create(['username' => 'viewer']);
        $this->viewer->givePermissionTo('users.view');

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('users.view');

        $this->viewedUser = User::factory()->create(['username' => 'target']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.users.index'))
            ->assertRedirect(route('login'));

        $this->get(route('admin.users.show', $this->viewedUser))
            ->assertRedirect(route('login'));
    }

    public function test_member_without_permission_gets_403(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->get(route('admin.users.show', $this->viewedUser))
            ->assertForbidden();
    }

    public function test_viewer_with_permission_can_view(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('admin.users.index'))
            ->assertOk();

        $this->actingAs($this->viewer)
            ->get(route('admin.users.show', $this->viewedUser))
            ->assertOk();
    }

    public function test_admin_can_view(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $this->viewedUser))
            ->assertOk();
    }

    public function test_index_search_and_role_filter(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('admin.users.index', ['search' => 'viewer']))
            ->assertOk()
            ->assertSee('viewer');

        $this->actingAs($this->viewer)
            ->get(route('admin.users.index', ['role' => 'Member']))
            ->assertOk()
            ->assertSee('member');
    }
}
