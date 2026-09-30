<?php

namespace App\Http\Requests\CreditCard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tạo policy version MỚI (N+1) cho thẻ đang có policy.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KHÔNG SỬA THẲNG VERSION ĐANG CHẠY
 * ---------------------------------------------------------------------------
 * Business rule của một version là BẤT BIẾN: giao dịch của kỳ cũ đã snapshot
 * version/tier/rule đó. Sửa thẳng sẽ làm cashback lịch sử không tái lập được.
 * Endpoint này chỉ tạo version kế tiếp; `PolicyService::createVersion()` lo phần
 * clone + chuyển version cũ sang `superseded`.
 *
 * `overrides` là phần CẤU HÌNH thay đổi, không phải toàn bộ cấu hình: version mới
 * được clone từ version hiện tại rồi áp override.
 */
class StorePolicyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date_format:Y-m-d'],

            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'min_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_total_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rounding_mode' => ['sometimes', Rule::in(['round', 'floor', 'ceil'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_from.required' => 'Vui lòng chọn ngày bắt đầu hiệu lực.',
            'effective_from.date_format' => 'Ngày bắt đầu hiệu lực phải có định dạng Y-m-d.',
            'name.required' => 'Vui lòng nhập tên chính sách.',
            'rounding_mode.in' => 'Cách làm tròn không hợp lệ.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        $overrides = [];

        foreach (['name', 'min_total_spend', 'max_cashback_total_per_period', 'rounding_mode', 'note'] as $field) {
            if ($this->has($field)) {
                $overrides[$field] = $this->input($field);
            }
        }

        return $overrides;
    }
}
