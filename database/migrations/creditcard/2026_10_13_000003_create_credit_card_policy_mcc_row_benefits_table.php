<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Dữ liệu hoàn tiền theo dòng MCC" — bảng CON của `credit_card_policy_mcc_rows`.
 *
 * Mục đích: lưu ĐẦY ĐỦ dữ liệu hoàn tiền trích từ TÀI LIỆU NGÂN HÀNG GỐC (tỷ lệ,
 * bậc, điều kiện chi tiêu, trần + kỳ/đơn vị, ngoại lệ, nguồn + trang) cho từng
 * DÒNG MCC của hồ sơ. Dữ liệu này CHỈ để TRA CỨU/đối chiếu tài liệu.
 *
 * RÀNG BUỘC QUAN TRỌNG:
 *   - KHÔNG dùng bảng này làm đầu vào engine cashback/wallet/ledger.
 *   - KHÔNG ghi đè hay thay thế cấu hình blueprint (`credit_card_policy_*`).
 *   - KHÔNG lưu dữ liệu suy diễn: tỷ lệ/điều kiện phải có căn cứ trong tài liệu.
 *   - Mỗi bản ghi thuộc ĐÚNG một dòng (`policy_mcc_row_id`); dòng bị xoá ⇒ bản
 *     ghi bị xoá theo (cascade) nên không sinh bản ghi mồ côi.
 *
 * Nhập liệu là idempotent: UNIQUE (policy_mcc_row_id, tier_order) chặn trùng bậc
 * khi chạy lại trình nhập.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('creditcard')->create('credit_card_policy_mcc_row_benefits', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('policy_mcc_row_id');
            $table->foreign('policy_mcc_row_id', 'cc_pmcc_row_benefits_row_fk')
                ->references('id')->on('credit_card_policy_mcc_rows')->cascadeOnDelete();

            // Thứ tự bậc trong tài liệu (0 = bậc đầu). Dùng để idempotent + hiển thị.
            $table->unsignedInteger('tier_order')->default(0);

            // Tỷ lệ hoàn tiền: số đã chuẩn hoá (10.000 = 10%) + nhãn gốc trong tài liệu.
            $table->decimal('cashback_percent', 6, 3)->nullable();
            $table->string('rate_label', 191)->nullable();

            // Ngưỡng chi tiêu của bậc/điều kiện (VND). NULL = tài liệu không nêu.
            $table->decimal('min_spend', 16, 2)->nullable();
            $table->decimal('max_spend', 16, 2)->nullable();

            // Trần hoàn tiền + kỳ/đơn vị (ví dụ 300000 / 'tháng' / 'VND').
            $table->decimal('cap_amount', 16, 2)->nullable();
            $table->string('cap_period', 50)->nullable();
            $table->string('cap_unit', 20)->nullable();

            // Điều kiện kèm theo và ngoại lệ đặc thù của bậc/dòng.
            $table->text('condition_note')->nullable();
            $table->text('exception_note')->nullable();

            // Nguồn: tài liệu ngân hàng gốc + trang/ghi chú trích dẫn.
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->foreign('source_document_id', 'cc_pmcc_row_benefits_doc_fk')
                ->references('id')->on('credit_card_policy_mcc_documents')->nullOnDelete();
            $table->string('source_page', 50)->nullable();
            $table->text('source_note')->nullable();

            $table->timestamps();

            $table->unique(['policy_mcc_row_id', 'tier_order'], 'cc_pmcc_row_benefits_row_tier_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('creditcard')->dropIfExists('credit_card_policy_mcc_row_benefits');
    }
};
