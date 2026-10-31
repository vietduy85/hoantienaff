<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public const SECTION_INFO = 'info';

    public const SECTION_BANK = 'bank';

    public function rules(): array
    {
        $info = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'zalo' => ['nullable', 'string', 'max:50'],
        ];

        $bank = [
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', Rule::in(config('banks.list'))],
        ];

        // Mỗi form chỉ được cập nhật đúng nhóm trường của nó, tránh lưu STK mà ghi đè luôn thông tin cá nhân.
        return match ($this->input('section')) {
            self::SECTION_INFO => $info,
            self::SECTION_BANK => $bank,
            default => $info + $bank,
        };
    }
}
