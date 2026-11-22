<?php

namespace App\Enums;

/**
 * Who may create a subscriber account: anyone, only people holding an invite
 * code, or nobody (staff create accounts by hand).
 */
enum SignUpPolicy: string
{
    case Open = 'open';
    case InviteOnly = 'invite_only';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InviteOnly => 'Invite only',
            self::Closed => 'Closed',
        };
    }
}
