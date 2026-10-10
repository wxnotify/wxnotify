<?php

namespace App\Actions\Locations;

use App\Enums\Integration;
use App\Support\Delivery\HeartbeatUrlGuard;
use App\Support\Integrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The one place that talks to Nominatim for forward and reverse geocoding, so OSM's usage policy
 * and the endpoint safeguards are kept in a single spot: the admin-set endpoint (Integrations
 * tab) or the configured Nominatim URL, vetted and pinned by HeartbeatUrlGuard (checked before
 * the rate limit is spent), one request per second app-wide across every lookup kind, an
 * identifying User-Agent, short timeouts and no redirects. Log messages are static: the query,
 * the coordinates and exception messages are never logged.
 *
 * Callers resolve the base URL with endpoint(), check their own cache, then call the instance
 * with the path and query. The status is `ok` (body is the decoded JSON), `failed` (refused,
 * could not ask or got an error status) or `throttled` (the rate limit is spent).
 */
class NominatimRequest
{
    public const RATE_LIMIT_KEY = 'reverse-geocode';

    public function endpoint(): string
    {
        return rtrim((string) (Integrations::endpoint(Integration::ReverseGeocoding) ?? config('filament-pinpoint.leaflet.nominatim_url')), '/');
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  string  $label  Names the lookup in log messages, e.g. "Reverse geocoding".
     * @return array{status: 'ok'|'failed'|'throttled', body: mixed}
     */
    public function __invoke(string $base, string $path, array $query, string $label): array
    {
        $pin = app(HeartbeatUrlGuard::class)->requestOptions($base);

        if ($pin === null) {
            Log::warning("{$label} endpoint refused");

            return ['status' => 'failed', 'body' => null];
        }

        if (! RateLimiter::attempt(self::RATE_LIMIT_KEY, 1, fn (): bool => true, 1)) {
            return ['status' => 'throttled', 'body' => null];
        }

        try {
            $response = Http::withHeaders(['User-Agent' => config('app.name').' (+'.config('app.url').')'])
                ->acceptJson()
                ->timeout(5)
                ->connectTimeout(3)
                ->withOptions(['allow_redirects' => false, ...$pin])
                ->get("{$base}/{$path}", $query);
        } catch (Throwable) {
            Log::warning("{$label} request failed");

            return ['status' => 'failed', 'body' => null];
        }

        if (! $response->successful()) {
            Log::warning("{$label} endpoint returned an error", ['status' => $response->status()]);

            return ['status' => 'failed', 'body' => null];
        }

        return ['status' => 'ok', 'body' => $response->json()];
    }
}
