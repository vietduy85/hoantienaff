<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lưu ĐƠN VỊ SỐ TIỀN của người dùng (VND | THOUSAND_VND).
 *
 * ---------------------------------------------------------------------------
 * CHỈ NHẬN `money_unit` — GIỐNG HỆT NGUYÊN TẮC `UpdateCreditCardSettingRequest`
 * ---------------------------------------------------------------------------
 * `user_id` lấy từ `auth()->id()`, không bao giờ từ request: dòng thiết lập luôn
 * thuộc chính người đang đăng nhập, không có đường nào chạm vào thiết lập của
 * người khác nên không cần policy.
 *
 * Đơn vị là CHUỖI constant (`in:`) chứ không boolean/threshold: module có đúng
 * hai cách đọc số, và việc ghi xuống DB đã qua `normalizeUnit()` của service —
 * request chặn sớm để trả 422 rõ ràng thay vì âm thầm ghi về VND.
 *
 * KHÔNG có giá trị tiền nào trong request này: đơn vị CHỈ đổi cách hiển thị,
 * không bao giờ nhận số tiền để nhân/chia — dữ liệu luôn là VND.
 */
class UpdateCreditCardMoneyUnitRequest extends FormRequest
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
            'money_unit' => ['required', 'string', 'in:'.implode(',', CreditCardUserSetting::moneyUnits())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'money_unit.required' => 'Vui lòng chọn đơn vị số tiền.',
            'money_unit.string' => 'Đơn vị số tiền không hợp lệ.',
            'money_unit.in' => 'Đơn vị số tiền phải là Đồng hoặc Nghìn đồng.',
        ];
    }

    /**
     * Chuỗi đơn vị đã qua validation.
     *
     * Ép kiểu ở đây để service nhận đúng kiểu — request đã bảo đảm giá trị nằm
     * trong hai constant, chỗ ghi không cần đoán lại.
     */
    public function moneyUnit(): string
    {
        return (string) $this->input('money_unit');
    }
}
