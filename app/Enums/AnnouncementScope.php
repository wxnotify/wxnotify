<?php

namespace App\Enums;

enum AnnouncementScope: string
{
    case AllSubscribers = 'all_subscribers';
    case SpecificTiers = 'specific_tiers';
    case SpecificUsers = 'specific_users';

    public function label(): string
    {
        return match ($this) {
            self::AllSubscribers => 'All subscribers',
            self::SpecificTiers => 'Specific tiers',
            self::SpecificUsers => 'Specific subscribers',
        };
    }
}
