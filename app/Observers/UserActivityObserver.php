<?php

namespace App\Observers;

use App\Actions\Visibility\MatchActivityAlertRules;
use App\Enums\ActivityAlertType;
use App\Models\User;

class UserActivityObserver
{
    public function created(User $user): void
    {
        app(MatchActivityAlertRules::class)($user, ActivityAlertType::AccountCreated);
    }

    public function updated(User $user): void
    {
        $match = app(MatchActivityAlertRules::class);

        if ($user->wasChanged('name')) {
            $match($user, ActivityAlertType::NameChanged);
        }

        if ($user->wasChanged('email')) {
            $match($user, ActivityAlertType::EmailChanged);
        }

        if ($user->wasChanged('password')) {
            $match($user, ActivityAlertType::PasswordChanged);
        }

        if ($user->wasChanged('app_authentication_secret')) {
            $match($user, ActivityAlertType::MfaChanged);
        }
    }

    public function deleted(User $user): void
    {
        app(MatchActivityAlertRules::class)($user, ActivityAlertType::AccountDeleted);
    }
}
