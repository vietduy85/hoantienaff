<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Report;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardReportService;
use App\Services\CreditCard\StatementPeriodService;
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
}
