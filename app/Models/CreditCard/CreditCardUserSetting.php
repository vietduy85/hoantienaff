<?php

namespace App\Models\CreditCard;

/**
 * Thiết lập CHUNG của người dùng cho module Thẻ tín dụng.
 *
 * ---------------------------------------------------------------------------
 * TÁCH BIỆT BA CẤP DỮ LIỆU — ĐỪNG TRỘN
 * ---------------------------------------------------------------------------
 *   - `CreditCardUserSetting` — của USER: nhắc thanh toán trước mấy ngày. Một
 *     người một dòng, áp dụng cho mọi thẻ.
 *   - `UserCard`              — của THẺ: hạn trả ngày mấy, hạn mức, chốt kỳ ngày nào.
 *   - `CreditCardStatement`   — của KỲ: chi tiêu thật, đã trả hay chưa.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO "ĐÃ TRẢ" NẰM Ở KỲ, CÒN "NHẮC TRƯỚC MẤY NGÀY" NẰM Ở ĐÂY
 * ---------------------------------------------------------------------------
 * "Đã trả" là câu hỏi của từng hóa đơn: thẻ có nhiều kỳ và mỗi kỳ một hạn riêng,
 * nên kỳ trước có thể đã trả trong khi kỳ sau chưa — xem `CreditCardStatement`.
 *
 * "Nhắc tôi trước mấy ngày" thì ngược lại: đó là một lựa chọn của người dùng về
 * CÁCH HỌ MUỐN ĐƯỢC NHẮC, giống như "nhắc trước 3 ngày" trong mọi app ngân hàng.
 * Đặt nó ở từng kỳ sẽ bắt người dùng khai báo lại cho từng hóa đơn — và các kỳ
 * tạo sau sẽ mặc định khác nhau, tức cùng một người dùng bị nhắc khác nhau.
 *
 * ---------------------------------------------------------------------------
 * KHÔNG CÓ CỜ BẬT/TẮT
 * ---------------------------------------------------------------------------
 * Nhắc trước LUÔN bật. Cờ boolean sẽ tạo ra trạng thái "tắt" không có ngày bắt đầu
 * cảnh báo, tức không tính được ngày phải cảnh báo — chỉ thêm một nhánh không ai
 * dùng tới. Vì vậy số ngày luôn có mặt và luôn trong `1..10`.
 */
class CreditCardUserSetting extends CreditCardModel
{
    protected $table = 'credit_card_user_settings';

    /**
     * Số ngày dùng khi người dùng chưa lưu thiết lập nào.
     *
     * Đặt 1 vì đây là mặc định ÍT GIẬT nhất vẫn hữu ích: hạn trả của thẻ tín dụng
     * là khoản nợ thật, bỏ qua hẳn một ngày thì người dùng dễ thấy cảnh báo khi
     * việc trả đã rất gấp. Số ngày lớn hơn thì người dùng tự đặt, và luôn có thể
     * đổi lại 1 — không có lựa chọn này bị khoá.
     */
    public const DEFAULT_PAYMENT_REMINDER_DAYS = 1;

    /**
     * Nhắc trước tối thiểu 1 ngày: nhắc đúng ngày đến hạn là nhắc quá muộn, và mục
     * tiêu của cảnh báo là cho người dùng còn thời gian trả.
     */
    public const MIN_PAYMENT_REMINDER_DAYS = 1;

    /**
     * Tối đa 10 ngày.
     *
     * Trần này CỐ Ý nhỏ: người dùng không nhắc thanh toán cách hạn 10 ngày thì vẫn
     * thấy cảnh báo khi hạn còn 10 ngày — đủ sớm để sắp xếp tiền. Nhắc sớm hơn
     * nữa chỉ làm nhiễu, và số ngày tùy ý là nơi dễ dùng để "tắt" cảnh báo mà không
     * cần nói ra.
     */
    public const MAX_PAYMENT_REMINDER_DAYS = 10;

    /**
     * `closing_balance`/`payment_status` không nằm ở đây — bảng này chỉ giữ thiết
     * lập của user.
     */
    protected $fillable = [
        'user_id',
        'payment_reminder_days',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'payment_reminder_days' => 'integer',
        ];
    }
}