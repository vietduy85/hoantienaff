<?php

namespace App\Services\CreditCard\Import;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * TransactionSheetReader — đọc sheet giao dịch ra mảng `TransactionRow`.
 *
 * CHỈ LOẠI file, KHÔNG validate nghiệp vụ, KHÔNG ghi database.
 *
 * ---------------------------------------------------------------------------
 * BA NGUYÊN TẮC
 * ---------------------------------------------------------------------------
 * 1. Map cột THEO TÊN HEADER, không theo vị trí. Ngân hàng/thư viện Excel khác
 *    nhau về thứ tự cột; người dùng tải file mẫu từ nhiều nơi.
 * 2. Header tiếng Việt lẫn tiếng Anh, không dấu/không dấu, không phân biệt hoa
 *    thường (vd "Ngày giao dịch" ≡ "ngay giao dich").
 * 3. TUỐI QUYỀN HỒ SƠ CỘT CASHBACK. Nếu file có cột kiểu cashback
 *    ("cashback", "hoàn tiền", ...) thì HÀNH ĐỘNG cả file bị từ chối, vì
 *    cashback là dữ liệu TÍNH TOÁN (§23), không bao giờ được nhập tay.
 */
class TransactionSheetReader
{
    /** Cột bắt buộc. */
    public const COLUMN_TRANSACTION_DATE = 'transaction_date';

    public const COLUMN_POSTED_DATE = 'posted_date';

    public const COLUMN_AMOUNT = 'amount';

    public const COLUMN_MERCHANT = 'merchant';

    public const COLUMN_CATEGORY = 'category';

    public const COLUMN_NOTE = 'note';

    /**
     * Header (đã chuẩn hoá) => cột hợp lệ trong hệ thống.
     *
     * @var array<string, string>
     */
    private const HEADER_ALIASES = [
        // Ngày giao dịch
        'ngay giao dich' => self::COLUMN_TRANSACTION_DATE,
        'ngay' => self::COLUMN_TRANSACTION_DATE,
        'date' => self::COLUMN_TRANSACTION_DATE,
        'transaction date' => self::COLUMN_TRANSACTION_DATE,
        'ngay thuc hien' => self::COLUMN_TRANSACTION_DATE,
        'ngay gd' => self::COLUMN_TRANSACTION_DATE,

        // Ngày ghi nhận
        'ngay ghi nhan' => self::COLUMN_POSTED_DATE,
        'ngay ghi nhan bank' => self::COLUMN_POSTED_DATE,
        'posted date' => self::COLUMN_POSTED_DATE,
        'posted' => self::COLUMN_POSTED_DATE,
        'ngay hach toan' => self::COLUMN_POSTED_DATE,
        'value date' => self::COLUMN_POSTED_DATE,

        // Số tiền
        'so tien' => self::COLUMN_AMOUNT,
        'so tien giao dich' => self::COLUMN_AMOUNT,
        'amount' => self::COLUMN_AMOUNT,
        'giao dich' => self::COLUMN_AMOUNT,
        'transaction amount' => self::COLUMN_AMOUNT,

        // Merchant
        'merchant' => self::COLUMN_MERCHANT,
        'ten merchant' => self::COLUMN_MERCHANT,
        'nguoi ban' => self::COLUMN_MERCHANT,
        'cua hang' => self::COLUMN_MERCHANT,
        'nhan hieu' => self::COLUMN_MERCHANT,

        // Danh mục
        'danh muc' => self::COLUMN_CATEGORY,
        'category' => self::COLUMN_CATEGORY,
        'danh muc chi tieu' => self::COLUMN_CATEGORY,
        'nhom chi' => self::COLUMN_CATEGORY,

        // Ghi chú
        'ghi chu' => self::COLUMN_NOTE,
        'note' => self::COLUMN_NOTE,
        'memo' => self::COLUMN_NOTE,
        'description' => self::COLUMN_NOTE,
    ];

    /**
     * Header chứa một trong các từ này ⇒ file bị từ chối.
     *
     * CỐ TÌNH CHỈ CHỌN TỪ KHÔNG THỂ TRÙNG VỚI TÊN CỘT HỢP LỆ. Ví dụ không dùng
     * `'bac'` vì "Bắc Giang" hay "bác sĩ" sẽ bị từ chối oan, và `'tier'` vì tên
     * bậc không bao giờ là tên cột trong file sao kê.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_HEADER_TOKENS = [
        'cashback',
        'hoan tien',
        'tien hoan',
    ];

    /**
     * @return array<int, TransactionRow>
     *
     * @throws RuntimeException khi file không đọc được, thiếu cột bắt buộc,
     *                          hoặc chứa cột cashback.
     */
    public function read(string $path, int $headerRow = 1): array
    {
        $reader = $this->readerFor($path);
        $spreadsheet = $reader->load($path);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn() ?: 'A');
            $lastRow = (int) $sheet->getHighestRow();

            $columnMap = $this->mapColumns($sheet, $headerRow, $lastColumn);

            $rows = [];

            for ($rowNumber = $headerRow + 1; $rowNumber <= $lastRow; $rowNumber++) {
                $row = $this->readRow($sheet, $rowNumber, $columnMap);

                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function readerFor(string $path): IReader
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => new XlsxReader,
            // Csv không dùng extension detection của PhpSpreadsheet vì cần
            // delimiter; Phase 1A chỉ nhận .xlsx.
            default => throw new RuntimeException(
                "Định dạng file \"{$extension}\" chưa được hỗ trợ. Phase 1A chỉ nhận .xlsx."
            ),
        };
    }

    /**
     * @return array<string, string> tên cột hệ thống => chỉ số cột (0-based)
     *
     * @throws RuntimeException
     */
    private function mapColumns(Worksheet $sheet, int $headerRow, int $lastColumn): array
    {
        $map = [];

        for ($column = 1; $column <= $lastColumn; $column++) {
            $header = $this->normalise(
                (string) $sheet->getCell(Coordinate::stringFromColumnIndex($column).$headerRow)->getValue()
            );

            if ($header === '') {
                continue;
            }

            foreach (self::FORBIDDEN_HEADER_TOKENS as $token) {
                if (Str::contains($header, $token)) {
                    throw new RuntimeException(
                        "File chứa cột \"{$header}\" — cashback là dữ liệu hệ thống tự tính, không được nhập từ Excel."
                    );
                }
            }

            $systemColumn = self::HEADER_ALIASES[$header] ?? null;

            if ($systemColumn !== null && ! isset($map[$systemColumn])) {
                $map[$systemColumn] = $column;
            }
        }

        foreach ([self::COLUMN_TRANSACTION_DATE, self::COLUMN_AMOUNT] as $required) {
            if (! isset($map[$required])) {
                throw new RuntimeException(
                    "File thiếu cột bắt buộc \"{$required}\". Cột tối thiểu cần có: ngày giao dịch và số tiền."
                );
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $columnMap
     */
    private function readRow(Worksheet $sheet, int $rowNumber, array $columnMap): ?TransactionRow
    {
        $get = function (string $column) use ($sheet, $rowNumber, $columnMap): ?string {
            if (! isset($columnMap[$column])) {
                return null;
            }

            $value = $sheet
                ->getCell(Coordinate::stringFromColumnIndex($columnMap[$column]).$rowNumber)
                ->getValue();

            if ($value === null) {
                return null;
            }

            // Ô có thể chứa RichText; lấy text thuần thay vì đối tượng.
            $value = is_scalar($value) ? (string) $value : $value->getRichText()->getPlainText();

            return trim($value) === '' ? null : trim($value);
        };

        $transactionDate = $get(self::COLUMN_TRANSACTION_DATE);
        $amount = $get(self::COLUMN_AMOUNT);

        // Bỏ qua dòng trống hoàn toàn (spreadsheet hay có dòng trống ở cuối).
        if ($transactionDate === null && $amount === null) {
            return null;
        }

        return new TransactionRow(
            rowNumber: $rowNumber,
            transactionDate: $transactionDate,
            postedDate: $get(self::COLUMN_POSTED_DATE),
            amount: $amount,
            merchant: $get(self::COLUMN_MERCHANT),
            category: $get(self::COLUMN_CATEGORY),
            note: $get(self::COLUMN_NOTE),
        );
    }

    /**
     * Chuẩn hoá header: bỏ dấu tiếng Việt, hạ chữ thường, gộp khoảng trắng.
     */
    private function normalise(string $header): string
    {
        $header = Str::ascii($header);

        return trim(preg_replace('/\s+/', ' ', strtolower($header)) ?? '');
    }
}
