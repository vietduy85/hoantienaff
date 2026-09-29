<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 6/10 — Card Policy + Policy Version (cùng một bảng).
 *
 * Vì sao Policy và PolicyVersion dùng CHUNG bảng:
 *   - Spec Phase 1A chốt đúng 10 bảng, không có `credit_card_policy_versions`.
 *   - "Version" chỉ là một bản ghi policy có `version_no` + `effective_from/to` +
 *     `status`, nên tách bảng chỉ tạo thêm một tầng join mà không thêm integrity.
 *   - Model `Policy` và `PolicyVersion` cùng trỏ bảng này (PolicyVersion extends Policy).
 *
 * Ba kiểu bản ghi:
 *   1. Template blueprint : user_card_id = NULL, template_id = X, version_no = 1
 *   2. Policy root của thẻ: user_card_id = Y, template_id = X, version_no = 1, root_policy_id = chính nó
 *   3. Policy version      : user_card_id = Y, version_no = 2..n, root_policy_id = trỏ về root
 *
 * APPEND-ONLY (§9.2): khi tạo version mới, KHÔNG UPDATE business rules của version
 * cũ. Chỉ đóng `effective_to` + chuyển `status` sang `superseded` — đó là metadata
 * vòng đời, không phải business rule. Nhờ vậy giao dịch lịch sử vẫn resolve đúng
 * version cũ sau khi admin tạo version mới.
 *
 * `root_policy_id` trỏ về chính nó ở bản ghi root (set sau khi insert) nhờ đó
 * UNIQUE (root_policy_id, version_no) thật sự chống trùng version trong 1 chain.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_policies', function (Blueprint $table) {
            $table->id();

            // === Chủ sở hữu ===
            // NULL = policy blueprint rời (chỉ dùng làm nguồn clone cho template).
            $table->foreignId('user_card_id')->nullable()->constrained('credit_card_user_cards')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('credit_card_policy_templates')->nullOnDelete();

            // === Chuỗi version ===
            // Bản ghi root tự trỏ về chính nó (gán sau khi insert).
            $table->foreignId('root_policy_id')->nullable()->constrained('credit_card_policies')->nullOnDelete();
            $table->unsignedInteger('version_no')->default(1);
            $table->enum('status', ['draft', 'active', 'superseded'])->default('draft');

            $table->string('name', 191)->comment('Tên user nhìn thấy, ví dụ "MB JCB Ultimate Cashback"');

            // === Khoảng hiệu lực ===
            $table->date('effective_from');
            $table->date('effective_to')->nullable()->comment('NULL = đang hiệu lực / mở');

            // === Business rules thuộc CARD POLICY / POLICY VERSION ===
            // Minimum spend KHÔNG thuộc từng spending category.
            $table->decimal('min_total_spend', 16, 2)->default(0)
                ->comment('Tổng chi tiêu eligible tối thiểu trong kỳ sao kê; chưa đạt => cashback = 0');
            // Cap 3/3 — trần cashback tổng mỗi kỳ (nằm ở policy, không nằm ở category).
            $table->decimal('max_cashback_total_per_period', 16, 2)->nullable()
                ->comment('Trần cashback tổng mỗi kỳ sao kê (VNĐ)');
            $table->enum('rounding_mode', ['round', 'floor', 'ceil'])->default('round');

            $table->text('note')->nullable();
            $table->boolean('is_locked')->default(false)
                ->comment('Kỳ đã finalize => khóa, không tính lại tự động');

            $table->timestamps();

            $table->unique(['root_policy_id', 'version_no'], 'credit_card_policies_root_version_unique');
            $table->index(['user_card_id', 'status'], 'credit_card_policies_user_card_status_index');
            $table->index(['status', 'effective_from'], 'credit_card_policies_status_effective_from_index');
            $table->index(['user_card_id', 'effective_from', 'effective_to'], 'credit_card_policies_user_card_effective_index');
            $table->index('template_id');
        });

        // Nối thẻ ↔ policy hiện tại. Thêm ở đây (sau khi credit_card_policies tồn tại)
        // để tránh vòng lặp FK giữa user_cards ↔ policies.
        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table) {
            $table->foreignId('current_policy_id')
                ->nullable()
                ->after('statement_date_basis')
                ->constrained('credit_card_policies')
                ->nullOnDelete();

            $table->index('current_policy_id', 'credit_card_user_cards_current_policy_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_user_cards', function (Blueprint $table) {
            $table->dropForeign(['current_policy_id']);
            $table->dropIndex('credit_card_user_cards_current_policy_index');
            $table->dropColumn('current_policy_id');
        });

        Schema::connection($this->connection)->dropIfExists('credit_card_policies');
    }
};
