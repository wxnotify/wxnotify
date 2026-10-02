<?php

namespace App\Actions\Weather;

use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One cached api.weather.gov /points lookup shared by the Full Daily Summary's
 * actions. NWS redirects any coordinate with more than four decimals to the
 * four-decimal URL, so the request is built rounded. The 7-day TTL lets an NWS
 * gridpoint remap eventually refresh; a failed lookup is never cached.
 */
class GetNwsPoint
{
    /**
     * @return array{forecast: ?string, forecastHourly: ?string, observationStations: ?string, radarStation: ?string, timeZone: ?string}|null
     */
    public function __invoke(Location $location): ?array
    {
        if (! $location->hasFix()) {
            return null;
        }

        $lat = number_format((float) $location->lat, 4, '.', '');
        $lng = number_format((float) $location->lng, 4, '.', '');

        return Cache::remember("nws-point:{$lat},{$lng}", now()->addDays(7), function () use ($lat, $lng): ?array {
            $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout(10)
                ->get("https://api.weather.gov/points/{$lat},{$lng}");

            if (! $response->successful()) {
                Log::warning('NWS point lookup failed', ['status' => $response->status()]);

                return null;
            }

            $properties = $response->json('properties');

            if (! is_array($properties)) {
                return null;
            }

            return [
                'forecast' => $properties['forecast'] ?? null,
                'forecastHourly' => $properties['forecastHourly'] ?? null,
                'observationStations' => $properties['observationStations'] ?? null,
                'radarStation' => $properties['radarStation'] ?? null,
                'timeZone' => $properties['timeZone'] ?? null,
            ];
        });
    }

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}
