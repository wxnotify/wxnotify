<?php

namespace App\Onboarding\Conditions;

use Illuminate\Database\Eloquent\Model;

/**
 * A real mailer is configured: not the log or array driver, and not the bundled Mailpit, which only
 * catches mail for the owner to read.
 */
final class MailConfiguredCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'Outgoing email is set up';
    }

    protected function check(Model $subject): bool
    {
        $mailer = config('mail.default');

        return filled($mailer) && ! in_array($mailer, ['log', 'array', 'mailpit'], true);
    }
}
