<?php

namespace App\Enums;

enum UnitSystem: string
{
    case Imperial = 'imperial';
    case Metric = 'metric';

    public function label(): string
    {
        return match ($this) {
            self::Imperial => 'Imperial (°F, mph, mi)',
            self::Metric => 'Metric (°C, km/h, km)',
        };
    }
}
