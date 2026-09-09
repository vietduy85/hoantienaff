<?php

namespace Tests\Unit\Services\Lazada;

use App\Services\Lazada\LazadaCashbackCalculator;
use PHPUnit\Framework\TestCase;

class LazadaCashbackCalculatorTest extends TestCase
{
    private LazadaCashbackCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new LazadaCashbackCalculator();
    }

    public function test_not_completed_never_credits_cashback(): void
    {
        $cb = $this->calculator->calculate(12000.0, 200000.0, false);

        $this->assertSame(0.0, $cb['cashback_amount']);
        $this->assertSame(0.50, $cb['cashback_rate']);
    }

    public function test_zero_commission_never_credits(): void
    {
        $cb = $this->calculator->calculate(0.0, 200000.0, true);

        $this->assertSame(0.0, $cb['cashback_amount']);
    }

    public function test_low_ratio_uses_50_percent_tier(): void
    {
        // ratio 12000/200000 = 0.06 -> tier 50%
        $cb = $this->calculator->calculate(12000.0, 200000.0, true);

        $this->assertSame(0.50, $cb['cashback_rate']);
        $this->assertSame(6000.0, $cb['cashback_amount']);
    }

    public function test_mid_ratio_uses_60_percent_tier(): void
    {
        // ratio 30000/200000 = 0.15 -> tier 60%
        $cb = $this->calculator->calculate(30000.0, 200000.0, true);

        $this->assertSame(0.60, $cb['cashback_rate']);
        $this->assertSame(18000.0, $cb['cashback_amount']);
    }

    public function test_high_ratio_uses_70_percent_tier_and_floors(): void
    {
        // ratio 125000/200000 = 0.625 -> tier 70%; floor(125000*0.7) = 87500
        $cb = $this->calculator->calculate(125000.0, 200000.0, true);

        $this->assertSame(0.70, $cb['cashback_rate']);
        $this->assertSame(87500.0, $cb['cashback_amount']);
    }

    public function test_boundary_0_12_flips_to_60(): void
    {
        // ratio exactly 0.12 -> 60%
        $cb = $this->calculator->calculate(24000.0, 200000.0, true);
        $this->assertSame(0.60, $cb['cashback_rate']);
    }

    public function test_zero_order_amount_falls_back_to_50(): void
    {
        $this->assertSame(0.50, $this->calculator->resolveRate(100.0, 0));
    }
}