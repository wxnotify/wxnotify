<?php

namespace App\Notifications;

use App\Notifications\Concerns\PrefixesMailSubject;
use Filament\Auth\Notifications\ResetPassword as BaseNotification;

class PrefixedResetPassword extends BaseNotification
{
    use PrefixesMailSubject;
}
