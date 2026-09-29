<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 9/10 — Statement Period (kỳ sao kê).
 *
 * Ranh giới kỳ (statement_day = S):
 *   kỳ kết thúc tại tháng M  →  [clamp(S, tháng trước) + 1 ngày, clamp(S, tháng M)]
 *   nên period_start(M) luôn = period_end(M-1) + 1 ngày ⇒ không trùng, không hở,
 *   kể cả khi S = 29/30/31 và tháng không đủ ngày.
 *
 * `spending_deadline_day` của thẻ KHÔNG tham gia vào việc tạo kỳ này —
 * nó chỉ là metadata nhắc nhở user (§14).
 *
 * Vòng đời: open → finalized.
 *   - `open`      : cashback chưa chốc, có thể recalculate (tier retroactive).
 *   - `finalized` : chốt số liệu vào cột snapshot bên dưới, `policy->is_locked = true`.
 *
 * Các cột `total_*` / `effective_cashback_rate` / `calculation_meta` là SNAPSHOT
 * của kỳ đã finalize, phục vụ hiển thị nhanh mà không cần tính lại.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_statement_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_card_id')->constrained('credit_card_user_cards')->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');
            $table->date('statement_date')->comment('Ngày chốt kỳ = period_end');
            $table->date('payment_due_date')->nullable();

            $table->enum('status', ['open', 'finalized'])->default('open');
            $table->timestamp('finalized_at')->nullable();

            // === Snapshot kỳ (ghi khi finalize) ===
            $table->decimal('total_eligible_spend', 18, 2)->nullable();
            $table->decimal('total_cashback', 18, 2)->nullable();
            $table->decimal('effective_cashback_rate', 6, 3)->nullable()
                ->comment('Tỉ lệ hoàn hiệu dụng của kỳ = total_cashback / total_eligible_spend * 100');
            $table->foreignId('policy_id')->nullable()->constrained('credit_card_policies')->nullOnDelete()
                ->comment('Policy Version đã resolve cho kỳ này');
            $table->json('calculation_meta')->nullable()
                ->comment('Audit kết quả tính: tổng eligible, tổng cashback, số giao dịch, lý do loại');

            $table->timestamps();

            $table->unique(['user_card_id', 'period_start', 'period_end'], 'credit_card_periods_card_range_unique');
            $table->index(['user_card_id', 'status'], 'credit_card_periods_card_status_index');
            $table->index(['user_card_id', 'period_end'], 'credit_card_periods_card_end_index');
            $table->index('policy_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_statement_periods');
    }
};
