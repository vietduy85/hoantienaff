<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
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
 * Chính sách hệ thống "VIB Max Card Visa" — seeded từ CreditCardSeeder.
 *
 * ---------------------------------------------------------------------------
 * CẤU TRÚC (báo cáo Review • X.7)
 * ---------------------------------------------------------------------------
 * 4 hạng thẻ (Starter / Plus / Pro / Elite), mỗi chính sách MỘT bậc chi tiêu
 * duy nhất phủ từ 0đ:
 *   - 5 MỤC TIÊU chi tiêu ở TỶ LỆ ƯU ĐÃI của hạng: 3 nhóm nhiều danh mục (combo
 *     hệ thống: Mua sắm, Du lịch, Online) + 2 nhóm một danh mục trỏ thẳng
 *     (Ẩm thực, Giải trí);
 *   - mọi danh mục ngoài các mục tiêu trên rơi về nền 0,1%;
 *   - trần hoàn tiền/THÁNG theo hạng (Cap 3 — trần TOÀN BẬC).
 * Cờ `counts_toward_tier_cap = true` cho CẢ rule nhóm lẫn fallback.
 *
 * Chính sách còn bị ràng buộc chỉ chọn được cho thẻ VIB có tên chứa từ khoá
 * sản phẩm "max card visa" (`applicable_bank_id` + `applicable_product_match`).
 */
class VibMaxCardVisaPolicyTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private const VIB_BANK_SLUG = 'vib';

    private const SLUGS = [
        'starter' => 'vib-max-card-visa-starter',
        'plus' => 'vib-max-card-visa-plus',
        'pro' => 'vib-max-card-visa-pro',
        'elite' => 'vib-max-card-visa-elite',
    ];

    /** Danh mục hệ thống đại diện cho từng nhóm nhiều danh mục (đều có trong bộ seed gốc). */
    private const COMBO_GROUPS = [
        'vib-shopping' => ['sieu-thi-tien-loi', 'thoi-trang'],
        'vib-travel' => ['ve-may-bay-du-lich', 'khach-san-luu-tru', 'chi-tieu-nuoc-ngoai'],
        'vib-online' => ['mua-sam-truc-tuyen', 'san-thuong-mai-dien-tu', 'shopee', 'tiki', 'lazada', 'tiktok'],
    ];

    /** 2 nhóm một danh mục — rule trỏ THẲNG `category_id`, không bọc combo nữa. */
    private const DIRECT_CATEGORY_SLUGS = [
        'am-thuc-an-uong',
        'ung-dung-so-dich-vu-giai-tri',
    ];

    private const PLAN_PERCENT = [
        'starter' => '0.500',
        'plus' => '5.000',
        'pro' => '8.000',
        'elite' => '10.000',
    ];

    private const PLAN_CAP = [
        'starter' => '500000.00',
        'plus' => '500000.00',
        'pro' => '1000000.00',
        'elite' => '1500000.00',
    ];

    /** Nhãn hiển thị của từng combo (slug ⇒ name). */
    private const COMBO_NAMES = [
        'vib-shopping' => 'Mua sắm — Shopping',
        'vib-travel' => 'Du lịch — Travel',
        'vib-online' => 'Giao dịch trực tuyến — Online Transactions',
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
    public function seeder_creates_the_four_vib_max_card_visa_templates_with_applicability(): void
    {
        $this->creditCardSeeder->run();

        $vib = $this->vibBank();

        foreach (self::SLUGS as $plan => $slug) {
            $template = $this->seededTemplate($slug);

            $this->assertSame('VIB Max Card Visa - '.ucfirst($plan), $template->name);
            $this->assertSame(PolicyTemplate::SCOPE_SYSTEM, $template->scope);
            $this->assertSame(PolicyTemplate::SYSTEM_OWNER_ID, (int) $template->owner_user_id);
            $this->assertTrue((bool) $template->is_builtin);
            $this->assertTrue((bool) $template->is_active);
            $this->assertSame((int) $vib->id, (int) $template->applicable_bank_id, 'Chỉ áp dụng cho thẻ ngân hàng VIB.');
            $this->assertSame('max card visa', $template->applicable_product_match, 'Tên thẻ phải chứa từ khoá sản phẩm.');

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
    public function each_template_has_one_tier_two_direct_category_rules_three_combo_rules_and_a_01_fallback(): void
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
            $this->assertSame(self::PLAN_CAP[$plan], $tier->max_cashback_per_period, 'Trần hoàn tiền/tháng theo hạng thẻ.');

            $rules = $tier->tierCategoryRules()->orderBy('sort_order')->orderBy('id')->get();
            $fallbacks = $rules->filter(fn (PolicyTierCategory $r): bool => $r->isFallback());
            $targets = $rules->reject(fn (PolicyTierCategory $r): bool => $r->isFallback())->values();

            $this->assertCount(1, $fallbacks, 'Đúng 1 rule nền cho mọi danh mục ngoài các mục tiêu.');
            $fallback = $fallbacks->first();
            $this->assertSame('0.100', $fallback->cashback_percent);
            $this->assertTrue((bool) $fallback->counts_toward_tier_cap, 'Fallback tính vào trần toàn bậc.');
            $this->assertNull($fallback->category_id);
            $this->assertNull($fallback->combo_id);
            $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallback->scope_type);

            // 5 mục tiêu ưu đãi = 2 danh mục trực tiếp (đứng trước) + 3 combo.
            $this->assertCount(5, $targets, '5 mục tiêu ưu đãi (2 danh mục + 3 combo).');
            $this->assertSame(
                self::DIRECT_CATEGORY_SLUGS,
                $targets->take(2)->map(fn (PolicyTierCategory $r): string => $r->category->slug)->all(),
                'Hai nhóm một danh mục trỏ thẳng `category_id`.'
            );
            $this->assertSame(
                array_keys(self::COMBO_GROUPS),
                $targets->slice(2)->map(fn (PolicyTierCategory $r): string => $r->combo->slug)->values()->all(),
                'Ba nhóm nhiều danh mục vẫn gom bằng combo.'
            );

            foreach ($targets as $rule) {
                $this->assertSame(self::PLAN_PERCENT[$plan], $rule->cashback_percent, 'Các mục tiêu đều nhận tỷ lệ ưu đãi của hạng.');
                $this->assertTrue((bool) $rule->counts_toward_tier_cap);
                $this->assertSame('0.00', $rule->spend_from);
                $this->assertNull($rule->spend_to);
                $this->assertNull($rule->max_cashback_per_transaction, 'Không cap theo giao dịch (Cap 3 toàn bậc).');
                $this->assertNull($rule->max_cashback_per_category_per_period, 'Không cap theo danh mục (Cap 3 toàn bậc).');
            }
        }
    }

    #[Test]
    public function the_three_multi_member_combos_exist_with_disjoint_memberships(): void
    {
        $this->creditCardSeeder->run();

        $seenCategoryIds = [];

        foreach (self::COMBO_GROUPS as $slug => $categorySlugs) {
            $combo = $this->comboBySlug($slug);

            $this->assertSame('system', $combo->scope);
            $this->assertSame(self::COMBO_NAMES[$slug], $combo->name);
            $this->assertGreaterThanOrEqual(2, $combo->items()->count(), 'Combo chính sách phải có từ 2 danh mục trở lên.');

            $membership = $combo->items()->pluck('category_id')->all();

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
                    'Một danh mục không được nằm trong hai combo của chính sách.'
                );
                $seenCategoryIds[(int) $categoryId] = true;
            }
        }

        // Hai nhóm một danh mục không còn dựng combo; danh mục vẫn tồn tại để rule trỏ thẳng.
        foreach (self::DIRECT_CATEGORY_SLUGS as $categorySlug) {
            $this->categoryBySlug($categorySlug);
        }

        foreach (['vib-dining', 'vib-entertainment'] as $removed) {
            $this->assertNull(
                \App\Models\CreditCard\CategoryCombo::query()->system()->where('slug', $removed)->first(),
                "Combo một-phần-tử {$removed} không được seed nữa."
            );
        }
    }

    #[Test]
    public function seeder_is_idempotent_for_vib_templates_and_combos(): void
    {
        $this->creditCardSeeder->run();
        $this->creditCardSeeder->run();

        foreach (self::SLUGS as $slug) {
            $this->assertSame(
                1,
                PolicyTemplate::query()->system()->where('slug', $slug)->count(),
                "Chạy lại không được tạo template thứ hai ({$slug})."
            );

            $template = $this->seededTemplate($slug);
            $this->assertSame(1, $template->blueprints()->count());
            $tier = $template->blueprint()->firstOrFail()->tiers()->firstOrFail();

            $this->assertSame(1, $tier->tierCategoryRules()->fallback()->count());
            $this->assertSame(6, $tier->tierCategoryRules()->count());
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
            ['0.100', '0.500', '5.000', '8.000', '10.000'],
            $unique,
            'Chỉ có nền 0,1% và 4 tỷ lệ ưu đãi theo hạng — không có mức ẩn khác.'
        );
    }

    // =====================================================================
    // B · Áp dụng sản phẩm + recorded_by_user_id
    // =====================================================================

    #[Test]
    public function vib_templates_attach_to_a_matching_vib_max_card_and_record_the_actor(): void
    {
        $this->creditCardSeeder->run();

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB Max Card Visa Elite',
        ]);

        $version = $this->cloner->attachTemplateToCard(
            $card,
            $this->seededTemplate(self::SLUGS['elite']),
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

        $template = $this->seededTemplate(self::SLUGS['pro']);
        $effective = CarbonImmutable::parse('2026-10-01');

        // Sai ngân hàng (có đúng tên sản phẩm).
        $otherBankCard = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->makeBank()->id,
            'name' => 'VIB Max Card Visa',
        ]);

        try {
            $this->policies->cloneUserTemplate($otherBankCard, (int) $template->id, $effective);
            $this->fail('Template VIB không được gắn cho thẻ ngân hàng khác.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('không áp dụng', $e->getMessage());
        }

        // Đúng ngân hàng nhưng tên không chứa từ khoá sản phẩm.
        $plainVibCard = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB Cashback Thường',
        ]);

        try {
            $this->policies->cloneUserTemplate($plainVibCard, (int) $template->id, $effective);
            $this->fail('Template VIB Max không được gắn cho thẻ VIB không phải dòng Max Card Visa.');
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
                'name' => 'VIB Cashback Thường',
                'bank_id' => $this->vibBank()->id,
                'statement_period_start' => '2026-10-01',
                'policy' => [
                    'template_id' => $this->seededTemplate(self::SLUGS['pro'])->id,
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

        $template = $this->seededTemplate(self::SLUGS['pro']);

        $response = $this->actingAs($this->user)
            ->postJson('/thetindung/api/the', [
                'name' => 'VIB Max Card Visa',
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
        $this->assertSame('VIB Max Card Visa - Pro', $history[0]['template_name']);
        $this->assertSame(1, (int) $history[0]['version_no']);
        $this->assertSame('2026-10-01', $history[0]['effective_from']);
        $this->assertNull($history[0]['previous_name'], 'Dòng đầu tiên chưa có gì phía trước.');
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame((int) $this->user->id, (int) $history[0]['recorded_by_user_id']);
        $this->assertNotNull($history[0]['recorded_at']);
    }

    #[Test]
    public function re_saving_without_changes_does_not_add_a_history_row(): void
    {
        $this->creditCardSeeder->run();

        $template = $this->seededTemplate(self::SLUGS['pro']);

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB Max Card Visa',
        ]);
        $this->cloner->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));
        $before = $card->refresh()->current_policy_id;

        // Lưu thẻ lần nữa với ĐÚNG mẫu đang chạy + cùng ngày hiệu lực, không đổi gì.
        $this->actingAs($this->user)
            ->patchJson("/thetindung/api/the/{$card->id}", [
                'name' => 'VIB Max Card Visa',
                'bank_id' => $this->vibBank()->id,
                'policy' => [
                    'template_id' => $template->id,
                    'effective_from' => '2026-10-01',
                ],
            ])
            ->assertOk();

        $history = $this->actingAs($this->user)
            ->getJson("/thetindung/api/the/{$card->id}/lich-su-chinh-sach")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $history, 'Re-save không thay đổi gì thì KHÔNG được sinh history row mới.');
        $this->assertSame((int) $before, (int) $card->refresh()->current_policy_id, 'Policy đang chạy giữ nguyên.');
    }

    #[Test]
    public function switching_template_appends_a_history_row_with_previous_name(): void
    {
        $this->creditCardSeeder->run();

        $pro = $this->seededTemplate(self::SLUGS['pro']);
        $starter = $this->seededTemplate(self::SLUGS['starter']);

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB Max Card Visa',
        ]);
        $this->cloner->attachTemplateToCard($card, $pro, CarbonImmutable::parse('2026-10-01'));

        // Đổi sang Starter — tạo version 1 mới theo template khác (append-only).
        $this->actingAs($this->user)
            ->patchJson("/thetindung/api/the/{$card->id}", [
                'name' => 'VIB Max Card Visa',
                'bank_id' => $this->vibBank()->id,
                'policy' => [
                    'template_id' => $starter->id,
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

        $this->assertSame('VIB Max Card Visa - Starter', $history[0]['name'], 'Dòng mới nhất ở đầu danh sách.');
        $this->assertSame('VIB Max Card Visa - Pro', $history[0]['previous_name'], 'previous_name = chính sách đang chạy trước đó.');
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame((int) $card->current_policy_id, (int) $history[0]['policy_id']);

        $this->assertSame('VIB Max Card Visa - Pro', $history[1]['name']);
        $this->assertNull($history[1]['previous_name']);
        $this->assertFalse($history[1]['is_current']);
    }

    #[Test]
    public function history_is_scoped_to_own_cards(): void
    {
        $this->creditCardSeeder->run();

        $other = User::factory()->create();
        $card = $this->makeUserCard($other->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB Max Card Visa',
        ]);
        $this->cloner->attachTemplateToCard($card, $this->seededTemplate(self::SLUGS['starter']), CarbonImmutable::parse('2026-10-01'));

        $this->actingAs($this->user)
            ->getJson("/thetindung/api/the/{$card->id}/lich-su-chinh-sach")
            ->assertForbidden();

        $this->actingAs($this->user)
            ->getJson('/thetindung/api/the/999999/lich-su-chinh-sach')
            ->assertNotFound();
    }

    // =====================================================================
    // D · Hành vi engine (CashbackRecordService thật)
    // =====================================================================

    #[Test]
    public function engine_applies_the_combo_rate_and_keeps_outside_categories_at_01_percent(): void
    {
        $card = $this->makeVibCardWithTemplate('starter');

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $dining = $this->addTxn($card, $period, '2026-09-05', '2000000', $this->categoryBySlug('am-thuc-an-uong'));
        $fuel = $this->addTxn($card, $period, '2026-09-06', '1000000', $this->categoryBySlug('xang-dau'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('0.500', $dining->refresh()->cashback_percent_snapshot, 'Ẩm thực thuộc nhóm Dining ⇒ tỷ lệ hạng Starter.');
        $this->assertSame('10000.00', $dining->refresh()->cashback_amount_snapshot);

        $this->assertSame('0.100', $fuel->refresh()->cashback_percent_snapshot, 'Xăng dầu ngoài 5 nhóm ⇒ nền 0,1%.');
        $this->assertSame('1000.00', $fuel->refresh()->cashback_amount_snapshot);

        $this->assertSame('11000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function engine_enforces_the_plan_tier_cap_against_combo_cashback(): void
    {
        $card = $this->makeVibCardWithTemplate('elite');

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $dining = $this->addTxn($card, $period, '2026-09-05', '20000000', $this->categoryBySlug('am-thuc-an-uong'));

        $this->records->calculatePeriod($card, $period);

        // 10% × 20tr = 2.000.000 thô ⇒ chặn ở trần toàn bậc của Elite (1.500.000).
        $this->assertSame('10.000', $dining->refresh()->cashback_percent_snapshot);
        $this->assertSame('1500000.00', $dining->refresh()->cashback_amount_snapshot);
        $this->assertSame('1500000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function switching_template_recalculates_open_but_keeps_finalized_periods_untouched(): void
    {
        $card = $this->makeVibCardWithTemplate('starter');
        $templateId = $this->seededTemplate(self::SLUGS['starter'])->id;

        // Kỳ cũ [2026-08-16..09-15] (sau effective của bản Starter 09-01) với 1
        // giao dịch nhóm Dining, rồi FINALIZE.
        $txOld = $this->addTxn($card, $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10')), '2026-09-05', '2000000', $this->categoryBySlug('am-thuc-an-uong'));
        $oldPeriod = StatementPeriod::findOrFail($txOld->statement_period_id);
        $this->records->calculatePeriod($card, $oldPeriod);
        $this->assertSame('10000.00', $oldPeriod->refresh()->total_cashback);
        $oldPeriod->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])->save();

        // Kỳ hiện tại [2026-09-16..10-15] open cũng có giao dịch → đổi sang Plus phải recalc.
        $open = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $txNew = $this->addTxn($card, $open, '2026-10-05', '2000000', $this->categoryBySlug('am-thuc-an-uong'));
        $this->records->calculatePeriod($card, $open);

        $beforeOpen = $open->refresh()->total_cashback;
        $oldPolicy = $oldPeriod->refresh()->policy_id;

        $this->actingAs($this->user)
            ->patchJson("/thetindung/api/the/{$card->id}", [
                'name' => $card->name,
                'bank_id' => $card->bank_id,
                'policy' => [
                    'template_id' => (int) $this->seededTemplate(self::SLUGS['plus'])->id,
                    'effective_from' => '2026-09-05',
                ],
            ])
            ->assertOk();

        // Kỳ OPEN: đã tính lại theo Plus (5% ⇒ 100.000).
        $open = $open->refresh();
        $txNew->refresh();

        $this->assertSame((int) $card->refresh()->current_policy_id, (int) $open->policy_id, 'Kỳ open re-attach version clone Plus.');
        $this->assertSame('100000.00', $open->total_cashback);
        $this->assertSame('5.000', $txNew->cashback_percent_snapshot);
        $this->assertSame('100000.00', $txNew->cashback_amount_snapshot);
        $this->assertNotSame($beforeOpen, $open->total_cashback);

        // Kỳ FINALIZED: nguyên vẹn (policy cũ, số cũ, snapshot cũ).
        $oldPeriod->refresh();
        $txOld->refresh();

        $this->assertSame(StatementPeriod::STATUS_FINALIZED, $oldPeriod->status);
        $this->assertSame((int) $oldPolicy, (int) $oldPeriod->policy_id, 'Kỳ finalized giữ nguyên version cũ.');
        $this->assertSame('10000.00', $oldPeriod->total_cashback);
        $this->assertSame('0.500', $txOld->cashback_percent_snapshot);
        $this->assertSame('10000.00', $txOld->cashback_amount_snapshot);
        $this->assertFalse((bool) Policy::findOrFail($oldPolicy)->is_locked, 'Finalize khoá kỳ chứ không khoá policy.');
        $this->assertSame((int) $templateId, (int) $oldPeriod->policy?->template_id, 'Kỳ finalized vẫn nhớ nguồn template cũ.');
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
        $combo = \App\Models\CreditCard\CategoryCombo::query()->system()->where('slug', $slug)->first();

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

    private function makeVibCardWithTemplate(string $plan): UserCard
    {
        $this->creditCardSeeder->run();

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->vibBank()->id,
            'name' => 'VIB Max Card Visa',
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