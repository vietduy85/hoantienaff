<?php

namespace App\Http\Requests\CreditCard;

use App\Http\Requests\CreditCard\Concerns\ValidatesTransactionDateInCurrentPeriod;
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
 *
 * ---------------------------------------------------------------------------
 * NGÀY PHẢI THUỘC KỲ SAO KẾ HIỆN TẠI
 * ---------------------------------------------------------------------------
 * Người dùng CHỌN ngày (UI mặc định hôm nay) nhưng ngày đó không được nằm ngoài
 * kỳ sao kê đang mở của thẻ: nếu không, giao dịch rơi sang kỳ khác và "Tổng
 * quan" — vốn chỉ đọc kỳ hiện tại — nhảy số bất ngờ.
 *
 * Ranh giới do `StatementPeriodService::boundariesForDate()` tính từ
 * `statement_day`: CHỈ tính toán, không truy vấn, không tạo bản ghi. Nên thẻ chưa
 * có kỳ nào trong DB vẫn validate được và việc kiểm tra không sinh ra kỳ mới; kỳ
 * chỉ được tạo khi giao dịch thật sự lưu.
 */
class StoreTransactionRequest extends FormRequest
{
    use ValidatesTransactionDateInCurrentPeriod;

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
            'transaction_date' => [
                'required',
                'date_format:Y-m-d',
                $this->transactionDateWithinCurrentPeriod(),
            ],
            'posted_date' => ['nullable', 'date_format:Y-m-d'],
            // Ô nhập tay ghi CHI TIÊU nên chỉ nhận số dương: `gt:0` chặn cả 0 lẫn
            // âm. Hoàn tiền (số âm) vẫn vào được qua luồng IMPORT Excel
            // (`TransactionImportService`, có test riêng cho số âm) — không mở
            // lại đường nhập tay số âm trên UI.
            'amount' => ['required', 'numeric', 'gt:0'],
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
            'transaction_date.required' => 'Vui lòng chọn ngày giao dịch.',
            'transaction_date.date_format' => 'Ngày giao dịch phải có định dạng Y-m-d.',
            'posted_date.date_format' => 'Ngày ghi nhận phải có định dạng Y-m-d.',
            'amount.required' => 'Vui lòng nhập số tiền.',
            'amount.gt' => 'Số tiền giao dịch phải lớn hơn 0.',
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

    protected function cardForTransactionDateValidation(): ?UserCard
    {
        $cardId = $this->input('user_card_id');

        if (! is_numeric($cardId)) {
            return null;
        }

        return UserCard::query()
            ->ownedBy((int) $this->user()->id)
            ->find((int) $cardId);
    }
}
