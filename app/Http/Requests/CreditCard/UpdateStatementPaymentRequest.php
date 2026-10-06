<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Đổi TRẠNG THÁI THANH TOÁN của một dòng sao kê đã có.
 *
 * ---------------------------------------------------------------------------
 * CHỈ NHẬN `payment_status` — KHÔNG CÒN GÌ NỮA
 * ---------------------------------------------------------------------------
 * Trước đây request này nhận thêm `payment_reminder_enabled` +
 * `payment_reminder_days`. Cả hai đã chuyển lên thiết lập chung của người dùng
 * (`UpdateCreditCardSettingRequest`), nên ở đây chỉ còn trạng thái của KỲ.
 *
 * Việc tách là bắt buộc, không phải sở thích: nếu ô nhắc ngày nằm tiếp tục trong
 * payload của kỳ thì giao diện sẽ có nút sửa nhắc ở từng dòng, và lưu kỳ này sẽ
 * âm thầm ghi đè thiết lập chung của cả những kỳ khác.
 *
 * ---------------------------------------------------------------------------
 * TÁCH RIÊNG `StoreStatementRequest`, KHÔNG GỘP VÀO
 * ---------------------------------------------------------------------------
 * Trạng thái thanh toán thuộc về "đã có sao kê", còn `actual_*` thuộc về "nhập bảng
 * kê". Gộp chung sẽ buộc mọi lần sửa tiền phải gửi kèm trạng thái thanh toán, và
 * một thao tác đánh dấu "đã trả" sẽ phải gửi lại số tiền — đúng cách rất dễ làm
 * lệch tiền thật.
 *
 * ---------------------------------------------------------------------------
 * KHÔNG NHẬN `user_card_id` / `statement_period_id`
 * ---------------------------------------------------------------------------
 * Chúng thậm chí không có trong `rules()` nên bị bỏ qua; thẻ và kỳ đều resolve từ
 * chính dòng sao kê trên URL. Không tin danh tính gửi từ client là nguyên tắc
 * chung của module (xem `StoreStatementRequest::payload()`).
 */
class UpdateStatementPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_status' => ['required', Rule::in([
                CreditCardStatement::PAYMENT_STATUS_UNPAID,
                CreditCardStatement::PAYMENT_STATUS_PAID,
            ])],
            // TUỲ CHỌN, chỉ dùng bởi endpoint theo KỲ (`updatePaymentForPeriod()`).
            // Endpoint theo DÒNG bỏ qua nó và lấy kỳ từ chính dòng sao kê — nhờ vậy
            // client không thể đổi trạng thái kỳ này sang kỳ khác chỉ bằng cách gửi
            // thêm một ngày.
            'period_start' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_status.required' => 'Vui lòng chọn trạng thái thanh toán.',
            'payment_status.in' => 'Trạng thái thanh toán không hợp lệ.',
            'period_start.date_format' => 'Kỳ sao kê không hợp lệ.',
        ];
    }

    /**
     * Dữ liệu gửi vào service — CHỈ trạng thái.
     *
     * Không có `actual_spend`/`actual_reward`/`closing_balance` ở đây: đổi trạng
     * thái thanh toán không được tiện tay sửa số tiền.
     *
     * `period_start` cũng không nằm trong payload: nó KHÔNG phải tiền, và chỉ có
     * tác dụng chỉ định kỳ đích — truyền riêng cho service để không bao giờ bị ghi
     * nhầm vào cột nào.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'payment_status' => $this->input('payment_status'),
        ];
    }

    /**
     * Kỳ người dùng gửi lên, hoặc null để dùng kỳ mặc định.
     */
    public function periodStart(): ?string
    {
        $value = $this->input('period_start');

        return is_string($value) && $value !== '' ? $value : null;
    }
}