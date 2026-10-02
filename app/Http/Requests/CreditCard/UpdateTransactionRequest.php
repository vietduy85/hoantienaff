<?php

namespace App\Http\Requests\CreditCard;

use App\Http\Requests\CreditCard\Concerns\ValidatesTransactionDateInCurrentPeriod;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sửa giao dịch nhập tay. Tất cả field `sometimes` (PATCH một phần).
 *
 * KHÔNG cho đổi `user_card_id`: giao dịch không di chuyển giữa các thẻ.
 *
 * ---------------------------------------------------------------------------
 * NGÀY VẪN PHẢI THUỘC KỲ HIỆN TẠI
 * ---------------------------------------------------------------------------
 * Sửa cũng dùng chung rule với thêm mới (`ValidatesTransactionDateInCurrentPeriod`)
 * và lấy thẻ từ chính giao dịch, không từ payload — nên không có đường vừa sửa ngày
 * vừa đổi thẻ để lách rule. Lưu ý: nếu chỉ sửa SỐ TIỀN/DANH MỤC/GHI CHÚ mà
 * không gửi `transaction_date` thì rule không chạy, đúng như PATCH một phần.
 */
class UpdateTransactionRequest extends FormRequest
{
    use ValidatesTransactionDateInCurrentPeriod;

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
            'transaction_date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                $this->transactionDateWithinCurrentPeriod(),
            ],
            'posted_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Sửa tay cũng là chi tiêu ⇒ số dương, khớp `StoreTransactionRequest`.
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'merchant' => ['sometimes', 'nullable', 'string', 'max:191'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_date.required' => 'Vui lòng chọn ngày giao dịch.',
            'transaction_date.date_format' => 'Ngày giao dịch phải có định dạng Y-m-d.',
            'posted_date.date_format' => 'Ngày ghi nhận phải có định dạng Y-m-d.',
            'amount.gt' => 'Số tiền giao dịch phải lớn hơn 0.',
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

    protected function cardForTransactionDateValidation(): ?UserCard
    {
        $transaction = $this->route('transaction');

        if (! is_numeric($transaction)) {
            return null;
        }

        // Chỉ cần thẻ để tính ranh giới kỳ — không nạp quan hệ, không kiểm tra quyền
        // ở đây (Policy lo việc đó trước khi service chạy).
        return Transaction::query()
            ->select('id', 'user_card_id')
            ->find((int) $transaction)
            ?->userCard;
    }
}
