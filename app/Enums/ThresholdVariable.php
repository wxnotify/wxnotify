<?php

namespace App\Enums;

enum ThresholdVariable: string
{
    case Temperature = 'temperature';
    case FeelsLike = 'feels_like';
    case WindSpeed = 'wind_speed';
    case Gusts = 'gusts';
    case Dewpoint = 'dewpoint';
    case Humidity = 'humidity';
    case PrecipChance = 'precip_chance';

    public function label(): string
    {
        return match ($this) {
            self::Temperature => 'Temperature',
            self::FeelsLike => 'Feels like',
            self::WindSpeed => 'Wind speed',
            self::Gusts => 'Wind gusts',
            self::Dewpoint => 'Dewpoint',
            self::Humidity => 'Relative humidity',
            self::PrecipChance => 'Precipitation chance',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Temperature, self::FeelsLike, self::Dewpoint => '°F',
            self::WindSpeed, self::Gusts => 'mph',
            self::Humidity, self::PrecipChance => '%',
        };
    }
}
