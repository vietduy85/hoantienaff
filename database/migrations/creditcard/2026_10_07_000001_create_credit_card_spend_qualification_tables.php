<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 7/10 — "Điều kiện hoàn tiền đặc biệt" (Spend Qualification).
 *
 * Bộ 6 bảng tạo MỘT nhóm tính năng, chia làm hai khối đối xứng:
 *
 *   1. KHỐI TEMPLATE (admin quản lý, là master dữ liệu):
 *      - credit_card_spend_qualification_templates              — mẫu điều kiện
 *      - credit_card_spend_qualification_template_conditions   — điều kiện của mẫu
 *      - ..._template_condition_excluded_categories            — danh mục LOẠI TRỪ
 *        (rút ngắn thành ..._template_excluded_categories trên MySQL — tên bảng
 *        gốc 70 ký tự vượt giới hạn 64 ký tự của MySQL/MariaDB, lỗi 1103)
 *   2. KHỐI POLICY VERSION (bản clone thực thi trên thẻ/version):
 *      - credit_card_spend_qualifications                       — điều kiện của 1 policy version
 *      - credit_card_spend_qualification_conditions            — điều kiện của nó
 *      - ..._condition_excluded_categories                      — danh mục LOẠI TRỪ
 *
 * ---------------------------------------------------------------------------
 * NGỮ NGHĨA ĐIỀU KIỆN (§ chốt với user)
 * ---------------------------------------------------------------------------
 *   condition_type = 'category':  tổng CHI TIÊU THỰC TẾ của 1 danh mục trong kỳ
 *                                 >= min_spend ⇒ đạt. LUÔN kèm `category_id`.
 *   condition_type = 'other':     tổng chi tiêu thực tế CẢ kỳ TRỪ tổng chi tiêu
 *                                 của các danh mục bị loại trừ (excluded) >= min_spend.
 *                                 `category_id` bằng NULL; rỗng excluded = lấy cả kỳ.
 *
 * Mọi điều kiện gộp bằng AND; 0 điều kiện hoặc qualification bị tắt = no-op.
 * Mỗi qualification tối đa MỘT `other`, và `other` LUÔN đứng cuối (`sort_order`).
 *
 * `credit_card_spend_qualifications.policy_version_id` UNIQUE ⇒ mỗi policy version
 * chỉ có duy nhất một bộ điều kiện (append-only: clone tạo version mới kéo theo
 * khối qualification riêng, KHÔNG bao giờ sửa khối của version cũ).
 *
 * `source_template_id` CHỈ là dấu vết nguồn (trace), KHÔNG dùng làm tham chiếu
 * runtime: sau khi clone, qualification là bản sao độc lập hoàn toàn.
 *
 * FK `category_id` RESTRICT: danh mục đang dùng trong điều kiện không bị xoá cứng
 * (như các bảng rule khác của module).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        $this->createTemplateTables();

        $this->createPolicyTables();
    }

    private function createTemplateTables(): void
    {
        Schema::connection($this->connection)->create('credit_card_spend_qualification_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);
            $table->string('slug', 150)->unique('credit_card_spend_qualification_templates_slug_unique');

            $table->text('description')->nullable();
            $table->text('note')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });

        Schema::connection($this->connection)->create('credit_card_spend_qualification_template_conditions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('template_id')
                ->constrained('credit_card_spend_qualification_templates', 'id', 'sq_tpl_conditions_template_id_fk')
                ->cascadeOnDelete();

            // 'category' | 'other' — dùng string để linh hoạt và khớp phong cách
            // `scope_type` của tier category rule hiện tại.
            $table->string('condition_type', 20)->default('category');

            // Chỉ `category` mới có; `other` luôn NULL.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('credit_card_categories', 'id', 'sq_tpl_conditions_category_id_fk')
                ->restrictOnDelete();

            $table->decimal('min_spend', 16, 2)->default(0);

            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('template_id', 'credit_card_sq_template_conditions_template_index');
            $table->index('category_id', 'credit_card_sq_template_conditions_category_index');
        });

        Schema::connection($this->connection)->create('credit_card_spend_qualification_template_excluded_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condition_id')
                ->constrained('credit_card_spend_qualification_template_conditions', 'id', 'sq_tpl_excluded_condition_id_fk')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('credit_card_categories', 'id', 'sq_tpl_excluded_category_id_fk')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['condition_id', 'category_id'], 'credit_card_sq_template_excluded_unique');
            $table->index('category_id', 'credit_card_sq_template_excluded_category_index');
        });
    }

    private function createPolicyTables(): void
    {
        Schema::connection($this->connection)->create('credit_card_spend_qualifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_version_id')
                ->unique('credit_card_spend_qualifications_policy_unique')
                ->constrained('credit_card_policies', 'id', 'sq_qual_policy_version_id_fk')
                ->cascadeOnDelete();

            // DẤU VẾT nguồn khi clone từ template; null = dựng tay. KHÔNG phải tham chiếu runtime.
            $table->foreignId('source_template_id')
                ->nullable()
                ->constrained('credit_card_spend_qualification_templates', 'id', 'sq_qual_source_template_id_fk')
                ->nullOnDelete();

            $table->string('name', 150)->nullable();
            $table->text('note')->nullable();

            $table->boolean('enabled')->default(true);

            $table->timestamps();
        });

        Schema::connection($this->connection)->create('credit_card_spend_qualification_conditions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('qualification_id')
                ->constrained('credit_card_spend_qualifications', 'id', 'sq_conditions_qualification_id_fk')
                ->cascadeOnDelete();

            $table->string('condition_type', 20)->default('category');

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('credit_card_categories', 'id', 'sq_conditions_category_id_fk')
                ->restrictOnDelete();

            $table->decimal('min_spend', 16, 2)->default(0);

            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('qualification_id', 'credit_card_sq_conditions_qualification_index');
            $table->index('category_id', 'credit_card_sq_conditions_category_index');
        });

        Schema::connection($this->connection)->create('credit_card_spend_qualification_condition_excluded_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condition_id')
                ->constrained('credit_card_spend_qualification_conditions', 'id', 'sq_cond_excluded_condition_id_fk')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('credit_card_categories', 'id', 'sq_cond_excluded_category_id_fk')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['condition_id', 'category_id'], 'credit_card_sq_excluded_unique');
            $table->index('category_id', 'credit_card_sq_excluded_category_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_spend_qualification_condition_excluded_categories');
        Schema::connection($this->connection)->dropIfExists('credit_card_spend_qualification_conditions');
        Schema::connection($this->connection)->dropIfExists('credit_card_spend_qualifications');
        Schema::connection($this->connection)->dropIfExists('credit_card_spend_qualification_template_excluded_categories');
        Schema::connection($this->connection)->dropIfExists('credit_card_spend_qualification_template_conditions');
        Schema::connection($this->connection)->dropIfExists('credit_card_spend_qualification_templates');
    }
};