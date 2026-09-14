<?php

namespace App\Actions\Traccar;

use App\Models\TraccarServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches a Traccar server's devices via its confirmed REST API
 * (github.com/traccar/traccar/blob/master/openapi.yaml's GET /api/devices),
 * authenticated with the server's long-lived Bearer token (the ApiKey
 * security scheme in that spec, available since Traccar 5.3).
 *
 * Returns null (not an empty array) on any failure, so callers can tell
 * "this server is unreachable/misconfigured" apart from "reachable, but
 * genuinely has zero devices" -- the same distinction PollTraccarServer
 * makes for the same reason.
 */
class ListTraccarDevices
{
    /**
     * @return ?array<int, array{id: int, name: string}>
     */
    public function __invoke(TraccarServer $server): ?array
    {
        try {
            $response = Http::withToken($server->api_token)
                ->timeout(10)
                ->get(rtrim($server->base_url, '/').'/api/devices');
        } catch (Throwable $e) {
            Log::warning('Failed to reach Traccar server for devices', [
                'traccar_server_id' => $server->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Traccar server returned an error fetching devices', [
                'traccar_server_id' => $server->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $devices = $response->json();

        if (! is_array($devices)) {
            Log::warning('Traccar server returned a malformed devices response', [
                'traccar_server_id' => $server->id,
            ]);

            return null;
        }

        $result = [];

        foreach ($devices as $device) {
            if (! is_array($device) || ! isset($device['id'], $device['name'])) {
                Log::warning('Traccar server returned a malformed device entry', [
                    'traccar_server_id' => $server->id,
                ]);

                return null;
            }

            $result[] = ['id' => $device['id'], 'name' => $device['name']];
        }

        return $result;
    }
}
