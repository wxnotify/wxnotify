<?php

namespace App\Actions\Weather;

use App\Models\Location;
use App\Support\Timezones;
use App\Support\WeatherIcon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The next twelve hours of NWS's hourly forecast. The raw period list is
 * cached for an hour (like the 7-day forecast) and elapsed periods are
 * dropped at read time, so a cached list never serves stale hours. Failed
 * fetches are not cached, so the next call retries.
 */
class GetHourlyForecast
{
    private const HOURS = 12;

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function __invoke(Location $location): ?array
    {
        $url = app(GetNwsPoint::class)($location)['forecastHourly'] ?? null;

        if (! is_string($url)) {
            return null;
        }

        $cacheKey = "nws-hourly:{$url}";
        $periods = Cache::get($cacheKey);

        if (! is_array($periods)) {
            $periods = $this->fetch($url);

            if ($periods === null) {
                return null;
            }

            Cache::put($cacheKey, $periods, now()->addHour());
        }

        $timezone = Timezones::forLocation($location) ?? config('app.timezone');

        $upcoming = collect($periods)
            ->filter(fn (mixed $period): bool => is_array($period)
                && is_string($period['startTime'] ?? null)
                && is_string($period['endTime'] ?? null)
                && Carbon::parse($period['endTime'])->gt(now()))
            ->take(self::HOURS)
            ->map(fn (array $period): array => [
                'time' => Carbon::parse($period['startTime'])->setTimezone($timezone)->toIso8601String(),
                'icon' => WeatherIcon::fromUrl($period['icon'] ?? null),
                'temperature' => $period['temperature'] ?? null,
                'unit' => $period['temperatureUnit'] ?? 'F',
                'precip_chance' => is_numeric($chance = data_get($period, 'probabilityOfPrecipitation.value')) ? (int) round($chance) : null,
                'wind' => trim(($period['windSpeed'] ?? '').' '.($period['windDirection'] ?? '')),
                'summary' => $period['shortForecast'] ?? '',
            ])
            ->values()
            ->all();

        return $upcoming === [] ? null : $upcoming;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function fetch(string $url): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => sprintf('(%s, %s)', config('app.name'), config('app.url'))])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException) {
            Log::warning('NWS hourly forecast connection failed');

            return null;
        }

        if (! $response->successful()) {
            Log::warning('NWS hourly forecast fetch failed', ['status' => $response->status()]);

            return null;
        }

        $periods = $response->json('properties.periods');

        return is_array($periods) ? $periods : null;
    }
}
