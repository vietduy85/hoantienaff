<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCC master — danh mục mã Merchant Category Code (chuẩn quốc tế).
 *
 * Bảng này là NGUỒN THAM CHIẾU duy nhất cho phần "Tra cứu MCC": mã 4 ký tự,
 * mô tả tiếng Anh (nguyên văn chuẩn) và giải thích tiếng Việt.
 *
 * ---------------------------------------------------------------------------
 * TRẠNG THÁI XÁC MINH LÀ BẮT BUỘC
 * ---------------------------------------------------------------------------
 * Dữ liệu MCC nhập từ file tham khảo (`MCC_reference_Vietnamese.xlsx`) hay từ
 * PDF của thẻ đều có thể CHƯA được đối chiếu. Vì vậy mỗi bản ghi mang
 * `verification_status`:
 *   - unverified   : mặc định, chưa đối chiếu (kể cả mọi dòng nhập từ Excel tham khảo)
 *   - needs_review : có dữ liệu nhưng cần người kiểm tra tay
 *   - verified     : đã đối chiếu nguồn
 *
 * KHÔNG bảng nào khác trong module lưu lại tên/mô tả MCC — tránh trùng lặp.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_mcc_codes', function (Blueprint $table) {
            $table->id();

            // Mã MCC 4 KÝ TỰ, giữ số 0 ở đầu. char(4) + UNIQUE.
            $table->char('mcc', 4)->unique()->comment('Mã MCC 4 ký tự, giữ nguyên số 0 đầu');

            $table->text('description_en')->nullable()->comment('Mô tả tiếng Anh (nguồn quốc tế)');
            $table->text('description_vi')->nullable()->comment('Giải thích tiếng Việt');
            $table->text('keywords')->nullable()->comment('Từ khoá tìm kiếm bổ sung');

            $table->string('source', 191)->nullable()
                ->comment('Nguồn dữ liệu: tên file hoặc tài liệu');

            $table->enum('verification_status', ['verified', 'unverified', 'needs_review'])
                ->default('unverified')
                ->comment('Trạng thái xác minh; mặc định unverified vì chưa đối chiếu nguồn');
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index('verification_status', 'credit_card_mcc_codes_verification_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_mcc_codes');
    }
};
