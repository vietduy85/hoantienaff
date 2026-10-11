<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTierCategory;
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
 * Chính sách hệ thống "VIB One Card Visa" — seeded từ CreditCardSeeder.
 *
 * ---------------------------------------------------------------------------
 * CẤU TRÚC (PDF mục 2.12 — "Ưu đãi 1: Điểm thưởng")
 * ---------------------------------------------------------------------------
 * 3 hạng thẻ (Gold / Platinum / Signature), mỗi chính sách MỘT bậc chi tiêu
 * duy nhất phủ từ 0đ:
 *   - khách hàng được đăng ký tối đa N DMCT theo hạng (Gold 01, Platinum 02,
 *     Signature 03); mọi DMCT ĐÃ ĐĂNG KÝ cùng nhận tỷ lệ theo hạng;
 *   - blueprint của từng hạng chứa rule combo CHỈ cho DMCT trong CẤU HÌNH KHỞI
 *     TẠO (Gold: Mua sắm; Platinum: Mua sắm, Bảo hiểm; Signature: Mua sắm,
 *     Giáo dục, Bảo hiểm) + fallback nền 0,1% cho mọi thứ không đăng ký;
 *   - trần Điểm thưởng/kỳ sao kê theo hạng (Cap toàn bậc).
 * Cờ `counts_toward_tier_cap = true` cho CẢ rule DMCT lẫn fallback.
 *
 * Đổi DMCT (tối đa 01 lượt/kỳ) không có cơ chế trong blueprint — đó là thao tác
 * sau khi clone lên thẻ (đổi bộ rule combo của bản sao), không phải việc seed.
 *
 * Trần riêng "Giao dịch đặc biệt" 10.000 điểm/kỳ KHÔNG được cấu hình: engine
 * không có khái niệm giao dịch đặc biệt (đồng bộ với Max Card Visa báo cáo X.7).
 */
class VibOneCardVisaPolicyTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private const VIB_BANK_SLUG = 'vib';

    private const SLUGS = [
        'gold' => 'vib-one-card-visa-gold',
        'platinum' => 'vib-one-card-visa-platinum',
        'signature' => 'vib-one-card-visa-signature',
    ];

    /** DMCT trong CẤU HÌNH KHỞI TẠO của từng hạng. */
    private const DEFAULT_COMBOS = [
        'gold' => ['vib-one-card-mua-sam'],
        'platinum' => ['vib-one-card-mua-sam', 'vib-one-card-bao-hiem'],
        'signature' => ['vib-one-card-mua-sam', 'vib-one-card-giao-duc', 'vib-one-card-bao-hiem'],
    ];

    private const PLAN_PERCENT = [
        'gold' => '3.000',
        'platinum' => '6.000',
        'signature' => '9.000',
    ];

    private const PLAN_CAP = [
        'gold' => '500000.00',
        'platinum' => '1000000.00',
        'signature' => '1500000.00',
    ];

    /** 5 combo "kho" DMCT — membership trên danh mục CÓ TRONG BỘ SEED GỐC. */
    private const COMBO_GROUPS = [
        'vib-one-card-mua-sam' => ['sieu-thi-tien-loi', 'thoi-trang'],
        'vib-one-card-du-lich' => ['ve-may-bay-du-lich', 'khach-san-luu-tru'],
        'vib-one-card-giao-duc' => ['giao-duc-hoc-phi'],
        'vib-one-card-bao-hiem' => ['bao-hiem'],
        'vib-one-card-nuoc-ngoai' => ['chi-tieu-nuoc-ngoai'],
    ];

    /** Nhãn hiển thị của từng combo (slug ⇒ name). */
    private const COMBO_NAMES = [
        'vib-one-card-mua-sam' => 'Mua sắm — Shopping',
        'vib-one-card-du-lich' => 'Du lịch — Travel',
        'vib-one-card-giao-duc' => 'Giáo dục — Education',
        'vib-one-card-bao-hiem' => 'Bảo hiểm — Insurance',
        'vib-one-card-nuoc-ngoai' => 'Chi tiêu nước ngoài — Overseas Spending',
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
    public function seeder_creates_the_three_vib_one_card_templates_with_applicability(): void
    {
        $this->creditCardSeeder->run();

        $vib = $this->vibBank();

        foreach (self::SLUGS as $plan => $slug) {
            $template = $this->seededTemplate($slug);

            $this->assertSame('VIB One Card '.ucfirst($plan), $template->name);
            $this->assertSame(PolicyTemplate::SCOPE_SYSTEM, $template->scope);
            $this->assertSame(PolicyTemplate::SYSTEM_OWNER_ID, (int) $template->owner_user_id);
            $this->assertTrue((bool) $template->is_builtin);
            $this->assertTrue((bool) $template->is_active);
            $this->assertSame((int) $vib->id, (int) $template->applicable_bank_id, 'Chỉ áp dụng cho thẻ ngân hàng VIB.');
            $this->assertSame('one card', $template->applicable_product_match, 'Tên thẻ phải chứa từ khoá sản phẩm.');

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
    public function each_template_has_one_tier_with_default_dmct_rules_and_a_01_fallback(): void
    {
        $this->creditCardSeeder->run();

        foreach (self::SLUGS as $plan => $slug) {
            $template = $this->seededTemplate($slug);
            $tiers = $template->blueprint()->firstOrFail()->tiers()->orderBy('sort_order')->orderBy('id')->get();

            $this->assertCount(1, $tiers, 'Mỗi chính sách có đúng 1 bậc chi tiêu.');

            $tier = $tiers->first();
            $this->assertSame('Toàn bộ chi tiêu hợp lệ', $tier->name);
            $this->assertSame('0.00', $tier->min_total_spend);
            $this->assertNull($tier->max_total_spend);
            $this->assertSame(self::PLAN_CAP[$plan], $tier->max_cashback_per_period, 'Trần Điểm thưởng/kỳ theo hạng thẻ.');

            $rules = $tier->tierCategoryRules()->orderBy('sort_order')->orderBy('id')->get();
            $fallbacks = $rules->filter(fn (PolicyTierCategory $r): bool => $r->isFallback());
            $comboRules = $rules->reject(fn (PolicyTierCategory $r): bool => $r->isFallback());

            $this->assertCount(1, $fallbacks, 'Đúng 1 rule nền cho chi tiêu ngoài DMCT đã đăng ký.');
            $fallback = $fallbacks->first();
            $this->assertSame('0.100', $fallback->cashback_percent);
            $this->assertTrue((bool) $fallback->counts_toward_tier_cap, 'Fallback tính vào trần toàn bậc.');
            $this->assertNull($fallback->category_id);
            $this->assertNull($fallback->combo_id);
            $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallback->scope_type);

            // Số rule combo = số DMCT trong CẤU HÌNH KHỞI TẠO của hạng.
            $this->assertSame(
                self::DEFAULT_COMBOS[$plan],
                $comboRules->map(fn (PolicyTierCategory $r): string => $r->combo->slug)->values()->all(),
                'Chỉ DMCT đăng ký khởi tạo có rule tỷ lệ ưu đãi.'
            );

            foreach ($comboRules as $rule) {
                $this->assertSame(self::PLAN_PERCENT[$plan], $rule->cashback_percent, 'Mọi DMCT đăng ký cùng nhận tỷ lệ của hạng.');
                $this->assertTrue((bool) $rule->counts_toward_tier_cap);
                $this->assertSame('0.00', $rule->spend_from);
                $this->assertNull($rule->spend_to);
                $this->assertNull($rule->max_cashback_per_transaction, 'Không cap theo giao dịch (cap toàn bậc).');
                $this->assertNull($rule->max_cashback_per_category_per_period, 'Không cap theo danh mục (cap toàn bậc).');
            }
        }
    }

    #[Test]
    public function the_five_one_card_combos_exist_with_disjoint_memberships_over_system_categories(): void
    {
        $this->creditCardSeeder->run();

        $seenCategoryIds = [];

        foreach (self::COMBO_GROUPS as $slug => $categorySlugs) {
            $combo = $this->comboBySlug($slug);

            $this->assertSame('system', $combo->scope);
            $this->assertSame(self::COMBO_NAMES[$slug], $combo->name);

            $membership = $combo->items()->pluck('category_id')->all();
            $this->assertNotEmpty($membership);

            foreach ($categorySlugs as $categorySlug) {
                $category = $this->categoryBySlug($categorySlug);
                $this->assertContains(
                    (int) $category->id,
                    $membership,
                    "Combo {$slug} phải chứa danh mục {$categorySlug}."
                );
            }

            foreach ($membership as $categoryId) {
                $this->assertArrayNotHasKey(
                    (int) $categoryId,
                    $seenCategoryIds,
                    'Một danh mục không được nằm trong hai combo "kho" của One Card.'
                );
                $seenCategoryIds[(int) $categoryId] = true;
            }
        }
    }

    #[Test]
    public function seeder_is_idempotent_for_one_card_templates_and_combos(): void
    {
        $this->creditCardSeeder->run();
        $this->creditCardSeeder->run();

        foreach (self::SLUGS as $plan => $slug) {
            $this->assertSame(
                1,
                PolicyTemplate::query()->system()->where('slug', $slug)->count(),
                "Chạy lại không được tạo template thứ hai ({$slug})."
            );

            $template = $this->seededTemplate($slug);
            $this->assertSame(1, $template->blueprints()->count());
            $tier = $template->blueprint()->firstOrFail()->tiers()->firstOrFail();

            $this->assertSame(1, $tier->tierCategoryRules()->fallback()->count());
            $this->assertSame(count(self::DEFAULT_COMBOS[$plan]) + 1, $tier->tierCategoryRules()->count());
        }

        foreach (array_keys(self::COMBO_GROUPS) as $slug) {
            $combo = $this->comboBySlug($slug);
            $members = array_map('intval', $combo->items()->pluck('category_id')->all());
            $this->assertSame($members, array_values(array_unique($members)), 'Membership không bị nhân bản.');
        }
    }

    #[Test]
    public function the_templates_contain_no_hidden_merchant_rates(): void
    {
        $this->creditCardSeeder->run();

        $percentages = [];

        foreach (self::SLUGS as $slug) {
            $rules = $this->seededTemplate($slug)
                ->blueprint()->firstOrFail()->tiers()->firstOrFail()
                ->tierCategoryRules()->get();

            foreach ($rules as $rule) {
                $percentages[(string) $rule->cashback_percent] = true;
            }
        }

        $unique = array_values(array_unique(array_keys($percentages)));
        sort($unique);

        $this->assertSame(
            ['0.100', '3.000', '6.000', '9.000'],
            $unique,
            'Chỉ có nền 0,1% và 3 tỷ lệ theo hạng — không có mức ẩn khác.'
        );
    }

    // =====================================================================
    // B · Áp dụng sản phẩm + recorded_by_user_id
    // =====================================================================

    #[Test]
    public function one_card_templates_attach_to_a_matching_vib_card_and_record_the_actor(): void
    {
        $this->creditCardSeeder->run();

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB One Card Visa Signature',
        ]);

        $version = $this->cloner->attachTemplateToCard(
            $card,
            $this->seededTemplate(self::SLUGS['signature']),
            CarbonImmutable::parse('2026-10-01'),
        );

        $this->assertNotNull($version);
        $this->assertSame((int) $card->id, (int) $version->user_card_id);
        $this->assertSame(1, (int) $version->version_no);

        $owned = Policy::query()->where('user_card_id', $card->id)->where('version_no', 1)->firstOrFail();
        $this->assertSame((int) $this->user->id, (int) $owned->recorded_by_user_id, 'Người đặt chính sách = chủ thẻ.');
        $this->assertSame((int) $version->id, (int) $card->refresh()->current_policy_id);
    }

    #[Test]
    public function mismatched_bank_or_product_name_is_rejected_at_the_service_choke_point(): void
    {
        $this->creditCardSeeder->run();

        $template = $this->seededTemplate(self::SLUGS['gold']);
        $effective = CarbonImmutable::parse('2026-10-01');

        // Sai ngân hàng (có đúng tên sản phẩm).
        $otherBankCard = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->makeBank()->id,
            'name' => 'VIB One Card Visa',
        ]);

        try {
            $this->policies->cloneUserTemplate($otherBankCard, (int) $template->id, $effective);
            $this->fail('Template One Card không được gắn cho thẻ ngân hàng khác.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('không áp dụng', $e->getMessage());
        }

        // Đúng ngân hàng nhưng tên không chứa từ khoá sản phẩm.
        $plainVibCard = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB Max Card Visa',
        ]);

        try {
            $this->policies->cloneUserTemplate($plainVibCard, (int) $template->id, $effective);
            $this->fail('Template One Card không được gắn cho thẻ VIB không phải dòng One Card.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('không áp dụng', $e->getMessage());
        }

        // Cả hai thẻ đều KHÔNG có policy nào bị tạo.
        $this->assertSame(0, Policy::query()->whereIn('user_card_id', [$otherBankCard->id, $plainVibCard->id])->count());
    }

    #[Test]
    public function the_card_api_rejects_a_mismatched_template_with_422_and_creates_no_card(): void
    {
        $this->creditCardSeeder->run();

        $this->actingAs($this->user)
            ->postJson('/thetindung/api/the', [
                'name' => 'VIB Max Card Visa',
                'bank_id' => $this->vibBank()->id,
                'statement_period_start' => '2026-10-01',
                'policy' => [
                    'template_id' => $this->seededTemplate(self::SLUGS['gold'])->id,
                    'effective_from' => '2026-10-01',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Chính sách đã chọn không áp dụng cho thẻ này (sản phẩm/ngân hàng không khớp).');

        $this->assertSame(0, UserCard::query()->count(), 'Thẻ không được để lại mồ côi khi policy bị từ chối.');
    }

    // =====================================================================
    // C · Lịch sử chính sách + dedupe re-save
    // =====================================================================

    #[Test]
    public function card_api_attaches_the_template_and_records_the_history_row(): void
    {
        $this->creditCardSeeder->run();

        $template = $this->seededTemplate(self::SLUGS['platinum']);

        $response = $this->actingAs($this->user)
            ->postJson('/thetindung/api/the', [
                'name' => 'VIB One Card Visa',
                'bank_id' => $this->vibBank()->id,
                'statement_period_start' => '2026-10-01',
                'policy' => [
                    'template_id' => $template->id,
                    'effective_from' => '2026-10-01',
                ],
            ])
            ->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));
        $owned = Policy::query()->where('user_card_id', $card->id)->firstOrFail();
        $this->assertSame((int) $this->user->id, (int) $owned->recorded_by_user_id);
        $this->assertSame(1, (int) $owned->version_no);

        $history = $this->actingAs($this->user)
            ->getJson("/thetindung/api/the/{$card->id}/lich-su-chinh-sach")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $history);
        $this->assertSame((int) $owned->id, (int) $history[0]['policy_id']);
        $this->assertSame((int) $template->id, (int) $history[0]['template_id']);
        $this->assertSame('VIB One Card Platinum', $history[0]['template_name']);
        $this->assertSame(1, (int) $history[0]['version_no']);
        $this->assertSame('2026-10-01', $history[0]['effective_from']);
        $this->assertNull($history[0]['previous_name'], 'Dòng đầu tiên chưa có gì phía trước.');
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame((int) $this->user->id, (int) $history[0]['recorded_by_user_id']);
        $this->assertNotNull($history[0]['recorded_at']);
    }

    #[Test]
    public function switching_template_appends_a_history_row_with_previous_name(): void
    {
        $this->creditCardSeeder->run();

        $gold = $this->seededTemplate(self::SLUGS['gold']);
        $signature = $this->seededTemplate(self::SLUGS['signature']);

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB One Card Visa',
        ]);
        $this->cloner->attachTemplateToCard($card, $gold, CarbonImmutable::parse('2026-10-01'));

        // Đổi sang Signature — tạo version 1 mới theo template khác (append-only).
        $this->actingAs($this->user)
            ->patchJson("/thetindung/api/the/{$card->id}", [
                'name' => 'VIB One Card Visa',
                'bank_id' => $this->vibBank()->id,
                'policy' => [
                    'template_id' => $signature->id,
                    'effective_from' => '2026-10-01',
                ],
            ])
            ->assertOk();

        $card->refresh();
        $history = $this->actingAs($this->user)
            ->getJson("/thetindung/api/the/{$card->id}/lich-su-chinh-sach")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $history);

        $this->assertSame('VIB One Card Signature', $history[0]['name'], 'Dòng mới nhất ở đầu danh sách.');
        $this->assertSame('VIB One Card Gold', $history[0]['previous_name'], 'previous_name = chính sách đang chạy trước đó.');
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame((int) $card->current_policy_id, (int) $history[0]['policy_id']);

        $this->assertSame('VIB One Card Gold', $history[1]['name']);
        $this->assertNull($history[1]['previous_name']);
        $this->assertFalse($history[1]['is_current']);
    }

    // =====================================================================
    // D · Hành vi engine (CashbackRecordService thật)
    // =====================================================================

    #[Test]
    public function engine_applies_registered_dmct_rate_and_keeps_others_at_01_percent(): void
    {
        $card = $this->makeVibOneCardWithTemplate('gold');

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $shopping = $this->addTxn($card, $period, '2026-09-05', '2000000', $this->categoryBySlug('sieu-thi-tien-loi'));
        $fuel = $this->addTxn($card, $period, '2026-09-06', '1000000', $this->categoryBySlug('xang-dau'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('3.000', $shopping->refresh()->cashback_percent_snapshot, 'Mua sắm là DMCT đăng ký của Gold ⇒ tỷ lệ 3%.');
        $this->assertSame('60000.00', $shopping->refresh()->cashback_amount_snapshot);

        $this->assertSame('0.100', $fuel->refresh()->cashback_percent_snapshot, 'Xăng dầu không nằm trong DMCT đăng ký ⇒ nền 0,1%.');
        $this->assertSame('1000.00', $fuel->refresh()->cashback_amount_snapshot);

        $this->assertSame('61000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function engine_applies_the_plan_rate_to_every_registered_dmct_of_platinum(): void
    {
        $card = $this->makeVibOneCardWithTemplate('platinum');

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $shopping = $this->addTxn($card, $period, '2026-09-05', '1000000', $this->categoryBySlug('sieu-thi-tien-loi'));
        $insurance = $this->addTxn($card, $period, '2026-09-06', '500000', $this->categoryBySlug('bao-hiem'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('6.000', $shopping->refresh()->cashback_percent_snapshot, 'Mua sắm đăng ký ⇒ 6%.');
        $this->assertSame('60000.00', $shopping->refresh()->cashback_amount_snapshot);

        $this->assertSame('6.000', $insurance->refresh()->cashback_percent_snapshot, 'Bảo hiểm đăng ký ⇒ 6%.');
        $this->assertSame('30000.00', $insurance->refresh()->cashback_amount_snapshot);

        $this->assertSame('90000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function engine_keeps_an_unregistered_dmct_at_the_01_fallback(): void
    {
        $card = $this->makeVibOneCardWithTemplate('gold');

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $travel = $this->addTxn($card, $period, '2026-09-05', '2000000', $this->categoryBySlug('ve-may-bay-du-lich'));

        $this->records->calculatePeriod($card, $period);

        // Du lịch nằm trong "kho" DMCT nhưng KHÔNG phải DMCT đăng ký của Gold
        // (blueprint chỉ có Mua sắm) ⇒ phải rơi về nền 0,1%, không nhận tỷ lệ 3%.
        $this->assertSame('0.100', $travel->refresh()->cashback_percent_snapshot);
        $this->assertSame('2000.00', $travel->refresh()->cashback_amount_snapshot);
        $this->assertSame('2000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function engine_enforces_the_plan_tier_cap_against_dmct_cashback(): void
    {
        $card = $this->makeVibOneCardWithTemplate('signature');

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $shopping = $this->addTxn($card, $period, '2026-09-05', '20000000', $this->categoryBySlug('sieu-thi-tien-loi'));

        $this->records->calculatePeriod($card, $period);

        // 9% × 20tr = 1.800.000 thô ⇒ chặn ở trần toàn bậc của Signature (1.500.000).
        $this->assertSame('9.000', $shopping->refresh()->cashback_percent_snapshot);
        $this->assertSame('1500000.00', $shopping->refresh()->cashback_amount_snapshot);
        $this->assertSame('1500000.00', $period->refresh()->total_cashback);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function vibBank(): Bank
    {
        $bank = Bank::query()->where('slug', self::VIB_BANK_SLUG)->first();

        $this->assertNotNull($bank, 'Seeder phải tạo ngân hàng VIB.');

        return $bank;
    }

    private function seededTemplate(string $slug): PolicyTemplate
    {
        $template = PolicyTemplate::query()->system()->where('slug', $slug)->first();

        $this->assertNotNull($template, "Seeder phải tạo template {$slug}.");

        return $template;
    }

    private function comboBySlug(string $slug)
    {
        $combo = CategoryCombo::query()->system()->where('slug', $slug)->first();

        $this->assertNotNull($combo, "Seeder phải tạo combo {$slug}.");

        return $combo;
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

    private function makeVibOneCardWithTemplate(string $plan): UserCard
    {
        $this->creditCardSeeder->run();

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB One Card Visa',
        ]);

        $version = $this->cloner->attachTemplateToCard(
            $card,
            $this->seededTemplate(self::SLUGS[$plan]),
            CarbonImmutable::parse('2026-09-01'),
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
}
