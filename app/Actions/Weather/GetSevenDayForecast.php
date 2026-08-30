<?php

namespace App\Actions\Weather;

use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetSevenDayForecast
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(Location $location): array
    {
        $forecastUrl = $this->resolveForecastUrl((float) $location->lat, (float) $location->lng);

        if (! $forecastUrl) {
            return [];
        }

        return Cache::remember(
            "nws-forecast:{$forecastUrl}",
            now()->addHour(),
            fn () => $this->fetchPeriods($forecastUrl),
        );
    }

    protected function resolveForecastUrl(float $lat, float $lng): ?string
    {
        return Cache::rememberForever(
            "nws-gridpoint:{$lat},{$lng}",
            function () use ($lat, $lng) {
                $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
                    ->get("https://api.weather.gov/points/{$lat},{$lng}");

                if (! $response->successful()) {
                    Log::warning('NWS points lookup failed', [
                        'lat' => $lat,
                        'lng' => $lng,
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                return $response->json('properties.forecast');
            },
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchPeriods(string $forecastUrl): array
    {
        $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
            ->get($forecastUrl);

        if (! $response->successful()) {
            Log::warning('NWS forecast fetch failed', [
                'url' => $forecastUrl,
                'status' => $response->status(),
            ]);

            return [];
        }

        return $response->json('properties.periods') ?? [];
    }

    protected function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}
