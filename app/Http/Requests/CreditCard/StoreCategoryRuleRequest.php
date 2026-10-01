<?php

namespace App\Http\Requests\CreditCard;

use App\Http\Requests\CreditCard\Concerns\ValidatesRuleTargets;
use App\Models\CreditCard\PolicyTierCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Thêm quy tắc cashback cho một DANH MỤC, một COMBO, hoặc làm fallback trong một bậc.
 *
 * ---------------------------------------------------------------------------
 * BA LOẠI TARGET
 * ---------------------------------------------------------------------------
 *   1. rule danh mục : `scope_type=category` + `category_id` có  + `combo_id` NULL
 *   2. rule combo    : `scope_type=category` + `combo_id` CÓ     + `category_id` NULL
 *   3. fallback      : `scope_type=other`    + cả hai target đều NULL
 *
 * Client cũ chỉ gửi `category_id` (không `scope_type`) vẫn hợp lệ — mặc định là
 * rule danh mục, nên không phá payload đang chạy.
 *
 * ---------------------------------------------------------------------------
 * KHÔNG CÓ TRƯỜNG CASHBACK KẾT QUẢ
 * ---------------------------------------------------------------------------
 * Rule chỉ MÔ TẢ CẤU HÌNH (`cashback_percent` + các mức cap), không mang giá trị
 * cashback đã tính. Vì vậy payload KHÔNG có `cashback_amount`,
 * `calculated_cashback`, `final_cashback` hay bất kỳ cột snapshot nào — những cột
 * đó do `CashbackRecordService` ghi vào `credit_card_transactions`, và tiền phải
 * luôn do hệ thống quyết định, không phải người dùng.
 *
 * Field gửi thừa bị bỏ qua (không whitelist) chứ không gây lỗi, để client cũ
 * không gửi `cashback_amount` cũng không vỡ.
 */
class StoreCategoryRuleRequest extends FormRequest
{
    use ValidatesRuleTargets;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return array_merge($this->ruleTargetRules(), [
            'cashback_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'name' => ['nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'spend_from' => ['sometimes', 'numeric', 'min:0'],
            'spend_to' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_per_transaction' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_per_category_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_transaction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_enabled' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
    }

    public function messages(): array
    {
        return [
            'cashback_percent.required' => 'Vui lòng nhập tỷ lệ hoàn tiền.',
            'cashback_percent.max' => 'Tỷ lệ hoàn tiền không được vượt quá 100%.',
            'spend_from.min' => 'Ngưỡng chi tiêu không được âm.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->assertSingleTarget($validator, (int) $this->user()->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'scope_type' => $this->input('scope_type', PolicyTierCategory::SCOPE_CATEGORY),
            'category_id' => $this->optionalId('category_id'),
            'combo_id' => $this->optionalId('combo_id'),
            'cashback_percent' => $this->input('cashback_percent'),
            'name' => $this->input('name'),
            'sort_order' => $this->input('sort_order'),
            'spend_from' => $this->input('spend_from', 0),
            'spend_to' => $this->input('spend_to'),
            'max_cashback_per_transaction' => $this->input('max_cashback_per_transaction'),
            'max_cashback_per_category_per_period' => $this->input('max_cashback_per_category_per_period'),
            'min_transaction_amount' => $this->input('min_transaction_amount'),
            'is_enabled' => $this->input('is_enabled', true),
            'note' => $this->input('note'),
        ];
    }

    private function optionalId(string $field): ?int
    {
        $value = $this->input($field);

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
