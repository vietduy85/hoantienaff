<?php

namespace App\Http\Requests\CreditCard\Concerns;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTierCategory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validate + chuẩn hoá phần POLICY của form Thêm/Sửa thẻ (`policy.*`).
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO TÁCH RA CONCERN
 * ---------------------------------------------------------------------------
 * `StoreUserCardRequest` và `UpdateUserCardRequest` đều nhận `policy.*`, còn bộ
 * rule của policy dài hơn cả bộ rule của thẻ. Không tách thì hai file lệch nhau
 * sau vài lần sửa, và form sửa thẻ sẽ chấp nhận cấu hình mà form thêm thẻ từ chối.
 *
 * ---------------------------------------------------------------------------
 * HỢP ĐỒNG PAYLOAD
 * ---------------------------------------------------------------------------
 * `policy` là OPTIONAL. Không gửi ⇒ thẻ giữ nguyên hiện trạng (thêm: không policy).
 *
 *   policy.template_id     int|null  mẫu được chọn; null = sửa policy riêng của thẻ
 *   policy.effective_from  date      ngày hiệu lực (mặc định hôm nay)
 *   policy.name            string    tên policy của thẻ
 *   policy.tiers           array     cấu hình đã sửa; thiếu = giữ nguyên bản clone
 *
 * Quyền chọn mẫu (system / của chính mình) KHÔNG kiểm ở đây: đó là luật nghiệp vụ
 * của `PolicyService::cloneUserTemplate()` và nhân bản nó ra FormRequest sẽ tạo
 * hai nơi phải sửa cùng lúc.
 */
trait ValidatesCardPolicySelection
{
    use ValidatesRuleTargets;

    /**
     * Ghép vào `rules()` của request thẻ.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function policySelectionRules(string $prefix = 'sometimes'): array
    {
        return [
            "{$prefix}policy" => ['nullable', 'array'],
            "{$prefix}policy.template_id" => [
                'nullable',
                'integer',
                // Chỉ chặn "không tồn tại". Scope system/user + ownership do service.
                Rule::exists(PolicyTemplate::class, 'id'),
            ],
            "{$prefix}policy.effective_from" => ['nullable', 'date_format:Y-m-d'],
            "{$prefix}policy.name" => ['nullable', 'string', 'max:150'],

            "{$prefix}policy.tiers" => ['sometimes', 'nullable', 'array'],
            // `id` tier/rule do editor mang theo để mở lại đúng dòng cũ. Service KHÔNG
            // dùng id này để cập nhật dòng đã có (`replaceChildren()` xoá rồi tạo
            // lại), nhưng vẫn phải khai báo là số để payload không lọt giá trị lạ
            // xuống tầng service.
            "{$prefix}policy.tiers.*.id" => ['sometimes', 'nullable', 'integer'],
            "{$prefix}policy.tiers.*.name" => ['nullable', 'string', 'max:150'],
            "{$prefix}policy.tiers.*.sort_order" => ['sometimes', 'integer', 'min:0'],
            "{$prefix}policy.tiers.*.min_total_spend" => ['sometimes', 'numeric', 'min:0'],
            "{$prefix}policy.tiers.*.max_total_spend" => ['sometimes', 'nullable', 'numeric', 'min:0'],
            "{$prefix}policy.tiers.*.max_cashback_per_period" => ['sometimes', 'nullable', 'numeric', 'min:0'],

            "{$prefix}policy.tiers.*.transaction_caps" => ['sometimes', 'nullable', 'array'],
            "{$prefix}policy.tiers.*.transaction_caps.*.min_transaction_amount" => ['required', 'numeric', 'min:0'],
            "{$prefix}policy.tiers.*.transaction_caps.*.max_transaction_amount" => ['sometimes', 'nullable', 'numeric', 'min:0'],
            "{$prefix}policy.tiers.*.transaction_caps.*.max_cashback_per_transaction" => ['required', 'numeric', 'min:0'],

            "{$prefix}policy.tiers.*.rules" => ['sometimes', 'array'],
            // `id`/`sort_order`/`note` không có ô nhập nhưng PHẢI đi kèm payload:
            // lưu thẻ tạo version mới bằng `replaceChildren()` (xoá rồi tạo lại
            // dòng), nên thiếu chúng là mất cấu hình/ghi chú của rule cũ.
            "{$prefix}policy.tiers.*.rules.*.id" => ['sometimes', 'nullable', 'integer'],
            "{$prefix}policy.tiers.*.rules.*.sort_order" => ['sometimes', 'integer', 'min:1'],
            "{$prefix}policy.tiers.*.rules.*.note" => ['sometimes', 'nullable', 'string', 'max:5000'],
            "{$prefix}policy.tiers.*.rules.*.scope_type" => [
                'sometimes',
                Rule::in([PolicyTierCategory::SCOPE_CATEGORY, PolicyTierCategory::SCOPE_OTHER]),
            ],
            // Target loại trừ lẫn nhau / thuộc quyền user kiểm ở `withValidator`.
            "{$prefix}policy.tiers.*.rules.*.category_id" => ['sometimes', 'nullable', 'integer', Rule::exists(Category::class, 'id')],
            "{$prefix}policy.tiers.*.rules.*.combo_id" => ['sometimes', 'nullable', 'integer', Rule::exists(CategoryCombo::class, 'id')],
            "{$prefix}policy.tiers.*.rules.*.name" => ['nullable', 'string', 'max:150'],
            "{$prefix}policy.tiers.*.rules.*.cashback_percent" => ['sometimes', 'numeric', 'min:0', 'max:100'],
            "{$prefix}policy.tiers.*.rules.*.max_cashback_per_transaction" => ['sometimes', 'nullable', 'numeric', 'min:0'],
            "{$prefix}policy.tiers.*.rules.*.max_cashback_per_category_per_period" => ['sometimes', 'nullable', 'numeric', 'min:0'],
            "{$prefix}policy.tiers.*.rules.*.min_transaction_amount" => ['sometimes', 'nullable', 'numeric', 'min:0'],
            "{$prefix}policy.tiers.*.rules.*.spend_from" => ['sometimes', 'numeric', 'min:0'],
            "{$prefix}policy.tiers.*.rules.*.spend_to" => ['sometimes', 'nullable', 'numeric', 'min:0'],
            "{$prefix}policy.tiers.*.rules.*.counts_toward_tier_cap" => ['sometimes', 'boolean'],
            "{$prefix}policy.tiers.*.rules.*.is_enabled" => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Thông báo lỗi riêng cho `policy.*` (tiền tố field đầy đủ nên khai báo literal).
     *
     * @return array<string, string>
     */
    protected function policySelectionMessages(): array
    {
        return [
            'policy.template_id.integer' => 'Mẫu chính sách không hợp lệ.',
            'policy.template_id.exists' => 'Mẫu chính sách không tồn tại.',
            'policy.effective_from.date_format' => 'Ngày hiệu lực của chính sách không hợp lệ.',
            'policy.name.max' => 'Tên chính sách không được vượt quá 150 ký tự.',
            'policy.tiers.*.rules.*.sort_order.integer' => 'Thứ tự quy tắc không hợp lệ.',
            'policy.tiers.*.rules.*.note.max' => 'Ghi chú của quy tắc quá dài.',
            'policy.tiers.*.rules.*.scope_type.in' => 'Phạm vi danh mục của quy tắc không hợp lệ.',
            'policy.tiers.*.rules.*.category_id.exists' => 'Danh mục không tồn tại.',
            'policy.tiers.*.rules.*.combo_id.exists' => 'Combo không tồn tại.',
            'policy.tiers.*.rules.*.cashback_percent.max' => 'Tỷ lệ hoàn tiền phải từ 0 đến 100.',
            'policy.tiers.*.rules.*.cashback_percent.min' => 'Tỷ lệ hoàn tiền không được âm.',
        ];
    }

    /**
     * Danh mục/combo phải thuộc quyền của chính user đang đăng nhập.
     */
    protected function assertCardPolicyTargets(Validator $validator): void
    {
        $this->assertNestedTargets($validator, $this->user()?->getAuthIdentifier() === null ? null : (int) $this->user()->id, 'policy.tiers');
    }

    /**
     * Payload policy đã chuẩn hoá, hoặc `null` khi form không gửi policy.
     *
     * `null` ≠ mảng rỗng: mảng rỗng là "chủ động gửi policy", còn `null` là "không
     * đụng vào policy". Phân biệt này quyết định việc sửa thẻ có tạo version mới
     * hay không.
     *
     * @return array<string, mixed>|null
     */
    public function policySelection(): ?array
    {
        $policy = $this->input('policy');

        if (! is_array($policy)) {
            return null;
        }

        $selection = [];

        $templateId = $policy['template_id'] ?? null;

        if ($templateId !== null && $templateId !== '') {
            $selection['template_id'] = (int) $templateId;
        }

        if (isset($policy['effective_from']) && $policy['effective_from'] !== '') {
            $selection['effective_from'] = (string) $policy['effective_from'];
        }

        if (isset($policy['name']) && is_string($policy['name']) && trim($policy['name']) !== '') {
            $selection['name'] = trim($policy['name']);
        }

        if (isset($policy['tiers']) && is_array($policy['tiers'])) {
            $selection['tiers'] = array_values($policy['tiers']);
        }

        return $selection === [] ? null : $selection;
    }
}
