<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\SpendQualification;
use App\Models\CreditCard\SpendQualificationTemplate;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CashbackRecordService;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\SpendQualificationService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * "Điều kiện hoàn tiền đặc biệt" (Spend Qualification) — gate của kỳ sao kê.
 *
 * Điều kiện đo trên CHI TIÊU THỰC TẾ của giao dịch trong kỳ (không phải eligible
 * spend, không phải cashback, không theo tier rate). Mọi điều kiện gộp bằng AND;
 * 0 điều kiện hoặc qualification bị tắt = no-op. Không đạt ⇒ mọi giao dịch kỳ
 * ineligible với `Transaction::REASON_QUALIFICATION_NOT_MET` và tổng kỳ = 0,
 * nhưng `total_eligible_spend` vẫn ghi con số thực tế.
 */
class SpendQualificationTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private CashbackRecordService $records;

    private SpendQualificationService $qualifications;

    private StatementPeriodService $periods;

    private PolicyCloneService $clone;

    private UserCard $card;

    private User $user;

    /** @var Category */
    private $category;

    /** @var Category */
    private $otherCategory;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->records = app(CashbackRecordService::class);
        $this->qualifications = app(SpendQualificationService::class);
        $this->periods = app(StatementPeriodService::class);
        $this->clone = app(PolicyCloneService::class);

        $this->user = User::factory()->create();
        $this->card = $this->makeUserCard($this->user->id, ['statement_day' => 31]);
        $this->category = $this->makeSystemCategory();
        $this->otherCategory = $this->makeSystemCategory();
    }

    private function policyId(): int
    {
        return (int) $this->card->refresh()->currentPolicy->id;
    }

    private function attachCategoryQualification(int $minSpend, ?int $sourceTemplateId = null): void
    {
        $payload = [
            'name' => 'Điều kiện test',
            'conditions' => [
                [
                    'type' => 'category',
                    'category_id' => $this->category->id,
                    'min_spend' => $minSpend,
                ],
            ],
        ];

        if ($sourceTemplateId !== null) {
            $payload['source_template_id'] = $sourceTemplateId;
        }

        $this->qualifications->persistForPolicyVersion($this->policyId(), $payload, (int) $this->user->id);
    }

    // =====================================================================
    // GATE — category
    // =====================================================================

    #[Test]
    public function qualification_gate_blocks_the_whole_period_when_the_category_minimum_is_not_met(): void
    {
        $this->seedTwoTiers();
        $this->attachCategoryQualification(5_000_000);

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $transaction = $this->addTransaction($period, '2026-09-05', '1000000');

        $this->records->calculatePeriod($this->card, $period);

        $this->assertFalse((bool) $transaction->refresh()->is_eligible, 'Không đạt điều kiện ⇒ toàn kỳ ineligible.');
        $this->assertSame(Transaction::REASON_QUALIFICATION_NOT_MET, $transaction->refresh()->ineligible_reason);
        $this->assertSame('0.00', $transaction->refresh()->cashback_amount_snapshot);
        $this->assertSame('0.00', $period->refresh()->total_cashback);
        // Tổng eligible spend THỰC TẾ vẫn được ghi lại để đối soát.
        $this->assertSame('1000000.00', $period->refresh()->total_eligible_spend);
    }

    #[Test]
    public function reaching_the_category_minimum_unlocks_the_whole_period(): void
    {
        $this->seedTwoTiers();
        $this->attachCategoryQualification(5_000_000);

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $transaction = $this->addTransaction($period, '2026-09-05', '6000000');

        $this->records->calculatePeriod($this->card, $period);

        $this->assertTrue((bool) $transaction->refresh()->is_eligible);
        $this->assertNull($transaction->refresh()->ineligible_reason);
        // 6tr @ bậc 2 (≥5tr) 10%.
        $this->assertSame('600000.00', $transaction->refresh()->cashback_amount_snapshot);
        $this->assertSame('600000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function a_late_transaction_crossing_the_gate_unlocks_earlier_ones(): void
    {
        $this->seedTwoTiers();
        $this->attachCategoryQualification(5_000_000);

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $first = $this->addTransaction($period, '2026-09-05', '3000000');

        $this->records->calculatePeriod($this->card, $period);
        $this->assertSame('0.00', $first->refresh()->cashback_amount_snapshot);

        // Thêm giao dịch cuối kỳ đưa tổng danh mục qua ngưỡng ⇒ RETROACTIVE.
        $this->addTransaction($period, '2026-09-28', '3000000');
        $this->records->calculatePeriod($this->card, $period);

        // 6tr ⇒ bậc 2 ⇒ 10% cho cả giao dịch đã nhập trước đó.
        $this->assertSame('300000.00', $first->refresh()->cashback_amount_snapshot);
        $this->assertTrue((bool) $first->refresh()->is_eligible);
        $this->assertSame('600000.00', $period->refresh()->total_cashback);
    }

    // =====================================================================
    // GATE — other ("Lĩnh vực khác")
    // =====================================================================

    #[Test]
    public function other_condition_measures_whole_period_spend_minus_excluded_categories(): void
    {
        $this->seedTwoTiers();

        // Chi tiêu: danh mục chính 2tr + danh mục khác 3tr = 5tr cả kỳ.
        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $this->addTransaction($period, '2026-09-05', '2000000');
        $this->addTransaction($period, '2026-09-20', '3000000', ['category_id' => $this->otherCategory->id]);

        // "other" loại trừ danh mục chính ⇒ điều kiện đo 3tr >= 3tr ⇒ đạt.
        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                [
                    'type' => 'other',
                    'min_spend' => 3000000,
                    'excluded_category_ids' => [$this->category->id],
                ],
            ],
        ], (int) $this->user->id);

        $this->records->calculatePeriod($this->card, $period);

        $this->assertNull($period->refresh()->calculation_meta['reason'] ?? null);
        $this->assertSame('40000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function other_with_excluded_categories_fails_when_what_remains_is_below_minimum(): void
    {
        $this->seedTwoTiers();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $this->addTransaction($period, '2026-09-05', '2000000');
        $transaction = $this->addTransaction($period, '2026-09-20', '3000000', ['category_id' => $this->otherCategory->id]);

        // Loại trừ danh mục chính (2tr) ⇒ còn 3tr < 4tr ⇒ chưa đạt.
        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                [
                    'type' => 'other',
                    'min_spend' => 4000000,
                    'excluded_category_ids' => [$this->category->id],
                ],
            ],
        ], (int) $this->user->id);

        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame(Transaction::REASON_QUALIFICATION_NOT_MET, $transaction->refresh()->ineligible_reason);
        $this->assertSame('0.00', $transaction->refresh()->cashback_amount_snapshot);
        $this->assertSame('0.00', $period->refresh()->total_cashback);
        $this->assertSame(Transaction::REASON_QUALIFICATION_NOT_MET, $period->refresh()->calculation_meta['reason']);
    }

    #[Test]
    public function other_without_exclusions_means_the_whole_period_spend(): void
    {
        $this->seedTwoTiers();

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $this->addTransaction($period, '2026-09-05', '2000000');
        $this->addTransaction($period, '2026-09-20', '3000000', ['category_id' => $this->otherCategory->id]);

        // excluded rỗng ⇒ điều kiện đo TOÀN BỘ chi tiêu cả kỳ (5tr) >= 5tr ⇒ đạt.
        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'other', 'min_spend' => 5000000],
            ],
        ], (int) $this->user->id);

        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('40000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function all_conditions_are_joined_with_and(): void
    {
        $this->seedTwoTiers();

        // Hai điều kiện: danh mục chính >= 3tr VÀ danh mục khác >= 2tr.
        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 3000000],
                ['type' => 'category', 'category_id' => $this->otherCategory->id, 'min_spend' => 2000000],
            ],
        ], (int) $this->user->id);

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $this->addTransaction($period, '2026-09-05', '3000000');
        $transaction = $this->addTransaction($period, '2026-09-20', '1000000', ['category_id' => $this->otherCategory->id]);

        $this->records->calculatePeriod($this->card, $period);
        $this->assertSame(Transaction::REASON_QUALIFICATION_NOT_MET, $transaction->refresh()->ineligible_reason, 'Chưa đạt điều kiện 2 ⇒ gate chặn cả kỳ.');

        // Thêm chi tiêu danh mục khác đủ ngưỡng ⇒ gate mở; giao dịch danh mục khác
        // không có rule nên kết thúc với no_category_rule (KHÔNG phải qualification_not_met).
        $this->addTransaction($period, '2026-09-25', '1000000', ['category_id' => $this->otherCategory->id]);
        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame(Transaction::REASON_NO_CATEGORY_RULE, $transaction->refresh()->ineligible_reason);
        $primary = Transaction::where('statement_period_id', $period->id)
            ->where('category_id', $this->category->id)
            ->firstOrFail();
        $this->assertTrue((bool) $primary->refresh()->is_eligible);
        $this->assertSame('60000.00', $primary->refresh()->cashback_amount_snapshot, '3tr @ bậc 1 2%.');
        $this->assertSame('60000.00', $period->refresh()->total_cashback);
    }

    // =====================================================================
    // NO-OP
    // =====================================================================

    #[Test]
    public function a_disabled_qualification_is_a_noop(): void
    {
        $this->seedTwoTiers();

        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'enabled' => false,
            'conditions' => [
                ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 5_000_000],
            ],
        ], (int) $this->user->id);

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $this->addTransaction($period, '2026-09-05', '1000000');

        $this->records->calculatePeriod($this->card, $period);

        $transaction = Transaction::where('statement_period_id', $period->id)->firstOrFail();
        $this->assertNull($transaction->ineligible_reason, 'Qualification tắt không được gate.');
        $this->assertSame('20000.00', $period->refresh()->total_cashback, '1tr @ bậc 1 2%.');
    }

    #[Test]
    public function a_qualification_without_active_conditions_is_a_noop(): void
    {
        $this->seedTwoTiers();

        // Không có condition nào ⇒ enabled conditions rỗng ⇒ no-op.
        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [],
        ], (int) $this->user->id);

        $period = $this->periods->resolvePeriodForDate($this->card, CarbonImmutable::parse('2026-09-10'));
        $this->addTransaction($period, '2026-09-05', '1000000');

        $this->records->calculatePeriod($this->card, $period);

        $this->assertSame('20000.00', $period->refresh()->total_cashback, '1tr @ bậc 1 2%.');
        $this->assertNull($period->refresh()->calculation_meta['reason'] ?? null);
    }

    // =====================================================================
    // CLONE (PolicyCloneService) — trọng tâm của fix copy "giữ nguyên"
    // =====================================================================

    #[Test]
    public function attaching_a_template_copies_its_qualification_into_the_card_version(): void
    {
        // Blueprint + điều kiện hệ thống.
        $template = $this->makeSystemTemplate();
        $blueprint = $this->clone->createTemplateBlueprint($template, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'Blueprint SQ',
            'tiers' => [
                [
                    'name' => 'Bậc 1',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [['category_id' => $this->category->id, 'percent' => '5.000']],
                ],
            ],
            'spend_qualification' => [
                'name' => 'Điều kiện mẫu',
                'conditions' => [
                    ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 3_000_000, 'note' => 'Mua sắm'],
                ],
            ],
        ]);

        // "Khoá vắng" — thêm thẻ KHÔNG kèm payload ⇒ giữ nguyên từ blueprint
        // (đây là nhánh bị lỗi trước đây: copyFromTemplate nhận nhầm id PolicyTemplate).
        $this->clone->attachTemplateToCard($this->card, $template, CarbonImmutable::parse('2026-10-01'));

        $root = $this->card->refresh()->currentPolicy;
        $payload = $this->qualifications->payloadForPolicyVersion((int) $root->id);

        $this->assertNotSame($blueprint->id, (int) $root->id, 'Thẻ phải có bản clone, không tham chiếu blueprint.');
        $this->assertNotNull($payload, 'Bản clone phải mang theo điều kiện của blueprint.');
        $this->assertSame('Điều kiện mẫu', $payload['name']);
        $this->assertCount(1, $payload['conditions']);
        $this->assertSame('category', $payload['conditions'][0]['type']);
        $this->assertSame($this->category->id, $payload['conditions'][0]['category_id']);
        $this->assertSame(3000000.0, $payload['conditions'][0]['min_spend']);
        $this->assertNull($payload['source_template_id']);
    }

    #[Test]
    public function attaching_a_template_with_overrides_replaces_the_qualification_and_sets_the_trace(): void
    {
        $template = $this->makeSystemTemplate();
        $this->clone->createTemplateBlueprint($template, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'Blueprint SQ',
            'tiers' => [
                [
                    'name' => 'Bậc 1',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [['category_id' => $this->category->id, 'percent' => '5.000']],
                ],
            ],
            'spend_qualification' => [
                'conditions' => [
                    ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 3_000_000],
                ],
            ],
        ]);

        $source = SpendQualificationTemplate::create([
            'name' => 'Mẫu SQ của user',
            'slug' => 'mau-sq-'.uniqid(),
            'description' => 'Nguồn dấu vết',
        ]);

        // Payload gửi kèm `spend_qualification` ⇒ GHI ĐÈ bản clone từ blueprint.
        $this->clone->attachTemplateToCard($this->card, $template, CarbonImmutable::parse('2026-10-01'), overrides: [
            'spend_qualification' => [
                'source_template_id' => $source->id,
                'conditions' => [
                    [
                        'type' => 'other',
                        'min_spend' => 2_000_000,
                        'excluded_category_ids' => [$this->category->id],
                    ],
                ],
            ],
        ]);

        $root = $this->card->refresh()->currentPolicy;
        $payload = $this->qualifications->payloadForPolicyVersion((int) $root->id);

        $this->assertCount(1, $payload['conditions']);
        $this->assertSame('other', $payload['conditions'][0]['type']);
        $this->assertSame([$this->category->id], $payload['conditions'][0]['excluded_category_ids']);
        $this->assertSame($source->id, $payload['source_template_id'], 'Chọn mẫu phải lưu dấu vết nguồn.');
    }

    #[Test]
    public function attaching_a_template_with_null_qualification_leaves_the_card_without_conditions(): void
    {
        $template = $this->makeSystemTemplate();
        $this->clone->createTemplateBlueprint($template, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'Blueprint SQ',
            'tiers' => [
                [
                    'name' => 'Bậc 1',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [['category_id' => $this->category->id, 'percent' => '5.000']],
                ],
            ],
            'spend_qualification' => [
                'conditions' => [
                    ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 3_000_000],
                ],
            ],
        ]);

        $this->clone->attachTemplateToCard($this->card, $template, CarbonImmutable::parse('2026-10-01'), overrides: [
            'spend_qualification' => null,
        ]);

        $root = $this->card->refresh()->currentPolicy;
        $this->assertNull($this->qualifications->payloadForPolicyVersion((int) $root->id), 'null = tắt, không dùng điều kiện.');
    }

    #[Test]
    public function creating_the_next_version_copies_the_qualification_and_its_trace(): void
    {
        $this->seedTwoTiers();

        $source = SpendQualificationTemplate::create([
            'name' => 'Mẫu SQ gốc',
            'slug' => 'mau-sq-goc-'.uniqid(),
        ]);

        $this->attachCategoryQualification(2_000_000, (int) $source->id);

        $originalId = $this->policyId();
        $generated = $this->clone->createNextVersion($this->card, CarbonImmutable::parse('2026-10-01'));
        $version2 = $this->card->refresh()->currentPolicy;

        $this->assertNotSame($generated->id, $originalId);
        $payload = $this->qualifications->payloadForPolicyVersion((int) $version2->id);

        $this->assertNotNull($payload, 'Version mới append-only phải mang theo điều kiện.');
        $this->assertSame($source->id, $payload['source_template_id'], 'Dấu vết nguồn được giữ nguyên khi copy.');
        $this->assertCount(1, $payload['conditions']);
        $this->assertSame($this->category->id, $payload['conditions'][0]['category_id']);
    }

    // =====================================================================
    // REPLACE / NULL — semantics của payload
    // =====================================================================

    #[Test]
    public function source_template_id_is_preserved_when_absent_and_rewritten_when_null(): void
    {
        $this->seedTwoTiers();

        $source = SpendQualificationTemplate::create([
            'name' => 'Mẫu SQ',
            'slug' => 'mau-sq-'.uniqid(),
        ]);

        $this->attachCategoryQualification(2_000_000, (int) $source->id);

        // Khoá VẮNG trong payload sau đó ⇒ giữ nguyên trace (editor admin không gửi khoá).
        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 3_000_000],
            ],
        ], (int) $this->user->id);

        $this->assertSame(
            $source->id,
            $this->qualifications->payloadForPolicyVersion($this->policyId())['source_template_id']
        );

        // Khoá gửi kèm value null ⇒ XOÁ trace (user chọn "Không sử dụng").
        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'source_template_id' => null,
            'conditions' => [
                ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 3_000_000],
            ],
        ], (int) $this->user->id);

        $this->assertNull($this->qualifications->payloadForPolicyVersion($this->policyId())['source_template_id']);
    }

    #[Test]
    public function persisting_null_deletes_the_whole_qualification(): void
    {
        $this->seedTwoTiers();
        $this->attachCategoryQualification(2_000_000);

        $this->assertNotNull($this->qualifications->payloadForPolicyVersion($this->policyId()));

        $this->qualifications->persistForPolicyVersion($this->policyId(), null, (int) $this->user->id);

        $this->assertNull($this->qualifications->payloadForPolicyVersion($this->policyId()));
        $this->assertFalse(SpendQualification::query()->where('policy_version_id', $this->policyId())->exists());
    }

    // =====================================================================
    // VALIDATION — normalizeConditions
    // =====================================================================

    #[Test]
    public function category_condition_rejects_excluded_categories(): void
    {
        $this->seedTwoTiers();

        $this->expectException(InvalidArgumentException::class);

        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                [
                    'type' => 'category',
                    'category_id' => $this->category->id,
                    'min_spend' => 1000,
                    'excluded_category_ids' => [$this->otherCategory->id],
                ],
            ],
        ], (int) $this->user->id);
    }

    #[Test]
    public function other_condition_rejects_an_explicit_category(): void
    {
        $this->seedTwoTiers();

        $this->expectException(InvalidArgumentException::class);

        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'other', 'category_id' => $this->category->id, 'min_spend' => 1000],
            ],
        ], (int) $this->user->id);
    }

    #[Test]
    public function rejects_more_than_one_other_condition(): void
    {
        $this->seedTwoTiers();

        $this->expectException(InvalidArgumentException::class);

        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'other', 'min_spend' => 1000],
                ['type' => 'other', 'min_spend' => 2000],
            ],
        ], (int) $this->user->id);
    }

    #[Test]
    public function rejects_negative_or_missing_min_spend(): void
    {
        $this->seedTwoTiers();

        $this->expectException(InvalidArgumentException::class);

        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'other', 'min_spend' => -100],
            ],
        ], (int) $this->user->id);
    }

    #[Test]
    public function rejects_duplicate_excluded_categories(): void
    {
        $this->seedTwoTiers();

        $this->expectException(InvalidArgumentException::class);

        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                [
                    'type' => 'other',
                    'min_spend' => 1000,
                    'excluded_category_ids' => [$this->category->id, $this->category->id],
                ],
            ],
        ], (int) $this->user->id);
    }

    #[Test]
    public function rejects_an_unknown_condition_type(): void
    {
        $this->seedTwoTiers();

        $this->expectException(InvalidArgumentException::class);

        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'trending', 'min_spend' => 1000],
            ],
        ], (int) $this->user->id);
    }

    // =====================================================================
    // PHẠM VI DANH MỤC — system-only vs user
    // =====================================================================

    #[Test]
    public function system_records_reject_user_categories(): void
    {
        $this->seedTwoTiers();
        $own = $this->makeUserCategory((int) $this->user->id);

        $this->expectException(InvalidArgumentException::class);

        // Bản ghi system (userId = null: blueprint admin) không được trỏ danh mục user.
        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'category', 'category_id' => $own->id, 'min_spend' => 1000],
            ],
        ], null);
    }

    #[Test]
    public function system_template_rejects_user_categories(): void
    {
        $template = SpendQualificationTemplate::create([
            'name' => 'Mẫu SQ admin',
            'slug' => 'mau-sq-admin-'.uniqid(),
        ]);
        $own = $this->makeUserCategory((int) $this->user->id);

        $this->expectException(InvalidArgumentException::class);

        $this->qualifications->persistTemplate((int) $template->id, [
            'conditions' => [
                ['type' => 'category', 'category_id' => $own->id, 'min_spend' => 1000],
            ],
        ]);
    }

    #[Test]
    public function user_records_accept_own_categories(): void
    {
        $this->seedTwoTiers();
        $own = $this->makeUserCategory((int) $this->user->id);

        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'category', 'category_id' => $own->id, 'min_spend' => 1000],
            ],
        ], (int) $this->user->id);

        $payload = $this->qualifications->payloadForPolicyVersion($this->policyId());
        $this->assertSame($own->id, $payload['conditions'][0]['category_id']);
    }

    #[Test]
    public function user_records_reject_another_users_categories(): void
    {
        $this->seedTwoTiers();
        $foreign = $this->makeUserCategory((int) User::factory()->create()->id);

        $this->expectException(InvalidArgumentException::class);

        $this->qualifications->persistForPolicyVersion($this->policyId(), [
            'conditions' => [
                ['type' => 'category', 'category_id' => $foreign->id, 'min_spend' => 1000],
            ],
        ], (int) $this->user->id);
    }

    // =====================================================================
    // ADMIN TEMPLATE CRUD
    // =====================================================================

    #[Test]
    public function template_payload_round_trips_in_canonical_order(): void
    {
        $template = SpendQualificationTemplate::create([
            'name' => 'Mẫu SQ chuẩn',
            'slug' => 'mau-sq-chuan-'.uniqid(),
            'description' => 'Mô tả',
            'note' => 'Ghi chú',
            'sort_order' => 3,
        ]);

        $this->qualifications->persistTemplate((int) $template->id, [
            'description' => 'Mô tả mới',
            'note' => 'Ghi chú mới',
            'conditions' => [
                ['type' => 'other', 'min_spend' => 5000000, 'excluded_category_ids' => [$this->otherCategory->id]],
                ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 3000000],
            ],
        ]);

        // Server chuẩn hoá: category trước, other cuối (payload đặt ngược).
        $payload = $this->qualifications->payloadForTemplate((int) $template->id);

        $this->assertSame('category', $payload['conditions'][0]['type']);
        $this->assertSame('other', $payload['conditions'][1]['type']);
        $this->assertSame([$this->otherCategory->id], $payload['conditions'][1]['excluded_category_ids']);
        $this->assertSame(3000000.0, $payload['conditions'][0]['min_spend']);
        $this->assertSame(5000000.0, $payload['conditions'][1]['min_spend']);

        $this->assertSame('Mô tả mới', $template->refresh()->description);
        $this->assertSame('Ghi chú mới', $template->refresh()->note);
    }

    #[Test]
    public function persisting_null_clears_template_conditions_but_keeps_the_template(): void
    {
        $template = SpendQualificationTemplate::create([
            'name' => 'Mẫu SQ',
            'slug' => 'mau-sq-'.uniqid(),
        ]);

        $this->qualifications->persistTemplate((int) $template->id, [
            'conditions' => [
                ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 1000],
            ],
        ]);

        $this->assertNotNull($this->qualifications->payloadForTemplate((int) $template->id));

        $this->qualifications->persistTemplate((int) $template->id, null);

        // `null` xoá các hàng điều kiện; template vẫn tồn tại, payload rỗng.
        $payload = $this->qualifications->payloadForTemplate((int) $template->id);
        $this->assertSame([], $payload['conditions']);
        $this->assertNotNull($template->refresh(), 'Xoá điều kiện không được xoá template.');
    }

    #[Test]
    public function a_used_template_cannot_be_deleted(): void
    {
        $this->seedTwoTiers();

        $source = SpendQualificationTemplate::create([
            'name' => 'Mẫu SQ đang dùng',
            'slug' => 'mau-sq-dang-dung-'.uniqid(),
        ]);

        $this->attachCategoryQualification(2_000_000, (int) $source->id);

        $this->expectException(InvalidArgumentException::class);

        $this->qualifications->deleteTemplate((int) $source->id);
    }

    #[Test]
    public function an_unused_template_can_be_deleted(): void
    {
        $template = SpendQualificationTemplate::create([
            'name' => 'Mẫu SQ rác',
            'slug' => 'mau-sq-rac-'.uniqid(),
        ]);

        $this->qualifications->persistTemplate((int) $template->id, [
            'conditions' => [
                ['type' => 'category', 'category_id' => $this->category->id, 'min_spend' => 1000],
            ],
        ]);

        $this->qualifications->deleteTemplate((int) $template->id);

        $this->assertNull(SpendQualificationTemplate::query()->find($template->id));
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function seedTwoTiers(): void
    {
        $this->makePolicyForCard($this->card, [
            ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
            ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
        ], [
            ['category_id' => $this->category->id, 'percent' => '2.000', 'only_tier' => 0],
            ['category_id' => $this->category->id, 'percent' => '10.000', 'only_tier' => 1],
        ]);
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
}