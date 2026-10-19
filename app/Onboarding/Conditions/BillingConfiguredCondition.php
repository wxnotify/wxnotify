<?php

namespace App\Onboarding\Conditions;

use Illuminate\Database\Eloquent\Model;

/**
 * A Stripe secret or a Paddle API key is present.
 */
final class BillingConfiguredCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'Billing is set up';
    }

    protected function check(Model $subject): bool
    {
        return filled(config('services.stripe.secret')) || filled(config('services.paddle.api_key'));
    }
}
