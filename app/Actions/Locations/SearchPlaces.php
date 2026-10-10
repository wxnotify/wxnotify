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
 * Forward geocoding for the app's stationary-location picker, against the same Nominatim as the
 * reverse lookups (the admin-set endpoint or the configured URL) and under the same rules: one
 * request per second app-wide (sharing ReverseGeocodeCoordinates's budget, as OSM's policy counts
 * every request), an identifying User-Agent, the endpoint vetted and pinned by HeartbeatUrlGuard,
 * no redirects, answers cached. Never logs the query text; the cache key hashes it.
 *
 * Statuses: `found`, `none` (nothing matches), `failed` (could not ask or got no usable answer)
 * and `throttled` (the rate limit is spent: retry in a moment).
 */
class SearchPlaces
{
    public const CACHE_DAYS = 7;

    public const MAX_RESULTS = 5;

    private const LABEL_MAX_LENGTH = 255;

    /**
     * @return array{status: 'found'|'none'|'failed'|'throttled', places: list<array{label: string, lat: float, lng: float}>}
     */
    public function __invoke(string $query): array
    {
        $base = rtrim((string) (Integrations::endpoint(Integration::ReverseGeocoding) ?? config('filament-pinpoint.leaflet.nominatim_url')), '/');
        $text = trim((string) preg_replace('/\s+/', ' ', $query));
        $cacheKey = 'forward-geocode:'.substr(sha1($base), 0, 12).':'.sha1(mb_strtolower($text));

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $pin = app(HeartbeatUrlGuard::class)->requestOptions($base);

        if ($pin === null) {
            Log::warning('Forward geocoding endpoint refused');

            return $this->result('failed');
        }

        if (! RateLimiter::attempt(ReverseGeocodeCoordinates::RATE_LIMIT_KEY, 1, fn (): bool => true, 1)) {
            return $this->result('throttled');
        }

        try {
            $response = Http::withHeaders(['User-Agent' => config('app.name').' (+'.config('app.url').')'])
                ->acceptJson()
                ->timeout(5)
                ->connectTimeout(3)
                ->withOptions(['allow_redirects' => false, ...$pin])
                ->get("{$base}/search", [
                    'format' => 'jsonv2',
                    'q' => $text,
                    'limit' => self::MAX_RESULTS,
                    'addressdetails' => 0,
                    'accept-language' => 'en',
                ]);
        } catch (Throwable) {
            Log::warning('Forward geocoding request failed');

            return $this->result('failed');
        }

        if (! $response->successful()) {
            Log::warning('Forward geocoding endpoint returned an error', ['status' => $response->status()]);

            return $this->result('failed');
        }

        $body = $response->json();

        if (! is_array($body) || ! array_is_list($body)) {
            Log::warning('Forward geocoding endpoint returned an unreadable response');

            return $this->result('failed');
        }

        $places = collect($body)
            ->map(fn (mixed $item): ?array => $this->place($item))
            ->filter()
            ->take(self::MAX_RESULTS)
            ->values()
            ->all();

        $result = $this->result($places === [] ? 'none' : 'found', $places);

        Cache::put($cacheKey, $result, now()->addDays(self::CACHE_DAYS));

        return $result;
    }

    /**
     * @return array{label: string, lat: float, lng: float}|null
     */
    private function place(mixed $item): ?array
    {
        if (! is_array($item) || ! is_string($item['display_name'] ?? null) || trim($item['display_name']) === '' || ! is_numeric($item['lat'] ?? null) || ! is_numeric($item['lon'] ?? null)) {
            return null;
        }

        return [
            'label' => mb_substr(trim($item['display_name']), 0, self::LABEL_MAX_LENGTH),
            'lat' => (float) $item['lat'],
            'lng' => (float) $item['lon'],
        ];
    }

    /**
     * @param  list<array{label: string, lat: float, lng: float}>  $places
     * @return array{status: 'found'|'none'|'failed'|'throttled', places: list<array{label: string, lat: float, lng: float}>}
     */
    private function result(string $status, array $places = []): array
    {
        return ['status' => $status, 'places' => $places];
    }
}
