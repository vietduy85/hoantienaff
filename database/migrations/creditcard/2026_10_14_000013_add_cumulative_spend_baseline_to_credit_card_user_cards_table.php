<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm CỘT MỐC CHI TIÊU LŨY KẾ KỲ TRƯỚC cho thẻ THẬT của user
 * (`credit_card_user_cards`).
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CẦN
 * ---------------------------------------------------------------------------
 * Điều kiện miễn phí thường niên của ngân hàng thường là "tổng chi tiêu trong NĂM
 * đạt ngưỡng", không phải "chi tiêu trong kỳ sao kê". Module chỉ cộng được chi
 * tiêu trong kỳ hiện tại (SUM giao dịch theo kỳ), nên phần chi tiêu của các kỳ
 * trước/NĂM trước do user tự khai làm mốc khởi điểm. Từ mốc đó, hệ thống cộng dồn
 * chi tiêu kỳ hiện tại để hiển thị tiến độ — KHÔNG tự khẳng định "đã đủ điều kiện
 * miễn phí", vì còn phụ thuộc chính sách ngân hàng (xem
 * `CreditCardCumulativeSpendService`).
 *
 * Ba cột, tất cả NULLABLE (thẻ cũ và người dùng không khai đều hợp lệ):
 *
 *   - `cumulative_spend_baseline`      : mốc lũy kế kỳ trước (VNĐ).
 *   - `cumulative_spend_baseline_date` : ngày chốt của mốc (tuỳ chọn, chỉ để đối
 *     chiếu; kỳ HIỆN TẠI vẫn suy từ anchor thẻ, không lấy từ cột này).
 *   - `cumulative_spend_note`          : ghi chú của user cho mốc này.
 *
 * Kiểu dữ liệu: `decimal(16, 2)` khớp `annual_fee`/`credit_limit`; ngày dùng
 * `date`. KHÔNG tự điền 0: NULL = chưa khai.
 *
 * Migration thuần BỔ SUNG: không xoá/tái tạo bảng hay dữ liệu cũ, không ảnh hưởng
 * engine cashback/blueprint, không thay đổi các cột phí thường niên đã có.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->decimal('cumulative_spend_baseline', 16, 2)
                ->nullable()
                ->after('next_annual_fee_date')
                ->comment('Mốc chi tiêu lũy kế kỳ trước (VNĐ) — NULL = chưa khai');
            $table->date('cumulative_spend_baseline_date')
                ->nullable()
                ->after('cumulative_spend_baseline')
                ->comment('Ngày chốt của mốc lũy kế (tuỳ chọn) — chỉ để đối chiếu');
            $table->text('cumulative_spend_note')
                ->nullable()
                ->after('cumulative_spend_baseline_date')
                ->comment('Ghi chú của user cho mốc lũy kế');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->dropColumn([
                'cumulative_spend_baseline',
                'cumulative_spend_baseline_date',
                'cumulative_spend_note',
            ]);
        });
    }
};
