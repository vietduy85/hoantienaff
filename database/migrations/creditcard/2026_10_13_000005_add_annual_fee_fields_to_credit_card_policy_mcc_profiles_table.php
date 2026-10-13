<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm 3 trường PHÍ THƯỜNG NIÊN cho HỒ SƠ MCC — thông tin THAM KHẢO theo hồ sơ.
 *
 *   - `annual_fee_primary`                  : phí thường niên thẻ CHÍNH (VNĐ/năm).
 *   - `annual_fee_supplementary`            : phí thường niên thẻ PHỤ (VNĐ/năm).
 *   - `annual_spend_for_annual_fee_waiver`  : mức chi tiêu trong năm để đáp ứng
 *     điều kiện hoàn/miễn phí thường niên (VNĐ/năm; KHÔNG diễn giải thành cam kết
 *     được miễn — ngân hàng có thể còn điều kiện kèm theo ghi ở `note`).
 *
 * Kiểu dữ liệu: `decimal(16, 2)` nullable — KHÔNG dùng số thực; KHÔNG tự điền 0;
 * NULL = chưa có thông tin đáng tin cậy. Migration thuần BỔ SUNG, an toàn:
 * không xoá/tái tạo bảng hay dữ liệu cũ, không ảnh hưởng engine cashback/blueprint.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_mcc_profiles', function (Blueprint $table) {
            $table->decimal('annual_fee_primary', 16, 2)->nullable()->after('note');
            $table->decimal('annual_fee_supplementary', 16, 2)->nullable()->after('annual_fee_primary');
            $table->decimal('annual_spend_for_annual_fee_waiver', 16, 2)->nullable()->after('annual_fee_supplementary');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_mcc_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'annual_fee_primary',
                'annual_fee_supplementary',
                'annual_spend_for_annual_fee_waiver',
            ]);
        });
    }
};
