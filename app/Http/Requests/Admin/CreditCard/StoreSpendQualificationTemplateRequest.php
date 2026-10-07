<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Models\CreditCard\SpendQualificationCondition;
use App\Models\CreditCard\SpendQualificationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tạo MẪU "Điều kiện hoàn tiền đặc biệt" (master data hệ thống).
 *
 * Chỉ admin có `credit-cards.manage` gọi được (middleware permission). Template
 * quy định bộ điều kiện chi tiêu mà USER chọn rồi hệ thống DEEP-CLONE vào policy
 * version của thẻ. Điều kiện chỉ tham chiếu DANH MỤC HỆ THỐNG ĐANG HOẠT ĐỘNG
 * (phạm vi system + is_active) — kiểm ở `SpendQualificationService`.
 */
class StoreSpendQualificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermissionTo('credit-cards.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'required',
                'string',
                'max:150',
                function (string $attribute, $value, $fail): void {
                    $slug = Str::slug((string) $value);

                    if ($slug === '') {
                        $fail('Slug không hợp lệ.');

                        return;
                    }

                    $exists = SpendQualificationTemplate::query()->where('slug', $slug)->exists();

                    if ($exists) {
                        $fail(sprintf('Slug "%s" đã tồn tại.', $slug));
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            // Bộ điều kiện bắt buộc khi tạo: template không điều kiện là vô nghĩa.
            'spend_qualification' => ['required', 'array'],
            'spend_qualification.enabled' => ['nullable', 'boolean'],
            'spend_qualification.name' => ['nullable', 'string', 'max:150'],
            'spend_qualification.note' => ['nullable', 'string', 'max:5000'],
            'spend_qualification.conditions' => ['required', 'array', 'min:1'],
            'spend_qualification.conditions.*.id' => ['sometimes', 'nullable', 'integer'],
            'spend_qualification.conditions.*.type' => [
                'required',
                Rule::in([SpendQualificationCondition::TYPE_CATEGORY, SpendQualificationCondition::TYPE_OTHER]),
            ],
            'spend_qualification.conditions.*.category_id' => ['sometimes', 'nullable', 'integer'],
            'spend_qualification.conditions.*.min_spend' => ['required', 'numeric', 'min:0'],
            'spend_qualification.conditions.*.note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'spend_qualification.conditions.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'spend_qualification.conditions.*.excluded_category_ids' => ['sometimes', 'array'],
            'spend_qualification.conditions.*.excluded_category_ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên mẫu điều kiện.',
            'slug.required' => 'Vui lòng nhập slug mẫu điều kiện.',
            'spend_qualification.required' => 'Vui lòng thiết lập bộ điều kiện chi tiêu cho mẫu.',
            'spend_qualification.conditions.required' => 'Vui lòng thêm ít nhất một điều kiện chi tiêu.',
            'spend_qualification.conditions.min' => 'Vui lòng thêm ít nhất một điều kiện chi tiêu.',
            'spend_qualification.conditions.*.type.in' => 'Loại điều kiện chi tiêu không hợp lệ.',
            'spend_qualification.conditions.*.min_spend.required' => 'Điều kiện chi tiêu phải có số tiền tối thiểu.',
        ];
    }

    /**
     * Metadata template (không bao gồm điều kiện — điều kiện ở `spendQualification()`.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'slug' => trim((string) $this->input('slug')),
            'description' => $this->input('description'),
            'note' => $this->input('note'),
            'is_active' => (bool) $this->input('is_active', true),
            'sort_order' => (int) $this->input('sort_order', 0),
        ];
    }

    /**
     * Bộ điều kiện chi tiêu của template (đã có khoá `spend_qualification` bắt buộc).
     *
     * @return array<string, mixed>
     */
    public function spendQualification(): array
    {
        return (array) $this->input('spend_qualification');
    }
}