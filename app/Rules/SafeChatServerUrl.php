<?php

namespace App\Rules;

use App\Support\Delivery\ChatServerGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A subscriber-supplied chat server address that ChatServerGuard allows, failing with the
 * guard's fixed message (which never repeats the address).
 */
class SafeChatServerUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $violation = is_string($value)
            ? app(ChatServerGuard::class)->violation($value)
            : ChatServerGuard::NEEDS_URL;

        if ($violation !== null) {
            $fail($violation);
        }
    }
}
