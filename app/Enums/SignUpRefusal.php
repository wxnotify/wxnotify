<?php

namespace App\Enums;

/**
 * Why the sign-up gate turned someone away.
 */
enum SignUpRefusal: string
{
    case Closed = 'closed';
    case InvalidCode = 'invalid_code';
    case EmailMismatch = 'email_mismatch';

    public function message(): string
    {
        return match ($this) {
            self::Closed => 'Sign-ups are closed. Ask the administrator for an account.',
            self::InvalidCode => "That invite code isn't valid.",
            self::EmailMismatch => 'This invite is for a different email address.',
        };
    }
}
