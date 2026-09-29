<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\TransactionImportService;
use PHPUnit\Framework\Attributes\Test;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Biên giới import Excel cho module Thẻ tín dụng.
 *
 * Trọng tâm là các BẤT BIẾN nghiệp vụ, không phải chi tiết parse spreadsheet:
 *   - cashback không bao giờ được nhập từ file,
 *   - dòng sai không được làm hỏng cả file,
 *   - nạp lại cùng file không tạo giao dịch trùng,
 *   - không để user gán giao dịch vào danh mục của người khác.
 */
class TransactionImportServiceTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    /** @var array<int, string> */
    private array $tempFiles = [];

    private User $user;

    private UserCard $card;

    private Category $category;

    private TransactionImportService $service;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->user = User::factory()->create();
        $this->card = $this->makeUserCard($this->user->id);
        $this->category = $this->makeSystemCategory(['name' => 'Ăn uống', 'slug' => 'an-uong']);
        $this->service = app(TransactionImportService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    // =====================================================================
    // Happy path
    // =====================================================================

    #[Test]
    public function it_imports_a_valid_sheet_and_runs_the_cashback_pipeline(): void
    {
        $this->makePolicyForCard(
            $this->card,
            [['name' => 'Tier 1', 'min' => 0, 'max' => null]],
            [['category_id' => $this->category->id, 'percent' => '2.0']],
        );

        $result = $this->service->import(
            $this->card,
            $this->writeSheet([
                'Ngày giao dịch', 'Số tiền', 'Merchant', 'Danh mục',
            ], [
                ['01/10/2026', '1.000.000', 'Highlands', 'Ăn uống'],
            ]),
            'sao-ke-thang-10.xlsx',
        );

        $this->assertSame(1, $result->imported);
        $this->assertSame(0, $result->failed);
        $this->assertSame(0, $result->skipped);
        $this->assertTrue($result->isSuccessful());

        $transaction = Transaction::query()->sole();

        $this->assertSame(Transaction::SOURCE_EXCEL, $transaction->source);
        $this->assertSame('sao-ke-thang-10.xlsx#2', $transaction->source_reference);
        $this->assertSame('2026-10-01', $transaction->transaction_date->toDateString());
        $this->assertSame('1000000.00', (string) $transaction->amount);
        $this->assertSame('Highlands', $transaction->merchant);
        $this->assertSame($this->category->id, $transaction->category_id);

        // Kỳ + cashback phải do pipeline tính, không phải do file cung cấp.
        $this->assertNotNull($transaction->statement_period_id, 'Kỳ phải được suy ra từ statement_day.');
        $this->assertNotNull($transaction->cashback_amount_snapshot);
        $this->assertSame('20000.00', (string) $transaction->cashback_amount_snapshot);
    }

    #[Test]
    public function it_maps_columns_by_header_name_in_any_order(): void
    {
        $result = $this->service->import(
            $this->card,
            $this->writeSheet([
                'Merchant', 'Ngày giao dịch', 'Ghi chú', 'Số tiền',
            ], [
                ['Cafe Central', '2026-10-05', 'ăn sáng', '250000'],
            ]),
            'cot-doi-thu-tu.xlsx',
        );

        $this->assertSame(1, $result->imported);

        $transaction = Transaction::query()->sole();

        $this->assertSame('2026-10-05', $transaction->transaction_date->toDateString());
        $this->assertSame('Cafe Central', $transaction->merchant);
        $this->assertSame('ăn sáng', $transaction->note);
    }

    #[Test]
    public function it_accepts_negative_amounts_for_refunds(): void
    {
        $result = $this->service->import(
            $this->card,
            $this->writeSheet(['Date', 'Amount'], [
                ['2026-10-05', '-150000'],
            ]),
            'hoan-tien.xlsx',
        );

        $this->assertSame(1, $result->imported);
        $this->assertSame('-150000.00', (string) Transaction::query()->sole()->amount);
    }

    // =====================================================================
    // Idempotency
    // =====================================================================

    #[Test]
    public function reimporting_the_same_file_creates_no_duplicates(): void
    {
        $file = $this->writeSheet(['Date', 'Amount'], [
            ['2026-10-05', '1000000'],
            ['2026-10-06', '2000000'],
        ]);

        $first = $this->service->import($this->card, $file, 'october.xlsx');
        $second = $this->service->import($this->card, $file, 'october.xlsx');

        $this->assertSame(2, $first->imported);
        $this->assertSame(0, $second->imported, 'Nạp lại cùng file không được tạo giao dịch mới.');
        $this->assertSame(2, $second->skipped);
        $this->assertSame(2, Transaction::query()->count());
    }

    #[Test]
    public function a_different_filename_is_treated_as_a_different_file(): void
    {
        $first = $this->service->import(
            $this->card,
            $this->writeSheet(['Date', 'Amount'], [['2026-10-05', '1000000']]),
            'a.xlsx',
        );
        $second = $this->service->import(
            $this->card,
            $this->writeSheet(['Date', 'Amount'], [['2026-10-05', '1000000']]),
            'b.xlsx',
        );

        $this->assertSame(1, $first->imported);
        $this->assertSame(1, $second->imported, 'Khác tên file là khác nguồn gốc, không skip.');
        $this->assertSame(2, Transaction::query()->count());
    }

    // =====================================================================
    // Per-row validation
    // =====================================================================

    #[Test]
    public function a_bad_row_is_reported_without_discarding_the_good_rows(): void
    {
        $result = $this->service->import(
            $this->card,
            $this->writeSheet(['Date', 'Amount', 'Danh mục'], [
                ['2026-10-05', '1000000', 'Ăn uống'],   // dòng 2 — hợp lệ
                ['không-phải-ngày', '1000000', 'Ăn uống'], // dòng 3 — ngày sai
                ['2026-10-07', 'abc', 'Ăn uống'],        // dòng 4 — tiền sai
                ['2026-10-08', '1000000', 'Không tồn tại'], // dòng 5 — danh mục sai
            ]),
            'mixed.xlsx',
        );

        $this->assertSame(1, $result->imported);
        $this->assertSame(3, $result->failed);
        $this->assertFalse($result->isSuccessful());

        $messages = array_map(fn ($e) => $e['row'].':'.$e['column'], $result->toArray()['errors']);
        $this->assertSame([
            '3:transaction_date',
            '4:amount',
            '5:category',
        ], $messages);

        $this->assertSame(1, Transaction::query()->count());
    }

    #[Test]
    public function it_ignores_entirely_blank_rows(): void
    {
        $result = $this->service->import(
            $this->card,
            $this->writeSheet(['Date', 'Amount'], [
                ['2026-10-05', '1000000'],
                [null, null],
                [null, null],
            ]),
            'blank-rows.xlsx',
        );

        $this->assertSame(1, $result->imported);
        $this->assertSame(0, $result->failed);
    }

    #[Test]
    public function it_cannot_import_into_another_users_category(): void
    {
        $stranger = User::factory()->create();
        $foreign = $this->makeUserCategory($stranger->id, ['name' => 'Danh mục bí mật', 'slug' => 'bi-mat']);

        $result = $this->service->import(
            $this->card,
            $this->writeSheet(['Date', 'Amount', 'Danh mục'], [
                ['2026-10-05', '1000000', 'bi-mat'],
            ]),
            'cross-user.xlsx',
        );

        $this->assertSame(0, $result->imported);
        $this->assertSame(1, $result->failed);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, $foreign->transactions()->count());
    }

    #[Test]
    public function it_imports_into_the_users_own_category(): void
    {
        $own = $this->makeUserCategory($this->user->id, ['name' => 'Mua sắm', 'slug' => 'mua-sam']);

        $result = $this->service->import(
            $this->card,
            $this->writeSheet(['Date', 'Amount', 'Danh mục'], [
                ['2026-10-05', '1000000', 'mua-sam'],
            ]),
            'own-category.xlsx',
        );

        $this->assertSame(1, $result->imported);
        $this->assertSame($own->id, Transaction::query()->sole()->category_id);
    }

    // =====================================================================
    // File-level rejections
    // =====================================================================

    #[Test]
    public function it_refuses_a_sheet_that_contains_a_cashback_column(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cashback là dữ liệu hệ thống tự tính');

        $this->service->import(
            $this->card,
            $this->writeSheet(['Ngày giao dịch', 'Số tiền', 'Cashback'], [
                ['2026-10-05', '1000000', '999999'],
            ]),
            'co-cot-cashback.xlsx',
        );
    }

    #[Test]
    public function it_refuses_a_vietnamese_cashback_column_too(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->import(
            $this->card,
            $this->writeSheet(['Ngày giao dịch', 'Số tiền', 'Hoàn tiền'], [
                ['2026-10-05', '1000000', '20000'],
            ]),
            'co-hoan-tien.xlsx',
        );
    }

    #[Test]
    public function it_refuses_a_sheet_missing_required_columns(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('thiếu cột bắt buộc');

        $this->service->import(
            $this->card,
            $this->writeSheet(['Merchant', 'Ghi chú'], [
                ['Highlands', 'ghi chú'],
            ]),
            'khong-co-tien.xlsx',
        );
    }

    #[Test]
    public function it_refuses_an_unsupported_file_format(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cc_import_').'.csv';
        file_put_contents($path, "Ngày giao dịch,Số tiền\n2026-10-05,1000000\n");
        $this->tempFiles[] = $path;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('chưa được hỗ trợ');

        $this->service->import($this->card, $path, 'sao-ke.csv');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Ghi một sheet .xlsx tạm và trả về đường dẫn.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|null>>  $rows
     */
    private function writeSheet(array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');

        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 2));
        }

        $path = tempnam(sys_get_temp_dir(), 'cc_import_').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->tempFiles[] = $path;

        return $path;
    }
}
