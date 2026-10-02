<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 21 - Bổ sung hồ sơ thẻ cho màn "Quản lý thẻ" (UX mobile-first).
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO THÊM, KHÔNG LỘI XÓA
 * ---------------------------------------------------------------------------
 * Bảng `credit_card_user_cards` đã có sẵn phần lớn thứ form cần:
 *   - `name`                      → Tên thẻ
 *   - `bank_id`                   → Ngân hàng phát hành
 *   - `payment_due_day`           → Ngày thanh toán sao kê
 *   - `spending_deadline_day`     → Hạn chót giao dịch
 *   - `note`                      → Ghi chú
 *   - `current_policy_id`         → Chính sách hoàn tiền
 * Ba cột dưới đây là phần còn thiếu cho đúng nhu cầu màn hình.
 *
 * ---------------------------------------------------------------------------
 * KỲ SAO KẺ = MỘT CẶP NGÀY, KHÔNG PHẢI "NGÀY CHỐT"
 * ---------------------------------------------------------------------------
 * `statement_day` (1–31) là ngày CHỐT để `StatementPeriodService` tự sinh kỳ —
 * cơ chế đó giữ nguyên, migration này không đụng tới. Hai cột ở đây là kỳ sao kê
 * mà user TỰ KHAI để đối chiếu với sao kê thật của ngân hàng:
 *
 *   - `statement_period_start` — ngày bắt đầu (user chọn).
 *   - `statement_period_end`   — ngày kết thúc, LUÔN do server tính từ start
 *                               (`UserCardService::statementPeriodEnd()`: start
 *                               + 1 tháng − 1 ngày) chứ KHÔNG nhận từ client.
 *
 * Vì sao lưu cả hai thay vì chỉ lưu start:
 *   - `end` là dữ liệu hiển thị/đối chiếu, lưu xuống để truy vấn được mà không
 *     phải suy lại mỗi lần đọc.
 *   - Nếu sau này đổi công thức tính, kỳ đã lưu vẫn giữ nguyên giá trị đúng
 *     tại thời điểm nó được khai.
 *
 * ---------------------------------------------------------------------------
 * `desired_spend` KHÁC `credit_limit`
 * ---------------------------------------------------------------------------
 * `credit_limit` là HẠN MỨC do ngân hàng cấp. `desired_spend` là SỐ TIỀN user
 * MONG MUỐN chi — mục tiêu cá nhân, không có ý nghĩa pháp lý, không dùng cho bất
 * kỳ phép tính cashback nào. Tách riêng để không bao giờ nhầm hai khái niệm.
 *
 * `promotion_info` là text tự do (khuyến mãi thẻ, ưu đãi riêng của user) — không
 * có bảng tra cứu vì dữ liệu này không cần truy vấn.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->date('statement_period_start')
                ->nullable()
                ->after('opened_at')
                ->comment('Ngày bắt đầu kỳ sao kê do user khai để đối chiếu sao kê thật');
            $table->date('statement_period_end')
                ->nullable()
                ->after('statement_period_start')
                ->comment('Ngày kết thúc kỳ sao kê — LUÔN server tính từ start, không nhận từ client');
            $table->decimal('desired_spend', 16, 2)
                ->nullable()
                ->after('credit_limit')
                ->comment('Số tiền user MONG MUỐN chi (mục tiêu cá nhân, KHÁC hạn mức credit_limit)');
            $table->text('promotion_info')
                ->nullable()
                ->after('note')
                ->comment('Thông tin khuyến mãi của thẻ (text tự do)');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->dropColumn([
                'statement_period_start',
                'statement_period_end',
                'desired_spend',
                'promotion_info',
            ]);
        });
    }
};
