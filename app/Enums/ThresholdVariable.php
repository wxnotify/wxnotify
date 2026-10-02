<?php

namespace App\Enums;

use App\Support\Units;

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

    public function unit(UnitSystem $system = UnitSystem::Imperial): string
    {
        $metric = $system === UnitSystem::Metric;

        return match ($this) {
            self::Temperature, self::FeelsLike, self::Dewpoint => $metric ? '°C' : '°F',
            self::WindSpeed, self::Gusts => $metric ? 'km/h' : 'mph',
            self::Humidity, self::PrecipChance => '%',
        };
    }

    /**
     * The stored (imperial) value as the subscriber's system shows it.
     */
    public function displayValue(float $canonical, UnitSystem $system): float
    {
        $units = new Units($system);

        return match ($this) {
            self::Temperature, self::FeelsLike, self::Dewpoint => $units->temperatureValue($canonical),
            self::WindSpeed, self::Gusts => $units->speedValue($canonical),
            self::Humidity, self::PrecipChance => $canonical,
        };
    }

    /**
     * A value typed in the subscriber's system, as the stored imperial value.
     */
    public function canonicalValue(float $display, UnitSystem $system): float
    {
        $units = new Units($system);

        return match ($this) {
            self::Temperature, self::FeelsLike, self::Dewpoint => $units->temperatureToFahrenheit($display),
            self::WindSpeed, self::Gusts => $units->speedToMph($display),
            self::Humidity, self::PrecipChance => $display,
        };
    }

    /**
     * How many decimals the subscriber sees and types. A Metric wind speed is
     * whole km/h: storage keeps 0.1 mph, which is coarser than 0.1 km/h, so a
     * typed fractional km/h would not come back as the same number.
     */
    public function displayDecimals(UnitSystem $system): int
    {
        return match ($this) {
            self::WindSpeed, self::Gusts => $system === UnitSystem::Metric ? 0 : 1,
            default => 1,
        };
    }

    /**
     * The display value plus the unit, the shape a rule name has always used
     * ("32.0°F"; Metric "0.0°C", Metric wind "32km/h").
     */
    public function format(float $canonical, UnitSystem $system): string
    {
        return number_format($this->displayValue($canonical, $system), $this->displayDecimals($system), '.', '').$this->unit($system);
    }
}
