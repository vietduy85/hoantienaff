<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Report;
use App\Support\CreditCard\CreditCardSort;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Xuất file `.xlsx` của báo cáo thẻ tín dụng.
 *
 * ---------------------------------------------------------------------------
 * DỮ LIỆU = ĐÚNG DỮ LIỆU MÀN HÌNH, KHÔNG TÍNH LẠI
 * ---------------------------------------------------------------------------
 * Lớp này GỌI LẠI `CreditCardReportService::byCard()` / `byCategory()` — tức là
 * MỘT nguồn duy nhất với trang kết quả (`/thetindung/bao-cao/{id}`). Báo cáo
 * Excel luôn bằng màn hình vì cùng query, cùng `cashback_amount_snapshot`, cùng
 * phạm vi kỳ theo `normalizePeriodKey()`. Không có công thức tính báo cáo thứ
 * hai ở đây.
 *
 * ---------------------------------------------------------------------------
 * ĐỊNH DẠNG Ô
 * ---------------------------------------------------------------------------
 *   - Tiền = số Excel thật (float), có `#,##0`; KHÔNG bao giờ là chuỗi có dấu
 *     chấm/phẩy hay hậu tố "đ". Giá trị luôn là VND THÔ từ service, kể cả khi
 *     user đang để Money Unit `THOUSAND_VND` (dòng ghi chú trong file luôn nói
 *     "Đơn vị tiền: VND").
 *   - Tỷ lệ = phân số (vd 0.0714) với định dạng `0.00%`; khi chi tiêu bằng 0 thì
 *     để trống (nhất quán với "—" trên giao diện).
 *   - Chữ do user kiểm soát (tên báo cáo, tên thẻ, tên danh mục, nhãn kỳ) được
 *     ghi BẰNG `setCellValueExplicit(..., TYPE_STRING)` để không bao giờ bị coi
 *     là công thức Excel khi mở lại.
 *
 * KIM NAM: export CHỈ ĐỌC, giống hệt mở trang — không tạo/sửa kỳ sao kê.
 */
class CreditCardReportExporter
{
    private const TEMP_DIR = 'app/temp';

    private const MONEY_FORMAT = '#,##0';

    private const PERCENT_FORMAT = '0.00%';

    public function __construct(
        private readonly CreditCardReportService $reports,
    ) {}

    /**
     * Tạo file `.xlsx` tạm và trả đường dẫn tuyệt đối. Gọi xong phải `unlink` —
     * controller dùng `response()->download(...)->deleteFileAfterSend(true)`.
     *
     * `$sortKey`/`$sortDir` là tiêu chí + chiều đang xem trên màn hình: file giữ
     * đúng thứ tự dòng như trang kết quả để dễ đối chiếu. Key/chiều lạ bị `null`
     * hoá bởi `CreditCardSort::normalize*()` — mọi số liệu và tổng không đổi.
     */
    public function export(
        Report $report,
        Collection $cards,
        string $periodKey,
        ?string $sortKey = null,
        string $sortDir = CreditCardSort::ASC,
    ): string
    {
        $isByCategory = $report->type === Report::TYPE_BY_CATEGORY;

        $data = $isByCategory
            ? $this->reports->byCategory($cards, $periodKey)
            : $this->reports->byCard($cards, $periodKey);

        $data['rows'] = CreditCardSort::applyRows(
            $data['rows'],
            $data['mode'],
            CreditCardSort::normalizeSort($sortKey),
            $sortDir,
        );

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($isByCategory ? 'Chi tieu danh muc' : 'Chi tieu theo the');

        $sheet->setCellValueExplicit('A1', $report->name, DataType::TYPE_STRING);
        $sheet->setCellValueExplicit(
            'A2',
            'Kỳ: '.$this->selectedPeriodLabel($cards, $periodKey).' · Đơn vị tiền: VND',
            DataType::TYPE_STRING,
        );

        if ($isByCategory) {
            $this->writeByCategory($sheet, $data);
        } else {
            $this->writeByCard($sheet, $data);
        }

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex(1))->setWidth(34);

        $tempPath = $this->tempPath();
        (new XlsxWriter($spreadsheet))->save($tempPath);
        $spreadsheet->disconnectWorksheets();

        return $tempPath;
    }

    /**
     * Tên file tải xuống, vd `bao-cao-chi-tieu-2026-10.xlsx`. Kỳ "hiện tại của
     * từng thẻ" không có một tháng chung nên lấy tháng hôm nay.
     */
    public function downloadFilename(Report $report, string $periodKey): string
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $periodKey) === 1 ? $periodKey : now()->format('Y-m');

        $prefix = $report->type === Report::TYPE_BY_CATEGORY
            ? 'bao-cao-chi-tieu-danh-muc'
            : 'bao-cao-chi-tieu';

        return $prefix.'-'.$month.'.xlsx';
    }

    private function writeByCard(Worksheet $sheet, array $data): void
    {
        $this->writeHeaderRow($sheet, 3, ['Thẻ', 'Chi tiêu', 'Cashback', 'Tỷ lệ Cashback/Chi tiêu', 'Kỳ sao kê']);

        $row = 4;

        foreach ($data['rows'] as $item) {
            $this->writeText($sheet, 'A'.$row, $item['name']);
            $this->writeMoney($sheet, 'B'.$row, $item['spend']);
            $this->writeMoney($sheet, 'C'.$row, $item['cashback']);
            $this->writePercent($sheet, 'D'.$row, $item['percent']);
            $this->writeText($sheet, 'E'.$row, $item['period_label'] ?? '');
            $row++;
        }

        $this->writeText($sheet, 'A'.$row, 'Tổng tất cả');
        $this->writeMoney($sheet, 'B'.$row, $data['total_spend']);
        $this->writeMoney($sheet, 'C'.$row, $data['total_cashback']);
        $this->writePercent($sheet, 'D'.$row, $data['total_percent']);
        $this->writeText($sheet, 'E'.$row, '');
        $this->styleTotalRow($sheet, $row);

        // Tỷ lệ nằm giữa tiền và kỳ; để kỳ đủ rộng đọc ngày.
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(16);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(28);
    }

    private function writeByCategory(Worksheet $sheet, array $data): void
    {
        $headers = ['Danh mục', 'Tổng chi tiêu', 'Tổng cashback', 'Tỷ lệ Cashback/Chi tiêu'];

        foreach ($data['columns'] as $column) {
            $headers[] = $column['name'].' - Chi tiêu';
            $headers[] = $column['name'].' - Cashback';
        }

        $this->writeHeaderRow($sheet, 3, $headers);

        $row = 4;

        foreach ($data['rows'] as $categoryRow) {
            $this->writeText($sheet, 'A'.$row, $categoryRow['name']);
            $this->writeMoney($sheet, 'B'.$row, $categoryRow['spend_total']);
            $this->writeMoney($sheet, 'C'.$row, $categoryRow['cashback_total']);
            $this->writePercent($sheet, 'D'.$row, $categoryRow['percent']);

            $col = 5;

            foreach ($data['columns'] as $column) {
                $cell = $categoryRow['cells'][$column['card_id']] ?? ['spend' => '0.00', 'cashback' => '0.00'];

                $this->writeMoney($sheet, Coordinate::stringFromColumnIndex($col).$row, $cell['spend']);
                $this->writeMoney($sheet, Coordinate::stringFromColumnIndex($col + 1).$row, $cell['cashback']);
                $col += 2;
            }

            $row++;
        }

        // Hai dòng tổng Y HỆT footer màn hình: 3 cột đầu = grand, cặp cột = theo thẻ.
        foreach (['Tổng theo thẻ', 'Tổng tất cả'] as $label) {
            $this->writeText($sheet, 'A'.$row, $label);
            $this->writeMoney($sheet, 'B'.$row, $data['grand']['spend']);
            $this->writeMoney($sheet, 'C'.$row, $data['grand']['cashback']);
            $this->writePercent($sheet, 'D'.$row, $data['grand']['percent']);

            $col = 5;

            foreach ($data['columns'] as $column) {
                $total = $data['card_totals'][$column['card_id']];

                $this->writeMoney($sheet, Coordinate::stringFromColumnIndex($col).$row, $total['spend']);
                $this->writeMoney($sheet, Coordinate::stringFromColumnIndex($col + 1).$row, $total['cashback']);
                $col += 2;
            }

            $this->styleTotalRow($sheet, $row);
            $row++;
        }

        for ($col = 2; $col <= count($headers); $col++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(16);
        }
    }

    private function writeHeaderRow(Worksheet $sheet, int $row, array $headers): void
    {
        foreach ($headers as $index => $header) {
            $this->writeText($sheet, Coordinate::stringFromColumnIndex($index + 1).$row, $header);
        }

        $last = Coordinate::stringFromColumnIndex(count($headers));
        $range = 'A'.$row.':'.$last.$row;

        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFE2E8F0');
    }

    private function styleTotalRow(Worksheet $sheet, int $row): void
    {
        $last = $sheet->getHighestColumn($row);
        $range = 'A'.$row.':'.$last.$row;

        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFF1F5F9');
    }

    private function writeMoney(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValue($cell, (float) $value);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
    }

    private function writePercent(Worksheet $sheet, string $cell, ?string $percent): void
    {
        if ($percent === null) {
            $sheet->setCellValueExplicit($cell, '', DataType::TYPE_STRING);

            return;
        }

        $sheet->setCellValue($cell, ((float) $percent) / 100);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::PERCENT_FORMAT);
    }

    private function writeText(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function selectedPeriodLabel(Collection $cards, string $periodKey): string
    {
        foreach ($this->reports->periodOptions($cards) as $option) {
            if ($option['key'] === $periodKey) {
                return $option['label'];
            }
        }

        return 'Kỳ hiện tại của từng thẻ';
    }

    private function tempPath(): string
    {
        $dir = storage_path(self::TEMP_DIR);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.DIRECTORY_SEPARATOR.'bao-cao-excel-'.now()->format('Ymd_His').'-'.Str::random(6).'.xlsx';
    }
}