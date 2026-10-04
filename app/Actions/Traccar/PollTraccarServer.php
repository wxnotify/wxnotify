<?php

namespace App\Actions\Traccar;

use App\Models\TraccarServer;
use App\Support\TraccarFailure;
use App\Support\TraccarPosition;
use App\Support\TraccarUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches every device's latest position from a Traccar server in one call
 * (GET /api/positions with no params, per the confirmed OpenAPI spec --
 * "without any params, it returns a list of last known positions for all
 * the user's Devices"). Returns null (not an empty array) on failure, so
 * the polling command can distinguish "nothing changed" from "this server
 * is broken right now" and leave existing positions untouched rather than
 * blanking them.
 */
class PollTraccarServer
{
    /**
     * @return ?array<int, array{lat: float, lng: float, fixTime: ?string}> keyed by deviceId
     */
    public function __invoke(TraccarServer $server): ?array
    {
        return $this->poll($server)['positions'];
    }

    /**
     * Like __invoke(), but also says why a poll failed, in a fixed message that is
     * safe to store and show (see TraccarFailure).
     *
     * @return array{positions: ?array<int, array{lat: float, lng: float, fixTime: ?string}>, error: ?string}
     */
    public function poll(TraccarServer $server): array
    {
        $guard = app(TraccarUrlGuard::class);
        $pinned = $guard->requestOptions((string) $server->base_url);

        if ($pinned === null) {
            Log::warning('Traccar server URL refused', ['traccar_server_id' => $server->id]);

            return $this->failed($guard->violation((string) $server->base_url) ?? 'That host name could not be resolved.');
        }

        try {
            $response = Http::withToken($server->api_token)
                ->timeout(10)
                ->withoutRedirecting()
                ->withOptions($pinned)
                ->get(rtrim($server->base_url, '/').'/api/positions');
        } catch (Throwable $e) {
            Log::warning('Failed to reach Traccar server for positions', [
                'traccar_server_id' => $server->id,
                'exception' => $e->getMessage(),
            ]);

            return $this->failed(TraccarFailure::forException($e));
        }

        if (! $response->successful()) {
            Log::warning('Traccar server returned an error fetching positions', [
                'traccar_server_id' => $server->id,
                'status' => $response->status(),
            ]);

            return $this->failed(TraccarFailure::forStatus($response->status()));
        }

        // A malformed whole-response body or any single malformed position
        // entry voids the WHOLE batch back to null, rather than silently
        // building a partial result from untrustworthy data -- mirrors
        // ListTraccarDevices's identical hardening, added after that
        // action's own first review found the naive collect()->map(fn
        // (array $x) => ...) shape throws an uncaught TypeError on a real
        // (or imperfectly self-hosted) server's malformed 2xx body.
        $positions = $response->json();

        if (! is_array($positions)) {
            Log::warning('Traccar server returned a malformed positions response', [
                'traccar_server_id' => $server->id,
            ]);

            return $this->failed(TraccarFailure::MALFORMED);
        }

        $result = [];

        foreach ($positions as $position) {
            if (! is_array($position)
                || ! isset($position['deviceId'], $position['latitude'], $position['longitude'])
                || ! TraccarPosition::isCoordinate($position['latitude'], 90)
                || ! TraccarPosition::isCoordinate($position['longitude'], 180)
            ) {
                Log::warning('Traccar server returned a malformed position entry', [
                    'traccar_server_id' => $server->id,
                ]);

                return $this->failed(TraccarFailure::MALFORMED);
            }

            // Well-formed but no GPS fix (flagged invalid, or 0,0): leave this device
            // out so the caller keeps its last good position instead of overwriting it.
            if (! TraccarPosition::hasFix($position)) {
                continue;
            }

            $result[$position['deviceId']] = [
                'lat' => (float) $position['latitude'],
                'lng' => (float) $position['longitude'],
                'fixTime' => $position['fixTime'] ?? null,
            ];
        }

        return ['positions' => $result, 'error' => null];
    }

    /**
     * @return array{positions: null, error: string}
     */
    private function failed(string $error): array
    {
        return ['positions' => null, 'error' => $error];
    }
}
