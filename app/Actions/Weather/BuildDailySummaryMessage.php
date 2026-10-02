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
            $lines[] = "{$day['name']}: {$day['shortForecast']}{$this->temperatureClause('high', $day, $display)}.";
        }

        if ($night !== null) {
            $lines[] = "{$night['name']}: {$night['shortForecast']}{$this->temperatureClause('low', $night, $display)}.";
        }

        return implode(' ', $lines);
    }

    /**
     * ", high 88°F" — or nothing when NWS sent no numeric temperature for the
     * period, so a missing value is never shown as a made-up 0°F. NWS publishes
     * /forecast periods in degF; honour the period's own unit flag anyway so a
     * Celsius period is never treated as Fahrenheit.
     *
     * @param  array<string, mixed>  $period
     */
    private function temperatureClause(string $label, array $period, Units $units): string
    {
        $value = $period['temperature'] ?? null;

        if (! is_numeric($value)) {
            return '';
        }

        $fahrenheit = strtoupper((string) ($period['temperatureUnit'] ?? 'F')) === 'C'
            ? (new Units(UnitSystem::Metric))->temperatureToFahrenheit((float) $value)
            : (float) $value;

        return ", {$label} {$units->temperature($fahrenheit)}";
    }
}
