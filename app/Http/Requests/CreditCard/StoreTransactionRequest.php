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
 *
 * ---------------------------------------------------------------------------
 * NGÀY GIAO DỊCH TỰ DO — KHÔNG GIỚI HẠN THEO KỲ SAO KẾ
 * ---------------------------------------------------------------------------
 * Người dùng chọn BẤT KỲ ngày nào: quá khứ, hiện tại hay tương lai. Ở đây chỉ
 * kiểm tra có mặt và đúng định dạng `Y-m-d`.
 *
 * Trước đây có thêm closure rule chặn ngày nằm ngoài kỳ sao kê hiện tại
 * (`ValidatesTransactionDateInCurrentPeriod`). Bỏ đi vì `statement_period_id`
 * KHÔNG phải thứ người dùng chọn: `StatementPeriodService::resolveForTransaction()`
 * suy ra kỳ từ `statement_day` cho BẤT KỲ ngày nào. Luồng import Excel
 * (`TransactionImportService`) vốn đã không có chặn này, nên thao tác nhập tay
 * nay khớp với nó.
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
            'transaction_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'posted_date' => ['nullable', 'date_format:Y-m-d'],
            // Ô nhập tay ghi CHI TIÊU nên chỉ nhận số dương: `gt:0` chặn cả 0 lẫn
            // âm. Hoàn tiền (số âm) vẫn vào được qua luồng IMPORT Excel
            // (`TransactionImportService`, có test riêng cho số âm) — không mở
            // lại đường nhập tay số âm trên UI.
            'amount' => ['required', 'numeric', 'gt:0'],
            'merchant' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:1000'],
            // Ý định của nút bấm: `save_and_close` (mặc định, giữ luồng cũ) hay
            // `save_and_continue` (giữ ngày + thẻ, xoá tiền/danh mục/ghi chú).
            // KHÔNG ảnh hưởng dữ liệu lưu — chỉ để server trả lại cho client.
            'save_intent' => ['nullable', 'string', Rule::in(['save_and_continue', 'save_and_close'])],
            // Xác nhận "vẫn lưu" khi client đã thấy cảnh báo trùng. Là token HMAC
            // của ĐÚNG tập giao dịch nghi trùng hiện tại; server so lại.
            'duplicate_ack' => ['nullable', 'string', 'max:255'],
            // Mã thao tác do client sinh MỘT LẦN khi mở form (đổi sau mỗi lần lưu
            // thành công). Cùng `submission_id` + cùng payload gửi hai lần (double
            // submit, retry mạng) chỉ tạo ĐÚNG một giao dịch.
            'submission_id' => ['nullable', 'string', 'max:100'],
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

    /**
     * Ý định lưu của nút bấm. Giá trị lạ/rỗng rơi về `save_and_close` — luồng cũ.
     */
    public function saveIntent(): string
    {
        return $this->input('save_intent') === 'save_and_continue'
            ? 'save_and_continue'
            : 'save_and_close';
    }

    /** Token xác nhận trùng (rỗng ⇒ chưa xác nhận). */
    public function duplicateAck(): ?string
    {
        $ack = $this->input('duplicate_ack');

        return is_string($ack) && $ack !== '' ? $ack : null;
    }

    /** Mã thao tác chống double-submit (rỗng ⇒ không dùng). */
    public function submissionId(): ?string
    {
        $id = $this->input('submission_id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
