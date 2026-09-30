<?php

namespace App\Http\Requests\CreditCard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tạo danh mục RIÊNG của user.
 *
 * KHÔNG có `scope` / `owner_user_id` trong rules: user không tạo được danh mục hệ
 * thống. `CategoryService::createUserCategory()` tự hard-code `scope = user` và
 * `owner_user_id = user đang đăng nhập`.
 */
class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên danh mục.',
            'name.max' => 'Tên danh mục không được vượt quá 150 ký tự.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'description' => $this->input('description'),
            'sort_order' => $this->input('sort_order'),
        ];
    }
}
