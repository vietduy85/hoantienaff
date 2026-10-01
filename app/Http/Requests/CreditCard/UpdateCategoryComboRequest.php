<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Category;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cập nhật combo riêng của user.
 */
class UpdateCategoryComboRequest extends FormRequest
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
            'category_ids' => ['required', 'array', 'min:1', 'distinct'],
            'category_ids.*' => ['required', 'integer', $this->selectableCategory($this->user()?->id)],
        ];
    }

    private function selectableCategory(?int $userId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($userId): void {
            if (! is_numeric($value)) {
                $fail('Danh mục không hợp lệ.');

                return;
            }

            $exists = Category::query()
                ->active()
                ->whereKey((int) $value)
                ->where(function ($inner) use ($userId): void {
                    $inner->where('scope', Category::SCOPE_SYSTEM);
                    if ($userId !== null) {
                        $inner->orWhere(function ($own) use ($userId): void {
                            $own->where('scope', Category::SCOPE_USER)
                                ->where('owner_user_id', $userId);
                        });
                    }
                })
                ->exists();

            if (! $exists) {
                $fail('Combo chỉ được gồm danh mục hệ thống và danh mục của chính bạn (đang hoạt động).');
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
        $description = $this->input('description');

        return [
            'name' => trim((string) $this->input('name')),
            'description' => ($description !== null && trim((string) $description) !== '')
                ? trim((string) $description)
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
