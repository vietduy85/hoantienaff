<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sửa giao dịch nhập tay. Tất cả field `sometimes` (PATCH một phần).
 *
 * KHÔNG cho đổi `user_card_id`: giao dịch không di chuyển giữa các thẻ.
 */
class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                // Phải là danh mục user được phép dùng; service kiểm tra lại.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $exists = Category::query()
                        ->selectableBy((int) $this->user()->id)
                        ->whereKey((int) $value)
                        ->exists();

                    if (! $exists) {
                        $fail('Danh mục không hợp lệ hoặc bạn không được sử dụng.');
                    }
                },
            ],
            'transaction_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'posted_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'amount' => ['sometimes', 'required', 'numeric', 'not_in:0'],
            'merchant' => ['sometimes', 'nullable', 'string', 'max:191'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_date.date_format' => 'Ngày giao dịch phải có định dạng Y-m-d.',
            'posted_date.date_format' => 'Ngày ghi nhận phải có định dạng Y-m-d.',
            'amount.not_in' => 'Số tiền giao dịch phải khác 0.',
            'amount.numeric' => 'Số tiền không hợp lệ.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['category_id', 'transaction_date', 'posted_date', 'amount', 'merchant', 'note'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        return $payload;
    }
}
