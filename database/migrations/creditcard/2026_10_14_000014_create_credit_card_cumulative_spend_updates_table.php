<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhật ký CẬP NHẬT MỐC CHI TIÊU LŨY KẾ của thẻ (`credit_card_cumulative_spend_updates`).
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CÓ BẢNG NÀY
 * ---------------------------------------------------------------------------
 * Mốc lũy kế ảnh hưởng tới con số "tiến độ miễn phí thường niên" user nhìn thấy,
 * và user có thể sửa nhiều lần. Giá trị hiện tại nằm trên `credit_card_user_cards`
 * (đọc nhanh), còn mỗi lần sửa ghi thêm MỘT dòng ở đây để trả lời được "ai đổi,
 * từ bao nhiêu sang bao nhiêu, khi nào". Giống tinh thần snapshot của module: con
 * số hiển thị không bao giờ mất dấu vết.

 * ---------------------------------------------------------------------------
 * KHÓA NGOẠI
 * ---------------------------------------------------------------------------
 *   - `user_card_id` → FK THẬT tới `credit_card_user_cards` (cùng database),
 *     `cascadeOnDelete`: xoá thẻ thì nhật ký của thẻ đó cũng đi theo.
 *   - `user_id` → CHỈ là logical reference tới `hoantienaff.users.id` (KHÁC
 *     database, không thể có FK vật lý). Index để tra theo user.
 *
 * Migration thuần BỔ SUNG: bảng mới, không đụng bảng/dữ liệu cũ.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_cumulative_spend_updates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_card_id')
                ->constrained('credit_card_user_cards')
                ->cascadeOnDelete();

            // Logical reference tới users (DB chính) — không có FK vật lý.
            $table->unsignedBigInteger('user_id')->index();

            $table->decimal('old_baseline', 16, 2)->nullable()
                ->comment('Mốc lũy kế trước khi sửa — NULL = trước đó chưa khai');
            $table->decimal('new_baseline', 16, 2)->nullable()
                ->comment('Mốc lũy kế sau khi sửa');
            $table->date('baseline_date')->nullable()
                ->comment('Ngày chốt của mốc tại thời điểm sửa');
            $table->text('note')->nullable()
                ->comment('Ghi chú của user tại thời điểm sửa');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_cumulative_spend_updates');
    }
};
