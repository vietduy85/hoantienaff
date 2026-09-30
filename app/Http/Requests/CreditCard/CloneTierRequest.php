<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\Policy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nhân bản một bậc (kèm toàn bộ rule) sang policy version khác.
 *
 * `target_policy_id` KHÔNG được suy ra từ client theo dạng "bản sao thuộc thẻ
 * này" — nó là một version cụ thể, và controller sẽ authorize `create` trên chính
 * version đó. Nếu chỉ tin `user_id` thì một user có thể đẩy cấu hình vào thẻ của
 * người khác; authorize trên version mới là chốt chặn quyết định.
 */
class CloneTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'target_policy_id' => [
                'required',
                'integer',
                // Đích phải là version THẬT của một thẻ (không phải blueprint).
                Rule::exists(Policy::class, 'id')->whereNotNull('user_card_id'),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_policy_id.required' => 'Vui lòng chọn phiên bản chính sách đích.',
            'target_policy_id.exists' => 'Phiên bản chính sách đích không hợp lệ.',
        ];
    }

    public function targetPolicyId(): int
    {
        return (int) $this->input('target_policy_id');
    }

    public function targetSortOrder(): ?int
    {
        $sortOrder = $this->input('sort_order');

        return $sortOrder === null ? null : (int) $sortOrder;
    }
}
