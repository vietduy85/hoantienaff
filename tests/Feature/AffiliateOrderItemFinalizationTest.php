<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AffiliateOrderItemFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'wallet_balance' => 0,
            'username' => 'testuser',
        ]);
    }

    private function makeItem(array $overrides = []): AffiliateOrderItem
    {
        return AffiliateOrderItem::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'TikTok',
            'order_id' => 'ORD-TK-1',
            'affiliate_status' => AffiliateOrderItem::STATUS_PENDING,
            'cashback_amount' => 10000,
        ], $overrides));
    }

    public function test_migration_adds_finalization_columns_nullable_and_backward_compatible(): void
    {
        $columns = Schema::getColumnListing('affiliate_order_items');

        foreach (['delivered_at', 'finalized_at', 'finalize_gate', 'final_cashback_amount', 'reversed_at', 'tt_order_status', 'settled_at'] as $column) {
            $this->assertContains($column, $columns, "missing column {$column}");
        }

        $this->assertContains('locked_at', $columns, 'locked_at must be kept for backward compatibility');

        $item = $this->makeItem();

        $this->assertNull($item->delivered_at);
        $this->assertNull($item->finalized_at);
        $this->assertNull($item->finalize_gate);
        $this->assertNull($item->final_cashback_amount);
        $this->assertNull($item->reversed_at);
        $this->assertNull($item->tt_order_status);
        $this->assertNull($item->settled_at);
    }

    public function test_status_constants_are_defined(): void
    {
        $this->assertSame('Hoàn thành', AffiliateOrderItem::STATUS_COMPLETED);
        $this->assertSame('Đang xử lý', AffiliateOrderItem::STATUS_PENDING);
        $this->assertSame('Đã hủy', AffiliateOrderItem::STATUS_CANCELLED);
        $this->assertSame('tiktok_settled', AffiliateOrderItem::FINALIZE_GATE_TIKTOK_SETTLED);
        $this->assertSame('lazada_delivered_10d', AffiliateOrderItem::FINALIZE_GATE_LAZADA_DELIVERED_10D);
    }

    public function test_new_row_is_not_finalized_and_has_no_snapshot(): void
    {
        $item = $this->makeItem();

        $this->assertFalse($item->isFinalized());
        $this->assertFalse($item->isReversed());
        $this->assertNull($item->getFinalCashbackAmount());
        $this->assertFalse($item->hasCompletedCashbackCredit());
    }

    public function test_mark_finalized_snapshots_cashback_and_sets_gate(): void
    {
        $item = $this->makeItem(['cashback_amount' => 26000]);

        $item->markFinalized(AffiliateOrderItem::FINALIZE_GATE_LAZADA_DELIVERED_10D);

        $item->refresh();

        $this->assertTrue($item->isFinalized());
        $this->assertNotNull($item->finalized_at);
        $this->assertSame(
            AffiliateOrderItem::FINALIZE_GATE_LAZADA_DELIVERED_10D,
            $item->finalize_gate
        );
        $this->assertSame(26000.0, (float) $item->final_cashback_amount);
        $this->assertSame(26000.0, $item->getFinalCashbackAmount());
    }

    public function test_mark_finalized_is_idempotent_and_never_overwrites_snapshot(): void
    {
        $item = $this->makeItem(['cashback_amount' => 10000]);

        $item->markFinalized(AffiliateOrderItem::FINALIZE_GATE_TIKTOK_SETTLED);

        $finalizedAt = $item->finalized_at;

        // Pretend a later sync recomputed the row to a different cashback —
        // finalization must NEVER restamp or recalculate the snapshot.
        $item->cashback_amount = 99999;
        $item->save();

        $item->markFinalized(AffiliateOrderItem::FINALIZE_GATE_LAZADA_DELIVERED_10D);

        $item->refresh();

        $this->assertTrue($item->isFinalized());
        $this->assertSame(
            AffiliateOrderItem::FINALIZE_GATE_TIKTOK_SETTLED,
            $item->finalize_gate
        );
        $this->assertSame(10000.0, (float) $item->final_cashback_amount);

        // Carbon instances come back fresh from the DB — compare by value.
        $this->assertEquals($finalizedAt, $item->finalized_at);
    }

    public function test_mark_reversed_records_reversal_without_touching_snapshot(): void
    {
        $item = $this->makeItem(['cashback_amount' => 8000]);

        $item->markFinalized(AffiliateOrderItem::FINALIZE_GATE_TIKTOK_SETTLED);
        $item->markReversed();

        $item->refresh();

        $this->assertTrue($item->isReversed());
        $this->assertNotNull($item->reversed_at);
        $this->assertTrue($item->isFinalized());
        $this->assertSame(8000.0, (float) $item->final_cashback_amount);
    }

    public function test_has_completed_cashback_credit_true_when_wallet_credit_exists(): void
    {
        $item = $this->makeItem(['cashback_amount' => 26000]);

        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Lazada',
            'type' => WalletTransaction::TYPE_CASHBACK,
            'direction' => WalletTransaction::DIRECTION_CREDIT,
            'amount' => 26000,
            'reference_type' => 'affiliate_order_item',
            'reference_id' => $item->id,
            'status' => WalletTransaction::STATUS_COMPLETED,
        ]);

        $this->assertTrue($item->hasCompletedCashbackCredit());
    }

    public function test_has_completed_cashback_credit_false_without_credit(): void
    {
        $item = $this->makeItem(['cashback_amount' => 26000]);

        $item->markFinalized(AffiliateOrderItem::FINALIZE_GATE_LAZADA_DELIVERED_10D);

        // Finalization fields alone are NOT the historical credit marker.
        $this->assertTrue($item->isFinalized());
        $this->assertFalse($item->hasCompletedCashbackCredit());
    }

    public function test_historical_credited_row_is_protected_by_wallet_credit_marker(): void
    {
        // Simulates a pre-lifecycle credited order: wallet credit exists but the
        // lifecycle fields were never populated (and MUST NOT be migrated from
        // current API state).
        $item = $this->makeItem([
            'platform' => 'Lazada',
            'order_id' => '528798670200749',
            'cashback_amount' => 26000,
            'affiliate_status' => AffiliateOrderItem::STATUS_COMPLETED,
            'locked_at' => now(),
        ]);

        WalletTransaction::factory()->create([
            'user_id' => $this->user->id,
            'username' => $this->user->username,
            'platform' => 'Lazada',
            'type' => WalletTransaction::TYPE_CASHBACK,
            'direction' => WalletTransaction::DIRECTION_CREDIT,
            'amount' => 26000,
            'reference_type' => 'affiliate_order_item',
            'reference_id' => $item->id,
            'status' => WalletTransaction::STATUS_COMPLETED,
        ]);

        $item->refresh();

        $this->assertFalse($item->isFinalized());
        $this->assertNull($item->finalize_gate);
        $this->assertNull($item->final_cashback_amount);
        // The authoritative money-moved signal is the wallet credit — present.
        $this->assertTrue($item->hasCompletedCashbackCredit());
        // No reversal must ever be created for historical credits placed by
        // the lifecycle: cashback wallet ledger count must stay exactly 1.
        $this->assertSame(
            1,
            WalletTransaction::where('reference_type', 'affiliate_order_item')
                ->where('reference_id', $item->id)
                ->where('type', WalletTransaction::TYPE_CASHBACK)
                ->where('status', WalletTransaction::STATUS_COMPLETED)
                ->count()
        );
    }
}