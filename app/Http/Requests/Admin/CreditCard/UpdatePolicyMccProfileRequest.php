<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Http\Requests\Admin\CreditCard\Concerns\ValidatesMccProfileRows;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Cập nhật một HỒ SƠ MCC chính sách.
 *
 * Chính sách (`policy_template_id`) KHÔNG đổi sau khi tạo — hồ sơ gắn cố định với
 * một chính sách để tra cứu lấy đúng tỷ lệ hoàn tiền từ blueprint.
 */
class UpdatePolicyMccProfileRequest extends FormRequest
{
    use ValidatesMccProfileRows;

    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermissionTo('credit-cards.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:5000'],

            'included' => ['nullable', 'array', 'max:200'],
            'included.*.category_name' => ['nullable', 'string', 'max:191'],
            'included.*.description' => ['nullable', 'string', 'max:2000'],
            'included.*.note' => ['nullable', 'string', 'max:2000'],
            'included.*.codes' => ['nullable', 'array'],
            'included.*.codes.*' => ['nullable', 'string', 'max:8'],

            'excluded' => ['nullable', 'array', 'max:200'],
            'excluded.*.category_name' => ['nullable', 'string', 'max:191'],
            'excluded.*.description' => ['nullable', 'string', 'max:2000'],
            'excluded.*.note' => ['nullable', 'string', 'max:2000'],
            'excluded.*.codes' => ['nullable', 'array'],
            'excluded.*.codes.*' => ['nullable', 'string', 'max:8'],

            'documents' => ['nullable', 'array', 'max:20'],
            'documents.*' => ['file', 'mimes:pdf,xlsx,xls', 'max:'.StorePolicyMccProfileRequest::DOCUMENT_MAX_KB],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $inner) => $this->validateMccRows($inner));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên MCC chính sách.',
            'name.max' => 'Tên MCC chính sách không được vượt quá 191 ký tự.',
            'documents.*.mimes' => 'Tài liệu chỉ chấp nhận định dạng PDF, XLSX hoặc XLS.',
            'documents.*.max' => 'Mỗi tài liệu không được vượt quá 10 MB.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_merge([
            'name' => (string) $this->input('name'),
            'note' => $this->input('note'),
        ], $this->profileRowPayload());
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function documentFiles(): array
    {
        $files = $this->file('documents', []);

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return is_array($files) ? array_values(array_filter($files)) : [];
    }
}
