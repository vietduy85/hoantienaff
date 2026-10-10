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
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Xuất Excel báo cáo (PHẦN B) — `/thetindung/bao-cao/{id}/export`.
 *
 * Tính nhất quán bắt buộc với màn hình:
 *   - Export gọi LẠI `CreditCardReportService::byCard()/byCategory()` — đúng tập
 *     giao dịch, đúng kỳ sau `normalizePeriodKey()`, đúng snapshot cashback.
 *   - Tiền trong file là số VND thô (float), không phải chuỗi đã format hoặc số
 *     đã floor theo Money Unit.
 *   - Tỷ lệ là phân số (vd 0.05) định dạng `0.00%`; chi tiêu = 0 ⇒ ô trống (nhất
 *     quán với "—" trên giao diện).
 *   - Export CHỈ ĐỌC: không tạo/sửa kỳ sao kê, không ghi DB.
 *   - Chỉ chủ sở hữu export được; kỳ/thẻ do client gửi đều bị chuẩn hoá/chặn.
 */
class CreditCardReportExportTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $user;

    private UserCard $cardA;

    private UserCard $cardB;

    private const EXPORT_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

        $this->user = User::factory()->create();
        $this->cardA = $this->makeUserCard($this->user->id, ['name' => 'Thẻ A']);
        $this->cardB = $this->makeUserCard($this->user->id, ['name' => 'Thẻ B']);
    }

    protected function tearDown(): void
    {
        CreditCardMoneyFormatter::flushAll();

        parent::tearDown();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

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
            'name' => 'Báo cáo chi tiêu',
            'type' => $type,
        ]);

        if ($cardIds !== []) {
            $report->cards()->sync($cardIds);
        }

        return $report->refresh();
    }

    private function downloadReport(Report $report, array $query = []): TestResponse
    {
        $response = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.export', array_merge(['report' => $report->id], $query)));

        $response->assertOk();

        return $response;
    }

    private function sheetOf(TestResponse $response): Worksheet
    {
        $tmp = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        return $sheet;
    }

    private function switchMoneyUnit(string $unit): void
    {
        $this->actingAs($this->user)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), ['money_unit' => $unit])
            ->assertOk();

        CreditCardMoneyFormatter::flushAll();
    }

    // =====================================================================
    // Export theo THẺ
    // =====================================================================

    #[Test]
    public function export_returns_a_valid_xlsx_file(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $response = $this->downloadReport($report);
        $response->assertHeader('Content-Type', self::EXPORT_MIME);

        $tmp = $response->baseResponse->getFile()->getPathname();
        $this->assertSame('PK', substr((string) file_get_contents($tmp), 0, 2));

        $sheet = $this->sheetOf($response);
        $this->assertSame('Chi tieu theo the', $sheet->getTitle());
    }

    #[Test]
    public function by_card_export_has_the_exact_screen_column_order(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $header = array_slice($sheet->toArray(null, true, false)[2], 0, 5);

        $this->assertSame(
            ['Thẻ', 'Chi tiêu', 'Cashback', 'Tỷ lệ Cashback/Chi tiêu', 'Kỳ sao kê'],
            $header,
        );
    }

    #[Test]
    public function by_card_export_has_per_card_numbers_and_the_selected_period(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '700000', '50000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $row = $sheet->toArray(null, true, false)[3];

        $this->assertSame('Thẻ A', $row[0]);
        $this->assertEqualsWithDelta(700000.0, $row[1], 0.001);
        $this->assertEqualsWithDelta(50000.0, $row[2], 0.001);
        $this->assertEqualsWithDelta(0.0714, $row[3], 0.0001);

        $expectedLabel = $period->period_start->format('d/m/Y').' – '.$period->period_end->format('d/m/Y');
        $this->assertSame($expectedLabel, $row[4]);
    }

    #[Test]
    public function by_card_export_total_row_matches_the_screen(): void
    {
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '10000.00');
        $this->makeTxn($this->cardB, $periodB, '3000000', '75000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id, $this->cardB->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        // Dòng 4-5 là hai thẻ, dòng 6 là tổng.
        $total = $sheet->toArray(null, true, false)[5];

        $this->assertSame('Tổng tất cả', $total[0]);
        $this->assertEqualsWithDelta(4000000.0, $total[1], 0.001);
        $this->assertEqualsWithDelta(85000.0, $total[2], 0.001);
    }

    #[Test]
    public function by_card_export_total_percent_is_from_totals_not_the_average(): void
    {
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        // Thẻ A: 10%, thẻ B: 1% — tổng phải là 1,9%, không phải 5,5%.
        $this->makeTxn($this->cardA, $periodA, '1000000', '100000.00');
        $this->makeTxn($this->cardB, $periodB, '9000000', '90000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id, $this->cardB->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $totalPercentCell = $sheet->getCell('D6');

        $this->assertEqualsWithDelta(0.019, $totalPercentCell->getValue(), 0.0000001);
        $this->assertSame('0.00%', $totalPercentCell->getStyle()->getNumberFormat()->getFormatCode());
    }

    #[Test]
    public function by_card_export_zero_spend_leaves_the_percent_cell_empty(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $value = $sheet->getCell('D4')->getValue();

        $this->assertTrue($value === '' || $value === null, 'Percent cell should be empty when spend is zero.');
        $this->assertEqualsWithDelta(0.0, $sheet->getCell('B4')->getValue(), 0.001);
    }

    #[Test]
    public function by_card_export_mirrors_the_screen_period_selection(): void
    {
        $current = $this->periodFor($this->cardA);
        $previous = $this->periodFor($this->cardA, 1);

        $this->makeTxn($this->cardA, $current, '1000000', '10000.00');
        $this->makeTxn($this->cardA, $previous, '4000000', '80000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $currentSheet = $this->sheetOf($this->downloadReport($report, ['period' => CreditCardReportService::PERIOD_CURRENT]));
        $this->assertEqualsWithDelta(1000000.0, $currentSheet->toArray(null, true, false)[3][1], 0.001);

        $previousSheet = $this->sheetOf($this->downloadReport($report, ['period' => $this->monthKeyFor($this->cardA, 1)]));
        $this->assertEqualsWithDelta(4000000.0, $previousSheet->toArray(null, true, false)[3][1], 0.001);

        // Kỳ lạ rơi về "kỳ hiện tại", giống normalizePeriodKey ở trang kết quả.
        $fallbackSheet = $this->sheetOf($this->downloadReport($report, ['period' => 'khong@hop#le']));
        $this->assertEqualsWithDelta(1000000.0, $fallbackSheet->toArray(null, true, false)[3][1], 0.001);
    }

    #[Test]
    public function by_card_export_follows_the_screen_sort(): void
    {
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        // Thẻ A: 1.000.000 / 90.000 = 9%; Thẻ B: 900.000 / 90.000 = 10%.
        $this->makeTxn($this->cardA, $periodA, '1000000', '90000.00');
        $this->makeTxn($this->cardB, $periodB, '900000', '90000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id, $this->cardB->id]);

        // spend asc: 900.000 lên trước — khác thứ tự tên mặc định, file phải giống màn hình.
        $sheet = $this->sheetOf($this->downloadReport($report, ['sort' => 'spend', 'dir' => 'asc']));
        $rows = $sheet->toArray(null, true, false);
        $this->assertSame('Thẻ B', $rows[3][0]);
        $this->assertSame('Thẻ A', $rows[4][0]);
        // Dòng tổng nằm cuối, số liệu không đổi khi đổi thứ tự.
        $this->assertSame('Tổng tất cả', $rows[5][0]);
        $this->assertEqualsWithDelta(1900000.0, $rows[5][1], 0.001);
        $this->assertEqualsWithDelta(180000.0, $rows[5][2], 0.001);

        // percent desc: B (10%) trước A (9%); nếu sort theo chuỗi kết quả ngược.
        $sheet = $this->sheetOf($this->downloadReport($report, ['sort' => 'percent', 'dir' => 'desc']));
        $rows = $sheet->toArray(null, true, false);
        $this->assertSame('Thẻ B', $rows[3][0]);
        $this->assertSame('Thẻ A', $rows[4][0]);

        // Key/chiều lạ bị bỏ qua ⇒ thứ tự gốc (A trước B), không lỗi.
        $sheet = $this->sheetOf($this->downloadReport($report, ['sort' => 'hack', 'dir' => 'x']));
        $this->assertSame('Thẻ A', $sheet->toArray(null, true, false)[3][0]);
    }

    // =====================================================================
    // Export theo DANH MỤC
    // =====================================================================

    #[Test]
    public function by_category_export_has_the_exact_screen_column_order(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $category->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $header = array_slice($sheet->toArray(null, true, false)[2], 0, 8);

        $this->assertSame([
            'Danh mục',
            'Tổng chi tiêu',
            'Tổng cashback',
            'Tỷ lệ Cashback/Chi tiêu',
            'Thẻ A - Chi tiêu',
            'Thẻ A - Cashback',
            'Thẻ B - Chi tiêu',
            'Thẻ B - Cashback',
        ], $header);
    }

    #[Test]
    public function by_category_export_has_category_totals_and_per_card_pairs(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '10000.00', $category->id);
        $this->makeTxn($this->cardB, $periodB, '2000000', '40000.00', $category->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $row = $sheet->toArray(null, true, false)[3];

        $this->assertSame('Siêu thị', $row[0]);
        $this->assertEqualsWithDelta(3000000.0, $row[1], 0.001);   // tổng chi tiêu
        $this->assertEqualsWithDelta(50000.0, $row[2], 0.001);     // tổng cashback
        $this->assertEqualsWithDelta(0.0166, $row[3], 0.0001);     // 50.000/3.000.000 = 1,66%
        $this->assertEqualsWithDelta(1000000.0, $row[4], 0.001);   // Thẻ A - Chi tiêu
        $this->assertEqualsWithDelta(10000.0, $row[5], 0.001);     // Thẻ A - Cashback
        $this->assertEqualsWithDelta(2000000.0, $row[6], 0.001);   // Thẻ B - Chi tiêu
        $this->assertEqualsWithDelta(40000.0, $row[7], 0.001);     // Thẻ B - Cashback
    }

    #[Test]
    public function by_category_export_keeps_both_total_rows_identical_to_the_screen(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '10000.00', $category->id);
        $this->makeTxn($this->cardA, $periodA, '500000', '5000.00', null);
        $this->makeTxn($this->cardB, $periodB, '2000000', '40000.00', $category->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $rows = collect($sheet->toArray(null, true, false))->filter(
            fn (array $row): bool => in_array($row[0], ['Tổng theo thẻ', 'Tổng tất cả'], true),
        );

        // 2 dòng tổng có cùng số liệu (như màn hình), không đếm trùng.
        $this->assertSame(2, $rows->count());

        foreach ($rows as $row) {
            $this->assertEqualsWithDelta(3500000.0, $row[1], 0.001); // grand chi tiêu
            $this->assertEqualsWithDelta(55000.0, $row[2], 0.001);   // grand cashback
            $this->assertEqualsWithDelta(1500000.0, $row[4], 0.001); // Thẻ A tổng chi tiêu
            $this->assertEqualsWithDelta(15000.0, $row[5], 0.001);   // Thẻ A tổng cashback
            $this->assertEqualsWithDelta(2000000.0, $row[6], 0.001); // Thẻ B tổng chi tiêu
            $this->assertEqualsWithDelta(40000.0, $row[7], 0.001);   // Thẻ B tổng cashback
        }
    }

    #[Test]
    public function by_category_export_keeps_uncategorized_rows(): void
    {
        $period = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $period, '400000', '20000.00', null);
        $this->makeTxn($this->cardA, $period, '600000', '6000.00', $this->makeSystemCategory()->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $row = collect($sheet->toArray(null, true, false))->first(fn (array $row): bool => $row[0] === 'Chưa phân loại');

        $this->assertNotNull($row, 'Uncategorized row must be exported.');
        $this->assertEqualsWithDelta(400000.0, $row[1], 0.001);
        $this->assertEqualsWithDelta(20000.0, $row[2], 0.001);
    }

    #[Test]
    public function by_category_export_never_double_counts_transactions(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        $this->makeTxn($this->cardA, $periodA, '1000000', '10000.00', $category->id);
        $this->makeTxn($this->cardA, $periodA, '500000', '5000.00', null);
        $this->makeTxn($this->cardB, $periodB, '2000000', '20000.00', $category->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $all = $sheet->toArray(null, true, false);
        $dataRows = collect($all)->filter(
            fn (array $row): bool => ! in_array($row[0], ['Tổng theo thẻ', 'Tổng tất cả'], true)
                && $row[0] !== null,
        );

        // Tổng theo danh mục (cột B/C) + tổng theo thẻ (cột E/F) + grand = một con số.
        $sumSpend = $dataRows->sum(fn (array $row): float => (float) $row[1]);
        $sumCashback = $dataRows->sum(fn (array $row): float => (float) $row[2]);
        $sumCardA = $dataRows->sum(fn (array $row): float => (float) $row[4]);

        $grandRow = collect($all)->first(fn (array $row): bool => $row[0] === 'Tổng tất cả');

        $this->assertEqualsWithDelta($grandRow[1], $sumSpend, 0.001);
        $this->assertEqualsWithDelta($grandRow[2], $sumCashback, 0.001);
        $this->assertEqualsWithDelta($grandRow[4], $sumCardA, 0.001);
    }

    #[Test]
    public function by_category_export_follows_the_selected_period(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $current = $this->periodFor($this->cardA);
        $previous = $this->periodFor($this->cardA, 1);

        $this->makeTxn($this->cardA, $current, '1000000', '10000.00', $category->id);
        $this->makeTxn($this->cardA, $previous, '9000000', '90000.00', $category->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id]);

        $currentSheet = $this->sheetOf($this->downloadReport($report));
        $this->assertEqualsWithDelta(1000000.0, $currentSheet->toArray(null, true, false)[3][1], 0.001);

        $previousSheet = $this->sheetOf($this->downloadReport($report, ['period' => $this->monthKeyFor($this->cardA, 1)]));
        $this->assertEqualsWithDelta(9000000.0, $previousSheet->toArray(null, true, false)[3][1], 0.001);
    }

    #[Test]
    public function by_category_export_follows_the_screen_sort(): void
    {
        $catA = $this->makeSystemCategory(['name' => 'A']);
        $catB = $this->makeSystemCategory(['name' => 'B']);
        $periodA = $this->periodFor($this->cardA);
        $periodB = $this->periodFor($this->cardB);

        // Cat A: 400.000 / 36.000 = 9%; Cat B: 300.000 / 30.000 = 10%.
        $this->makeTxn($this->cardA, $periodA, '200000', '18000.00', $catA->id);
        $this->makeTxn($this->cardB, $periodB, '200000', '18000.00', $catA->id);
        $this->makeTxn($this->cardA, $periodA, '150000', '15000.00', $catB->id);
        $this->makeTxn($this->cardB, $periodB, '150000', '15000.00', $catB->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id, $this->cardB->id]);

        // percent desc: B (10%) lên trước A (9%) — khác thứ tự tên mặc định.
        $sheet = $this->sheetOf($this->downloadReport($report, ['sort' => 'percent', 'dir' => 'desc']));
        $rows = $sheet->toArray(null, true, false);

        $this->assertSame('B', $rows[3][0]);
        $this->assertSame('A', $rows[4][0]);
        // Cặp dữ liệu mỗi thẻ vẫn nằm nguyên trong dòng danh mục của nó.
        $this->assertEqualsWithDelta(150000.0, $rows[3][4], 0.001); // Thẻ A - Chi tiêu
        $this->assertEqualsWithDelta(150000.0, $rows[3][6], 0.001); // Thẻ B - Chi tiêu
        $this->assertEqualsWithDelta(200000.0, $rows[4][4], 0.001);
        $this->assertEqualsWithDelta(200000.0, $rows[4][6], 0.001);

        // Hai dòng tổng giữ nguyên số liệu sau khi đổi thứ tự.
        $totals = collect($rows)->filter(
            fn (array $row): bool => in_array($row[0], ['Tổng theo thẻ', 'Tổng tất cả'], true),
        );
        $this->assertSame(2, $totals->count());
        foreach ($totals as $row) {
            $this->assertEqualsWithDelta(700000.0, $row[1], 0.001);
            $this->assertEqualsWithDelta(66000.0, $row[2], 0.001);
        }
    }

    // =====================================================================
    // Định dạng tiền / tỷ lệ & tiếng Việt
    // =====================================================================

    #[Test]
    public function export_money_cells_are_excel_numbers_not_formatted_strings(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '50000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $spendCell = $sheet->getCell('B4');
        $cashbackCell = $sheet->getCell('C4');

        $this->assertIsFloat($spendCell->getValue());
        $this->assertIsFloat($cashbackCell->getValue());
        $this->assertEqualsWithDelta(1000000.0, $spendCell->getValue(), 0.001);
        $this->assertSame('#,##0', $spendCell->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertStringNotContainsString('đ', (string) $cashbackCell->getValue());
    }

    #[Test]
    public function export_percent_cells_are_numeric_with_a_percentage_format(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '700000', '50000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $percentCell = $sheet->getCell('D4');

        $this->assertIsFloat($percentCell->getValue());
        $this->assertEqualsWithDelta(0.0714, $percentCell->getValue(), 0.0001);
        $this->assertSame('0.00%', $percentCell->getStyle()->getNumberFormat()->getFormatCode());
    }

    #[Test]
    public function export_keeps_raw_vnd_even_under_thousand_vnd_unit(): void
    {
        $this->switchMoneyUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $period = $this->periodFor($this->cardA);
        // 1.999.999 đã floor hiển thị thành 1.999 nghìn — export phải giữ 1.999.999.
        $this->makeTxn($this->cardA, $period, '1999999', '1000000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $this->assertEqualsWithDelta(1999999.0, $sheet->getCell('B4')->getValue(), 0.001);
        $this->assertStringContainsString('Đơn vị tiền: VND', (string) $sheet->getCell('A2')->getValue());
    }

    #[Test]
    public function export_keeps_vietnamese_unicode_text(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Hóa đơn điện, nước, internet']);
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $category->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id], );
        $report->update(['name' => 'Báo cáo danh mục quý 4']);
        $sheet = $this->sheetOf($this->downloadReport($report));

        $this->assertSame('Báo cáo danh mục quý 4', $sheet->getCell('A1')->getValue());
        $this->assertSame('Hóa đơn điện, nước, internet', $sheet->getCell('A4')->getValue());
        $this->assertSame('Thẻ A - Chi tiêu', $sheet->getCell('E3')->getValue());
    }

    #[Test]
    public function export_never_treats_user_text_as_a_formula(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $report->update(['name' => '=SUM(A1:A2)  tên báo cáo']);
        $sheet = $this->sheetOf($this->downloadReport($report));

        // Tên báo cáo được ghi kiểu chuỗi — không phải công thức.
        $this->assertFalse($sheet->getCell('A1')->isFormula());
        $this->assertStringContainsString('tên báo cáo', (string) $sheet->getCell('A1')->getValue());
    }

    // =====================================================================
    // Quyền, hành vi "không ghi", và giao diện nút export
    // =====================================================================

    #[Test]
    public function a_foreign_user_cannot_export_someone_elses_report(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $foreign = User::factory()->create();

        $this->actingAs($foreign)
            ->get(route('credit-cards.reports.export', ['report' => $report->id]))
            ->assertForbidden();
    }

    #[Test]
    public function export_with_no_transactions_still_produces_a_valid_file(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $sheet = $this->sheetOf($this->downloadReport($report));

        $this->assertSame('Thẻ', $sheet->toArray(null, true, false)[2][0]);
        // Thẻ vẫn xuất hiện với chi tiêu 0; dòng Tổng nằm ngay dưới.
        $this->assertSame('Thẻ A', $sheet->toArray(null, true, false)[3][0]);
        $this->assertEqualsWithDelta(0.0, $sheet->getCell('B4')->getValue(), 0.001);
        $this->assertSame('Tổng tất cả', $sheet->toArray(null, true, false)[4][0]);
        $this->assertEqualsWithDelta(0.0, $sheet->getCell('B5')->getValue(), 0.001);
    }

    #[Test]
    public function export_does_not_create_or_touch_statement_periods(): void
    {
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00');

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id]);

        $periodsBefore = StatementPeriod::query()->count();

        $this->downloadReport($report);

        $this->assertSame($periodsBefore, StatementPeriod::query()->count());
    }

    #[Test]
    public function the_show_page_offers_an_export_link_for_the_selected_period(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id, 'period' => 'current']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="report-export-link"', $html);
        $this->assertStringContainsString(
            route('credit-cards.reports.export', ['report' => $report->id, 'period' => 'current']),
            $html,
        );
    }

    #[Test]
    public function the_show_page_keeps_the_export_link_in_sync_with_the_chosen_month(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);
        $month = $this->monthKeyFor($this->cardA);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id, 'period' => $month]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('credit-cards.reports.export', ['report' => $report->id, 'period' => $month]),
            $html,
        );
    }

    #[Test]
    public function a_report_without_cards_has_no_export_link(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CARD);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('report-export-link', $html);
    }

    #[Test]
    public function an_export_error_is_flashed_back_on_the_report_page(): void
    {
        session()->flash('error', 'Không thể tạo file Excel. Vui lòng thử lại sau giây lát.');

        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id]);

        $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id]))
            ->assertOk()
            ->assertSee('report-export-error', false);
    }
}