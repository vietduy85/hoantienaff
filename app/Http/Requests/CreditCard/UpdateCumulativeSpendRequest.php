<?php

namespace App\Http\Requests\CreditCard;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cập nhật MỐC CHI TIÊU LŨY KẾ KỲ TRƯỚC của một thẻ (tab "Phí thường niên và chi
 * tiêu tích lũy" ở trang Sao kê).
 *
 * ---------------------------------------------------------------------------
 * CHỈ NHẬN HAI TRƯỜNG CỦA MỐC
 * ---------------------------------------------------------------------------
 * Đây là endpoint HẸP: người dùng chỉ khai mốc lũy kế + ghi chú. NGÀY của mốc
 * KHÔNG nhận từ input — server tự suy từ ngày chốt sao kê của kỳ trước của thẻ
 * (`CreditCardCumulativeSpendService`), nên client không thể chọn/ghi đè ngày.
 * Các cột phí thường niên (`annual_fee`, `annual_fee_waiver_spend`,
 * `next_annual_fee_date`) KHÔNG nhận ở đây — chúng thuộc form Thêm/Sửa thẻ ở Quản
 * lý thẻ. Tách endpoint để một lần khai mốc không âm thầm ghi đè cấu hình thẻ.
 *
 * ---------------------------------------------------------------------------
 * MỐC LÀ BẮT BUỘC
 * ---------------------------------------------------------------------------
 * Khác các cột phí thường niên (nullable), ở đây người dùng bấm "Cập nhật mốc" là
 * hành động chủ đích ⇒ thiếu mốc là lỗi nhập liệu (422), không phải "chưa khai".
 * Muốn "chưa khai" thì không bấm. Service vẫn kiểm tra lại phòng thủ.
 */
class UpdateCumulativeSpendRequest extends FormRequest
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
            'cumulative_spend_baseline' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            'cumulative_spend_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cumulative_spend_baseline.required' => 'Vui lòng nhập chi tiêu lũy kế tính đến hết kỳ trước.',
            'cumulative_spend_baseline.numeric' => 'Chi tiêu lũy kế tính đến hết kỳ trước không hợp lệ.',
            'cumulative_spend_baseline.min' => 'Chi tiêu lũy kế tính đến hết kỳ trước không được âm.',
            'cumulative_spend_baseline.max' => 'Chi tiêu lũy kế tính đến hết kỳ trước quá lớn.',
            'cumulative_spend_note.max' => 'Ghi chú không được dài quá 1000 ký tự.',
        ];
    }

    /**
     * Dữ liệu gửi vào service — CHỈ hai trường của mốc.
     *
     * KHÔNG có `cumulative_spend_baseline_date`: ngày do service suy từ kỳ sao kê.
     * Nếu client có gửi kèm ngày, nó bị bỏ qua (không nằm trong payload).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'cumulative_spend_baseline' => $this->input('cumulative_spend_baseline'),
            'cumulative_spend_note' => $this->input('cumulative_spend_note'),
        ];
    }
}
