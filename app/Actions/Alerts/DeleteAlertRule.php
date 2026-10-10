<?php

namespace App\Actions\Alerts;

use App\Models\AlertRule;
use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

class DeleteAlertRule
{
    public function __invoke(User $user, AlertRule $rule): void
    {
        app(CauserResolver::class)->withCauser($user, fn () => $rule->delete());
    }
}
