<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceReversalGuardTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WalletService::class);

        $this->user = User::factory()->create([
            'wallet_balance' => 0,
            'total_earned' => 0,
            'username' => 'testuser',
        ]);
    }

    private function makeCreditedItem(int $creditAmount): AffiliateOrderItem
    {
        $item = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'TikTok',
            'order_id' => 'ORD-GUARD-1',
            'affiliate_status' => AffiliateOrderItem::STATUS_COMPLETED,
            'cashback_amount' => $creditAmount,
        ]);

        $this->service->creditCashback($item);

        return $item;
    }

    public function test_reversal_succeeds_when_balance_is_sufficient(): void
    {
        $item = $this->makeCreditedItem(5000);

        $this->user->refresh();
        $this->assertSame(5000.0, (float) $this->user->wallet_balance);

        $item->affiliate_status = AffiliateOrderItem::STATUS_CANCELLED;
        $item->save();

        $refund = $this->service->reverseCashback($item, throwOnDuplicate: false);

        $this->assertNotNull($refund);
        $this->assertSame('refund', $refund->type);
        $this->assertSame('debit', $refund->direction);
        $this->assertSame(5000.0, (float) $refund->amount);

        $this->user->refresh();
        $this->assertSame(0.0, (float) $this->user->wallet_balance);
    }

    public function test_reversal_aborts_when_balance_is_insufficient_and_is_atomic(): void
    {
        $item = $this->makeCreditedItem(5000);

        // User withdraws the credited cashback, leaving balance 0.
        $this->user->refresh();
        $this->assertSame(5000.0, (float) $this->user->wallet_balance);
        $this->user->wallet_balance = 0;
        $this->user->save();

        $item->affiliate_status = AffiliateOrderItem::STATUS_CANCELLED;
        $item->save();

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->service->reverseCashback($item, throwOnDuplicate: false);
        } finally {
            // Atomicity: the aborted reversal must leave NO refund ledger row
            // and NO negative balance (nothing partially written).
            $this->user->refresh();
            $this->assertSame(0.0, (float) $this->user->wallet_balance);
            $this->assertGreaterThanOrEqual(0.0, (float) $this->user->wallet_balance);

            $this->assertSame(
                0,
                WalletTransaction::where('reference_type', 'affiliate_order_item')
                    ->where('reference_id', $item->id)
                    ->where('type', WalletTransaction::TYPE_REFUND)
                    ->where('status', WalletTransaction::STATUS_COMPLETED)
                    ->count()
            );

            // The original cashback credit is never deleted.
            $this->assertTrue($this->service->isCashbackCredited($item));
            $this->assertFalse($this->service->isCashbackReversed($item));
        }
    }

    public function test_reversal_aborts_when_balance_is_negative_scenario_before_guard(): void
    {
        $item = $this->makeCreditedItem(5000);

        // Simulate a user who withdrew MORE than the credited amount.
        $this->user->refresh();
        $this->user->wallet_balance = 3000;
        $this->user->save();

        $item->affiliate_status = AffiliateOrderItem::STATUS_CANCELLED;
        $item->save();

        try {
            $this->service->reverseCashback($item, throwOnDuplicate: false);
            $this->fail('Expected InsufficientBalanceException was not thrown.');
        } catch (InsufficientBalanceException $e) {
            // number_format renders 3000 as "3.000" in the message.
            $this->assertStringContainsString('3.000', $e->getMessage());
        }

        $this->user->refresh();
        $this->assertSame(3000.0, (float) $this->user->wallet_balance);
        $this->assertFalse($this->service->isCashbackReversed($item));
    }

    public function test_reversal_still_idempotent_after_successful_guard(): void
    {
        $item = $this->makeCreditedItem(2000);

        $item->affiliate_status = AffiliateOrderItem::STATUS_CANCELLED;
        $item->save();

        $first = $this->service->reverseCashback($item, throwOnDuplicate: false);
        $this->assertNotNull($first);

        // Second reversal must be a no-op (duplicate guard before balance math).
        $second = $this->service->reverseCashback($item, throwOnDuplicate: false);
        $this->assertNull($second);

        $this->user->refresh();
        $this->assertSame(0.0, (float) $this->user->wallet_balance);
        $this->assertSame(
            1,
            WalletTransaction::where('reference_type', 'affiliate_order_item')
                ->where('reference_id', $item->id)
                ->where('type', WalletTransaction::TYPE_REFUND)
                ->where('status', WalletTransaction::STATUS_COMPLETED)
                ->count()
        );
    }

    public function test_reversal_with_no_credit_returns_null_without_guard_machinery(): void
    {
        $item = AffiliateOrderItem::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'TikTok',
            'order_id' => 'ORD-GUARD-2',
            'affiliate_status' => AffiliateOrderItem::STATUS_CANCELLED,
            'cashback_amount' => 0,
        ]);

        $refund = $this->service->reverseCashback($item, throwOnDuplicate: false);

        $this->assertNull($refund);
        $this->user->refresh();
        $this->assertSame(0.0, (float) $this->user->wallet_balance);
        $this->assertFalse($this->service->isCashbackReversed($item));
    }
}