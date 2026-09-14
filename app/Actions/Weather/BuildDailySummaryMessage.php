<?php

namespace App\Actions\Weather;

use App\Models\Location;

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
    public function __invoke(Location $location): ?string
    {
        $periods = app(GetSevenDayForecast::class)($location);

        $day = collect($periods)->first(fn (array $p): bool => $p['isDaytime'] === true);
        $night = collect($periods)->first(fn (array $p): bool => $p['isDaytime'] === false);

        if ($day === null && $night === null) {
            return null;
        }

        $lines = [];

        if ($day !== null) {
            $lines[] = "{$day['name']}: {$day['shortForecast']}, high {$day['temperature']}\u{b0}{$day['temperatureUnit']}.";
        }

        if ($night !== null) {
            $lines[] = "{$night['name']}: {$night['shortForecast']}, low {$night['temperature']}\u{b0}{$night['temperatureUnit']}.";
        }

        return implode(' ', $lines);
    }
}
