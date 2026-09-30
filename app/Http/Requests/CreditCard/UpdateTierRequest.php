<?php

namespace App\Http\Requests\CreditCard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sửa bậc chi tiêu (PATCH một phần).
 *
 * Không cho đổi `policy_id`: bậc không di chuyển giữa các version. Muốn cấu hình
 * ở version khác thì dùng `POST /bac/{tier}/nhan-ban` (clone sang version đích).
 */
class UpdateTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'min_total_spend' => ['sometimes', 'numeric', 'min:0'],
            'max_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên bậc.',
            'min_total_spend.min' => 'Ngưỡng chi tiêu không được âm.',
            'max_total_spend.min' => 'Ngưỡng chi tiêu tối đa không được âm.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['name', 'sort_order', 'min_total_spend', 'max_total_spend'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $field === 'name'
                    ? trim((string) $this->input($field))
                    : $this->input($field);
            }
        }

        return $payload;
    }
}
