<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\WithdrawRequest;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WithdrawRequestAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private User $viewer;
    private User $manager;
    private WithdrawRequest $pendingRequest;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create(['name' => 'withdrawals.view']);
        Permission::create(['name' => 'withdrawals.manage']);

        Role::create(['name' => 'Member']);
        Role::create(['name' => 'Admin']);

        $this->member = User::factory()->create(['username' => 'member']);
        $this->member->assignRole('Member');

        $this->viewer = User::factory()->create(['username' => 'viewer']);
        $this->viewer->givePermissionTo('withdrawals.view');

        $this->manager = User::factory()->create([
            'username' => 'manager',
            'wallet_balance' => 500000,
            'bank_name' => 'BIDV',
            'bank_account_number' => '1234567890',
            'bank_account_name' => 'MANAGER',
        ]);
        $this->manager->givePermissionTo('withdrawals.view');
        $this->manager->givePermissionTo('withdrawals.manage');

        $this->pendingRequest = WithdrawRequest::factory()->create([
            'user_id' => $this->manager->id,
            'username' => $this->manager->username,
            'amount' => 50000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'MANAGER',
            'status' => 'pending',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.withdraw-requests.index'))
            ->assertRedirect(route('login'));

        $this->post(route('admin.withdraw-requests.complete', $this->pendingRequest))
            ->assertRedirect(route('login'));

        $this->post(route('admin.withdraw-requests.reject', $this->pendingRequest), ['note' => 'test'])
            ->assertRedirect(route('login'));
    }

    public function test_member_without_permission_gets_403(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.withdraw-requests.index'))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->post(route('admin.withdraw-requests.complete', $this->pendingRequest))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->post(route('admin.withdraw-requests.reject', $this->pendingRequest), ['note' => 'test'])
            ->assertForbidden();
    }

    public function test_viewer_can_view_but_not_manage(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('admin.withdraw-requests.index'))
            ->assertOk();

        $this->actingAs($this->viewer)
            ->post(route('admin.withdraw-requests.complete', $this->pendingRequest))
            ->assertForbidden();

        $this->actingAs($this->viewer)
            ->post(route('admin.withdraw-requests.reject', $this->pendingRequest), ['note' => 'test'])
            ->assertForbidden();
    }

    public function test_manager_can_view_and_manage(): void
    {
        $this->actingAs($this->manager)
            ->get(route('admin.withdraw-requests.index'))
            ->assertOk();

        $this->actingAs($this->manager)
            ->post(route('admin.withdraw-requests.complete', $this->pendingRequest))
            ->assertRedirect();

        $fresh = $this->pendingRequest->fresh();
        $this->assertSame('paid', $fresh->status);

        $request2 = WithdrawRequest::factory()->create([
            'user_id' => $this->manager->id,
            'username' => $this->manager->username,
            'amount' => 30000,
            'bank_name' => 'BIDV',
            'bank_account' => '1234567890',
            'account_name' => 'MANAGER',
            'status' => 'pending',
        ]);

        $this->actingAs($this->manager)
            ->post(route('admin.withdraw-requests.reject', $request2), ['note' => 'Sai thông tin'])
            ->assertRedirect();

        $fresh2 = $request2->fresh();
        $this->assertSame('rejected', $fresh2->status);
        $this->assertSame('Sai thông tin', $fresh2->note);
    }
}
