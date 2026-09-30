<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\UserCard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Thêm giao dịch NHẬP TAY.
 *
 * KHÔNG có `cashback_amount` / `cashback_percent`: cashback do hệ thống tính.
 * Cột Excel chứa cashback cũng bị từ chối khi import (xem
 * `TransactionSheetReader`) — ở đây cũng vậy để không mở đường nhập tay.
 */
class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = (int) $this->user()->id;

        return [
            // `Rule::exists` với MODEL CLASS (không phải tên bảng) để Laravel lấy
            // đúng connection `creditcard`; scope `user_id` ⇒ thẻ của người khác
            // và thẻ không tồn tại trả CÙNG một lỗi ⇒ không lộ thông tin tồn tại.
            // Thẻ đã đóng vẫn qua được validation này: đó là xung đột trạng thái
            // (409) nên để `CreditCardTransactionService` trả về, không phải lỗi field.
            'user_card_id' => [
                'required',
                'integer',
                Rule::exists(UserCard::class, 'id')->where(
                    fn ($query) => $query->where('user_id', $userId)
                ),
            ],
            'category_id' => [
                'nullable',
                'integer',
                // `selectableBy` = danh mục hệ thống đang bật + danh mục riêng đang
                // bật của chính user — đúng điều kiện mà service sẽ kiểm lại.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $exists = Category::query()
                        ->selectableBy((int) $this->user()->id)
                        ->whereKey((int) $value)
                        ->exists();

                    if (! $exists) {
                        $fail('Danh mục không hợp lệ hoặc bạn không được sử dụng.');
                    }
                },
            ],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'posted_date' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'merchant' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_card_id.required' => 'Vui lòng chọn thẻ tín dụng.',
            'user_card_id.exists' => 'Thẻ tín dụng không hợp lệ hoặc không thuộc về bạn.',
            'category_id.integer' => 'Danh mục không hợp lệ.',
            'transaction_date.required' => 'Vui lòng nhập ngày giao dịch.',
            'transaction_date.date_format' => 'Ngày giao dịch phải có định dạng Y-m-d.',
            'posted_date.date_format' => 'Ngày ghi nhận phải có định dạng Y-m-d.',
            'amount.required' => 'Vui lòng nhập số tiền.',
            'amount.not_in' => 'Số tiền giao dịch phải khác 0.',
            'amount.numeric' => 'Số tiền không hợp lệ.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'category_id' => $this->input('category_id'),
            'transaction_date' => $this->input('transaction_date'),
            'posted_date' => $this->input('posted_date'),
            'amount' => $this->input('amount'),
            'merchant' => $this->input('merchant'),
            'note' => $this->input('note'),
        ];
    }
}
