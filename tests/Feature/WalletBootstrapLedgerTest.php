<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletBootstrapLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'wallet_balance' => 0,
        ]);
    }

    public function test_bootstrap_creates_ledger_for_completed_orders(): void
    {
        AffiliateOrderItem::factory()->count(3)->create([
            'affiliate_status' => 'Hoàn thành',
            'user_id' => $this->user->id,
            'cashback_amount' => 10000,
        ]);

        $this->artisan('wallet:bootstrap-ledger')
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 3);
        $this->assertSame(30000.0, (float) $this->user->fresh()->wallet_balance);
    }

    public function test_bootstrap_skips_items_without_user(): void
    {
        AffiliateOrderItem::factory()->count(2)->create([
            'affiliate_status' => 'Hoàn thành',
            'user_id' => null,
            'cashback_amount' => 10000,
        ]);

        $this->artisan('wallet:bootstrap-ledger')
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0.0, (float) $this->user->fresh()->wallet_balance);
    }

    public function test_bootstrap_skips_existing_ledger_entries(): void
    {
        $item = AffiliateOrderItem::factory()->create([
            'affiliate_status' => 'Hoàn thành',
            'user_id' => $this->user->id,
            'cashback_amount' => 10000,
        ]);

        app(WalletService::class)->creditCashback($item, throwOnDuplicate: true);

        User::where('id', $this->user->id)->update(['wallet_balance' => 0]);
        $this->user = $this->user->fresh();

        $this->artisan('wallet:bootstrap-ledger')
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(0.0, (float) $this->user->fresh()->wallet_balance);
    }

    public function test_bootstrap_dry_run_does_not_write_to_database(): void
    {
        AffiliateOrderItem::factory()->count(3)->create([
            'affiliate_status' => 'Hoàn thành',
            'user_id' => $this->user->id,
            'cashback_amount' => 10000,
        ]);

        $this->artisan('wallet:bootstrap-ledger', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0.0, (float) $this->user->fresh()->wallet_balance);
    }

    public function test_bootstrap_handles_zero_completed_orders(): void
    {
        $this->artisan('wallet:bootstrap-ledger')
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_bootstrap_handles_mixed_state_items(): void
    {
        AffiliateOrderItem::factory()->create([
            'affiliate_status' => 'Hoàn thành',
            'user_id' => $this->user->id,
            'cashback_amount' => 10000,
        ]);

        AffiliateOrderItem::factory()->create([
            'affiliate_status' => 'Đang chờ xử lý',
            'user_id' => $this->user->id,
            'cashback_amount' => 5000,
        ]);

        $this->artisan('wallet:bootstrap-ledger')
            ->assertSuccessful();

        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(10000.0, (float) $this->user->fresh()->wallet_balance);
    }
}
