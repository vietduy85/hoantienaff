<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use App\Support\CreditCard\CreditCardMoneyFormatter;

/**
 * Thiết lập CHUNG của người dùng: nhắc thanh toán trước mấy ngày + đơn vị số
 * tiền + ký tự đại diện của đơn vị đó.
 *
 * ---------------------------------------------------------------------------
 * ĐỌC KHÔNG GHI
 * ---------------------------------------------------------------------------
 * `reminderDaysFor()` trả về số ngày mà không tạo dòng thiết lập. Lý do giống hệt
 * `CreditCardStatementService`: mở trang không được sinh dữ liệu. Người dùng mới
 * mở Tổng quan sẽ nhận số ngày mặc định, và dòng thiết lập chỉ được tạo ra đúng
 * lúc họ thực sự đổi số ngày.
 *
 * ---------------------------------------------------------------------------
 * `updateOrCreate` THEO `user_id`
 * ---------------------------------------------------------------------------
 * `credit_card_user_settings.user_id` là UNIQUE nên `updateOrCreate` là đúng một
 * lần ghi cho mỗi người dùng. `firstOrNew` thay vì `updateOrCreate` để không phải
 * chạy SELECT rồi INSERT trong một transaction mà vẫn có thể đụng UNIQUE khi hai
 * request cùng lúc tới — người dùng bấm nhiều lần là chuyện thường.
 */
class CreditCardUserSettingService
{
    /**
     * Số ngày nhắc trước hạn của user, luôn trong khoảng hợp lệ.
     *
     * Dùng mặc định khi chưa có dòng thiết lập. Giá trị đọc ra được chuẩn hoá về
     * `1..10` ngay ở đây — đây là nơi DUY NHẤT quyết "hợp lệ là bao nhiêu", nên kỳ
     * nào đọc cũng không tự đoán. Nếu thiếu lớp này thì mỗi nơi đọc lại một kiểu
     * và chúng sẽ trôi khỏi nhau.
     */
    public function reminderDaysFor(int $userId): int
    {
        $days = $this->forUser($userId)?->payment_reminder_days;

        return $days === null ? CreditCardUserSetting::DEFAULT_PAYMENT_REMINDER_DAYS : $this->normalize($days);
    }

    /**
     * Đơn vị số tiền của user, luôn về một trong hai giá trị constant.
     *
     * Cùng nguyên tắc {@see reminderDaysFor()}: đọc KHÔNG tạo dòng thiết lập —
     * mở trang không được sinh dữ liệu. Chưa từng đổi đơn vị ⇒ `VND`, đúng đơn vị
     * module hiển thị từ trước, nên không cần UPDATE dữ liệu nào khi thêm cột.
     *
     * Chuẩn hoá tại đây vì đây là nơi DUY NHẤT quyết "đơn vị hợp lệ là gì": giá
     * trị lạ trong DB chỉ làm hiển thị về VND thay vì làm sập trang hay để mỗi
     * chỗ đọc tự đoán một kiểu.
     */
    public function moneyUnitFor(int $userId): string
    {
        $unit = $this->forUser($userId)?->money_unit;

        return $unit === null ? CreditCardUserSetting::MONEY_UNIT_VND : $this->normalizeUnit($unit);
    }

    /**
     * Ký tự đại diện ĐANG LƯU (thô) của user, hoặc NULL nếu chưa từng cấu hình.
     *
     * Trả NGUYÊN trạng thái DB — không resolve mặc định: "" (chủ động bỏ suffix)
     * và NULL (chưa cấu hình) là hai ý nghĩa khác nhau, chỗ cần biết "user đã
     * cấu hình gì" phải đọc chỗ này; chỗ chỉ cần chuỗi hiển thị dùng
     * {@see resolveMoneyUnitSymbol()}.
     *
     * Cùng nguyên tắc {@see moneyUnitFor()}: đọc KHÔNG tạo dòng thiết lập.
     */
    public function moneyUnitSymbolFor(int $userId): ?string
    {
        return $this->forUser($userId)?->money_unit_symbol;
    }

    /**
     * Resolve ký tự HIỂN THỊ từ đơn vị + ký tự đã lưu — hàm thuần, không ghi DB.
     *
     *   - `rawSymbol !== NULL` ⇒ dùng nguyên văn (kể cả chuỗi rỗng — người dùng
     *     chủ động bỏ suffix thì phải hiển thị không suffix);
     *   - `rawSymbol === NULL`  ⇒ mặc định theo đơn vị (VND ⇒ "đ",
     *     THOUSAND_VND ⇒ "nghìn").
     *
     * Một nơi duy nhất cho quy tắc này nên formatter (PHP), Settings controller
     * (truyền vào view) và response của endpoint không thể lệch nhau.
     */
    public static function resolveMoneyUnitSymbol(string $moneyUnit, ?string $rawSymbol): string
    {
        return $rawSymbol ?? CreditCardUserSetting::defaultMoneyUnitSymbol($moneyUnit);
    }

    /**
     * Dòng thiết lập thô của user, hoặc null. Chỉ đọc, không tạo.
     */
    public function forUser(int $userId): ?CreditCardUserSetting
    {
        return CreditCardUserSetting::query()
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Ghi số ngày nhắc trước, tạo dòng thiết lập nếu chưa có.
     *
     * Chuẩn hoá TRƯỚC khi ghi để mọi giá trị lưu xuống đều nằm trong khoảng hợp lệ.
     * Request đã chặn rồi, nhưng đây là cửa ghi duy nhất và service không được
     * tin rằng mọi đường vào đều đã qua validation.
     *
     * @return array{0: CreditCardUserSetting, 1: int}
     */
    public function updateReminderDays(int $userId, int $days): array
    {
        $normalized = $this->normalize($days);

        $setting = CreditCardUserSetting::query()->updateOrCreate(
            ['user_id' => $userId],
            ['payment_reminder_days' => $normalized],
        );

        return [$setting->refresh(), $normalized];
    }

    /**
     * Ghi ĐƠN VỊ + KÝ TỰ ĐẠI DIỆN của user, tạo dòng thiết lập nếu chưa có.
     *
     * Cả hai cột ghi trong ĐÚNG MỘT `updateOrCreate` — một payload "Lưu" của
     * Settings không được tách thành hai lần ghi để giữa hai lần đơn vị và ký tự
     * lệch nhau. `payment_reminder_days` không nằm trong mảng attributes nên
     * update không đụng tới nó (xem test N).
     *
     * `symbol`:
     *   - `NULL`  = caller không cấu hình ký tự ⇒ cột giữ NULL (chưa từng cấu hình);
     *   - `""`    = chủ động bỏ suffix ⇒ ghi "" (KHÔNG chuyển thành NULL);
     *   - khác    => ký tự tuỳ chỉnh, trim hai đầu trước khi ghi.
     *
     * Chuẩn hoá TRƯỚC khi ghi; request đã chặn rồi nhưng đây là cửa ghi duy nhất,
     * không được tin mọi đường vào đều qua validation.
     *
     * Sau khi ghi phải `flush()` memo của `CreditCardMoneyFormatter`: formatter
     * memo (đơn vị, ký tự) theo user trong đời request, để nguyên thì request
     * đang render (hoặc request tiếp theo trong cùng worker dài) vẫn đọc giá trị
     * cũ.
     *
     * @return CreditCardUserSetting
     */
    public function updateMoneyUnit(int $userId, string $unit, ?string $symbol = null): CreditCardUserSetting
    {
        $setting = CreditCardUserSetting::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'money_unit' => $this->normalizeUnit($unit),
                'money_unit_symbol' => $symbol === null ? null : $this->normalizeSymbol($symbol),
            ],
        );

        CreditCardMoneyFormatter::flush($userId);

        return $setting->refresh();
    }

    /**
     * Kẹp giá trị về khoảng hợp lệ.
     *
     * Kẹp chứ không ném lỗi: đây là hàm đọc dữ liệu đã tồn tại, và giá trị lệch
     * chỉ có thể do dữ liệu cũ. Ném lỗi ở đây sẽ làm hỏng cả trang Tổng quan
     * vì một con số sai — còn kẹp về giá trị hợp lệ thì trang vẫn đúng và con số
     * sai được sửa khi người dùng lưu lại.
     */
    private function normalize(int $days): int
    {
        return max(
            CreditCardUserSetting::MIN_PAYMENT_REMINDER_DAYS,
            min(CreditCardUserSetting::MAX_PAYMENT_REMINDER_DAYS, $days),
        );
    }

    /**
     * Kẹp đơn vị về giá trị hợp lệ.
     *
     * Kẹp chứ không ném lỗi (cùng lập luận {@see normalize()}): giá trị lạ chỉ có
     * thể do dữ liệu hỏng, và đơn vị sai không đáng làm hỏng trang — về `VND`,
     * đơn vị của chính dữ liệu, thì giao diện vẫn hiển thị đúng con số thật.
     */
    private function normalizeUnit(string $unit): string
    {
        return in_array($unit, CreditCardUserSetting::moneyUnits(), true)
            ? $unit
            : CreditCardUserSetting::MONEY_UNIT_VND;
    }

    /**
     * Chuẩn hoá ký tự đại diện trước khi ghi: trim hai đầu, kẹp về 20 ký tự.
     *
     * KHÔNG đổi chuỗi rỗng thành NULL: người dùng gõ toàn khoảng trắng rồi Lưu
     * là hành động "bỏ suffix", kết quả phải là "" chứ không phải "chưa từng cấu
     * hình" — hai ý nghĩa khác nhau (xem `resolveMoneyUnitSymbol()`).
     *
     * Kẹp 20 ký tự theo cột DB — request đã chặn `max:20`, đây là lưới an toàn
     * thứ hai của cửa ghi duy nhất, cùng lập luận với `normalize()`.
     */
    private function normalizeSymbol(string $symbol): string
    {
        $trimmed = trim($symbol);

        return mb_strlen($trimmed) > 20 ? mb_substr($trimmed, 0, 20) : $trimmed;
    }
}