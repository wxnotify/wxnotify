<?php

namespace App\Onboarding\Conditions;

use Illuminate\Database\Eloquent\Model;

/**
 * A readable Firebase service-account file plus VAPID public and private keys.
 */
final class PushConfiguredCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'Mobile and browser push are set up';
    }

    protected function check(Model $subject): bool
    {
        $serviceAccount = config('services.firebase.service_account');

        return is_string($serviceAccount)
            && $serviceAccount !== ''
            && is_file($serviceAccount)
            && is_readable($serviceAccount)
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }
}
