<?php

namespace Tests\Unit\Services;

use App\Services\CashbackCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Locks the project's SINGLE cashback formula (after-tax).
 *
 * Rule: user cashback = floor( floor(commission * 0.90) * rate )
 * where rate is 50% (default), 60% or 70% based on commission/price ratio.
 * 10% withholding tax is applied on the publisher commission.
 */
class CashbackCalculatorTest extends TestCase
{
    private CashbackCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new CashbackCalculator;
    }

    public function test_low_commission_rate_is_fifty_percent_after_tax(): void
    {
        // ratio 100000/1000000 = 0.10 -> 50%
        // net = floor(100000 * 0.90) = 90000; user = floor(90000 * 0.50) = 45000
        $result = $this->calculator->calculate(100000.0, 1000000.0);

        $this->assertSame(0.50, $result['cashback_rate']);
        $this->assertSame(45000, $result['user_estimated_cashback']);
    }

    public function test_mid_commission_rate_is_sixty_percent_after_tax(): void
    {
        // ratio 150000/1000000 = 0.15 -> 60%
        // net = 135000; user = floor(135000 * 0.60) = 81000
        $result = $this->calculator->calculate(150000.0, 1000000.0);

        $this->assertSame(0.60, $result['cashback_rate']);
        $this->assertSame(81000, $result['user_estimated_cashback']);
    }

    public function test_high_commission_rate_is_seventy_percent_after_tax(): void
    {
        // ratio 600000/1000000 = 0.60 -> 70%
        // net = 540000; user = floor(540000 * 0.70) = 378000
        $result = $this->calculator->calculate(600000.0, 1000000.0);

        $this->assertSame(0.70, $result['cashback_rate']);
        $this->assertSame(378000, $result['user_estimated_cashback']);
    }

    public function test_tax_is_withheld_before_applying_rate(): void
    {
        // 10% tax rounding happens first: floor(105000 * 0.90) = 94500
        // then user = floor(94500 * 0.50) = 47250
        $result = $this->calculator->calculate(105000.0, 1000000.0);

        $this->assertSame(0.50, $result['cashback_rate']);
        $this->assertSame(47250, $result['user_estimated_cashback']);
    }

    public function test_missing_inputs_yield_zero_and_default_rate(): void
    {
        $this->assertSame(
            ['cashback_rate' => 0.50, 'user_estimated_cashback' => 0],
            $this->calculator->calculate(0.0, 1000000.0),
        );

        $this->assertSame(
            ['cashback_rate' => 0.50, 'user_estimated_cashback' => 0],
            $this->calculator->calculate(100000.0, 0.0),
        );
    }
}
