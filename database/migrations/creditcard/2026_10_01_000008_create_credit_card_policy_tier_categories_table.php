<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 8/10 — Tier Category Rule (quy tắc cashback của 1 danh mục trong 1 bậc).
 *
 * Đây là nơi DUY NHẤT chứa cashback %. Danh mục (`credit_card_categories`)
 * không chứa cashback.
 *
 * Một category có thể có nhiều mức theo spend (§12):
 *   Online [0, 5tr)        -> 5%   cap 100k/giao dịch, 500k/danh mục/kỳ
 *   Online [5tr, 10tr)     -> 7%
 *   Online [10tr, NULL)    -> 10%   (mở)
 *
 * Khoảng `[spend_from, spend_to)` là chi tiêu eligible CỦA DANH MỤC ĐÓ trong kỳ
 * (cùng nguyên tắc retroactive như tier) — xem CashbackCalculator.
 *
 * 3 loại cap:
 *   1. max_cashback_per_transaction        — trần mỗi giao dịch
 *   2. max_cashback_per_category_per_period — trần mỗi danh mục mỗi kỳ
 *   3. max_cashback_total_per_period        — trần tổng mỗi kỳ (nằm ở policy)
 *
 * KHÔNG có `min_cashback` (§14: không tạo minimum cashback).
 * `min_transaction_amount` là bộ lọc giao dịch, KHÔNG phải minimum spend của kỳ.
 *
 * UNIQUE (tier_id, category_id, spend_from) chặn trùng rule cùng mốc bắt đầu.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_policy_tier_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tier_id')->constrained('credit_card_policy_tiers')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('credit_card_categories')->restrictOnDelete();

            $table->string('name', 150)->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            // Khoảng chi tiêu của danh mục trong kỳ: [spend_from, spend_to)
            $table->decimal('spend_from', 16, 2)->default(0);
            $table->decimal('spend_to', 16, 2)->nullable()->comment('NULL = không trần');

            $table->decimal('cashback_percent', 6, 3)->comment('% hoàn tiền, ví dụ 10.000 = 10%');

            // Cap 1 — trần mỗi giao dịch
            $table->decimal('max_cashback_per_transaction', 16, 2)->nullable();
            // Cap 2 — trần mỗi danh mục mỗi kỳ
            $table->decimal('max_cashback_per_category_per_period', 16, 2)->nullable();
            // Bộ lọc mức giao dịch tối thiểu để áp rule (KHÔNG phải minimum spend của kỳ)
            $table->decimal('min_transaction_amount', 16, 2)->nullable();

            $table->boolean('is_enabled')->default(true);
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['tier_id', 'category_id', 'spend_from'], 'credit_card_tier_categories_unique');
            $table->index('tier_id', 'credit_card_tier_categories_tier_index');
            $table->index('category_id', 'credit_card_tier_categories_category_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_tier_categories');
    }
};
