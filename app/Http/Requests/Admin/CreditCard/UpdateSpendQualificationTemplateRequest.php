<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Models\CreditCard\SpendQualificationTemplate;
use Illuminate\Support\Str;

/**
 * Cập nhật MẪU "Điều kiện hoàn tiền đặc biệt" (PATCH).
 *
 * PATCH cho phép sửa từng phần: nếu KHÔNG gửi `spend_qualification` thì bộ điều
 * kiện hiện tại được giữ nguyên. Điều kiện không gửi kèm = không đổi, gửi mảng =
 * ghi đè toàn bộ (server chuẩn hoá + replace), gửi `null` = xoá hẳn điều kiện.
 */
class UpdateSpendQualificationTemplateRequest extends StoreSpendQualificationTemplateRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        foreach (array_keys($rules) as $field) {
            // `sometimes` để PATCH từng phần; `spend_qualification` là optional.
            $rules[$field] = array_merge(['sometimes'], $rules[$field]);
        }

        // Slug phải duy nhất NHƯNG được phép trùng chính nó khi không đổi.
        $rules['slug'] = [
            'sometimes',
            'string',
            'max:150',
            function (string $attribute, $value, $fail): void {
                $slug = Str::slug((string) $value);
                $current = $this->route('template');

                if ($slug === '') {
                    $fail('Slug không hợp lệ.');

                    return;
                }

                $exists = SpendQualificationTemplate::query()
                    ->when($current !== null, fn ($query) => $query->whereKeyNot((int) $current))
                    ->where('slug', $slug)
                    ->exists();

                if ($exists) {
                    $fail(sprintf('Slug "%s" đã tồn tại.', $slug));
                }
            },
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên mẫu điều kiện.',
            'slug.required' => 'Vui lòng nhập slug mẫu điều kiện.',
            'spend_qualification.conditions.min' => 'Vui lòng thêm ít nhất một điều kiện chi tiêu.',
            'spend_qualification.conditions.*.type.in' => 'Loại điều kiện chi tiêu không hợp lệ.',
            'spend_qualification.conditions.*.min_spend.required' => 'Điều kiện chi tiêu phải có số tiền tối thiểu.',
        ];
    }

    public function hasSpendQualification(): bool
    {
        return $this->has('spend_qualification');
    }

    public function spendQualification(): ?array
    {
        return $this->input('spend_qualification');
    }

    /**
     * Metadata template: CHỈ các trường có mặt trong payload PATCH.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $metadata = [];

        foreach (['name', 'slug', 'description', 'note', 'is_active', 'sort_order'] as $field) {
            if ($this->has($field)) {
                $metadata[$field] = match ($field) {
                    'name', 'slug' => trim((string) $this->input($field)),
                    'is_active' => (bool) $this->input($field),
                    'sort_order' => (int) $this->input($field),
                    default => $this->input($field),
                };
            }
        }

        return $metadata;
    }
}