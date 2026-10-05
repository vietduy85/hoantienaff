<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sao kê THỰC TẾ (`credit_card_statements`).
 *
 * ---------------------------------------------------------------------------
 * KHÁC `credit_card_statement_periods`
 * ---------------------------------------------------------------------------
 * Bảng kỳ là DỮ LIỆU SUY RA: server tính từ anchor của thẻ, không ai nhập tay.
 * Bảng này là CON SỐ THẬT user đọc trên bảng sao kê của ngân hàng — không suy ra
 * được từ giao dịch nhập tay (giao dịch có thể thiếu, có thể sai), nên phải nhập.
 *
 * ---------------------------------------------------------------------------
 * MỘT CẶP (THẺ, KỲ) CHỈ CÓ MỘT DÒNG
 * ---------------------------------------------------------------------------
 * `UNIQUE (user_card_id, statement_period_id)`. Người dùng sửa một kỳ thì ghi đè
 * dòng cũ chứ không sinh dòng thứ hai — nếu không, "số dư còn phải trả" sẽ là tổng
 * của hai dòng và sai tiền.
 *
 * ---------------------------------------------------------------------------
 * `closing_balance` LUÔN TÍNH Ở SERVER
 * ---------------------------------------------------------------------------
 * = `actual_spend - actual_reward`. Không nhận từ client (xem
 * `CreditCardStatement`), và cũng KHÔNG có default: để NULL bắt buộc ghi đủ ba cột
 * trong một lần, thay vì lưu dòng nửa vời.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_statements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_card_id')->constrained('credit_card_user_cards')->cascadeOnDelete();
            $table->foreignId('statement_period_id')->constrained('credit_card_statement_periods')->cascadeOnDelete();

            // Cùng độ chính xác với `credit_card_transactions.amount` (18,2).
            $table->decimal('actual_spend', 18, 2)->comment('Số tiền thực tế trong kỳ, đọc từ bảng kê ngân hàng');
            $table->decimal('actual_reward', 18, 2)->comment('Hoàn tiền/thưởng thực tế trong kỳ');
            $table->decimal('closing_balance', 18, 2)
                ->comment('Luôn = actual_spend - actual_reward, do server tính');

            $table->timestamps();

            $table->unique(
                ['user_card_id', 'statement_period_id'],
                'credit_card_statements_card_period_unique',
            );
            $table->index('statement_period_id', 'credit_card_statements_period_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_statements');
    }
};
