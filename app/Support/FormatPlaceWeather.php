<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * Turns a GetPlaceWeather result (metric, units-neutral, up to 10 minutes old) into the card the
 * API serves: values in the subscriber's unit system, times in the place's timezone, hours already
 * past and days before today dropped, and every field the source did not send omitted.
 */
final class FormatPlaceWeather
{
    private const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

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
        ];

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
        $wind = null;

        if (isset($current['wind_kph'])) {
            $wind = $units->speed(Units::mphFromKilometersPerHour((float) $current['wind_kph']));

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
        ]);
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
