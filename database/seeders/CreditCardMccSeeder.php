<?php

namespace Database\Seeders;

use App\Services\CreditCard\Import\MccReferenceImporter;
use Illuminate\Database\Seeder;

/**
 * CreditCardMccSeeder — nạp dữ liệu MCC cho module "Tra cứu" nếu CÓ file tham chiếu.
 *
 * IDEMPOTENT và KHÔNG BỊA DỮ LIỆU: seeder chỉ tìm file tham chiếu có sẵn trong
 * `database/data/` rồi uỷ quyền cho `MccReferenceImporter`. Nếu chưa có file
 * (ví dụ chưa bổ sung `MCC_reference_Vietnamese.xlsx`), seeder KHÔNG tạo bản ghi
 * MCC/mapping nào — tránh nhét dữ liệu giả rồi hiển thị như thật trong "Tra cứu".
 *
 * Chạy:
 *   php artisan db:seed --class=CreditCardMccSeeder --database=creditcard
 * hoặc đặt file vào `database/data/` rồi chạy lệnh trên.
 */
class CreditCardMccSeeder extends Seeder
{
    /**
     * Các tên file tham chiếu được tìm (theo thứ tự ưu tiên).
     *
     * @var array<int, string>
     */
    private const CANDIDATES = [
        'mcc_reference_vietnamese.xlsx',
        'mcc_reference_vietnamese.csv',
        'mcc_reference_vietnamese.xls',
    ];

    public function run(): void
    {
        $importer = app(MccReferenceImporter::class);

        $found = false;

        foreach (self::CANDIDATES as $name) {
            $path = database_path('data'.DIRECTORY_SEPARATOR.$name);

            if (! is_file($path)) {
                continue;
            }

            $found = true;
            $stats = $importer->import($path);

            $this->command?->info(sprintf(
                '%s → MCC +%d/~%d, mapping +%d/~%d, bỏ qua %d',
                $name,
                $stats['mcc_created'],
                $stats['mcc_updated'],
                $stats['mappings_created'],
                $stats['mappings_updated'],
                $stats['skipped'],
            ));
        }

        if (! $found) {
            $this->command?->warn(
                'Không tìm thấy file dữ liệu MCC trong database/data/ — bỏ qua (không tạo dữ liệu giả).'
            );
        }
    }
}
