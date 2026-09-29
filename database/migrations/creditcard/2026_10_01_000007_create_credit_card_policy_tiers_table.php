<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7/10 — Policy Tier (bậc chi tiêu của một Policy Version).
 *
 * Bậc được chọn theo TỔNG eligible spend của CẢ kỳ sao kê (RETROACTIVE — §10).
 * Nếu tổng cuối kỳ rơi vào Tier 3 thì MỌI giao dịch eligible trong kỳ đều tính
 * theo Tier 3. KHÔNG có chế độ progressive.
 *
 * Khoảng `[min_total_spend, max_total_spend)` — nửa mở để không trùng ranh giới.
 * `max_total_spend = NULL` ⇒ bậc cuối mở (open-ended).
 *
 * Ràng buộc (validate ở PolicyCloneService, không nhúng business logic vào migration):
 *   - min >= 0
 *   - max NULL hoặc max > min
 *   - các bậc liên tiếp không chồng lấn, không có khoảng hơ hẳn
 *   - ít nhất 1 bậc, bậc đầu tiên min = 0
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_policy_tiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_id')->constrained('credit_card_policies')->cascadeOnDelete();

            $table->string('name', 150);
            $table->unsignedInteger('sort_order')->default(0);

            $table->decimal('min_total_spend', 16, 2)->default(0);
            $table->decimal('max_total_spend', 16, 2)->nullable()
                ->comment('NULL = không trần (bậc cuối)');

            $table->timestamps();

            $table->unique(['policy_id', 'sort_order'], 'credit_card_policy_tiers_policy_order_unique');
            $table->index(['policy_id', 'min_total_spend'], 'credit_card_policy_tiers_policy_min_spend_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_tiers');
    }
};
