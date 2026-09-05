<?php

namespace Tests\Unit\Services\ShopeeFood;

use App\Services\ShopeeFood\ShopeeFoodCashbackCalculator;
use Tests\TestCase;

class ShopeeFoodCashbackCalculatorTest extends TestCase
{
    private ShopeeFoodCashbackCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new ShopeeFoodCashbackCalculator();
    }

    public function test_tier_70_when_commission_ratio_is_high(): void
    {
        // commission 70, order 100 -> ratio 0.70 >= 0.52 -> 70%
        $r = $this->calc->calculate(70.0, 100.0, true);

        $this->assertSame(0.70, $r['cashback_rate']);
        $this->assertSame(49.0, $r['cashback_amount']);
    }

    public function test_tier_60_when_commission_ratio_is_medium(): void
    {
        // 20 / 100 = 0.20 -> >= 0.12 -> 60%
        $r = $this->calc->calculate(20.0, 100.0, true);

        $this->assertSame(0.60, $r['cashback_rate']);
        $this->assertSame(12.0, $r['cashback_amount']);
    }

    public function test_tier_50_for_low_ratio(): void
    {
        // 5 / 100 = 0.05 -> 50%
        $r = $this->calc->calculate(5.0, 100.0, true);

        $this->assertSame(0.50, $r['cashback_rate']);
        $this->assertSame(2.0, $r['cashback_amount']);
    }

    public function test_not_completed_produces_zero_amount(): void
    {
        $r = $this->calc->calculate(70.0, 100.0, false);

        $this->assertSame(0.50, $r['cashback_rate']);
        $this->assertSame(0.0, $r['cashback_amount']);
    }

    public function test_zero_commission_produces_zero_amount_even_when_completed(): void
    {
        $r = $this->calc->calculate(0.0, 100.0, true);

        $this->assertSame(0.0, $r['cashback_amount']);
        $this->assertSame(0.50, $r['cashback_rate']);
    }

    public function test_amount_is_floored(): void
    {
        // 75 * 0.70 = 52.5 -> floor -> 52.0
        $r = $this->calc->calculate(75.0, 100.0, true);

        $this->assertSame(52.0, $r['cashback_amount']);
    }

    public function test_boundary_ratios(): void
    {
        // exactly 0.52 -> 70%
        $this->assertSame(0.70, $this->calc->resolveRate(52.0, 100.0));
        // just under 0.52 -> 60% (51.999/100)
        $this->assertSame(0.60, round($this->calc->resolveRate(51.999, 100.0), 2) === 0.60 ? 0.60 : $this->calc->resolveRate(51.999, 100.0));
        // exactly 0.12 -> 60%
        $this->assertSame(0.60, $this->calc->resolveRate(12.0, 100.0));
    }
}