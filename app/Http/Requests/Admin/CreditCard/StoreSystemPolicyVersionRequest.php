<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Http\Requests\CreditCard\Concerns\ValidatesRuleTargets;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Admin SỬA chính sách hệ thống ⇒ tạo blueprint VERSION MỚI (append-only).
 *
 * Đồng thời chấp nhận metadata của template (tên, mô tả, trạng thái xuất bản) để
 * màn hình "Chỉnh sửa" có thể lưu một lượt cả vỏ ngoài lẫn cấu hình.
 *
 * Business rule của blueprint cũ BẤT BIẾN — payload này chỉ là "cấu hình mới".
 */
class StoreSystemPolicyVersionRequest extends FormRequest
{
    use ValidatesRuleTargets;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date_format:Y-m-d'],

            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],

            'min_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rounding_mode' => ['sometimes', Rule::in(['round', 'floor', 'ceil'])],

            // Luồng "Chỉnh sửa version N": bản blueprint sẽ được dùng làm nguồn
            // copy (thay cho current/latest). Tính hợp lệ thuộc template được
            // kiểm trong PolicyCloneService (chỉ accept blueprint của template).
            'source_version_id' => ['sometimes', 'nullable', 'integer', Rule::exists(PolicyVersion::class, 'id')],

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
            'tiers.*.rules.*.scope_type' => [
                'sometimes',
                Rule::in([PolicyTierCategory::SCOPE_CATEGORY, PolicyTierCategory::SCOPE_OTHER]),
            ],
            'tiers.*.rules.*.counts_toward_tier_cap' => ['sometimes', 'boolean'],
            // Target (danh mục | combo | fallback) kiểm ở `withValidator`:
            // `required_unless` không diễn đạt được case combo.
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
     * `$userId = null` ⇒ target phải là danh mục/combo HỆ THỐNG đang bật.
     */
    public function withValidator(Validator $validator): void
    {
        $this->assertNestedTargets($validator, null);
    }

    /**
     * Metadata template đi kèm cấu hình mới (nếu có).
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
     * Version được chọn làm nguồn copy cho blueprint mới (đường "Chỉnh sửa version N").
     */
    public function sourceVersionId(): ?int
    {
        $id = $this->input('source_version_id');

        return $id === null || $id === '' ? null : (int) $id;
    }

    /**
     * Override cấu hình cho blueprint mới.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        $overrides = [];

        foreach (['min_total_spend', 'rounding_mode', 'note'] as $field) {
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
