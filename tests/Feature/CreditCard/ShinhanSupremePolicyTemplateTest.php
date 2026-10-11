<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\Transaction;
use App\Models\User;
use App\Services\CreditCard\CashbackRecordService;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use Database\Seeders\CreditCardSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Chính sách hệ thống "Shinhan Supreme Visa" — seeded từ CreditCardSeeder.
 *
 * Nguồn: PDF "Hoan tien Shinhan Supreme Card_VN" (bản tiếng Việt). Cấu trúc 3
 * bậc RETROACTIVE theo TỔNG chi tiêu hợp lệ của kỳ sao kê.
 */
class ShinhanSupremePolicyTemplateTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private const SLUG = 'shinhan-supreme-visa';

    private const BUILTIN_SLUG = 'cashback-pho-thong';

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

    // ---------------------------------------------------------------------
    // Cấu trúc template
    // ---------------------------------------------------------------------

    #[Test]
    public function seeder_creates_the_shinhan_supreme_template_with_expected_metadata(): void
    {
        $this->creditCardSeeder->run();

        $template = $this->seededTemplate();

        $this->assertSame('Shinhan Supreme Visa', $template->name);
        $this->assertSame(PolicyTemplate::SCOPE_SYSTEM, $template->scope);
        $this->assertSame(PolicyTemplate::SYSTEM_OWNER_ID, (int) $template->owner_user_id);
        $this->assertTrue((bool) $template->is_builtin);
        $this->assertTrue((bool) $template->is_active);

        $blueprint = $template->blueprint()->first();
        $this->assertNotNull($blueprint);
        $this->assertNull($blueprint->user_card_id);
        $this->assertSame((int) $template->id, (int) $blueprint->template_id);
        $this->assertSame(1, (int) $blueprint->version_no);
        $this->assertSame(Policy::STATUS_ACTIVE, $blueprint->status);
        $this->assertSame('0.00', $blueprint->min_total_spend, 'Không có chi tiêu tối thiểu.');
        $this->assertNull($blueprint->max_cashback_total_per_period, 'Trần toàn bậc nằm ở TIER (Cap 3).');
        $this->assertSame('round', $blueprint->rounding_mode);
    }

    #[Test]
    public function template_has_three_tiers_with_the_pdf_ranges_and_caps(): void
    {
        $this->creditCardSeeder->run();

        $tiers = $this->tiersOf($this->seededTemplate());

        $this->assertCount(3, $tiers);

        [$tier1, $tier2, $tier3] = $tiers;

        $this->assertSame('0.00', $tier1->min_total_spend);
        $this->assertSame('3000000.00', $tier1->max_total_spend);
        $this->assertNull($tier1->max_cashback_per_period, 'Bậc 1 không trần hoàn tiền.');

        $this->assertSame('3000000.00', $tier2->min_total_spend);
        $this->assertSame('15000000.00', $tier2->max_total_spend);
        $this->assertSame('300000.00', $tier2->max_cashback_per_period, 'Bậc 2 trần 300.000đ/kỳ.');

        $this->assertSame('15000000.00', $tier3->min_total_spend);
        $this->assertNull($tier3->max_total_spend);
        $this->assertSame('1000000.00', $tier3->max_cashback_per_period, 'Bậc 3 trần 1.000.000đ/kỳ.');
    }

    #[Test]
    public function tier_one_has_only_the_03_fallback(): void
    {
        $this->creditCardSeeder->run();

        $tiers = $this->tiersOf($this->seededTemplate());
        $rules = $tiers[0]->tierCategoryRules()->get();

        $this->assertCount(1, $rules, 'Bậc 1 CHỈ có fallback — danh mục đặc biệt phải về 0,3%.');
        $this->assertTrue($rules->first()->isFallback());
        $this->assertSame('0.300', $rules->first()->cashback_percent);
        $this->assertTrue((bool) $rules->first()->counts_toward_tier_cap);
    }

    #[Test]
    public function tiers_two_and_three_share_the_pdf_specific_rates_and_a_03_fallback(): void
    {
        $this->creditCardSeeder->run();

        $tiers = $this->tiersOf($this->seededTemplate());

        foreach ([$tiers[1], $tiers[2]] as $tier) {
            $rules = $tier->tierCategoryRules()->orderBy('sort_order')->orderBy('id')->get();
            $fallbacks = $rules->filter(fn (PolicyTierCategory $rule): bool => $rule->isFallback());
            $specifics = $rules->reject(fn (PolicyTierCategory $rule): bool => $rule->isFallback());

            $this->assertCount(1, $fallbacks, 'Mỗi bậc có đúng 1 fallback.');
            $this->assertCount(4, $specifics, 'Bậc có 4 rule danh mục đặc biệt.');
            $this->assertSame(5, $rules->count());

            $fallback = $fallbacks->first();
            $this->assertSame('0.300', $fallback->cashback_percent);
            $this->assertTrue((bool) $fallback->counts_toward_tier_cap, 'Fallback Shinhan tính vào trần toàn bậc.');
            $this->assertNull($fallback->category_id);
            $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallback->scope_type);

            $this->assertSame(
                ['san-thuong-mai-dien-tu', 'giao-duc-hoc-phi', 'y-te-benh-vien', 'bao-hiem'],
                $specifics->map(fn (PolicyTierCategory $rule): ?string => $rule->category?->slug)->values()->all(),
                'Ánh xạ danh mục theo danh mục hệ thống có sẵn.'
            );
            $this->assertSame(
                ['12.000', '12.000', '6.000', '6.000'],
                $specifics->map(fn (PolicyTierCategory $rule): string => $rule->cashback_percent)->values()->all()
            );

            foreach ($specifics as $rule) {
                $this->assertSame('0.00', $rule->spend_from, 'Một mức duy nhất "từ 0".');
                $this->assertNull($rule->spend_to);
                $this->assertNull($rule->max_cashback_per_transaction, 'Không cap theo giao dịch (chỉ Cap 3 toàn bậc).');
                $this->assertNull($rule->max_cashback_per_category_per_period, 'Không cap theo danh mục (chỉ Cap 3 toàn bậc).');
                $this->assertTrue((bool) $rule->counts_toward_tier_cap);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Idempotent & không đụng dữ liệu khác
    // ---------------------------------------------------------------------

    #[Test]
    public function seeder_is_idempotent_for_the_shinhan_template(): void
    {
        $this->creditCardSeeder->run();
        $this->creditCardSeeder->run();

        $this->assertSame(
            1,
            PolicyTemplate::query()->system()->where('slug', self::SLUG)->count(),
            'Chạy lại không tạo template thứ hai.'
        );

        $template = $this->seededTemplate();
        $this->assertSame(1, $template->blueprints()->count(), 'Chỉ một blueprint version 1.');

        foreach ($this->tiersOf($template) as $tier) {
            $this->assertSame(1, $tier->tierCategoryRules()->fallback()->count());
        }

        $this->assertSame(5, $this->tiersOf($template)[1]->tierCategoryRules()->count());
    }

    #[Test]
    public function seeding_keeps_the_builtin_template_untouched(): void
    {
        $this->creditCardSeeder->run();

        $builtin = $this->styleBuiltinTemplate();
        $before = $this->snapshotBlueprint($builtin->blueprint()->firstOrFail());

        $this->creditCardSeeder->run();
        $this->creditCardSeeder->run();

        $this->assertSame(
            $before,
            $this->snapshotBlueprint($builtin->fresh()->blueprint()->firstOrFail()),
            'Seeder chạy lại không được sửa blueprint Cashback phổ thông.'
        );

        $this->assertSame(
            6,
            PolicyTemplate::query()->system()->count(),
            'Đúng 6 template hệ thống: Cashback phổ thông + Shinhan Supreme + 4 bậc VIB Max Card Visa.'
        );
    }

    #[Test]
    public function seeding_does_not_create_user_cards_or_user_policies(): void
    {
        $this->creditCardSeeder->run();

        $this->assertSame(0, Policy::query()->whereNotNull('user_card_id')->count());
    }

    // ---------------------------------------------------------------------
    // Thêm thẻ + clone sang thẻ
    // ---------------------------------------------------------------------

    #[Test]
    public function template_is_selectable_when_attaching_to_a_card(): void
    {
        $this->creditCardSeeder->run();

        $template = $this->seededTemplate();

        $this->assertTrue(
            PolicyTemplate::query()->selectableBy($this->user->id)->whereKey($template->id)->exists(),
            'Template hệ thống đang hoạt động phải chọn được khi thêm thẻ.'
        );

        $card = $this->makeUserCard($this->user->id, ['statement_day' => 31]);
        $version = $this->cloner->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        $this->assertNotNull($version);
        $owned = Policy::query()->where('user_card_id', $card->id)->where('version_no', 1)->first();
        $this->assertNotNull($owned);
        $this->assertSame(3, $owned->tiers()->count());
    }

    #[Test]
    public function attaching_a_card_keeps_the_template_clean_and_preserves_the_fallback_cap_flag(): void
    {
        $this->creditCardSeeder->run();

        $template = $this->seededTemplate();
        $blueprint = $template->blueprint()->firstOrFail();
        $before = $this->snapshotBlueprint($blueprint);

        $card = $this->makeUserCard($this->user->id, ['statement_day' => 31]);
        $version = $this->cloner->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(
            $before,
            $this->snapshotBlueprint($template->blueprint()->firstOrFail()),
            'Gắn template cho thẻ KHÔNG được sửa blueprint nguồn.'
        );

        $cardTiers = $version->tiers()->orderBy('sort_order')->orderBy('id')->get();
        $this->assertCount(3, $cardTiers);

        foreach ($cardTiers as $tier) {
            $fallback = $tier->tierCategoryRules()->fallback()->first();
            $this->assertNotNull($fallback);
            $this->assertSame('0.300', $fallback->cashback_percent);
            $this->assertTrue(
                (bool) $fallback->counts_toward_tier_cap,
                'Clone sang thẻ phải GIỮ nguyên cờ "tính vào trần bậc" của fallback.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // Hành vi engine (CashbackRecordService thật, có DB)
    // ---------------------------------------------------------------------

    #[Test]
    public function engine_keeps_special_categories_at_03_percent_below_3m(): void
    {
        $card = $this->makeShinhanCard();

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $txn = $this->addTxn($card, $period, '2026-09-05', '2000000', $this->categoryBySlug('san-thuong-mai-dien-tu'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('0.300', $txn->refresh()->cashback_percent_snapshot);
        $this->assertSame('6000.00', $txn->refresh()->cashback_amount_snapshot, 'Dưới 3tr: TMĐT rơi về 0,3% (bậc 1 chỉ có fallback).');
        $this->assertSame('2000000.00', $period->refresh()->total_eligible_spend);
        $this->assertSame('6000.00', $period->total_cashback);
    }

    #[Test]
    public function engine_applies_12_percent_at_3m_and_enforces_the_300k_tier_cap(): void
    {
        $card = $this->makeShinhanCard();

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $txn = $this->addTxn($card, $period, '2026-09-05', '3000000', $this->categoryBySlug('san-thuong-mai-dien-tu'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('12.000', $txn->refresh()->cashback_percent_snapshot, 'Tổng 3tr ⇒ bậc 2 ⇒ TMĐT 12%.');
        $this->assertSame('300000.00', $txn->refresh()->cashback_amount_snapshot, '12% × 3tr = 360k thô ⇒ chặn ở trần bậc 300k.');
        $this->assertSame('300000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function engine_treats_exactly_15m_as_tier_three_and_enforces_the_1m_cap(): void
    {
        $card = $this->makeShinhanCard();

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));
        $txn = $this->addTxn($card, $period, '2026-09-05', '15000000', $this->categoryBySlug('san-thuong-mai-dien-tu'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('12.000', $txn->refresh()->cashback_percent_snapshot);
        $this->assertSame('1000000.00', $txn->refresh()->cashback_amount_snapshot, 'Đúng 15tr thuộc bậc 3 ⇒ trần 1 triệu.');
        $this->assertSame('1000000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function engine_counts_the_fallback_toward_the_tier_cap(): void
    {
        $card = $this->makeShinhanCard();

        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));

        $tmdt = $this->addTxn($card, $period, '2026-09-05', '2500000', $this->categoryBySlug('san-thuong-mai-dien-tu'));
        $other = $this->addTxn($card, $period, '2026-09-06', '500000', $this->categoryBySlug('am-thuc-an-uong'));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('12.000', $tmdt->refresh()->cashback_percent_snapshot);
        $this->assertSame('300000.00', $tmdt->refresh()->cashback_amount_snapshot, 'TMĐT chiếm trọn ngân sách 300k của bậc 2.');

        $this->assertSame('0.300', $other->refresh()->cashback_percent_snapshot);
        $this->assertSame(
            '0.00',
            $other->refresh()->cashback_amount_snapshot,
            'Fallback 0,3% TÍNH VÀO trần (counts=true) nên hết ngân sách ⇒ 0đ.'
        );

        $this->assertSame('300000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function the_template_contains_no_hidden_50_percent_or_merchant_offers(): void
    {
        $this->creditCardSeeder->run();

        $rules = $this->tiersOf($this->seededTemplate())
            ->flatMap(fn (PolicyTier $tier) => $tier->tierCategoryRules()->get());

        $this->assertSame(['0.300', '6.000', '12.000'], $rules->pluck('cashback_percent')->unique()->sort()->values()->all());

        $this->assertCount(
            0,
            $rules->filter(fn (PolicyTierCategory $rule): bool => (float) $rule->cashback_percent >= 50.0),
            'Không có mức 50% — Netflix/Spotify/ChatGPT KHÔNG được gán vào danh mục.'
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function seededTemplate(): PolicyTemplate
    {
        $template = PolicyTemplate::query()->system()->where('slug', self::SLUG)->first();

        $this->assertNotNull($template, 'Seeder phải tạo template Shinhan Supreme.');

        return $template;
    }

    private function styleBuiltinTemplate(): PolicyTemplate
    {
        $template = PolicyTemplate::query()->system()->where('slug', self::BUILTIN_SLUG)->first();

        $this->assertNotNull($template, 'Seeder phải tạo template Cashback phổ thông.');

        return $template;
    }

    /**
     * @return \Illuminate\Support\Collection<int, PolicyTier>
     */
    private function tiersOf(PolicyTemplate $template)
    {
        $blueprint = $template->blueprint()->first();

        $this->assertNotNull($blueprint, 'Template phải có blueprint.');

        return $blueprint->tiers()->orderBy('sort_order')->orderBy('id')->get();
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

    private function makeShinhanCard()
    {
        $this->creditCardSeeder->run();

        $card = $this->makeUserCard($this->user->id, ['statement_day' => 31]);
        $version = $this->cloner->attachTemplateToCard($card, $this->seededTemplate(), CarbonImmutable::parse('2026-09-01'));

        $this->assertNotNull($version);

        return $card;
    }

    private function addTxn($card, $period, string $date, string $amount, Category $category): Transaction
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

    private function snapshotBlueprint(Policy $blueprint): array
    {
        return $blueprint->tiers()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (PolicyTier $tier): array => [
                'name' => $tier->name,
                'min_total_spend' => $tier->min_total_spend,
                'max_total_spend' => $tier->max_total_spend,
                'max_cashback_per_period' => $tier->max_cashback_per_period,
                'rules' => $tier->tierCategoryRules()->orderBy('sort_order')->orderBy('id')->get()
                    ->map(fn (PolicyTierCategory $rule): array => [
                        'category_id' => (int) $rule->category_id,
                        'scope_type' => $rule->scope_type,
                        'counts_toward_tier_cap' => (bool) $rule->counts_toward_tier_cap,
                        'cashback_percent' => $rule->cashback_percent,
                        'spend_from' => $rule->spend_from,
                        'spend_to' => $rule->spend_to,
                        'is_enabled' => (bool) $rule->is_enabled,
                    ])->all(),
            ])->all();
    }
}