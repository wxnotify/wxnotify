<?php

namespace App\Actions\Weather;

use App\Models\Location;
use App\Support\SunTimes;
use App\Support\WeatherIcon;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the queue-serializable report the Full Daily Summary email renders:
 * plain nested arrays only (no models, Carbon instances or closures), with
 * timestamps as ISO-8601 strings in the location's timezone.
 *
 * The 7-day forecast is the one required section: without it there is nothing
 * to send (null), exactly like Brief. Every other section is built inside its
 * own guard so a failure omits just that section.
 */
class BuildDailySummaryReport
{
    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(Location $location, bool $includeTestAlerts = false): ?array
    {
        $forecast = $this->guard('forecast', fn (): array => app(GetSevenDayForecast::class)($location)) ?? [];

        if ($forecast === []) {
            return null;
        }

        $timezone = $location->timezone ?? config('app.timezone');

        return [
            'location' => $location->label,
            'timezone' => $timezone,
            'sun' => $this->guard('sun', fn () => SunTimes::forLocation($location)),
            'alerts' => $this->guard('alerts', fn (): array => app(GetActiveAlertsForLocation::class)($location, $includeTestAlerts)) ?? [],
            'current' => $this->guard('current conditions', fn () => app(GetCurrentConditions::class)($location)),
            'radar_site' => $this->guard('radar site', fn (): ?string => $this->radarSite($location)),
            'hourly' => $this->guard('hourly forecast', fn () => app(GetHourlyForecast::class)($location)),
            'periods' => $this->periods($forecast, $timezone),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $forecast
     * @return array<int, array<string, mixed>>
     */
    private function periods(array $forecast, string $timezone): array
    {
        return array_values(array_map(fn (array $period): array => [
            'name' => (string) ($period['name'] ?? ''),
            'date' => isset($period['startTime']) ? Carbon::parse($period['startTime'])->setTimezone($timezone)->toIso8601String() : null,
            'summary' => (string) ($period['shortForecast'] ?? ''),
            'label' => ($period['isDaytime'] ?? true) ? 'High' : 'Low',
            'temperature' => $period['temperature'] ?? null,
            'unit' => (string) ($period['temperatureUnit'] ?? 'F'),
            'icon' => WeatherIcon::fromUrl($period['icon'] ?? null),
        ], $forecast));
    }

    private function radarSite(Location $location): ?string
    {
        $site = app(GetNwsPoint::class)($location)['radarStation'] ?? null;

        return is_string($site) && preg_match('/^[A-Z0-9]{3,4}\z/', $site) === 1 ? $site : null;
    }

    /**
     * Fixed strings only in the log: an exception message can carry URLs or
     * response fragments.
     *
     * @template T
     *
     * @param  Closure(): T  $section
     * @return T|null
     */
    private function guard(string $name, Closure $section): mixed
    {
        try {
            return $section();
        } catch (Throwable $e) {
            Log::warning("Daily summary {$name} section failed", ['exception' => $e::class]);

            return null;
        }
    }
}
