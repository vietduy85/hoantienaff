<?php

namespace App\Http\Requests\CreditCard;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Nhập sao kê thực tế của MỘT KỲ mà người dùng chọn.
 *
 * ---------------------------------------------------------------------------
 * `period_start` — KỲ NÀO, KHÔNG PHẢI THÁNG NÀO
 * ---------------------------------------------------------------------------
 * Gửi kỳ theo NGÀY MỞ KỲ (`period_start`), không theo tháng lịch: hai thẻ có
 * thể lệch chu kỳ, cùng một tháng có thể thuộc hai kỳ khác nhau. Ngày mở kỳ là
 * thứ duy nhất định danh kỳ mà không phải tự suy luận lại chu kỳ.
 *
 * TUỲ CHỌN: thiếu `period_start` thì dùng kỳ mặc định (kỳ đã kết thúc gần
 * nhất) — giữ được client cũ chỉ gửi hai con số, và cũng là hành vi mong muốn
 * khi người dùng bấm "Nhập sao kê" mà không chọn kỳ.
 *
 * ---------------------------------------------------------------------------
 * `closing_balance` KHÔNG ĐƯỢC NHẬN
 * ---------------------------------------------------------------------------
 * Cột này do server tính (`actual_spend - actual_reward`, xem
 * `CreditCardStatement::closingBalance()`). Ở đây nó thậm chí không có trong
 * `rules()` nên Laravel coi là trường lạ và bỏ qua — client gửi lên cũng không
 * tới nơi ghi. Ràng buộc mạnh hơn nữa nằm ở {@see payload()}: chỉ trả về hai
 * con số thật, nên kể cả sau này có ai đó đổi rules thì service vẫn không nhận
 * được `closing_balance`.
 */
class StoreStatementRequest extends FormRequest
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
            // `nullable` + `date_format`: ràng buộc thật là "kỳ có thuộc thẻ
            // này không", kiểm ở `StatementPeriodService::resolvePeriodStart()` —
            // ở đây chỉ chặn ngày không đọc được trước khi tới đó.
            'period_start' => ['nullable', 'date_format:Y-m-d'],
            'actual_spend' => ['required', 'numeric', 'min:0'],
            // `lte:actual_spend` chạy được vì `actual_spend` là `required` — không
            // có trường này thì request đã fail trước đó.
            'actual_reward' => ['required', 'numeric', 'min:0', 'lte:actual_spend'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period_start.date_format' => 'Kỳ sao kê không hợp lệ.',
            'actual_spend.required' => 'Vui lòng nhập số tiền thực tế chi tiêu trong kỳ.',
            'actual_spend.numeric' => 'Số tiền thực tế chi tiêu không hợp lệ.',
            'actual_spend.min' => 'Số tiền thực tế chi tiêu không được nhỏ hơn 0.',
            'actual_reward.required' => 'Vui lòng nhập số tiền hoàn/thưởng thực tế trong kỳ.',
            'actual_reward.numeric' => 'Số tiền hoàn/thưởng thực tế không hợp lệ.',
            'actual_reward.min' => 'Số tiền hoàn/thưởng thực tế không được nhỏ hơn 0.',
            'actual_reward.lte' => 'Tiền hoàn/thưởng không được lớn hơn thực tế chi tiêu.',
        ];
    }

    /**
     * Dữ liệu gửi vào service — CHỈ hai con số thật.
     *
     * `period_start` không nằm trong payload: nó KHÔNG phải tiền, và service
     * nhận nó qua tham số riêng (`upsertForPeriodStart()`) để không bao giờ bị
     * ghi nhầm vào cột tiền.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'actual_spend' => $this->input('actual_spend'),
            'actual_reward' => $this->input('actual_reward'),
        ];
    }

    /**
     * `period_start` người dùng gửi lên, hoặc null để dùng kỳ mặc định.
     */
    public function periodStart(): ?string
    {
        $value = $this->input('period_start');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
