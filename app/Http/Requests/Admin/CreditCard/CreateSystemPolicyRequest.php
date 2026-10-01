<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Http\Requests\CreditCard\Concerns\ValidatesRuleTargets;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\PolicyTierCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Tạo MỚI một chính sách hệ thống (System Template + blueprint version 1).
 *
 * Chỉ admin mới gọi được (route gắn `permission:credit-cards.manage`). Phạm vi danh
 * mục (hệ thống, đang active) và luật nghiệp vụ do `CategoryRuleService` bảo vệ ở
 * tầng service — FormRequest chỉ chặn lệch kiểu dữ liệu và target mơ hồ
 * (danh mục + combo cùng lúc).
 */
class CreateSystemPolicyRequest extends FormRequest
{
    use ValidatesRuleTargets;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'min_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rounding_mode' => ['sometimes', Rule::in(['round', 'floor', 'ceil'])],

            'tiers' => ['sometimes', 'array'],
            'tiers.*.id' => ['sometimes', 'nullable', 'integer'],
            'tiers.*.name' => ['nullable', 'string', 'max:150'],
            'tiers.*.min_total_spend' => ['sometimes', 'numeric', 'min:0'],
            'tiers.*.max_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tiers.*.max_cashback_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'tiers.*.transaction_caps' => ['sometimes', 'nullable', 'array'],
            'tiers.*.transaction_caps.*.min_transaction_amount' => ['required', 'numeric', 'min:0'],
            'tiers.*.transaction_caps.*.max_transaction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tiers.*.transaction_caps.*.max_cashback_per_transaction' => ['required', 'numeric', 'min:0'],

            'tiers.*.rules' => ['sometimes', 'array'],
            'tiers.*.rules.*.id' => ['sometimes', 'nullable', 'integer'],
            'tiers.*.rules.*.counts_toward_tier_cap' => ['sometimes', 'boolean'],
            // Target (danh mục | combo | fallback) được kiểm ở `withValidator`:
            // `required_unless` không diễn đạt được case combo.
            'tiers.*.rules.*.scope_type' => [
                'sometimes',
                Rule::in([PolicyTierCategory::SCOPE_CATEGORY, PolicyTierCategory::SCOPE_OTHER]),
            ],
            'tiers.*.rules.*.category_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Category::class, 'id')],
            'tiers.*.rules.*.combo_id' => ['sometimes', 'nullable', 'integer', Rule::exists(CategoryCombo::class, 'id')],
            'tiers.*.rules.*.name' => ['nullable', 'string', 'max:150'],
            'tiers.*.rules.*.cashback_percent' => ['required_unless:tiers.*.rules.*.scope_type,other', 'numeric', 'min:0', 'max:100'],
            'tiers.*.rules.*.max_cashback_per_transaction' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tiers.*.rules.*.max_cashback_per_category_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tiers.*.rules.*.min_transaction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tiers.*.rules.*.spend_from' => ['sometimes', 'numeric', 'min:0'],
            'tiers.*.rules.*.spend_to' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên chính sách.',
            'effective_from.required' => 'Vui lòng chọn ngày bắt đầu hiệu lực.',
            'effective_from.date_format' => 'Ngày bắt đầu hiệu lực phải có định dạng Y-m-d.',
            'status.in' => 'Trạng thái không hợp lệ.',
            'rounding_mode.in' => 'Cách làm tròn không hợp lệ.',
            'tiers.*.rules.*.scope_type.in' => 'Phạm vi danh mục của quy tắc không hợp lệ.',
            'tiers.*.rules.*.category_id.exists' => 'Danh mục không tồn tại.',
            'tiers.*.rules.*.combo_id.exists' => 'Combo không tồn tại.',
            'tiers.*.rules.*.cashback_percent.required_unless' => 'Vui lòng nhập tỷ lệ hoàn tiền.',
            'tiers.*.rules.*.cashback_percent.max' => 'Tỷ lệ hoàn tiền phải từ 0 đến 100.',
            'tiers.*.transaction_caps.*.min_transaction_amount.required' => 'Điều kiện giới hạn theo giá trị giao dịch phải có giá trị "Từ".',
            'tiers.*.transaction_caps.*.min_transaction_amount.min' => 'Giá trị "Từ" của giới hạn theo giá trị giao dịch không được âm.',
            'tiers.*.transaction_caps.*.max_transaction_amount.min' => 'Giá trị "Đến" của giới hạn theo giá trị giao dịch không được âm.',
            'tiers.*.transaction_caps.*.max_cashback_per_transaction.required' => 'Điều kiện giới hạn theo giá trị giao dịch phải có "Hoàn tối đa".',
            'tiers.*.transaction_caps.*.max_cashback_per_transaction.min' => '"Hoàn tối đa" của giới hạn theo giá trị giao dịch không được âm.',
        ];
    }

    /**
     * `$userId = null` ⇒ phía admin: target phải là danh mục/combo HỆ THỐNG đang
     * bật. Combo riêng của user không được nhét vào blueprint (sẽ được mọi user
     * clone mượn).
     */
    public function withValidator(Validator $validator): void
    {
        $this->assertNestedTargets($validator, null);
    }

    public function isPublished(): bool
    {
        return $this->input('status') === 'published';
    }

    public function description(): ?string
    {
        $value = $this->input('description');

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Override cho `createSystemTemplate()`: cấu hình business của blueprint v1.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        $overrides = [];

        foreach (['min_total_spend', 'rounding_mode'] as $field) {
            if ($this->has($field)) {
                $overrides[$field] = $this->input($field);
            }
        }

        $tiers = $this->input('tiers');

        if (is_array($tiers) && $tiers !== []) {
            $overrides['tiers'] = array_values($tiers);
        }

        return $overrides;
    }
}
