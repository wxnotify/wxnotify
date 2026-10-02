<?php

namespace App\Notifications;

use App\Notifications\Concerns\PrefixesMailSubject;
use Filament\Auth\Notifications\VerifyEmailChange as BaseNotification;

class PrefixedVerifyEmailChange extends BaseNotification
{
    use PrefixesMailSubject;
}
