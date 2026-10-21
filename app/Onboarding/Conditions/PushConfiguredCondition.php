<?php

namespace App\Onboarding\Conditions;

use Illuminate\Database\Eloquent\Model;

/**
 * A readable Firebase service-account file, which is what mobile push needs.
 */
final class PushConfiguredCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'Mobile push is set up';
    }

    protected function check(Model $subject): bool
    {
        $serviceAccount = config('services.firebase.service_account');

        return is_string($serviceAccount)
            && $serviceAccount !== ''
            && is_file($serviceAccount)
            && is_readable($serviceAccount);
    }
}
