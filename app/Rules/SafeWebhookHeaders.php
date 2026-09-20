<?php

namespace App\Rules;

use App\Support\Delivery\WebhookHeaderGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeWebhookHeaders implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $violation = app(WebhookHeaderGuard::class)->violation($value);

        if ($violation !== null) {
            $fail($violation);
        }
    }
}
