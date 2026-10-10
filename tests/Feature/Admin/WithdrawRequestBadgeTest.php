<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Badge số yêu cầu rút tiền đang chờ, hiển thị cạnh "Trang chủ" trong
 * navigation dùng chung (layouts.navigation).
 *
 * Bất biến được khoá ở đây:
 *   1. Chỉ đếm đúng trạng thái `pending` (không đếm paid/rejected/cancelled).
 *   2. Chỉ người có quyền `withdrawals.view` mới thấy badge.
 *   3. Số đếm luôn lấy từ trạng thái DB MỚI NHẤT ở mỗi lần render navigation
 *      (không lưu session), nên giảm/tăng đúng sau khi xử lý/tạo yêu cầu.
 *   4. Hiển thị giới hạn `99+` nhưng giá trị đếm thực tế vẫn chính xác.
 */
class WithdrawRequestBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create(['name' => 'withdrawals.view']);
        Permission::create(['name' => 'withdrawals.manage']);
        Permission::create(['name' => 'users.view']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function adminWithView(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('withdrawals.view');

        return $user;
    }

    private function makeOwner(): User
    {
        return User::factory()->create([
            'username' => 'owner-'.random_int(100000, 999999),
        ]);
    }

    private function makePending(int $count): void
    {
        $owner = $this->makeOwner();

        for ($i = 0; $i < $count; $i++) {
            WithdrawRequest::factory()->create([
                'user_id' => $owner->id,
                'username' => $owner->username,
                'status' => WithdrawRequest::STATUS_PENDING,
            ]);
        }
    }

    /**
     * Tạo một yêu cầu pending "xử lý được" kèm WalletTransaction pending
     * tương ứng và số dư đủ, để endpoint complete/reject hoạt động như thật.
     */
    private function makeProcessable(float $amount = 50000): WithdrawRequest
    {
        $owner = User::factory()->create([
            'username' => 'owner-'.random_int(100000, 999999),
            'wallet_balance' => $amount + 1000,
        ]);

        $withdrawRequest = WithdrawRequest::factory()->create([
            'user_id' => $owner->id,
            'username' => $owner->username,
            'amount' => $amount,
            'status' => WithdrawRequest::STATUS_PENDING,
        ]);

        WalletTransaction::factory()->create([
            'user_id' => $owner->id,
            'username' => $owner->username,
            'type' => 'withdraw',
            'direction' => 'debit',
            'amount' => $amount,
            'balance_before' => $amount + 1000,
            'balance_after' => $amount + 1000,
            'reference_type' => 'withdraw_request',
            'reference_id' => $withdrawRequest->id,
            'status' => 'pending',
        ]);

        return $withdrawRequest;
    }

    private function badgeNumber(string $html): ?string
    {
        if (! preg_match('/data-testid="pending-withdraw-badge"[^>]*>\s*([^<]+?)\s*</', $html, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    private function dashboardBadge(User $user): ?string
    {
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        return $this->badgeNumber($html);
    }

    // ---------------------------------------------------------------------
    // Hiển thị
    // ---------------------------------------------------------------------

    public function test_badge_shows_pending_count(): void
    {
        $this->makePending(3);

        $this->assertSame('3', $this->dashboardBadge($this->adminWithView()));
    }

    public function test_badge_hidden_when_no_pending_request(): void
    {
        $admin = $this->adminWithView();

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertNull($this->badgeNumber($html));
        $this->assertStringNotContainsString('pending-withdraw-badge', $html);
    }

    public function test_only_pending_status_is_counted(): void
    {
        $owner = $this->makeOwner();

        foreach ([
            WithdrawRequest::STATUS_PAID,
            WithdrawRequest::STATUS_REJECTED,
            WithdrawRequest::STATUS_CANCELLED,
        ] as $status) {
            WithdrawRequest::factory()->create([
                'user_id' => $owner->id,
                'username' => $owner->username,
                'status' => $status,
            ]);
        }

        $this->makePending(2);

        $this->assertSame('2', $this->dashboardBadge($this->adminWithView()));
    }

    public function test_badge_display_is_capped_at_99_plus_but_counter_is_exact(): void
    {
        $admin = $this->adminWithView();
        $this->makePending(100);

        $html = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame('99+', $this->badgeNumber($html));
        $this->assertStringContainsString('100 yêu cầu rút tiền đang chờ xử lý', $html);
    }

    // ---------------------------------------------------------------------
    // Phân quyền
    // ---------------------------------------------------------------------

    public function test_regular_user_does_not_see_badge(): void
    {
        $this->makePending(3);

        $this->assertNull($this->dashboardBadge(User::factory()->create()));
    }

    public function test_admin_without_withdrawals_view_permission_does_not_see_badge(): void
    {
        $this->makePending(3);

        $adminWithoutPermission = User::factory()->create();
        $adminWithoutPermission->givePermissionTo('users.view');

        $this->assertNull($this->dashboardBadge($adminWithoutPermission));
    }

    // ---------------------------------------------------------------------
    // Cập nhật theo trạng thái DB
    // ---------------------------------------------------------------------

    public function test_badge_decreases_after_manager_completes_one_request(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('withdrawals.view');
        $manager->givePermissionTo('withdrawals.manage');

        $target = $this->makeProcessable(50000);
        $this->makePending(2);

        $this->assertSame('3', $this->dashboardBadge($manager));

        $this->actingAs($manager)
            ->post(route('admin.withdraw-requests.complete', $target))
            ->assertRedirect();

        $this->assertSame('paid', $target->fresh()->status);
        $this->assertSame('2', $this->dashboardBadge($manager));
    }

    public function test_badge_decreases_after_manager_rejects_one_request(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('withdrawals.view');
        $manager->givePermissionTo('withdrawals.manage');

        $target = $this->makeProcessable(50000);
        $this->makePending(2);

        $this->assertSame('3', $this->dashboardBadge($manager));

        $this->actingAs($manager)
            ->post(route('admin.withdraw-requests.reject', $target), ['note' => 'Sai thông tin'])
            ->assertRedirect();

        $this->assertSame('rejected', $target->fresh()->status);
        $this->assertSame('2', $this->dashboardBadge($manager));
    }

    public function test_badge_increases_after_new_pending_request_created(): void
    {
        $admin = $this->adminWithView();

        $this->assertNull($this->dashboardBadge($admin));

        $this->makePending(1);

        $this->assertSame('1', $this->dashboardBadge($admin));
    }

    public function test_badge_hides_again_when_last_pending_request_is_processed(): void
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('withdrawals.view');
        $manager->givePermissionTo('withdrawals.manage');

        $target = $this->makeProcessable(50000);

        $this->assertSame('1', $this->dashboardBadge($manager));

        $this->actingAs($manager)
            ->post(route('admin.withdraw-requests.reject', $target), ['note' => 'Sai thông tin'])
            ->assertRedirect();

        $this->assertNull($this->dashboardBadge($manager));
    }
}
