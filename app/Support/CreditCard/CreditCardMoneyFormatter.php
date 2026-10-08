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
 * `4.237 nghìn`; `money_unit_symbol` (kèm default theo đơn vị khi NULL) quyết
 * định ký tự đứng sau con số — kể cả chuỗi rỗng, tức KHÔNG hiển thị suffix.
 * Mọi phép tính (cashback, quota, tổng hạn mức) vẫn chạy trên VND
 * trong SQL/service; formatter không bao giờ tham gia tính, chỉ dựng chuỗi hiển
 * thị. Nhờ đó đổi đơn vị/ký tự không làm thay đổi một đồng nào trong dữ liệu.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO DÙNG `bcdiv` CHỨ KHÔNG PHÉP CHIA FLOAT
 * ---------------------------------------------------------------------------
 * Đơn vị nghìn hiển thị SỐ NGUYÊN ĐÃ FLOOR: `456.999` ⇒ `456`, `499.999` ⇒
 * `499` — không làm tròn lên, nên số in ra luôn là mức người dùng chắc chắn đã
 * đạt, và JS (`ccMoney`) floor cùng một chỗ. Chia float (456999 / 1000) có thể
 * ra `456.99899999999996` — cắt về số nguyên qua float là cờ bạc đúng chỗ
 * biên. `bcdiv($vnd, '1000', 0)` cắt phần lẻ trên chuỗi số, không qua
 * IEEE-754.
 *
 * Số VND được LÀM TRÒN về đồng nguyên trước khi chia (half away from zero, cùng
 * cách `number_format` của nhánh VND đang làm), rồi mới floor xuống nhóm 1000.
 *
 * ---------------------------------------------------------------------------
 * MEMO THEO USER — ĐỌC MỘT LẦN CHO MỘT LẦN RENDER
 * ---------------------------------------------------------------------------
 * Trang Tổng quan/Chính sách render vài chục tới hàng trăm chỗ `x-credit-card.money`,
 * mỗi chỗ là một component. Không memo thì mỗi ô một câu SELECT → N+1. Memo
 * static theo `user_id` trong đời request, và `flush($userId)` được gọi đúng lúc
 * cửa ghi (`updateMoneyUnit`) đổi đơn vị/ký tự — đọc sau đó luôn thấy giá trị
 * mới. Key là `user_id` nên memo không rò giữa các user trong cùng request.
 */
final class CreditCardMoneyFormatter
{
    /**
     * Thiết lập đã đọc của từng user trong đời request; null = chưa đọc.
     *
     * @var array<int, array{unit: string, symbol: ?string}>
     */
    private static array $settingByUser = [];

    /**
     * Số tiền hiển thị kèm hậu tố, phân tách bằng NBSP — bản PHP của `ccMoneyVnd`.
     *
     * NBSP (U+00A0) chứ không space thường: "đ"/"nghìn" là chữ duy nhất có thể
     * ngắt dòng trong một số tiền, và trên mobile nó rớt xuống dòng mới đúng lúc
     * khung hẹp nhất ("4.237.000" / "đ").
     *
     * Khi hậu tố RỖNG (người dùng chủ động bỏ ký tự) chỉ trả phần số — không
     * nối NBSP thừa phía sau, nếu không ra "2.000.000&nbsp;" hay "2.000.000 ".
     *
     * @param  mixed  $value  Số tiền VND (string|int|float|null). `null`/rỗng ⇒ 0.
     */
    public static function money(mixed $value, ?int $userId): string
    {
        $suffix = self::suffix($userId);

        if ($suffix === '') {
            return self::number($value, $userId);
        }

        return self::number($value, $userId)."\u{00A0}".$suffix;
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
     * Hậu tố HIỂN THỊ của user: ký tự đã lưu, hoặc mặc định theo đơn vị.
     *
     *   - ký tự đã lưu (kể cả `""`) ⇒ dùng nguyên văn — `""` nghĩa là người dùng
     *     chủ động bỏ suffix, formatter trả "" và mọi nơi tự ngắt NBSP;
     *   - chưa từng cấu hình (`NULL`) ⇒ mặc định `đ`/`nghìn` theo đơn vị.
     *
     * Quy tắc resolve nằm duy nhất ở
     * `CreditCardUserSettingService::resolveMoneyUnitSymbol()` để view PHP,
     * response API và bản JS không thể lệch nhau.
     */
    public static function suffix(?int $userId): string
    {
        $setting = self::setting($userId);

        return CreditCardUserSettingService::resolveMoneyUnitSymbol($setting['unit'], $setting['symbol']);
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
        return self::setting($userId)['unit'];
    }

    /**
     * Đọc (đơn vị, ký tự thô) của user — MỘT câu SELECT cho cả hai, memo theo user.
     *
     * `symbol` để nguyên theo DB (NULL | "" | chuỗi) — chỗ hiển thị mới resolve
     * mặc định, nhờ đó hai trạng thái "chưa cấu hình" và "chủ động bỏ suffix"
     * không bị gộp lại ngay từ tầng đọc.
     *
     * @return array{unit: string, symbol: ?string}
     */
    private static function setting(?int $userId): array
    {
        if ($userId === null) {
            return ['unit' => CreditCardUserSetting::MONEY_UNIT_VND, 'symbol' => null];
        }

        if (! array_key_exists($userId, self::$settingByUser)) {
            $row = app(CreditCardUserSettingService::class)->forUser($userId);
            $unit = $row?->money_unit;

            self::$settingByUser[$userId] = [
                'unit' => in_array($unit, CreditCardUserSetting::moneyUnits(), true)
                    ? $unit
                    : CreditCardUserSetting::MONEY_UNIT_VND,
                'symbol' => $row?->money_unit_symbol,
            ];
        }

        return self::$settingByUser[$userId];
    }

    /**
     * Quên thiết lập đã memo của user — gọi sau mọi cửa ghi `money_unit`.
     */
    public static function flush(?int $userId): void
    {
        if ($userId !== null) {
            unset(self::$settingByUser[$userId]);
        }
    }

    /**
     * Xóa toàn bộ memo — chỉ dùng khi test muốn bắt đầu từ trạng thái sạch.
     */
    public static function flushAll(): void
    {
        self::$settingByUser = [];
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
     * Hiển thị nghìn: LÀM TRÒN XUỐNG về số nguyên — `4.237` / `456` / `0`.
     *
     * Số VND được làm tròn về đồng nguyên trước (half away from zero, cùng cách
     * `number_format` của nhánh VND), rồi chia 1000 và CẮT phần lẻ (floor).
     * `456.999` ⇒ `456`, `499.999` ⇒ `499` — không làm tròn lên: số hiển thị
     * luôn là mức NGUYÊN TỐI ĐA mà người dùng chắc chắn đã đạt, và JS
     * (`ccMoney`) floor cùng một chỗ nên hai phía không thể lệch.
     *
     * `bcdiv(..., 0)` cắt phần lẻ trên chuỗi số — không qua float IEEE-754.
     *
     * @param  mixed  $value  Số tiền VND.
     */
    private static function thousand(mixed $value): string
    {
        $vnd = self::vndPlain($value);
        $floored = bcdiv($vnd, '1000', 0);

        // `bcdiv` scale 0 CẮT về 0 (toward zero): số âm có phần lẻ phải trừ
        // thêm 1 mới đúng floor — khớp `Math.floor` của JS.
        if (bccomp($vnd, '0', 0) < 0 && bccomp(bcmod($vnd, '1000'), '0', 0) !== 0) {
            $floored = bcsub($floored, '1', 0);
        }

        return self::replaceDecimal($floored);
    }

    /**
     * Giá trị để ĐIỀN VÀO ô nhập trước khi user sửa — cùng chuỗi với
     * {@see number()}, ô đọc lại được NGAY CON SỐ người dùng nhìn thấy.
     *
     * VND giữ tối đa 2 chữ số thập phân (`0,01`); nghìn là SỐ NGUYÊN đã floor
     * (`103.400` ⇒ `103`, `4.237.500` ⇒ `4.237`) — đơn vị nghìn hiển thị xuống
     * dưới đồng thì prefill cũng phải như vậy, không được ô sửa vô tình kéo
     * lại số lẻ đã mất khỏi màn hình. Không nhập được thì `''`.
     *
     * @param  mixed  $value  Số tiền VND (string|int|float|null).
     */
    public static function input(mixed $value, ?int $userId): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (self::unit($userId) === CreditCardUserSetting::MONEY_UNIT_THOUSAND) {
            return self::thousand($value);
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
