<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Models\CreditCard\Category;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tạo Combo hệ thống.
 *
 * Lưu ý: KHÔNG validate `slug` ở đây. `CategoryComboService` là nơi quyết định
 * slug (tự sinh từ `name` và tự thêm hậu tố `-2`, `-3` khi trùng trong scope
 * system) — nếu request cũng ép slug thì sẽ có hai nơi quyết định cùng một thứ
 * và UI có thể gửi slug không khớp với thứ service thực sự lưu.
 */
class StoreSystemComboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermissionTo('credit-cards.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category_ids' => ['required', 'array', 'min:1', 'distinct'],
            'category_ids.*' => ['required', 'integer', $this->systemActiveCategory()],
        ];
    }

    private function systemActiveCategory(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_numeric($value)) {
                $fail('Danh mục không hợp lệ.');

                return;
            }

            $exists = Category::query()
                ->system()
                ->active()
                ->whereKey((int) $value)
                ->exists();
            if (! $exists) {
                $fail('Combo hệ thống chỉ được gồm danh mục hệ thống đang hoạt động.');
            }
        };
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên combo.',
            'category_ids.required' => 'Combo phải có ít nhất một danh mục.',
            'category_ids.min' => 'Combo phải có ít nhất một danh mục.',
            'category_ids.distinct' => 'Mỗi danh mục chỉ được xuất hiện một lần trong combo.',
        ];
    }

    /**
     * @return array{name:string, description:string|null, category_ids:array<int>}
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'description' => ($d = $this->input('description')) !== null && trim((string) $d) !== ''
                ? trim((string) $d)
                : null,
            'category_ids' => $this->categoryIds(),
        ];
    }

    /**
     * @return array<int>
     */
    private function categoryIds(): array
    {
        $ids = [];

        foreach ((array) $this->input('category_ids', []) as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $ids[] = (int) $value;
        }

        return array_values($ids);
    }
}
