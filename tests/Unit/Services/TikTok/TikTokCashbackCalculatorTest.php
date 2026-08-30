<?php

namespace Tests\Unit\Services\TikTok;

use App\Services\TikTok\DTOs\TikTokOrder;
use App\Services\TikTok\TikTokCashbackCalculator;
use Tests\TestCase;

class TikTokCashbackCalculatorTest extends TestCase
{
    private TikTokCashbackCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new TikTokCashbackCalculator();
    }

    public function test_settled_order_uses_net_commission_without_ten_percent_deduction(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id'          => 'ORD-NET',
            'status'            => 2,
            'settlement_status' => 'SETTLED',
            'commission_gmv'    => 100000,
            'actual_commission' => 18000,
        ]);

        $result = $this->calculator->calculate($order);

        // NET basis: 18000 * 0.60 = 10800 (NOT 18000*0.9*0.6 = 9720)
        $this->assertSame(0.60, $result['cashback_rate']);
        $this->assertSame(10800.0, $result['cashback_amount']);
    }

    public function test_rate_50_when_ratio_below_twelve_percent(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id'          => 'ORD-50',
            'status'            => 2,
            'settlement_status' => 'SETTLED',
            'commission_gmv'    => 100000,
            'actual_commission' => 5000,
        ]);

        $result = $this->calculator->calculate($order);

        $this->assertSame(0.50, $result['cashback_rate']);
        $this->assertSame(2500.0, $result['cashback_amount']);
    }

    public function test_rate_70_when_ratio_above_fifty_two_percent(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id'          => 'ORD-70',
            'status'            => 2,
            'settlement_status' => 'SETTLED',
            'commission_gmv'    => 100000,
            'actual_commission' => 60000,
        ]);

        $result = $this->calculator->calculate($order);

        $this->assertSame(0.70, $result['cashback_rate']);
        $this->assertSame(42000.0, $result['cashback_amount']);
    }

    public function test_refunded_order_produces_no_cashback(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id'          => 'ORD-REF',
            'status'            => 3,
            'settlement_status' => 'REFUNDED',
            'commission_gmv'    => 100000,
            'actual_commission' => null,
        ]);

        $result = $this->calculator->calculate($order);

        $this->assertSame(0.0, $result['cashback_amount']);
    }

    public function test_pending_order_produces_no_cashback(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id'          => 'ORD-PENDING',
            'status'            => 1,
            'settlement_status' => 'AWAITING SETTLEMENT',
            'commission_gmv'    => 100000,
            'actual_commission' => null,
        ]);

        $result = $this->calculator->calculate($order);

        $this->assertSame(0.0, $result['cashback_amount']);
    }

    public function test_settled_with_null_actual_commission_produces_no_cashback(): void
    {
        $order = TikTokOrder::fromArray([
            'order_id'          => 'ORD-NULL',
            'status'            => 2,
            'settlement_status' => 'SETTLED',
            'commission_gmv'    => 100000,
            'actual_commission' => null,
        ]);

        $result = $this->calculator->calculate($order);

        $this->assertSame(0.0, $result['cashback_amount']);
    }
}
