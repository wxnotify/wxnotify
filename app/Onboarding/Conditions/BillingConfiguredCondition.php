<?php

namespace App\Onboarding\Conditions;

use App\Enums\Secret;
use App\Support\Secrets;
use Illuminate\Database\Eloquent\Model;

/**
 * A Stripe secret or a Paddle API key is set, stored in App Settings > Secrets or in .env.
 */
final class BillingConfiguredCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'Billing is set up';
    }

    protected function check(Model $subject): bool
    {
        return filled(Secrets::get(Secret::StripeSecretKey)) || filled(Secrets::get(Secret::PaddleApiKey));
    }
}
