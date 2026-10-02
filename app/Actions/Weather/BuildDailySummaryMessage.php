<?php

namespace App\Actions\Weather;

use App\Enums\UnitSystem;
use App\Models\Location;
use App\Support\Units;

/**
 * Composes Daily Weather Summary's message from the same NWS 12-hour
 * periods GetSevenDayForecast already fetches for the 7-day forecast
 * widget — no separate data source, per the plan's own scoping for this
 * category. Returns null only when the forecast couldn't be fetched at all
 * (not when there's simply nothing notable — a summary always has
 * something to say, unlike a Threshold Alert).
 */
class BuildDailySummaryMessage
{
    public function __invoke(Location $location, UnitSystem $units = UnitSystem::Imperial): ?string
    {
        $periods = app(GetSevenDayForecast::class)($location);
        $display = new Units($units);

        $day = collect($periods)->first(fn (array $p): bool => $p['isDaytime'] === true);
        $night = collect($periods)->first(fn (array $p): bool => $p['isDaytime'] === false);

        if ($day === null && $night === null) {
            return null;
        }

        $lines = [];

        if ($day !== null) {
            $lines[] = "{$day['name']}: {$day['shortForecast']}, high {$this->temperature($day, $display)}.";
        }

        if ($night !== null) {
            $lines[] = "{$night['name']}: {$night['shortForecast']}, low {$this->temperature($night, $display)}.";
        }

        return implode(' ', $lines);
    }

    /**
     * NWS publishes /forecast periods in degF; honour the period's own unit
     * flag anyway so a Celsius period is never treated as Fahrenheit.
     *
     * @param  array<string, mixed>  $period
     */
    private function temperature(array $period, Units $units): string
    {
        $value = (float) $period['temperature'];
        $fahrenheit = strtoupper((string) ($period['temperatureUnit'] ?? 'F')) === 'C' ? $value * 9 / 5 + 32 : $value;

        return $units->temperature($fahrenheit);
    }
}
