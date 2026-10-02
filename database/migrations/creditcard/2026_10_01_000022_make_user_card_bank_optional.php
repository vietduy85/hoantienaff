<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 22 - Cho phép thẻ tín dụng TẠM CHƯA CÓ ngân hàng phát hành.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO ĐẢO NGƯỢC QUYẾT ĐỊNH CỦA MIGRATION 11
 * ---------------------------------------------------------------------------
 * Migration 11 cố ý siết `bank_id` từ `nullable()` → `NOT NULL` + FK `RESTRICT`
 * (bước 3), vì Phase 1B coi "thẻ = bank + tên" và mọi thẻ đều phải có ngân hàng.
 *
 * Màn "Quản lý thẻ" mobile-first yêu cầu người dùng nhập thẻ NGAY khi vừa nhận
 * thẻ vật lý, lúc đó họ thường chưa nhớ/thèm tra mã ngân hàng. Bắt buộc chọn ở
 * đây khiến form không submit được và người dùng bỏ dở. `NULL` = "chưa biết",
 * hoàn toàn khác "không có ngân hàng" nên không mất thông tin nào.
 *
 * ---------------------------------------------------------------------------
 * FK VẪN GIỮ NGUYÊN
 * ---------------------------------------------------------------------------
 * `MODIFY COLUMN` không làm rơi ràng buộc khóa ngoại đang gắn vào cột, nên:
 *   - `bank_id = 5`      → vẫn phải là bank có thật, không xoá được bank đang dùng.
 *   - `bank_id = NULL`   → hợp lệ, không tham chiếu gì.
 * Ràng buộc toàn vẹn vẫn giữ nguyên; chỉ có "bắt buộc có mặt" bị gỡ.
 *
 * ---------------------------------------------------------------------------
 * AI PHẢI SỐNG CHUNG VỚI `bank_id = NULL`
 * ---------------------------------------------------------------------------
 * Quan hệ `UserCard::bank()` là `belongsTo` nên trả `null` an toàn, và hiện KHÔNG
 * có chỗ nào trong `app/` dereference `->bank` một cách cứng. Từ migration này,
 * code mới phải coi `bank` là có thể null (hiển thị "Chưa chọn ngân hàng",
 * lọc/báo cáo theo ngân hàng phải bỏ qua thẻ chưa có).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        // Thẻ đang tồn tại mà `bank_id` NULL (không thể xảy ra trước migration này
        // vì cột NOT NULL) — nhưng để `change()` không chết vì dữ liệu bẩn thì dọn trước.
        DB::connection($this->connection)->table('credit_card_user_cards')
            ->whereNull('bank_id')
            ->update(['bank_id' => DB::connection($this->connection)->table('credit_card_banks')->value('id')]);

        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table): void {
            // KHÔNG đụng `foreign()`/`index()`: `change()` chỉ MODIFY COLUMN nên
            // FK `credit_card_user_cards_bank_id_foreign` và index đi kèm vẫn còn.
            $table->unsignedBigInteger('bank_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        // Dựng lại NOT NULL trước, nếu còn thẻ chưa chọn ngân hàng thì rollback
        // không thể thành công — báo lỗi rõ ràng thay vì để MySQL báo mã lỗi vô nghĩa.
        $orphans = DB::connection($this->connection)->table('credit_card_user_cards')
            ->whereNull('bank_id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Không rollback được: còn {$orphans} thẻ chưa chọn ngân hàng. "
                .'Hãy gán ngân hàng cho các thẻ đó rồi chạy lại.'
            );
        }

        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->unsignedBigInteger('bank_id')
                ->nullable(false)
                ->change();
        });
    }
};
