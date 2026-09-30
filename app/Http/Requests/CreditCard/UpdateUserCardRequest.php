<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\UserCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Sửa thẻ tín dụng.
 *
 * Mọi trường đều `sometimes` — chỉ gửi field muốn đổi. Service sẽ bỏ qua field
 * không có trong payload, nên PATCH một phần không làm mất dữ liệu.
 */
class UpdateUserCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'bank_id' => [
                'sometimes',
                'integer',
                // `Bank::class` ⇒ `creditcard.credit_card_banks` (xem StoreUserCardRequest).
                Rule::exists(Bank::class, 'id')->where('is_active', true),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'card_number_last4' => ['sometimes', 'nullable', 'digits:4'],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'statement_day' => ['sometimes', 'integer', 'between:1,31'],
            'payment_due_day' => ['sometimes', 'integer', 'between:1,31'],
            'spending_deadline_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'statement_date_basis' => ['sometimes', Rule::in([
                UserCard::BASIS_TRANSACTION_DATE,
                UserCard::BASIS_POSTED_DATE,
            ])],
            'opened_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Đóng / mở lại thẻ.
            'status' => ['sometimes', Rule::in([UserCard::STATUS_ACTIVE, UserCard::STATUS_INACTIVE])],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_id.exists' => 'Ngân hàng không tồn tại hoặc không còn được chọn.',
            'name.required' => 'Vui lòng nhập tên thẻ.',
            'card_number_last4.digits' => 'Số cuối thẻ phải đúng 4 chữ số.',
            'statement_day.between' => 'Ngày chốt kỳ phải từ 1 đến 31.',
            'payment_due_day.between' => 'Ngày đến hạn phải từ 1 đến 31.',
            'status.in' => 'Trạng thái thẻ không hợp lệ.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $fields = [
            'bank_id',
            'name',
            'card_number_last4',
            'credit_limit',
            'statement_day',
            'payment_due_day',
            'spending_deadline_day',
            'statement_date_basis',
            'opened_at',
            'sort_order',
            'note',
        ];

        $payload = [];

        foreach ($fields as $field) {
            if ($this->has($field)) {
                $payload[$field] = $field === 'name' && $this->input($field) !== null
                    ? trim((string) $this->input($field))
                    : $this->input($field);
            }
        }

        return $payload;
    }

    public function requestedStatus(): ?string
    {
        $status = $this->input('status');

        return is_string($status) ? $status : null;
    }

    /**
     * @return Collection<int, Bank>
     */
    public function availableBanks(): Collection
    {
        return Bank::query()->active()->orderBy('name')->get();
    }
}
