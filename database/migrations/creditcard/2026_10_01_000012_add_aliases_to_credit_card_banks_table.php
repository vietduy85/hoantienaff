<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12/12 — Thêm cột `aliases` cho Bank: lưu mã legacy/alias của một ngân hàng
 * mà KHÔNG tạo thêm bản ghi bank riêng.
 *
 * VÌ SAO CẦN CỘT NÀY
 * ------------------
 * Danh sách ngân hàng mà owner cung cấp có 44 DÒNG NGHIỆP VỤ, nhưng trong đó có
 * cặp mã trùng NĂNG LỰC trên cùng một pháp nhân:
 *
 *   #23 "Sài Gòn (SCB)"   \
 *                          >— CÙNG MỘT entity: Ngân hàng TMCP Sài Gòn — Sacombank
 *   #33 "Sacombank (STB)" /
 *
 * Sacombank là tên thương hiệu, mã chính thức hiện hành là `STB`; `SCB` chỉ là
 * mã viết tắt cũ hay bị nhầm. Nếu seed theo từng dòng thì database sẽ có HAI
 * bản ghi cho CÙNG một ngân hàng → user thấy trùng, cashback template có thể
 * cấu hình nhầm, và báo cáo "44 banks" sẽ sai về mặt nghiệp vụ.
 *
 * CÁCH SỬA ĐÃ CHỌN
 * ----------------
 *   - 44 dòng nghiệp vụ  →  43 bank CANONICAL trong DB.
 *   - `stb` là slug canonical của Sacombank, `aliases = ["scb"]`.
 *   - Mọi mã cũ vẫn tra được ngân hàng đúng qua `Bank::resolveByCode()`.
 *
 * Vì sao là JSON chứ không phải bảng riêng `credit_card_bank_aliases`?
 *   Vì alias CHỈ đọc kèm bank, không có vòng đời riêng, và chỉ có vài chục bản
 *   ghi — thêm bảng riêng sẽ tạo thêm một nơi phải đồng bộ FK/unique mà không đổi
 *   lại được gì. `updateOrCreate` theo slug đã đủ để upsert nguyên tử.
 *
 * MySQL: `json`. SQLite (test harness): `text` — cả hai đều cast `'array'`
 * được, nên `Bank` không cần biết đang chạy trên engine nào.
 *
 * KHÔNG thêm index cho `aliases`: cột JSON không index trực tiếp được ở MySQL
 * 5.7, và tra cứu alias chỉ đi qua 43 dòng trong PHP nên không cần index.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_banks', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('credit_card_banks', 'aliases')) {
                return;
            }

            $table->json('aliases')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_banks', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('credit_card_banks', 'aliases')) {
                return;
            }

            $table->dropColumn('aliases');
        });
    }
};
