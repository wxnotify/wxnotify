<?php

namespace App\Actions\Weather;

use App\Support\WeatherKit\WeatherKitClient;
use Illuminate\Support\Facades\Cache;

/**
 * The Apple Weather attribution every WeatherKit card must carry: the service name, the light and
 * dark logos (Apple sends partial paths, resolved against weatherkit.apple.com; the largest scale
 * sent is used) and the legal attribution page. Cached for a day; an unavailable lookup is
 * remembered for 60 seconds.
 */
class GetWeatherAttribution
{
    private const ASSET_BASE_URL = 'https://weatherkit.apple.com';

    /**
     * Apple's "legal link to other data sources" that its attribution requirements name; the
     * attribution response carries only the logos and the service name.
     */
    private const LEGAL_URL = 'https://developer.apple.com/weatherkit/data-source-attribution/';

    private const DEFAULT_NAME = 'Weather';

    private const CACHE_KEY = 'weatherkit:attribution';

    private const SUCCESS_TTL_SECONDS = 86400;

    private const FAILURE_TTL_SECONDS = 60;

    public function __construct(private readonly WeatherKitClient $client) {}

    /**
     * @return array{name: string, logo_light_url: ?string, logo_dark_url: ?string, legal_url: ?string}|null
     */
    public function __invoke(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        if ($cached === false) {
            return null;
        }

        $payload = $this->client->attribution();

        if ($payload === null) {
            Cache::put(self::CACHE_KEY, false, self::FAILURE_TTL_SECONDS);

            return null;
        }

        $serviceName = $payload['serviceName'] ?? null;

        $attribution = [
            'name' => is_string($serviceName) && $serviceName !== '' ? $serviceName : self::DEFAULT_NAME,
            'logo_light_url' => $this->logo($payload, 'logoLight'),
            'logo_dark_url' => $this->logo($payload, 'logoDark'),
            'legal_url' => self::LEGAL_URL,
        ];

        Cache::put(self::CACHE_KEY, $attribution, self::SUCCESS_TTL_SECONDS);

        return $attribution;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logo(array $payload, string $prefix): ?string
    {
        foreach (['@3x', '@2x', '@1x'] as $scale) {
            $path = $payload[$prefix.$scale] ?? null;

            if (! is_string($path) || $path === '') {
                continue;
            }

            if (str_starts_with($path, 'https://')) {
                return $path;
            }

            return str_starts_with($path, '/') ? self::ASSET_BASE_URL.$path : null;
        }

        return null;
    }
}
