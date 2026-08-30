<?php

namespace App\Enums;

enum ActivityAlertScope: string
{
    case AllSubscribers = 'all_subscribers';
    case SpecificUsers = 'specific_users';

    public function label(): string
    {
        return match ($this) {
            self::AllSubscribers => 'All subscribers',
            self::SpecificUsers => 'Specific subscribers',
        };
    }
}
