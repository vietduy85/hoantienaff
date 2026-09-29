<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 10/10 — Transaction (giao dịch user nhập tay / import).
 *
 * KHÔNG có `calculation_date` (§15, §17) — ngày dùng để tính chính là
 * `calc_basis`, và nó được derive từ `statement_date_basis` của thẻ, không phải
 * ngày admin/user chỉnh để "ép" kết quả.
 *
 * KHÔNG có ô nhập cashback: cashback LUÔN được hệ thống tính (§23).
 *
 * Ba nhóm cột theo §16:
 *   A. SOURCE OF TRUTH : user_card_id, transaction_date, posted_date,
 *                        statement_period_id, category_id, merchant, amount, note
 *   B. RESOLVED POLICY : policy_version_id, policy_tier_id, policy_tier_category_id
 *   C. CALCULATED      : cashback_percent_snapshot, cashback_amount_snapshot,
 *                        is_eligible, ineligible_reason, calc_meta, calculated_at
 *
 * Vì tier là RETROACTIVE, cashback KHÔNG bất biến ngay lúc nhập: khi tổng chi
 * tiêu kỳ thay đổi thì snapshot được cập nhật lại (chỉ khi kỳ còn `open`).
 * Nhưng BỘ business rule của policy version là bất biến (§25).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_transactions', function (Blueprint $table) {
            $table->id();

            // === A. SOURCE OF TRUTH ===
            $table->foreignId('user_card_id')->constrained('credit_card_user_cards')->cascadeOnDelete();
            $table->foreignId('statement_period_id')->nullable()
                ->constrained('credit_card_statement_periods')->nullOnDelete()
                ->comment('Kỳ sao kê suy luận từ statement_day; user có thể chỉnh lại khi kỳ chưa finalize');
            $table->foreignId('category_id')->nullable()
                ->constrained('credit_card_categories')->restrictOnDelete()
                ->comment('RESTRICT: danh mục đã dùng cho giao dịch thì KHÔNG xoá cứng, chỉ is_active=false');
            $table->string('merchant', 191)->nullable();
            $table->decimal('amount', 18, 2);
            $table->text('note')->nullable();

            $table->date('transaction_date')->comment('Ngày user thực hiện giao dịch');
            $table->date('posted_date')->nullable()->comment('Ngày ngân hàng ghi nhận (có thể chưa biết)');

            $table->enum('source', ['manual', 'excel'])->default('manual')
                ->comment('Nguồn giao dịch. Mọi nguồn đều đi qua cùng pipeline tính cashback.');
            $table->string('source_reference', 191)->nullable()
                ->comment('Tham chiếu nguồn, ví dụ "2026-10-abc.xlsx#12" cho import Excel');

            // === B. RESOLVED POLICY (snapshot trỏ tới version/bậc/rule đã dùng) ===
            $table->foreignId('policy_version_id')->nullable()
                ->constrained('credit_card_policies')->nullOnDelete();
            $table->foreignId('policy_tier_id')->nullable()
                ->constrained('credit_card_policy_tiers')->nullOnDelete();
            $table->foreignId('policy_tier_category_id')->nullable()
                ->constrained('credit_card_policy_tier_categories')->nullOnDelete();

            // === C. CALCULATED RESULT ===
            $table->decimal('cashback_percent_snapshot', 6, 3)->nullable();
            $table->decimal('cashback_amount_snapshot', 18, 2)->nullable();
            $table->boolean('is_eligible')->nullable();
            $table->string('ineligible_reason', 191)->nullable()
                ->comment('VD: below_minimum_spend, no_category_rule, below_min_transaction_amount');
            $table->date('calc_basis')->nullable()
                ->comment('Ngày thực tế dùng để xác định kỳ (theo statement_date_basis) — KHÔNG phải calculation_date');
            $table->json('calc_meta')->nullable()
                ->comment('Audit: cap nào chạm, quota trước/sau, lý do loại');
            $table->timestamp('calculated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('user_card_id', 'credit_card_transactions_user_card_index');
            $table->index(['user_card_id', 'transaction_date'], 'credit_card_transactions_card_date_index');
            $table->index('statement_period_id', 'credit_card_transactions_period_index');
            $table->index(['statement_period_id', 'category_id'], 'credit_card_transactions_period_category_index');
            $table->index('category_id', 'credit_card_transactions_category_index');
            $table->index('policy_version_id', 'credit_card_transactions_policy_version_index');
            $table->index('policy_tier_id', 'credit_card_transactions_tier_index');
            $table->index('transaction_date', 'credit_card_transactions_tx_date_index');
            $table->index('posted_date', 'credit_card_transactions_posted_date_index');
            $table->index('source', 'credit_card_transactions_source_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_transactions');
    }
};
