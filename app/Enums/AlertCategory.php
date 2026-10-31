<?php

namespace App\Enums;

enum AlertCategory: string
{
    case WeatherAlerts = 'weather_alerts';
    case DailySummary = 'daily_summary';
    case Threshold = 'threshold';
    case SpcWpc = 'spc_wpc';
    case OutlookReport = 'outlook_report';
    case Lightning = 'lightning';

    public function label(): string
    {
        return match ($this) {
            self::WeatherAlerts => 'Weather Alerts',
            self::DailySummary => 'Daily Weather Summary',
            self::Threshold => 'Threshold Alerts',
            self::SpcWpc => 'SPC & WPC Alerts',
            self::OutlookReport => 'Severe Weather Outlook Report',
            self::Lightning => 'Lightning Alerts',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::WeatherAlerts => 'Warnings, watches, and advisories for the products you choose.',
            self::DailySummary => 'A daily push of the 7-day forecast for a saved location.',
            self::Threshold => 'Get notified when a forecast variable crosses a value you set.',
            self::SpcWpc => 'Convective outlooks, mesoscale discussions, and rainfall outlooks.',
            self::OutlookReport => 'A daily overview of severe weather chances over the next week from the SPC outlooks.',
            self::Lightning => 'Lightning detected near a saved location.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::WeatherAlerts => 'heroicon-o-exclamation-triangle',
            self::DailySummary => 'heroicon-o-sun',
            self::Threshold => 'heroicon-o-adjustments-horizontal',
            self::SpcWpc => 'heroicon-o-map',
            self::OutlookReport => 'heroicon-o-cloud',
            self::Lightning => 'heroicon-o-bolt',
        };
    }
}
