<?php

namespace App\Actions\Weather;

use App\Models\Location;
use App\Support\Timezones;
use App\Support\WeatherIcon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The latest observation from the nearest station that has a usable one.
 * NWS lists a point's stations nearest-first, but the nearest is not always
 * the best: some report nulls or stop reporting, so up to five are tried and
 * the first with a temperature no older than three hours wins.
 *
 * Station ids come from an external response and are interpolated into a URL,
 * so they must match a plain station-code pattern first.
 */
class GetCurrentConditions
{
    private const MAX_STATIONS = 5;

    private const MAX_AGE_HOURS = 3;

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(Location $location): ?array
    {
        $point = app(GetNwsPoint::class)($location);
        $stationsUrl = $point['observationStations'] ?? null;

        if (! is_string($stationsUrl)) {
            return null;
        }

        $lat = number_format((float) $location->lat, 4, '.', '');
        $lng = number_format((float) $location->lng, 4, '.', '');

        return Cache::remember(
            "nws-conditions:{$lat},{$lng}",
            now()->addMinutes(10),
            fn (): ?array => $this->resolve($stationsUrl, $location),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(string $stationsUrl, Location $location): ?array
    {
        $stations = $this->get($stationsUrl);

        if ($stations === null) {
            Log::warning('NWS station list connection failed');

            return null;
        }

        if (! $stations->successful()) {
            Log::warning('NWS station list lookup failed', ['status' => $stations->status()]);

            return null;
        }

        $features = $stations->json('features');

        foreach (array_slice(is_array($features) ? $features : [], 0, self::MAX_STATIONS) as $feature) {
            $id = is_array($feature) ? data_get($feature, 'properties.stationIdentifier') : null;

            if (! is_string($id) || preg_match('/^[A-Z0-9]{3,5}\z/', $id) !== 1) {
                continue;
            }

            $observation = $this->get("https://api.weather.gov/stations/{$id}/observations/latest");

            if ($observation === null) {
                Log::warning('NWS observation connection failed');

                continue;
            }

            if (! $observation->successful()) {
                continue;
            }

            $properties = $observation->json('properties');

            if (is_array($properties) && $this->isUsable($properties)) {
                return $this->normalize($feature, $properties, $location);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function isUsable(array $properties): bool
    {
        if ($this->value($properties, 'temperature') === null || ! is_string($properties['timestamp'] ?? null)) {
            return false;
        }

        try {
            return Carbon::parse($properties['timestamp'])->gte(now()->subHours(self::MAX_AGE_HOURS));
        } catch (InvalidFormatException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $feature
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function normalize(array $feature, array $properties, Location $location): array
    {
        $timezone = Timezones::forLocation($location) ?? config('app.timezone');
        $temperatureF = $this->celsiusToFahrenheit($this->value($properties, 'temperature'));
        $feelsLikeC = $this->value($properties, 'windChill') ?? $this->value($properties, 'heatIndex');
        $windSpeed = $this->kmhToMph($this->value($properties, 'windSpeed'));
        $windDegrees = $this->value($properties, 'windDirection');
        $pressurePa = $this->value($properties, 'barometricPressure') ?? $this->value($properties, 'seaLevelPressure');
        $visibilityM = $this->value($properties, 'visibility');
        $elevationM = data_get($feature, 'properties.elevation.value');
        $coordinates = data_get($feature, 'geometry.coordinates');

        return [
            'station' => [
                'id' => $feature['properties']['stationIdentifier'],
                'name' => $feature['properties']['name'] ?? null,
                'lat' => is_array($coordinates) && isset($coordinates[1]) ? (float) $coordinates[1] : null,
                'lng' => is_array($coordinates) && isset($coordinates[0]) ? (float) $coordinates[0] : null,
                'elevation_ft' => is_numeric($elevationM) ? (float) $elevationM * 3.28084 : null,
            ],
            'observed_at' => Carbon::parse($properties['timestamp'])->setTimezone($timezone)->toIso8601String(),
            'description' => $properties['textDescription'] ?? null,
            'icon' => WeatherIcon::fromUrl($properties['icon'] ?? null),
            'temperature_f' => $temperatureF,
            'feels_like_f' => $feelsLikeC !== null ? $this->celsiusToFahrenheit($feelsLikeC) : $temperatureF,
            'humidity_pct' => $this->value($properties, 'relativeHumidity'),
            'wind' => [
                'direction' => $windSpeed !== null && $windSpeed >= 0.5 && $windDegrees !== null ? $this->compass($windDegrees) : null,
                'speed_mph' => $windSpeed,
                'gust_mph' => $this->kmhToMph($this->value($properties, 'windGust')),
            ],
            'barometer_inhg' => $pressurePa !== null ? $pressurePa / 3386.389 : null,
            'dewpoint_f' => $this->celsiusToFahrenheit($this->value($properties, 'dewpoint')),
            'visibility_mi' => $visibilityM !== null ? $visibilityM / 1609.344 : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function value(array $properties, string $key): ?float
    {
        $value = data_get($properties, "{$key}.value");

        return is_numeric($value) ? (float) $value : null;
    }

    private function celsiusToFahrenheit(?float $celsius): ?float
    {
        return $celsius === null ? null : $celsius * 9 / 5 + 32;
    }

    private function kmhToMph(?float $kmh): ?float
    {
        return $kmh === null ? null : $kmh / 1.609344;
    }

    private function compass(float $degrees): string
    {
        $points = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        $normalized = fmod(fmod($degrees, 360) + 360, 360);

        return $points[(int) round($normalized / 22.5) % 16];
    }

    private function get(string $url): ?Response
    {
        try {
            return Http::withHeaders(['User-Agent' => sprintf('(%s, %s)', config('app.name'), config('app.url'))])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }
    }
}
