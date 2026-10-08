<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardOverviewService;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Tổng quan — kỳ HIỆN TẠI derive theo anchor từng thẻ (Fix 1).
 *
 * ---------------------------------------------------------------------------
 * HỢP ĐỒNG (đã chốt — không đọc record stale, không tạo record khi đọc)
 * ---------------------------------------------------------------------------
 *   1. Kỳ hiện tại của mỗi thẻ = `StatementPeriodService::currentBoundaries()`
 *      theo `statement_day`/`statement_period_start` CỦA THẺ ĐÓ; record chỉ được
 *      khớp khi đúng `(user_card_id, period_start, period_end)`.
 *   2. Không có record khớp ⇒ `has_period = false`, số liệu = '0.00' — KHÔNG BAO
 *      GIỜ đọc một record open "chứa hôm nay" với bounds sai.
 *   3. Đọc không sinh bản ghi, không N+1: một truy vấn gom mọi thẻ.
 *   4. Dữ liệu của user khác không bao giờ lọt vào metrics.
 *
 * Hai record POISON open chứa hôm nay với bounds sai được tạo TRƯỚC và SAU
 * giao dịch thật: code cũ (`hôm nay ∈ [period_start, period_end]` + `keyBy`
 * giữ record cuối) luôn chọn poison và hiển thị sai — các test này fail đúng
 * trên code cũ.
 */
class OverviewPeriodRolloverTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    /** Hôm nay: 07/10/2026 — statement_day 15 ⇒ kỳ derive [09-15 .. 10-14]. */
    private const TODAY = '2026-10-07 12:00:00';

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();

        $this->atDate(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // =====================================================================
    // A · Kỳ hiện tại derive theo anchor — record stale chứa hôm nay bị bỏ qua
    // =====================================================================

    #[Test]
    public function the_current_period_is_derived_from_the_anchor_and_ignores_stale_records(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng']);
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);
        $this->attachFivePercentPolicy($card, $category);

        // Kỳ derive đúng của thẻ: statement_day 15, hôm nay 07/10 < 15 ⇒ mở từ
        // tháng trước: [2026-09-15 .. 2026-10-14].
        [$start, $end] = app(StatementPeriodService::class)->currentBoundaries($card);

        $this->assertSame('2026-09-15', $start->toDateString());
        $this->assertSame('2026-10-14', $end->toDateString());

        // POISON 1 — open, chứa hôm nay, SAI bounds. Tạo TRƯỚC giao dịch thật.
        $this->makeStatementPeriod($card, [
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'statement_date' => '2026-10-31',
            'payment_due_date' => '2026-11-10',
            'total_cashback' => '777777.00',
            'total_eligible_spend' => '777777.00',
        ]);

        // Giao dịch thật — service tự derive bounds, TẠO record đúng kỳ
        // [09-15 .. 10-14] và gắn snapshot policy vào đó.
        $this->spend($card, $category, '45000');

        // POISON 2 — Tạo SAU. Thứ tự insert này khiến record "cuối cùng" mà
        // code cũ `keyBy('user_card_id')` giữ lại LUÔN là một record poison.
        $this->makeStatementPeriod($card, [
            'period_start' => '2026-10-02',
            'period_end' => '2026-10-20',
            'statement_date' => '2026-10-20',
            'payment_due_date' => '2026-10-30',
            'total_cashback' => '888888.00',
            'total_eligible_spend' => '888888.00',
        ]);

        $page = $this->overview();
        $metrics = $page['cards'][$card->id];

        // Đúng record derive — không phải poison nào.
        $period = $page['current_periods']->firstWhere('user_card_id', $card->id);
        $this->assertNotNull($period, 'Kỳ derive phải khớp đúng record (user_card_id, period_start, period_end).');
        $this->assertSame('2026-09-15', $period->period_start->toDateString());
        $this->assertSame('2026-10-14', $period->period_end->toDateString());

        $this->assertTrue($metrics['has_period']);
        $this->assertSame('45000.00', $metrics['spent']);
        $this->assertSame('2250.00', $metrics['cashback']);
        $this->assertSame('1000000.00', $metrics['quota']['limit']);
    }

    // =====================================================================
    // B · Không có record khớp ⇒ số liệu 0, không bao giờ đọc record stale
    // =====================================================================

    #[Test]
    public function a_card_without_a_matching_period_reports_zero_instead_of_a_stale_record(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng']);
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);
        $this->attachFivePercentPolicy($card, $category);

        // Record open chứa 05/11 nhưng bounds [11-01 .. 11-30] KHÔNG khớp kỳ
        // derive [2026-10-15 .. 2026-11-14] của thẻ.
        $this->makeStatementPeriod($card, [
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'statement_date' => '2026-11-30',
            'payment_due_date' => '2026-12-10',
        ]);

        $this->atDate('2026-11-05 12:00:00');

        $page = $this->overview();
        $metrics = $page['cards'][$card->id];

        // Code cũ chọn record "chứa hôm nay" ⇒ has_period = true ⇒ fail ở đây.
        $this->assertFalse($metrics['has_period']);
        $this->assertSame('0.00', $metrics['spent']);
        $this->assertSame('0.00', $metrics['cashback']);

        // Record stale không được kéo vào tập kỳ hiện tại.
        $this->assertSame([], $page['current_periods']->all());
    }

    // =====================================================================
    // I · Derive + khớp record gom MỘT truy vấn cho mọi thẻ (không N+1)
    // =====================================================================

    #[Test]
    public function deriving_the_current_period_costs_one_query_for_every_card(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng']);

        foreach ([15, 20, 25] as $statementDay) {
            $card = $this->makeUserCard($this->owner->id, ['statement_day' => $statementDay]);
            $this->attachFivePercentPolicy($card, $category);
            $this->spend($card, $category, '45000');
        }

        $connection = DB::connection('creditcard');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->overview();

        $connection->disableQueryLog();

        $queries = array_filter(
            $connection->getQueryLog(),
            fn (array $entry): bool => str_contains($entry['query'], 'credit_card_statement_periods'),
        );

        $this->assertCount(
            1,
            $queries,
            'Kỳ hiện tại của mọi thẻ phải gom vào ĐÚNG MỘT truy vấn, không N+1 theo thẻ.',
        );
    }

    // =====================================================================
    // J · Ownership: kỳ của user khác không bao giờ vào metrics
    // =====================================================================

    #[Test]
    public function another_users_period_never_enters_the_metrics(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng']);

        $stranger = User::factory()->create();
        $strangerCard = $this->makeUserCard($stranger->id, ['statement_day' => 15]);
        $this->attachFivePercentPolicy($strangerCard, $category);
        $this->spend($strangerCard, $category, '45000');

        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);
        $this->attachFivePercentPolicy($card, $category);

        $page = $this->overview();

        $this->assertArrayHasKey($card->id, $page['cards']);
        $this->assertArrayNotHasKey($strangerCard->id, $page['cards']);
        $this->assertFalse($page['cards'][$card->id]['has_period']);
        $this->assertSame([], $page['current_periods']->all());
    }

    // =====================================================================
    // K · Mỗi thẻ một anchor — hai statement_day ⇒ hai kỳ hiện tại khác nhau
    // =====================================================================

    #[Test]
    public function each_card_derives_its_own_current_period_from_its_own_statement_day(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng']);

        $day15 = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);
        $day20 = $this->makeUserCard($this->owner->id, ['statement_day' => 20]);
        $this->attachFivePercentPolicy($day15, $category);
        $this->attachFivePercentPolicy($day20, $category);

        $this->spend($day15, $category, '45000');
        $this->spend($day20, $category, '99000');

        $page = $this->overview();

        $period15 = $page['current_periods']->firstWhere('user_card_id', $day15->id);
        $period20 = $page['current_periods']->firstWhere('user_card_id', $day20->id);

        $this->assertNotNull($period15, 'Thẻ statement_day 15 phải có kỳ [09-15 .. 10-14].');
        $this->assertNotNull($period20, 'Thẻ statement_day 20 phải có kỳ [09-20 .. 10-19].');
        $this->assertSame('2026-09-15', $period15->period_start->toDateString());
        $this->assertSame('2026-09-20', $period20->period_start->toDateString());

        $metrics15 = $page['cards'][$day15->id];
        $metrics20 = $page['cards'][$day20->id];

        $this->assertTrue($metrics15['has_period']);
        $this->assertTrue($metrics20['has_period']);
        $this->assertSame('45000.00', $metrics15['spent']);
        $this->assertSame('2250.00', $metrics15['cashback']);
        $this->assertSame('99000.00', $metrics20['spent']);
        $this->assertSame('4950.00', $metrics20['cashback']);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Đọc thẳng service — tránh query thêm của controller (latestCompletedBundleFor). */
    private function overview(): array
    {
        return app(CreditCardOverviewService::class)->forPage($this->owner->id);
    }

    private function attachFivePercentPolicy(UserCard $card, Category $category): void
    {
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => 1000000]],
            [['category_id' => $category->id, 'percent' => '5.000']],
        );
    }

    /**
     * Giao dịch đi qua service thật: tự derive bounds, tạo record đúng kỳ và
     * ghi snapshot policy — bản ghi trần không có những thứ đó.
     */
    private function spend(UserCard $card, Category $category, string $amount): void
    {
        [$start] = app(StatementPeriodService::class)->currentBoundaries($card, CarbonImmutable::now());

        $date = CarbonImmutable::now()->subDay();

        if ($date->lessThan($start)) {
            $date = $start;
        }

        app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => $date->toDateString(),
            'amount' => $amount,
            'category_id' => $category->id,
        ]);
    }

    private function atDate(string $when): void
    {
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);
    }
}
