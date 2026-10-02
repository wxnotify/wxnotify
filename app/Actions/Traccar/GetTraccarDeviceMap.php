<?php

namespace App\Actions\Traccar;

use App\Models\TraccarServer;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every device, with a position, on each of the given Traccar servers --
 * the data behind the Traccar Servers page's device map.
 *
 * Each server's /api/devices and /api/positions are fetched in parallel
 * (one slow server costs its own timeout, not the sum) and joined by device
 * id. The normalized per-server result is cached for 60 seconds under a key
 * that includes the server's updated_at, so editing a URL or token takes
 * effect immediately; a failed fetch is never cached. Staleness is computed
 * on every call from the cached last-check-in, so a cached entry never
 * serves a stale flag older than the cache window.
 *
 * Nothing sensitive leaves this class: only the server label, device name,
 * coordinates, last check-in and stale flag. Logs are fixed strings plus the
 * server id and an HTTP status -- never an exception message, URL or token.
 */
class GetTraccarDeviceMap
{
    public const STALE_AFTER_MINUTES = 60;

    private const CACHE_SECONDS = 60;

    private const TIMEOUT_SECONDS = 5;

    /**
     * @param  iterable<TraccarServer>  $servers
     * @return array{devices: array<int, array<string, mixed>>, unplaced: int, unreachable: array<int, string>}
     */
    public function __invoke(iterable $servers): array
    {
        $servers = collect($servers)->values();

        $results = [];
        $missing = [];

        foreach ($servers as $server) {
            $cached = Cache::get($this->cacheKey($server));

            if (is_array($cached)) {
                $results[$server->id] = $cached;
            } else {
                $missing[] = $server;
            }
        }

        foreach ($this->fetch($missing) as $serverId => $result) {
            $results[$serverId] = $result;
        }

        return $this->assemble($servers->all(), $results);
    }

    /**
     * @param  array<int, TraccarServer>  $servers
     * @return array<int, ?array{devices: array<int, array<string, mixed>>, unplaced: int}>
     */
    private function fetch(array $servers): array
    {
        if ($servers === []) {
            return [];
        }

        $responses = Http::pool(function (Pool $pool) use ($servers): array {
            $requests = [];

            foreach ($servers as $server) {
                $base = rtrim($server->base_url, '/');

                foreach (['devices', 'positions'] as $endpoint) {
                    $requests[] = $pool->as("{$endpoint}:{$server->id}")
                        ->withToken((string) $server->api_token)
                        ->acceptJson()
                        ->timeout(self::TIMEOUT_SECONDS)
                        ->withOptions(['allow_redirects' => false])
                        ->get("{$base}/api/{$endpoint}");
                }
            }

            return $requests;
        });

        $results = [];

        foreach ($servers as $server) {
            $result = $this->normalize($server, $responses["devices:{$server->id}"] ?? null, $responses["positions:{$server->id}"] ?? null);

            if ($result !== null) {
                Cache::put($this->cacheKey($server), $result, now()->addSeconds(self::CACHE_SECONDS));
            }

            $results[$server->id] = $result;
        }

        return $results;
    }

    /**
     * A pool entry is a Response, or the exception object when the request
     * itself failed (connection refused, timeout).
     *
     * @return array{devices: array<int, array<string, mixed>>, unplaced: int}|null
     */
    private function normalize(TraccarServer $server, mixed $devices, mixed $positions): ?array
    {
        if (! $devices instanceof Response || ! $positions instanceof Response) {
            Log::warning('Traccar device map: server unreachable', ['traccar_server_id' => $server->id]);

            return null;
        }

        foreach ([$devices, $positions] as $response) {
            if (! $response->successful()) {
                Log::warning('Traccar device map: server returned an error', [
                    'traccar_server_id' => $server->id,
                    'status' => $response->status(),
                ]);

                return null;
            }
        }

        $deviceList = $devices->json();
        $positionList = $positions->json();

        if (! is_array($deviceList) || ! is_array($positionList)) {
            return $this->malformed($server);
        }

        $byDevice = [];

        foreach ($positionList as $position) {
            if (! is_array($position)
                || ! isset($position['deviceId'])
                || ! is_numeric($position['latitude'] ?? null)
                || ! is_numeric($position['longitude'] ?? null)
            ) {
                return $this->malformed($server);
            }

            $byDevice[(int) $position['deviceId']] = $position;
        }

        $entries = [];
        $unplaced = 0;

        foreach ($deviceList as $device) {
            if (! is_array($device) || ! isset($device['id'], $device['name'])) {
                return $this->malformed($server);
            }

            if (($device['disabled'] ?? false) === true) {
                continue;
            }

            $position = $byDevice[(int) $device['id']] ?? null;

            if ($position === null) {
                $unplaced++;

                continue;
            }

            $entries[] = [
                'key' => "{$server->id}:{$device['id']}",
                'name' => (string) $device['name'],
                'lat' => (float) $position['latitude'],
                'lng' => (float) $position['longitude'],
                'last_checkin' => $this->iso($device['lastUpdate'] ?? null) ?? $this->iso($position['serverTime'] ?? null),
            ];
        }

        return ['devices' => $entries, 'unplaced' => $unplaced];
    }

    /**
     * @param  array<int, TraccarServer>  $servers
     * @param  array<int, ?array{devices: array<int, array<string, mixed>>, unplaced: int}>  $results
     * @return array{devices: array<int, array<string, mixed>>, unplaced: int, unreachable: array<int, string>}
     */
    private function assemble(array $servers, array $results): array
    {
        $devices = [];
        $unplaced = 0;
        $unreachable = [];

        foreach ($servers as $server) {
            $result = $results[$server->id] ?? null;

            if ($result === null) {
                $unreachable[] = (string) $server->label;

                continue;
            }

            $unplaced += $result['unplaced'];

            foreach ($result['devices'] as $entry) {
                $devices[] = [
                    'server' => (string) $server->label,
                    'key' => $entry['key'],
                    'name' => $entry['name'],
                    'lat' => $entry['lat'],
                    'lng' => $entry['lng'],
                    'last_checkin' => $entry['last_checkin'],
                    'stale' => $this->isStale($entry['last_checkin']),
                ];
            }
        }

        return ['devices' => $devices, 'unplaced' => $unplaced, 'unreachable' => $unreachable];
    }

    private function isStale(?string $lastCheckin): bool
    {
        return $lastCheckin === null
            || Carbon::parse($lastCheckin)->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    private function iso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return null
     */
    private function malformed(TraccarServer $server)
    {
        Log::warning('Traccar device map: server returned a malformed response', ['traccar_server_id' => $server->id]);

        return null;
    }

    private function cacheKey(TraccarServer $server): string
    {
        return "traccar-device-map:{$server->id}:".($server->updated_at?->getTimestamp() ?? 0);
    }
}
