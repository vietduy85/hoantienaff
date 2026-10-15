<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm cấu hình PHÍ THƯỜNG NIÊN cho thẻ THẬT của user
 * (`credit_card_user_cards`) — dữ liệu user tự khai trên form Thêm/Sửa thẻ.
 *
 * Ba cột, tất cả NULLABLE (thẻ cũ và người dùng không nhập đều hợp lệ):
 *
 *   - `annual_fee`               : phí thường niên (VNĐ/năm).
 *   - `annual_fee_waiver_spend`  : tổng mức chi tiêu cần đạt để đáp ứng điều
 *     kiện miễn phí thường niên (VNĐ/năm).
 *   - `next_annual_fee_date`     : ngày dự kiến ngân hàng tính phí thường niên
 *     tiếp theo (chỉ để nhắc nhở, KHÔNG dùng cho phép tính nào).
 *
 * Kiểu dữ liệu: `decimal(16, 2)` khớp `credit_limit`/`desired_spend` — số tiền
 * VND nguyên (không lưu chuỗi có dấu phân cách); ngày dùng `date` như
 * `statement_period_start`. KHÔNG tự điền 0: NULL = chưa khai.
 *
 * KHÁC với phí thường niên ở `credit_card_policy_mcc_profiles`
 * (migration 2026_10_13_000005): đó là thông tin THAM KHẢO theo hồ sơ sản phẩm,
 * còn ba cột này là cấu hình RIÊNG của từng thẻ user đang sở hữu. Không trùng lặp.
 *
 * Migration thuần BỔ SUNG: không xoá/tái tạo bảng hay dữ liệu cũ, không ảnh
 * hưởng engine cashback/blueprint.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->decimal('annual_fee', 16, 2)
                ->nullable()
                ->after('desired_spend')
                ->comment('Phí thường niên (VNĐ/năm) — NULL = chưa khai');
            $table->decimal('annual_fee_waiver_spend', 16, 2)
                ->nullable()
                ->after('annual_fee')
                ->comment('Chi tiêu cần đạt để miễn phí thường niên (VNĐ/năm) — NULL = chưa khai');
            $table->date('next_annual_fee_date')
                ->nullable()
                ->after('annual_fee_waiver_spend')
                ->comment('Ngày tính phí thường niên tiếp theo — chỉ để nhắc nhở, không dùng cho phép tính');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->dropColumn([
                'annual_fee',
                'annual_fee_waiver_spend',
                'next_annual_fee_date',
            ]);
        });
    }
};
