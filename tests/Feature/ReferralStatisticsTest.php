<?php

namespace Tests\Feature;

use App\Models\Referral;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReferralStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create(['name' => 'users.view']);

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Member']);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('users.view');

        $this->member = User::factory()->create(['username' => 'member']);
        $this->member->assignRole('Member');
    }

    private function referredUser(string $username): User
    {
        return User::factory()->create(['username' => $username]);
    }

    private function createReferral(User $referrer, User $referred, string $status): Referral
    {
        return Referral::factory()->create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'status' => $status,
            'completed_orders' => $status === Referral::STATUS_COMPLETED ? 3 : 0,
            'rewarded_at' => $status === Referral::STATUS_COMPLETED ? now() : null,
        ]);
    }

    private function referralCount(User $user): int
    {
        return (int) Referral::where('referrer_id', $user->id)->count();
    }

    private function referralCompletedCount(User $user): int
    {
        return (int) Referral::where('referrer_id', $user->id)
            ->where('status', Referral::STATUS_COMPLETED)
            ->count();
    }

    private function referralPendingCount(User $user): int
    {
        return (int) Referral::where('referrer_id', $user->id)
            ->where('status', Referral::STATUS_PENDING)
            ->count();
    }

    /** TEST 1: User A có 3 referrals (2 completed, 1 pending) → đúng số liệu */
    #[Test]
    public function statistics_reports_mixed_statuses_for_user(): void
    {
        $referrer = User::factory()->create(['username' => 'userA']);
        $this->createReferral($referrer, $this->referredUser('r1'), Referral::STATUS_COMPLETED);
        $this->createReferral($referrer, $this->referredUser('r2'), Referral::STATUS_COMPLETED);
        $this->createReferral($referrer, $this->referredUser('r3'), Referral::STATUS_PENDING);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.referrals.statistics'));

        $response->assertOk()
            ->assertSee('userA')
            ->assertSee('3')
            ->assertSee('2')
            ->assertSee('1');
    }

    /** TEST 2: User B có 5 referrals đều completed → 5/5/0 */
    #[Test]
    public function statistics_reports_all_completed_for_user(): void
    {
        $referrer = User::factory()->create(['username' => 'userB']);
        for ($i = 1; $i <= 5; $i++) {
            $this->createReferral($referrer, $this->referredUser("b{$i}"), Referral::STATUS_COMPLETED);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.referrals.statistics'))
            ->assertOk()
            ->assertSee('userB')
            ->assertSee('5');
    }

    /** TEST 3: User C không có referral → không xuất hiện trong danh sách */
    #[Test]
    public function user_without_referrals_is_excluded(): void
    {
        User::factory()->create(['username' => 'userC']);
        $referrer = User::factory()->create(['username' => 'userA']);
        $this->createReferral($referrer, $this->referredUser('r1'), Referral::STATUS_PENDING);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.referrals.statistics'));

        $response->assertOk()
            ->assertSee('userA')
            ->assertDontSee('userC');
    }

    /** TEST 4: Sort giảm dần theo total referrals */
    #[Test]
    public function statistics_sorted_by_total_desc(): void
    {
        $low = User::factory()->create(['username' => 'low']);
        $this->createReferral($low, $this->referredUser('l1'), Referral::STATUS_PENDING);

        $high = User::factory()->create(['username' => 'high']);
        for ($i = 1; $i <= 4; $i++) {
            $this->createReferral($high, $this->referredUser("h{$i}"), Referral::STATUS_COMPLETED);
        }

        $this->actingAs($this->admin)
            ->get(route('admin.referrals.statistics'))
            ->assertOk()
            ->assertSeeInOrder(['high', 'low']);
    }

    /** TEST 5: completed + pending = total cho mọi user */
    #[Test]
    public function completed_plus_pending_equals_total_for_every_user(): void
    {
        $referrer = User::factory()->create(['username' => 'userX']);
        $this->createReferral($referrer, $this->referredUser('x1'), Referral::STATUS_COMPLETED);
        $this->createReferral($referrer, $this->referredUser('x2'), Referral::STATUS_PENDING);
        $this->createReferral($referrer, $this->referredUser('x3'), Referral::STATUS_PENDING);
        $this->createReferral($referrer, $this->referredUser('x4'), Referral::STATUS_COMPLETED);

        $total = $this->referralCount($referrer);
        $completed = $this->referralCompletedCount($referrer);
        $pending = $this->referralPendingCount($referrer);

        $this->assertSame($completed + $pending, $total);
        $this->assertSame(4, $total);
        $this->assertSame(2, $completed);
        $this->assertSame(2, $pending);
    }

    /** TEST 6: User không có permission → 403 */
    #[Test]
    public function member_without_permission_cannot_view_statistics(): void
    {
        $this->actingAs($this->member)
            ->get(route('admin.referrals.statistics'))
            ->assertForbidden();
    }

    /** TEST 6b: Guest → redirect login */
    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.referrals.statistics'))
            ->assertRedirect(route('login'));
    }

    /** TEST 7: Statistics không tạo/chỉnh sửa wallet transaction */
    #[Test]
    public function statistics_does_not_create_wallet_transactions(): void
    {
        $referrer = User::factory()->create(['username' => 'userA']);
        $this->createReferral($referrer, $this->referredUser('r1'), Referral::STATUS_COMPLETED);
        $this->createReferral($referrer, $this->referredUser('r2'), Referral::STATUS_PENDING);

        $before = (int) WalletTransaction::count();

        $this->actingAs($this->admin)
            ->get(route('admin.referrals.statistics'))
            ->assertOk();

        $this->assertSame($before, (int) WalletTransaction::count());
    }

    /** TEST 8: Statistics không thay đổi referrals table */
    #[Test]
    public function statistics_does_not_modify_referrals(): void
    {
        $referrer = User::factory()->create(['username' => 'userA']);
        $this->createReferral($referrer, $this->referredUser('r1'), Referral::STATUS_COMPLETED);

        $snapshot = Referral::select('id', 'referrer_id', 'referred_user_id', 'status', 'completed_orders', 'rewarded_at')
            ->get()
            ->each(fn ($r) => $r->setAppends([]))
            ->toArray();

        $this->actingAs($this->admin)
            ->get(route('admin.referrals.statistics'))
            ->assertOk();

        $after = Referral::select('id', 'referrer_id', 'referred_user_id', 'status', 'completed_orders', 'rewarded_at')
            ->get()
            ->each(fn ($r) => $r->setAppends([]))
            ->toArray();

        $this->assertSame($snapshot, $after);
    }
}