<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 5/10 — User Card (thẻ thật của user).
 *
 * `user_id` là LOGICAL REFERENCE tới `hoantienaff.users.id`
 * (bigint unsigned) — TUYỆT ĐỐI KHÔNG tạo physical FK cross-database.
 * Bù lại, index `(user_id, ...)` phục vụ mọi truy vấn ownership.
 *
 * `current_policy_id` được thêm ở migration 6 (sau khi `credit_card_policies`
 * tồn tại) vì bảng này không tham chiếu ngược lại chính nó.
 *
 * BẢO MẬT: chỉ lưu 4 số cuối. KHÔNG có `card_number` / `cvv` / `exp_date`.
 *
 * `spending_deadline_day` là metadata CHỈ ĐỂ NHẮC NHỞ user.
 * Nó KHÔNG bao giờ được dùng để quyết định transaction thuộc kỳ sao kê nào
 * (xem StatementPeriodService — chỉ dùng `statement_day`).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_user_cards', function (Blueprint $table) {
            $table->id();

            // === Identity của chủ thẻ (logical cross-DB reference, KHÔNG FK) ===
            $table->unsignedBigInteger('user_id')
                ->comment('users.id ở hoantienaff.users — logical reference, cố ý KHÔNG có FK cross-database');

            $table->foreignId('product_id')->constrained('credit_card_products')->restrictOnDelete();

            // === Identity hiển thị ===
            $table->string('name', 191)->comment('Tên thẻ do user tự đặt, ví dụ "Thẻ MB chính"');
            $table->char('card_number_last4', 4)->nullable()
                ->comment('Chỉ lưu 4 số cuối để nhận diện thẻ. TUYỆT ĐỐI không lưu số thẻ đầy đủ / CVV / ngày hết hạn');

            $table->decimal('credit_limit', 16, 2)->nullable()->comment('Hạn mức tín dụng (VNĐ)');

            // === Cấu hình kỳ sao kê ===
            $table->unsignedTinyInteger('statement_day')->default(1)->comment('Ngày chốt sao kê (1-31, tự clamp về ngày cuối tháng)');
            $table->unsignedTinyInteger('payment_due_day')->default(25)->comment('Ngày đến hạn thanh toán (1-31)');
            $table->unsignedTinyInteger('spending_deadline_day')->nullable()
                ->comment('CHỈ để nhắc nhở user. KHÔNG dùng để gán statement period.');
            $table->enum('statement_date_basis', ['transaction_date', 'posted_date'])->default('transaction_date')
                ->comment('Cơ sở xác định kỳ sao kê; posted_date được ưu tiên nếu có giá trị');

            // === Trạng thái ===
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->index('user_id', 'credit_card_user_cards_user_id_index');
            $table->index(['user_id', 'status'], 'credit_card_user_cards_user_status_index');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_user_cards');
    }
};
