<?php

namespace App\Onboarding\Conditions;

use Illuminate\Database\Eloquent\Model;

/**
 * Chatwoot base URL and website token are both present.
 */
final class SupportWidgetConfiguredCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'The support chat widget is set up';
    }

    protected function check(Model $subject): bool
    {
        return filled(config('services.chatwoot.base_url')) && filled(config('services.chatwoot.website_token'));
    }
}
