<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CashbackRecordService;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\PolicyService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use Database\Seeders\CreditCardSeeder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Chính sách hệ thống mở rộng (12/10/2026) — seeded từ CreditCardSeeder.
 *
 * ---------------------------------------------------------------------------
 * PHẠM VI
 * ---------------------------------------------------------------------------
 * 8 template cho các thẻ hoàn tiền có tài liệu chính thức đối chiếu đủ (xem
 * docs/CREDIT_CARD_NEW_TEMPLATES_RESEARCH_REPORT.md). KHÔNG tạo template cho
 * "VPBank Reward Visa" (không có sản phẩm Visa chính thức) và "Shinhan
 * Cashback Visa" (3 hạng + T&C xung đột tỷ lệ nền + ưu đãi cuối tuần không cấu
 * hình được) — các sản phẩm này bị chặn ở seeder và được ghi nhận ở báo cáo.
 *
 * Mọi rule chỉ trỏ danh mục hệ thống trong bộ seed gốc (19 danh mục) nên DB
 * test (mới) và DB live đều khớp. Điểm quy đổi 1:1 được mô hình hoá như phần
 * trăm hoàn tiền (tiền lệ VIB Max Card Visa / One Card).
 */
class ResearchedTemplatesPolicyTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    /** slug ⇒ cấu hình kỳ vọng để kiểm tra cấu trúc blueprint. */
    private const PLANS = [
        'sacombank-visa-platinum-cashback' => [
            'bank' => 'stb',
            'name' => 'Sacombank Visa Platinum Cashback',
            'match' => 'platinum cashback',
            'tiers' => [
                [
                    'name' => 'Toàn bộ chi tiêu hợp lệ',
                    'min' => 0,
                    'max' => null,
                    'cap' => '680000.00',
                    'fallback' => ['percent' => '0.390', 'counts_cap' => false, 'min_tx' => 100000],
                    'rules' => [
                        ['cat' => 'sieu-thi-tien-loi', 'percent' => '6.800', 'min_tx' => 100000, 'counts_cap' => true],
                        ['cat' => 'goi-xe-di-chuyen', 'percent' => '16.800', 'min_tx' => 100000, 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'sacombank-platinum-amex' => [
            'bank' => 'stb',
            'name' => 'Sacombank Platinum American Express',
            'match' => 'platinum american express',
            'tiers' => [
                [
                    'name' => 'Toàn bộ chi tiêu hợp lệ',
                    'min' => 0,
                    'max' => null,
                    'cap' => '1000000.00',
                    'fallback' => ['percent' => '0.500', 'counts_cap' => true, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 've-may-bay-du-lich', 'percent' => '20.000', 'counts_cap' => true],
                        ['cat' => 'khach-san-luu-tru', 'percent' => '20.000', 'counts_cap' => true],
                        ['cat' => 'chi-tieu-nuoc-ngoai', 'percent' => '10.000', 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'mb-jcb-platinum' => [
            'bank' => 'mb',
            'name' => 'MB JCB Platinum',
            'match' => 'jcb platinum',
            'tiers' => [
                [
                    'name' => 'Bậc 1 – Dưới 5 triệu',
                    'min' => 0,
                    'max' => 5000000,
                    'cap' => '200000.00',
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'am-thuc-an-uong', 'percent' => '5.000', 'counts_cap' => true],
                        ['cat' => 'sieu-thi-tien-loi', 'percent' => '5.000', 'counts_cap' => true],
                    ],
                ],
                [
                    'name' => 'Bậc 2 – Từ 5 triệu',
                    'min' => 5000000,
                    'max' => null,
                    'cap' => '500000.00',
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'am-thuc-an-uong', 'percent' => '10.000', 'counts_cap' => true],
                        ['cat' => 'sieu-thi-tien-loi', 'percent' => '10.000', 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'uob-one' => [
            'bank' => 'uob',
            'name' => 'UOB One',
            'match' => 'uob one',
            'tiers' => [
                [
                    'name' => 'Toàn bộ chi tiêu hợp lệ',
                    'min' => 0,
                    'max' => null,
                    'cap' => '600000.00',
                    'fallback' => ['percent' => '0.300', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'goi-xe-di-chuyen', 'percent' => '10.000', 'counts_cap' => true],
                        ['cat' => 'ung-dung-so-dich-vu-giai-tri', 'percent' => '5.000', 'counts_cap' => true],
                        ['cat' => 'bao-hiem', 'percent' => '3.000', 'counts_cap' => true],
                        ['cat' => 'thoi-trang', 'percent' => '2.000', 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'vpbank-flex' => [
            'bank' => 'vpb',
            'name' => 'VPBank Flex Mastercard',
            'match' => 'flex',
            'tiers' => [
                [
                    'name' => 'Bậc 1 – Dưới 2 triệu',
                    'min' => 0,
                    'max' => 2000000,
                    'cap' => null,
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [],
                ],
                [
                    'name' => 'Bậc 2 – Từ 2 triệu',
                    'min' => 2000000,
                    'max' => null,
                    'cap' => '500000.00',
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'am-thuc-an-uong', 'percent' => '8.000', 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'shinhan-365-cashback-platinum' => [
            'bank' => 'shbvn',
            'name' => 'Shinhan 365 Cashback Platinum',
            'match' => '365 cashback',
            'tiers' => [
                [
                    'name' => 'Bậc 1 – Dưới 3 triệu',
                    'min' => 0,
                    'max' => 3000000,
                    'cap' => null,
                    'fallback' => ['percent' => '0.400', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [],
                ],
                [
                    'name' => 'Bậc 2 – Từ 3 triệu',
                    'min' => 3000000,
                    'max' => null,
                    'cap' => '400000.00',
                    'fallback' => ['percent' => '0.400', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'sieu-thi-tien-loi', 'percent' => '10.000', 'counts_cap' => true],
                        ['cat' => 'chi-tieu-nuoc-ngoai', 'percent' => '3.000', 'counts_cap' => false, 'cap_cat' => 200000],
                    ],
                ],
            ],
        ],
        'shinhan-mylife-visa-bach-kim' => [
            'bank' => 'shbvn',
            'name' => 'Shinhan MyLife Visa - Bạch Kim',
            'match' => 'mylife',
            'tiers' => [
                [
                    'name' => 'Bậc 1 – Dưới 3 triệu',
                    'min' => 0,
                    'max' => 3000000,
                    'cap' => null,
                    'fallback' => ['percent' => '0.400', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [],
                ],
                [
                    'name' => 'Bậc 2 – Từ 3 triệu',
                    'min' => 3000000,
                    'max' => null,
                    'cap' => null,
                    'fallback' => ['percent' => '0.400', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'goi-xe-di-chuyen', 'percent' => '10.000', 'cap_cat' => 300000],
                        ['cat' => 'sieu-thi-tien-loi', 'percent' => '10.000', 'cap_cat' => 300000],
                        ['cat' => 'am-thuc-an-uong', 'percent' => '10.000', 'cap_cat' => 300000],
                        ['cat' => 'ung-dung-so-dich-vu-giai-tri', 'percent' => '10.000', 'cap_cat' => 300000],
                    ],
                ],
            ],
        ],
        'cake-2in1-premium' => [
            'bank' => 'cakevpb',
            'name' => 'Thẻ Cake 2in1 – Gói Premium',
            'match' => 'cake 2in1',
            'tiers' => [
                [
                    'name' => 'Bậc 1 – Dưới 20 triệu',
                    'min' => 0,
                    'max' => 20000000,
                    'cap' => null,
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [],
                ],
                [
                    'name' => 'Bậc 2 – Từ 20 triệu',
                    'min' => 20000000,
                    'max' => null,
                    'cap' => '2000000.00',
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'giao-duc-hoc-phi', 'percent' => '20.000', 'cap_tx' => 200000, 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'vpbank-s-rewards-mastercard' => [
            'bank' => 'vpb',
            'name' => 'VPBank S Rewards Mastercard',
            'match' => 's rewards',
            'tiers' => [
                [
                    'name' => 'Bậc 1 – Dưới 5 triệu',
                    'min' => 0,
                    'max' => 5000000,
                    'cap' => null,
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [],
                ],
                [
                    'name' => 'Bậc 2 – Từ 5 triệu',
                    'min' => 5000000,
                    'max' => null,
                    'cap' => null,
                    'fallback' => ['percent' => '0.100', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'shopee', 'percent' => '12.000', 'cap_cat' => 400000, 'counts_cap' => true],
                        ['combo' => 'vpb-s-rewards-uu-dai', 'percent' => '4.000', 'cap_cat' => 200000, 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'hsbc-cash-back' => [
            'bank' => 'hsbc',
            'name' => 'HSBC Cash Back',
            'match' => 'cash back',
            'tiers' => [
                [
                    'name' => 'Toàn bộ chi tiêu hợp lệ',
                    'min' => 0,
                    'max' => null,
                    'cap' => null,
                    'fallback' => ['percent' => '0.500', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'sieu-thi-tien-loi', 'percent' => '6.000', 'cap_cat' => 200000],
                        ['cat' => 'bao-hiem', 'percent' => '1.000'],
                        ['cat' => 'giao-duc-hoc-phi', 'percent' => '1.000'],
                    ],
                ],
            ],
        ],
        'bidv-visa-cashback-360' => [
            'bank' => 'bidv',
            'name' => 'BIDV Visa Cashback 360',
            'match' => 'cashback 360',
            'tiers' => [
                [
                    'name' => 'Toàn bộ chi tiêu hợp lệ',
                    'min' => 0,
                    'max' => null,
                    'cap' => '500000.00',
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'sieu-thi-tien-loi', 'from' => 0, 'to' => 10000000, 'percent' => '2.000', 'cap_cat' => 300000, 'counts_cap' => true],
                        ['cat' => 'sieu-thi-tien-loi', 'from' => 10000000, 'to' => null, 'percent' => '10.000', 'cap_cat' => 300000, 'counts_cap' => true],
                        ['cat' => 'y-te-benh-vien', 'percent' => '2.000', 'cap_cat' => 300000, 'counts_cap' => true],
                        ['cat' => 'giao-duc-hoc-phi', 'percent' => '2.000', 'cap_cat' => 300000, 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'tpb-evo' => [
            'bank' => 'tpb',
            'name' => 'TPBank EVO',
            'match' => 'evo',
            'tiers' => [
                [
                    'name' => 'Bậc 1 – Dưới 10 triệu',
                    'min' => 0,
                    'max' => 10000000,
                    'cap' => '500000.00',
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['combo' => 'tpb-evo-online', 'percent' => '1.000', 'counts_cap' => true],
                    ],
                ],
                [
                    'name' => 'Bậc 2 – Từ 10 đến 30 triệu',
                    'min' => 10000000,
                    'max' => 30000000,
                    'cap' => '500000.00',
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['combo' => 'tpb-evo-online', 'percent' => '5.000', 'counts_cap' => true],
                    ],
                ],
                [
                    'name' => 'Bậc 3 – Từ 30 triệu',
                    'min' => 30000000,
                    'max' => null,
                    'cap' => '500000.00',
                    'fallback' => ['percent' => '0.000', 'counts_cap' => false, 'min_tx' => null],
                    'rules' => [
                        ['combo' => 'tpb-evo-online', 'percent' => '10.000', 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'vib-online-plus-2in1' => [
            'bank' => 'vib',
            'name' => 'VIB Online Plus 2in1',
            'match' => 'online plus 2in1',
            'tiers' => [
                [
                    'name' => 'Toàn bộ chi tiêu hợp lệ',
                    'min' => 0,
                    'max' => null,
                    'cap' => '600000.00',
                    'fallback' => ['percent' => '0.100', 'counts_cap' => true, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'chi-tieu-nuoc-ngoai', 'percent' => '5.000', 'counts_cap' => true],
                        ['combo' => 'vib-online', 'percent' => '3.000', 'counts_cap' => true],
                    ],
                ],
            ],
        ],
        'ocb-jcb-platinum' => [
            'bank' => 'ocb',
            'name' => 'OCB JCB Platinum',
            'match' => 'jcb platinum',
            'tiers' => [
                [
                    'name' => 'Bậc 1 – Dưới 30 triệu',
                    'min' => 0,
                    'max' => 30000000,
                    'cap' => '700000.00',
                    'fallback' => ['percent' => '0.100', 'counts_cap' => true, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'y-te-benh-vien', 'percent' => '7.000', 'cap_cat' => 150000, 'counts_cap' => true],
                        ['cat' => 'bao-hiem', 'percent' => '7.000', 'cap_cat' => 150000, 'counts_cap' => true],
                    ],
                ],
                [
                    'name' => 'Bậc 2 – Từ 30 triệu',
                    'min' => 30000000,
                    'max' => null,
                    'cap' => '1500000.00',
                    'fallback' => ['percent' => '0.100', 'counts_cap' => true, 'min_tx' => null],
                    'rules' => [
                        ['cat' => 'y-te-benh-vien', 'percent' => '15.000', 'cap_cat' => 300000, 'counts_cap' => true],
                        ['cat' => 'bao-hiem', 'percent' => '15.000', 'cap_cat' => 300000, 'counts_cap' => true],
                    ],
                ],
            ],
        ],
    ];

    /** Các sản phẩm CỐ TÌNH không có template (chặn ở seeder, ghi nhận ở báo cáo). */
    private const NOT_SEEDED_SLUGS = [
        'vpbank-reward-visa',
        'shinhan-cashback-visa',
    ];

    private CreditCardSeeder $creditCardSeeder;

    private PolicyCloneService $cloner;

    private PolicyService $policies;

    private CashbackRecordService $records;

    private StatementPeriodService $periods;

    private User $user;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->creditCardSeeder = app(CreditCardSeeder::class);
        $this->cloner = app(PolicyCloneService::class);
        $this->policies = app(PolicyService::class);
        $this->records = app(CashbackRecordService::class);
        $this->periods = app(StatementPeriodService::class);

        $this->user = User::factory()->create();
    }

    // =====================================================================
    // A · Cấu trúc seeder
    // =====================================================================

    #[Test]
    public function seeder_creates_the_researched_templates_with_applicability(): void
    {
        $this->creditCardSeeder->run();

        $this->assertSame(14, count(self::PLANS));

        foreach (self::PLANS as $slug => $plan) {
            $template = $this->seededTemplate($slug);

            $this->assertSame($plan['name'], $template->name);
            $this->assertSame(PolicyTemplate::SCOPE_SYSTEM, $template->scope);
            $this->assertSame(PolicyTemplate::SYSTEM_OWNER_ID, (int) $template->owner_user_id);
            $this->assertTrue((bool) $template->is_builtin);
            $this->assertTrue((bool) $template->is_active);
            $this->assertSame((int) $this->bankBySlug($plan['bank'])->id, (int) $template->applicable_bank_id);
            $this->assertSame($plan['match'], $template->applicable_product_match);

            $blueprint = $template->blueprint()->first();
            $this->assertNotNull($blueprint);
            $this->assertNull($blueprint->user_card_id);
            $this->assertSame(1, (int) $blueprint->version_no);
            $this->assertSame('0.00', $blueprint->min_total_spend);
            $this->assertNull($blueprint->max_cashback_total_per_period, 'Trần toàn bậc nằm ở TIER.');
            $this->assertSame('round', $blueprint->rounding_mode);
        }
    }

    #[Test]
    public function each_template_has_the_expected_tiers_rules_and_fallback(): void
    {
        $this->creditCardSeeder->run();

        foreach (self::PLANS as $slug => $plan) {
            $tiers = $this->seededTemplate($slug)
                ->blueprint()->firstOrFail()
                ->tiers()->orderBy('sort_order')->orderBy('id')->get();

            $this->assertSame(count($plan['tiers']), $tiers->count(), "{$slug}: số bậc đúng cấu hình.");

            foreach ($plan['tiers'] as $index => $expected) {
                $tier = $tiers[$index];
                $asMoney = fn ($value) => $value === null ? null : (float) $value;

                $this->assertSame($expected['name'], $tier->name);
                $this->assertSame($asMoney($expected['min']), $asMoney($tier->min_total_spend));
                $this->assertSame($asMoney($expected['max']), $asMoney($tier->max_total_spend));
                $this->assertSame($expected['cap'], $tier->max_cashback_per_period);

                $rules = $tier->tierCategoryRules()->orderBy('sort_order')->orderBy('id')->get();
                $fallback = $rules->filter(fn (PolicyTierCategory $r): bool => $r->isFallback())->first();
                $targets = $rules->reject(fn (PolicyTierCategory $r): bool => $r->isFallback())->values();

                $this->assertNotNull($fallback, "{$slug} bậc {$index}: phải có đúng 1 fallback.");
                $this->assertSame($expected['fallback']['percent'], $fallback->cashback_percent);
                $this->assertSame($expected['fallback']['counts_cap'], (bool) $fallback->counts_toward_tier_cap);
                $this->assertSame(
                    $asMoney($expected['fallback']['min_tx']),
                    $asMoney($fallback->min_transaction_amount),
                );
                $this->assertNull($fallback->category_id);
                $this->assertNull($fallback->combo_id);
                $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallback->scope_type);
                $this->assertSame(
                    $targets->max(fn (PolicyTierCategory $r): int => (int) $r->sort_order) + 1,
                    (int) $fallback->sort_order,
                    'Fallback luôn đứng cuối bậc.',
                );

                $this->assertSame(count($expected['rules']), $targets->count(), "{$slug} bậc {$index}: số rule ưu đãi.");

                foreach ($expected['rules'] as $offset => $rule) {
                    $model = $targets[$offset];

                    $this->assertSame($asMoney($rule['from'] ?? 0), $asMoney($model->spend_from));
                    $this->assertSame($asMoney($rule['to'] ?? null), $asMoney($model->spend_to));

                    if (isset($rule['combo'])) {
                        // Rule combo: nhiều danh mục chia sẻ chung một trần.
                        $this->assertNull($model->category_id);
                        $this->assertSame($this->comboBySlug($rule['combo'])->id, (int) $model->combo_id);
                    } else {
                        $this->assertSame($this->categoryBySlug($rule['cat'])->id, (int) $model->category_id);
                        $this->assertNull($model->combo_id);
                    }

                    $this->assertSame($rule['percent'], $model->cashback_percent);
                    $this->assertSame($rule['counts_cap'] ?? false, (bool) $model->counts_toward_tier_cap);
                    $this->assertSame($asMoney($rule['cap_tx'] ?? null), $asMoney($model->max_cashback_per_transaction));
                    $this->assertSame($asMoney($rule['cap_cat'] ?? null), $asMoney($model->max_cashback_per_category_per_period));
                    $this->assertSame($asMoney($rule['min_tx'] ?? null), $asMoney($model->min_transaction_amount));
                }
            }
        }
    }

    #[Test]
    public function reporter_flagged_products_are_not_seeded(): void
    {
        $this->creditCardSeeder->run();

        foreach (self::NOT_SEEDED_SLUGS as $slug) {
            $this->assertNull(
                PolicyTemplate::query()->system()->where('slug', $slug)->first(),
                "Sản phẩm nghi ngờ ({$slug}) không được tạo template."
            );
        }
    }

    #[Test]
    public function seeder_is_idempotent_for_researched_templates(): void
    {
        $this->creditCardSeeder->run();
        $this->creditCardSeeder->run();

        foreach (self::PLANS as $slug => $plan) {
            $this->assertSame(
                1,
                PolicyTemplate::query()->system()->where('slug', $slug)->count(),
                "Chạy lại không được tạo template thứ hai ({$slug})."
            );

            $template = $this->seededTemplate($slug);
            $this->assertSame(1, $template->blueprints()->count());

            foreach ($plan['tiers'] as $index => $expected) {
                $tier = $template->blueprint()->firstOrFail()->tiers()->orderBy('sort_order')->orderBy('id')->get()[$index];
                $this->assertSame(1, $tier->tierCategoryRules()->fallback()->count());
                $this->assertSame(1 + count($expected['rules']), $tier->tierCategoryRules()->count());
            }
        }
    }

    // =====================================================================
    // B · Áp dụng sản phẩm
    // =====================================================================

    #[Test]
    public function researched_templates_attach_to_matching_cards_by_bank_and_product_keyword(): void
    {
        $this->creditCardSeeder->run();

        foreach (self::PLANS as $slug => $plan) {
            $bank = $this->bankBySlug($plan['bank']);

            $card = $this->makeUserCard($this->user->id, [
                'bank_id' => $bank->id,
                'name' => $plan['name'],
            ]);

            $version = $this->cloner->attachTemplateToCard(
                $card,
                $this->seededTemplate($slug),
                CarbonImmutable::parse('2026-10-01'),
            );

            $this->assertNotNull($version);
            $this->assertSame((int) $card->id, (int) $version->user_card_id);
            $this->assertSame(1, (int) $version->version_no);
            $this->assertSame((int) $version->id, (int) $card->refresh()->current_policy_id);
        }
    }

    #[Test]
    public function mismatched_bank_or_product_name_is_rejected_for_researched_templates(): void
    {
        $this->creditCardSeeder->run();

        $template = $this->seededTemplate('sacombank-platinum-amex');
        $effective = CarbonImmutable::parse('2026-10-01');

        // Sai ngân hàng (có đúng tên sản phẩm).
        $wrongBankCard = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->bankBySlug('mb')->id,
            'name' => 'Sacombank Platinum American Express',
        ]);

        // Đúng ngân hàng nhưng tên không chứa từ khoá sản phẩm.
        $wrongProductCard = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->bankBySlug('stb')->id,
            'name' => 'Sacombank Uniq Visa',
        ]);

        try {
            $this->policies->cloneUserTemplate($wrongBankCard, (int) $template->id, $effective);
            $this->fail('Template Sacombank Amex không được gắn cho thẻ ngân hàng khác.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('không áp dụng', $e->getMessage());
        }

        try {
            $this->policies->cloneUserTemplate($wrongProductCard, (int) $template->id, $effective);
            $this->fail('Template Sacombank Amex không được gắn cho thẻ Sacombank không phải dòng Platinum Amex.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('không áp dụng', $e->getMessage());
        }

        $this->assertSame(0, Policy::query()->whereIn('user_card_id', [$wrongBankCard->id, $wrongProductCard->id])->count());
    }

    // =====================================================================
    // C · Hành vi engine (CashbackRecordService thật)
    // =====================================================================

    #[Test]
    public function sacombank_visa_cashback_applies_16_8_and_6_8_percent_with_039_fallback_and_min_transaction(): void
    {
        $card = $this->makeCardWithTemplate('sacombank-visa-platinum-cashback');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '3000000', $this->categoryBySlug('goi-xe-di-chuyen'));
        $this->addTxn($card, $period, '2026-10-06', '2000000', $this->categoryBySlug('sieu-thi-tien-loi'));
        $this->addTxn($card, $period, '2026-10-07', '1000000', $this->categoryBySlug('xang-dau'));
        $small = $this->addTxn($card, $period, '2026-10-08', '50000', $this->categoryBySlug('sieu-thi-tien-loi'));

        $this->records->calculatePeriod($card, $period);

        // Di chuyển 3tr × 16,8% = 504.000; Siêu thị 2tr × 6,8% = 136.000;
        // nền 0,39% cho xăng dầu 1tr = 3.900. Giao dịch 50k dưới mức
        // 100.000đ được hoàn ⇒ không sinh hoàn tiền.
        $this->assertSame('16.800', $this->snapshotPercent($card, $period, 'goi-xe-di-chuyen'));
        $this->assertSame('504000.00', $this->snapshotAmount($card, $period, 'goi-xe-di-chuyen'));
        $this->assertSame('6.800', $this->snapshotPercent($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('136000.00', $this->snapshotAmount($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('0.390', $this->snapshotPercent($card, $period, 'xang-dau'));
        $this->assertSame('3900.00', $this->snapshotAmount($card, $period, 'xang-dau'));

        $this->assertSame('0.00', $small->refresh()->cashback_amount_snapshot, 'Giao dịch dưới 100.000đ không được hoàn.');
        $this->assertSame('643900.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function sacombank_visa_cashback_caps_the_bonus_group_at_680000_per_period(): void
    {
        $card = $this->makeCardWithTemplate('sacombank-visa-platinum-cashback');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '4000000', $this->categoryBySlug('goi-xe-di-chuyen'));
        $this->addTxn($card, $period, '2026-10-06', '1000000', $this->categoryBySlug('sieu-thi-tien-loi'));

        $this->records->calculatePeriod($card, $period);

        // 4tr × 16,8% = 672.000 + 1tr × 6,8% = 68.000 ⇒ trần chung 680.000.
        $this->assertSame('680000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function amex_applies_travel_and_foreign_rates_and_enforces_the_1m_total_cap(): void
    {
        $this->creditCardSeeder->run();

        // Cận dưới trần: du lịch 1tr (20%) + nước ngoài 1tr (10%) + nền 1tr (0,5%).
        $card = $this->makeCardWithTemplate('sacombank-platinum-amex');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '1000000', $this->categoryBySlug('ve-may-bay-du-lich'));
        $this->addTxn($card, $period, '2026-10-06', '1000000', $this->categoryBySlug('chi-tieu-nuoc-ngoai'));
        $this->addTxn($card, $period, '2026-10-07', '1000000', $this->categoryBySlug('xang-dau'));

        $this->records->calculatePeriod($card, $period);
        $period->refresh();

        $this->assertSame('200000.00', $this->snapshotAmount($card, $period, 've-may-bay-du-lich'));
        $this->assertSame('100000.00', $this->snapshotAmount($card, $period, 'chi-tieu-nuoc-ngoai'));
        $this->assertSame('5000.00', $this->snapshotAmount($card, $period, 'xang-dau'));
        $this->assertSame('305000.00', $period->total_cashback);

        // Vượt trần: du lịch 6tr × 20% = 1,2tr thô ⇒ khoá ở 1.000.000 (trần cả nền).
        $card2 = $this->makeCardWithTemplate('sacombank-platinum-amex');
        $period2 = $this->periods->resolvePeriodForDate($card2, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card2, $period2, '2026-10-05', '6000000', $this->categoryBySlug('khach-san-luu-tru'));

        $this->records->calculatePeriod($card2, $period2);

        $this->assertSame('20.000', $this->snapshotPercent($card2, $period2, 'khach-san-luu-tru'));
        $this->assertSame('1000000.00', $period2->refresh()->total_cashback);
    }

    #[Test]
    public function mb_jcb_platinum_switches_rate_by_period_total_spend(): void
    {
        $this->creditCardSeeder->run();

        // Dưới 5 triệu/kỳ ⇒ bậc 1: 5%.
        $card = $this->makeCardWithTemplate('mb-jcb-platinum');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '1000000', $this->categoryBySlug('am-thuc-an-uong'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('5.000', $this->snapshotPercent($card, $period, 'am-thuc-an-uong'));
        $this->assertSame('50000.00', $period->refresh()->total_cashback);

        // Từ 5 triệu/kỳ (5tr đúng ngưỡng) ⇒ bậc 2: 10%, trần 500.000.
        $card2 = $this->makeCardWithTemplate('mb-jcb-platinum');
        $period2 = $this->periods->resolvePeriodForDate($card2, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card2, $period2, '2026-10-05', '3000000', $this->categoryBySlug('am-thuc-an-uong'));
        $this->addTxn($card2, $period2, '2026-10-06', '2000000', $this->categoryBySlug('sieu-thi-tien-loi'));

        $this->records->calculatePeriod($card2, $period2);

        $this->assertSame('10.000', $this->snapshotPercent($card2, $period2, 'am-thuc-an-uong'));
        $this->assertSame('10.000', $this->snapshotPercent($card2, $period2, 'sieu-thi-tien-loi'));
        $this->assertSame('500000.00', $period2->refresh()->total_cashback);
    }

    #[Test]
    public function shinhan_365_keeps_the_040_percent_base_below_the_spend_threshold(): void
    {
        // Dưới 3 triệu/kỳ ⇒ chỉ có nền 0,4% (siêu thị chưa được nhóm ưu đãi).
        $card = $this->makeCardWithTemplate('shinhan-365-cashback-platinum');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '1000000', $this->categoryBySlug('sieu-thi-tien-loi'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('0.400', $this->snapshotPercent($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('4000.00', $this->snapshotAmount($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('4000.00', $period->refresh()->total_cashback);

        // Từ 3 triệu/kỳ ⇒ siêu thị nhận 10%.
        $card2 = $this->makeCardWithTemplate('shinhan-365-cashback-platinum');
        $period2 = $this->periods->resolvePeriodForDate($card2, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card2, $period2, '2026-10-05', '3000000', $this->categoryBySlug('sieu-thi-tien-loi'));

        $this->records->calculatePeriod($card2, $period2);

        $this->assertSame('10.000', $this->snapshotPercent($card2, $period2, 'sieu-thi-tien-loi'));
        $this->assertSame('300000.00', $period2->refresh()->total_cashback);
    }

    // =====================================================================
    // D · Hành vi engine cho 6 template bổ sung (12/10/2026)
    //
    // Mỗi test khẳng định ĐÚNG hành vi engine đọc từ blueprint đã seed. Các
    // test có tên "..._documented_limit" cố định một GIỚI HẠN đã biết của
    // engine (không phải bug): chúng mô tả engine xấp xỉ chính sách thật tới
    // đâu, để không ai tưởng nhầm là đã hỗ trợ đầy đủ.
    // =====================================================================

    #[Test]
    public function vpbank_s_rewards_pays_nothing_below_5m_and_applies_shopee_12_and_combo_4_above(): void
    {
        // Dưới 5 triệu/kỳ ⇒ bậc 1 (không rule), fallback 0% ⇒ KHÔNG hoàn.
        $below = $this->makeCardWithTemplate('vpbank-s-rewards-mastercard');
        $periodBelow = $this->periods->resolvePeriodForDate($below, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($below, $periodBelow, '2026-10-05', '2000000', $this->categoryBySlug('am-thuc-an-uong'));

        $this->records->calculatePeriod($below, $periodBelow);

        $this->assertSame('0.000', $this->snapshotPercent($below, $periodBelow, 'am-thuc-an-uong'));
        $this->assertSame('0.00', $this->snapshotAmount($below, $periodBelow, 'am-thuc-an-uong'));
        $this->assertSame('0.00', $periodBelow->refresh()->total_cashback, 'Dưới 5 triệu không hoàn.');

        // Đúng ngưỡng 5 triệu/kỳ ⇒ bậc 2: Shopee 12%, combo (Siêu thị/Ăn uống/
        // Di chuyển) 4%, còn lại 0,1%.
        $card = $this->makeCardWithTemplate('vpbank-s-rewards-mastercard');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '1000000', $this->categoryBySlug('shopee'));
        $this->addTxn($card, $period, '2026-10-06', '1000000', $this->categoryBySlug('am-thuc-an-uong'));
        $this->addTxn($card, $period, '2026-10-07', '2000000', $this->categoryBySlug('goi-xe-di-chuyen'));
        $this->addTxn($card, $period, '2026-10-08', '1000000', $this->categoryBySlug('xang-dau'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('12.000', $this->snapshotPercent($card, $period, 'shopee'));
        $this->assertSame('120000.00', $this->snapshotAmount($card, $period, 'shopee'));
        $this->assertSame('4.000', $this->snapshotPercent($card, $period, 'am-thuc-an-uong'));
        $this->assertSame('40000.00', $this->snapshotAmount($card, $period, 'am-thuc-an-uong'));
        $this->assertSame('4.000', $this->snapshotPercent($card, $period, 'goi-xe-di-chuyen'));
        $this->assertSame('80000.00', $this->snapshotAmount($card, $period, 'goi-xe-di-chuyen'));
        $this->assertSame('0.100', $this->snapshotPercent($card, $period, 'xang-dau'));
        $this->assertSame('1000.00', $this->snapshotAmount($card, $period, 'xang-dau'));
        $this->assertSame('241000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function vpbank_s_rewards_caps_shopee_at_400k(): void
    {
        $card = $this->makeCardWithTemplate('vpbank-s-rewards-mastercard');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '5000000', $this->categoryBySlug('shopee'));

        $this->records->calculatePeriod($card, $period);

        // 5tr × 12% = 600.000 thô ⇒ trần riêng Shopee 400.000.
        $this->assertSame('12.000', $this->snapshotPercent($card, $period, 'shopee'));
        $this->assertSame('400000.00', $this->snapshotAmount($card, $period, 'shopee'));
        $this->assertSame('400000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function vpbank_s_rewards_combo_group_cap_is_enforced_per_category_documented_limit(): void
    {
        // GIỚI HẠN ĐÃ BIẾT: chính sách thật đặt TRẦN CHUNG 200.000 xu/kỳ cho cả
        // nhóm 4% (Siêu thị + Ăn uống + Di chuyển). Engine áp `cap_cat` theo TỪNG
        // danh mục (Cap 2 ở CashbackCalculator dùng categoryId), nên tổng combo có
        // thể vượt trần nhóm. Test này cố định hành vi đó — KHÔNG phải khẳng định
        // chính sách đúng.
        $card = $this->makeCardWithTemplate('vpbank-s-rewards-mastercard');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '6000000', $this->categoryBySlug('am-thuc-an-uong'));
        $this->addTxn($card, $period, '2026-10-06', '6000000', $this->categoryBySlug('goi-xe-di-chuyen'));

        $this->records->calculatePeriod($card, $period);

        // Mỗi danh mục tự khoá ở 200.000 ⇒ tổng 400.000 (vượt trần nhóm 200.000).
        $this->assertSame('200000.00', $this->snapshotAmount($card, $period, 'am-thuc-an-uong'));
        $this->assertSame('200000.00', $this->snapshotAmount($card, $period, 'goi-xe-di-chuyen'));
        $this->assertSame('400000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function hsbc_cash_back_applies_supermarket_bonus_insurance_education_and_05_fallback(): void
    {
        $card = $this->makeCardWithTemplate('hsbc-cash-back');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '1000000', $this->categoryBySlug('sieu-thi-tien-loi'));
        $this->addTxn($card, $period, '2026-10-06', '1000000', $this->categoryBySlug('bao-hiem'));
        $this->addTxn($card, $period, '2026-10-07', '1000000', $this->categoryBySlug('giao-duc-hoc-phi'));
        $this->addTxn($card, $period, '2026-10-08', '1000000', $this->categoryBySlug('xang-dau'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('6.000', $this->snapshotPercent($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('60000.00', $this->snapshotAmount($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('1.000', $this->snapshotPercent($card, $period, 'bao-hiem'));
        $this->assertSame('10000.00', $this->snapshotAmount($card, $period, 'bao-hiem'));
        $this->assertSame('1.000', $this->snapshotPercent($card, $period, 'giao-duc-hoc-phi'));
        $this->assertSame('10000.00', $this->snapshotAmount($card, $period, 'giao-duc-hoc-phi'));
        $this->assertSame('0.500', $this->snapshotPercent($card, $period, 'xang-dau'));
        $this->assertSame('5000.00', $this->snapshotAmount($card, $period, 'xang-dau'));
        $this->assertSame('85000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function hsbc_cash_back_caps_supermarket_at_200k_and_ignores_salary_tier_documented_limit(): void
    {
        // GIỚI HẠN ĐÃ BIẾT: HSBC 8% nếu nhận lương qua HSBC không có cờ dữ liệu
        // ⇒ engine luôn áp 6%. Trần cũng được hiểu theo KỲ SAO KÊ (engine) chứ
        // không theo THÁNG DƯƠNG LỊCH (chính sách).
        $card = $this->makeCardWithTemplate('hsbc-cash-back');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '5000000', $this->categoryBySlug('sieu-thi-tien-loi'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('6.000', $this->snapshotPercent($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('200000.00', $this->snapshotAmount($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('200000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function bidv_cashback_360_uses_2pct_supermarket_2pct_health_and_education_below_10m(): void
    {
        $card = $this->makeCardWithTemplate('bidv-visa-cashback-360');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '3000000', $this->categoryBySlug('sieu-thi-tien-loi'));
        $this->addTxn($card, $period, '2026-10-06', '1000000', $this->categoryBySlug('y-te-benh-vien'));
        $this->addTxn($card, $period, '2026-10-07', '1000000', $this->categoryBySlug('giao-duc-hoc-phi'));
        $this->addTxn($card, $period, '2026-10-08', '1000000', $this->categoryBySlug('xang-dau'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('2.000', $this->snapshotPercent($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('60000.00', $this->snapshotAmount($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('2.000', $this->snapshotPercent($card, $period, 'y-te-benh-vien'));
        $this->assertSame('20000.00', $this->snapshotAmount($card, $period, 'y-te-benh-vien'));
        $this->assertSame('2.000', $this->snapshotPercent($card, $period, 'giao-duc-hoc-phi'));
        $this->assertSame('20000.00', $this->snapshotAmount($card, $period, 'giao-duc-hoc-phi'));
        $this->assertSame('0.000', $this->snapshotPercent($card, $period, 'xang-dau'));
        $this->assertSame('100000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function bidv_cashback_360_switches_supermarket_band_to_10pct_at_exactly_10m(): void
    {
        $card = $this->makeCardWithTemplate('bidv-visa-cashback-360');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '10000000', $this->categoryBySlug('sieu-thi-tien-loi'));

        $this->records->calculatePeriod($card, $period);

        // Doanh số siêu thị đúng 10 triệu ⇒ rơi vào dải [10tr, ∞) → 10%.
        // 10tr × 10% = 1.000.000 thô ⇒ trần nhóm 300.000.
        $this->assertSame('10.000', $this->snapshotPercent($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('300000.00', $this->snapshotAmount($card, $period, 'sieu-thi-tien-loi'));
        $this->assertSame('300000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function bidv_cashback_360_enforces_the_500k_per_period_total_cap(): void
    {
        $card = $this->makeCardWithTemplate('bidv-visa-cashback-360');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '20000000', $this->categoryBySlug('y-te-benh-vien'));
        $this->addTxn($card, $period, '2026-10-06', '20000000', $this->categoryBySlug('giao-duc-hoc-phi'));

        $this->records->calculatePeriod($card, $period);

        // Y tế: 20tr × 2% = 400.000 thô ⇒ trần nhóm 300.000.
        // Giáo dục: 400.000 thô ⇒ trần nhóm 300.000, rồi trần kỳ còn 500.000-300.000
        // = 200.000 ⇒ còn 200.000. Tổng kỳ = 500.000.
        $this->assertSame('300000.00', $this->snapshotAmount($card, $period, 'y-te-benh-vien'));
        $this->assertSame('200000.00', $this->snapshotAmount($card, $period, 'giao-duc-hoc-phi'));
        $this->assertSame('500000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function tpb_evo_switches_online_rate_by_period_spend_and_caps_at_500k(): void
    {
        // Bậc 1 (< 10 triệu/kỳ): 1%.
        $card = $this->makeCardWithTemplate('tpb-evo');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '5000000', $this->categoryBySlug('shopee'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('1.000', $this->snapshotPercent($card, $period, 'shopee'));
        $this->assertSame('50000.00', $period->refresh()->total_cashback);

        // Bậc 2 (10–30 triệu/kỳ): 5%, trần 500.000.
        $card2 = $this->makeCardWithTemplate('tpb-evo');
        $period2 = $this->periods->resolvePeriodForDate($card2, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card2, $period2, '2026-10-05', '10000000', $this->categoryBySlug('shopee'));

        $this->records->calculatePeriod($card2, $period2);

        $this->assertSame('5.000', $this->snapshotPercent($card2, $period2, 'shopee'));
        $this->assertSame('500000.00', $period2->refresh()->total_cashback);
    }

    #[Test]
    public function tpb_evo_applies_10pct_at_30m_and_caps_at_500k(): void
    {
        $card = $this->makeCardWithTemplate('tpb-evo');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '30000000', $this->categoryBySlug('shopee'));

        $this->records->calculatePeriod($card, $period);

        // 30tr × 10% = 3.000.000 thô ⇒ trần kỳ 500.000.
        $this->assertSame('10.000', $this->snapshotPercent($card, $period, 'shopee'));
        $this->assertSame('500000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function tpb_evo_only_rewards_the_approximated_online_combo_documented_limit(): void
    {
        // GIỚI HẠN ĐÃ BIẾT: TPBank EVO hoàn theo KÊNH trực tuyến; engine không có
        // cờ kênh nên xấp xỉ bằng combo danh mục TMĐT `tpb-evo-online`. Danh mục
        // không nằm trong combo (vd. thời trang mua online) rơi về fallback 0%.
        $card = $this->makeCardWithTemplate('tpb-evo');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '5000000', $this->categoryBySlug('shopee'));
        $this->addTxn($card, $period, '2026-10-06', '5000000', $this->categoryBySlug('thoi-trang'));

        $this->records->calculatePeriod($card, $period);

        // Tổng 10tr ⇒ bậc 2: Shopee (trong combo) 5%; thời trang fallback 0%.
        $this->assertSame('5.000', $this->snapshotPercent($card, $period, 'shopee'));
        $this->assertSame('250000.00', $this->snapshotAmount($card, $period, 'shopee'));
        $this->assertSame('0.000', $this->snapshotPercent($card, $period, 'thoi-trang'));
        $this->assertSame('0.00', $this->snapshotAmount($card, $period, 'thoi-trang'));
        $this->assertSame('250000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function vib_online_plus_applies_foreign_5_online_3_and_01_fallback(): void
    {
        $card = $this->makeCardWithTemplate('vib-online-plus-2in1');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '1000000', $this->categoryBySlug('chi-tieu-nuoc-ngoai'));
        $this->addTxn($card, $period, '2026-10-06', '1000000', $this->categoryBySlug('shopee'));
        $this->addTxn($card, $period, '2026-10-07', '1000000', $this->categoryBySlug('xang-dau'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('5.000', $this->snapshotPercent($card, $period, 'chi-tieu-nuoc-ngoai'));
        $this->assertSame('50000.00', $this->snapshotAmount($card, $period, 'chi-tieu-nuoc-ngoai'));
        $this->assertSame('3.000', $this->snapshotPercent($card, $period, 'shopee'));
        $this->assertSame('30000.00', $this->snapshotAmount($card, $period, 'shopee'));
        $this->assertSame('0.100', $this->snapshotPercent($card, $period, 'xang-dau'));
        $this->assertSame('1000.00', $this->snapshotAmount($card, $period, 'xang-dau'));
        $this->assertSame('81000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function vib_online_plus_enforces_600k_per_period_cap_across_all_rewarded_spend(): void
    {
        $card = $this->makeCardWithTemplate('vib-online-plus-2in1');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '12000000', $this->categoryBySlug('chi-tieu-nuoc-ngoai'));
        $this->addTxn($card, $period, '2026-10-06', '1000000', $this->categoryBySlug('shopee'));

        $this->records->calculatePeriod($card, $period);

        // Nước ngoài 12tr × 5% = 600.000 ⇒ chạm trần kỳ 600.000 (mọi rule đều
        // counts_toward_tier_cap) ⇒ Shopee 3% còn 0.
        $this->assertSame('600000.00', $this->snapshotAmount($card, $period, 'chi-tieu-nuoc-ngoai'));
        $this->assertSame('0.00', $this->snapshotAmount($card, $period, 'shopee'));
        $this->assertSame('600000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function ocb_jcb_platinum_applies_7pct_health_and_insurance_below_30m(): void
    {
        $card = $this->makeCardWithTemplate('ocb-jcb-platinum');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '1000000', $this->categoryBySlug('y-te-benh-vien'));
        $this->addTxn($card, $period, '2026-10-06', '1000000', $this->categoryBySlug('bao-hiem'));
        $this->addTxn($card, $period, '2026-10-07', '1000000', $this->categoryBySlug('xang-dau'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('7.000', $this->snapshotPercent($card, $period, 'y-te-benh-vien'));
        $this->assertSame('70000.00', $this->snapshotAmount($card, $period, 'y-te-benh-vien'));
        $this->assertSame('7.000', $this->snapshotPercent($card, $period, 'bao-hiem'));
        $this->assertSame('70000.00', $this->snapshotAmount($card, $period, 'bao-hiem'));
        $this->assertSame('0.100', $this->snapshotPercent($card, $period, 'xang-dau'));
        $this->assertSame('1000.00', $this->snapshotAmount($card, $period, 'xang-dau'));
        $this->assertSame('141000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function ocb_jcb_platinum_caps_each_reward_group_at_150k_below_30m(): void
    {
        $card = $this->makeCardWithTemplate('ocb-jcb-platinum');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '10000000', $this->categoryBySlug('y-te-benh-vien'));
        $this->addTxn($card, $period, '2026-10-06', '10000000', $this->categoryBySlug('bao-hiem'));

        $this->records->calculatePeriod($card, $period);

        // Mỗi nhóm 10tr × 7% = 700.000 thô ⇒ trần nhóm 150.000.
        $this->assertSame('150000.00', $this->snapshotAmount($card, $period, 'y-te-benh-vien'));
        $this->assertSame('150000.00', $this->snapshotAmount($card, $period, 'bao-hiem'));
        $this->assertSame('300000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function ocb_jcb_platinum_switches_to_15pct_and_300k_group_cap_at_30m(): void
    {
        $card = $this->makeCardWithTemplate('ocb-jcb-platinum');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '30000000', $this->categoryBySlug('y-te-benh-vien'));

        $this->records->calculatePeriod($card, $period);

        // Đúng 30 triệu ⇒ bậc 2: 15%, trần nhóm 300.000.
        $this->assertSame('15.000', $this->snapshotPercent($card, $period, 'y-te-benh-vien'));
        $this->assertSame('300000.00', $this->snapshotAmount($card, $period, 'y-te-benh-vien'));
        $this->assertSame('300000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function ocb_jcb_platinum_does_not_enforce_the_7_transaction_condition_documented_limit(): void
    {
        // GIỚI HẠN ĐÃ BIẾT: chính sách yêu cầu tối thiểu 7 giao dịch hợp lệ/kỳ.
        // Engine KHÔNG có điều kiện theo SỐ GIAO DỊCH (`SpendQualificationService`
        // chỉ hỗ trợ "danh mục đạt mức chi tiêu" và "lĩnh vực khác đạt mức chi tiêu"),
        // nên cổng này KHÔNG được áp. Một giao dịch duy nhất vẫn được hoàn.
        $card = $this->makeCardWithTemplate('ocb-jcb-platinum');
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '1000000', $this->categoryBySlug('y-te-benh-vien'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('70000.00', $this->snapshotAmount($card, $period, 'y-te-benh-vien'));
        $this->assertSame('70000.00', $period->refresh()->total_cashback);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function bankBySlug(string $slug): Bank
    {
        $bank = Bank::query()->where('slug', $slug)->first();

        $this->assertNotNull($bank, "Seeder phải tạo ngân hàng {$slug}.");

        return $bank;
    }

    private function seededTemplate(string $slug): PolicyTemplate
    {
        $template = PolicyTemplate::query()->system()->where('slug', $slug)->first();

        $this->assertNotNull($template, "Seeder phải tạo template {$slug}.");

        return $template;
    }

    private function categoryBySlug(string $slug): Category
    {
        $category = Category::query()
            ->where('scope', Category::SCOPE_SYSTEM)
            ->where('slug', $slug)
            ->first();

        $this->assertNotNull($category, 'Danh mục hệ thống "'.$slug.'" phải tồn tại sau khi seed.');

        return $category;
    }

    private function comboBySlug(string $slug): CategoryCombo
    {
        $combo = CategoryCombo::query()
            ->where('scope', CategoryCombo::SCOPE_SYSTEM)
            ->where('slug', $slug)
            ->first();

        $this->assertNotNull($combo, 'Combo hệ thống "'.$slug.'" phải tồn tại sau khi seed.');

        return $combo;
    }

    private function makeCardWithTemplate(string $slug): UserCard
    {
        $this->creditCardSeeder->run();

        $plan = self::PLANS[$slug];

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->bankBySlug($plan['bank'])->id,
            'name' => $plan['name'],
        ]);

        $version = $this->cloner->attachTemplateToCard(
            $card,
            $this->seededTemplate($slug),
            CarbonImmutable::parse('2026-10-01'),
        );

        $this->assertNotNull($version);

        return $card;
    }

    private function addTxn(UserCard $card, $period, string $date, string $amount, Category $category): Transaction
    {
        return Transaction::create([
            'user_card_id' => $card->id,
            'statement_period_id' => $period->id,
            'category_id' => $category->id,
            'transaction_date' => $date,
            'amount' => $amount,
            'source' => Transaction::SOURCE_MANUAL,
        ]);
    }

    private function snapshotPercent(UserCard $card, StatementPeriod $period, string $categorySlug): string
    {
        return (string) Transaction::query()
            ->where('user_card_id', $card->id)
            ->where('statement_period_id', $period->id)
            ->where('category_id', $this->categoryBySlug($categorySlug)->id)
            ->firstOrFail()
            ->cashback_percent_snapshot;
    }

    private function snapshotAmount(UserCard $card, StatementPeriod $period, string $categorySlug): string
    {
        return (string) Transaction::query()
            ->where('user_card_id', $card->id)
            ->where('statement_period_id', $period->id)
            ->where('category_id', $this->categoryBySlug($categorySlug)->id)
            ->firstOrFail()
            ->cashback_amount_snapshot;
    }
}
