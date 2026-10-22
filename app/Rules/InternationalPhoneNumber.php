<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A NANP or international phone number that PhoneNumber::normalizeInternational() accepts.
 */
class InternationalPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::normalizeInternational($value) === null) {
            $fail(PhoneNumber::INTERNATIONAL_INVALID_MESSAGE);
        }
    }
}
