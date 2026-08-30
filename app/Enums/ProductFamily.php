<?php

namespace App\Enums;

enum ProductFamily: string
{
    case Warning = 'warning';
    case Watch = 'watch';
    case Advisory = 'advisory';
    case Statement = 'statement';
    case SpcWpc = 'spc_wpc';
    case ForecastDiscussion = 'forecast_discussion';

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Warnings',
            self::Watch => 'Watches',
            self::Advisory => 'Advisories',
            self::Statement => 'Statements',
            self::SpcWpc => 'SPC & WPC Products',
            self::ForecastDiscussion => 'Forecast Discussions',
        };
    }
}
