<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\UserCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Thêm thẻ tín dụng.
 *
 * KHÔNG có `product_id`: thẻ = `bank_id` + `name` (xem migration 000011).
 * KHÔNG có trường cashback: cashback luôn do hệ thống tính.
 */
class StoreUserCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'bank_id' => [
                'required',
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
            'statement_day' => ['nullable', 'integer', 'between:1,31'],
            'payment_due_day' => ['nullable', 'integer', 'between:1,31'],
            'spending_deadline_day' => ['nullable', 'integer', 'between:1,31'],
            'statement_date_basis' => ['nullable', Rule::in([
                UserCard::BASIS_TRANSACTION_DATE,
                UserCard::BASIS_POSTED_DATE,
            ])],
            'opened_at' => ['nullable', 'date_format:Y-m-d'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_id.required' => 'Vui lòng chọn ngân hàng.',
            'bank_id.exists' => 'Ngân hàng không tồn tại hoặc không còn được chọn.',
            'name.required' => 'Vui lòng nhập tên thẻ.',
            'name.max' => 'Tên thẻ không được vượt quá 150 ký tự.',
            'card_number_last4.digits' => 'Số cuối thẻ phải đúng 4 chữ số.',
            'credit_limit.min' => 'Hạn mức tín dụng không được âm.',
            'statement_day.between' => 'Ngày chốt kỳ phải từ 1 đến 31.',
            'payment_due_day.between' => 'Ngày đến hạn phải từ 1 đến 31.',
            'spending_deadline_day.between' => 'Ngày nên chi tiêu phải từ 1 đến 31.',
            'statement_date_basis.in' => 'Cách tính ngày chốt kỳ không hợp lệ.',
        ];
    }

    /**
     * Chuẩn hoá trước khi vào service: bỏ khoảng trắng, ép số.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_filter([
            'bank_id' => $this->integer('bank_id'),
            'name' => trim((string) $this->input('name')),
            'card_number_last4' => $this->input('card_number_last4'),
            'credit_limit' => $this->input('credit_limit'),
            'statement_day' => $this->input('statement_day'),
            'payment_due_day' => $this->input('payment_due_day'),
            'spending_deadline_day' => $this->input('spending_deadline_day'),
            'statement_date_basis' => $this->input('statement_date_basis'),
            'opened_at' => $this->input('opened_at'),
            'sort_order' => $this->input('sort_order'),
            'note' => $this->input('note'),
        ], fn ($value) => $value !== null);
    }

    /**
     * Danh sách ngân hàng đang active — dùng cho dropdown.
     */
    public function availableBanks(): Collection
    {
        return Bank::query()->active()->orderBy('name')->get();
    }
}
