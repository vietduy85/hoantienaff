<?php

namespace App\Rules;

use App\Services\PromotionNews\Support\PromotionNewsUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class HttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! PromotionNewsUrl::isValid($value)) {
            $fail('Trường :attribute phải là một URL http/https hợp lệ.');
        }
    }
}
