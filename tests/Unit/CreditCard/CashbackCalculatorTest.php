<?php

namespace Tests\Unit\CreditCard;

use App\Services\CreditCard\CashbackCalculator;
use App\Services\CreditCard\CashbackResult;
use App\Services\CreditCard\TransactionLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test THUẦN cho CashbackCalculator — không DB, không Laravel.
 *
 * Đây là nơi khẳng định business rule cashback của module. Nếu một test ở đây
 * đổi, business rule đã đổi, không phải lỗi kỹ thuật.
 */
class CashbackCalculatorTest extends TestCase
{
    private CashbackCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new CashbackCalculator;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @param  array<int, array<string, mixed>>  $overrides
     * @return array<string, mixed>
     */
    private function rule(int $id, int $categoryId, float $percent, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'category_id' => $categoryId,
            'spend_from' => 0.0,
            'spend_to' => null,
            'cashback_percent' => $percent,
            'max_cashback_per_transaction' => null,
            'max_cashback_per_category_per_period' => null,
            'min_transaction_amount' => null,
        ], $overrides);
    }

    private function line(int $id, string $date, string $amount, ?int $categoryId): TransactionLine
    {
        return new TransactionLine($id, $date, $categoryId, $amount);
    }

    /**
     * @param  array<int, CashbackResult>  $results
     * @return array<int, CashbackResult>
     */
    private function keyByTransaction(array $results): array
    {
        $keyed = [];

        foreach ($results as $result) {
            $keyed[$result->transactionId] = $result;
        }

        return $keyed;
    }

    private function sum(array $results): float
    {
        return array_sum(array_map(fn (CashbackResult $r): float => $r->cashbackAmountAsFloat(), $results));
    }

    // =====================================================================
    // Cashback cơ bản
    // =====================================================================

    #[Test]
    public function it_calculates_cashback_with_a_single_flat_rate(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 5.0)],
            transactions: [
                $this->line(1, '2026-10-05', '1000000', 10),
                $this->line(2, '2026-10-12', '500000', 10),
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertTrue($keyed[1]->isEligible);
        $this->assertSame('50000.00', $keyed[1]->cashbackAmount);
        $this->assertSame('25000.00', $keyed[2]->cashbackAmount);
        $this->assertSame('75000.00', number_format($this->sum($results), 2, '.', ''));
    }

    #[Test]
    public function it_marks_transaction_without_category_as_ineligible(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 5.0)],
            transactions: [$this->line(1, '2026-10-05', '1000000', null)],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $this->assertFalse($results[0]->isEligible);
        $this->assertSame(CashbackCalculator::REASON_NO_CATEGORY, $results[0]->ineligibleReason);
        $this->assertSame('0.00', $results[0]->cashbackAmount);
    }

    #[Test]
    public function it_marks_transaction_without_rule_as_ineligible(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 5.0)],
            transactions: [$this->line(1, '2026-10-05', '1000000', 99)],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $this->assertFalse($results[0]->isEligible);
        $this->assertSame(CashbackCalculator::REASON_NO_CATEGORY_RULE, $results[0]->ineligibleReason);
    }

    // =====================================================================
    // Minimum spend — thuộc POLICY, không thuộc category
    // =====================================================================

    #[Test]
    public function it_gives_zero_cashback_when_period_total_is_below_policy_minimum_spend(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 5.0)],
            transactions: [
                $this->line(1, '2026-10-05', '3000000', 10), // tổng 3tr < 5tr
                $this->line(2, '2026-10-12', '1000000', 10),
            ],
            minTotalSpend: 5000000,
            maxCashbackTotalPerPeriod: null,
        );

        foreach ($results as $result) {
            $this->assertFalse($result->isEligible);
            $this->assertSame(CashbackCalculator::REASON_BELOW_MINIMUM_SPEND, $result->ineligibleReason);
        }

        $this->assertSame(0.0, $this->sum($results));
    }

    #[Test]
    public function it_pays_out_once_the_period_total_crosses_policy_minimum_spend(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 5.0)],
            transactions: [
                $this->line(1, '2026-10-05', '5000000', 10), // tổng đúng 5tr => đạt
            ],
            minTotalSpend: 5000000,
            maxCashbackTotalPerPeriod: null,
        );

        $this->assertTrue($results[0]->isEligible);
        $this->assertSame('250000.00', $results[0]->cashbackAmount);
    }

    // =====================================================================
    // min_transaction_amount (thuộc rule của category)
    // =====================================================================

    #[Test]
    public function it_excludes_transactions_below_the_rules_min_transaction_amount(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 5.0, ['min_transaction_amount' => 100000])],
            transactions: [
                $this->line(1, '2026-10-05', '50000', 10),   // dưới ngưỡng
                $this->line(2, '2026-10-12', '200000', 10),  // đủ ngưỡng
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertFalse($keyed[1]->isEligible);
        $this->assertSame(CashbackCalculator::REASON_BELOW_MIN_TRANSACTION, $keyed[1]->ineligibleReason);

        $this->assertTrue($keyed[2]->isEligible);
        $this->assertSame('10000.00', $keyed[2]->cashbackAmount);
    }

    /**
     * REGRESSION: một danh mục có nhiều rule với `min_transaction_amount` khác nhau.
     *
     * Rule cuối cùng được chọn (theo tổng chi tiêu cả kỳ) có min = 1.000.000.
     * Giao dịch 100.000 lọt qua bước sàng lọc ban đầu (vì rule [0, 5tr) không
     * đặt min) nhưng KHÔNG được trả cashback: dưới min của rule đang áp dụng.
     *
     * Trước khi sửa, giao dịch này nhận 5% ⇒ trả tiền oát cho giao dịch dưới
     * ngưỡng của chính rule được chọn.
     */
    #[Test]
    public function it_excludes_transactions_below_the_selected_rules_min_transaction_amount(): void
    {
        $results = $this->calculator->calculate(
            rules: [
                // [0, 5tr) — KHÔNG đặt min
                $this->rule(1, 10, 5.0, ['spend_from' => 0, 'spend_to' => 5000000]),
                // [5tr, ∞) — yêu cầu giao dịch >= 1tr
                $this->rule(2, 10, 10.0, [
                    'spend_from' => 5000000,
                    'spend_to' => null,
                    'min_transaction_amount' => 1000000,
                ]),
            ],
            transactions: [
                $this->line(1, '2026-10-05', '100000', 10),   // < 1tr ⇒ loại
                $this->line(2, '2026-10-12', '6000000', 10),  // đủ ngưỡng
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertFalse($keyed[1]->isEligible);
        $this->assertSame(CashbackCalculator::REASON_BELOW_MIN_TRANSACTION, $keyed[1]->ineligibleReason);
        $this->assertSame(2, $keyed[1]->meta['rule_id'] ?? null);

        $this->assertTrue($keyed[2]->isEligible);
        $this->assertSame('600000.00', $keyed[2]->cashbackAmount);
    }

    /**
     * REGRESSION: không được loại OÁT giao dịch chỉ vì MỘT rule khác không đạt min.
     *
     * Cùng danh mục, rule [0, 5tr) yêu cầu min = 2.000.000 nhưng rule [5tr, ∞)
     * không đặt min. Trước khi sửa, vòng lặc `return` sớm khiến giao dịch 500.000
     * bị loại dù tồn tại rule hợp lệ. Sau khi sửa, giao dịch chỉ bị loại khi
     * KHÔNG rule nào trong danh mục chạm tới nó.
     */
    #[Test]
    public function it_does_not_exclude_a_transaction_because_an_unrelated_rule_has_a_higher_minimum(): void
    {
        $results = $this->calculator->calculate(
            rules: [
                $this->rule(1, 10, 5.0, [
                    'spend_from' => 0,
                    'spend_to' => 5000000,
                    'min_transaction_amount' => 2000000,
                ]),
                $this->rule(2, 10, 10.0, ['spend_from' => 5000000, 'spend_to' => null]),
            ],
            transactions: [
                $this->line(1, '2026-10-05', '500000', 10),   // dưới 2tr
                $this->line(2, '2026-10-12', '5000000', 10),  // đẩy tổng lên 5.5tr
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertTrue($keyed[1]->isEligible);
        $this->assertSame('50000.00', $keyed[1]->cashbackAmount);

        $this->assertTrue($keyed[2]->isEligible);
        // Tổng danh mục = 5.500.000 ⇒ chọn rule [5tr, ∞) @10%.
        // Cashback tính trên SỐ TIỀN GIAO DỊCH, không phải tổng danh mục.
        $this->assertSame('500000.00', $keyed[2]->cashbackAmount);
    }

    // =====================================================================
    // RETROACTIVE — khoảng chi tiêu của CẢ KỲ, không phải từng giao dịch
    // =====================================================================

    #[Test]
    public function it_picks_the_rate_by_total_category_spend_for_the_whole_period(): void
    {
        // [0, 5tr) → 5%   [5tr, NULL) → 10%
        $rules = [
            $this->rule(1, 10, 5.0, ['spend_from' => 0, 'spend_to' => 5000000]),
            $this->rule(2, 10, 10.0, ['spend_from' => 5000000, 'spend_to' => null]),
        ];

        $results = $this->calculator->calculate(
            rules: $rules,
            transactions: [
                $this->line(1, '2026-10-05', '3000000', 10),
                $this->line(2, '2026-10-12', '3000000', 10), // tổng danh mục = 6tr
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $keyed = $this->keyByTransaction($results);

        // Cả hai giao dịch đều ở mức 10% vì tổng danh mục cả kỳ là 6tr.
        $this->assertSame('10.000', $keyed[1]->cashbackPercent);
        $this->assertSame('300000.00', $keyed[1]->cashbackAmount);
        $this->assertSame('300000.00', $keyed[2]->cashbackAmount);
        $this->assertSame(2, $keyed[1]->ruleId, 'Cùng rule 10% cho cả hai giao dịch.');
    }

    #[Test]
    public function it_does_not_upgrade_rate_per_transaction_as_spend_accumulates(): void
    {
        // Chống lại cách hiểu SAI kiểu progressive: giao dịch 1 chỉ 1tr
        // nhưng cùng bậc với giao dịch sau vì tổng kỳ đã vượt ngưỡng.
        $rules = [
            $this->rule(1, 10, 2.0, ['spend_from' => 0, 'spend_to' => 5000000]),
            $this->rule(2, 10, 8.0, ['spend_from' => 5000000, 'spend_to' => null]),
        ];

        $results = $this->calculator->calculate(
            rules: $rules,
            transactions: [
                $this->line(1, '2026-10-05', '1000000', 10),  // riêng lẻ chỉ 1tr
                $this->line(2, '2026-10-12', '9000000', 10), // tổng = 10tr
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertSame('8.000', $keyed[1]->cashbackPercent, 'Không được dùng mức 2% riêng cho giao dịch nhỏ.');
        $this->assertSame('80000.00', $keyed[1]->cashbackAmount);
    }

    #[Test]
    public function it_keeps_category_spend_ranges_independent_per_category(): void
    {
        // Danh mục 10 đạt 6tr (mức 10%), danh mục 20 chỉ đạt 1tr (mức 5%).
        $rules = [
            $this->rule(1, 10, 5.0, ['spend_from' => 0, 'spend_to' => 5000000]),
            $this->rule(2, 10, 10.0, ['spend_from' => 5000000, 'spend_to' => null]),
            $this->rule(3, 20, 5.0, ['spend_from' => 0, 'spend_to' => 5000000]),
        ];

        $results = $this->calculator->calculate(
            rules: $rules,
            transactions: [
                $this->line(1, '2026-10-05', '6000000', 10),
                $this->line(2, '2026-10-12', '1000000', 20),
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertSame('10.000', $keyed[1]->cashbackPercent);
        $this->assertSame('5.000', $keyed[2]->cashbackPercent);
    }

    // =====================================================================
    // 3 CAP
    // =====================================================================

    #[Test]
    public function it_caps_cashback_per_transaction(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 10.0, ['max_cashback_per_transaction' => 200000])],
            transactions: [
                $this->line(1, '2026-10-05', '10000000', 10), // 10% = 1tr ⇒ bị chặn còn 200k
                $this->line(2, '2026-10-12', '1000000', 10),  // 10% = 100k ⇒ không chặn
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertSame('200000.00', $keyed[1]->cashbackAmount);
        $this->assertSame('100000.00', $keyed[2]->cashbackAmount);

        $perTransactionCaps = array_values(array_filter(
            $keyed[1]->meta['caps'],
            fn (array $cap): bool => $cap['type'] === 'per_transaction'
        ));

        $this->assertTrue($perTransactionCaps[0]['applied']);
    }

    #[Test]
    public function it_caps_cashback_per_category_per_period(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 10.0, ['max_cashback_per_category_per_period' => 250000])],
            transactions: [
                $this->line(1, '2026-10-05', '2000000', 10), // 200k
                $this->line(2, '2026-10-12', '2000000', 10), // còn 50k
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertSame('200000.00', $keyed[1]->cashbackAmount);
        $this->assertSame('50000.00', $keyed[2]->cashbackAmount);
        $this->assertSame('250000.00', number_format($this->sum($results), 2, '.', ''));
    }

    #[Test]
    public function it_caps_total_cashback_per_period(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 10.0)],
            transactions: [
                $this->line(1, '2026-10-05', '2000000', 10), // 200k
                $this->line(2, '2026-10-12', '2000000', 10), // còn 100k
                $this->line(3, '2026-10-20', '2000000', 10), // hết quota
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: 300000,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertSame('200000.00', $keyed[1]->cashbackAmount);
        $this->assertSame('100000.00', $keyed[2]->cashbackAmount);
        $this->assertSame('0.00', $keyed[3]->cashbackAmount);
        $this->assertSame('300000.00', number_format($this->sum($results), 2, '.', ''));
    }

    #[Test]
    public function it_applies_per_transaction_and_per_period_total_caps_together(): void
    {
        // cap1 = 200k, cap2 = 500k, cap3 = 300k
        //   Tx1: 5tr @10% = 500k  → cap1 chặn còn 200k
        //   Tx2: 2tr @10% = 200k  → cap1 không chặn, cap3 còn 100k nên chặn còn 100k
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 10.0, [
                'max_cashback_per_transaction' => 200000,
                'max_cashback_per_category_per_period' => 500000,
            ])],
            transactions: [
                $this->line(1, '2026-10-05', '5000000', 10),
                $this->line(2, '2026-10-12', '2000000', 10),
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: 300000,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertSame('200000.00', $keyed[1]->cashbackAmount);
        $this->assertSame('100000.00', $keyed[2]->cashbackAmount);
        $this->assertSame('300000.00', number_format($this->sum($results), 2, '.', ''));

        // Cả 3 cap đều được áp dụng và GHI LẠI để giải thích kết quả.
        $capTypes = array_column($keyed[1]->meta['caps'], 'type');
        $this->assertSame(['per_transaction', 'per_category', 'per_period_total'], $capTypes);

        $appliedOnTx2 = array_column(
            array_filter($keyed[2]->meta['caps'], fn (array $cap): bool => $cap['applied'] === true),
            'type'
        );
        $this->assertSame(['per_period_total'], $appliedOnTx2);
    }

    #[Test]
    public function it_makes_the_per_category_cap_squeeze_out_the_later_transactions(): void
    {
        // cap2 = 250k nhỏ hơn cap3 ⇒ cap2 mới là ràng buộc thật sự.
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 10.0, [
                'max_cashback_per_transaction' => 150000,
                'max_cashback_per_category_per_period' => 250000,
            ])],
            transactions: [
                $this->line(1, '2026-10-05', '10000000', 10), // thô 1tr → cap1 150k
                $this->line(2, '2026-10-12', '3000000', 10),  // thô 300k → cap2 còn 100k
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: 1000000,
        );

        $keyed = $this->keyByTransaction($results);

        $this->assertSame('150000.00', $keyed[1]->cashbackAmount);
        $this->assertSame('100000.00', $keyed[2]->cashbackAmount);
        $this->assertSame('250000.00', number_format($this->sum($results), 2, '.', ''));
    }

    // =====================================================================
    // TẤT ĐỊNH — cùng input phải cho cùng output
    // =====================================================================

    #[Test]
    public function it_is_deterministic_regardless_of_input_order(): void
    {
        $rules = [$this->rule(1, 10, 10.0, [
            'max_cashback_per_category_per_period' => 200000,
        ])];

        $lines = [
            $this->line(1, '2026-10-05', '1000000', 10),
            $this->line(2, '2026-10-12', '1000000', 10),
            $this->line(3, '2026-10-20', '1000000', 10),
        ];

        $ordered = $this->calculator->calculate($rules, $lines, 0, null);
        $shuffled = $this->calculator->calculate($rules, array_reverse($lines), 0, null);

        $this->assertSame(
            $this->keyByTransaction($ordered)[1]->cashbackAmount,
            $this->keyByTransaction($shuffled)[1]->cashbackAmount
        );

        $this->assertSame($this->sum($ordered), $this->sum($shuffled));
        $this->assertSame(
            array_map(fn (CashbackResult $r): string => $r->cashbackAmount, $ordered),
            array_map(fn (CashbackResult $r): string => $r->cashbackAmount, $shuffled)
        );
    }

    #[Test]
    public function quota_is_allocated_to_earliest_transactions_first(): void
    {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 10.0)],
            transactions: [
                $this->line(1, '2026-10-20', '1000000', 10),
                $this->line(2, '2026-10-05', '1000000', 10),
            ],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: 50000,
        );

        $keyed = $this->keyByTransaction($results);

        // Giao dịch 05/10 mới nhận quota vì sớm hơn.
        $this->assertSame('50000.00', $keyed[2]->cashbackAmount);
        $this->assertSame('0.00', $keyed[1]->cashbackAmount);
    }

    // =====================================================================
    // Làm tròn
    // =====================================================================

    #[Test]
    #[DataProvider('roundingCases')]
    public function it_applies_the_requested_rounding_mode(
        string $mode,
        string $amount,
        float $percent,
        string $expected
    ): void {
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, $percent)],
            transactions: [$this->line(1, '2026-10-05', $amount, 10)],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
            roundingMode: $mode,
        );

        $this->assertSame($expected, $results[0]->cashbackAmount);
    }

    /**
     * 12.345 @ 3,33% = 411,0885 ⇒ 4 chữ số thập phân, không nằm trên ranh giới
     * .005 nên round/floor/ceil cho 3 kết quả khác nhau, đủ để phân biệt.
     *
     * @return array<string, array{0: string, 1: string, 2: float, 3: string}>
     */
    public static function roundingCases(): array
    {
        return [
            'round half up' => ['round', '12345', 3.33, '411.09'],
            'floor' => ['floor', '12345', 3.33, '411.08'],
            'ceil' => ['ceil', '12345', 3.33, '411.09'],
        ];
    }

    #[Test]
    public function floor_mode_never_pays_more_than_the_exact_value(): void
    {
        // 33.333 @ 2,5% = 833,325
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 2.5)],
            transactions: [$this->line(1, '2026-10-05', '33333', 10)],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
            roundingMode: 'floor',
        );

        $this->assertSame('833.32', $results[0]->cashbackAmount);
    }

    #[Test]
    public function ceil_mode_never_pays_less_than_the_exact_value(): void
    {
        // 33.333 @ 2,5% = 833,325
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 2.5)],
            transactions: [$this->line(1, '2026-10-05', '33333', 10)],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
            roundingMode: 'ceil',
        );

        $this->assertSame('833.33', $results[0]->cashbackAmount);
    }

    #[Test]
    public function floor_mode_does_not_lose_a_dong_to_floating_point_noise(): void
    {
        // 55.555 @ 1% = 555,55 — CHÍNH XÁC. Nếu làm floor() thẳng giá trị double
        // (55554,999999999995) thì hạ người dùng 1 xu. Phải ra 555,55.
        $results = $this->calculator->calculate(
            rules: [$this->rule(1, 10, 1.0)],
            transactions: [$this->line(1, '2026-10-05', '55555', 10)],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
            roundingMode: 'floor',
        );

        $this->assertSame('555.55', $results[0]->cashbackAmount);
    }

    // =====================================================================
    // Cạm bẫy cấu hình
    // =====================================================================

    #[Test]
    public function it_handles_empty_input_without_error(): void
    {
        $results = $this->calculator->calculate([], [], 0, null);

        $this->assertSame([], $results);
    }

    #[Test]
    public function it_uses_the_narrowest_matching_spend_range_when_rules_overlap(): void
    {
        $rules = [
            $this->rule(1, 10, 5.0, ['spend_from' => 0, 'spend_to' => null]),   // rộng
            $this->rule(2, 10, 9.0, ['spend_from' => 0, 'spend_to' => 5000000]), // hẹp hơn
        ];

        $results = $this->calculator->calculate(
            rules: $rules,
            transactions: [$this->line(1, '2026-10-05', '1000000', 10)],
            minTotalSpend: 0,
            maxCashbackTotalPerPeriod: null,
        );

        $this->assertSame('90000.00', $results[0]->cashbackAmount);
    }
}
