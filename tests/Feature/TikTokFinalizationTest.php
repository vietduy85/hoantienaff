<?php

namespace Tests\Feature;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\RioHub\RioHubClient;
use App\Services\RioHub\RioHubResponse;
use App\Services\TikTok\TikTokCashbackCalculator;
use App\Services\TikTok\TikTokOrderNormalizer;
use App\Services\TikTok\TikTokOrderSyncService;
use App\Services\TikTok\TikTokUserResolver;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase 2 lifecycle tests: SETTLED belt, TRUE LOCK, historical protection and
 * the idempotent reversal flow for TikTok NEW orders.
 */
class TikTokFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $fallback;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fallback = User::factory()->create([
            'username'       => 'tintuctonghop103',
            'wallet_balance' => 0,
            'total_earned'   => 0,
        ]);
    }

    private function service(RioHubClient $client): TikTokOrderSyncService
    {
        $normalizer = new TikTokOrderNormalizer(
            new TikTokUserResolver(),
            new TikTokCashbackCalculator(),
        );

        return new TikTokOrderSyncService($client, $normalizer, new WalletService());
    }

    private function mockApi(array $orders): RioHubClient
    {
        return $this->mutableClient($orders);
    }

    private function mutableClient(array &$current): RioHubClient
    {
        $mock = Mockery::mock(RioHubClient::class);
        $mock->shouldReceive('getOrders')
            ->andReturnUsing(function () use (&$current) {
                return new RioHubResponse(200, [
                    'page'      => 1,
                    'page_size' => 50,
                    'total'     => count($current),
                    'orders'    => $current,
                ]);
            });

        return $mock;
    }

    /**
     * Belt-passing SETTLED order (status=2, SETTLED, tt_order_status=103,
     * est == actual). Optionally drops settled_at to test it is informational.
     */
    private function settledOrder(string $orderId, float $gmv, float $commission, bool $withSettledAt = true): array
    {
        $order = [
            'order_id'          => $orderId,
            'product_id'        => '1000',
            'product_name'      => "Sản phẩm {$orderId}",
            'status'            => 2,
            'settlement_status' => 'SETTLED',
            'tt_order_status'   => 103,
            'commission_gmv'    => $gmv,
            'est_commission'    => $commission,
            'actual_commission' => $commission,
            'time_created'      => '2026-07-28 10:00:00',
            'time_delivered'    => '2026-07-29 05:00:00',
        ];

        if ($withSettledAt) {
            $order['settled_at'] = '2026-09-11 01:02:22';
        }

        return $order;
    }

    private function refundedOrder(string $orderId): array
    {
        return [
            'order_id'          => $orderId,
            'product_id'        => '1000',
            'product_name'      => "Sản phẩm {$orderId}",
            'status'            => 3,
            'settlement_status' => 'REFUNDED',
            'tt_order_status'   => 104,
            'commission_gmv'    => 100000,
            'actual_commission' => null,
            'est_commission'    => null,
            'time_created'      => '2026-07-28 10:00:00',
        ];
    }

    private function toSettleOrder(string $orderId, float $gmv, float $est): array
    {
        return [
            'order_id'          => $orderId,
            'product_id'        => '1000',
            'product_name'      => "Sản phẩm {$orderId}",
            'status'            => 1,
            'settlement_status' => 'To-SETTLE',
            'tt_order_status'   => 100,
            'commission_gmv'    => $gmv,
            'est_commission'    => $est,
            'actual_commission' => null,
            'time_created'      => '2026-07-28 10:00:00',
        ];
    }

    private function makeTikTokRow(array $overrides = []): AffiliateOrderItem
    {
        $base = [
            'platform'                 => 'TikTok',
            'order_id'                 => 'HIST-001',
            'order_status'             => 'SETTLED',
            'checkout_id'              => '',
            'shop_name'                => 'S',
            'shop_id'                  => 0,
            'item_id'                  => 9999,
            'item_name'                => 'i',
            'model_id'                 => 0,
            'item_price'               => 100000,
            'quantity'                 => 1,
            'order_amount'             => 100000,
            'commission_type'          => 'Fixed',
            'shopee_commission_rate'   => 0,
            'shopee_commission'        => 0,
            'seller_commission_rate'   => 0,
            'total_product_commission' => 23653,
            'order_commission_shopee'  => 0,
            'order_commission_seller'  => 0,
            'total_order_commission'   => 23653,
            'agreed_commission_rate'   => 0,
            'net_commission'           => 23653,
            'affiliate_status'         => 'Hoàn thành',
            'import_batch'             => '20260913_000000',
            'user_id'                  => $this->fallback->id,
            'username'                 => $this->fallback->username,
            'cashback_rate'            => 0.5,
            'cashback_amount'          => 11826,
            'first_imported_at'        => now(),
            'last_tiktok_sync_at'      => now(),
        ];

        return AffiliateOrderItem::create(array_merge($base, $overrides));
    }

    private function row(string $orderId): AffiliateOrderItem
    {
        return AffiliateOrderItem::where('platform', 'TikTok')->where('order_id', $orderId)->firstOrFail();
    }

    // =================================================================
    //  Belt A — full SETTLED belt passes => finalize + credit + lock
    // =================================================================
    public function test_settled_belt_passes_finalizes_credits_and_locks(): void
    {
        $result = $this->service($this->mockApi([$this->settledOrder('F-001', 100000, 8848)]))->run();

        $this->assertSame(1, $result->inserted);
        $this->assertSame(1, $result->cashbackCredited);

        $item = $this->row('F-001');
        $this->assertSame('Hoàn thành', $item->affiliate_status);
        $this->assertSame('SETTLED', $item->order_status);
        $this->assertSame(4424.0, (float) $item->cashback_amount);
        $this->assertSame(8848.0, (float) $item->net_commission);
        $this->assertNotNull($item->finalized_at);
        $this->assertSame(AffiliateOrderItem::FINALIZE_GATE_TIKTOK_SETTLED, $item->finalize_gate);
        $this->assertSame(4424.0, $item->getFinalCashbackAmount());
        $this->assertSame(103, $item->tt_order_status);
        $this->assertSame('2026-09-11 01:02:22', $item->settled_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($item->completed_at);
        $this->assertNotNull($item->locked_at);

        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count());
        $this->fallback->refresh();
        $this->assertSame(4424.0, (float) $this->fallback->wallet_balance);
    }

    // =================================================================
    //  Belt B — settlement still To-SETTLE => pending, no credit
    // =================================================================
    public function test_settled_status_with_to_settle_settlement_stays_pending_no_credit(): void
    {
        $order = $this->settledOrder('F-002', 100000, 8848);
        $order['settlement_status'] = 'To-SETTLE';

        $result = $this->service($this->mockApi([$order]))->run();

        $item = $this->row('F-002');
        $this->assertSame('Đang xử lý', $item->affiliate_status);
        $this->assertSame(4424.0, (float) $item->cashback_amount, 'display estimate only');
        $this->assertNull($item->finalized_at);
        $this->assertNull($item->final_cashback_amount);
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, WalletTransaction::count());
        $this->fallback->refresh();
        $this->assertSame(0.0, (float) $this->fallback->wallet_balance);
    }

    // =================================================================
    //  Belt C — status != 2 => pending, no credit
    // =================================================================
    public function test_status_one_with_settled_settlement_stays_pending_no_credit(): void
    {
        $order = $this->settledOrder('F-003', 100000, 8848);
        $order['status'] = 1;

        $result = $this->service($this->mockApi([$order]))->run();

        $item = $this->row('F-003');
        $this->assertSame('Đang xử lý', $item->affiliate_status);
        $this->assertNull($item->finalized_at);
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, WalletTransaction::count());
    }

    // =================================================================
    //  Belt D — tt_order_status != 103 => pending, no credit
    // =================================================================
    public function test_tt_order_status_100_blocks_finalize_no_credit(): void
    {
        $order = $this->settledOrder('F-004', 100000, 8848);
        $order['tt_order_status'] = 100;

        $result = $this->service($this->mockApi([$order]))->run();

        $item = $this->row('F-004');
        $this->assertSame('Đang xử lý', $item->affiliate_status);
        $this->assertNull($item->finalized_at);
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, WalletTransaction::count());
    }

    // =================================================================
    //  Belt E — actual != est => pending, no finalize, no credit
    // =================================================================
    public function test_actual_differs_from_estimate_blocks_finalize(): void
    {
        $order = $this->settledOrder('F-005', 100000, 9000);
        $order['actual_commission'] = 8848;

        $result = $this->service($this->mockApi([$order]))->run();

        $item = $this->row('F-005');
        $this->assertSame('Đang xử lý', $item->affiliate_status);
        $this->assertNull($item->finalized_at);
        $this->assertNull($item->final_cashback_amount);
        $this->assertSame(4500.0, (float) $item->cashback_amount, 'estimate from est_commission (9000@50%)');
        $this->assertSame(0, $result->cashbackCredited);
        $this->assertSame(0, WalletTransaction::count());
    }

    // =================================================================
    //  Idempotency — two syncs, one credit, frozen snapshot
    // =================================================================
    public function test_finalize_is_idempotent_no_double_credit(): void
    {
        $orders = [$this->settledOrder('F-006', 100000, 8848)];
        $service = $this->service($this->mockApi($orders));

        $service->run();
        $service->run();

        $item = $this->row('F-006');
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count());
        $this->assertSame(4424.0, $item->getFinalCashbackAmount());
        $this->assertNotNull($item->finalized_at);

        $this->fallback->refresh();
        $this->assertSame(4424.0, (float) $this->fallback->wallet_balance);
    }

    // =================================================================
    //  Drift — SETTLED then To-SETTLE: lock must not downgrade / re-credit
    // =================================================================
    public function test_settled_to_tosettle_drift_keeps_finalized_state(): void
    {
        $current = [$this->settledOrder('F-007', 100000, 8848)];
        $service = $this->service($this->mutableClient($current));

        $service->run();

        $item = $this->row('F-007');
        $this->assertNotNull($item->finalized_at);

        // API drifts back to To-SETTLE.
        $current = [$this->toSettleOrder('F-007', 100000, 8848)];

        $service->run();

        $item->refresh();
        $this->assertSame('Hoàn thành', $item->affiliate_status);
        $this->assertSame(4424.0, (float) $item->cashback_amount);
        $this->assertSame(4424.0, $item->getFinalCashbackAmount());
        $this->assertNotNull($item->finalized_at);

        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count());
        $this->fallback->refresh();
        $this->assertSame(4424.0, (float) $this->fallback->wallet_balance);
    }

    // =================================================================
    //  Historical — pre-deploy credited order is NEVER touched
    // =================================================================
    public function test_historical_credited_order_never_touched_by_lifecycle(): void
    {
        $item = $this->makeTikTokRow(['order_id' => 'HIST-001', 'item_id' => 9999]);
        (new WalletService())->creditCashback($item);
        $item->refresh();

        $this->assertSame(1, WalletTransaction::count());
        $this->fallback->refresh();

        // API now reports the order as REFUNDED.
        $refunded = $this->refundedOrder('HIST-001');
        $refunded['product_id'] = '9999';
        $service = $this->service($this->mockApi([$refunded]));
        $service->run();

        $item->refresh();
        $this->assertSame('Hoàn thành', $item->affiliate_status);
        $this->assertSame(11826.0, (float) $item->cashback_amount);
        $this->assertNull($item->reversed_at, 'historical orders are never reversed');
        $this->assertNull($item->finalized_at, 'no retroactive finalization backfill');
        $this->assertNull($item->final_cashback_amount);

        $this->assertSame(1, WalletTransaction::count(), 'no refund transaction created');
        $this->fallback->refresh();
        $this->assertSame(11826.0, (float) $this->fallback->wallet_balance);

        // API now reports the order back to To-SETTLE — still untouched.
        $toSettle = $this->toSettleOrder('HIST-001', 100000, 23653);
        $toSettle['product_id'] = '9999';
        $service = $this->service($this->mockApi([$toSettle]));
        $service->run();

        $item->refresh();
        $this->assertSame('Hoàn thành', $item->affiliate_status);
        $this->assertSame(11826.0, (float) $item->cashback_amount);
        $this->assertSame(1, WalletTransaction::count());
        $this->fallback->refresh();
        $this->assertSame(11826.0, (float) $this->fallback->wallet_balance);
    }

    // =================================================================
    //  Section 10 — pre-existing PENDING row (no credit) is still NEW
    // =================================================================
    public function test_preexisting_pending_row_without_credit_finalizes_when_settled(): void
    {
        $this->makeTikTokRow([
            'order_id'         => 'HNEW-001',
            'item_id'          => 1000,
            'affiliate_status' => 'Đang xử lý',
            'cashback_amount'  => 4424,
            'net_commission'   => 8848,
            'total_product_commission' => 8848,
            'total_order_commission'   => 8848,
        ]);
        $this->assertSame(0, WalletTransaction::count());

        $service = $this->service($this->mockApi([$this->settledOrder('HNEW-001', 100000, 8848)]));
        $result = $service->run();

        $item = $this->row('HNEW-001');
        $this->assertSame('Hoàn thành', $item->affiliate_status);
        $this->assertNotNull($item->finalized_at);
        $this->assertSame(4424.0, $item->getFinalCashbackAmount());
        $this->assertSame(1, $result->cashbackCredited);
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count());
        $this->fallback->refresh();
        $this->assertSame(4424.0, (float) $this->fallback->wallet_balance);
    }

    // =================================================================
    //  Refund after finalize — exactly one reversal, kept snapshot
    // =================================================================
    public function test_refund_after_finalize_reverses_exactly_once(): void
    {
        $current = [$this->settledOrder('F-010', 100000, 8848)];
        $service = $this->service($this->mutableClient($current));

        $service->run();

        $item = $this->row('F-010');
        $this->assertNotNull($item->finalized_at);
        $this->assertSame(4424.0, (float) $this->fallback->fresh()->wallet_balance);

        // Platform reverses the order.
        $current = [$this->refundedOrder('F-010')];
        $result = $service->run();

        $item->refresh();
        $this->assertSame('Đã hủy', $item->affiliate_status);
        $this->assertSame(0.0, (float) $item->cashback_amount);
        $this->assertNotNull($item->reversed_at);
        $this->assertNotNull($item->finalized_at, 'finalized snapshot survives reversal for audit');
        $this->assertSame(4424.0, $item->getFinalCashbackAmount());

        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count(), 'original credit kept');
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_REFUND)->count());
        $refund = WalletTransaction::where('type', WalletTransaction::TYPE_REFUND)->first();
        $this->assertSame(4424.0, (float) $refund->amount, 'reverses exactly the credited amount');
        $this->assertSame(1, $result->cashbackReversed);

        $this->fallback->refresh();
        $this->assertSame(0.0, (float) $this->fallback->wallet_balance);

        // A further sync of the still-refunded order must NOT reverse again.
        $service->run();
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_REFUND)->count());
        $this->fallback->refresh();
        $this->assertSame(0.0, (float) $this->fallback->wallet_balance);
    }

    // =================================================================
    //  settled_at is informational only — null still finalizes
    // =================================================================
    public function test_settled_at_null_is_informational_and_does_not_block_finalize(): void
    {
        $order = $this->settledOrder('F-011', 100000, 8848, withSettledAt: false);
        $this->assertArrayNotHasKey('settled_at', $order);

        $result = $this->service($this->mockApi([$order]))->run();

        $item = $this->row('F-011');
        $this->assertSame(1, $result->cashbackCredited);
        $this->assertNull($item->settled_at);
        $this->assertNotNull($item->finalized_at);
        $this->assertSame(1, WalletTransaction::where('type', WalletTransaction::TYPE_CASHBACK)->count());
    }
}