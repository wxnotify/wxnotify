<?php

namespace App\Onboarding\Conditions;

use Illuminate\Database\Eloquent\Model;

/**
 * Both VAPID keys for browser push are set.
 */
final class WebPushConfiguredCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'Browser push is set up';
    }

    protected function check(Model $subject): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }
}
