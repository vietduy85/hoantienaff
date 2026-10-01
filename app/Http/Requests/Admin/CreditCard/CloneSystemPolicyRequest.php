<?php

namespace App\Http\Requests\Admin\CreditCard;

/**
 * Clone một chính sách hoàn tiền hệ thống từ payload của EDITOR ADMIN.
 *
 * Payload đúng bằng payload trang "Chỉnh sửa"/"Tạo mới" gửi đi (`payload()`
 * trong `partials/editor.blade.php`): metadata template + cấu hình blueprint
 * nguồn (đang hydrate) + `source_version_id`. Nên mọi rule validate tái dùng
 * `StoreSystemPolicyVersionRequest`, chỉ thêm bắt buộc `name` (tạo chính sách
 * MỚI phải có tên).
 *
 * Route API đã gắn `permission:credit-cards.manage`; phân quyền phạm vi hệ
 * thống do `PolicyCloneService::createSystemPolicyFromEditor()` bảo vệ tiếp.
 */
class CloneSystemPolicyRequest extends StoreSystemPolicyVersionRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = ['required', 'string', 'max:150'];

        return $rules;
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Vui lòng nhập tên chính sách mới.',
            'name.max' => 'Tên chính sách không được quá 150 ký tự.',
        ]);
    }
}
