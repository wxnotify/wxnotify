<?php

namespace App\Rules;

use App\Actions\Weather\SearchUgcCodes;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A county (SSCnnn) or forecast zone (SSZnnn) UGC code that exists in the weather tables.
 */
class KnownUgcCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(SearchUgcCodes::class)->exists($value)) {
            $fail('The :attribute must be a known county or zone code.');
        }
    }
}
