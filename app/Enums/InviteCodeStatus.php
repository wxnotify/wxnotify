<?php

namespace App\Enums;

/**
 * Where an invite code stands: usable, used up, past its expiry or revoked by staff.
 */
enum InviteCodeStatus: string
{
    case Active = 'active';
    case UsedUp = 'used_up';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::UsedUp => 'Used up',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::UsedUp => 'gray',
            self::Expired => 'warning',
            self::Revoked => 'danger',
        };
    }
}
