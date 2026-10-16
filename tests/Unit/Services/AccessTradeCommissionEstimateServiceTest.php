<?php

namespace Tests\Unit\Services;

use App\Services\AccessTrade\AccessTradeCommissionEstimateService;
use App\Services\CashbackCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A rate may only be derived when the campaign's advertised `max_com` and its
 * `commission_policy` AGREE on exactly one commission percentage. The publisher
 * commission is then fed through the project's single cashback formula
 * ({@see CashbackCalculator}); no new tax rate is introduced.
 */
class AccessTradeCommissionEstimateServiceTest extends TestCase
{
    private AccessTradeCommissionEstimateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AccessTradeCommissionEstimateService(new CashbackCalculator);
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: float}>
     */
    public static function acceptedRates(): array
    {
        return [
            'simple match' => ['6%', 'Hoa hồng (Chưa VAT): 6%', 0.06],
            'comma decimal' => ['10,5%', 'Hoa hồng 10,5% giá trị đơn hàng.', 0.105],
            'max_com with qualifier suffix' => ['0.63% / giá trị đơn hàng', 'Hoa hồng 0,63% trên giá trị đơn hàng.', 0.0063],
            'policy mentions VAT rate' => ['10,5%', 'Hoa hồng 10,5% (chưa bao gồm 8% VAT).', 0.105],
            'adidas vat-inclusive' => ['4.2%', 'Hoa hồng 4.2% (bao gồm VAT).', 0.042],
            'single policy rate' => ['2.8%', 'Hoa hồng: 2,8% x giá trị đơn hàng (Chưa VAT).', 0.028],
            'rate above tax threshold' => ['15%', 'Hoa hồng 15%.', 0.15],
        ];
    }

    #[DataProvider('acceptedRates')]
    public function test_derives_rate_when_policy_corroborates_max_com(?string $maxCom, ?string $policy, float $expected): void
    {
        $this->assertSame($expected, $this->service->deriveRate($maxCom, $policy));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function rejectedRates(): array
    {
        return [
            'no max_com' => [null, 'Hoa hồng 6%.'],
            'empty max_com' => ['', 'Hoa hồng 6%.'],
            'non-percentage max_com' => ['700000', 'Hoa hồng 6%.'],
            'max_com None' => ['None', 'Hoa hồng 6%.'],
            'zero percent' => ['0%', 'Hoa hồng 0%.'],
            'multiple max_com rates' => ['1.4% (vé) và 5.6% (khách sạn)', '1.4% và 5.6%'],
            'no policy' => ['6%', null],
            'empty policy' => ['6%', ''],
            'policy without percentage' => ['6%', 'Hoa hồng tối đa 700.000đ mỗi đơn.'],
            'policy contradicts max_com' => ['12%', 'Hoa hồng lên đến 5.5% giá trị đơn hàng.'],
            'policy category table' => ['6%', 'Hoa hồng 0,65% cho Điện thoại; 3,24% cho Laptop; 2,07% cho Phụ kiện.'],
            'policy has extra different rate' => ['10.5%', 'Áp dụng 10,5%; riêng nhóm A là 7%; nhóm B 8,4%.'],
            'policy fixed amount' => ['6%', 'Hoa hồng 588.300 VNĐ mỗi đơn.'],
        ];
    }

    #[DataProvider('rejectedRates')]
    public function test_returns_null_when_rate_cannot_be_corroborated(?string $maxCom, ?string $policy): void
    {
        $this->assertNull($this->service->deriveRate($maxCom, $policy));
    }

    public function test_estimate_uses_the_single_cashback_formula(): void
    {
        $estimate = $this->service->estimate(10_000_000, '6%', 'Hoa hồng (Chưa VAT): 6%');

        $this->assertNotNull($estimate);
        $this->assertSame(600_000.0, $estimate['commission']);
        $this->assertSame(0.50, $estimate['cashback_rate']);
        // floor(floor(600000 * 0.90) * 0.50) = floor(540000 * 0.5) = 270000
        $this->assertSame(270_000, $estimate['user_estimated_cashback']);
    }

    public function test_estimate_applies_high_commission_tier(): void
    {
        $estimate = $this->service->estimate(1_000_000, '15%', 'Hoa hồng 15%');

        $this->assertNotNull($estimate);
        $this->assertSame(150_000.0, $estimate['commission']);
        $this->assertSame(0.60, $estimate['cashback_rate']);
        // floor(floor(150000 * 0.90) * 0.60) = floor(135000 * 0.6) = 81000
        $this->assertSame(81_000, $estimate['user_estimated_cashback']);
    }

    public function test_estimate_is_null_when_rate_is_not_corroborated(): void
    {
        $this->assertNull($this->service->estimate(10_000_000, '12%', 'Hoa hồng lên đến 5.5%'));
    }

    public function test_estimate_is_null_without_a_price(): void
    {
        $this->assertNull($this->service->estimate(0, '6%', 'Hoa hồng 6%'));
    }
}
