<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\PolicyTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nhân bản một rule cashback sang bậc khác.
 *
 * `target_tier_id` được authorize bằng `PolicyTierCategoryPolicy::create()` trên
 * bậc đích — bậc đích có thể nằm ở version của thẻ khác, nên không thể tin
 * `user_id` client.
 */
class CloneCategoryRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'target_tier_id' => ['required', 'integer', Rule::exists(PolicyTier::class, 'id')],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_tier_id.required' => 'Vui lòng chọn bậc đích.',
            'target_tier_id.exists' => 'Bậc đích không tồn tại.',
        ];
    }

    public function targetTierId(): int
    {
        return (int) $this->input('target_tier_id');
    }

    public function targetSortOrder(): ?int
    {
        $sortOrder = $this->input('sort_order');

        return $sortOrder === null ? null : (int) $sortOrder;
    }
}
