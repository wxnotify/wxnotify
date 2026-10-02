<?php

namespace App\Notifications\Concerns;

use App\Support\MessagePrefix;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * `parent::` inside a trait method resolves against the class that USES the
 * trait, not where the trait is defined -- so each class below correctly
 * calls up to its own Filament parent's toMail().
 */
trait PrefixesMailSubject
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $message = parent::toMail($notifiable);

        $message->subject(MessagePrefix::apply((string) $message->subject));

        return $message;
    }
}
