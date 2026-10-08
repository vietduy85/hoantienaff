<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đơn vị hiển thị số tiền của USER — thêm vào bảng thiết lập CHUNG.
 *
 * ---------------------------------------------------------------------------
 * CHỈ ĐỔI CÁCH HIỂN THỊ, KHÔNG ĐỔI DỮ LIỆU
 * ---------------------------------------------------------------------------
 * `money_unit` quyết định module ghi số tiền dưới dạng `4.237.000 đ` hay
 * `4.237 nghìn` — tức là một lựa chọn CỦA NGƯỜI DÙNG về cách đọc số, giống hệt
 * cách họ chọn "nhắc trước mấy ngày". DB LUÔN lưu VND, mọi phép tính (cashback,
 * quota, tổng hạn mức…) vẫn chạy trên VND; đổi đơn vị không làm thay đổi một
 * đồng nào trong dữ liệu, nên không có cột tiền nào được nhân/chia ở đây.
 *
 * ---------------------------------------------------------------------------
 * DEFAULT `'VND'` — DỮ LIỆU CŨ KHÔNG CẦN ĐỤNG VÀO
 * ---------------------------------------------------------------------------
 * Bảng đã có dòng thiết lập thật (người dùng đã lưu số ngày nhắc). `default`
 * khiến mọi dòng cũ tự nhận `VND` — đúng đơn vị họ vẫn đang thấy — và không
 * cần UPDATE hàng loạt. Cột `string(16)` + DEFAULT chứ không ENUM của DB: cùng
 * lập luận với `payment_status` (const của model dễ mở rộng hơn ENUM cứng).
 *
 * `hasColumn()` bao quát để migration chạy được cả ở nơi cột đã kịp có:
 * migration đã chạy một lần thì chạy lại không nên ném lỗi.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasColumn('credit_card_user_settings', 'money_unit')) {
            $schema->table('credit_card_user_settings', function (Blueprint $table) {
                $table->string('money_unit', 16)
                    ->default('VND')
                    ->comment('Đơn vị hiển thị số tiền: VND | THOUSAND_VND — dữ liệu vẫn lưu VND');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasColumn('credit_card_user_settings', 'money_unit')) {
            $schema->table('credit_card_user_settings', function (Blueprint $table) {
                $table->dropColumn('money_unit');
            });
        }
    }
};
