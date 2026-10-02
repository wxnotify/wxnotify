<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * Sunrise/sunset for today in the location's timezone, computed locally by
 * PHP's date_sun_info() (NWS publishes neither). date_sun_info() resolves "the
 * day" from the timestamp's UTC date, so a zone far east of UTC (Pacific/
 * Auckland) given its own local noon would land on the previous calendar day;
 * anchoring on UTC noon of the *local* calendar date returns the right day
 * everywhere (verified for Denver, Honolulu and Auckland).
 */
class SunTimes
{
    /**
     * @return array{sunrise: string, sunset: string}|null
     */
    public static function forLocation(Location $location): ?array
    {
        if (! $location->hasFix()) {
            return null;
        }

        $timezone = $location->timezone ?? config('app.timezone');
        $localToday = Carbon::now($timezone);
        $anchor = Carbon::create($localToday->year, $localToday->month, $localToday->day, 12, 0, 0, 'UTC');

        $info = date_sun_info($anchor->getTimestamp(), (float) $location->lat, (float) $location->lng);

        if (! is_int($info['sunrise'] ?? null) || ! is_int($info['sunset'] ?? null)) {
            return null;
        }

        return [
            'sunrise' => Carbon::createFromTimestamp($info['sunrise'], $timezone)->toIso8601String(),
            'sunset' => Carbon::createFromTimestamp($info['sunset'], $timezone)->toIso8601String(),
        ];
    }
}
