<?php

namespace App\Support\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use App\Services\CreditCard\CreditCardUserSettingService;

/**
 * CreditCardMoneyFormatter — ĐỊNH DẠNG SỐ TIỀN theo đơn vị của USER, đúng MỘT nơi.
 *
 * ---------------------------------------------------------------------------
 * DB LUÔN LƯU VND — ĐÂY CHỈ LÀ CÁCH ĐỌC
 * ---------------------------------------------------------------------------
 * `money_unit` của user quyết định con số VND đọc ra là `4.237.000 đ` hay
 * `4.237 nghìn`. Mọi phép tính (cashback, quota, tổng hạn mức) vẫn chạy trên VND
 * trong SQL/service; formatter không bao giờ tham gia tính, chỉ dựng chuỗi hiển
 * thị. Nhờ đó đổi đơn vị không làm thay đổi một đồng nào trong dữ liệu.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO DÙNG `bcdiv` CHỨ KHÔNG PHÉP CHIA FLOAT
 * ---------------------------------------------------------------------------
 * 1 đồng = 0.001 nghìn. Chia float (4237000 / 1000) an toàn, nhưng
 * `103400 / 1000` qua float có thể ra `103.39999999999999` — cắt thập phân là
 * mất chữ số cuối đúng chỗ hiển thị nhỏ nhất. `bcdiv($vnd, '1000', 3)` cắt đúng
 * 3 chữ số thập phân (= chính xác từng đồng) trên chuỗi, không qua IEEE-754.
 *
 * Số VND được LÀM TRÒN về đồng nguyên trước khi chia (half away from zero, cùng
 * cách `number_format` của nhánh VND đang làm) nên không có số lẻ nào bị cắt mất.
 *
 * ---------------------------------------------------------------------------
 * MEMO THEO USER — ĐỌC MỘT LẦN CHO MỘT LẦN RENDER
 * ---------------------------------------------------------------------------
 * Trang Tổng quan/Chính sách render vài chục tới hàng trăm chỗ `x-credit-card.money`,
 * mỗi chỗ là một component. Không memo thì mỗi ô một câu SELECT → N+1. Memo
 * static theo `user_id` trong đời request, và `flush($userId)` được gọi đúng lúc
 * cửa ghi (`updateMoneyUnit`) đổi đơn vị — đọc sau đó luôn thấy giá trị mới.
 */
final class CreditCardMoneyFormatter
{
    /**
     * Đơn vị đã đọc của từng user trong đời request; null = chưa đọc.
     *
     * @var array<int, string>
     */
    private static array $unitByUser = [];

    /**
     * Số tiền hiển thị kèm hậu tố, phân tách bằng NBSP — bản PHP của `ccMoneyVnd`.
     *
     * NBSP (U+00A0) chứ không space thường: "đ"/"nghìn" là chữ duy nhất có thể
     * ngắt dòng trong một số tiền, và trên mobile nó rớt xuống dòng mới đúng lúc
     * khung hẹp nhất ("4.237.000" / "đ").
     *
     * @param  mixed  $value  Số tiền VND (string|int|float|null). `null`/rỗng ⇒ 0.
     */
    public static function money(mixed $value, ?int $userId): string
    {
        return self::number($value, $userId)."\u{00A0}".self::suffix($userId);
    }

    /**
     * CHỈ phần số, không hậu tố: `4.237.000` hoặc `4.237`.
     *
     * @param  mixed  $value  Số tiền VND.
     */
    public static function number(mixed $value, ?int $userId): string
    {
        if (self::unit($userId) === CreditCardUserSetting::MONEY_UNIT_THOUSAND) {
            return self::thousand($value);
        }

        return self::vnd($value);
    }

    /**
     * Hậu tố đơn vị: `đ` hoặc `nghìn`.
     */
    public static function suffix(?int $userId): string
    {
        return self::unit($userId) === CreditCardUserSetting::MONEY_UNIT_THOUSAND ? 'nghìn' : 'đ';
    }

    /**
     * Đơn vị của user, luôn về một trong hai giá trị constant.
     *
     * Giá trị rỗng/lạ trong DB chỉ có thể do dữ liệu hỏng — đọc về `VND` (đơn vị
     * dữ liệu) thay vì ném lỗi: một chuỗi hiển thị sai không đáng làm sập cả trang.
     * Khi `$userId` null (chưa đăng nhập, render ngoài request auth…) thì trả
     * luôn `VND` — đơn vị của dữ liệu — không cần đoán.
     */
    public static function unit(?int $userId): string
    {
        if ($userId === null) {
            return CreditCardUserSetting::MONEY_UNIT_VND;
        }

        if (! array_key_exists($userId, self::$unitByUser)) {
            $unit = app(CreditCardUserSettingService::class)->moneyUnitFor($userId);

            self::$unitByUser[$userId] = in_array($unit, CreditCardUserSetting::moneyUnits(), true)
                ? $unit
                : CreditCardUserSetting::MONEY_UNIT_VND;
        }

        return self::$unitByUser[$userId];
    }

    /**
     * Quên đơn vị đã memo của user — gọi sau mọi cửa ghi `money_unit`.
     */
    public static function flush(?int $userId): void
    {
        if ($userId !== null) {
            unset(self::$unitByUser[$userId]);
        }
    }

    /**
     * Xóa toàn bộ memo — chỉ dùng khi test muốn bắt đầu từ trạng thái sạch.
     */
    public static function flushAll(): void
    {
        self::$unitByUser = [];
    }

    /**
     * Hiển thị VND: phân cách `.`, không thập phân — đúng như trước đây.
     *
     * @param  mixed  $value  Số tiền VND.
     */
    private static function vnd(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 0, ',', '.');
    }

    /**
     * Giá trị VND làm tròn về đồng nguyên, CHƯA phân cách — input cho `bcdiv`.
     *
     * Dùng `number_format(..., 0, '.', '')` (dấu thập phân là `.`, không phân
     * cách nghìn): lấy chính xác chuỗi bcmath có thể chia tiếp.
     */
    private static function vndPlain(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 0, '.', '');
    }

    /**
     * Hiển thị nghìn: `4.237` / `103,4` / `0,999`.
     *
     * Số 0 vô nghĩa phía sau bị bỏ (`50.000` ⇒ `50`, không phải `50,000`); phần
     * thập phân còn lại tối đa 3 chữ số = chính xác tới 1 đồng. Dấu thập phân là
     * `,` và dấu phân cách nghìn là `.` (quy ước vi-VN), khớp `ccMoney` của JS.
     *
     * @param  mixed  $value  Số tiền VND.
     */
    private static function thousand(mixed $value): string
    {
        // Làm tròn về đồng NGUYÊN trước: `bcdiv` CẮT phần lẻ, để nguyên thì
        // 103.499đ thành `103,499` thay vì `103,5` — lệch đúng 1 đồng hiển thị.
        // Và chỉ lấy chuỗi SỐ THẦN (không dấu chấm nghìn) — `bcdiv` coi `.` là
        // dấu thập phân, nạp "4.237.500" vào sẽ đọc sai thành 4,2.
        $scaled = bcdiv(self::vndPlain($value), '1000', 3);
        $trimmed = rtrim(rtrim($scaled, '0'), '.');

        [$integer, $fraction] = array_pad(explode('.', $trimmed, 2), 2, '');

        $grouped = number_format((float) $integer, 0, ',', '.');

        return $fraction === '' ? $grouped : $grouped.','.substr($fraction, 0, 3);
    }

    /**
     * Giá trị để ĐIỀN VÀO ô nhập trước khi user sửa — bảo toàn đủ chữ số.
     *
     * Khác {@see number()}: ô nhập là cửa vào VÀ RA của dữ liệu nên phải đọc lại
     * được CHÍNH XÁC giá trị (kể cả lẻ xu). VND giữ tối đa 2 chữ số thập phân,
     * nghìn giữ tối đa 5 (0,00001 nghìn = 0,01 đ) — ngược lại làm tròn khi hiển
     * thị thì lần sửa sau vô tình đổi tiền. Không nhập được thì `''`.
     *
     * @param  mixed  $value  Số tiền VND (string|int|float|null).
     */
    public static function input(mixed $value, ?int $userId): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (self::unit($userId) === CreditCardUserSetting::MONEY_UNIT_THOUSAND) {
            return self::replaceDecimal(trim(rtrim(bcdiv(self::vndPlain($value), '1000', 5), '0'), '.'));
        }

        return self::replaceDecimal(rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'));
    }

    /**
     * Đổi `.` thập phân (đầu ra chuẩn bcmath/number_format) thành `,` để người
     * dùng thấy đúng quy ước vi-VN; phân cách nghìn `.` giữ nguyên (đầu ra của
     * number_format(`.', ''` ) không có nhóm nghìn nên không đụng vào).
     */
    private static function replaceDecimal(string $number): string
    {
        [$integer, $fraction] = array_pad(explode('.', $number, 2), 2, '');

        $grouped = number_format((float) $integer, 0, ',', '.');

        return $fraction === '' ? $grouped : $grouped.','.$fraction;
    }
}
