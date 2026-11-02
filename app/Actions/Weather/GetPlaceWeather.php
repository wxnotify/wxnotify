<?php

namespace App\Actions\Weather;

use App\Models\Location;
use App\Support\Timezones;
use App\Support\WeatherKit\WeatherKitClient;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A place's WeatherKit weather, normalised to metric, units-neutral values and shared through the
 * cache by every place within the same 0.01 degree cell and timezone (10 minutes; an unavailable
 * lookup is remembered for 60 seconds so an outage is not hammered). A value WeatherKit did not
 * send is null, never 0. Formatting to the account's unit system happens after the cache.
 *
 * Shape: as_of, timezone, current (temperature_c, feels_like_c, condition, condition_code,
 * wind_kph, wind_direction, humidity, uv_index, is_daylight), hours (the next 6 hours starting
 * after now, each with is_daylight) and days (today plus the next 4, dated in the place's timezone).
 */
class GetPlaceWeather
{
    private const HOURS = 6;

    private const DAYS = 5;

    private const SUCCESS_TTL_SECONDS = 600;

    private const FAILURE_TTL_SECONDS = 60;

    public function __construct(private readonly WeatherKitClient $client) {}

    /**
     * @return array{as_of: ?string, timezone: string, current: array<string, mixed>, hours: list<array<string, mixed>>, days: list<array<string, mixed>>}|null
     */
    public function __invoke(Location $location): ?array
    {
        $timezone = Timezones::forLocation($location) ?? config('app.timezone');
        $lat = round((float) $location->lat, 2) + 0.0;
        $lng = round((float) $location->lng, 2) + 0.0;
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
     * @return array{as_of: ?string, timezone: string, current: array<string, mixed>, hours: list<array<string, mixed>>, days: list<array<string, mixed>>}|null
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
            ],
            'hours' => $this->hours($payload['forecastHourly']['hours'] ?? null, $timezone),
            'days' => $this->days($payload['forecastDaily']['days'] ?? null, $timezone),
        ];
    }

    /**
     * @return list<array{time: string, temperature_c: float, precip_chance: ?int, condition: string, condition_code: string, is_daylight: ?bool}>
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
