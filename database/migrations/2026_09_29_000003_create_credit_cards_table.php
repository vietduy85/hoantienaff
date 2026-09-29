<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();

            // === References ===
            // nullOnDelete: xoá ngân hàng / danh mục không được làm mất lịch sử thẻ.
            $table->foreignId('bank_id')->nullable()->constrained('credit_card_banks')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('credit_card_categories')->nullOnDelete();

            // === Card Identity ===
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->string('image')->nullable()->comment('Đường dẫn ảnh thẻ');
            $table->decimal('annual_fee', 14, 2)->nullable()->comment('Phí thường niên (VNĐ)');
            $table->text('description')->nullable();

            // === Status ===
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('is_active');
            $table->index('bank_id');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
