<?php

namespace App\Onboarding\Conditions;

use Illuminate\Database\Eloquent\Model;

/**
 * The signed-in user is the installation owner.
 */
final class IsOwnerCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'You are the owner';
    }

    protected function check(Model $subject): bool
    {
        return $subject->is_owner === true;
    }
}
