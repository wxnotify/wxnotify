<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A US or Canadian phone number that PhoneNumber::normalize() accepts.
 */
class NanpPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::normalize($value) === null) {
            $fail(PhoneNumber::INVALID_MESSAGE);
        }
    }
}
