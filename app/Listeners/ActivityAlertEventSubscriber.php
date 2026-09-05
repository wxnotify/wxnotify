<?php

namespace App\Listeners;

use App\Actions\Visibility\MatchActivityAlertRules;
use App\Enums\ActivityAlertType;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;

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

    public function handleRegistered(Registered $event): void
    {
        app(MatchActivityAlertRules::class)($event->user, ActivityAlertType::AccountCreated);
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
        $events->listen(Registered::class, [self::class, 'handleRegistered']);
    }
}
