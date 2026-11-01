<?php

namespace App\Support\WeatherKit;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin client for Apple's WeatherKit REST API. Every failure (no usable token, HTTP error,
 * connection error, unreadable body) yields null; failures are logged at most once per five
 * minutes with only the HTTP status or exception class, never the token, key or coordinates.
 */
final class WeatherKitClient
{
    private const BASE_URL = 'https://weatherkit.apple.com/api/v1';

    private const ATTRIBUTION_URL = 'https://weatherkit.apple.com/attribution';

    private const LANGUAGE = 'en';

    private const TIMEOUT_SECONDS = 4;

    public function __construct(private readonly WeatherKitToken $token = new WeatherKitToken) {}

    /**
     * @return array<string, mixed>|null
     */
    public function weather(float $lat, float $lng, string $timezone): ?array
    {
        return $this->get(
            self::BASE_URL.'/weather/'.self::LANGUAGE.'/'.$lat.'/'.$lng,
            ['dataSets' => 'currentWeather,forecastHourly,forecastDaily', 'timezone' => $timezone],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function attribution(): ?array
    {
        return $this->get(self::ATTRIBUTION_URL.'/'.self::LANGUAGE);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null
     */
    private function get(string $url, array $query = []): ?array
    {
        $token = $this->token->token();

        if ($token === null) {
            $this->logFailure('no usable token');

            return null;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url, $query);
        } catch (Throwable $e) {
            $this->logFailure('exception '.class_basename($e));

            return null;
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(Response $response): ?array
    {
        if (! $response->successful()) {
            $this->logFailure('HTTP '.$response->status());

            return null;
        }

        $data = $response->json();

        if (! is_array($data)) {
            $this->logFailure('unreadable body');

            return null;
        }

        return $data;
    }

    private function logFailure(string $reason): void
    {
        if (Cache::add('weatherkit.failure-logged', true, 300)) {
            Log::warning('WeatherKit request failed', ['reason' => $reason]);
        }
    }
}
