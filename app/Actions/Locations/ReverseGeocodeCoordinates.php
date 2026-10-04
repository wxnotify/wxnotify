<?php

namespace App\Actions\Locations;

use App\Enums\Integration;
use App\Support\Delivery\HeartbeatUrlGuard;
use App\Support\Integrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The nearest street address for a coordinate, from a Nominatim reverse-geocoding
 * endpoint: the admin-set one (Integrations tab) or the configured Nominatim URL
 * (the public OpenStreetMap instance by default, the same service the stationary
 * map picker uses from the subscriber's browser).
 *
 * Follows OSM's usage policy: one request per second app-wide, an identifying
 * User-Agent, and results cached by coordinates rounded to about 11 m (a parked
 * device never asks twice). Nothing is sent when the call is throttled or cached.
 * The endpoint is staff-set, so it goes through HeartbeatUrlGuard (private hosts are
 * fine for a self-hosted Nominatim, metadata and link-local are not), is pinned to
 * the addresses the guard vetted, and never follows redirects. Coordinates are never
 * logged.
 *
 * Statuses: `found` (address set), `none` (the service has no address there, e.g.
 * open water), `failed` (could not ask or got no usable answer: retry later) and
 * `throttled` (the rate limit is spent: retry in a moment).
 */
class ReverseGeocodeCoordinates
{
    public const CACHE_DAYS = 30;

    private const RATE_LIMIT_KEY = 'reverse-geocode';

    private const ADDRESS_MAX_LENGTH = 255;

    /**
     * @return array{status: 'found'|'none'|'failed'|'throttled', address: ?string}
     */
    public function __invoke(float $lat, float $lng): array
    {
        $base = rtrim((string) (Integrations::endpoint(Integration::ReverseGeocoding) ?? config('filament-pinpoint.leaflet.nominatim_url')), '/');
        $cacheKey = sprintf('reverse-geocode:%s:%.4f:%.4f', substr(sha1($base), 0, 12), $lat, $lng);

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $pin = app(HeartbeatUrlGuard::class)->requestOptions($base);

        if ($pin === null) {
            Log::warning('Reverse geocoding endpoint refused');

            return $this->result('failed');
        }

        if (! RateLimiter::attempt(self::RATE_LIMIT_KEY, 1, fn (): bool => true, 1)) {
            return $this->result('throttled');
        }

        try {
            $response = Http::withHeaders(['User-Agent' => config('app.name').' (+'.config('app.url').')'])
                ->acceptJson()
                ->timeout(5)
                ->connectTimeout(3)
                ->withOptions(['allow_redirects' => false, ...$pin])
                ->get("{$base}/reverse", [
                    'format' => 'jsonv2',
                    'lat' => round($lat, 7),
                    'lon' => round($lng, 7),
                    'zoom' => 18,
                    'addressdetails' => 0,
                    'accept-language' => 'en',
                ]);
        } catch (Throwable) {
            Log::warning('Reverse geocoding request failed');

            return $this->result('failed');
        }

        if (! $response->successful()) {
            Log::warning('Reverse geocoding endpoint returned an error', ['status' => $response->status()]);

            return $this->result('failed');
        }

        $body = $response->json();
        $name = is_array($body) ? ($body['display_name'] ?? null) : null;

        $result = match (true) {
            is_string($name) && trim($name) !== '' => $this->result('found', mb_substr(trim($name), 0, self::ADDRESS_MAX_LENGTH)),
            is_array($body) && isset($body['error']) => $this->result('none'),
            default => null,
        };

        if ($result === null) {
            Log::warning('Reverse geocoding endpoint returned an unreadable response');

            return $this->result('failed');
        }

        Cache::put($cacheKey, $result, now()->addDays(self::CACHE_DAYS));

        return $result;
    }

    /**
     * @return array{status: 'found'|'none'|'failed'|'throttled', address: ?string}
     */
    private function result(string $status, ?string $address = null): array
    {
        return ['status' => $status, 'address' => $address];
    }
}
