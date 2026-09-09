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

    public function test_pending_order_without_any_commission_produces_no_cashback(): void
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

    public function test_pending_order_with_est_commission_shows_estimate(): void
    {
        // Real case: est 23653 / gmv 473064 -> ratio 0.05 -> tier 50%
        // -> floor(23653 * 0.50) = 11826 (display only, wallet untouched).
        $order = TikTokOrder::fromArray([
            'order_id'          => 'ORD-PENDING-EST',
            'status'            => 1,
            'settlement_status' => 'AWAITING PAYMENT',
            'commission_gmv'    => 473064,
            'est_commission'    => 23653,
            'actual_commission' => null,
        ]);

        $result = $this->calculator->calculate($order);

        $this->assertSame(0.50, $result['cashback_rate']);
        $this->assertSame(11826.0, $result['cashback_amount']);
    }

    public function test_pending_order_with_low_est_commission_shows_estimate(): void
    {
        // Real case #2: est 9204 / gmv 306800 -> ratio 0.03 -> tier 50%
        // -> floor(9204 * 0.50) = 4602.
        $order = TikTokOrder::fromArray([
            'order_id'          => 'ORD-PENDING-EST2',
            'status'            => 1,
            'settlement_status' => 'TO-SETTLE',
            'commission_gmv'    => 306800,
            'est_commission'    => 9204,
            'actual_commission' => null,
        ]);

        $result = $this->calculator->calculate($order);

        $this->assertSame(0.50, $result['cashback_rate']);
        $this->assertSame(4602.0, $result['cashback_amount']);
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

    // ------------------------------------------------------------------
    //  calculateFromCommission — the canonical estimate/credit formula
    // ------------------------------------------------------------------

    public function test_calculate_from_commission_matches_order_credit(): void
    {
        $cases = [
            ['commission' => 5000.0,  'gmv' => 100000.0, 'tier' => 0.50],
            ['commission' => 12000.0, 'gmv' => 100000.0, 'tier' => 0.60],
            ['commission' => 52000.0, 'gmv' => 100000.0, 'tier' => 0.70],
        ];

        foreach ($cases as $case) {
            $fromCommission = $this->calculator->calculateFromCommission($case['commission'], $case['gmv']);

            $order = TikTokOrder::fromArray([
                'order_id'          => 'ORD-FC-' . $case['commission'],
                'status'            => 2,
                'settlement_status' => 'SETTLED',
                'commission_gmv'    => $case['gmv'],
                'actual_commission' => $case['commission'],
            ]);
            $fromOrder = $this->calculator->calculate($order);

            $this->assertSame($case['tier'], $fromCommission['cashback_rate']);
            $this->assertSame($case['tier'], $fromOrder['cashback_rate']);
            $this->assertSame($fromOrder['cashback_amount'], $fromCommission['cashback_amount']);
        }
    }

    public function test_calculate_from_commission_13_100_at_50_percent_equals_6550(): void
    {
        // ratio 13100/200000 = 0.0655 -> tier 50% -> floor(13100 × 0.50) = 6550
        $result = $this->calculator->calculateFromCommission(13100.0, 200000.0);

        $this->assertSame(0.50, $result['cashback_rate']);
        $this->assertSame(6550.0, $result['cashback_amount']);
    }

    public function test_calculate_from_commission_boundaries(): void
    {
        // exactly 0.52 -> 70%
        $result = $this->calculator->calculateFromCommission(52000.0, 100000.0);
        $this->assertSame(0.70, $result['cashback_rate']);
        $this->assertSame(36400.0, $result['cashback_amount']);

        // just below 0.52 -> 60%
        $result = $this->calculator->calculateFromCommission(51000.0, 100000.0);
        $this->assertSame(0.60, $result['cashback_rate']);
        $this->assertSame(30600.0, $result['cashback_amount']);

        // exactly 0.12 -> 60%
        $result = $this->calculator->calculateFromCommission(12000.0, 100000.0);
        $this->assertSame(0.60, $result['cashback_rate']);
        $this->assertSame(7200.0, $result['cashback_amount']);

        // just below 0.12 -> 50%
        $result = $this->calculator->calculateFromCommission(11000.0, 100000.0);
        $this->assertSame(0.50, $result['cashback_rate']);
        $this->assertSame(5500.0, $result['cashback_amount']);
    }

    public function test_calculate_from_commission_zero_or_negative_commission_gives_nothing(): void
    {
        $this->assertSame(0.0, $this->calculator->calculateFromCommission(0.0, 100000.0)['cashback_amount']);
        $this->assertSame(0.0, $this->calculator->calculateFromCommission(-5.0, 100000.0)['cashback_amount']);
    }
}
