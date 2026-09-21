<?php

namespace App\Listeners;

use App\Actions\Visibility\MatchActivityAlertRules;
use App\Enums\ActivityAlertType;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class ActivityAlertEventSubscriber
{
    public function handleLogin(Login $event): void
    {
        app(MatchActivityAlertRules::class)($event->user, ActivityAlertType::SignedIn);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        app(MatchActivityAlertRules::class)($event->user, ActivityAlertType::SignedOut);
    }
}
