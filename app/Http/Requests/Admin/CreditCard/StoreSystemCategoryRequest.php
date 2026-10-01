<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Models\CreditCard\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Tạo danh mục HỆ THỐNG (master data).
 *
 * Chỉ admin có `credit-cards.manage` mới gọi được (middleware permission).
 * `slug` bắt buộc, được chuẩn hoá kebab-case ở service và phải duy nhất trong
 * scope system (unique index (scope, owner_user_id, slug)).
 */
class StoreSystemCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermissionTo('credit-cards.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'required',
                'string',
                'max:150',
                function (string $attribute, $value, $fail): void {
                    $slug = Str::slug((string) $value);

                    if ($slug === '') {
                        $fail('Slug không hợp lệ.');

                        return;
                    }

                    $exists = Category::query()
                        ->system()
                        ->where('slug', $slug)
                        ->exists();

                    if ($exists) {
                        $fail(sprintf('Slug "%s" đã tồn tại trong danh mục hệ thống.', $slug));
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên danh mục.',
            'name.max' => 'Tên danh mục không được vượt quá 150 ký tự.',
            'slug.required' => 'Vui lòng nhập slug danh mục.',
            'slug.max' => 'Slug không được vượt quá 150 ký tự.',
            'description.max' => 'Mô tả không được vượt quá 1000 ký tự.',
        ];
    }

    /**
     * @return array{name:string, slug:string, description?:string|null}
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'slug' => trim((string) $this->input('slug')),
            'description' => $this->input('description'),
        ];
    }
}
