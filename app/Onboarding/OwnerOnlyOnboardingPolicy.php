<?php

namespace App\Onboarding;

use Illuminate\Contracts\Auth\Authenticatable;
use Wallacemartinss\FilamentOnboarding\Policies\OnboardingPolicy;

/**
 * The onboarding flows, steps and conditions hold the owner setup checklist, so only the owner may
 * see or change them in the admin panel. Named for all three models in
 * config('filament-onboarding.policies'). For an owner the plugin's default answers apply.
 */
final class OwnerOnlyOnboardingPolicy extends OnboardingPolicy
{
    public function before(Authenticatable $user): ?bool
    {
        return $user->is_owner === true ? null : false;
    }
}
