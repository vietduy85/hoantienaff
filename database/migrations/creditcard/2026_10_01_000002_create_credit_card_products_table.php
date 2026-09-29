<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2/10 — Card Product (sản phẩm thẻ do bank phát hành).
 *
 * KHÔNG có `category_id`: danh mục loại thẻ của bảng legacy `credit_cards`
 * KHÔNG phải Spending Category. Trộn hai khái niệm này là lỗi domain đã được
 * xác định ở audit Phase 1A. Sản phẩm thẻ không gắn danh mục chi tiêu.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bank_id')->constrained('credit_card_banks')->cascadeOnDelete();

            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->string('image')->nullable();
            $table->decimal('annual_fee', 14, 2)->nullable()->comment('Phí thường niên (VNĐ)');
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_products');
    }
};
