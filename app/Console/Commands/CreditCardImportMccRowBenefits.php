<?php

namespace App\Console\Commands;

use App\Services\CreditCard\Import\MccRowBenefitImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Nạp DỮ LIỆU HOÀN TIỀN theo dòng MCC cho tất cả hồ sơ (module "Tra cứu MCC").
 *
 * Đọc file dữ liệu tĩnh (`database/data/credit_card_mcc_row_benefits.php`) và
 * ghi vào `credit_card_policy_mcc_row_benefits`. Idempotent: chạy lại cùng nguồn
 * không tạo bản ghi trùng. KHÔNG sửa dữ liệu chính sách/engine đang chạy.
 *
 * Ví dụ:
 *   php artisan credit-card:import-mcc-row-benefits
 *   php artisan credit-card:import-mcc-row-benefits --file=database/data/credit_card_mcc_row_benefits.php
 */
class CreditCardImportMccRowBenefits extends Command
{
    protected $signature = 'credit-card:import-mcc-row-benefits
                            {--file= : Đường dẫn file dữ liệu (mặc định database/data/credit_card_mcc_row_benefits.php)}';

    protected $description = 'Nạp dữ liệu hoàn tiền theo dòng MCC cho hồ sơ (idempotent)';

    public function handle(MccRowBenefitImporter $importer): int
    {
        $file = $this->option('file');
        $file = is_string($file) && $file !== '' ? $file : null;

        $this->components->info('Nạp dữ liệu hoàn tiền theo dòng MCC…');

        try {
            $stats = $importer->import($file);
        } catch (Throwable $e) {
            $this->components->error('Nạp thất bại: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Hồ sơ đã nạp', (string) $stats['profiles_imported']);
        $this->components->twoColumnDetail('Dòng đã nạp', (string) $stats['rows_imported']);
        $this->components->twoColumnDetail('Bậc tạo mới', (string) $stats['tiers_created']);
        $this->components->twoColumnDetail('Bậc cập nhật', (string) $stats['tiers_updated']);
        $this->components->twoColumnDetail('Bậc gỡ bỏ', (string) $stats['tiers_removed']);

        foreach ($stats['skipped_profiles'] as $message) {
            $this->components->warn('Bỏ qua hồ sơ — '.$message);
        }

        foreach ($stats['skipped_rows'] as $message) {
            $this->components->warn('Bỏ qua dòng — '.$message);
        }

        return self::SUCCESS;
    }
}
