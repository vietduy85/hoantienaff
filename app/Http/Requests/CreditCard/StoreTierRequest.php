<?php

namespace App\Http\Requests\CreditCard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tạo bậc chi tiêu trong một policy version.
 *
 * RETROACTIVE: bậc được chọn theo TỔNG chi tiêu cả kỳ. Không có trường
 * `application_mode` / `progressive` ở đây — cũng không có ở service, không có ở
 * DB. Thêm một cờ như vậy là mở đường cho kết quả không tái lập được.
 *
 * `min_total_spend <= max_total_spend` và khoảng không chồng lấn được
 * `TierService` kiểm (`assertBandIsSane` + `assertNoOverlappingBands`) vì đó là
 * quy tắc nghiệp vụ, không nhân bản ra FormRequest.
 */
class StoreTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'min_total_spend' => ['required', 'numeric', 'min:0'],
            'max_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên bậc.',
            'min_total_spend.required' => 'Vui lòng nhập ngưỡng chi tiêu tối thiểu.',
            'min_total_spend.min' => 'Ngưỡng chi tiêu không được âm.',
            'max_total_spend.min' => 'Ngưỡng chi tiêu tối đa không được âm.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'sort_order' => $this->input('sort_order'),
            'min_total_spend' => $this->input('min_total_spend'),
            'max_total_spend' => $this->input('max_total_spend'),
        ];
    }
}
