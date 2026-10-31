<?php

namespace App\Enums;

enum OutlookReportMode: string
{
    case EveryDay = 'every_day';
    case OnlyWhenForecast = 'only_when_forecast';

    public function label(): string
    {
        return match ($this) {
            self::EveryDay => 'Every day',
            self::OnlyWhenForecast => 'Only when severe weather is forecast',
        };
    }
}
