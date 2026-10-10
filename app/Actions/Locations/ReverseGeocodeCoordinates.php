<?php

namespace App\Actions\Locations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

    public const RATE_LIMIT_KEY = NominatimRequest::RATE_LIMIT_KEY;

    private const ADDRESS_MAX_LENGTH = 255;

    /**
     * @return array{status: 'found'|'none'|'failed'|'throttled', address: ?string}
     */
    public function __invoke(float $lat, float $lng): array
    {
        $nominatim = app(NominatimRequest::class);
        $base = $nominatim->endpoint();
        $cacheKey = sprintf('reverse-geocode:%s:%.4f:%.4f', substr(sha1($base), 0, 12), $lat, $lng);

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $answer = $nominatim($base, 'reverse', [
            'format' => 'jsonv2',
            'lat' => round($lat, 7),
            'lon' => round($lng, 7),
            'zoom' => 18,
            'addressdetails' => 0,
            'accept-language' => 'en',
        ], 'Reverse geocoding');

        if ($answer['status'] !== 'ok') {
            return $this->result($answer['status']);
        }

        $body = $answer['body'];
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
