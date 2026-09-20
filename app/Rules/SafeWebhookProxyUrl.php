<?php

namespace App\Rules;

use App\Support\Delivery\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeWebhookProxyUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $violation = app(WebhookUrlGuard::class)->proxyViolation((string) $value);

        if ($violation !== null) {
            $fail($violation);
        }
    }
}
