<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;

/**
 * Thiết lập CHUNG của người dùng (hiện chỉ có nhắc thanh toán trước mấy ngày).
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
}