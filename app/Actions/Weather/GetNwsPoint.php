<?php

namespace App\Actions\Weather;

use App\Models\Location;
use Illuminate\Http\Client\ConnectionException;
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
            try {
                $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
                    ->timeout(10)
                    ->get("https://api.weather.gov/points/{$lat},{$lng}");
            } catch (ConnectionException) {
                Log::warning('NWS point lookup connection failed');

                return null;
            }

            if (! $response->successful()) {
                Log::warning('NWS point lookup failed', ['status' => $response->status()]);

                return null;
            }

            $properties = $response->json('properties');

            if (! is_array($properties)) {
                return null;
            }

            $forecast = $this->trustedUrl($properties['forecast'] ?? null);
            $forecastHourly = $this->trustedUrl($properties['forecastHourly'] ?? null);
            $observationStations = $this->trustedUrl($properties['observationStations'] ?? null);

            if ($forecast === null && $forecastHourly === null && $observationStations === null) {
                Log::warning('NWS point lookup returned no usable URLs');

                return null;
            }

            return [
                'forecast' => $forecast,
                'forecastHourly' => $forecastHourly,
                'observationStations' => $observationStations,
                'radarStation' => $properties['radarStation'] ?? null,
                'timeZone' => $properties['timeZone'] ?? null,
            ];
        });
    }

    /**
     * URLs in an NWS response are fetched later, so only api.weather.gov ones are kept.
     */
    private function trustedUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://api.weather.gov/') ? $url : null;
    }

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}
