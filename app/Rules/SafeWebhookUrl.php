<?php

namespace App\Rules;

use App\Support\Delivery\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeWebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $violation = app(WebhookUrlGuard::class)->violation((string) $value);

        if ($violation !== null) {
            $fail($violation);
        }
    }
}
