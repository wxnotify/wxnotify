<?php

namespace App\Actions\Alerts;

use App\Models\AlertRule;
use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

class SetAlertRuleActive
{
    public function __invoke(User $user, AlertRule $rule, bool $active): AlertRule
    {
        app(CauserResolver::class)->withCauser($user, fn () => $rule->update(['is_active' => $active]));

        return $rule;
    }
}
