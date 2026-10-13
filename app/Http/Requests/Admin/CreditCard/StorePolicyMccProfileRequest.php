<?php

namespace App\Http\Requests\Admin\CreditCard;

use App\Http\Requests\Admin\CreditCard\Concerns\ValidatesMccProfileRows;
use App\Models\CreditCard\PolicyTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Tạo mới một HỒ SƠ MCC chính sách.
 *
 * Business rules:
 *   - Chính sách phải tồn tại và thuộc phạm vi ADMIN quản lý (scope = system).
 *   - Tên hồ sơ là riêng của hồ sơ, KHÔNG đổi tên template gốc.
 *   - Hai bảng dòng MCC (included / excluded): mã phải có trong master, không trùng,
 *     không cùng một mã nằm cả hai bảng.
 *   - Tài liệu ngân hàng: chỉ PDF/XLSX/XLS, tối đa 10 MB mỗi file.
 */
class StorePolicyMccProfileRequest extends FormRequest
{
    use ValidatesMccProfileRows;

    public const DOCUMENT_MAX_KB = 10240;

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
            'policy_template_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $exists = PolicyTemplate::query()
                        ->whereKey((int) $value)
                        ->where('scope', PolicyTemplate::SCOPE_SYSTEM)
                        ->exists();

                    if (! $exists) {
                        $fail('Chính sách hoàn tiền không tồn tại hoặc không thuộc phạm vi quản lý.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:5000'],

            'annual_fee_primary' => ['nullable', 'numeric', 'min:0'],
            'annual_fee_supplementary' => ['nullable', 'numeric', 'min:0'],
            'annual_spend_for_annual_fee_waiver' => ['nullable', 'numeric', 'min:0'],

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
            'documents.*' => ['file', 'mimes:pdf,xlsx,xls', 'max:'.self::DOCUMENT_MAX_KB],
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
            'policy_template_id.required' => 'Vui lòng chọn chính sách hoàn tiền.',
            'name.required' => 'Vui lòng nhập tên MCC chính sách.',
            'name.max' => 'Tên MCC chính sách không được vượt quá 191 ký tự.',
            'annual_fee_primary.numeric' => 'Phí thường niên thẻ chính phải là số.',
            'annual_fee_primary.min' => 'Phí thường niên thẻ chính không được âm.',
            'annual_fee_supplementary.numeric' => 'Phí thường niên thẻ phụ phải là số.',
            'annual_fee_supplementary.min' => 'Phí thường niên thẻ phụ không được âm.',
            'annual_spend_for_annual_fee_waiver.numeric' => 'Mức chi tiêu miễn phí thường niên phải là số.',
            'annual_spend_for_annual_fee_waiver.min' => 'Mức chi tiêu miễn phí thường niên không được âm.',
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
            'policy_template_id' => (int) $this->input('policy_template_id'),
            'name' => (string) $this->input('name'),
            'note' => $this->input('note'),
            'annual_fee_primary' => $this->input('annual_fee_primary'),
            'annual_fee_supplementary' => $this->input('annual_fee_supplementary'),
            'annual_spend_for_annual_fee_waiver' => $this->input('annual_spend_for_annual_fee_waiver'),
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
