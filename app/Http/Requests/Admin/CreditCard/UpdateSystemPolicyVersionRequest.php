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
 * Admin "Lưu lại" — cập nhật IN-PLACE cấu hình của CHÍNH phiên bản blueprint đang sửa.
 *
 * KHÔNG tạo version mới. Cấu hình thuộc VERSION (ngày hiệu lực, ngưỡng chi tiêu,
 * tier/rules) do `updateSystemVersion()` ghi; metadata template (tên / mô tả /
 * trạng thái) nếu editor gửi kèm được ghi qua `updateSystemMeta()` — cùng một lần
 * bấm "Lưu lại", để ô Mô tả trên trang Chỉnh sửa không bị mất.
 *
 * Cấu trúc canonical là `tiers[].rules` — không có nhánh `tiers[].categories`.
 */
class UpdateSystemPolicyVersionRequest extends FormRequest
{
    use ValidatesRuleTargets;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'min_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],

            'tiers' => ['sometimes', 'array'],
            'tiers.*.id' => ['sometimes', 'nullable', 'integer'],
            'tiers.*.name' => ['nullable', 'string', 'max:150'],
            'tiers.*.min_total_spend' => ['sometimes', 'numeric', 'min:0'],
            'tiers.*.max_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tiers.*.max_cashback_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // §23 — giới hạn hoàn tiền theo giá trị giao dịch (overlap/max<min do
            // TierService kiểm khi sync, add trong cùng transaction bậc).
            'tiers.*.transaction_caps' => ['sometimes', 'nullable', 'array'],
            'tiers.*.transaction_caps.*.min_transaction_amount' => ['required', 'numeric', 'min:0'],
            'tiers.*.transaction_caps.*.max_transaction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tiers.*.transaction_caps.*.max_cashback_per_transaction' => ['required', 'numeric', 'min:0'],

            'tiers.*.rules' => ['sometimes', 'array'],
            'tiers.*.rules.*.id' => ['sometimes', 'nullable', 'integer'],
            'tiers.*.rules.*.scope_type' => [
                'sometimes',
                Rule::in([PolicyTierCategory::SCOPE_CATEGORY, PolicyTierCategory::SCOPE_OTHER]),
            ],
            'tiers.*.rules.*.counts_toward_tier_cap' => ['sometimes', 'boolean'],
            'tiers.*.rules.*.category_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Category::class, 'id')],
            'tiers.*.rules.*.combo_id' => ['sometimes', 'nullable', 'integer', Rule::exists(CategoryCombo::class, 'id')],
            'tiers.*.rules.*.name' => ['nullable', 'string', 'max:150'],
            'tiers.*.rules.*.cashback_percent' => ['required_unless:tiers.*.rules.*.scope_type,other', 'numeric', 'min:0', 'max:100'],
            'tiers.*.rules.*.max_cashback_per_transaction' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tiers.*.rules.*.max_cashback_per_category_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tiers.*.rules.*.min_transaction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_from.date_format' => 'Ngày bắt đầu hiệu lực phải có định dạng Y-m-d.',
            'status.in' => 'Trạng thái không hợp lệ.',
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
     * `$userId = null` ⇒ target phải là danh mục/combo HỆ THỐNG đang bật.
     */
    public function withValidator(Validator $validator): void
    {
        $this->assertNestedTargets($validator, null);
    }

    /**
     * Metadata template đi kèm khi bấm "Lưu lại" (nếu editor có gửi).
     *
     * Chỉ trả về field CÓ mặt trong payload ⇒ PATCH thuần cấu hình version vẫn
     * không đụng metadata (hành vi cũ cho test/API cũ vẫn giữ nguyên).
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $meta = [];

        if ($this->has('name')) {
            $meta['name'] = $this->input('name');
        }

        if ($this->has('description')) {
            $meta['description'] = $this->input('description');
        }

        if ($this->has('status')) {
            $meta['is_active'] = $this->input('status') === 'published';
        }

        return $meta;
    }

    /**
     * Cấu hình version được cập nhật in-place (bộ phận duy nhất `updateSystemVersion`
     * chấp nhận) — không có metadata template, không có source_version_id.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        $overrides = [];

        foreach (['effective_from', 'min_total_spend'] as $field) {
            if ($this->has($field)) {
                $overrides[$field] = $this->input($field);
            }
        }

        $tiers = $this->input('tiers');

        if (is_array($tiers)) {
            $overrides['tiers'] = array_values($tiers);
        }

        return $overrides;
    }
}
