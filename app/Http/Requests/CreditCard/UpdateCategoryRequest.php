<?php

namespace App\Http\Requests\CreditCard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sửa danh mục riêng.
 *
 * Cho phép `is_active` để ẩn/hiện — kể cả danh mục đã dùng (ẩn là thao tác an
 * toàn, không đụng dữ liệu lịch sử). Các field khác trên danh mục đã dùng sẽ bị
 * `CategoryService` chặn vì đổi nhãn sẽ làm sai lịch sử.
 */
class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên danh mục.',
            'is_active.boolean' => 'Trạng thái danh mục không hợp lệ.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['name', 'description', 'sort_order', 'is_active'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $field === 'name' && $this->input($field) !== null
                    ? trim((string) $this->input($field))
                    : $this->input($field);
            }
        }

        return $payload;
    }
}
