<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sửa quy tắc cashback (PATCH một phần).
 *
 * Không cho đổi `tier_id`: rule không di chuyển giữa các bậc. Muốn mang rule sang
 * bậc khác thì dùng `POST /quy-tac/{rule}/nhan-ban`.
 *
 * Giống `StoreCategoryRuleRequest`: KHÔNG có trường cashback kết quả.
 */
class UpdateCategoryRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'required', 'integer', $this->categoryRule()],
            'cashback_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'spend_from' => ['sometimes', 'numeric', 'min:0'],
            'spend_to' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_per_transaction' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_per_category_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_transaction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_enabled' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Vui lòng chọn danh mục.',
            'cashback_percent.required' => 'Vui lòng nhập tỷ lệ hoàn tiền.',
            'cashback_percent.max' => 'Tỷ lệ hoàn tiền không được vượt quá 100%.',
            'spend_from.min' => 'Ngưỡng chi tiêu không được âm.',
        ];
    }

    private function categoryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_numeric($value)) {
                $fail('Danh mục không hợp lệ.');

                return;
            }

            $exists = Category::query()
                ->selectableBy((int) $this->user()->id)
                ->whereKey((int) $value)
                ->exists();

            if (! $exists) {
                $fail('Danh mục không hợp lệ hoặc bạn không được sử dụng.');
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach ([
            'category_id',
            'cashback_percent',
            'name',
            'sort_order',
            'spend_from',
            'spend_to',
            'max_cashback_per_transaction',
            'max_cashback_per_category_per_period',
            'min_transaction_amount',
            'is_enabled',
            'note',
        ] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        return $payload;
    }
}
