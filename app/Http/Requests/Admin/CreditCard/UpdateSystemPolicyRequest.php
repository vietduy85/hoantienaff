<?php

namespace App\Http\Requests\Admin\CreditCard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sửa METADATA của chính sách hệ thống (vỏ ngoài template, không đụng business rule).
 *
 * Muốn đổi cấu hình (bậc, tỷ lệ, cap) phải gọi `StoreSystemPolicyVersionRequest` để
 * tạo version mới — version cũ bất biến.
 */
class UpdateSystemPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Trạng thái không hợp lệ.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = [];

        if ($this->has('name')) {
            $meta['name'] = $this->input('name');
        }

        if ($this->has('description')) {
            $meta['description'] = $this->input('description');
        }

        if ($this->has('status')) {
            $meta['is_active'] = $this->input('status') === 'published';
        }

        return $meta;
    }
}
