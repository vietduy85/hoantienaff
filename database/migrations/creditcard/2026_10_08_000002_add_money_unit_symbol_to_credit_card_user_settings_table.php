<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ký tự đại diện của ĐƠN VỊ TIỀN — thêm vào bảng thiết lập CHUNG.
 *
 * ---------------------------------------------------------------------------
 * NULL KHÁC "" — đây là SEMANTICS then chốt của cột này
 * ---------------------------------------------------------------------------
 *   - `NULL`  = người dùng CHƯA TỪNG cấu hình ký tự. Khi hiển thị, formatter
 *     tự lấy mặc định theo `money_unit` (VND ⇒ "đ", THOUSAND_VND ⇒ "nghìn").
 *   - `""`    = người dùng CHỦ ĐỘNG xoá ký tự và muốn KHÔNG hiển thị suffix
 *     ("2.000.000" chứ không phải "2.000.000 đ").
 *   - chuỗi khác ("VND", "k", "₫"…) = ký tự tuỳ chỉnh của người dùng.
 *
 * Vì vậy KHÔNG có DB default và KHÔNG migrate dữ liệu cũ thành "đ"/"nghìn":
 * điền default vào cột thì không còn phân biệt được "chưa cấu hình" với "đã
 * cấu hình đúng ký tự mặc định", và người dùng chủ động xoá ký tự sẽ bị biến
 * mất mỗi lần đọc.
 *
 * Cột chỉ là CÁCH HIỂN THỊ: database vẫn lưu VND, mọi phép tính (cashback,
 * quota, statement…) không đọc cột này.
 *
 * `hasColumn()` bao quát để migration chạy được cả ở nơi cột đã kịp có — cùng
 * lập luận với migration `money_unit` bên cạnh.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasColumn('credit_card_user_settings', 'money_unit_symbol')) {
            $schema->table('credit_card_user_settings', function (Blueprint $table) {
                $table->string('money_unit_symbol', 20)
                    ->nullable()
                    ->comment('Ký tự hiển thị sau số tiền: NULL = chưa cấu hình (dùng mặc định theo money_unit), "" = chủ động bỏ suffix');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasColumn('credit_card_user_settings', 'money_unit_symbol')) {
            $schema->table('credit_card_user_settings', function (Blueprint $table) {
                $table->dropColumn('money_unit_symbol');
            });
        }
    }
};
