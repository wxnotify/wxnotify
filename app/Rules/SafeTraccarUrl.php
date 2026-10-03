<?php

namespace App\Rules;

use App\Support\TraccarUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeTraccarUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $violation = app(TraccarUrlGuard::class)->violation((string) $value);

        if ($violation !== null) {
            $fail($violation);
        }
    }
}
