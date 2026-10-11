<?php

namespace App\Console\Commands;

use App\Services\CreditCard\Import\MccReferenceImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Nạp dữ liệu MCC + mapping cho module "Tra cứu".
 *
 * Đọc file tham chiếu (CSV/XLSX), ghi vào `credit_card_mcc_codes` và
 * `credit_card_policy_mcc_mappings`. Idempotent: chạy lại cùng file không tạo
 * bản ghi trùng. KHÔNG sửa dữ liệu chính sách đang chạy.
 *
 * Ví dụ:
 *   php artisan credit-card:import-mcc database/data/mcc_reference_vietnamese.csv
 */
class CreditCardImportMcc extends Command
{
    protected $signature = 'credit-card:import-mcc
                            {file : Đường dẫn file .csv/.xlsx/.xls}
                            {--sheet= : Tên sheet chứa danh sách MCC (mặc định tự dò)}';

    protected $description = 'Nạp dữ liệu MCC + mapping chính sách cho module Tra cứu (idempotent)';

    public function handle(MccReferenceImporter $importer): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->components->error("Không tìm thấy file: {$path}");

            return self::FAILURE;
        }

        $sheet = $this->option('sheet');
        $sheet = is_string($sheet) && $sheet !== '' ? $sheet : null;

        $this->components->info("Nạp MCC từ: {$path}");

        try {
            $stats = $importer->import($path, $sheet);
        } catch (Throwable $e) {
            $this->components->error('Nạp thất bại: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Sheet dùng', $stats['sheet']);
        $this->components->twoColumnDetail('Dòng đọc được', (string) $stats['rows_read']);
        $this->components->twoColumnDetail('MCC tạo mới', (string) $stats['mcc_created']);
        $this->components->twoColumnDetail('MCC cập nhật', (string) $stats['mcc_updated']);
        $this->components->twoColumnDetail('MCC không đổi', (string) $stats['mcc_unchanged']);
        $this->components->twoColumnDetail('Mapping tạo mới', (string) $stats['mappings_created']);
        $this->components->twoColumnDetail('Mapping cập nhật', (string) $stats['mappings_updated']);
        $this->components->twoColumnDetail('Dòng bỏ qua', (string) $stats['skipped']);

        return self::SUCCESS;
    }
}
