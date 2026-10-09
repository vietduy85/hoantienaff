<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Models\CreditCard\SpendQualificationTemplate;
use Illuminate\Support\Str;

/**
 * Cập nhật MẪU "Điều kiện hoàn tiền đặc biệt" (PATCH).
 *
 * PATCH cho phép sửa từng phần. Ba trường hợp của khoá `spend_qualification`:
 *   - KHÔNG gửi khoá       ⇒ `hasSpendQualification()` = false, controller giữ
 *                            nguyên bộ điều kiện hiện tại (không đụng tới).
 *   - Gửi mảng điều kiện   ⇒ ghi đè toàn bộ (server chuẩn hoá + replace).
 *   - Gửi mảng rỗng `[]`   ⇒ xoá bộ điều kiện đang có.
 *
 * `null` không phải đường xoá: lớp cha (qua luật `required`) chặn giá trị null
 * bằng lỗi validate 422 — chỉ giao diện đầy đủ mới gửi khoá này dạng mảng.
 *
 * Lưu ý kiểu trả về: `spendQualification(): ?array` ở lớp con KHÔNG được phép
 * "mở rộng" so với lớp cha, nên kiểu `?array` được khai báo tại
 * `StoreSpendQualificationTemplateRequest` (xem docblock ở đó).
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

                if ($slug === '') {
                    $fail('Slug không hợp lệ.');

                    return;
                }

                // Route đã implicit-bind `{template}` thành MODEL, nên phải lấy
                // khoá qua `getKey()` — không được ép `(int)` thẳng vào model.
                $current = $this->route('template');
                $currentKey = $current instanceof SpendQualificationTemplate ? $current->getKey() : $current;

                $exists = SpendQualificationTemplate::query()
                    ->when($currentKey !== null, fn ($query) => $query->whereKeyNot($currentKey))
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

    /**
     * Khoá `spend_qualification` CÓ MẶT trong payload PATCH hay không.
     *
     * Đây là cờ quyết định "ghi đè điều kiện" hay "giữ nguyên" — `false` nghĩa là
     * client chỉ sửa metadata, controller bỏ qua hoàn toàn bộ điều kiện hiện tại.
     */
    public function hasSpendQualification(): bool
    {
        return $this->has('spend_qualification');
    }

    /**
     * Giá trị RAW của `spend_qualification` (mảng điều kiện), hoặc `null` khi khoá
     * vắng. Chỉ gọi khi `hasSpendQualification()` = true.
     *
     * @return array<string, mixed>|null
     */
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