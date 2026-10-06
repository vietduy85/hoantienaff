<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thiết lập CHUNG của người dùng cho module Thẻ tín dụng.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO TÁCH RIÊNG, KHÔNG NHỒI VÀO BẢNG NÀO KHÁC
 * ---------------------------------------------------------------------------
 * Module đã có ba nơi rất dễ bị nhầm là "chỗ của thiết lập":
 *
 *   - `credit_card_user_cards` — thuộc TỪNG THẺ (hạn trả ngày mấy, hạn mức).
 *   - `credit_card_statements` — thuộc TỪNG KỲ (chi tiêu thật, đã trả chưa).
 *   - bảng này               — thuộc TỪNG USER, dùng chung cho mọi thẻ và mọi kỳ.
 *
 * Số ngày nhắc thanh toán rơi vào nhóm thứ ba: người dùng đặt "nhắc tôi trước 3
 * ngày" một lần và áp dụng cho MỌI thẻ. Nếu nhồi vào `credit_card_user_cards` thì
 * phải sửa từng thẻ và hai thẻ sẽ lệch nhau; nhồi vào `credit_card_statements` thì
 * phải sửa từng kỳ, và các kỳ sau sẽ mặc định khác nhau. Cả hai đều sai ý nghĩa:
 * đây là một lựa chọn của người dùng, không phải thuộc tính của thẻ hay của hóa đơn.
 *
 * ---------------------------------------------------------------------------
 * `user_id` LÀ LOGICAL REFERENCE, KHÔNG CÓ FOREIGN KEY
 * ---------------------------------------------------------------------------
 * Cùng nguyên tắc với `credit_card_user_cards.user_id`: database Thẻ tín dụng nằm
 * ở connection khác database chính nên không thể đặt FK xuyên database. UNIQUE vẫn
 * giữ được: một người dùng chỉ có MỘT dòng thiết lập.
 *
 * ---------------------------------------------------------------------------
 * UNIQUE `user_id` — KHÔNG PHẢI INDEX THUẦN
 * ---------------------------------------------------------------------------
 * Hai dòng cho cùng một người dùng là mâu thuẫn dữ liệu, và lệnh ghi dùng upsert
 * theo `user_id`. UNIQUE biến lỗi đó từ "hành vi không xác định" thành lỗi DB rõ
 * ràng, đồng thời cho index phục vụ việc tra một dòng thiết lập.
 *
 * ---------------------------------------------------------------------------
 * MỘT CỘT NHỚ TRẠNG THÁI, KHÔNG THÊM CỜ BẬT/TẮT
 * ---------------------------------------------------------------------------
 * Nhắc trước luôn bật; số ngày luôn có giá trị trong `1..10`. Cột boolean
 * "có nhắc không" sẽ tạo ra trạng thái tắt không có ngày bắt đầu cảnh báo — tức
 * không tính được ngày cần cảnh báo. Vì vậy không có `payment_reminder_enabled` ở
 * đây, cũng không có ở `credit_card_statements`.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_user_settings', function (Blueprint $table) {
            $table->id();

            // Logical reference tới `hoantienaff.users.id` — xem docblock class.
            $table->unsignedBigInteger('user_id');

            // 1..10 ngày. `tinyint` vừa đủ và nhỏ hơn `int`; CHECK của MySQL 8 và
            // validation của request là hai lớp, không thay thế nhau.
            $table->unsignedTinyInteger('payment_reminder_days')
                ->default(1)
                ->comment('Nhắc thanh toán trước hạn bao nhiêu ngày; luôn trong 1..10');

            $table->timestamps();

            $table->unique('user_id', 'credit_card_user_settings_user_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_user_settings');
    }
};