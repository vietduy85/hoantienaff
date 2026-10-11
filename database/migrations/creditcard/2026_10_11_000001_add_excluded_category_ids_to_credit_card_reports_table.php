<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bổ sung cấu hình LOẠI TRỪ DANH MỤC cho báo cáo Chi tiêu theo danh mục.
 *
 * ---------------------------------------------------------------------------
 * VẪN LÀ "XEM GÌ", KHÔNG PHẢI "RA SỐ NÀO"
 * ---------------------------------------------------------------------------
 * Cột `excluded_category_ids` là danh sách ID danh mục sẽ BỎ khỏi bảng kết quả
 * và BỎ khỏi mọi tổng của báo cáo theo danh mục. Giá trị là mảng JSON, rỗng =
 * không loại trừ danh mục nào (mặc định tương thích với báo cáo cũ).
 *
 * Hàng đâu đó TỪNG báo cáo cũ (trước migration) sẽ có giá trị `NULL` — tầng PHP
 * đọc `NULL` thành danh sách rỗng, nên báo cáo cũ không đổi hành vi.
 *
 * Chỉ CHẠY qua `php artisan credit-card:migrate` (connection `creditcard`), như
 * mọi migration ở thư mục con `database/migrations/creditcard/`.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_reports', function (Blueprint $table) {
            $table->json('excluded_category_ids')
                ->nullable()
                ->after('type')
                ->comment('ID danh mục bị loại trừ khi tính báo cáo theo danh mục ([] = không loại trừ)');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_reports', function (Blueprint $table) {
            $table->dropColumn('excluded_category_ids');
        });
    }
};