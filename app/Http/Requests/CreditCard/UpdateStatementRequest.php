<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use App\Support\CreditCard\Decimal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sửa sao kê thực tế — CẬP NHẬT TỪNG PHẦN.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KIỂM TRA `reward <= spend` PHẢI GỘP VỚI GIÁ TRỊ ĐANG LƯU
 * ---------------------------------------------------------------------------
 * Cả hai trường đều `sometimes`, nên `lte:actual_spend` trên `actual_reward` sẽ hỏng
 * khi request chỉ gửi mỗi `actual_reward` (không có `actual_spend` để so).
 * Trường hợp đó lại chính là lúc dễ lọt lỗi: sửa riêng hoàn/thưởng lên cao hơn
 * số chi tiêu đang lưu mà không ai chặn.
 *
 * Vì vậy {@see withValidator()} so sánh trên giá trị HIỆU LỰC = giá trị gửi lên nếu có,
 * ngược lại lấy giá trị đang lưu trong DB — và so bằng `Decimal` (bcmath) để không
 * lệch ở số tiền lớn.
 *
 * `closing_balance` không được nhận — xem `StoreStatementRequest`.
 */
class UpdateStatementRequest extends FormRequest
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
            'actual_spend' => ['sometimes', 'numeric', 'min:0'],
            'actual_reward' => ['sometimes', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'actual_spend.numeric' => 'Số tiền thực tế chi tiêu không hợp lệ.',
            'actual_spend.min' => 'Số tiền thực tế chi tiêu không được nhỏ hơn 0.',
            'actual_reward.numeric' => 'Số tiền hoàn/thưởng thực tế không hợp lệ.',
            'actual_reward.min' => 'Số tiền hoàn/thưởng thực tế không được nhỏ hơn 0.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $current = $this->currentStatement();

            $spend = $this->has('actual_spend')
                ? Decimal::money($this->input('actual_spend'))
                : ($current === null ? null : Decimal::money($current->actual_spend));

            $reward = $this->has('actual_reward')
                ? Decimal::money($this->input('actual_reward'))
                : ($current === null ? null : Decimal::money($current->actual_reward));

            if ($spend !== null && $reward !== null && Decimal::compare($reward, $spend) > 0) {
                $validator->errors()->add(
                    'actual_reward',
                    'Tiền hoàn/thưởng không được lớn hơn thực tế chi tiêu.',
                );
            }
        });
    }

    /**
     * Dòng sao kê đang sửa, resolve từ route binding.
     */
    private function currentStatement(): ?CreditCardStatement
    {
        $statement = $this->route('statement');

        return $statement instanceof CreditCardStatement ? $statement : null;
    }

    /**
     * Dữ liệu gửi vào service — CHỈ hai con số thật.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach (['actual_spend', 'actual_reward'] as $field) {
            if ($this->has($field)) {
                $payload[$field] = $this->input($field);
            }
        }

        return $payload;
    }
}
