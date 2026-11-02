<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * Turns a GetPlaceWeather result (metric, units-neutral, up to 10 minutes old) into the card the
 * API serves: values in the subscriber's unit system, times in the place's timezone, hours already
 * past and days before today dropped, and every field the source did not send omitted.
 *
 * next_hour is one short sentence about precipitation in the 60 minutes from now, measured from the
 * minutes' own start times (the cached data can be 10 minutes old). A minute is wet when its chance is
 * at least 50% or its intensity at least 0.1 mm/h (the lightest measurable drizzle, so float noise
 * never reads as rain). A dry hour or rain all hour is only claimed when the minutes reach at least
 * 55 minutes ahead; with less data only a start or an end inside the data is reported.
 */
final class FormatPlaceWeather
{
    private const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

    private const NEXT_HOUR_SECONDS = 3600;

    private const NEXT_HOUR_COVERED_SECONDS = 3300;

    private const WET_CHANCE_PERCENT = 50;

    private const WET_INTENSITY_MM_H = 0.1;

    private const PRESSURE_TRENDS = ['rising', 'falling', 'steady'];

    /** Apple PrecipitationType values with their own word; precipitation, mixed and anything else are generic. */
    private const PRECIPITATION_WORDS = ['rain' => 'Rain', 'snow' => 'Snow', 'sleet' => 'Sleet', 'hail' => 'Hail'];

    /**
     * @param  array<string, mixed>  $weather
     * @return array<string, mixed>
     */
    public static function forUser(array $weather, Units $units, Location $location): array
    {
        $timezone = (string) $weather['timezone'];
        $now = now();
        $today = $now->copy()->setTimezone($timezone)->toDateString();

        $card = [
            'id' => $location->id,
            'label' => (string) $location->label,
            'available' => true,
        ] + self::present(['as_of' => $weather['as_of'] ?? null]) + [
            'timezone' => $timezone,
            'current' => self::current($weather['current'] ?? [], $units),
            'hours' => [],
            'days' => [],
        ] + self::present(['next_hour' => self::nextHour($weather['next_hour'] ?? null, $now)]);

        foreach ($weather['hours'] ?? [] as $hour) {
            $time = Carbon::parse($hour['time']);

            if ($time->lessThanOrEqualTo($now)) {
                continue;
            }

            $card['hours'][] = [
                'label' => $time->copy()->setTimezone($timezone)->format('g A'),
                'time' => $hour['time'],
                'temperature' => self::temperature($hour['temperature_c'], $units),
            ] + self::present([
                'precip_chance' => self::percent($hour['precip_chance'] ?? null),
            ]) + ['condition_code' => $hour['condition_code']] + self::present([
                'is_daylight' => $hour['is_daylight'] ?? null,
                'wind' => self::speedOrNull($hour['wind_kph'] ?? null, $units),
            ]);
        }

        foreach ($weather['days'] ?? [] as $day) {
            if ($day['date'] < $today) {
                continue;
            }

            $card['days'][] = [
                'label' => $day['date'] === $today ? 'Today' : Carbon::parse($day['date'], $timezone)->format('D'),
                'date' => $day['date'],
            ] + self::present([
                'high' => self::temperatureOrNull($day['high_c'] ?? null, $units),
                'low' => self::temperatureOrNull($day['low_c'] ?? null, $units),
                'precip_chance' => self::percent($day['precip_chance'] ?? null),
            ]) + ['condition_code' => $day['condition_code']] + self::present([
                'sunrise' => self::clock($day['sunrise'] ?? null, $timezone),
                'sunset' => self::clock($day['sunset'] ?? null, $timezone),
            ]);
        }

        return $card;
    }

    /**
     * @return array{id: int, label: string, available: false}
     */
    public static function unavailable(Location $location): array
    {
        return [
            'id' => $location->id,
            'label' => (string) $location->label,
            'available' => false,
        ];
    }

    /**
     * The 8-point compass name for a bearing in degrees.
     */
    public static function compass(int|float $degrees): string
    {
        $index = (int) round(fmod(fmod($degrees, 360) + 360, 360) / 45) % 8;

        return self::COMPASS[$index];
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private static function current(array $current, Units $units): array
    {
        $temperature = (float) $current['temperature_c'];
        $wind = self::speedOrNull($current['wind_kph'] ?? null, $units);

        if ($wind !== null) {
            if (isset($current['wind_direction'])) {
                $wind .= ' '.self::compass($current['wind_direction']);
            }
        }

        return [
            'temperature' => self::temperature($temperature, $units),
            'temperature_value' => (int) round($units->temperatureValue(Units::fahrenheitFromCelsius($temperature))),
            'condition' => $current['condition'] ?? '',
            'condition_code' => $current['condition_code'] ?? '',
        ] + self::present([
            'feels_like' => self::temperatureOrNull($current['feels_like_c'] ?? null, $units),
            'wind' => $wind,
            'humidity' => self::percent($current['humidity'] ?? null),
            'uv_index' => $current['uv_index'] ?? null,
            'is_daylight' => $current['is_daylight'] ?? null,
            'gust' => self::speedOrNull($current['gust_kph'] ?? null, $units),
            'pressure' => isset($current['pressure_hpa']) ? $units->pressure(Units::inHgFromHectopascals((float) $current['pressure_hpa'])) : null,
            'pressure_trend' => in_array($current['pressure_trend'] ?? null, self::PRESSURE_TRENDS, true) ? $current['pressure_trend'] : null,
            'visibility' => self::visibility($current['visibility_km'] ?? null, $units),
            'dew_point' => self::temperatureOrNull($current['dew_point_c'] ?? null, $units),
            'cloud_cover' => self::percent($current['cloud_cover'] ?? null),
        ]);
    }

    /**
     * @param  array{summary?: list<array{start: string, end: ?string, condition: string}>, minutes?: list<array{start: string, chance: ?int, intensity_mm_h: ?float}>}|null  $nextHour
     */
    private static function nextHour(?array $nextHour, Carbon $now): ?string
    {
        $window = [];

        foreach ($nextHour['minutes'] ?? [] as $minute) {
            $start = Carbon::parse($minute['start']);
            $offset = (int) $now->diffInSeconds($start, false);

            if ($offset <= -60 || $offset >= self::NEXT_HOUR_SECONDS) {
                continue;
            }

            $window[] = ['offset' => $offset, 'start' => $start, 'wet' => self::isWet($minute)];
        }

        if ($window === []) {
            return null;
        }

        $covered = end($window)['offset'] + 60 >= self::NEXT_HOUR_COVERED_SECONDS;
        $summary = $nextHour['summary'] ?? [];

        if ($window[0]['wet'] && $window[0]['offset'] < 60) {
            foreach ($window as $minute) {
                if (! $minute['wet']) {
                    return self::precipitationWord($summary, $now).' ending in '.self::minutesFrom($minute['offset']).' min';
                }
            }

            return $covered ? self::precipitationWord($summary, $now).' for the next hour' : null;
        }

        foreach ($window as $minute) {
            if ($minute['wet']) {
                return self::precipitationWord($summary, $minute['start']).' starting in '.self::minutesFrom($minute['offset']).' min';
            }
        }

        return $covered ? 'No rain expected in the next hour' : null;
    }

    /**
     * @param  array{chance: ?int, intensity_mm_h: ?float}  $minute
     */
    private static function isWet(array $minute): bool
    {
        $chance = $minute['chance'] ?? null;
        $intensity = $minute['intensity_mm_h'] ?? null;

        return ($chance !== null && $chance >= self::WET_CHANCE_PERCENT)
            || ($intensity !== null && $intensity >= self::WET_INTENSITY_MM_H);
    }

    /**
     * The word for the summary period's precipitation at $at, else the first period with any, else
     * the generic "Precipitation".
     *
     * @param  list<array{start: string, end: ?string, condition: string}>  $summary
     */
    private static function precipitationWord(array $summary, Carbon $at): string
    {
        $wetPeriods = array_values(array_filter($summary, fn (array $period): bool => $period['condition'] !== 'clear'));

        foreach ($wetPeriods as $period) {
            if (Carbon::parse($period['start'])->lessThanOrEqualTo($at) && ($period['end'] === null || $at->lessThan(Carbon::parse($period['end'])))) {
                return self::PRECIPITATION_WORDS[$period['condition']] ?? 'Precipitation';
            }
        }

        return self::PRECIPITATION_WORDS[$wetPeriods[0]['condition'] ?? ''] ?? 'Precipitation';
    }

    private static function minutesFrom(int $seconds): int
    {
        return max(1, (int) round($seconds / 60));
    }

    private static function speedOrNull(mixed $kph, Units $units): ?string
    {
        return $kph === null ? null : $units->speed(Units::mphFromKilometersPerHour((float) $kph));
    }

    /**
     * Whole miles or km from 10 up, one decimal below, so a foggy 0.8 km never reads as "0 mi".
     */
    private static function visibility(mixed $kilometers, Units $units): ?string
    {
        if ($kilometers === null) {
            return null;
        }

        $miles = Units::milesFromKilometers((float) $kilometers);

        return $units->distance($miles, $units->distanceValue($miles) < 10 ? 1 : 0);
    }

    private static function temperature(float|int $celsius, Units $units): string
    {
        return Units::number($units->temperatureValue(Units::fahrenheitFromCelsius((float) $celsius))).'°';
    }

    private static function temperatureOrNull(mixed $celsius, Units $units): ?string
    {
        return $celsius === null ? null : self::temperature($celsius, $units);
    }

    private static function percent(mixed $value): ?string
    {
        return $value === null ? null : $value.'%';
    }

    private static function clock(?string $iso, string $timezone): ?string
    {
        return $iso === null ? null : Carbon::parse($iso)->setTimezone($timezone)->format('g:i A');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function present(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null);
    }
}
