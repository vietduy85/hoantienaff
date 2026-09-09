<?php

namespace Tests\Feature\Admin;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPendingCashbackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create(['name' => 'users.view']);

        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create(['username' => 'admin']);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('users.view');

        $this->target = User::factory()->create(['username' => 'target']);
    }

    public function test_pending_cashback_list_only_shows_uncredited_cashback_rows(): void
    {
        $pendingPositive = AffiliateOrderItem::factory()->create([
            'user_id' => $this->target->id,
            'affiliate_status' => 'Đang chờ xử lý',
            'cashback_amount' => 5000,
            'ordered_at' => now()->subDays(3),
        ]);

        $completedCredited = AffiliateOrderItem::factory()->create([
            'user_id' => $this->target->id,
            'affiliate_status' => 'Hoàn thành',
            'cashback_amount' => 3000,
            'ordered_at' => now()->subDays(4),
        ]);

        WalletTransaction::factory()->create([
            'user_id' => $this->target->id,
            'reference_type' => 'affiliate_order_item',
            'reference_id' => $completedCredited->id,
            'type' => WalletTransaction::TYPE_CASHBACK,
            'direction' => WalletTransaction::DIRECTION_CREDIT,
            'status' => WalletTransaction::STATUS_COMPLETED,
        ]);

        $cancelledZero = AffiliateOrderItem::factory()->create([
            'user_id' => $this->target->id,
            'affiliate_status' => 'Đã hủy',
            'cashback_amount' => 0,
            'ordered_at' => now()->subDays(2),
        ]);

        $cancelledPositive = AffiliateOrderItem::factory()->create([
            'user_id' => $this->target->id,
            'affiliate_status' => 'Đã hủy',
            'cashback_amount' => 2000,
            'ordered_at' => now()->subDays(1),
        ]);

        $pendingZero = AffiliateOrderItem::factory()->create([
            'user_id' => $this->target->id,
            'affiliate_status' => 'Đang chờ xử lý',
            'cashback_amount' => 0,
            'ordered_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.users.show', $this->target));

        $response->assertOk();

        $listed = collect($response->viewData('pendingCashbackItems'));

        /** @var list<int> $listedIds */
        $listedIds = $listed->pluck('id')->map(fn (int $id): int => $id)->all();

        $this->assertContains($pendingPositive->id, $listedIds);
        $this->assertNotContains($completedCredited->id, $listedIds);
        $this->assertNotContains($cancelledZero->id, $listedIds);
        $this->assertNotContains($cancelledPositive->id, $listedIds);
        $this->assertNotContains($pendingZero->id, $listedIds);
    }

    public function test_pending_cashback_stats_sets_pending_cashback(): void
    {
        AffiliateOrderItem::factory()->create([
            'user_id' => $this->target->id,
            'affiliate_status' => 'Đang chờ xử lý',
            'cashback_amount' => 5000,
        ]);

        AffiliateOrderItem::factory()->create([
            'user_id' => $this->target->id,
            'affiliate_status' => 'Đã hủy',
            'cashback_amount' => 0,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.users.show', $this->target));

        $response->assertOk();

        $stats = $response->viewData('stats');

        $this->assertSame(5000.0, (float) $stats['pending_cashback']);
    }
}