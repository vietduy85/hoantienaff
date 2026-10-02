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
 * Thêm thẻ tín dụng.
 *
 * KHÔNG có `product_id`: thẻ = `bank_id` + `name` (xem migration 000011).
 * KHÔNG có trường cashback: cashback luôn do hệ thống tính.
 *
 * `policy` (tuỳ chọn) đi kèm để thẻ và bản policy clone được lưu trong cùng một
 * transaction — xem `ValidatesCardPolicySelection`.
 */
class StoreUserCardRequest extends FormRequest
{
    use ValidatesCardPolicySelection;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return array_merge($this->policySelectionRules(), [
            'bank_id' => [
                // OPTIONAL (migration 22): người dùng có thể khai thẻ trước khi
                // biết ngân hàng phát hành. `NULL` = "chưa biết", không phải lỗi.
                'nullable',
                'integer',
                // Truyền `Bank::class` (không phải tên bảng) để Laravel dựng
                // thành `creditcard.credit_card_banks` — bảng này nằm ở
                // connection `creditcard`, không phải connection mặc định.
                // Bank phải tồn tại VÀ đang active: bank đã ẩn thì không gán cho
                // thẻ mới, nhưng thẻ cũ vẫn đọc được (xem `BankService`).
                Rule::exists(Bank::class, 'id')->where('is_active', true),
            ],
            'name' => ['required', 'string', 'max:150'],
            'card_number_last4' => ['nullable', 'digits:4'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            // Số tiền user MONG MUỐN chi — khác hạn mức `credit_limit` của ngân hàng.
            'desired_spend' => ['nullable', 'numeric', 'min:0'],
            'statement_day' => ['nullable', 'integer', 'between:1,31'],
            'payment_due_day' => ['nullable', 'integer', 'between:1,31'],
            'spending_deadline_day' => ['nullable', 'integer', 'between:1,31'],
            'statement_date_basis' => ['nullable', Rule::in([
                UserCard::BASIS_TRANSACTION_DATE,
                UserCard::BASIS_POSTED_DATE,
            ])],
            'opened_at' => ['nullable', 'date_format:Y-m-d'],
            // Kỳ sao kê: client CHỈ gửi `start`. `statement_period_end` cố ý KHÔNG
            // có trong rules — server luôn tính từ start (UserCardService) nên không
            // thể bị client gửi cặp ngày lệch nhau.
            'statement_period_start' => ['nullable', 'date_format:Y-m-d'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'promotion_info' => ['nullable', 'string', 'max:2000'],
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
            'name.max' => 'Tên thẻ không được vượt quá 150 ký tự.',
            'card_number_last4.digits' => 'Số cuối thẻ phải đúng 4 chữ số.',
            'credit_limit.min' => 'Hạn mức tín dụng không được âm.',
            'statement_day.between' => 'Ngày chốt kỳ phải từ 1 đến 31.',
            'payment_due_day.between' => 'Ngày đến hạn phải từ 1 đến 31.',
            'spending_deadline_day.between' => 'Ngày nên chi tiêu phải từ 1 đến 31.',
            'statement_date_basis.in' => 'Cách tính ngày chốt kỳ không hợp lệ.',
            'desired_spend.min' => 'Số tiền mong muốn chi không được âm.',
            'statement_period_start.date_format' => 'Ngày bắt đầu kỳ sao kê không hợp lệ.',
            'promotion_info.max' => 'Thông tin khuyến mãi tối đa 2.000 ký tự.',
        ]);
    }

    /**
     * Chuẩn hoá trước khi vào service: bỏ khoảng trắng, ép số.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_filter([
            // KHÔNG dùng `$this->integer('bank_id')`: khi field vắng mặt nó trả `0`
            // chứ không phải `null`, và `array_filter` giữ lại `0` (0 !== null) ⇒
            // service nhận "ngân hàng #0". Rỗng/`null` phải thành `null` thật.
            'bank_id' => $this->optionalBankId(),
            'name' => trim((string) $this->input('name')),
            'card_number_last4' => $this->input('card_number_last4'),
            'credit_limit' => $this->input('credit_limit'),
            'desired_spend' => $this->input('desired_spend'),
            'statement_day' => $this->input('statement_day'),
            'payment_due_day' => $this->input('payment_due_day'),
            'spending_deadline_day' => $this->input('spending_deadline_day'),
            'statement_date_basis' => $this->input('statement_date_basis'),
            'opened_at' => $this->input('opened_at'),
            'statement_period_start' => $this->input('statement_period_start'),
            'sort_order' => $this->input('sort_order'),
            'note' => $this->input('note'),
            'promotion_info' => $this->input('promotion_info'),
        ], fn ($value) => $value !== null);
    }

    /**
     * `bank_id` sau validation, hoặc `null` nghĩa là chưa chọn ngân hàng.
     */
    private function optionalBankId(): ?int
    {
        $value = $this->input('bank_id');

        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * Danh sách ngân hàng đang active — dùng cho dropdown.
     */
    public function availableBanks(): Collection
    {
        return Bank::query()->active()->orderBy('name')->get();
    }
}
