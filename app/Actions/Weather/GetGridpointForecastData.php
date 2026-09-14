<?php

namespace App\Actions\Weather;

use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches NWS's raw numerical gridpoint data (/gridpoints/{office}/{x},{y},
 * not the human-readable /forecast or /forecast/hourly endpoints) — the only
 * one of the three that actually carries every variable Threshold Alerts
 * promise. Confirmed directly against the real API: /forecast's 12-hour
 * periods carry only temperature and precipitation chance; /forecast/hourly
 * adds dewpoint and humidity but still no feels-like or wind gust figures.
 * Only the raw gridpoint endpoint has all seven (temperature,
 * apparentTemperature, windSpeed, windGust, dewpoint, relativeHumidity,
 * probabilityOfPrecipitation), each as an hours-to-days-resolution
 * ISO 8601 interval time series in metric units (confirmed: degC, km/h).
 *
 * Also fetches today's day/night period boundaries from the regular
 * /forecast endpoint (already resolved and cached by GetSevenDayForecast's
 * points lookup) — real sunrise/sunset-based boundaries, not a fixed
 * "6am-8pm" guess, to slice the gridpoint series into "day" vs "overnight"
 * windows for Threshold Alerts' timeframe option.
 */
class GetGridpointForecastData
{
    /**
     * NWS gridpoint property name per ThresholdVariable, and the metric
     * unit it's published in (confirmed via unitCode on a real response).
     *
     * @var array<string, array{property: string, unit: string}>
     */
    private const VARIABLE_MAP = [
        'temperature' => ['property' => 'temperature', 'unit' => 'degC'],
        'feels_like' => ['property' => 'apparentTemperature', 'unit' => 'degC'],
        'wind_speed' => ['property' => 'windSpeed', 'unit' => 'km_h'],
        'gusts' => ['property' => 'windGust', 'unit' => 'km_h'],
        'dewpoint' => ['property' => 'dewpoint', 'unit' => 'degC'],
        'humidity' => ['property' => 'relativeHumidity', 'unit' => 'percent'],
        'precip_chance' => ['property' => 'probabilityOfPrecipitation', 'unit' => 'percent'],
    ];

    /**
     * @return array{periods: array<int, array<string, mixed>>, series: array<string, array<int, array{start: Carbon, end: Carbon, value: float}>>}|null
     */
    public function __invoke(Location $location): ?array
    {
        $lat = (float) $location->lat;
        $lng = (float) $location->lng;

        $urls = $this->resolveUrls($lat, $lng);

        if ($urls === null) {
            return null;
        }

        $gridData = $this->fetchGridData($urls['gridData']);
        $periods = $this->fetchPeriods($urls['forecast']);

        if ($gridData === null) {
            return null;
        }

        $series = [];

        foreach (self::VARIABLE_MAP as $variable => $config) {
            $series[$variable] = $this->parseSeries($gridData[$config['property']] ?? null, $config['unit']);
        }

        return ['periods' => $periods, 'series' => $series];
    }

    /**
     * @return array{forecast: string, gridData: string}|null
     */
    private function resolveUrls(float $lat, float $lng): ?array
    {
        return Cache::rememberForever(
            "nws-gridpoint-urls:{$lat},{$lng}",
            function () use ($lat, $lng): ?array {
                $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
                    ->get("https://api.weather.gov/points/{$lat},{$lng}");

                if (! $response->successful()) {
                    Log::warning('NWS points lookup failed', ['lat' => $lat, 'lng' => $lng, 'status' => $response->status()]);

                    return null;
                }

                $forecast = $response->json('properties.forecast');
                $gridData = $response->json('properties.forecastGridData');

                if ($forecast === null || $gridData === null) {
                    return null;
                }

                return ['forecast' => $forecast, 'gridData' => $gridData];
            },
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchGridData(string $url): ?array
    {
        return Cache::remember(
            "nws-grid-data:{$url}",
            now()->addHour(),
            function () use ($url): ?array {
                $response = Http::withHeaders(['User-Agent' => $this->userAgent()])->get($url);

                if (! $response->successful()) {
                    Log::warning('NWS gridpoint data fetch failed', ['url' => $url, 'status' => $response->status()]);

                    return null;
                }

                return $response->json('properties');
            },
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPeriods(string $forecastUrl): array
    {
        return Cache::remember(
            "nws-forecast:{$forecastUrl}",
            now()->addHour(),
            function () use ($forecastUrl): array {
                $response = Http::withHeaders(['User-Agent' => $this->userAgent()])->get($forecastUrl);

                if (! $response->successful()) {
                    return [];
                }

                return $response->json('properties.periods') ?? [];
            },
        );
    }

    /**
     * Parses NWS's `{validTime: "2026-09-13T17:00:00+00:00/PT1H", value: n}`
     * time-series shape into concrete start/end Carbon instances plus a
     * value converted to the unit subscribers actually see (°F, mph).
     *
     * @param  array{values: array<int, array{validTime: string, value: ?float}>}|null  $property
     * @return array<int, array{start: Carbon, end: Carbon, value: float}>
     */
    private function parseSeries(?array $property, string $unit): array
    {
        $entries = [];

        foreach ($property['values'] ?? [] as $entry) {
            if ($entry['value'] === null) {
                continue;
            }

            [$startIso, $durationIso] = explode('/', $entry['validTime'], 2);
            $start = Carbon::parse($startIso);
            $end = $start->copy()->add(new \DateInterval($durationIso));

            $entries[] = [
                'start' => $start,
                'end' => $end,
                'value' => $this->convert($entry['value'], $unit),
            ];
        }

        return $entries;
    }

    private function convert(float $value, string $unit): float
    {
        return match ($unit) {
            'degC' => $value * 9 / 5 + 32,
            'km_h' => $value / 1.60934,
            default => $value,
        };
    }

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}
