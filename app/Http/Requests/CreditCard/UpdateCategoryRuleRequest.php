<?php

namespace App\Http\Requests\CreditCard;

use App\Http\Requests\CreditCard\Concerns\ValidatesRuleTargets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Sửa quy tắc cashback (PATCH một phần).
 *
 * Không cho đổi `tier_id`: rule không di chuyển giữa các bậc. Muốn mang rule sang
 * bậc khác thì dùng `POST /quy-tac/{rule}/nhan-ban`.
 *
 * ---------------------------------------------------------------------------
 * ĐỔI LOẠI TARGET
 * ---------------------------------------------------------------------------
 * Chuyển rule danh mục ⇄ rule combo bằng cách gửi target mới và target cũ bằng
 * `null`:
 *
 *   - sang combo    : `{ "combo_id": 7, "category_id": null }`
 *   - về danh mục   : `{ "category_id": 3, "combo_id": null }`
 *
 * Gửi cả hai khác null (hoặc cả hai null) là 422 — xem `ValidatesRuleTargets`.
 * Nếu KHÔNG gửi target nào thì giữ nguyên target hiện tại (PATCH một phần), chỉ
 * validate lại để chặn trường hợp target cũ đã không còn dùng được nữa.
 */
class UpdateCategoryRuleRequest extends FormRequest
{
    use ValidatesRuleTargets;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return array_merge($this->ruleTargetRules(), [
            'cashback_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'spend_from' => ['sometimes', 'numeric', 'min:0'],
            'spend_to' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_per_transaction' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_per_category_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_transaction_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_enabled' => ['sometimes', 'boolean'],
            // Bất biến "cùng một bậc" chặn ở `CategoryRuleService` (cần hỏi DB xem
            // bậc khác trong version đã tick chưa) ⇒ trả 422 kèm đúng message.
            'is_quota_category' => ['sometimes', 'boolean'],
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
        $sendsTarget = $this->has('scope_type') || $this->has('category_id') || $this->has('combo_id');

        // PATCH không đụng target ⇒ để `CategoryRuleService::applyTarget()` giữ
        // target hiện tại (nó tự validate lại target đó), không ép client phải
        // gửi lại thứ không đổi.
        if ($sendsTarget) {
            $this->assertSingleTarget($validator, (int) $this->user()->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach ([
            'scope_type',
            'category_id',
            'combo_id',
            'cashback_percent',
            'name',
            'sort_order',
            'spend_from',
            'spend_to',
            'max_cashback_per_transaction',
            'max_cashback_per_category_per_period',
            'min_transaction_amount',
            'is_enabled',
            'is_quota_category',
            'note',
        ] as $field) {
            if ($this->has($field)) {
                $payload[$field] = in_array($field, ['category_id', 'combo_id'], true)
                    ? $this->optionalId($field)
                    : $this->input($field);
            }
        }

        return $payload;
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
