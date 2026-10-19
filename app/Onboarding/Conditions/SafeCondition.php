<?php

namespace App\Onboarding\Conditions;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use Wallacemartinss\FilamentOnboarding\Contracts\HasConditionLabel;
use Wallacemartinss\FilamentOnboarding\Contracts\OnboardingCondition;

/**
 * Base for the owner setup checklist's conditions. They run on every admin page, so each one
 * must be cheap, and a check that throws (a malformed cached value, an unreadable file) counts
 * as "not done" rather than taking the panel down.
 */
abstract class SafeCondition implements HasConditionLabel, OnboardingCondition
{
    final public function isCompleted(Model $subject, ?Model $scope = null): bool
    {
        try {
            return $this->check($subject);
        } catch (Throwable) {
            return false;
        }
    }

    abstract protected function check(Model $subject): bool;
}
