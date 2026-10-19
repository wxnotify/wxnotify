<?php

namespace App\Onboarding\Conditions;

use Illuminate\Database\Eloquent\Model;

/**
 * True on the hosted service; ignores the subject.
 */
final class IsCloudModeCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'This is the hosted (cloud) service';
    }

    protected function check(Model $subject): bool
    {
        return config('wxnotify.mode') === 'cloud';
    }
}
