<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\Import\TransactionImportResult;
use App\Services\CreditCard\Import\TransactionRow;
use App\Services\CreditCard\Import\TransactionRowError;
use App\Services\CreditCard\Import\TransactionSheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TransactionImportService — BIÊN giới import Excel cho module Thẻ tín dụng.
 *
 * Trách nhiệm:
 *   - đọc file (qua `TransactionSheetReader`),
 *   - validate từng dòng, KHÔNG abort cả file vì một dòng sai,
 *   - resolve danh mục theo slug/tên, scope đúng user,
 *   - tạo giao dịch với `source = 'excel'` + `source_reference` để idempotent,
 *   - giao cho `CashbackRecordService` tính cashback qua ĐÚNG pipeline.
 *
 * ---------------------------------------------------------------------------
 * NGUYÊN TẮC KHÔNG THỎA HIỆP
 * ---------------------------------------------------------------------------
 * 1. KHÔNG nhập cashback. Cashback luôn TÍNH TOÁN (§23); file có cột cashback bị
 *    `TransactionSheetReader` từ chối ngay từ đầu.
 * 2. KHÔNG tự chọn kỳ theo ý user. `statement_period_id` suy ra từ
 *    `statement_day` qua `StatementPeriodService`; user tự chỉnh tay sau qua
 *    `assignTransactionToPeriod()` khi kỳ còn `open`.
 * 3. Idempotent: cùng `filename#row` không tạo trùng ⇒ nạp lại file an toàn.
 * 4. Mọi ghi trong MỘT transaction DB ⇒ lỗi giữa chừng không để lại kỳ nửa vời.
 */
class TransactionImportService
{
    /**
     * Thứ tự có ý nghĩa: format chặt hơn (4 chữ số năm) được thử trước để
     * "2026-10-01" không bị đọc nhầm theo `d/m/Y`.
     *
     * @var array<int, string>
     */
    private const DATE_FORMATS = [
        'Y-m-d',
        'Y/m/d',
        'd/m/Y',
        'd-m-Y',
        'd/m/y',
    ];

    public function __construct(
        private readonly TransactionSheetReader $reader,
        private readonly StatementPeriodService $periods,
        private readonly CashbackRecordService $records,
    ) {}

    public function import(
        UserCard $userCard,
        string $path,
        string $filename,
        int $headerRow = 1,
    ): TransactionImportResult {
        // Đọc + kiểm tra header trước (ném RuntimeException nếu file sai cấu trúc).
        $rows = $this->reader->read($path, $headerRow);

        return DB::connection('creditcard')->transaction(
            function () use ($userCard, $rows, $filename): TransactionImportResult {
                $errors = [];
                $importedIds = [];
                $skipped = 0;
                $failed = 0;

                // source_reference đã có ⇒ dòng này đã import rồi.
                $existing = $this->existingReferences($userCard, $filename, $rows);

                foreach ($rows as $row) {
                    $reference = $this->sourceReference($filename, $row->rowNumber);

                    if (isset($existing[$reference])) {
                        $skipped++;

                        continue;
                    }

                    $rowErrors = $this->validate($row, $userCard);

                    if ($rowErrors !== []) {
                        $failed++;
                        $errors = array_merge($errors, $rowErrors);

                        continue;
                    }

                    $transaction = $this->createTransaction($userCard, $row, $filename);

                    // Tính cashback qua pipeline chuẩn (gắn kỳ + snapshot).
                    $this->records->calculateTransaction($userCard, $transaction);

                    $importedIds[] = $transaction->id;

                    $existing[$reference] = true;
                }

                return new TransactionImportResult(
                    imported: count($importedIds),
                    skipped: $skipped,
                    failed: $failed,
                    errors: $errors,
                    importedIds: $importedIds,
                );
            }
        );
    }

    /**
     * @param  array<int, TransactionRow>  $rows
     * @return array<string, true>
     */
    private function existingReferences(UserCard $userCard, string $filename, array $rows): array
    {
        $references = [];

        foreach ($rows as $row) {
            $references[$this->sourceReference($filename, $row->rowNumber)] = true;
        }

        if ($references === []) {
            return [];
        }

        $found = Transaction::query()
            ->where('user_card_id', $userCard->id)
            ->whereIn('source_reference', array_keys($references))
            ->pluck('source_reference')
            ->all();

        return array_fill_keys($found, true);
    }

    /**
     * @return array<int, TransactionRowError>
     */
    private function validate(TransactionRow $row, UserCard $userCard): array
    {
        $errors = [];

        $date = $this->parseDate($row->transactionDate);

        if ($date === null) {
            $errors[] = new TransactionRowError(
                $row->rowNumber,
                TransactionSheetReader::COLUMN_TRANSACTION_DATE,
                'Ngày giao dịch không hợp lệ (nhận dạng được: d/m/Y, Y-m-d, d-m-Y).'
            );
        }

        $amount = $this->parseAmount($row->amount);

        if ($amount === null) {
            $errors[] = new TransactionRowError(
                $row->rowNumber,
                TransactionSheetReader::COLUMN_AMOUNT,
                'Số tiền không hợp lệ.'
            );
        }

        if ($row->postedDate !== null && $this->parseDate($row->postedDate) === null) {
            $errors[] = new TransactionRowError(
                $row->rowNumber,
                TransactionSheetReader::COLUMN_POSTED_DATE,
                'Ngày ghi nhận không hợp lệ.'
            );
        }

        if ($row->category !== null && $this->resolveCategory($userCard, $row->category) === null) {
            $errors[] = new TransactionRowError(
                $row->rowNumber,
                TransactionSheetReader::COLUMN_CATEGORY,
                "Không tìm thấy danh mục \"{$row->category}\" khả dụng cho tài khoản này."
            );
        }

        return $errors;
    }

    private function createTransaction(UserCard $userCard, TransactionRow $row, string $filename): Transaction
    {
        $category = $row->category !== null
            ? $this->resolveCategory($userCard, $row->category)
            : null;

        return Transaction::create([
            'user_card_id' => $userCard->id,
            'category_id' => $category?->id,
            'transaction_date' => $this->parseDate($row->transactionDate)?->toDateString(),
            'posted_date' => $this->parseDate($row->postedDate)?->toDateString(),
            'amount' => number_format((float) $this->parseAmount($row->amount), 2, '.', ''),
            'merchant' => $row->merchant,
            'note' => $row->note,
            'source' => Transaction::SOURCE_EXCEL,
            'source_reference' => $this->sourceReference($filename, $row->rowNumber),
        ]);
    }

    /**
     * Danh mục phải nằm trong tập user được phép chọn (hệ thống + riêng của họ),
     * nếu không user có thể gán giao dịch vào danh mục của người khác.
     */
    private function resolveCategory(UserCard $userCard, string $value): ?Category
    {
        $needle = Str::slug($value);

        return Category::query()
            ->selectableBy((int) $userCard->user_id)
            ->where(function ($query) use ($value, $needle): void {
                $query->where('slug', $needle)
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower(trim($value))]);
            })
            ->first();
    }

    /**
     * Định dạng ngày chấp nhận: Excel đọc ra có thể là "01/10/2026", "2026-10-01"
     * hoặc "01-10-2026". Trả về null nếu không khớp format nào.
     *
     * Dùng `hasFormat` thay vì `createFromFormat` vì app bật Carbon strict mode —
     * `createFromFormat` NÉM exception thay vì trả `false` khi dữ liệu sai.
     */
    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        foreach (self::DATE_FORMATS as $format) {
            if (! CarbonImmutable::hasFormat($value, $format)) {
                continue;
            }

            $parsed = CarbonImmutable::createFromFormat($format, $value)->startOfDay();

            // Vòng tròn: chốt chặn ngày "đi lùi" do Carbon tự chuẩn hoá (13/13/2026).
            if ($parsed->format($format) === $this->formatWith($format, $value)) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Định dạng lại chuỗi ngày theo đúng format đang thử, để so sánh round-trip.
     */
    private function formatWith(string $format, string $value): string
    {
        return CarbonImmutable::createFromFormat($format, $value)->format($format);
    }

    /**
     * Số tiền cho phép ký hiệu tiền tệ, dấu ngoặc ký âm, và CẢ HAI quy ước phân
     * tách số: kiểu Việt ("1.000.000", "1.000.000,50") lẫn kiểu Anh ("1,000,000.50").
     *
     * Cách phân biệt dấu thập phân với dấu phân cách nghìn:
     *   - có cả ',' và '.' ⇒ dấu ngoài cùng bên phải là dấu thập phân;
     *   - chỉ có một dấu và sau nó ĐÚNG 3 chữ số ⇒ phân cách nghìn ("1.000" = 1000);
     *   - còn lại ⇒ dấu thập phân ("12.50" = 12.5).
     *
     * Ngân sách cố ý là VND nên số nguyên chiếm đa số; quy tắc trên khớp với
     * sao kê ngân hàng VN (1.000.000) và bảng tính nước ngoài (1,000,000.00).
     */
    private function parseAmount(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Ngoặc ký âm: (150.000) = -150000
        $negative = str_starts_with($value, '-')
            || str_starts_with($value, '(')
            || Str::contains(mb_strtolower($value), 'trừ');

        // Bỏ ký hiệu tiền tệ, khoảng trắng, chữ cái — giữ số và dấu phân tách.
        $digits = preg_replace('/[^0-9.,]/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        $lastComma = mb_strrpos($digits, ',');
        $lastDot = mb_strrpos($digits, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Dấu ngoài cùng bên phải là dấu thập phân; dấu kia là phân cách nghìn.
            $decimalPosition = max($lastComma, $lastDot);
            $thousandSeparator = $lastComma > $lastDot ? '.' : ',';
        } else {
            $decimalPosition = $lastComma !== false ? $lastComma : $lastDot;
            $thousandSeparator = $lastComma !== false ? ',' : '.';
        }

        $thousandsAt = $decimalPosition === false
            ? false
            : $this->isThousandsSeparator($digits, (int) $decimalPosition, $thousandSeparator);

        if ($thousandsAt !== false) {
            $digits = mb_substr($digits, 0, $thousandsAt)
                .mb_substr($digits, (int) $thousandsAt + 1);
        } else {
            $digits = str_replace(',', '.', $digits);
        }

        $digits = str_replace(['.', ','], '', $digits);

        if ($digits === '' || ! is_numeric($digits)) {
            return null;
        }

        $amount = (float) $digits;

        return $negative ? -$amount : $amount;
    }

    /**
     * Vị trí dấu cần coi là phân cách nghìn, hoặc false nếu là dấu thập phân.
     */
    private function isThousandsSeparator(string $digits, int $position, string $separator): int|false
    {
        // Nhiều hơn một dấu cùng loại ⇒ chắc chắn là phân cách nghìn.
        if (substr_count($digits, $separator) > 1) {
            return $position;
        }

        // Đúng 3 chữ số sau dấu ⇒ phân cách nghìn ("1.000"), không phải thập phân.
        if (strlen(substr($digits, $position + 1)) === 3) {
            return $position;
        }

        return false;
    }

    private function sourceReference(string $filename, int $rowNumber): string
    {
        return $filename.'#'.$rowNumber;
    }
}
