<?php

namespace App\Http\Requests\Admin\CreditCard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sắp xếp lại danh mục hệ thống.
 *
 * `ordered_ids` phải là danh sách đúng và đủ id của MỌI danh mục hệ thống;
 * `SystemCategoryService::reorder()` kiểm tra đầy đủ và chuẩn hoá `sort_order`
 * thành 1..N trong một transaction.
 */
class ReorderSystemCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermissionTo('credit-cards.manage');
    }

    public function rules(): array
    {
        return [
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'ordered_ids.required' => 'Vui lòng gửi danh sách thứ tự mới.',
            'ordered_ids.array' => 'Thứ tự không hợp lệ.',
            'ordered_ids.min' => 'Danh sách thứ tự không được rỗng.',
            'ordered_ids.*.integer' => 'Thứ tự phải gồm các id hợp lệ.',
        ];
    }

    /**
     * @return array{ordered_ids: array<int, int>}
     */
    public function payload(): array
    {
        return [
            'ordered_ids' => array_map('intval', $this->input('ordered_ids', [])),
        ];
    }
}
