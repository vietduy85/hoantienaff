<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Quản lý MCC theo từng chính sách hoàn tiền" — dữ liệu do ADMIN nhập tay.
 *
 * TÁCH BIỆT hoàn toàn với `credit_card_policy_mcc_mappings` (dữ liệu import từ
 * tài liệu PDF/Excel, append-only, chống trùng bằng `fingerprint`). Ba bảng dưới
 * đây phục vụ UI quản trị + tra cứu:
 *
 *   1. `credit_card_policy_mcc_groups`          — nhóm MCC của một chính sách.
 *   2. `credit_card_policy_mcc_group_codes`     — từng mã MCC của nhóm, mỗi mã MỘT
 *      dòng, gắn vai trò `applied` (được áp dụng) hoặc `excluded` (loại trừ riêng
 *      của nhóm). Quan hệ rõ ràng, truy vấn được, kiểm tra trùng bằng UNIQUE
 *      (group + mcc) ⇒ một mã không thể vừa áp dụng vừa loại trừ trong cùng nhóm.
 *   3. `credit_card_policy_mcc_common_exclusions` — MCC loại trừ CHUNG của cả
 *      chính sách, độc lập với MCC loại trừ riêng của từng nhóm.
 *
 * NGUYÊN TẮC:
 *   - Tên danh mục tham khảo (`reference_name`) là TEXT tự do, KHÔNG liên kết
 *     `credit_card_categories`, không tạo danh mục hệ thống, không đổi cashback.
 *   - `scope_mode = 'all'` biểu diễn "Tất cả MCC" — KHÔNG sinh mapping cho mọi
 *     mã trong master.
 *   - `mcc_code_id` là khóa ngoại tới MCC master; `mcc` giữ mã 4 ký tự để hiển thị
 *     và tra cứu nhanh. Master bị xoá ⇒ `mcc_code_id` = NULL, mã vẫn còn để đối chiếu.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_policy_mcc_groups', function (Blueprint $table) {
            $table->id();

            // Tên FK đặt NGẮN thủ công: tên tự sinh vượt giới hạn 64 ký tự của MySQL.
            $table->unsignedBigInteger('policy_template_id');
            $table->foreign('policy_template_id', 'cc_pmcc_groups_template_fk')
                ->references('id')->on('credit_card_policy_templates')->cascadeOnDelete();

            // Tên danh mục tham khảo do admin nhập — KHÔNG phải danh mục hệ thống.
            $table->string('reference_name', 191)->nullable();

            // 'list' = danh sách MCC cụ thể; 'all' = áp dụng mọi MCC (trừ ngoại lệ).
            $table->enum('scope_mode', ['list', 'all'])->default('list');

            $table->text('description')->nullable()->comment('Mô tả phạm vi áp dụng theo tài liệu');
            $table->text('note')->nullable()->comment('Ghi chú do admin nhập');

            $table->unsignedInteger('display_order')->default(0);

            $table->timestamps();

            $table->index(['policy_template_id', 'display_order'], 'cc_policy_mcc_groups_template_order_index');
        });

        Schema::connection($this->connection)->create('credit_card_policy_mcc_group_codes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('policy_mcc_group_id');
            $table->foreign('policy_mcc_group_id', 'cc_pmcc_codes_group_fk')
                ->references('id')->on('credit_card_policy_mcc_groups')->cascadeOnDelete();

            // Khóa ngoại tới MCC master (natural key là `mcc`). Null nếu master bị xoá.
            $table->unsignedBigInteger('mcc_code_id')->nullable();
            $table->foreign('mcc_code_id', 'cc_pmcc_codes_mcc_fk')
                ->references('id')->on('credit_card_mcc_codes')->nullOnDelete();

            $table->char('mcc', 4);
            $table->enum('role', ['applied', 'excluded'])->default('applied');

            $table->timestamps();

            $table->unique(['policy_mcc_group_id', 'mcc'], 'cc_policy_mcc_group_codes_group_mcc_unique');
            $table->index('mcc', 'cc_policy_mcc_group_codes_mcc_index');
        });

        Schema::connection($this->connection)->create('credit_card_policy_mcc_common_exclusions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('policy_template_id');
            $table->foreign('policy_template_id', 'cc_pmcc_excl_template_fk')
                ->references('id')->on('credit_card_policy_templates')->cascadeOnDelete();

            $table->unsignedBigInteger('mcc_code_id')->nullable();
            $table->foreign('mcc_code_id', 'cc_pmcc_excl_mcc_fk')
                ->references('id')->on('credit_card_mcc_codes')->nullOnDelete();

            $table->char('mcc', 4);
            $table->text('note')->nullable()->comment('Ghi chú chung cho ngoại lệ của cả chính sách');

            $table->timestamps();

            $table->unique(['policy_template_id', 'mcc'], 'cc_policy_mcc_common_exclusions_template_mcc_unique');
            $table->index('mcc', 'cc_policy_mcc_common_exclusions_mcc_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_mcc_common_exclusions');
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_mcc_group_codes');
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_mcc_groups');
    }
};
