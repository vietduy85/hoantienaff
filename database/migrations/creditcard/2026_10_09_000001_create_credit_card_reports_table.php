<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Báo cáo — CẤU HÌNH của một báo cáo do user đặt tên và lưu lại.
 *
 * ---------------------------------------------------------------------------
 * CHỈ LƯU "XEM GÌ", KHÔNG LƯU "RA SỐ NÀO"
 * ---------------------------------------------------------------------------
 * Bảng này KHÔNG có cột tổng chi tiêu / tổng cashback. Báo cáo phải phản ánh dữ
 * liệu HIỆN TẠI: user nhập thêm giao dịch, sửa danh mục, hay kỳ sao kê được tính
 * lại thì mở lại báo cáo phải ra số mới. Chốt cứng kết quả vào đây sẽ biến báo
 * cáo thành một bản sao chết của lịch sử giao dịch.
 *
 * Vì vậy cấu hình chỉ gồm: ai sở hữu, tên, và KIỂU báo cáo (theo thẻ / theo danh
 * mục). Danh sách thẻ là một bảng nối riêng (`credit_card_report_cards`) để có
 * thể kiểm chứng được từng thẻ thay vì nhét chuỗi id vào một cột.
 *
 * `user_id` là LOGICAL REFERENCE tới `hoantienaff.users.id` — cùng quy ước mọi
 * bảng Thẻ tín dụng khác, KHÔNG có physical FK cross-database.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_reports', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')
                ->comment('users.id ở hoantienaff.users — logical reference, KHÔNG FK cross-database');

            $table->string('name', 150)->comment('Tên user tự đặt, ví dụ "Chi tiêu quý này"');
            $table->enum('type', ['by_card', 'by_category'])
                ->comment('by_card = chi tiêu theo thẻ; by_category = chi tiêu theo danh mục');

            $table->timestamps();

            // Mọi truy vấn đều scope theo chủ sở hữu — index phục vụ đúng đường đọc đó.
            $table->index(['user_id', 'id'], 'credit_card_reports_user_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_reports');
    }
};
