<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lưu ĐƠN VỊ SỐ TIỀN (VND | THOUSAND_VND) + KÝ TỰ ĐẠI DIỆN của đơn vị.
 *
 * ---------------------------------------------------------------------------
 * CHỈ NHẬN `money_unit` VÀ `money_unit_symbol` — GIỐNG HỆT NGUYÊN TẮC
 * `UpdateCreditCardSettingRequest`
 * ---------------------------------------------------------------------------
 * `user_id` lấy từ `auth()->id()`, không bao giờ từ request: dòng thiết lập luôn
 * thuộc chính người đang đăng nhập, không có đường nào chạm vào thiết lập của
 * người khác nên không cần policy.
 *
 * Đơn vị là CHUỖI constant (`in:`) chứ không boolean/threshold: module có đúng
 * hai cách đọc số, và việc ghi xuống DB đã qua `normalizeUnit()` của service —
 * request chặn sớm để trả 422 rõ ràng thay vì âm thầm ghi về VND.
 *
 * Ký tự là `nullable|string|max:20` và KHÔNG giới hạn vào danh sách cố định:
 * người dùng có thể đặt "VND", "k", "₫"… tuỳ ý. `nullable` để payload thiếu
 * trường (caller chưa cấu hình ký tự) ghi NULL — phân biệt với chuỗi rỗng mà
 * frontend gửi khi người dùng CHỦ ĐỘNG xoá ký tự.
 *
 * KHÔNG có giá trị tiền nào trong request này: đơn vị/Ký tự CHỈ đổi cách hiển
 * thị, không bao giờ nhận số tiền để nhân/chia — dữ liệu luôn là VND.
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
            'money_unit_symbol' => ['nullable', 'string', 'max:20'],
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
            'money_unit_symbol.string' => 'Ký tự đại diện không hợp lệ.',
            'money_unit_symbol.max' => 'Ký tự đại diện tối đa 20 ký tự.',
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

    /**
     * Ký tự đại diện đã qua validation, trim hai đầu — NULL nếu trường vắng mặt.
     *
     * Đọc theo KEY CÓ MẶT hay không, không theo giá trị: middleware toàn cục
     * `ConvertEmptyStringsToNull` đã biến `""` gửi lên thành `null` trước khi vào
     * controller, nên phân biệt ý nghĩa chỉ dựa vào sự hiện diện của key:
     *
     *   - key KHÔNG có trong payload      => `NULL` (chưa cấu hình ⇒ cột NULL);
     *   - key có, giá trị `""`/`"  "`      => `""`  (chủ động bỏ suffix);
     *   - key có, giá trị `" k "`          => `"k"`.
     *
     * KHÔNG convert "" thành NULL và KHÔNG tự trả default — ý định của người
     * dùng do service resolve theo `resolveMoneyUnitSymbol()` khi hiển thị.
     */
    public function moneyUnitSymbol(): ?string
    {
        if (! array_key_exists('money_unit_symbol', $this->all())) {
            return null;
        }

        return trim((string) $this->input('money_unit_symbol'));
    }
}
