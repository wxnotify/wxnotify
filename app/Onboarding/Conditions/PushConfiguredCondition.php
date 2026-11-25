<?php

namespace App\Onboarding\Conditions;

use App\Support\Delivery\FcmClient;
use Illuminate\Database\Eloquent\Model;

/**
 * A usable Firebase service account (stored in App settings or in a readable key file), which is what mobile push needs.
 */
final class PushConfiguredCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'Mobile push is set up';
    }

    protected function check(Model $subject): bool
    {
        return app(FcmClient::class)->isConfigured();
    }
}
