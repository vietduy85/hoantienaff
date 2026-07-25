<?php

namespace App\Services\BankExports;

use App\Contracts\BankExporterInterface;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ChuyenkhoantheobangkeExporter implements BankExporterInterface
{
    private const TEMPLATE_PATH = 'app/Chuyenkhoantheobangke.xlsx';

    private const TEMP_DIR = 'app/temp';

    private const COLUMN_MAP = [
        'stt'              => 'A',
        'bank_account'     => 'B',
        'account_name'     => 'C',
        'bank_name'        => 'D',
        'amount'           => 'E',
        'transfer_content' => 'F',
    ];

    private const DATA_START_ROW = 3;

    private const TRANSFER_PREFIX = 'HoanTien.xyz';

    public function validateTemplate(): void
    {
        $templatePath = storage_path(self::TEMPLATE_PATH);

        if (! file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found: {$templatePath}");
        }

        try {
            IOFactory::load($templatePath);
        } catch (\Exception $e) {
            throw new \RuntimeException("Cannot read template file: {$e->getMessage()}", 0, $e);
        }
    }

    public function validateData(Collection $requests): void
    {
        $missing = collect();

        foreach ($requests as $wr) {
            $issues = [];

            if (empty($wr->bank_account)) {
                $issues[] = 'bank_account';
            }

            if (empty($wr->account_name)) {
                $issues[] = 'account_name';
            }

            if (empty($wr->bank_name)) {
                $issues[] = 'bank_name';
            }

            if ($wr->amount === null || $wr->amount <= 0) {
                $issues[] = 'amount';
            }

            if ($issues !== []) {
                $missing->push("Request #{$wr->id}: " . implode(', ', $issues));
            }
        }

        if ($missing->isNotEmpty()) {
            throw new \InvalidArgumentException(
                'Withdraw requests missing required data for export: ' . $missing->implode('; ')
            );
        }
    }

    public function generateTempFile(Collection $requests): string
    {
        $this->validateTemplate();
        $this->validateData($requests);

        $templatePath = storage_path(self::TEMPLATE_PATH);

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $startRow = self::DATA_START_ROW;
        $colMap = self::COLUMN_MAP;

        foreach ($requests as $index => $wr) {
            $row = $startRow + $index;

            $sheet->setCellValue($colMap['stt'] . $row, $index + 1);
            $sheet->setCellValue($colMap['bank_account'] . $row, $wr->bank_account);
            $sheet->setCellValue($colMap['account_name'] . $row, $wr->account_name);
            $sheet->setCellValue($colMap['bank_name'] . $row, $wr->bank_name);
            $sheet->setCellValue($colMap['amount'] . $row, (float) $wr->amount);
            $sheet->setCellValue($colMap['transfer_content'] . $row, self::TRANSFER_PREFIX . ' ' . $wr->running_no);
        }

        $tempDir = storage_path(self::TEMP_DIR);

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $filename = $this->getTempFilename();
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . $filename;

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempPath);

        return $tempPath;
    }

    public function getTempFilename(): string
    {
        return now()->format('dmY_His') . 'Chuyenkhoantheobangke.xlsx';
    }

    public function getDownloadFilename(): string
    {
        return now()->format('dmY') . 'Chuyenkhoantheobangke.xlsx';
    }
}
