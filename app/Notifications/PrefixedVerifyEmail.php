<?php

namespace App\Notifications;

use App\Notifications\Concerns\PrefixesMailSubject;
use Filament\Auth\Notifications\VerifyEmail as BaseNotification;

class PrefixedVerifyEmail extends BaseNotification
{
    use PrefixesMailSubject;
}
