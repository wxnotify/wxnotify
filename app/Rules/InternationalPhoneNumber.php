<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A NANP or international phone number that PhoneNumber::normalizeInternational() accepts, or,
 * when a type narrows it, that the type's own normaliser accepts (with the type's message).
 */
class InternationalPhoneNumber implements ValidationRule
{
    /**
     * @param  (Closure(string): ?string)|null  $normalize  the type's normaliser; normalizeInternational() when null
     */
    public function __construct(
        private readonly ?Closure $normalize = null,
        private readonly string $message = PhoneNumber::INTERNATIONAL_INVALID_MESSAGE,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalize = $this->normalize ?? PhoneNumber::normalizeInternational(...);

        if (! is_string($value) || $normalize($value) === null) {
            $fail($this->message);
        }
    }
}
