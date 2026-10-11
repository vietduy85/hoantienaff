<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Hồ sơ MCC chính sách" — mô hình đơn giản hoá cho module Quản lý MCC.
 *
 * Mỗi HỒ SƠ thuộc về MỘT chính sách hoàn tiền (template) và chứa:
 *   1. `credit_card_policy_mcc_profiles`      — hồ sơ (tên riêng + ghi chú chung).
 *   2. `credit_card_policy_mcc_rows`          — các DÒNG MCC, mỗi dòng thuộc một
 *      loại (`included` = được hoàn tiền, `excluded` = loại trừ). Tên danh mục là
 *      text tự do, KHÔNG liên kết `credit_card_categories`.
 *   3. `credit_card_policy_mcc_row_codes`     — từng mã MCC của dòng (một dòng có
 *      thể chứa nhiều MCC). Mỗi mã một bản ghi để truy vấn ngược MCC → hồ sơ.
 *   4. `credit_card_policy_mcc_documents`     — tài liệu ngân hàng đính kèm
 *      (PDF/XLSX/XLS) lưu trên disk private, tải qua route có kiểm tra quyền.
 *
 * KHÔNG đụng `credit_card_policy_mcc_mappings` (dữ liệu import append-only) và
 * KHÔNG đụng engine cashback. Tỷ lệ hoàn tiền KHÔNG lưu ở đây — luôn đọc từ
 * blueprint/quy tắc của chính sách hiện có.
 *
 * Ba bảng cũ (`..._groups`, `..._group_codes`, `..._common_exclusions`) vẫn được
 * GIỮ NGUYÊN cho tới khi dữ liệu được chuyển và xác minh (xem migration 000005).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_policy_mcc_profiles', function (Blueprint $table) {
            $table->id();

            // Tên FK đặt NGẮN thủ công: tên tự sinh vượt giới hạn 64 ký tự của MySQL.
            $table->unsignedBigInteger('policy_template_id');
            $table->foreign('policy_template_id', 'cc_pmcc_profiles_template_fk')
                ->references('id')->on('credit_card_policy_templates')->cascadeOnDelete();

            // "Tên MCC chính sách" — tên RIÊNG của hồ sơ, không đổi tên template gốc.
            $table->string('name', 191);

            // Ghi chú chung của cả hồ sơ (khác ghi chú từng dòng).
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['policy_template_id', 'name'], 'cc_pmcc_profiles_template_name_index');
        });

        Schema::connection($this->connection)->create('credit_card_policy_mcc_rows', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('policy_mcc_profile_id');
            $table->foreign('policy_mcc_profile_id', 'cc_pmcc_rows_profile_fk')
                ->references('id')->on('credit_card_policy_mcc_profiles')->cascadeOnDelete();

            // included = MCC được hoàn tiền; excluded = MCC loại trừ.
            $table->enum('row_type', ['included', 'excluded'])->default('included');

            $table->string('category_name', 191)->nullable();
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->unsignedInteger('display_order')->default(0);

            $table->timestamps();

            $table->index(['policy_mcc_profile_id', 'row_type', 'display_order'], 'cc_pmcc_rows_profile_type_order_index');
        });

        Schema::connection($this->connection)->create('credit_card_policy_mcc_row_codes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('policy_mcc_row_id');
            $table->foreign('policy_mcc_row_id', 'cc_pmcc_row_codes_row_fk')
                ->references('id')->on('credit_card_policy_mcc_rows')->cascadeOnDelete();

            // Khóa ngoại tới MCC master (natural key là `mcc`). Null nếu master bị xoá.
            $table->unsignedBigInteger('mcc_code_id')->nullable();
            $table->foreign('mcc_code_id', 'cc_pmcc_row_codes_mcc_fk')
                ->references('id')->on('credit_card_mcc_codes')->nullOnDelete();

            $table->char('mcc', 4);

            $table->timestamps();

            $table->unique(['policy_mcc_row_id', 'mcc'], 'cc_pmcc_row_codes_row_mcc_unique');
            $table->index('mcc', 'cc_pmcc_row_codes_mcc_index');
        });

        Schema::connection($this->connection)->create('credit_card_policy_mcc_documents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('policy_mcc_profile_id');
            $table->foreign('policy_mcc_profile_id', 'cc_pmcc_docs_profile_fk')
                ->references('id')->on('credit_card_policy_mcc_profiles')->cascadeOnDelete();

            // Tài liệu ngân hàng gốc (PDF/XLSX/XLS) lưu trên disk riêng tư.
            $table->string('disk', 50)->default('local');
            $table->string('path', 191);
            $table->string('original_name', 191);
            $table->string('mime', 191)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // User admin đã tải lên (logical reference tới `hoantienaff.users.id`).
            $table->unsignedBigInteger('uploaded_by')->nullable();

            $table->timestamps();

            $table->index('policy_mcc_profile_id', 'cc_pmcc_documents_profile_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_mcc_documents');
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_mcc_row_codes');
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_mcc_rows');
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_mcc_profiles');
    }
};
