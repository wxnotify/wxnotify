<?php

namespace App\Actions\Weather;

use App\Models\Location;
use App\Support\Timezones;
use App\Support\WeatherKit\WeatherKitClient;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A place's WeatherKit weather, normalised to metric, units-neutral values and shared through the
 * cache by every place within the same 0.01 degree cell and timezone (10 minutes; an unavailable
 * lookup is remembered for 60 seconds so an outage is not hammered). A value WeatherKit did not
 * send is null, never 0. Formatting to the account's unit system happens after the cache.
 *
 * Shape: as_of, timezone, current (temperature_c, feels_like_c, condition, condition_code,
 * wind_kph, wind_direction, humidity, uv_index, is_daylight, gust_kph, pressure_hpa, pressure_trend
 * rising|falling|steady, visibility_km, dew_point_c, cloud_cover percent), hours (the next 24 hours
 * starting after now, each with is_daylight and wind_kph), days (today plus the next 4, dated in
 * the place's timezone) and next_hour (null when WeatherKit has no next-hour forecast for the place:
 * it is only offered in some countries). next_hour keeps up to 75 minutes not yet over, each with its
 * ISO start time so the formatter can measure offsets from its own now even from a 10 minute old
 * cache entry, and Apple's summary periods with their precipitation kind.
 */
class GetPlaceWeather
{
    private const HOURS = 24;

    /** Sixty minutes plus the ten a cached result can age, plus a little slack. */
    private const NEXT_HOUR_MINUTES = 75;

    private const PRESSURE_TRENDS = ['rising', 'falling', 'steady'];

    private const DAYS = 5;

    private const SUCCESS_TTL_SECONDS = 600;

    private const FAILURE_TTL_SECONDS = 60;

    private const TIMEZONE_TTL_DAYS = 7;

    private const TIMEZONE_FAILURE_TTL_SECONDS = 3600;

    public function __construct(private readonly WeatherKitClient $client, private readonly GetNwsPoint $nwsPoint) {}

    /**
     * The place's own timezone; else the one at its coordinates (a phone location has none of its own, and a phone
     * is not always where its owner lives); else the owner's; else the app's.
     */
    private function timezone(Location $location, float $lat, float $lng): string
    {
        if (filled($location->timezone)) {
            return $location->timezone;
        }

        return $this->timezoneAt($lat, $lng) ?? Timezones::forLocation($location) ?? config('app.timezone');
    }

    /**
     * The timezone at a 0.01 degree cell, from api.weather.gov (US coordinates only). Remembered per cell so a
     * moving phone does not look up every position, and a failure is not retried for an hour.
     */
    private function timezoneAt(float $lat, float $lng): ?string
    {
        $key = 'weather-timezone:'.number_format($lat, 2, '.', '').','.number_format($lng, 2, '.', '');
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        try {
            $timezone = ($this->nwsPoint)(new Location(['lat' => $lat, 'lng' => $lng]))['timeZone'] ?? null;
        } catch (Throwable $e) {
            // The card must still show: a lookup error costs only the better timezone.
            Log::warning('Weather timezone lookup failed', ['exception' => $e::class]);
            $timezone = null;
        }

        if (! Timezones::isValid($timezone)) {
            Cache::put($key, false, self::TIMEZONE_FAILURE_TTL_SECONDS);

            return null;
        }

        Cache::put($key, $timezone, now()->addDays(self::TIMEZONE_TTL_DAYS));

        return $timezone;
    }

    /**
     * @return array{as_of: ?string, timezone: string, current: array<string, mixed>, hours: list<array<string, mixed>>, days: list<array<string, mixed>>, next_hour: array{summary: list<array{start: string, end: ?string, condition: string}>, minutes: list<array{start: string, chance: ?int, intensity_mm_h: ?float}>}|null}|null
     */
    public function __invoke(Location $location): ?array
    {
        $lat = round((float) $location->lat, 2) + 0.0;
        $lng = round((float) $location->lng, 2) + 0.0;
        $timezone = $this->timezone($location, $lat, $lng);
        $key = 'weatherkit:place:'.number_format($lat, 2, '.', '').','.number_format($lng, 2, '.', '').':'.$timezone;

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        if ($cached === false) {
            return null;
        }

        $weather = $this->normalise($this->client->weather($lat, $lng, $timezone), $timezone);

        if ($weather === null) {
            Cache::put($key, false, self::FAILURE_TTL_SECONDS);

            return null;
        }

        Cache::put($key, $weather, self::SUCCESS_TTL_SECONDS);

        return $weather;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{as_of: ?string, timezone: string, current: array<string, mixed>, hours: list<array<string, mixed>>, days: list<array<string, mixed>>, next_hour: array{summary: list<array{start: string, end: ?string, condition: string}>, minutes: list<array{start: string, chance: ?int, intensity_mm_h: ?float}>}|null}|null
     */
    private function normalise(?array $payload, string $timezone): ?array
    {
        $current = $payload['currentWeather'] ?? null;

        if (! is_array($current) || ! is_numeric($current['temperature'] ?? null)) {
            return null;
        }

        return [
            'as_of' => $this->time($current['asOf'] ?? null, $timezone),
            'timezone' => $timezone,
            'current' => [
                'temperature_c' => (float) $current['temperature'],
                'feels_like_c' => $this->float($current['temperatureApparent'] ?? null),
                'condition' => $this->humanise($current['conditionCode'] ?? null),
                'condition_code' => $this->code($current['conditionCode'] ?? null),
                'wind_kph' => $this->float($current['windSpeed'] ?? null),
                'wind_direction' => $this->int($current['windDirection'] ?? null),
                'humidity' => $this->percent($current['humidity'] ?? null),
                'uv_index' => $this->int($current['uvIndex'] ?? null),
                'is_daylight' => is_bool($current['daylight'] ?? null) ? $current['daylight'] : null,
                'gust_kph' => $this->float($current['windGust'] ?? null),
                'pressure_hpa' => $this->float($current['pressure'] ?? null),
                'pressure_trend' => in_array($current['pressureTrend'] ?? null, self::PRESSURE_TRENDS, true) ? $current['pressureTrend'] : null,
                'visibility_km' => is_numeric($current['visibility'] ?? null) ? (float) $current['visibility'] / 1000 : null,
                'dew_point_c' => $this->float($current['temperatureDewPoint'] ?? null),
                'cloud_cover' => $this->percent($current['cloudCover'] ?? null),
            ],
            'hours' => $this->hours($payload['forecastHourly']['hours'] ?? null, $timezone),
            'days' => $this->days($payload['forecastDaily']['days'] ?? null, $timezone),
            'next_hour' => $this->nextHour($payload['forecastNextHour'] ?? null, $timezone),
        ];
    }

    /**
     * @return list<array{time: string, temperature_c: float, precip_chance: ?int, condition: string, condition_code: string, is_daylight: ?bool, wind_kph: ?float}>
     */
    private function hours(mixed $hours, string $timezone): array
    {
        if (! is_array($hours)) {
            return [];
        }

        $now = now();
        $result = [];

        foreach ($hours as $hour) {
            $start = is_array($hour) ? $this->parse($hour['forecastStart'] ?? null) : null;

            if ($start === null || $start->lessThanOrEqualTo($now) || ! is_numeric($hour['temperature'] ?? null)) {
                continue;
            }

            $result[] = [
                'time' => $start->setTimezone($timezone)->toIso8601String(),
                'temperature_c' => (float) $hour['temperature'],
                'precip_chance' => $this->percent($hour['precipitationChance'] ?? null),
                'condition' => $this->humanise($hour['conditionCode'] ?? null),
                'condition_code' => $this->code($hour['conditionCode'] ?? null),
                'is_daylight' => is_bool($hour['daylight'] ?? null) ? $hour['daylight'] : null,
                'wind_kph' => $this->float($hour['windSpeed'] ?? null),
            ];

            if (count($result) === self::HOURS) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return list<array{date: string, high_c: ?float, low_c: ?float, precip_chance: ?int, condition: string, condition_code: string, sunrise: ?string, sunset: ?string}>
     */
    private function days(mixed $days, string $timezone): array
    {
        if (! is_array($days)) {
            return [];
        }

        $today = now()->setTimezone($timezone)->toDateString();
        $result = [];

        foreach ($days as $day) {
            $start = is_array($day) ? $this->parse($day['forecastStart'] ?? null) : null;
            $date = $start?->setTimezone($timezone)->toDateString();

            if ($date === null || $date < $today) {
                continue;
            }

            $result[] = [
                'date' => $date,
                'high_c' => $this->float($day['temperatureMax'] ?? null),
                'low_c' => $this->float($day['temperatureMin'] ?? null),
                'precip_chance' => $this->percent($day['precipitationChance'] ?? null),
                'condition' => $this->humanise($day['conditionCode'] ?? null),
                'condition_code' => $this->code($day['conditionCode'] ?? null),
                'sunrise' => $this->time($day['sunrise'] ?? null, $timezone),
                'sunset' => $this->time($day['sunset'] ?? null, $timezone),
            ];

            if (count($result) === self::DAYS) {
                break;
            }
        }

        return $result;
    }

    /**
     * Apple's NextHourForecastData (minutes of ForecastMinute, summary of ForecastPeriodSummary),
     * keeping the minutes not yet over; null when the data set is absent or has no such minute.
     *
     * @return array{summary: list<array{start: string, end: ?string, condition: string}>, minutes: list<array{start: string, chance: ?int, intensity_mm_h: ?float}>}|null
     */
    private function nextHour(mixed $nextHour, string $timezone): ?array
    {
        if (! is_array($nextHour) || ! is_array($nextHour['minutes'] ?? null)) {
            return null;
        }

        $now = now();
        $minutes = [];

        foreach ($nextHour['minutes'] as $minute) {
            $start = is_array($minute) ? $this->parse($minute['startTime'] ?? null) : null;

            if ($start === null || $start->copy()->addMinute()->lessThanOrEqualTo($now)) {
                continue;
            }

            $minutes[] = [
                'start' => $start->setTimezone($timezone)->toIso8601String(),
                'chance' => $this->percent($minute['precipitationChance'] ?? null),
                'intensity_mm_h' => $this->float($minute['precipitationIntensity'] ?? null),
            ];

            if (count($minutes) === self::NEXT_HOUR_MINUTES) {
                break;
            }
        }

        if ($minutes === []) {
            return null;
        }

        $summary = [];

        foreach (is_array($nextHour['summary'] ?? null) ? $nextHour['summary'] : [] as $period) {
            $start = is_array($period) ? $this->parse($period['startTime'] ?? null) : null;

            if ($start === null || ! is_string($period['condition'] ?? null)) {
                continue;
            }

            $summary[] = [
                'start' => $start->setTimezone($timezone)->toIso8601String(),
                'end' => $this->time($period['endTime'] ?? null, $timezone),
                'condition' => $period['condition'],
            ];
        }

        return ['summary' => $summary, 'minutes' => $minutes];
    }

    /**
     * Turns a WeatherKit condition code into a sentence-case label: "MostlyCloudy" becomes
     * "Mostly cloudy", "ScatteredThunderstorms" becomes "Scattered thunderstorms".
     */
    private function humanise(mixed $code): string
    {
        $code = $this->code($code);

        return $code === '' ? '' : Str::ucfirst(Str::lower(Str::headline($code)));
    }

    private function code(mixed $code): string
    {
        return is_string($code) ? $code : '';
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function time(mixed $value, string $timezone): ?string
    {
        return $this->parse($value)?->setTimezone($timezone)->toIso8601String();
    }

    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function percent(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value * 100) : null;
    }
}
