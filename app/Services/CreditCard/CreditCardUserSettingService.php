<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use App\Support\CreditCard\CreditCardMoneyFormatter;

/**
 * Thiết lập CHUNG của người dùng: nhắc thanh toán trước mấy ngày + đơn vị số tiền.
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
     * Ghi đơn vị số tiền của user, tạo dòng thiết lập nếu chưa có.
     *
     * Chuẩn hoá TRƯỚC khi ghi để chỉ hai giá trị constant nằm trong DB; request
     * đã chặn rồi nhưng đây là cửa ghi duy nhất, không được tin mọi đường vào
     * đều qua validation.
     *
     * Sau khi ghi phải `flush()` memo của `CreditCardMoneyFormatter`: formatter
     * memo đơn vị theo user trong đời request, để nguyên thì request đang render
     * (hoặc request tiếp theo trong cùng worker dài) vẫn đọc giá trị cũ.
     *
     * @return CreditCardUserSetting
     */
    public function updateMoneyUnit(int $userId, string $unit): CreditCardUserSetting
    {
        $normalized = $this->normalizeUnit($unit);

        $setting = CreditCardUserSetting::query()->updateOrCreate(
            ['user_id' => $userId],
            ['money_unit' => $normalized],
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
}