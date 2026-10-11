<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ánh xạ MCC ↔ chính sách hoàn tiền của thẻ.
 *
 * KHÔNG lưu lại tên/mức hoàn tiền của chính sách: cột `policy_template_id` trỏ
 * về `credit_card_policy_templates` (dữ liệu chính sách ĐÃ CÓ), `category_id`
 * trỏ về danh mục chi tiêu, `policy_tier_category_id` tham chiếu quy tắc hoàn
 * tiền cụ thể nếu mapping 1-1 với một rule.
 *
 * ---------------------------------------------------------------------------
 * BIỂU DIỄN QUY TẮC (match_type)
 * ---------------------------------------------------------------------------
 *   direct     : một MCC cụ thể ở cột `mcc` (4 ký tự).
 *   group      : một NHÓM MCC — các mã thành viên nằm ở `mcc_codes` (JSON).
 *   all        : áp dụng cho MỌI MCC (không liệt kê).
 *   all_except : áp dụng cho mọi MCC NGOẠI TRỪ các mã ngoại lệ ở `mcc_codes`.
 *
 * Nguyên tắc BẮT BUỘC: quy tắc "tất cả trừ các mã sau" được lưu ĐÚNG dạng
 * quy tắc + danh sách ngoại lệ. KHÔNG tự mở rộng thành danh sách MCC đầy đủ
 * khi chưa có căn cứ.
 *
 * `application_status`:
 *   applied   : quy tắc mang lại hoàn tiền
 *   excluded  : quy tắc loại trừ (không hoàn tiền)
 *   unverified: chưa đủ dữ liệu để kết luận
 *
 * `fingerprint` là UNIQUE để import chạy lại không nhân bản bản ghi (idempotent).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_policy_mcc_mappings', function (Blueprint $table) {
            $table->id();

            // Chủ sở hữu chính sách: template hệ thống hoặc riêng của user.
            $table->foreignId('policy_template_id')->nullable()
                ->constrained('credit_card_policy_templates')->cascadeOnDelete();

            // Danh mục chi tiêu hoàn tiền liên quan (nếu xác định được).
            $table->foreignId('category_id')->nullable()
                ->constrained('credit_card_categories')->nullOnDelete();

            // Tham chiếu quy tắc hoàn tiền cụ thể (nếu mapping khớp 1-1 một rule).
            $table->foreignId('policy_tier_category_id')->nullable()
                ->constrained('credit_card_policy_tier_categories')->nullOnDelete();

            $table->enum('match_type', ['direct', 'group', 'all', 'all_except'])->default('direct');

            // MCC đơn (chỉ dùng khi match_type = direct).
            $table->char('mcc', 4)->nullable();
            // Nhãn nhóm MCC (khi match_type = group và không liệt kê đủ mã).
            $table->string('mcc_group', 191)->nullable();
            // Danh sách mã: thành viên nhóm (group) hoặc mã ngoại lệ (all_except).
            $table->json('mcc_codes')->nullable();

            $table->enum('application_status', ['applied', 'excluded', 'unverified'])->default('unverified');

            $table->decimal('cashback_percent', 6, 3)->nullable()
                ->comment('Mức hoàn tiền nếu không tham chiếu được rule; 10.000 = 10%');
            $table->text('condition_note')->nullable()->comment('Mô tả chi tiết điều kiện hoàn tiền');
            $table->text('exclusion_reason')->nullable()->comment('Lý do/điều kiện loại trừ');

            $table->string('source_pdf', 191)->nullable()->comment('File PDF nguồn');
            $table->string('source_page', 64)->nullable()->comment('Trang trong PDF, ví dụ "12-13"');

            $table->enum('verification_status', ['verified', 'unverified', 'needs_review'])->default('unverified');
            $table->timestamp('verified_at')->nullable();

            // Khoá tự nhiên đã băm — chống trùng khi import chạy lại nhiều lần.
            $table->string('fingerprint', 64)->unique('credit_card_policy_mcc_mappings_fingerprint_unique');

            $table->timestamps();

            $table->index('policy_template_id', 'credit_card_policy_mcc_mappings_template_index');
            $table->index('mcc', 'credit_card_policy_mcc_mappings_mcc_index');
            $table->index('category_id', 'credit_card_policy_mcc_mappings_category_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_mcc_mappings');
    }
};
