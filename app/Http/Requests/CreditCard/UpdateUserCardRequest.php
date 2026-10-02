<?php

namespace App\Http\Requests\CreditCard;

use App\Http\Requests\CreditCard\Concerns\ValidatesCardPolicySelection;
use App\Models\CreditCard\Bank;
use App\Models\CreditCard\UserCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Sửa thẻ tín dụng.
 *
 * Mọi trường đều `sometimes` — chỉ gửi field muốn đổi. Service sẽ bỏ qua field
 * không có trong payload, nên PATCH một phần không làm mất dữ liệu.
 *
 * `policy` cũng `sometimes`: không gửi ⇒ không đụng policy. Gửi `policy.tiers`
 * ⇒ sửa policy RIÊNG của thẻ bằng cách tạo version mới (không sửa version cũ đã
 * có giao dịch). Gửi `policy.template_id` ⇒ clone lại từ mẫu.
 */
class UpdateUserCardRequest extends FormRequest
{
    use ValidatesCardPolicySelection;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return array_merge($this->policySelectionRules('sometimes'), [
            'bank_id' => [
                'sometimes',
                // OPTIONAL (migration 22). Gửi `null` = BỎ chọn ngân hàng, khác với
                // không gửi field nào (giữ nguyên ngân hàng hiện tại). `payload()`
                // dùng `has()` nên phân biệt được hai trường hợp này.
                'nullable',
                'integer',
                // `Bank::class` ⇒ `creditcard.credit_card_banks` (xem StoreUserCardRequest).
                Rule::exists(Bank::class, 'id')->where('is_active', true),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'card_number_last4' => ['sometimes', 'nullable', 'digits:4'],
            // `max` khớp sức chứa cột `decimal(16,2)` — xem `StoreUserCardRequest`.
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
            'desired_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'statement_day' => ['sometimes', 'integer', 'between:1,31'],
            'payment_due_day' => ['sometimes', 'integer', 'between:1,31'],
            'spending_deadline_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'statement_date_basis' => ['sometimes', Rule::in([
                UserCard::BASIS_TRANSACTION_DATE,
                UserCard::BASIS_POSTED_DATE,
            ])],
            'opened_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Chỉ `start`; `statement_period_end` do server tính (xem StoreUserCardRequest).
            'statement_period_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'promotion_info' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Đóng / mở lại thẻ.
            'status' => ['sometimes', Rule::in([UserCard::STATUS_ACTIVE, UserCard::STATUS_INACTIVE])],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $this->assertCardPolicyTargets($validator);
    }

    public function messages(): array
    {
        return array_merge($this->policySelectionMessages(), [
            'bank_id.integer' => 'Ngân hàng không hợp lệ.',
            'bank_id.exists' => 'Ngân hàng không tồn tại hoặc không còn được chọn.',
            'name.required' => 'Vui lòng nhập tên thẻ.',
            'card_number_last4.digits' => 'Số cuối thẻ phải đúng 4 chữ số.',
            'credit_limit.min' => 'Hạn mức tín dụng không được âm.',
            'credit_limit.max' => 'Hạn mức tín dụng vượt quá giới hạn lưu trữ.',
            'statement_day.between' => 'Ngày chốt kỳ phải từ 1 đến 31.',
            'payment_due_day.between' => 'Ngày đến hạn phải từ 1 đến 31.',
            'desired_spend.min' => 'Số tiền mong muốn chi không được âm.',
            'statement_period_start.date_format' => 'Ngày bắt đầu kỳ sao kê không hợp lệ.',
            'promotion_info.max' => 'Thông tin khuyến mãi tối đa 2.000 ký tự.',
            'status.in' => 'Trạng thái thẻ không hợp lệ.',
        ]);
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
            'desired_spend',
            'statement_day',
            'payment_due_day',
            'spending_deadline_day',
            'statement_date_basis',
            'opened_at',
            'statement_period_start',
            'sort_order',
            'note',
            'promotion_info',
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
