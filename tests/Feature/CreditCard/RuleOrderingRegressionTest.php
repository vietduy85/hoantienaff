<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\CashbackRecordService;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\StatementPeriodService;
use App\Support\CreditCard\SystemPolicyPresenter;
use Database\Seeders\CreditCardSeeder;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use App\Models\User;

/**
 * Hồi quy — THỨ TỰ QUY TẮC HOÀN TIỀN (fallback "📦 Các danh mục còn lại" luôn cuối).
 *
 * Bất biến: trong MỘT BẬC, thứ tự hiển thị là danh mục cụ thể → combo danh mục →
 * fallback. Thứ tự được bảo đảm ở TẦNG DỮ LIỆU (`sort_order = 1..n` sau chuẩn hoá)
 * lẫn TẦNG ĐỌC (`ordered()`), áp dụng cho mọi đường ghi cấu hình: seeder Shinhan +
 * VIB, sửa rule, clone sang thẻ, tạo version mới, lưu payload editor, lệnh sửa chữa.
 */
class RuleOrderingRegressionTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private const SHINHAN_SLUG = 'shinhan-supreme-visa';

    private const VIB_SLUGS = [
        'starter' => 'vib-max-card-visa-starter',
        'plus' => 'vib-max-card-visa-plus',
        'pro' => 'vib-max-card-visa-pro',
        'elite' => 'vib-max-card-visa-elite',
    ];

    private const VIB_PLAN_CAP = [
        'starter' => '500000.00',
        'plus' => '500000.00',
        'pro' => '1000000.00',
        'elite' => '1500000.00',
    ];

    private const VIB_PLAN_PERCENT = [
        'starter' => '0.500',
        'plus' => '5.000',
        'pro' => '8.000',
        'elite' => '10.000',
    ];

    private const VIB_COMBO_SLUGS = [
        'vib-shopping',
        'vib-travel',
        'vib-online',
    ];

    /** 2 nhóm một danh mục — trỏ thẳng danh mục, đứng TRƯỚC combo. */
    private const VIB_DIRECT_CATEGORY_SLUGS = [
        'am-thuc-an-uong',
        'ung-dung-so-dich-vu-giai-tri',
    ];

    private CreditCardSeeder $creditCardSeeder;

    private PolicyCloneService $cloner;

    private CashbackRecordService $records;

    private StatementPeriodService $periods;

    private User $user;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->creditCardSeeder = app(CreditCardSeeder::class);
        $this->cloner = app(PolicyCloneService::class);
        $this->records = app(CashbackRecordService::class);
        $this->periods = app(StatementPeriodService::class);

        $this->user = User::factory()->create();
    }

    // =====================================================================
    // A · Dữ liệu seeder (Shinhan Supreme + 4 VIB Max Card Visa)
    // =====================================================================

    #[Test]
    public function vib_blueprints_order_two_direct_categories_three_combos_then_the_fallback(): void
    {
        $this->creditCardSeeder->run();

        foreach (self::VIB_SLUGS as $plan => $slug) {
            $tier = $this->seededTemplate($slug)->blueprint()->firstOrFail()->tiers()->first();

            $rules = $this->listFor($tier);

            $this->assertSame(
                ['category', 'category', 'combo', 'combo', 'combo', 'fallback'],
                $rules->map(fn (PolicyTierCategory $r): string => $this->target($r))->all(),
                "{$slug}: danh mục trực tiếp trước, combo sau, fallback cuối."
            );
            $this->assertSame([1, 2, 3, 4, 5, 6], $rules->pluck('sort_order')->map(fn ($v) => (int) $v)->all(), "{$slug}: sort_order liên tục 1..6.");

            $targets = $rules->reject(fn (PolicyTierCategory $r): bool => $r->isFallback())->values();

            $this->assertSame(
                self::VIB_DIRECT_CATEGORY_SLUGS,
                $targets->take(2)->map(fn (PolicyTierCategory $r): string => $r->category->slug)->all(),
                "{$slug}: 2 nhóm một danh mục trỏ thẳng, theo thứ tự seeder."
            );
            $this->assertSame(
                self::VIB_COMBO_SLUGS,
                $targets->slice(2)->map(fn (PolicyTierCategory $r): string => $r->combo->slug)->values()->all(),
                "{$slug}: 3 combo theo thứ tự seeder."
            );

            $fallback = $rules->last();
            $this->assertTrue($fallback->isFallback());
            $this->assertSame('0.100', $fallback->cashback_percent);
            $this->assertSame(self::VIB_PLAN_CAP[$plan], $tier->max_cashback_per_period, 'Trần toàn bậc không đổi.');

            foreach ($targets as $rule) {
                $this->assertSame(self::VIB_PLAN_PERCENT[$plan], $rule->cashback_percent, 'Tỷ lệ hạng thẻ không đổi.');
            }
        }
    }

    #[Test]
    public function shinhan_blueprints_order_specific_categories_then_the_fallback_in_every_tier(): void
    {
        $this->creditCardSeeder->run();

        $tiers = $this->seededTemplate(self::SHINHAN_SLUG)->blueprint()->firstOrFail()->tiers()->orderBy('sort_order')->get();

        $this->assertCount(3, $tiers);

        // Bậc 1: CHỈ fallback.
        $this->assertSame(['fallback'], $this->listFor($tiers[0])->map(fn ($r) => $this->target($r))->all());

        foreach ([1, 2] as $index) {
            $rules = $this->listFor($tiers[$index]);

            $this->assertSame(
                ['category', 'category', 'category', 'category', 'fallback'],
                $rules->map(fn (PolicyTierCategory $r): string => $this->target($r))->all(),
                "Bậc {$index}: danh mục cụ thể trước, fallback cuối."
            );
            $this->assertSame([1, 2, 3, 4, 5], $rules->pluck('sort_order')->map(fn ($v) => (int) $v)->all(), "Bậc {$index}: sort_order liên tục 1..5.");

            $this->assertSame(
                ['12.000', '12.000', '6.000', '6.000'],
                $rules->take(4)->map(fn (PolicyTierCategory $r): string => $r->cashback_percent)->all(),
                "Bậc {$index}: tỷ lệ danh mục không đổi."
            );
            $this->assertSame('0.300', $rules->last()->cashback_percent, "Bậc {$index}: fallback 0,3%.");
        }
    }

    #[Test]
    public function seeder_reruns_keep_the_canonical_rule_order(): void
    {
        $this->creditCardSeeder->run();
        $this->creditCardSeeder->run();

        foreach ([self::SHINHAN_SLUG, ...array_values(self::VIB_SLUGS)] as $slug) {
            $before = $this->seedOrderSnapshot($slug);

            $this->creditCardSeeder->run();
            $this->creditCardSeeder->run();

            $this->assertSame($before, $this->seedOrderSnapshot($slug), "Chạy lại seeder không đổi thứ tự rule của {$slug}.");
        }
    }

    // =====================================================================
    // B · Đọc — `listFor`/presenter hiển thị thứ tự chuẩn kể cả dữ liệu cũ sai
    // =====================================================================

    #[Test]
    public function list_for_orders_fallback_last_even_when_stored_sort_order_is_scrambled(): void
    {
        $this->creditCardSeeder->run();

        // Cấu hình kiểu cũ "fallback chèn giữa": fallback sort = 1, combo sort 0..4.
        $tier = $this->seededTemplate(self::VIB_SLUGS['starter'])->blueprint()->firstOrFail()->tiers()->first();
        $this->scrambleVibStyle($tier);

        $service = app(CategoryRuleService::class);

        $this->assertSame(
            ['category', 'category', 'combo', 'combo', 'combo', 'fallback'],
            $service->listFor($tier)->map(fn (PolicyTierCategory $r): string => $this->target($r))->all(),
            'Hiển thị phải chuẩn ngay cả khi cột sort_order để sai.'
        );

        // Chuẩn hoá dữ liệu đưa sort_order về đúng thứ tự 1..n.
        $service->normaliseRuleOrder($tier);
        $this->assertSame([1, 2, 3, 4, 5, 6], $service->listFor($tier)->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
    }

    #[Test]
    public function normalise_rule_order_moves_a_midlist_fallback_to_the_end_with_category_combo_fallback(): void
    {
        $policy = $this->makeBlankPolicy();

        $category = $this->makeSystemCategory();
        $combo = $this->makeCombo($category);

        $tier = $this->makeTier($policy);

        PolicyTierCategory::create(['tier_id' => $tier->id, 'category_id' => $category->id, 'scope_type' => 'category', 'sort_order' => 2, 'spend_from' => 0, 'cashback_percent' => '2.000']);
        PolicyTierCategory::create(['tier_id' => $tier->id, 'combo_id' => $combo->id, 'scope_type' => 'category', 'sort_order' => 4, 'spend_from' => 0, 'cashback_percent' => '3.000']);
        PolicyTierCategory::create(['tier_id' => $tier->id, 'scope_type' => 'other', 'sort_order' => 1, 'spend_from' => 0, 'cashback_percent' => '0.500']);

        $service = app(CategoryRuleService::class);

        $this->assertSame(3, $service->normaliseRuleOrder($tier), 'Cả 3 rule đều được đánh số lại.');

        $rules = $service->listFor($tier);

        $this->assertSame(
            ['category', 'combo', 'fallback'],
            $rules->map(fn (PolicyTierCategory $r): string => $this->target($r))->all(),
            'Danh mục cụ thể → combo → fallback.'
        );
        $this->assertSame([1, 2, 3], $rules->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['2.000', '3.000', '0.500'], $rules->map(fn (PolicyTierCategory $r): string => $r->cashback_percent)->all());
    }

    #[Test]
    public function system_policy_presenter_emits_canonical_rule_order_for_scrambled_data(): void
    {
        $this->creditCardSeeder->run();

        $blueprint = $this->seededTemplate(self::VIB_SLUGS['starter'])->blueprint()->firstOrFail();
        $tier = $blueprint->tiers()->first();
        $this->scrambleVibStyle($tier);

        $presented = app(SystemPolicyPresenter::class)->tiers($blueprint);

        $this->assertSame(
            ['category', 'category', 'combo', 'combo', 'combo', 'other'],
            array_map(fn ($rule): string => (string) $rule['target_type'], $presented[0]['rules']),
            'Presenter (editor hệ thống) phải hiển thị fallback cuối dù sort_order để sai.'
        );
    }

    // =====================================================================
    // C · Ghi — mọi đường ghi cấu hình ép fallback xuống cuối
    // =====================================================================

    #[Test]
    public function ensure_single_fallback_forces_the_fallback_last_even_if_payload_sort_order_is_in_the_middle(): void
    {
        $policy = $this->makeBlankPolicy();
        $tier = $this->makeTier($policy);

        $category = $this->makeSystemCategory();

        PolicyTierCategory::create(['tier_id' => $tier->id, 'category_id' => $category->id, 'scope_type' => 'category', 'sort_order' => 1, 'spend_from' => 0, 'cashback_percent' => '2.000']);

        $service = app(CategoryRuleService::class);

        // Payload editor gửi `sort_order: 1` (chèn giữa) — phải bị ép xuống cuối.
        $service->ensureSingleFallback($tier, ['sort_order' => 1, 'cashback_percent' => '0.500']);

        $rules = $service->listFor($tier);

        $this->assertSame(['category', 'fallback'], $rules->map(fn (PolicyTierCategory $r): string => $this->target($r))->all());
        $this->assertSame([1, 2], $rules->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
    }

    #[Test]
    public function changing_a_rule_to_fallback_moves_it_to_the_end_of_the_tier(): void
    {
        $policy = $this->makeBlankPolicy();
        $tier = $this->makeTier($policy);

        $catA = $this->makeSystemCategory();
        $catB = $this->makeSystemCategory();

        $ruleA = PolicyTierCategory::create(['tier_id' => $tier->id, 'category_id' => $catA->id, 'scope_type' => 'category', 'sort_order' => 1, 'spend_from' => 0, 'cashback_percent' => '2.000']);
        PolicyTierCategory::create(['tier_id' => $tier->id, 'category_id' => $catB->id, 'scope_type' => 'category', 'sort_order' => 2, 'spend_from' => 0, 'cashback_percent' => '1.000']);

        $service = app(CategoryRuleService::class);

        $service->update((int) $ruleA->id, [
            'scope_type' => PolicyTierCategory::SCOPE_OTHER,
            'cashback_percent' => '0.500',
        ]);

        $rules = $service->listFor($tier);

        $this->assertSame(['category', 'fallback'], $rules->map(fn (PolicyTierCategory $r): string => $this->target($r))->all());
        $this->assertSame([1, 2], $rules->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('1.000', $rules[0]->cashback_percent, 'Rule danh mục được đẩy lên đầu.');
        $this->assertSame('0.500', $rules[1]->cashback_percent, 'Rule vừa chuyển thành fallback đứng cuối.');
    }

    #[Test]
    public function editor_payload_with_fallback_in_the_middle_is_normalised_when_saving_a_new_version(): void
    {
        $card = $this->makeVibCard('starter');
        $version = $card->currentPolicy;

        $comboIds = PolicyTierCategory::query()
            ->where('tier_id', $version->tiers()->first()->id)
            ->comboSpecific()
            ->orderBy('sort_order')
            ->pluck('combo_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Giả lập payload editor đang hiển thị fallback ở GIỮA (dữ liệu cũ sai).
        $rulesPayload = [];
        foreach ($comboIds as $index => $comboId) {
            $rulesPayload[] = [
                'scope_type' => 'category',
                'combo_id' => $comboId,
                'counts_toward_tier_cap' => true,
                'cashback_percent' => self::VIB_PLAN_PERCENT['starter'],
                'sort_order' => $index + 1,
                'spend_from' => 0,
            ];
        }
        $rulesPayload[] = [
            'scope_type' => 'other',
            'counts_toward_tier_cap' => true,
            'name' => PolicyTierCategory::FALLBACK_NAME,
            'cashback_percent' => '0.100',
            'sort_order' => 1, // payload cố tình chèn giữa
            'spend_from' => 0,
        ];

        $next = $this->cloner->createNextVersion(
            $card,
            CarbonImmutable::parse('2026-10-01'),
            ['tiers' => [[
                'name' => 'Toàn bộ chi tiêu hợp lệ',
                'sort_order' => 1,
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'max_cashback_per_period' => self::VIB_PLAN_CAP['starter'],
                'transaction_caps' => [],
                'rules' => $rulesPayload,
            ]]],
        );

        $nextTier = PolicyTier::query()->where('policy_id', $next->id)->first();
        $rules = $this->listFor($nextTier);

        $this->assertSame(
            ['combo', 'combo', 'combo', 'fallback'],
            $rules->map(fn (PolicyTierCategory $r): string => $this->target($r))->all()
        );
        $this->assertSame([1, 2, 3, 4], $rules->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
    }

    // =====================================================================
    // D · Clone / version — bản sao giữ thứ tự chuẩn
    // =====================================================================

    #[Test]
    public function attaching_a_vib_template_to_a_card_keeps_the_canonical_rule_order_and_rates(): void
    {
        $card = $this->makeVibCard('pro');
        $tier = $card->currentPolicy->tiers()->first();

        $rules = $this->listFor($tier);

        $this->assertSame(
            ['category', 'category', 'combo', 'combo', 'combo', 'fallback'],
            $rules->map(fn (PolicyTierCategory $r): string => $this->target($r))->all(),
            'Clone sang thẻ phải giữ thứ tự chuẩn.'
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], $rules->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('8.000', $rules[0]->cashback_percent);
        $this->assertSame('1000000.00', $tier->max_cashback_per_period);
    }

    #[Test]
    public function attaching_a_scrambled_template_still_produces_a_canonical_clone(): void
    {
        $this->creditCardSeeder->run();

        $vib = Bank::query()->where('slug', 'vib')->firstOrFail();
        $template = $this->seededTemplate(self::VIB_SLUGS['starter']);

        $this->scrambleVibStyle($template->blueprint()->firstOrFail()->tiers()->first());

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => (int) $vib->id,
            'name' => 'VIB Max Card Visa',
        ]);
        $this->cloner->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-09-01'));

        $rules = $this->listFor($card->currentPolicy->tiers()->first());

        $this->assertSame(
            ['category', 'category', 'combo', 'combo', 'combo', 'fallback'],
            $rules->map(fn (PolicyTierCategory $r): string => $this->target($r))->all(),
            'Clone từ dữ liệu sai vẫn phải ra thứ tự chuẩn.'
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], $rules->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('0.500', $rules[0]->cashback_percent, 'Tỷ lệ khôi phục theo nguồn, không bị lệch.');
    }

    #[Test]
    public function a_new_version_without_overrides_copies_the_canonical_order(): void
    {
        $card = $this->makeVibCard('plus');

        $next = $this->cloner->createNextVersion($card, CarbonImmutable::parse('2026-10-01'));

        $rules = $this->listFor($next->tiers()->first());

        $this->assertSame(
            ['category', 'category', 'combo', 'combo', 'combo', 'fallback'],
            $rules->map(fn (PolicyTierCategory $r): string => $this->target($r))->all()
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], $rules->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('5.000', $rules[0]->cashback_percent);
    }

    // =====================================================================
    // E · Engine — kết quả hoàn tiền KHÔNG phụ thuộc sort_order
    // =====================================================================

    #[Test]
    public function engine_cashback_is_identical_after_scrambling_rule_sort_order(): void
    {
        $card = $this->makeVibCard('starter');

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $dining = $this->addTxn($card, $period, '2026-09-05', '2000000', $this->categoryBySlug('am-thuc-an-uong'));
        $fuel = $this->addTxn($card, $period, '2026-09-06', '1000000', $this->categoryBySlug('xang-dau'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('10000.00', $dining->refresh()->cashback_amount_snapshot);
        $this->assertSame('1000.00', $fuel->refresh()->cashback_amount_snapshot);
        $this->assertSame('11000.00', $period->refresh()->total_cashback);

        // Giả lập dữ liệu cũ sai thứ tự RỒI tính lại kỳ OPEN.
        $this->scrambleVibStyle($card->currentPolicy->tiers()->first());
        $this->records->calculatePeriod($card, $period);

        $this->assertSame('10000.00', $dining->refresh()->cashback_amount_snapshot, 'Khớp combo không phụ thuộc thứ tự hiển thị.');
        $this->assertSame('1000.00', $fuel->refresh()->cashback_amount_snapshot, 'Khớp fallback không phụ thuộc thứ tự hiển thị.');
        $this->assertSame('11000.00', $period->refresh()->total_cashback);
    }

    // =====================================================================
    // F · Lệnh sửa chữa — chỉ sửa sort_order, chỉ trong phạm vi
    // =====================================================================

    #[Test]
    public function the_repair_command_normalises_only_in_scope_policies_and_only_touches_sort_order(): void
    {
        $this->creditCardSeeder->run();

        // Dữ liệu SAI trong phạm vi: Shinhan (mọi bậc) + VIB (4 template).
        $shinhan = $this->seededTemplate(self::SHINHAN_SLUG)->blueprint()->firstOrFail();
        $shinhanTiers = $shinhan->tiers()->orderBy('id')->get();

        foreach ($shinhanTiers as $tier) {
            $this->scrambleVibStyle($tier);
        }

        $vibTiers = [];
        foreach (self::VIB_SLUGS as $slug) {
            $tier = $this->seededTemplate($slug)->blueprint()->firstOrFail()->tiers()->first();
            $this->scrambleVibStyle($tier);
            $vibTiers[] = $tier;
        }

        // Dữ liệu SAI NGOÀI phạm vi: template cá nhân (scope user).
        $outOfScope = $this->makeUserTemplateWithScrambledRules();
        $outTier = $outOfScope->tiers()->first();

        $allTiers = array_merge($shinhanTiers->all(), $vibTiers);
        $before = $this->ruleSnapshot($allTiers);
        $beforeOut = $this->listFor($outTier)->pluck('sort_order')->map(fn ($v) => (int) $v)->all();

        $exit = Artisan::call('credit-card:normalise-rule-order');

        $this->assertSame(0, $exit);

        // Bậc 0 của Shinhan CHỈ có fallback.
        $this->assertSame([1], $this->listFor($shinhanTiers[0])->pluck('sort_order')->map(fn ($v) => (int) $v)->all());

        // Bậc 1, 2 của Shinhan: 4 danh mục + fallback, sort_order liên tục 1..5.
        foreach ([1, 2] as $index) {
            $this->assertSame(
                [1, 2, 3, 4, 5],
                $this->listFor($shinhanTiers[$index])->pluck('sort_order')->map(fn ($v) => (int) $v)->all(),
                "Shinhan bậc {$index} đã được đánh số lại (dữ liệu SAI → [0,1,2,3,4,1-end] → [1,2,3,4,5])."
            );
        }

        foreach ($vibTiers as $tier) {
            $this->assertSame(
                [1, 2, 3, 4, 5, 6],
                $this->listFor($tier)->pluck('sort_order')->map(fn ($v) => (int) $v)->all(),
                'Mọi template VIB đã được đánh số lại.'
            );
        }

        // NGOÀI phạm vi: không được đụng tới (vẫn còn thứ tự SAI cũ).
        $this->assertSame(
            $beforeOut,
            $this->listFor($outTier)->pluck('sort_order')->map(fn ($v) => (int) $v)->all(),
            'Policy ngoài phạm vi phải giữ nguyên.'
        );

        // CHỈ sort_order đổi: phần trăm / hạn mức / cờ / nhóm của rule giữ nguyên.
        $this->assertSame($before, $this->ruleSnapshot($allTiers));

        // Idempotent: chạy lại không đổi gì thêm.
        Artisan::call('credit-card:normalise-rule-order');
        $this->assertSame($before, $this->ruleSnapshot($allTiers));
    }

    #[Test]
    public function the_repair_command_accepts_an_explicit_template_scope(): void
    {
        $this->creditCardSeeder->run();

        $vib = $this->seededTemplate(self::VIB_SLUGS['pro'])->blueprint()->firstOrFail()->tiers()->first();
        $elite = $this->seededTemplate(self::VIB_SLUGS['elite'])->blueprint()->firstOrFail()->tiers()->first();

        $this->scrambleVibStyle($vib);
        $this->scrambleVibStyle($elite);

        Artisan::call('credit-card:normalise-rule-order', [
            '--templates' => self::VIB_SLUGS['pro'],
        ]);

        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            $this->listFor($vib)->pluck('sort_order')->map(fn ($v) => (int) $v)->all(),
            'Template được chỉ định phải chuẩn hoá dữ liệu.'
        );

        $this->assertSame(
            [0, 1, 2, 3, 4, 1],
            $this->listFor($elite)->pluck('sort_order')->map(fn ($v) => (int) $v)->all(),
            'Template KHÔNG được chỉ định phải giữ nguyên dữ liệu sai.'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function seededTemplate(string $slug): \App\Models\CreditCard\PolicyTemplate
    {
        $template = \App\Models\CreditCard\PolicyTemplate::query()->system()->where('slug', $slug)->first();

        $this->assertNotNull($template, "Seeder phải tạo template {$slug}.");

        return $template;
    }

    private function listFor(PolicyTier $tier): \Illuminate\Support\Collection
    {
        return app(CategoryRuleService::class)->listFor($tier);
    }

    private function target(PolicyTierCategory $rule): string
    {
        return $rule->isFallback() ? 'fallback' : ($rule->isComboSpecific() ? 'combo' : 'category');
    }

    private function makeVibCard(string $plan): UserCard
    {
        $this->creditCardSeeder->run();

        $vib = Bank::query()->where('slug', 'vib')->firstOrFail();

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => (int) $vib->id,
            'name' => 'VIB Max Card Visa',
        ]);

        $this->cloner->attachTemplateToCard($card, $this->seededTemplate(self::VIB_SLUGS[$plan]), CarbonImmutable::parse('2026-09-01'));

        return $card->refresh();
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

    private function categoryBySlug(string $slug): Category
    {
        $category = Category::query()->where('scope', Category::SCOPE_SYSTEM)->where('slug', $slug)->first();

        $this->assertNotNull($category, 'Danh mục hệ thống "'.$slug.'" phải tồn tại sau khi seed.');

        return $category;
    }

    /**
     * Giả lập dữ liệu cũ SAI: fallback bị chèn GIỮA danh sách (sort_order = 1 chung
     * với combo đầu), y hệt lỗi seeder trước khi chuẩn hoá.
     */
    private function scrambleVibStyle(PolicyTier $tier): void
    {
        $rules = $tier->tierCategoryRules()->orderBy('id')->get();

        $sort = 0;

        foreach ($rules->reject(fn (PolicyTierCategory $r): bool => $r->isFallback()) as $rule) {
            $rule->forceFill(['sort_order' => $sort++])->save();
        }

        foreach ($rules->filter(fn (PolicyTierCategory $r): bool => $r->isFallback()) as $rule) {
            $rule->forceFill(['sort_order' => 1])->save();
        }
    }

    private function seedOrderSnapshot(string $slug): array
    {
        $tiers = $this->seededTemplate($slug)->blueprint()->firstOrFail()->tiers()->orderBy('sort_order')->get();

        return $tiers->map(fn (PolicyTier $tier): array => $this->listFor($tier)
            ->map(fn (PolicyTierCategory $r): array => [$this->target($r), (int) $r->sort_order])
            ->values()->all())->all();
    }

    private function makeBlankPolicy(): Policy
    {
        $policy = Policy::create([
            'user_card_id' => null,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => 'Policy thứ tự',
            'effective_from' => '2026-01-01',
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);
        $policy->forceFill(['root_policy_id' => $policy->id])->save();

        return $policy->refresh();
    }

    private function makeTier(Policy $policy): PolicyTier
    {
        return PolicyTier::create([
            'policy_id' => $policy->id,
            'name' => 'Bậc duy nhất',
            'sort_order' => 1,
            'min_total_spend' => 0,
            'max_total_spend' => null,
        ]);
    }

    private function makeCombo(Category $member): CategoryCombo
    {
        $combo = CategoryCombo::create([
            'scope' => CategoryCombo::SCOPE_SYSTEM,
            'owner_user_id' => CategoryCombo::SYSTEM_OWNER_ID,
            'name' => 'Combo kiểm thử',
            'slug' => 'combo-thu-tu-'.uniqid(),
            'is_active' => true,
        ]);

        CategoryComboItem::create([
            'combo_id' => $combo->id,
            'category_id' => $member->id,
            'sort_order' => 0,
        ]);

        return $combo;
    }

    private function makeUserTemplateWithScrambledRules(): Policy
    {
        $template = \App\Models\CreditCard\PolicyTemplate::create([
            'scope' => \App\Models\CreditCard\PolicyTemplate::SCOPE_USER,
            'owner_user_id' => (int) $this->user->id,
            'name' => 'Template cá nhân ngoài phạm vi',
            'slug' => 'template-ca-nhan-ngoai-pham-vi-'.uniqid(),
            'is_builtin' => false,
            'is_active' => true,
        ]);

        $policy = Policy::create([
            'user_card_id' => null,
            'template_id' => $template->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => $template->name,
            'effective_from' => '2026-01-01',
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);
        $policy->forceFill(['root_policy_id' => $policy->id])->save();

        $tier = $this->makeTier($policy);

        PolicyTierCategory::create(['tier_id' => $tier->id, 'scope_type' => 'other', 'sort_order' => 1, 'spend_from' => 0, 'cashback_percent' => '0.100']);
        PolicyTierCategory::create(['tier_id' => $tier->id, 'category_id' => $this->makeSystemCategory()->id, 'scope_type' => 'category', 'sort_order' => 0, 'spend_from' => 0, 'cashback_percent' => '2.000']);

        return $policy->refresh();
    }

    /**
     * Snapshot tỷ lệ/hạn mức/cờ của mọi rule trong các tier — để chứng minh lệnh
     * CHỈ đổi sort_order.
     *
     * @param  iterable<int, PolicyTier>  $tiers
     */
    private function ruleSnapshot(iterable $tiers): array
    {
        $rows = [];

        foreach ($tiers as $tier) {
            // Không dùng quan hệ `tierCategoryRules()` (đã orderBy('sort_order')):
            // snapshot phải theo id để so sánh ổn định trước/sau khi chạy lệnh.
            $rules = \App\Models\CreditCard\PolicyTierCategory::query()
                ->where('tier_id', (int) $tier->id)
                ->orderBy('id')
                ->get();

            foreach ($rules as $rule) {
                $rows[] = $rule->only([
                    'category_id',
                    'combo_id',
                    'scope_type',
                    'counts_toward_tier_cap',
                    'is_quota_category',
                    'name',
                    'spend_from',
                    'spend_to',
                    'cashback_percent',
                    'max_cashback_per_transaction',
                    'max_cashback_per_category_per_period',
                    'min_transaction_amount',
                    'is_enabled',
                ]);
            }
        }

        return $rows;
    }
}