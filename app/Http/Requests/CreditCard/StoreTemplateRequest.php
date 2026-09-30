<?php

namespace App\Http\Requests\CreditCard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lưu cấu hình policy hiện tại của thẻ thành mẫu RIÊNG của user đó.
 *
 * KHÔNG có `user_id` / `owner_user_id` trong payload: `PolicyService::saveAsUserTemplate()`
 * lấy chủ sở hữu từ `$userCard->user_id`. Nếu nhận từ client thì chỉ cần truyền
 * vài id là sao chép được template của người khác.
 */
class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên mẫu chính sách.',
        ];
    }

    public function templateName(): string
    {
        return trim((string) $this->input('name'));
    }

    public function templateDescription(): ?string
    {
        $description = $this->input('description');

        return is_string($description) && trim($description) !== '' ? trim($description) : null;
    }
}
