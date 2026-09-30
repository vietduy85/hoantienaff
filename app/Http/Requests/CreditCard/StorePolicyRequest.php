<?php

namespace App\Http\Requests\CreditCard;

use App\Models\CreditCard\PolicyTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tạo policy version 1 cho một thẻ.
 *
 * Ba đường vào được gộp vào MỘT endpoint (`POST /the/{card}/chinh-sach`) và phân
 * biệt bằng `mode`:
 *
 *   mode = scratch        → `PolicyService::createFromScratch()`
 *   mode = clone_system   → `PolicyService::cloneSystemTemplate()`
 *   mode = clone_user     → `PolicyService::cloneUserTemplate()`
 *
 * Vì sao gộp: cả ba đều trả về "version 1 của thẻ" và có cùng tiền đề/kết luận.
 * Tách ba endpoint sẽ lặp lại validation `userCard`/`effective_from` và dễ lệch
 * hành vi giữa các nhánh.
 *
 * KHÔNG có `user_id` trong payload: userId lấy từ `$this->user()`.
 */
class StorePolicyRequest extends FormRequest
{
    public const MODE_SCRATCH = 'scratch';

    public const MODE_CLONE_SYSTEM = 'clone_system';

    public const MODE_CLONE_USER = 'clone_user';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $mode = $this->mode();

        return [
            'mode' => ['required', Rule::in([
                self::MODE_SCRATCH,
                self::MODE_CLONE_SYSTEM,
                self::MODE_CLONE_USER,
            ])],
            'effective_from' => ['required', 'date_format:Y-m-d'],

            // Chỉ dùng với mode = scratch.
            'name' => [Rule::requiredIf($mode === self::MODE_SCRATCH), 'nullable', 'string', 'max:150'],
            'min_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_cashback_total_per_period' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rounding_mode' => ['sometimes', Rule::in(['round', 'floor', 'ceil'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // Bậc chi tiêu (chỉ mode = scratch). Bỏ trống hoặc mảng rỗng hợp lệ:
            // service tự tạo một bậc mặc định, nên không bắt `min:1`.
            'tiers' => ['sometimes', 'array'],
            'tiers.*.name' => ['nullable', 'string', 'max:150'],
            'tiers.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'tiers.*.min_total_spend' => ['sometimes', 'numeric', 'min:0'],
            'tiers.*.max_total_spend' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // Chỉ dùng với mode = clone_system / clone_user.
            'template_id' => [
                Rule::requiredIf(in_array($mode, [self::MODE_CLONE_SYSTEM, self::MODE_CLONE_USER], true)),
                'nullable',
                'integer',
                // `PolicyTemplate::class` ⇒ đúng connection `creditcard`.
                // CHỈ chặn "không tồn tại" ở đây; scope SYSTEM/USER và ownership
                // do `PolicyService` kiểm — không nhân bản quy tắc nghiệp vụ ra
                // FormRequest.
                Rule::exists(PolicyTemplate::class, 'id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'mode.in' => 'Cách tạo policy không hợp lệ.',
            'effective_from.required' => 'Vui lòng chọn ngày bắt đầu hiệu lực.',
            'effective_from.date_format' => 'Ngày bắt đầu hiệu lực phải có định dạng Y-m-d.',
            'name.required' => 'Vui lòng nhập tên chính sách.',
            'template_id.required' => 'Vui lòng chọn mẫu chính sách.',
            'template_id.exists' => 'Mẫu chính sách không tồn tại.',
            'rounding_mode.in' => 'Cách làm tròn không hợp lệ.',
        ];
    }

    public function mode(): string
    {
        $mode = $this->input('mode');

        return is_string($mode) ? $mode : '';
    }

    /**
     * Tạo policy từ đầu: CHỈ các field của chính mode scratch.
     *
     * @return array<string, mixed>
     */
    public function scratchPayload(): array
    {
        $payload = [
            'name' => $this->input('name'),
            'min_total_spend' => $this->input('min_total_spend', 0),
            'max_cashback_total_per_period' => $this->input('max_cashback_total_per_period'),
            'rounding_mode' => $this->input('rounding_mode', 'round'),
            'note' => $this->input('note'),
        ];

        $tiers = $this->input('tiers');

        if (is_array($tiers)) {
            $payload['tiers'] = array_values($tiers);
        }

        return $payload;
    }

    public function templateId(): ?int
    {
        $id = $this->input('template_id');

        return $id === null ? null : (int) $id;
    }
}
