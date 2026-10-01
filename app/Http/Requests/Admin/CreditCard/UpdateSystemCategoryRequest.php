<?php

namespace App\Http\Requests\Admin\CreditCard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Đổi tên danh mục HỆ THỐNG.
 *
 * CHỈ sửa `name`/`description` của ĐÚNG bản ghi `Category` đã có. `category_id`
 * là định danh nên KHÔNG đổi; không tạo danh mục mới; không tạo version policy
 * mới; không snapshot tên. Slug giữ nguyên (là khoá ánh xạ `CategoryIcon`).
 */
class UpdateSystemCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermissionTo('credit-cards.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên danh mục.',
            'name.max' => 'Tên danh mục không được vượt quá 150 ký tự.',
            'description.max' => 'Mô tả không được vượt quá 1000 ký tự.',
        ];
    }

    /**
     * @return array{name?:string, description?:string|null}
     */
    public function payload(): array
    {
        $payload = [];

        if ($this->has('name')) {
            $payload['name'] = trim((string) $this->input('name'));
        }

        if ($this->has('description')) {
            $payload['description'] = $this->input('description');
        }

        return $payload;
    }
}
