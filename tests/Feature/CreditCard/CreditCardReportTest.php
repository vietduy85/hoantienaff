<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use App\Models\CreditCard\Report;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardReportService;
use App\Services\CreditCard\StatementPeriodService;
use App\Support\CreditCard\CreditCardMoneyFormatter;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Phase 2 — Báo cáo chi tiêu (/thetindung/bao-cao).
 *
 * Bất biến quan trọng nhất của module:
 *   1. Báo cáo LƯU cấu hình (tên/kiểu/thẻ), TÍNH lại số mỗi lần mở — không lưu
 *      kết quả vào DB.
 *   2. Cashback lấy từ `cashback_amount_snapshot` của giao dịch, KHÔNG tính lại
 *      theo policy hiện tại.
 *   3. Phạm vi là giao dịch gắn đúng kỳ (`statement_period_id`), không join nên
 *      không nhân dòng.
 *   4. Mở báo cáo KHÔNG tạo kỳ sao kê / giao dịch.
 *   5. Chỉ chủ sở hữu đọc/sửa/xoá báo cáo; không gắn được thẻ của user khác.
 */
class CreditCardReportTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $user;

    private UserCard $cardA;

    private UserCard $cardB;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

        $this->user = User::factory()->create();
        $this->cardA = $this->makeUserCard($this->user->id, ['name' => 'Thẻ A']);
        $this->cardB = $this->makeUserCard($this->user->id, ['name' => 'Thẻ B']);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Tạo kỳ sao kê khớp ĐÚNG ranh giới mà report service sẽ suy ra.
     *
     * `$offset = 0` là kỳ hiện tại; `1` là kỳ liền trước (để test bộ chọn kỳ).
     */
    private function periodFor(UserCard $card, int $offset = 0): StatementPeriod
    {
        $boundaries = app(StatementPeriodService::class)
            ->selectableBoundaries($card, CarbonImmutable::now());

        [$start, $end] = $boundaries[$offset];

        return $this->makeStatementPeriod($card, [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'statement_date' => $end->toDateString(),
            'payment_due_date' => $end->toDateString(),
        ]);
    }

    private function monthKeyFor(UserCard $card, int $offset = 0): string
    {
        $boundaries = app(StatementPeriodService::class)
            ->selectableBoundaries($card, CarbonImmutable::now());

        return $boundaries[$offset][1]->format('Y-m');
    }

    private function makeTxn(
        UserCard $card,
        StatementPeriod $period,
        string $amount,
        ?string $cashback,
        ?int $categoryId = null,
    ): Transaction {
        return Transaction::create([
            'user_card_id' => $card->id,
            'statement_period_id' => $period->id,
            'category_id' => $categoryId,
            'amount' => $amount,
            'transaction_date' => $period->period_start->toDateString(),
            'source' => Transaction::SOURCE_MANUAL,
            'cashback_amount_snapshot' => $cashback,
            'is_eligible' => true,
            'calc_basis' => $period->period_start->toDateString(),
        ]);
    }

    private function makeReport(string $type = Report::TYPE_BY_CARD, array $cardIds = []): Report
    {
        $report = Report::create([
            'user_id' => $this->user->id,
            'name' => 'Báo cáo thử',
            'type' => $type,
        ]);

        if ($cardIds !== []) {
            $report->cards()->sync($cardIds);
        }

        return $report->refresh();
    }

    // =====================================================================
    // CRUD cấu hình
    // =====================================================================

    #[Test]
    public function a_user_can_create_a_report_from_the_form(): void
    {
        $response = $this->actingAs($this->user)->post(route('credit-cards.reports.store'), [
            'name' => 'Chi tiêu quý này',
            'type' => Report::TYPE_BY_CARD,
            'card_ids' => [$this->cardA->id, $this->cardB->id],
        ]);

        $report = Report::query()->where('user_id', $this->user->id)->firstOrFail();

        $response->assertRedirect(route('credit-cards.reports.show', ['report' => $report->id]));
        $this->assertSame('Chi tiêu quý này', $report->name);
        $this->assertSame(Report::TYPE_BY_CARD, $report->type);

        $this->assertDatabaseHas('credit_card_report_cards', [
            'report_id' => $report->id,
            'user_card_id' => $this->cardA->id,
        ], 'creditcard');
        $this->assertDatabaseHas('credit_card_report_cards', [
            'report_id' => $report->id,
            'user_card_id' => $this->cardB->id,
        ], 'creditcard');
    }

    #[Test]
    public function a_report_requires_a_name_at_least_one_card_and_a_valid_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('credit-cards.reports.store'), [
                'name' => '',
                'type' => 'khong-hop-le',
                'card_ids' => [],
            ])
            ->assertSessionHasErrors(['name', 'type', 'card_ids']);

        $this->assertSame(0, Report::query()->where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function a_user_cannot_attach_a_card_of_another_user(): void
    {
        $other = User::factory()->create();
        $foreign = $this->makeUserCard($other->id);

        $this->actingAs($this->user)
            ->post(route('credit-cards.reports.store'), [
                'name' => 'Báo cáo lạ',
                'type' => Report::TYPE_BY_CARD,
                'card_ids' => [$foreign->id],
            ])
            ->assertSessionHasErrors('card_ids.0');

        $this->assertSame(0, Report::query()->where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function a_user_can_update_a_reports_configuration(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $this->actingAs($this->user)
            ->patch(route('credit-cards.reports.update', ['report' => $report->id]), [
                'name' => 'Đổi tên',
                'type' => Report::TYPE_BY_CATEGORY,
                'card_ids' => [$this->cardB->id],
            ])
            ->assertRedirect(route('credit-cards.reports.show', ['report' => $report->id]));

        $fresh = $report->fresh();

        $this->assertSame('Đổi tên', $fresh->name);
        $this->assertSame(Report::TYPE_BY_CATEGORY, $fresh->type);
        $this->assertTrue($fresh->cards->contains('id', $this->cardB->id));
        $this->assertFalse($fresh->cards->contains('id', $this->cardA->id));
    }

    #[Test]
    public function a_user_can_delete_a_report(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $this->actingAs($this->user)
            ->delete(route('credit-cards.reports.destroy', ['report' => $report->id]))
            ->assertRedirect(route('credit-cards.reports'));

        $this->assertDatabaseMissing('credit_card_reports', ['id' => $report->id], 'creditcard');
        $this->assertDatabaseMissing('credit_card_report_cards', ['report_id' => $report->id], 'creditcard');
    }

    #[Test]
    public function a_report_of_another_user_is_forbidden(): void
    {
        $other = User::factory()->create();
        $report = Report::create([
            'user_id' => $other->id,
            'name' => 'Của người khác',
            'type' => Report::TYPE_BY_CARD,
        ]);

        $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertForbidden();

        $this->actingAs($this->user)
            ->patch(route('credit-cards.reports.update', ['report' => $report->id]), [
                'name' => 'x',
                'type' => Report::TYPE_BY_CARD,
                'card_ids' => [$this->cardA->id],
            ])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->delete(route('credit-cards.reports.destroy', ['report' => $report->id]))
            ->assertForbidden();
    }

    // =====================================================================
    // Báo cáo A — theo thẻ
    // =====================================================================

    #[Test]
    public function by_card_report_sums_spend_and_cashback_per_card(): void
    {
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '50.00');
        $this->makeTxn($this->cardA, $periodA, '2000000', '100.00');
        $this->makeTxn($this->cardB, $periodB, '3000000', '150.00');

        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA, $this->cardB]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertSame('3000000.00', $data['rows'][0]['spend']);
        $this->assertSame('150.00', $data['rows'][0]['cashback']);
        $this->assertSame('3000000.00', $data['rows'][1]['spend']);
        $this->assertSame('150.00', $data['rows'][1]['cashback']);

        $this->assertSame('6000000.00', $data['total_spend']);
        $this->assertSame('300.00', $data['total_cashback']);
    }

    #[Test]
    public function by_card_uses_the_transaction_cashback_snapshot_not_a_recomputation(): void
    {
        $period = $this->periodFor($this->cardA);

        // amount 1.000.000 nhưng snapshot chỉ 123,45: báo cáo phải TIN snapshot.
        $this->makeTxn($this->cardA, $period, '1000000', '123.45');

        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertSame('123.45', $data['rows'][0]['cashback']);
        $this->assertSame('123.45', $data['total_cashback']);
    }

    #[Test]
    public function by_card_includes_a_card_without_a_period_record_as_zero(): void
    {
        // Không tạo kỳ nào cho cardA: ranh giới vẫn suy ra được nhưng chưa có bản ghi.
        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertTrue($data['rows'][0]['has_period']);
        $this->assertFalse($data['rows'][0]['has_record']);
        $this->assertSame('0.00', $data['rows'][0]['spend']);
        $this->assertSame('0.00', $data['rows'][0]['cashback']);
    }

    #[Test]
    public function by_card_only_counts_transactions_of_the_selected_period(): void
    {
        $current = $this->periodFor($this->cardA);
        $previous = $this->periodFor($this->cardA, 1);

        $this->makeTxn($this->cardA, $current, '1000000', '10.00');
        $this->makeTxn($this->cardA, $previous, '5000000', '70.00');

        $service = app(CreditCardReportService::class);

        $currentData = $service->byCard(collect([$this->cardA]), CreditCardReportService::PERIOD_CURRENT);
        $this->assertSame('1000000.00', $currentData['total_spend']);

        $previousData = $service->byCard(collect([$this->cardA]), $this->monthKeyFor($this->cardA, 1));
        $this->assertSame('5000000.00', $previousData['total_spend']);
        $this->assertSame('70.00', $previousData['total_cashback']);
    }

    // =====================================================================
    // Báo cáo B — theo danh mục
    // =====================================================================

    #[Test]
    public function by_category_report_lists_categories_with_per_card_columns_and_grand_total(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $other = $this->makeSystemCategory(['name' => 'Di chuyển']);

        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '10.00', $category->id);
        $this->makeTxn($this->cardB, $periodB, '2000000', '20.00', $category->id);
        $this->makeTxn($this->cardA, $periodA, '500000', '5.00', $other->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA, $this->cardB]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $rows = collect($data['rows'])->keyBy('name');

        $this->assertSame('3000000.00', $rows['Ăn uống']['spend_total']);
        $this->assertSame('500000.00', $rows['Di chuyển']['spend_total']);

        $this->assertSame('1500000.00', $data['card_totals'][$this->cardA->id]['spend']);
        $this->assertSame('2000000.00', $data['card_totals'][$this->cardB->id]['spend']);
        $this->assertSame('3500000.00', $data['grand']['spend']);
        $this->assertSame('35.00', $data['grand']['cashback']);
    }

    #[Test]
    public function by_category_puts_uncategorized_transactions_in_their_own_row_and_keeps_them_in_totals(): void
    {
        $periodA = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $periodA, '400000', '4.00', null);
        $this->makeTxn($this->cardA, $periodA, '600000', '6.00', $this->makeSystemCategory()->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $rows = collect($data['rows'])->keyBy('name');

        $this->assertArrayHasKey('Chưa phân loại', $rows->all());
        $this->assertSame('400000.00', $rows['Chưa phân loại']['spend_total']);

        // Không join nên tổng = đúng tổng giao dịch, không bị nhân đôi.
        $this->assertSame('1000000.00', $data['grand']['spend']);
    }

    #[Test]
    public function by_category_uncategorized_row_is_listed_last(): void
    {
        $periodA = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $periodA, '100000', '1.00', null);
        $this->makeTxn($this->cardA, $periodA, '100000', '1.00', $this->makeSystemCategory()->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $last = end($data['rows']);
        $this->assertTrue($last['is_uncategorized']);
    }

    // =====================================================================
    // Bộ chọn kỳ
    // =====================================================================

    #[Test]
    public function period_options_start_with_current_and_list_months_newest_first(): void
    {
        $options = app(CreditCardReportService::class)->periodOptions(collect([$this->cardA]));

        $this->assertSame(CreditCardReportService::PERIOD_CURRENT, $options[0]['key']);

        $keys = array_slice(array_column($options, 'key'), 1);
        $sorted = $keys;
        rsort($sorted);
        $this->assertSame($sorted, $keys);
    }

    #[Test]
    public function an_invalid_period_key_falls_back_to_the_current_period(): void
    {
        $service = app(CreditCardReportService::class);
        $cards = collect([$this->cardA]);

        $this->assertSame(
            CreditCardReportService::PERIOD_CURRENT,
            $service->normalizePeriodKey('1999-01', $cards),
        );
        $this->assertSame(
            CreditCardReportService::PERIOD_CURRENT,
            $service->normalizePeriodKey('rac', $cards),
        );
        $this->assertSame(
            CreditCardReportService::PERIOD_CURRENT,
            $service->normalizePeriodKey(null, $cards),
        );
    }

    #[Test]
    public function a_valid_month_key_is_kept(): void
    {
        $key = $this->monthKeyFor($this->cardA);

        $this->assertSame(
            $key,
            app(CreditCardReportService::class)->normalizePeriodKey($key, collect([$this->cardA])),
        );
    }

    // =====================================================================
    // Mở báo cáo KHÔNG ghi
    // =====================================================================

    #[Test]
    public function opening_a_report_does_not_create_periods_or_transactions(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $periodsBefore = StatementPeriod::query()->count();
        $txnBefore = Transaction::query()->count();

        $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk();

        $this->assertSame($periodsBefore, StatementPeriod::query()->count());
        $this->assertSame($txnBefore, Transaction::query()->count());
    }

    #[Test]
    public function the_result_page_renders_both_report_types(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10.00', $this->makeSystemCategory()->id);

        $byCard = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $byCategory = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id]);

        $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $byCard->id]))
            ->assertOk()
            ->assertSee('report-by-card', false)
            ->assertSee('Tổng tất cả thẻ đã chọn');

        $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $byCategory->id]))
            ->assertOk()
            ->assertSee('report-by-category', false)
            ->assertSee('Tổng theo thẻ');
    }

    protected function tearDown(): void
    {
        CreditCardMoneyFormatter::flushAll();

        parent::tearDown();
    }

    // =====================================================================
    // Tỷ lệ Cashback/Chi tiêu — báo cáo theo THẺ
    // =====================================================================

    #[Test]
    public function by_card_columns_are_ordered_card_spend_cashback_percent_period(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->assertSeeInOrder([
                'by-card-col-card',
                'by-card-col-spend',
                'by-card-col-cashback',
                'by-card-col-percent',
                'by-card-col-period',
            ], false);
    }

    #[Test]
    public function by_card_percent_is_cashback_over_spend(): void
    {
        // 50.000 / 700.000 = 7,14% — ví dụ định dạng theo spec.
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '700000', '50000.00');

        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertSame('7.14', $data['rows'][0]['percent']);
        $this->assertSame('7.14', $data['total_percent']);

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('700.000', $html);
        $this->assertStringContainsString('>7,14%<', $html);
    }

    #[Test]
    public function by_card_zero_cashback_with_positive_spend_shows_zero_percent(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '0.00');

        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertSame('0.00', $data['rows'][0]['percent']);
        $this->assertSame('0.00', $data['total_percent']);

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->assertSee('>0,00%<', false);
    }

    #[Test]
    public function by_card_zero_spend_shows_dash(): void
    {
        // Không có giao dịch ⇒ chi tiêu 0 ⇒ không có tỷ lệ.
        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertNull($data['rows'][0]['percent']);
        $this->assertNull($data['total_percent']);

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->assertSee('>—<', false);
    }

    #[Test]
    public function by_card_total_percent_uses_totals_not_the_average_of_card_ratios(): void
    {
        // Thẻ A: 10%, thẻ B: 1% — trung bình cộng là 5,5% nhưng tổng phải là 1,9%.
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '100000.00');
        $this->makeTxn($this->cardB, $periodB, '9000000', '90000.00');

        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA, $this->cardB]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertSame('10.00', $data['rows'][0]['percent']);
        $this->assertSame('1.00', $data['rows'][1]['percent']);
        $this->assertSame('10000000.00', $data['total_spend']);
        $this->assertSame('190000.00', $data['total_cashback']);
        $this->assertSame('1.90', $data['total_percent']);
    }

    #[Test]
    public function by_card_percent_follows_the_selected_period(): void
    {
        $current = $this->periodFor($this->cardA);
        $previous = $this->periodFor($this->cardA, 1);

        $this->makeTxn($this->cardA, $current, '1000000', '10000.00');
        $this->makeTxn($this->cardA, $previous, '2000000', '40000.00');

        $service = app(CreditCardReportService::class);

        $currentData = $service->byCard(collect([$this->cardA]), CreditCardReportService::PERIOD_CURRENT);
        $this->assertSame('1.00', $currentData['rows'][0]['percent']);

        $previousData = $service->byCard(collect([$this->cardA]), $this->monthKeyFor($this->cardA, 1));
        $this->assertSame('2000000.00', $previousData['rows'][0]['spend']);
        $this->assertSame('2.00', $previousData['rows'][0]['percent']);
    }

    // =====================================================================
    // Tỷ lệ Cashback/Chi tiêu — báo cáo theo DANH MỤC
    // =====================================================================

    #[Test]
    public function by_category_columns_are_ordered_category_totals_percent_then_cards(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $category->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id]);

        $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->assertSeeInOrder([
                'category-col-name',
                'category-col-spend',
                'category-col-cashback',
                'category-col-percent',
                'category-card-col-'.$this->cardA->id,
            ], false);
    }

    #[Test]
    public function by_category_percent_is_computed_from_totals_not_per_card_ratios(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        // Thẻ A: 10%, thẻ B: 1% — danh mục từ tổng = 1,9%, không phải trung bình 5,5%.
        $this->makeTxn($this->cardA, $periodA, '1000000', '100000.00', $category->id);
        $this->makeTxn($this->cardB, $periodB, '9000000', '90000.00', $category->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA, $this->cardB]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $row = collect($data['rows'])->firstWhere('name', 'Siêu thị');

        $this->assertSame('10000000.00', $row['spend_total']);
        $this->assertSame('190000.00', $row['cashback_total']);
        $this->assertSame('1.90', $row['percent']);
        $this->assertSame('1.90', $data['grand']['percent']);
    }

    #[Test]
    public function by_category_per_card_totals_match_the_card_columns(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $other = $this->makeSystemCategory(['name' => 'Di chuyển']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '10000.00', $category->id);
        $this->makeTxn($this->cardA, $periodA, '500000', '5000.00', $other->id);
        $this->makeTxn($this->cardB, $periodB, '2000000', '40000.00', $category->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA, $this->cardB]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        // Dòng Tổng theo thẻ = tổng CHÍNH THẺ đó, không trộn thẻ khác.
        $this->assertSame('1500000.00', $data['card_totals'][$this->cardA->id]['spend']);
        $this->assertSame('15000.00', $data['card_totals'][$this->cardA->id]['cashback']);
        $this->assertSame('1.00', $data['card_totals'][$this->cardA->id]['percent']);
        $this->assertSame('2000000.00', $data['card_totals'][$this->cardB->id]['spend']);
        $this->assertSame('40000.00', $data['card_totals'][$this->cardB->id]['cashback']);
        $this->assertSame('2.00', $data['card_totals'][$this->cardB->id]['percent']);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);
        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="card-total-'.$this->cardA->id.'-spend"', $html);
        $this->assertStringContainsString('data-testid="card-total-'.$this->cardB->id.'-cashback"', $html);
    }

    #[Test]
    public function by_category_grand_row_matches_the_whole_report(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '10000.00', $category->id);
        $this->makeTxn($this->cardB, $periodB, '3000000', '75000.00', $category->id);
        $this->makeTxn($this->cardB, $periodB, '500000', '5000.00', null);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA, $this->cardB]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertSame('4500000.00', $data['grand']['spend']);
        $this->assertSame('90000.00', $data['grand']['cashback']);
        $this->assertSame('2.00', $data['grand']['percent']);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);
        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="grand-row-spend"', $html);
        $this->assertStringContainsString('data-testid="grand-row-cashback"', $html);
        $this->assertStringContainsString('data-testid="grand-row-percent"', $html);
    }

    #[Test]
    public function by_category_zero_spend_shows_dash_and_zero_cashback_shows_zero_percent(): void
    {
        $zeroSpendCategory = $this->makeSystemCategory(['name' => 'Không chi']);
        $zeroCashbackCategory = $this->makeSystemCategory(['name' => 'Không hoàn']);

        $period = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $period, '0', '0.00', $zeroSpendCategory->id);
        $this->makeTxn($this->cardA, $period, '1000000', '0.00', $zeroCashbackCategory->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $rows = collect($data['rows'])->keyBy('name');

        $this->assertNull($rows['Không chi']['percent']);
        $this->assertSame('0.00', $rows['Không hoàn']['percent']);
        $this->assertSame('0.00', $data['grand']['percent']);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id]);
        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>—<', $html);
        $this->assertStringContainsString('>0,00%<', $html);
    }

    #[Test]
    public function by_category_uncategorized_stays_in_the_row_and_totals(): void
    {
        $period = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $period, '400000', '20000.00', null);
        $this->makeTxn($this->cardA, $period, '600000', '6000.00', $this->makeSystemCategory()->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $rows = collect($data['rows'])->keyBy('name');

        $this->assertSame('400000.00', $rows['Chưa phân loại']['spend_total']);
        $this->assertSame('20000.00', $rows['Chưa phân loại']['cashback_total']);
        $this->assertSame('5.00', $rows['Chưa phân loại']['percent']);
        $this->assertSame('1000000.00', $data['grand']['spend']);
        $this->assertSame('26000.00', $data['grand']['cashback']);
        $this->assertSame('2.60', $data['grand']['percent']);
    }

    #[Test]
    public function by_category_aggregates_do_not_double_count(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '10000.00', $category->id);
        $this->makeTxn($this->cardA, $periodA, '500000', '5000.00', null);
        $this->makeTxn($this->cardB, $periodB, '2000000', '20000.00', $category->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA, $this->cardB]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        // Tổng dòng danh mục == tổng cột thẻ == grand: ba lối đếm trùng một con số.
        $sumRowSpend = (string) collect($data['rows'])->reduce(
            fn (string $carry, array $row): string => Decimal::add($carry, $row['spend_total']),
            '0.00',
        );
        $sumRowCashback = (string) collect($data['rows'])->reduce(
            fn (string $carry, array $row): string => Decimal::add($carry, $row['cashback_total']),
            '0.00',
        );

        $sumCardSpend = Decimal::add(
            $data['card_totals'][$this->cardA->id]['spend'],
            $data['card_totals'][$this->cardB->id]['spend'],
        );
        $sumCardCashback = Decimal::add(
            $data['card_totals'][$this->cardA->id]['cashback'],
            $data['card_totals'][$this->cardB->id]['cashback'],
        );

        $this->assertSame('3500000.00', $data['grand']['spend']);
        $this->assertSame($data['grand']['spend'], $sumRowSpend);
        $this->assertSame($data['grand']['cashback'], $sumRowCashback);
        $this->assertSame($data['grand']['spend'], $sumCardSpend);
        $this->assertSame($data['grand']['cashback'], $sumCardCashback);
    }

    #[Test]
    public function by_category_aggregates_follow_the_selected_period(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $current = $this->periodFor($this->cardA);
        $previous = $this->periodFor($this->cardA, 1);

        $this->makeTxn($this->cardA, $current, '1000000', '10000.00', $category->id);
        $this->makeTxn($this->cardA, $previous, '4000000', '80000.00', $category->id);

        $service = app(CreditCardReportService::class);

        $currentData = $service->byCategory(collect([$this->cardA]), CreditCardReportService::PERIOD_CURRENT);
        $this->assertSame('1000000.00', $currentData['grand']['spend']);
        $this->assertSame('1.00', $currentData['grand']['percent']);

        $previousData = $service->byCategory(collect([$this->cardA]), $this->monthKeyFor($this->cardA, 1));
        $this->assertSame('4000000.00', $previousData['grand']['spend']);
        $this->assertSame('80000.00', $previousData['grand']['cashback']);
        $this->assertSame('2.00', $previousData['grand']['percent']);
    }

    // =====================================================================
    // Cột "Danh mục" — độ rộng & căn thẳng header/body/footer (PHẦN A)
    // =====================================================================

    #[Test]
    public function category_name_column_uses_one_min_width_across_header_body_and_totals(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Hóa đơn điện, nước, internet']);
        $period = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $category->id);
        $this->makeTxn($this->cardA, $period, '500000', '5000.00', null);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);
        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        // Độ rộng cột "Danh mục" được khai báo ở MỘT chỗ duy nhất: `<colgroup>`
        // (cột tên rộng hơn trên desktop) — header/body/footer thẳng cột mà không
        // phải lặp class lên từng ô.
        $this->assertSame(1, substr_count($html, '<col class="min-w-[180px] sm:min-w-[240px]">'));
        $this->assertSame(1, substr_count($html, '<col span="7" class="min-w-[110px]">'));
        // Không còn class cũ rải rác trên các ô.
        $this->assertSame(0, substr_count($html, 'min-w-[160px] sm:min-w-[220px]'));
        // Thay vào đó đúng 5 ô dính trái (header + 2 dòng + 2 dòng tổng) giữ cột
        // tên khi cuộn ngang.
        $this->assertSame(5, substr_count($html, 'sticky left-0 z-10'));
        // Tên danh mục dài xuống dòng tại khoảng trắng, không vỡ từ.
        $this->assertSame(2, substr_count($html, 'text-gray-800 break-words'));
        // Nhãn hai dòng tổng giữ nguyên một dòng khi khung hẹp.
        $this->assertStringContainsString('whitespace-nowrap">Tổng theo thẻ', $html);
        $this->assertStringContainsString('whitespace-nowrap">Tổng tất cả', $html);
    }

    #[Test]
    public function category_name_column_layout_keeps_the_scroll_wrapper_and_card_colspans(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        // Bảng rộng (4 + 2×N cột) vẫn nằm trong khung cuộn ngang riêng.
        $this->assertStringContainsString('overflow-x-auto', $html);
        // Độ rộng cột khai báo tập trung ở `<colgroup>` nhờ cột `<col span>`.
        $this->assertStringContainsString('<colgroup>', $html);
        $this->assertStringContainsString('<col span="7" class="min-w-[110px]">', $html);
        // Header mỗi thẻ trải 2 cột — không bị hẹp lại khi cột Danh mục rộng.
        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringContainsString('min-w-[180px]', $html);
        // Các ô tiền/tỷ lệ giữ nowrap nên không bị ép thành nhiều dòng.
        $this->assertStringContainsString('whitespace-nowrap', $html);
        // Cột tên cố định cũng giữ một dòng.
        $this->assertStringContainsString('align-bottom whitespace-nowrap', $html);
    }

    #[Test]
    public function category_name_min_width_survives_a_report_without_transactions(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        // Không có dữ liệu ⇒ cột tên vẫn lấy width từ `<colgroup>`, chỉ còn
        // header + 2 dòng tổng dính trái.
        $this->assertSame(1, substr_count($html, '<col class="min-w-[180px] sm:min-w-[240px]">'));
        $this->assertSame(3, substr_count($html, 'sticky left-0 z-10'));
        $this->assertSame(0, substr_count($html, 'min-w-[160px] sm:min-w-[220px]'));
        // Dòng "chưa có giao dịch" trải đúng hết bảng: 4 + 2×2 cột.
        $this->assertStringContainsString('colspan="8"', $html);
        $this->assertStringContainsString('Kỳ này chưa có giao dịch nào.', $html);
    }

    // =====================================================================
    // Nhãn cột & Sắp xếp (PHẦN A + PHẦN C)
    // =====================================================================

    #[Test]
    public function by_card_report_labels_the_ratio_column_ty_le(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        // Nhãn ngắn gọn "Tỷ lệ" chỉ đổi cho màn hình; giá trị và thứ tự cột giữ nguyên.
        preg_match('/data-testid="by-card-col-percent".*?<\/th>/s', $html, $m);
        $this->assertSame(1, count($m));
        $this->assertStringContainsString('>Tỷ lệ</span>', $m[0]);
        $this->assertStringNotContainsString('Tỷ lệ Cashback/Chi tiêu', $html);
    }

    #[Test]
    public function by_card_spend_sort_compares_numbers_not_strings(): void
    {
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        // Thẻ A: 1.000.000 > Thẻ B: 900.000 về mặt số, nhưng theo chuỗi
        // "1000000.00" < "900000.00" — nếu sort sai theo chuỗi sẽ cho kết quả ngược.
        $this->makeTxn($this->cardA, $periodA, '1000000', '90000.00');
        $this->makeTxn($this->cardB, $periodB, '900000', '90000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id, $this->cardB->id]);

        $asc = $this->pageWith($report, ['sort' => 'spend', 'dir' => 'asc']);
        $pos = $this->htmlPositions($asc, [
            'a' => 'by-card-row-'.$this->cardA->id,
            'b' => 'by-card-row-'.$this->cardB->id,
        ]);
        $this->assertTrue($pos['b'] < $pos['a'], 'spend asc phải đưa thẻ 900.000 lên trước.');

        $desc = $this->pageWith($report, ['sort' => 'spend', 'dir' => 'desc']);
        $pos = $this->htmlPositions($desc, [
            'a' => 'by-card-row-'.$this->cardA->id,
            'b' => 'by-card-row-'.$this->cardB->id,
        ]);
        $this->assertTrue($pos['a'] < $pos['b'], 'spend desc phải đưa thẻ 1.000.000 lên trước.');
    }

    #[Test]
    public function by_card_cashback_ties_keep_original_order_and_totals_stay_last(): void
    {
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '90000.00');
        $this->makeTxn($this->cardB, $periodB, '900000', '90000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id, $this->cardB->id]);

        $html = $this->pageWith($report, ['sort' => 'cashback', 'dir' => 'asc']);

        // Cashback bằng nhau ⇒ giữ nguyên thứ tự gốc của service (Thẻ A trước B).
        $pos = $this->htmlPositions($html, [
            'a' => 'by-card-row-'.$this->cardA->id,
            'b' => 'by-card-row-'.$this->cardB->id,
            'total' => 'by-card-total-spend',
        ]);
        $this->assertTrue($pos['a'] < $pos['b'], 'Hai thẻ bằng nhau phải giữ thứ tự ban đầu.');
        // Dòng tổng không tham gia sort — luôn sau mọi dòng thẻ.
        $this->assertTrue($pos['total'] > $pos['a'] && $pos['total'] > $pos['b'], 'Dòng tổng phải đứng cuối.');
        // Số liệu không đổi khi đổi thứ tự: 1.900.000 / 180.000 / 9,47%.
        $this->assertStringContainsString('>1.900.000', $html);
        $this->assertStringContainsString('>180.000', $html);
        $this->assertStringContainsString('>9,47%<', $html);
    }

    #[Test]
    public function by_card_percent_sort_keeps_null_ratio_last_in_both_directions(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '100000.00');
        // Thẻ B không có giao dịch ⇒ chi tiêu 0, tỷ lệ "—" (null).

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id, $this->cardB->id]);

        foreach (['asc', 'desc'] as $dir) {
            $html = $this->pageWith($report, ['sort' => 'percent', 'dir' => $dir]);

            $pos = $this->htmlPositions($html, [
                'a' => 'by-card-row-'.$this->cardA->id,
                'b' => 'by-card-row-'.$this->cardB->id,
            ]);
            $this->assertTrue($pos['a'] < $pos['b'], "percent {$dir}: thẻ có tỷ lệ đứng trước '—'.");

            $this->assertStringContainsString('data-testid="by-card-percent-'.$this->cardB->id.'">', $html);
            $this->assertStringContainsString('<span>—</span>', $html);
        }
    }

    #[Test]
    public function by_card_sort_ignores_unknown_keys_and_directions(): void
    {
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '90000.00');
        $this->makeTxn($this->cardB, $periodB, '900000', '90000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id, $this->cardB->id]);

        $html = $this->pageWith($report, ['sort' => 'hack', 'dir' => 'sideways']);

        // Không có tiêu chí hợp lệ ⇒ thứ tự gốc (Thẻ A trước B), không lỗi.
        $pos = $this->htmlPositions($html, [
            'a' => 'by-card-row-'.$this->cardA->id,
            'b' => 'by-card-row-'.$this->cardB->id,
        ]);
        $this->assertTrue($pos['a'] < $pos['b']);
        // Không có sort active ⇒ form chọn kỳ không kèm hidden input, không cột active.
        $this->assertStringNotContainsString('name="sort"', $html);
        $this->assertStringNotContainsString('aria-sort="ascending"', $html);
        $this->assertStringNotContainsString('aria-sort="descending"', $html);
    }

    #[Test]
    public function by_card_active_sort_flips_direction_and_marks_aria(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '100000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $html = $this->pageWith($report, ['sort' => 'percent', 'dir' => 'asc']);

        $this->assertSame(1, substr_count($html, 'aria-sort="ascending"'));
        $this->assertSame(0, substr_count($html, 'aria-sort="descending"'));
        $this->assertSame(2, substr_count($html, 'aria-sort="none"'));
        // Nhấn lại cột đang active sẽ đảo chiều ⇒ href sang desc; cột mới vào asc.
        $this->assertStringContainsString('sort=percent&amp;dir=desc', $html);
        $this->assertStringContainsString('sort=spend&amp;dir=asc', $html);
        $this->assertStringContainsString('sort=cashback&amp;dir=asc', $html);
        // Mũi tên trạng thái: active ▲, hai cột còn lại ⇅.
        $this->assertStringContainsString('>▲<', $html);
        $this->assertSame(2, substr_count($html, '>⇅<'));
        // Form chọn kỳ giữ lại trạng thái sort khi đổi kỳ.
        $this->assertStringContainsString('name="sort" value="percent"', $html);
        $this->assertStringContainsString('name="dir" value="asc"', $html);
    }

    #[Test]
    public function period_change_keeps_the_chosen_sort(): void
    {
        $currentA = $this->periodFor($this->cardA);
        $previousA = $this->periodFor($this->cardA, 1);
        $currentB = $this->periodFor($this->cardB);
        $previousB = $this->periodFor($this->cardB, 1);

        $this->makeTxn($this->cardA, $currentA, '1000000', '90000.00');
        $this->makeTxn($this->cardB, $currentB, '900000', '90000.00');
        $this->makeTxn($this->cardA, $previousA, '900000', '90000.00');
        $this->makeTxn($this->cardB, $previousB, '1000000', '90000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id, $this->cardB->id]);

        $html = $this->pageWith($report, [
            'period' => $this->monthKeyFor($this->cardA, 1),
            'sort' => 'spend',
            'dir' => 'desc',
        ]);

// Kỳ trước Thẻ A = 900.000, Thẻ B = 1.000.000; dir=desc vẫn được giữ sau khi
// đổi kỳ (sort chỉ đọc query param) nên B phải đứng trước A. Nếu bị reset về
// thứ tự tên mặc định thì A đứng trước B và test này fail.
        $pos = $this->htmlPositions($html, [
            'a' => 'by-card-row-'.$this->cardA->id,
            'b' => 'by-card-row-'.$this->cardB->id,
        ]);
        $this->assertTrue($pos['b'] < $pos['a'], 'Đổi kỳ không được reset thứ tự sắp xếp.');
        // Số liệu của kỳ vừa chọn được tôn trọng: trong kỳ trước chỉ Thẻ B = 1.000.000.
        $this->assertSame(1, substr_count($html, '1.000.000'));
    }

    #[Test]
    public function category_sort_swaps_aggregate_order_and_keeps_pairs_attached(): void
    {
        $catA = $this->makeSystemCategory(['name' => 'A']);
        $catB = $this->makeSystemCategory(['name' => 'B']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        // Cat A: 400.000 / 36.000 = 9,00%; Cat B: 300.000 / 30.000 = 10,00%.
        $this->makeTxn($this->cardA, $periodA, '200000', '18000.00', $catA->id);
        $this->makeTxn($this->cardB, $periodB, '200000', '18000.00', $catA->id);
        $this->makeTxn($this->cardA, $periodA, '150000', '15000.00', $catB->id);
        $this->makeTxn($this->cardB, $periodB, '150000', '15000.00', $catB->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);

        // Mặc định A (9%) trước B (10%); sort theo SỐ desc phải cho B lên trước —
        // nếu sort theo chuỗi ("9.00" > "10.00") sẽ cho A trước và test này fail.
        $html = $this->pageWith($report, ['sort' => 'percent', 'dir' => 'desc']);
        $pos = $this->htmlPositions($html, [
            'a' => 'category-row-'.$catA->id,
            'b' => 'category-row-'.$catB->id,
            'grand' => 'grand-row-spend',
        ]);
        $this->assertTrue($pos['b'] < $pos['a'], 'Tỷ lệ danh mục desc phải đưa B (10%) lên trên A (9%).');

        // Tỷ lệ và cặp dữ liệu của mỗi thẻ nằm nguyên vẹn trong dòng danh mục của nó.
        $sliceB = substr($html, $pos['b'], $pos['a'] - $pos['b']);
        $this->assertStringContainsString('10,00%', $sliceB);
        $this->assertStringContainsString('cell-'.$this->cardA->id.'-'.$catB->id.'-spend', $sliceB);
        $this->assertStringContainsString('cell-'.$this->cardB->id.'-'.$catB->id.'-spend', $sliceB);

        $sliceA = substr($html, $pos['a'], $pos['grand'] - $pos['a']);
        $this->assertStringContainsString('9,00%', $sliceA);

        // Dòng tổng luôn nằm sau dòng dữ liệu cuối.
        $this->assertTrue($pos['a'] < $pos['grand'], 'Dòng tổng phải đứng cuối sau mọi dòng danh mục.');
        // Số liệu giữ nguyên khi đổi thứ tự: 700.000 / 66.000 / 9,42% (VND thô —
        // tỷ lệ làm tròn xuống 2 chữ số như toàn module).
        $this->assertStringContainsString('>700.000', $html);
        $this->assertStringContainsString('>66.000', $html);
        $this->assertStringContainsString('>9,42%', $html);
    }

    #[Test]
    public function category_spend_and_cashback_sort_swap_rows(): void
    {
        $catA = $this->makeSystemCategory(['name' => 'A']);
        $catB = $this->makeSystemCategory(['name' => 'B']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '200000', '18000.00', $catA->id);
        $this->makeTxn($this->cardB, $periodB, '200000', '18000.00', $catA->id);
        $this->makeTxn($this->cardA, $periodA, '150000', '15000.00', $catB->id);
        $this->makeTxn($this->cardB, $periodB, '150000', '15000.00', $catB->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);

        $cases = [
            ['sort' => 'spend', 'dir' => 'asc', 'first' => $catB->id, 'second' => $catA->id],
            ['sort' => 'cashback', 'dir' => 'desc', 'first' => $catA->id, 'second' => $catB->id],
        ];

        foreach ($cases as $case) {
            $html = $this->pageWith($report, ['sort' => $case['sort'], 'dir' => $case['dir']]);
            $pos = $this->htmlPositions($html, [
                'first' => 'category-row-'.$case['first'],
                'second' => 'category-row-'.$case['second'],
            ]);
            $this->assertTrue(
                $pos['first'] < $pos['second'],
                "category {$case['sort']} {$case['dir']} phải đưa danh mục {$case['first']} lên trước.",
            );
        }
    }

    /** Mở trang kết quả với query params và trả HTML, không render lỗi. */
    private function pageWith(Report $report, array $query = []): string
    {
        return $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id] + $query))
            ->assertOk()
            ->getContent();
    }

    private function htmlPositions(string $html, array $needles): array
    {
        $positions = [];
        foreach ($needles as $name => $needle) {
            $positions[$name] = strpos($html, $needle);
            $this->assertNotFalse($positions[$name], 'Không thấy trong HTML: '.$needle);
        }

        return $positions;
    }

    // =====================================================================
    // Đơn vị tiền & quyền truy cập
    // =====================================================================

    #[Test]
    public function thousand_vnd_still_computes_the_ratio_from_raw_vnd(): void
    {
        $this->switchMoneyUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $period = $this->periodFor($this->cardA);
        // VND thô: 1.000.000 / 1.999.999 = 50,00%. Dùng số đã floor (1000/1999)
        // sẽ ra 50,03% — nếu hiện 50,00% nghĩa là tính trên VND thô.
        $this->makeTxn($this->cardA, $period, '1999999', '1000000.00');

        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertSame('50.00', $data['rows'][0]['percent']);
        $this->assertSame('50.00', $data['total_percent']);

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        // Tiền hiển thị theo nghìn (floor: 1.999 nghìn) nhưng tỷ lệ vẫn là VND thô.
        $this->assertStringContainsString('>1.999', $html);
        $this->assertStringContainsString('>50,00%<', $html);
    }

    #[Test]
    public function the_report_never_leaks_data_of_another_user(): void
    {
        $other = User::factory()->create();
        $foreignCard = $this->makeUserCard($other->id, ['name' => 'Thẻ của người khác']);
        $foreignPeriod = $this->periodFor($foreignCard);
        $this->makeTxn($foreignCard, $foreignPeriod, '99000000', '99000.00');

        // Cửa ghi duy nhất (StoreReportRequest) chặn ngay id thẻ ngoại nhân.
        $this->actingAs($this->user)
            ->post(route('credit-cards.reports.store'), [
                'name' => 'Báo cáo lạ',
                'type' => Report::TYPE_BY_CARD,
                'card_ids' => [$foreignCard->id, $this->cardA->id],
            ])
            ->assertSessionHasErrors('card_ids.0');

        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00');

        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );
        $this->assertSame('1000000.00', $data['total_spend']);
        $this->assertSame('10000.00', $data['total_cashback']);

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Thẻ của người khác', $html);
        $this->assertStringNotContainsString('99.000.000', $html);
    }

    private function switchMoneyUnit(string $unit): void
    {
        $this->actingAs($this->user)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), ['money_unit' => $unit])
            ->assertOk();

        CreditCardMoneyFormatter::flushAll();
    }
}
