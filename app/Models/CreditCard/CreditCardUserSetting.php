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
 *
 * ---------------------------------------------------------------------------
 * `money_unit` — CHỈ ĐỔI GIAO DIỆN, KHÔNG ĐỔI DỮ LIỆU
 * ---------------------------------------------------------------------------
 * Đơn vị số tiền (VND | THOUSAND_VND) cũng là thiết lập của USER, áp dụng cho
 * mọi thẻ và mọi kỳ — giống `payment_reminder_days`. Khác ở chỗ nó KHÔNG BAO GIỜ
 * tham gia tính: database luôn lưu VND, `money_unit` chỉ đổi cách View đọc con số
 * đó (4.237.000 đ hay 4.237 nghìn). Nên không có cột tiền nào đổi giá trị khi
 * người dùng đổi đơn vị.
 *
 * Kèm theo là `money_unit_symbol` — ký tự người dùng muốn thấy sau con số ("đ",
 * "nghìn", "VND", "k"…). NULL = chưa từng cấu hình (dùng mặc định theo đơn vị),
 * "" = chủ động bỏ suffix. Cả hai chỉ là presentation, không tham gia tính.
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
     * Đơn vị hiển thị số tiền: đồng (4.237.000 đ).
     *
     * Đây là đơn vị MẶC ĐỊNH và là đơn vị DỮ LIỆU: mọi cột tiền trong database
     * luôn là VND, `money_unit` chỉ đổi CÁCH ĐỌC số ở giao diện. Chọn mặc định
     * là VND nên người dùng chưa từng đổi đơn vị vẫn thấy đúng thứ họ thấy trước
     * đây — không cần UPDATE dữ liệu nào khi thêm cột.
     */
    public const MONEY_UNIT_VND = 'VND';

    /**
     * Đơn vị hiển thị số tiền: nghìn đồng (4.237 nghìn).
     *
     * Lựa chọn của người dùng có ngân hàng đọc hạn mức theo nghìn; số liệu VND
     * đầy đủ vẫn còn trong DB nên đổi qua lại hai chiều không mất dấu chữ số nào
     * (1 nghìn đồng = 1.000 đồng, không làm tròn).
     */
    public const MONEY_UNIT_THOUSAND = 'THOUSAND_VND';

    /**
     * Ký tự mặc định khi `money_unit_symbol` là NULL (chưa từng cấu hình).
     *
     * Hai giá trị này là NGUỒN DUY NHẤT cho mặc định — formatter, service và
     * giao diện Settings đều đọc từ đây, nên không chỗ nào có thể tự đoán một
     * kiểu khác. Không phải hằng của DB: DB không lưu default (xem migration).
     */
    public const DEFAULT_MONEY_UNIT_SYMBOL_VND = 'đ';

    public const DEFAULT_MONEY_UNIT_SYMBOL_THOUSAND = 'nghìn';

    /**
     * `closing_balance`/`payment_status` không nằm ở đây — bảng này chỉ giữ thiết
     * lập của user.
     */
    protected $fillable = [
        'user_id',
        'payment_reminder_days',
        'money_unit',
        'money_unit_symbol',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'payment_reminder_days' => 'integer',
            'money_unit' => 'string',
            // `string` cast giữ nguyên NULL và chuỗi rỗng — hai giá trị có ý
            // nghĩa KHÁC NHAU (chưa cấu hình vs chủ động bỏ suffix), không được
            // gộp lại thành một.
            'money_unit_symbol' => 'string',
        ];
    }

    /**
     * Ký tự mặc định của một đơn vị — dùng khi `money_unit_symbol` là NULL.
     *
     * Đơn vị lạ (dữ liệu hỏng) về mặc định của VND, cùng lập luận với
     * {@see moneyUnits()}: một chuỗi hiển thị sai không đáng làm sập trang.
     */
    public static function defaultMoneyUnitSymbol(string $unit): string
    {
        return $unit === self::MONEY_UNIT_THOUSAND
            ? self::DEFAULT_MONEY_UNIT_SYMBOL_THOUSAND
            : self::DEFAULT_MONEY_UNIT_SYMBOL_VND;
    }

    /**
     * Mọi đơn vị hợp lệ của `money_unit` — dùng cho validation `in:`.
     *
     * @return list<string>
     */
    public static function moneyUnits(): array
    {
        return [self::MONEY_UNIT_VND, self::MONEY_UNIT_THOUSAND];
    }
}