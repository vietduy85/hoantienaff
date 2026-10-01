<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CashbackRecordService;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\PolicyEngineService;
use App\Services\CreditCard\StatementPeriodService;
use App\Services\CreditCard\TierService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * §10, §16, §24 — pipeline tính cashback end-to-end (có DB).
 *
 * Đây là test chứng minh RETROACTIVE hoạt động thật: thêm một giao dịch cuối kỳ
 * làm cashback của các giao dịch ĐÃ NHẬP TRƯỚC ĐÓ thay đổi.
 */
class CashbackPipelineTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private CashbackRecordService $records;

    private StatementPeriodService $periods;

    private UserCard $card;

    private User $user;

    /** @var Category */
    private $category;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->records = app(CashbackRecordService::class);
        $this->periods = app(StatementPeriodService::class);

        $this->user = User::factory()->create();
        $this->card = $this->makeUserCard($this->user->id, ['statement_day' => 31]);
        $this->category = $this->makeSystemCategory();
    }

    // =====================================================================
    // §10 — RETROACTIVE
    // =====================================================================

    #[Test]
    public function adding_a_late_transaction_upgrades_cashback_on_earlier_ones(): void
    {
        $this->seedTwoTiers();

        // Kỳ [01/09 .. 30/09]
        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));

        $first = $this->addTransaction($period, '2026-09-05', '6000000');
        $this->records->calculatePeriod($this->card, $period);

        // Tổng 6tr ⇒ bậc 2 (≥5tr) ⇒ 10%
        $this->assertSame('600000.00', $first->refresh()->cashback_amount_snapshot);
        $this->assertSame('10.000', $first->refresh()->cashback_percent_snapshot);
        $this->assertSame('6000000.00', $period->refresh()->total_eligible_spend);
        $this->assertSame('600000.00', $period->refresh()->total_cashback);

        // Thêm giao dịch nhỏ ở CUỐI kỳ — vẫn nằm bậc 2, nhưng tổng tăng.
        $second = $this->addTransaction($period, '2026-09-28', '1000000');
        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('100000.00', $second->refresh()->cashback_amount_snapshot);

        // Đây mới là điểm mấu chốt: giao dịch ĐÃ TÍNH trước đó bị TÍNH LẠI.
        $this->assertSame('600000.00', $first->refresh()->cashback_amount_snapshot);
        $this->assertSame('7000000.00', $period->refresh()->total_eligible_spend);
        $this->assertSame('700000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function a_removed_transaction_downgrades_cashback_on_remaining_ones(): void
    {
        $this->seedTwoTiers();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));

        $big = $this->addTransaction($period, '2026-09-05', '8000000');
        $this->addTransaction($period, '2026-09-28', '2000000');
        $this->records->calculatePeriod($this->card, $period);

        // Tổng 10tr ⇒ bậc 2 ⇒ 10%
        $this->assertSame('800000.00', $big->refresh()->cashback_amount_snapshot);

        // Xoá mềm giao dịch cuối ⇒ tổng còn 8tr, vẫn bậc 2.
        $this->deleteTransaction($period, '2026-09-28');
        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('800000.00', $big->refresh()->cashback_amount_snapshot);
        $this->assertSame('8000000.00', $period->refresh()->total_eligible_spend);
        $this->assertSame('800000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function dropping_below_a_tier_threshold_rewrites_earlier_cashback(): void
    {
        // Bậc 1: [0, 10tr) 2%   Bậc 2: [10tr, NULL) 8%
        $this->seedTiers(
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000],
                ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null],
            ],
            ['2.000', '8.000']
        );

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));

        $big = $this->addTransaction($period, '2026-09-05', '12000000');
        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('960000.00', $big->refresh()->cashback_amount_snapshot, '12tr phải vào bậc 2.');

        // Thêm giao dịch ÂM (hoàn tiền) làm tổng rơi từ 12tr xuống 5tr ⇒ về bậc 1.
        $refund = $this->addTransaction($period, '2026-09-28', '-7000000');
        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('5000000.00', $period->refresh()->total_eligible_spend);

        // Giao dịch lớn bị hạ rate: 12.000.000 @2% = 240.000
        // (số tiền giao dịch không đổi, chỉ MỨC % rút xuống bậc 1)
        $this->assertSame('240000.00', $big->refresh()->cashback_amount_snapshot);
        $this->assertSame('2.000', $big->refresh()->cashback_percent_snapshot);
        $this->assertSame('240000.00', $period->refresh()->total_cashback);

        // Giao dịch âm không sinh cashback âm.
        $this->assertSame('0.00', $refund->refresh()->cashback_amount_snapshot);
    }

    #[Test]
    public function cashback_is_recalculated_for_every_open_period_not_just_the_newest(): void
    {
        $this->seedTwoTiers();

        $september = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $august = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-08-20'));

        $this->addTransaction($september, '2026-09-05', '6000000');
        $this->addTransaction($august, '2026-08-20', '6000000');

        $this->records->recalculateOpenPeriods($this->card);

        $this->assertSame('600000.00', $september->refresh()->total_cashback);
        $this->assertSame('600000.00', $august->refresh()->total_cashback);
    }

    #[Test]
    public function recalculating_twice_produces_identical_numbers(): void
    {
        $this->seedTwoTiers();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));

        $this->addTransaction($period, '2026-09-05', '3000000');
        $this->addTransaction($period, '2026-09-12', '4000000');

        $this->records->calculatePeriod($this->card, $period);
        $first = $period->refresh()->only(['total_cashback', 'total_eligible_spend', 'effective_cashback_rate']);

        $this->records->calculatePeriod($this->card, $period);
        $second = $period->refresh()->only(['total_cashback', 'total_eligible_spend', 'effective_cashback_rate']);

        $this->assertSame($first, $second, 'Tính lại phải idempotent.');
    }

    // =====================================================================
    // §11 — minimum spend thuộc POLICY
    // =====================================================================

    #[Test]
    public function nothing_gets_cashback_below_the_policy_minimum_spend(): void
    {
        $this->seedTwoTiers(['min_total_spend' => 10000000]);

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $transaction = $this->addTransaction($period, '2026-09-05', '7000000');

        $this->records->calculatePeriod($this->card, $period);

        $this->assertFalse((bool) $transaction->refresh()->is_eligible);
        $this->assertSame(Transaction::REASON_BELOW_MINIMUM_SPEND, $transaction->refresh()->ineligible_reason);
        $this->assertSame('0.00', $transaction->refresh()->cashback_amount_snapshot);
        $this->assertSame('0.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function reaching_the_policy_minimum_spend_unlocks_cashback_for_the_whole_period(): void
    {
        $this->seedTwoTiers(['min_total_spend' => 10000000]);

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));

        $first = $this->addTransaction($period, '2026-09-05', '7000000');
        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('0.00', $first->refresh()->cashback_amount_snapshot);

        $this->addTransaction($period, '2026-09-20', '3000000');
        $this->records->calculatePeriod($this->card, $period);

        // Tổng 10tr ⇒ đạt ngưỡng ⇒ cả hai giao dịch trong kỳ đều có cashback.
        $this->assertSame('700000.00', $first->refresh()->cashback_amount_snapshot);
        $this->assertSame('1000000.00', $period->refresh()->total_cashback);
    }

    // =====================================================================
    // §9.1 — version resolve theo kỳ
    // =====================================================================

    #[Test]
    public function each_period_resolves_its_own_policy_version(): void
    {
        $this->seedTwoTiers();
        $engine = app(PolicyEngineService::class);
        $clone = app(PolicyCloneService::class);

        // Tạo version 2 hiệu lực từ 01/10 với rate khác hẳn.
        $version2 = $clone->createNextVersion(
            $this->card,
            CarbonImmutable::parse('2026-10-01'),
            [
                'tiers' => [
                    [
                        'name' => 'Bậc v2',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            [
                                'category_id' => $this->category->id,
                                'cashback_percent' => 3.0,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ]
        );

        $september = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $october = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-10-10'));

        $this->addTransaction($september, '2026-09-05', '1000000');
        $this->addTransaction($october, '2026-10-05', '1000000');

        $this->records->recalculateOpenPeriods($this->card);

        $september->refresh();
        $october->refresh();

        $this->assertNotSame($version2->id, (int) $september->policy_id, 'Kỳ 09/2026 phải dùng version 1.');
        $this->assertSame($version2->id, (int) $october->policy_id, 'Kỳ 10/2026 phải dùng version 2.');

        // version 1 = 10% (bậc 2 do tổng 1tr? không — bậc 1 = 2%)
        $this->assertSame('20000.00', $september->total_cashback);
        $this->assertSame('30000.00', $october->total_cashback);
    }

    #[Test]
    public function transactions_without_any_policy_version_get_no_cashback(): void
    {
        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $transaction = $this->addTransaction($period, '2026-09-05', '1000000');

        $this->records->calculatePeriod($this->card, $period);

        $this->assertFalse((bool) $transaction->refresh()->is_eligible);
        $this->assertSame(Transaction::REASON_NO_POLICY, $transaction->refresh()->ineligible_reason);
        $this->assertNull($period->refresh()->policy_id);
    }

    // =====================================================================
    // Kỳ finalize
    // =====================================================================

    #[Test]
    public function a_finalized_period_is_never_recalculated(): void
    {
        $this->seedTwoTiers();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $transaction = $this->addTransaction($period, '2026-09-05', '6000000');

        $this->records->calculatePeriod($this->card, $period);
        $this->assertSame('600000.00', $transaction->refresh()->cashback_amount_snapshot);

        $period->forceFill([
            'status' => StatementPeriod::STATUS_FINALIZED,
            'finalized_at' => now(),
        ])->save();

        // Thêm giao dịch mới rồi tính lại ⇒ kỳ finalize bị bỏ qua.
        $this->addTransaction($period, '2026-09-28', '5000000');
        $result = $this->records->calculatePeriod($this->card, $period->refresh());

        $this->assertTrue($result['skipped']);
        $this->assertSame([], $result['results']);
        $this->assertSame('600000.00', $period->refresh()->total_cashback, 'Tổng kỳ finalize bị đổi.');

        // `whereDate` bắt buộc: cột DATE được cast lưu kèm " 00:00:00".
        $late = Transaction::whereDate('transaction_date', '2026-09-28')->firstOrFail();

        $this->assertNull(
            $late->cashback_amount_snapshot,
            'Giao dịch trong kỳ finalize không được ghi snapshot.'
        );
        $this->assertNull($late->is_eligible);
        $this->assertSame('600000.00', $transaction->refresh()->cashback_amount_snapshot);
    }

    #[Test]
    public function recalculate_open_periods_skips_finalized_ones(): void
    {
        $this->seedTwoTiers();

        $september = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $august = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-08-20'));

        $september->forceFill(['status' => StatementPeriod::STATUS_FINALIZED, 'finalized_at' => now()])->save();

        $recalculated = $this->records->recalculateOpenPeriods($this->card);

        $this->assertCount(1, $recalculated);
        $this->assertSame($august->id, $recalculated[0]->id);
    }

    // =====================================================================
    // §16 — audit trail
    // =====================================================================

    #[Test]
    public function every_snapshot_records_the_version_tier_and_rule_it_used(): void
    {
        $this->seedTwoTiers();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $transaction = $this->addTransaction($period, '2026-09-05', '6000000');

        $this->records->calculatePeriod($this->card, $period);
        $transaction->refresh();

        $this->assertNotNull($transaction->policy_version_id);
        $this->assertNotNull($transaction->policy_tier_id);
        $this->assertNotNull($transaction->policy_tier_category_id);
        $this->assertSame('2026-09-05', $transaction->calc_basis->toDateString());
        $this->assertNotNull($transaction->calculated_at);
        $this->assertIsArray($transaction->calc_meta);
        $this->assertSame('retroactive', $period->refresh()->calculation_meta['application_mode']);
    }

    #[Test]
    public function posted_date_basis_is_recorded_as_calc_basis(): void
    {
        $this->seedTwoTiers();

        $this->card->forceFill(['statement_date_basis' => UserCard::BASIS_POSTED_DATE])->save();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $transaction = $this->addTransaction($period, '2026-09-05', '6000000', ['posted_date' => '2026-09-08']);

        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('2026-09-08', $transaction->refresh()->calc_basis->toDateString());
    }

    // =====================================================================
    // §12 — cashback nằm ở rule, không nằm ở category
    // =====================================================================

    #[Test]
    public function a_category_without_any_rule_yields_no_cashback(): void
    {
        $this->seedTwoTiers();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $orphan = $this->makeSystemCategory();

        $transaction = $this->addTransaction($period, '2026-09-05', '6000000', ['category_id' => $orphan->id]);

        $this->records->calculatePeriod($this->card, $period);

        $this->assertFalse((bool) $transaction->refresh()->is_eligible);
        $this->assertSame(Transaction::REASON_NO_CATEGORY_RULE, $transaction->refresh()->ineligible_reason);
        $this->assertSame('0.00', $period->refresh()->total_eligible_spend);
    }

    /**
     * REGRESSION: `calculateTransaction` phải chặn giao dịch của thẻ khác.
     *
     * Trước khi sửa, giao dịch thuộc thẻ B có thể bị tính/ghi cashback vào kỳ
     * của thẻ A, làm sai tổng tiền của thẻ A.
     */
    #[Test]
    public function a_transaction_belonging_to_another_card_cannot_be_calculated(): void
    {
        $this->seedTwoTiers();

        $otherCard = $this->makeUserCard($this->user->id, ['statement_day' => 31]);
        $otherPeriod = $this->periods->resolvePeriodForDate($otherCard, CarbonImmutable::parse('2026-09-10'));

        $foreign = Transaction::create([
            'user_card_id' => $otherCard->id,
            'statement_period_id' => null,
            'category_id' => $this->category->id,
            'transaction_date' => '2026-09-05',
            'amount' => '6000000',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->records->calculateTransaction($this->card, $foreign);
    }

    /**
     * Cùng guard, nhưng kiểm tra cả trường hợp giao dịch chưa gắn kỳ:
     * phải ném lỗi TRƯỚC khi ghi bất kỳ thay đổi nào.
     */
    #[Test]
    public function calculating_a_foreign_transaction_writes_nothing(): void
    {
        $this->seedTwoTiers();

        $otherCard = $this->makeUserCard($this->user->id, ['statement_day' => 31]);

        $foreign = Transaction::create([
            'user_card_id' => $otherCard->id,
            'statement_period_id' => null,
            'category_id' => $this->category->id,
            'transaction_date' => '2026-09-05',
            'amount' => '6000000',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        try {
            $this->records->calculateTransaction($this->card, $foreign);
            $this->fail('Phải ném InvalidArgumentException cho giao dịch của thẻ khác.');
        } catch (\InvalidArgumentException) {
            // Mong đợi.
        }

        $this->assertNull($foreign->refresh()->statement_period_id);
        $this->assertNull($foreign->refresh()->cashback_amount_snapshot);
    }

    // =====================================================================
    // §15 — FALLBACK END-TO-END
    // =====================================================================

    #[Test]
    public function a_transaction_in_a_category_without_a_specific_rule_gets_the_fallback_cashback(): void
    {
        $this->seedTwoTiers();

        $policy = $this->card->refresh()->currentPolicy;
        $tier1 = $policy->tiers()->orderBy('sort_order')->orderBy('id')->first();
        app(CategoryRuleService::class)->create($tier1, ['scope_type' => 'other']);

        $otherCategory = $this->makeSystemCategory();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));

        $specific = $this->addTransaction($period, '2026-09-05', '1000000');
        $uncategorized = $this->addTransaction($period, '2026-09-28', '200000', ['category_id' => $otherCategory->id]);

        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('20000.00', $specific->refresh()->cashback_amount_snapshot, 'Danh mục có rule cụ thể giữ nguyên rate.');
        $this->assertTrue((bool) $uncategorized->refresh()->is_eligible, 'Danh mục không có rule cụ thể vẫn eligible qua fallback.');
        $this->assertNull($uncategorized->refresh()->ineligible_reason);
        $this->assertSame('0.00', $uncategorized->refresh()->cashback_amount_snapshot, 'Fallback 0% cho 0đ nhưng vẫn đủ điều kiện.');

        $this->assertSame('1200000.00', $period->refresh()->total_eligible_spend, 'Chi tiêu fallback vẫn tính vào tổng eligible.');
        $this->assertSame('20000.00', $period->refresh()->total_cashback);
    }

    // =====================================================================
    // §23 — giới hạn hoàn tiền theo giá trị giao dịch (cap động) E2E
    // =====================================================================

    #[Test]
    public function dynamic_transaction_caps_apply_end_to_end(): void
    {
        $this->seedTwoTiers();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));

        // Gắn cap động cho BẬC 2 (10%) của danh mục.
        $policy = Policy::where('user_card_id', $this->card->id)->firstOrFail();
        $tier2 = $policy->tiers()->orderBy('sort_order')->get()[1];

        app(TierService::class)->syncTransactionCaps($tier2, [
            ['min_transaction_amount' => 0, 'max_transaction_amount' => 1000000, 'max_cashback_per_transaction' => 50000],
            ['min_transaction_amount' => 1000000.01, 'max_transaction_amount' => null, 'max_cashback_per_transaction' => 200000],
        ]);

        // Giao dịch 6tr @10% = 600k thô → thuộc khoảng ≥ 1tr ⇒ cap 200k.
        $big = $this->addTransaction($period, '2026-09-05', '6000000');
        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('10.000', $big->refresh()->cashback_percent_snapshot);
        $this->assertSame('200000.00', $big->refresh()->cashback_amount_snapshot, 'Cap động khoảng [1tr, ∞) áp dụng.');

        // Giao dịch 600k @10% = 60k thô → thuộc khoảng [0, 1tr] ⇒ cap 50k.
        $small = $this->addTransaction($period, '2026-09-06', '600000');
        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('50000.00', $small->refresh()->cashback_amount_snapshot, 'Cap động khoảng [0, 1tr] áp dụng.');

        // KPIs kỳ vẫn được cập nhật từ cashback thực nhận.
        $period->refresh();
        $this->assertSame('250000.00', $period->total_cashback);
        $this->assertSame('6600000.00', $period->total_eligible_spend);
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function seedTwoTiers(array $policyAttributes = []): void
    {
        $this->seedTiers(
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
            ],
            ['2.000', '10.000'],
            $policyAttributes
        );
    }

    /**
     * Mỗi bậc có MỘT rule riêng cho cùng danh mục, với rate ứng với bậc đó.
     * (Cùng category ở 2 bậc là cấu hình thực tế: rate phụ thuộc bậc.)
     *
     * @param  array<int, array{name: string, min: float, max: float|null}>  $tiers
     * @param  array<int, string>  $rates
     */
    private function seedTiers(array $tiers, array $rates, array $policyAttributes = []): void
    {
        $rules = [];

        foreach ($rates as $index => $percent) {
            $rules[] = [
                'category_id' => $this->category->id,
                'percent' => $percent,
                'only_tier' => $index,
            ];
        }

        $this->makePolicyForCard($this->card, $tiers, $rules, $policyAttributes);
    }

    private function addTransaction(
        StatementPeriod $period,
        string $date,
        string $amount,
        array $attributes = []
    ): Transaction {
        return Transaction::create(array_merge([
            'user_card_id' => $this->card->id,
            'statement_period_id' => $period->id,
            'category_id' => $this->category->id,
            'transaction_date' => $date,
            'amount' => $amount,
            'source' => Transaction::SOURCE_MANUAL,
        ], $attributes));
    }

    private function deleteTransaction(StatementPeriod $period, string $date): void
    {
        Transaction::where('statement_period_id', $period->id)
            ->whereDate('transaction_date', $date)
            ->first()
            ->delete();
    }
}
