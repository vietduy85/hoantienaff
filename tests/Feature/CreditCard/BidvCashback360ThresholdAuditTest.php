<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CashbackRecordService;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\PolicyService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use Database\Seeders\CreditCardSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * AUDIT (Phase I.7 — 1.2/1.3): BIDV Visa Cashback 360 — ma trận ngưỡng.
 *
 * Fixture audit-only, dựng trực tiếp từ template seed `bidv-visa-cashback-360`
 * (đối chiếu live: template #32 / policy #68 / tier #103). Mục tiêu là kiểm tra
 * ranh giới spend_from/spend_to, trần danh mục 300.000, trần kỳ 500.000 và việc
 * min_total_spend = 0 không chặn chi tiêu nhỏ.
 *
 * KHÔNG sửa production/schema/live data. Chỉ đọc engine qua CashbackRecordService.
 */
class BidvCashback360ThresholdAuditTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private const SLUG = 'bidv-visa-cashback-360';

    private const SIU_THI = 'sieu-thi-tien-loi';

    private const Y_TE = 'y-te-benh-vien';

    private const GIAO_DUC = 'giao-duc-hoc-phi';

    private const XANG_DAU = 'xang-dau';

    private CreditCardSeeder $creditCardSeeder;

    private PolicyCloneService $cloner;

    private PolicyService $policies;

    private CashbackRecordService $records;

    private StatementPeriodService $periods;

    private User $user;

    private bool $seeded = false;

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

    #[Test]
    public function supermarket_below_boundary_uses_the_2_percent_band(): void
    {
        $card = $this->makeCardWithTemplate();
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '9000000', $this->categoryBySlug(self::SIU_THI));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('2.000', $this->snapshotPercent($card, $period, self::SIU_THI));
        $this->assertSame('180000.00', $this->snapshotAmount($card, $period, self::SIU_THI));
        $this->assertSame('180000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function supermarket_at_exactly_the_boundary_switches_to_the_10_percent_band(): void
    {
        $card = $this->makeCardWithTemplate();
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '10000000', $this->categoryBySlug(self::SIU_THI));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('10.000', $this->snapshotPercent($card, $period, self::SIU_THI));
        $this->assertSame('300000.00', $this->snapshotAmount($card, $period, self::SIU_THI));
        $this->assertSame('300000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function supermarket_above_the_boundary_applies_10_percent_to_the_full_spend_without_splitting(): void
    {
        $card = $this->makeCardWithTemplate();
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '15000000', $this->categoryBySlug(self::SIU_THI));

        $this->records->calculatePeriod($card, $period);

        // 15tr × 10% = 1.500.000 thô (không chia dải) ⇒ trần danh mục 300.000.
        $this->assertSame('10.000', $this->snapshotPercent($card, $period, self::SIU_THI));
        $this->assertSame('300000.00', $this->snapshotAmount($card, $period, self::SIU_THI));
        $this->assertSame('300000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function min_total_spend_is_zero_so_small_eligible_spend_still_earns_cashback(): void
    {
        $this->creditCardSeeder->run();

        $template = PolicyTemplate::query()->system()->where('slug', self::SLUG)->firstOrFail();
        $blueprint = $template->blueprint()->firstOrFail();

        $this->assertSame('0.00', $blueprint->min_total_spend, 'Live #68: min_total_spend = 0.');
        $this->assertSame('0.00', (string) $blueprint->tiers()->firstOrFail()->min_total_spend);

        $card = $this->makeCardWithTemplate();
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '100000', $this->categoryBySlug(self::SIU_THI));

        $this->records->calculatePeriod($card, $period);

        $this->assertSame('2.000', $this->snapshotPercent($card, $period, self::SIU_THI));
        $this->assertSame('2000.00', $this->snapshotAmount($card, $period, self::SIU_THI));
        $this->assertSame('2000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function health_and_education_use_2_percent_and_share_the_500k_period_cap(): void
    {
        $card = $this->makeCardWithTemplate();
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '20000000', $this->categoryBySlug(self::Y_TE));
        $this->addTxn($card, $period, '2026-10-06', '20000000', $this->categoryBySlug(self::GIAO_DUC));

        $this->records->calculatePeriod($card, $period);

        // Y tế: 20tr × 2% = 400.000 ⇒ trần danh mục 300.000.
        // Giáo dục: 400.000 thô ⇒ trần danh mục 300.000, trần kỳ còn 500.000-300.000=200.000.
        $this->assertSame('2.000', $this->snapshotPercent($card, $period, self::Y_TE));
        $this->assertSame('2.000', $this->snapshotPercent($card, $period, self::GIAO_DUC));
        $this->assertSame('300000.00', $this->snapshotAmount($card, $period, self::Y_TE));
        $this->assertSame('200000.00', $this->snapshotAmount($card, $period, self::GIAO_DUC));
        $this->assertSame('500000.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function unbounded_10_percent_band_does_not_apply_to_categories_without_a_rule(): void
    {
        $card = $this->makeCardWithTemplate();
        $period = $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($card, $period, '2026-10-05', '50000000', $this->categoryBySlug(self::XANG_DAU));

        $this->records->calculatePeriod($card, $period);

        // Xăng dầu không có rule ⇒ fallback 0%.
        $this->assertSame('0.000', $this->snapshotPercent($card, $period, self::XANG_DAU));
        $this->assertSame('0.00', $this->snapshotAmount($card, $period, self::XANG_DAU));
        $this->assertSame('0.00', $period->refresh()->total_cashback);
    }

    #[Test]
    public function total_cashback_is_independent_of_transaction_insertion_order(): void
    {
        $this->seedOnce();
        $template = PolicyTemplate::query()->system()->where('slug', self::SLUG)->firstOrFail();

        $cardA = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->bankBySlug('bidv')->id,
            'name' => 'BIDV Visa Cashback 360',
        ]);
        $this->cloner->attachTemplateToCard($cardA, $template, CarbonImmutable::parse('2026-10-01'));
        $periodA = $this->periods->resolvePeriodForDate($cardA, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($cardA, $periodA, '2026-10-05', '6000000', $this->categoryBySlug(self::SIU_THI));
        $this->addTxn($cardA, $periodA, '2026-10-06', '4000000', $this->categoryBySlug(self::SIU_THI));

        $cardB = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->bankBySlug('bidv')->id,
            'name' => 'BIDV Visa Cashback 360',
        ]);
        $this->cloner->attachTemplateToCard($cardB, $template, CarbonImmutable::parse('2026-10-01'));
        $periodB = $this->periods->resolvePeriodForDate($cardB, CarbonImmutable::parse('2026-10-10'));
        $this->addTxn($cardB, $periodB, '2026-10-06', '4000000', $this->categoryBySlug(self::SIU_THI));
        $this->addTxn($cardB, $periodB, '2026-10-05', '6000000', $this->categoryBySlug(self::SIU_THI));

        $this->records->calculatePeriod($cardA, $periodA);
        $this->records->calculatePeriod($cardB, $periodB);

        // Tổng siêu thị = 10.000.000 ⇒ dải 10% ⇒ trần danh mục 300.000, bất kể thứ tự nhập.
        $this->assertSame('300000.00', $periodA->refresh()->total_cashback);
        $this->assertSame('300000.00', $periodB->refresh()->total_cashback);
    }

    private function seedOnce(): void
    {
        if (! $this->seeded) {
            $this->creditCardSeeder->run();
            $this->seeded = true;
        }
    }

    private function bankBySlug(string $slug): Bank
    {
        $bank = Bank::query()->where('slug', $slug)->first();

        $this->assertNotNull($bank, "Seeder phải tạo ngân hàng {$slug}.");

        return $bank;
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

    private function makeCardWithTemplate(): UserCard
    {
        $this->seedOnce();

        $card = $this->makeUserCard($this->user->id, [
            'bank_id' => $this->bankBySlug('bidv')->id,
            'name' => 'BIDV Visa Cashback 360',
        ]);

        $template = PolicyTemplate::query()->system()->where('slug', self::SLUG)->firstOrFail();

        $version = $this->cloner->attachTemplateToCard(
            $card,
            $template,
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

    private function snapshotPercent(UserCard $card, $period, string $categorySlug): string
    {
        return (string) Transaction::query()
            ->where('user_card_id', $card->id)
            ->where('statement_period_id', $period->id)
            ->where('category_id', $this->categoryBySlug($categorySlug)->id)
            ->firstOrFail()
            ->cashback_percent_snapshot;
    }

    private function snapshotAmount(UserCard $card, $period, string $categorySlug): string
    {
        return (string) Transaction::query()
            ->where('user_card_id', $card->id)
            ->where('statement_period_id', $period->id)
            ->where('category_id', $this->categoryBySlug($categorySlug)->id)
            ->firstOrFail()
            ->cashback_amount_snapshot;
    }
}
