<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWithdrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Vui lòng nhập số tiền rút.',
            'amount.numeric' => 'Số tiền không hợp lệ.',
            'amount.min' => 'Số tiền rút tối thiểu là 10.000đ.',
        ];
    }
}
