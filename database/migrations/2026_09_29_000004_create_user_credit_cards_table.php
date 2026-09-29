<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_credit_cards', function (Blueprint $table) {
            $table->id();

            // === References ===
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained('credit_cards')->cascadeOnDelete();

            // === Card Identity ===
            // TUYỆT ĐỐI không lưu số thẻ đầy đủ (CVV / số thẻ / ngày hết hạn).
            // Chỉ lưu 4 số cuối để user tự nhận diện thẻ của mình.
            $table->char('card_number_last4', 4)->nullable();

            // === Limit & Billing Cycle ===
            $table->decimal('credit_limit', 16, 2)->nullable()->comment('Hạn mức tín dụng (VNĐ)');
            $table->unsignedTinyInteger('statement_day')->nullable()->comment('Ngày chốt sao kê (1-31)');
            $table->unsignedTinyInteger('payment_due_day')->nullable()->comment('Ngày đến hạn thanh toán (1-31)');

            // === Status ===
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index('credit_card_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_credit_cards');
    }
};
