<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lưu thiết lập CHUNG của người dùng cho module Thẻ tín dụng.
 *
 * ---------------------------------------------------------------------------
 * CHỈ NHẬN `payment_reminder_days` — KHÔNG CÓ CỜ BẬT/TẮT
 * ---------------------------------------------------------------------------
 * Nhắc trước luôn bật; chỉ có một câu hỏi là "trước mấy ngày". Nếu thêm cờ bật/tắt
 * thì sẽ tạo ra trạng thái "tắt" không có ngày bắt đầu cảnh báo — tức không tính
 * được ngày phải cảnh báo, và người dùng có thể tắt cảnh báo quá hạn mà không cần
 * nói ra. Vì vậy `required` chứ không phải `nullable`, và không có `boolean` nào ở
 * đây.
 *
 * ---------------------------------------------------------------------------
 * `user_id` KHÔNG CÓ TRONG `rules()` ⇒ BỊ BỎ QUA
 * ---------------------------------------------------------------------------
 * Người dùng lấy từ `auth()->id()`, không bao giờ từ request. Cùng nguyên tắc với
 * mọi endpoint khác của module.
 */
class UpdateCreditCardSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_reminder_days' => [
                'required',
                'integer',
                'min:'.CreditCardUserSetting::MIN_PAYMENT_REMINDER_DAYS,
                'max:'.CreditCardUserSetting::MAX_PAYMENT_REMINDER_DAYS,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_reminder_days.required' => 'Vui lòng nhập số ngày nhắc trước.',
            'payment_reminder_days.integer' => 'Số ngày nhắc trước phải là số nguyên.',
            'payment_reminder_days.min' => 'Số ngày nhắc trước phải từ '
                .CreditCardUserSetting::MIN_PAYMENT_REMINDER_DAYS.' ngày trở lên.',
            'payment_reminder_days.max' => 'Số ngày nhắc trước không được vượt quá '
                .CreditCardUserSetting::MAX_PAYMENT_REMINDER_DAYS.' ngày.',
        ];
    }

    /**
     * Số ngày đã ép về kiểu int.
     *
     * Ép kiểu ở đây thay vì để service nhận chuỗi: request đã bảo đảm là số nguyên
     * trong khoảng hợp lệ, nên chỗ ghi không cần đoán lại.
     */
    public function reminderDays(): int
    {
        return (int) $this->input('payment_reminder_days');
    }
}