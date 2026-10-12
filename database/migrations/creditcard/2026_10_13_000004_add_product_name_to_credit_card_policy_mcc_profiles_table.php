<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thêm `product_name` cho HỒ SƠ MCC — TÊN SẢN PHẨM ĐẠI DIỆN.
 *
 * Một số chương trình ngân hàng dùng CHUNG một tài liệu cho NHIỀU HẠNG thẻ
 * (VIB Max Card Visa: Starter/Plus/Pro/Elite; VIB One Card Visa: Gold/Platinum/
 * Signature). Khi đó các hạng thẻ chỉ là các template hệ thống riêng, còn hồ sơ
 * MCC + tài liệu là DÙNG CHUNG.
 *
 * `product_name` cho phép hồ sơ khai báo TÊN SẢN PHẨM đại diện để "Tra cứu MCC"
 * gộp các hạng về MỘT kết quả (`VIB Max Card Visa`, `VIB One Card Visa`) thay vì
 * hiển thị tên hạng thẻ vận chuyển dữ liệu.
 *
 * NULL = giữ nguyên hành vi cũ (tên kết quả lấy từ template như trước). KHÔNG
 * ảnh hưởng engine cashback hay blueprint.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_mcc_profiles', function (Blueprint $table) {
            $table->string('product_name', 191)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_mcc_profiles', function (Blueprint $table) {
            $table->dropColumn('product_name');
        });
    }
};
