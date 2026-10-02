# Traccar Servers Page Device Map Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Leaflet map above the Traccar Servers table plots every device (with a position) on all of the subscriber's Traccar servers — red when the last check-in is recent, gray when stale — with a pop-up showing server, device and last check-in; the map shows even with no servers.

**Architecture:** A `GetTraccarDeviceMap` action fetches each server's `/api/devices` and `/api/positions` in parallel, joins them, caches each server's result for 60 s, and computes staleness per call. A Filament header widget (`TraccarDevicesMapWidget`) exposes it through a Livewire method that Alpine calls after the map has rendered; Leaflet is lazy-loaded from the same CDN the admin shapefile map already uses (no new dependency).

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5 widgets (Livewire + Alpine), Leaflet 1.9.4 (CDN), Pest.

**Spec:** `docs/superpowers/specs/2026-10-02-traccar-device-map-design.md` (binding authority; this plan argues from it).

## Global Constraints

- **Tokens never reach the browser**: the payload carries only server label, device name, coordinates, `last_checkin`, `stale`, and a `key`. No `api_token`, base URL, or raw Traccar body.
- **All external strings render with `textContent`** (pop-up and caption) — never `innerHTML` — because server/device names are external data. The only `innerHTML`-style markup is the pin SVG built from constants in our own code.
- **Every query is scoped to `auth()->user()`'s own servers.**
- **Stale** = last check-in older than **1 hour** (`GetTraccarDeviceMap::STALE_AFTER_MINUTES = 60`), or unknown. Exactly 60 minutes is **not** stale.
- **Last check-in** = the device's `lastUpdate`, falling back to the position's `serverTime`; ISO-8601 strings (plain data, no Carbon in the payload).
- **Per-server isolation**: one unreachable/malformed server never breaks the others; it is named in `unreachable`.
- **Fixed-string logs only** (plus `traccar_server_id` and an HTTP status) — never an exception message, URL, or token.
- **Timeouts/redirects**: 5 s per request, `allow_redirects => false`, servers fetched in parallel (`Http::pool`).
- **Cache**: per server, 60 s, key includes the server id **and** its `updated_at` timestamp; a failed fetch is never cached.
- **No new npm/composer dependency**; the admin shapefile map page is left untouched.
- **Out of scope (flagged follow-up, do not implement):** a private-address/SSRF guard (`TraccarUrlGuard`) for Traccar URLs — the existing poller/lister have none either.
- Project conventions: Pest feature tests; `vendor/bin/pint --dirty --format agent` before each commit; commit directly to `main` (no push); commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Traccar pages are paid-tier only (`TraccarServerResource::canAccess()` is false for Free): tests that render the page or widget use `User::factory()->create(['tier_override' => Tier::Plus])`.

## How to run tests (read this once)

The host cannot resolve the `pgsql` hostname and runs out of memory under `php artisan test`. Run Pest inside the app container:

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

Every "Run:" line below means that command with the stated test file(s). The full suite takes ~5–7 minutes: run it with `run_in_background`. Pint runs on the host: `vendor/bin/pint --dirty --format agent`.

## File Structure

| Path | Responsibility |
| --- | --- |
| `app/Actions/Traccar/GetTraccarDeviceMap.php` | Parallel fetch, join, cache, staleness |
| `app/Filament/Dashboard/Resources/TraccarServers/Widgets/TraccarDevicesMapWidget.php` | Widget class; `devices()` Livewire method |
| `resources/views/filament/dashboard/widgets/traccar-devices-map.blade.php` | Map container, caption, and the Alpine/Leaflet script |
| `resources/views/filament/partials/leaflet-loader.blade.php` | Shared `window.whenLeafletReady(cb)` lazy CDN loader |
| `app/Filament/Dashboard/Resources/TraccarServers/TraccarServerResource.php` | `getWidgets()` (registers the widget as a Livewire component) |
| `app/Filament/Dashboard/Resources/TraccarServers/Pages/ListTraccarServers.php` | `getHeaderWidgets()` |
| `tests/Feature/GetTraccarDeviceMapTest.php`, `tests/Feature/TraccarDevicesMapWidgetTest.php` | Tests |

Spec delta: each device entry also carries a `key` (`"{serverId}:{deviceId}"`) so the browser can update markers in place across refreshes.

---

### Task 1: `GetTraccarDeviceMap`

**Files:**
- Create: `app/Actions/Traccar/GetTraccarDeviceMap.php`
- Test: `tests/Feature/GetTraccarDeviceMapTest.php`

**Interfaces:**
- Produces (consumed by Task 2): `GetTraccarDeviceMap::__invoke(iterable $servers): array` returning
  ```php
  [
      'devices' => [
          ['server' => string, 'key' => string, 'name' => string, 'lat' => float, 'lng' => float, 'last_checkin' => ?string, 'stale' => bool],
      ],
      'unplaced' => int,          // devices on reachable servers with no position
      'unreachable' => string[],  // labels of servers that failed or returned malformed data
  ]
  ```
  and `public const STALE_AFTER_MINUTES = 60`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/GetTraccarDeviceMapTest.php`:

```php
<?php

use App\Actions\Traccar\GetTraccarDeviceMap;
use App\Models\TraccarServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function mapServer(string $label, string $host, string $token = 'tok'): TraccarServer
{
    return TraccarServer::factory()->create(['label' => $label, 'base_url' => "https://{$host}", 'api_token' => $token]);
}

/**
 * @param  array<int, array<string, mixed>>  $devices
 * @param  array<int, array<string, mixed>>  $positions
 * @return array<string, mixed>
 */
function fakeTraccar(string $host, array $devices, array $positions): array
{
    return [
        "{$host}/api/devices" => Http::response($devices),
        "{$host}/api/positions" => Http::response($positions),
    ];
}

function position(int $deviceId, float $lat = 39.74, float $lng = -104.99, ?string $serverTime = '2026-10-02T11:59:00Z'): array
{
    return ['id' => $deviceId * 10, 'deviceId' => $deviceId, 'latitude' => $lat, 'longitude' => $lng, 'fixTime' => '2026-10-02T11:58:00Z', 'serverTime' => $serverTime];
}

test('devices on several servers are joined to their positions, labelled by server', function () {
    $fleet = mapServer('Fleet', 'fleet.example.com');
    $boat = mapServer('Boat', 'boat.example.com');
    Http::fake([
        ...fakeTraccar('fleet.example.com', [
            ['id' => 1, 'name' => 'Truck 1', 'lastUpdate' => '2026-10-02T11:55:00Z'],
            ['id' => 2, 'name' => 'Truck 2', 'lastUpdate' => '2026-10-02T11:50:00Z'],
        ], [position(1, 40.0, -105.0), position(2, 41.0, -106.0)]),
        ...fakeTraccar('boat.example.com', [['id' => 7, 'name' => 'Dinghy', 'lastUpdate' => '2026-10-02T11:59:00Z']], [position(7, 25.7, -80.1)]),
    ]);

    $result = (new GetTraccarDeviceMap)([$fleet, $boat]);

    expect($result['devices'])->toBe([
        ['server' => 'Fleet', 'key' => "{$fleet->id}:1", 'name' => 'Truck 1', 'lat' => 40.0, 'lng' => -105.0, 'last_checkin' => '2026-10-02T11:55:00+00:00', 'stale' => false],
        ['server' => 'Fleet', 'key' => "{$fleet->id}:2", 'name' => 'Truck 2', 'lat' => 41.0, 'lng' => -106.0, 'last_checkin' => '2026-10-02T11:50:00+00:00', 'stale' => false],
        ['server' => 'Boat', 'key' => "{$boat->id}:7", 'name' => 'Dinghy', 'lat' => 25.7, 'lng' => -80.1, 'last_checkin' => '2026-10-02T11:59:00+00:00', 'stale' => false],
    ])->and($result['unplaced'])->toBe(0)
        ->and($result['unreachable'])->toBe([]);
});

test('a device is stale after exactly more than one hour without a check-in', function () {
    $server = mapServer('Fleet', 'fleet.example.com');
    Http::fake(fakeTraccar('fleet.example.com', [
        ['id' => 1, 'name' => 'Exactly an hour', 'lastUpdate' => '2026-10-02T11:00:00Z'],
        ['id' => 2, 'name' => 'A second over', 'lastUpdate' => '2026-10-02T10:59:59Z'],
        ['id' => 3, 'name' => 'Recent', 'lastUpdate' => '2026-10-02T11:59:59Z'],
    ], [position(1), position(2), position(3)]));

    $stale = collect((new GetTraccarDeviceMap)([$server])['devices'])->pluck('stale', 'name')->all();

    expect($stale)->toBe(['Exactly an hour' => false, 'A second over' => true, 'Recent' => false]);
});

test('a missing lastUpdate falls back to the position serverTime, and no time at all is stale and unknown', function () {
    $server = mapServer('Fleet', 'fleet.example.com');
    Http::fake(fakeTraccar('fleet.example.com', [
        ['id' => 1, 'name' => 'Fallback', 'lastUpdate' => null],
        ['id' => 2, 'name' => 'Nothing', 'lastUpdate' => null],
    ], [position(1, serverTime: '2026-10-02T11:58:00Z'), position(2, serverTime: null)]));

    $devices = collect((new GetTraccarDeviceMap)([$server])['devices'])->keyBy('name');

    expect($devices['Fallback']['last_checkin'])->toBe('2026-10-02T11:58:00+00:00')
        ->and($devices['Fallback']['stale'])->toBeFalse()
        ->and($devices['Nothing']['last_checkin'])->toBeNull()
        ->and($devices['Nothing']['stale'])->toBeTrue();
});

test('a device with no position is counted, and a disabled device is ignored', function () {
    $server = mapServer('Fleet', 'fleet.example.com');
    Http::fake(fakeTraccar('fleet.example.com', [
        ['id' => 1, 'name' => 'Placed', 'lastUpdate' => '2026-10-02T11:59:00Z'],
        ['id' => 2, 'name' => 'Never reported', 'lastUpdate' => null],
        ['id' => 3, 'name' => 'Disabled', 'disabled' => true, 'lastUpdate' => '2026-10-02T11:59:00Z'],
    ], [position(1), position(3)]));

    $result = (new GetTraccarDeviceMap)([$server]);

    expect(collect($result['devices'])->pluck('name')->all())->toBe(['Placed'])
        ->and($result['unplaced'])->toBe(1);
});

test('one server failing never affects the others, and is named as unreachable', function (string $scenario) {
    $good = mapServer('Good', 'good.example.com');
    $bad = mapServer('Bad', 'bad.example.com');

    $badFakes = match ($scenario) {
        'a 500' => ['bad.example.com/api/devices' => Http::response([], 500), 'bad.example.com/api/positions' => Http::response([])],
        'a 401' => ['bad.example.com/api/devices' => Http::response(['error' => 'no'], 401), 'bad.example.com/api/positions' => Http::response([], 401)],
        'a redirect' => ['bad.example.com/api/devices' => Http::response('', 302), 'bad.example.com/api/positions' => Http::response([])],
        'a non-json body' => ['bad.example.com/api/devices' => Http::response('<html>hi</html>'), 'bad.example.com/api/positions' => Http::response([])],
        'non-numeric coordinates' => fakeTraccar('bad.example.com', [['id' => 1, 'name' => 'X']], [['deviceId' => 1, 'latitude' => 'north', 'longitude' => 'west']]),
        'a malformed device' => fakeTraccar('bad.example.com', ['not-a-device'], []),
        'a connection failure' => ['bad.example.com/*' => fn () => throw new ConnectionException('Connection refused')],
    };

    Http::fake([...fakeTraccar('good.example.com', [['id' => 1, 'name' => 'Fine', 'lastUpdate' => '2026-10-02T11:59:00Z']], [position(1)]), ...$badFakes]);

    $result = (new GetTraccarDeviceMap)([$good, $bad]);

    expect(collect($result['devices'])->pluck('name')->all())->toBe(['Fine'])
        ->and($result['unreachable'])->toBe(['Bad']);
})->with(['a 500', 'a 401', 'a redirect', 'a non-json body', 'non-numeric coordinates', 'a malformed device', 'a connection failure']);

test('requests carry the bearer token, and the token never appears in the result', function () {
    $server = mapServer('Fleet', 'fleet.example.com', 'super-secret-token');
    Http::fake(fakeTraccar('fleet.example.com', [['id' => 1, 'name' => 'Truck', 'lastUpdate' => '2026-10-02T11:59:00Z']], [position(1)]));

    $result = (new GetTraccarDeviceMap)([$server]);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer super-secret-token'));
    expect(json_encode($result))->not->toContain('super-secret-token')->not->toContain('fleet.example.com');
});

test('with no servers nothing is requested and the result is empty', function () {
    Http::fake();

    expect((new GetTraccarDeviceMap)([]))->toBe(['devices' => [], 'unplaced' => 0, 'unreachable' => []]);
    Http::assertNothingSent();
});

test('each server is cached for 60 seconds, and editing it busts only its own cache', function () {
    $fleet = mapServer('Fleet', 'fleet.example.com');
    $boat = mapServer('Boat', 'boat.example.com');
    Http::fake([
        ...fakeTraccar('fleet.example.com', [['id' => 1, 'name' => 'Truck', 'lastUpdate' => '2026-10-02T11:59:00Z']], [position(1)]),
        ...fakeTraccar('boat.example.com', [['id' => 7, 'name' => 'Dinghy', 'lastUpdate' => '2026-10-02T11:59:00Z']], [position(7)]),
    ]);

    (new GetTraccarDeviceMap)([$fleet, $boat]);
    (new GetTraccarDeviceMap)([$fleet, $boat]);
    Http::assertSentCount(4); // two servers x two endpoints, once

    Carbon::setTestNow('2026-10-02 12:00:10');
    $fleet->update(['label' => 'Fleet renamed']);
    (new GetTraccarDeviceMap)([$fleet->fresh(), $boat]);

    Http::assertSentCount(6); // only the edited server was fetched again

    Carbon::setTestNow('2026-10-02 12:01:30'); // past the 60 s TTL
    (new GetTraccarDeviceMap)([$fleet->fresh(), $boat]);

    Http::assertSentCount(10);
});

test('a failed fetch is not cached, so the next call retries', function () {
    $server = mapServer('Fleet', 'fleet.example.com');
    Http::fake([
        'fleet.example.com/api/devices' => Http::sequence()
            ->push([], 500)
            ->push([['id' => 1, 'name' => 'Truck', 'lastUpdate' => '2026-10-02T11:59:00Z']]),
        'fleet.example.com/api/positions' => Http::response([position(1)]),
    ]);

    $first = (new GetTraccarDeviceMap)([$server]);
    $second = (new GetTraccarDeviceMap)([$server]);

    expect($first['unreachable'])->toBe(['Fleet'])
        ->and(collect($second['devices'])->pluck('name')->all())->toBe(['Truck'])
        ->and($second['unreachable'])->toBe([]);
});

test('staleness is recomputed on every call, even from cache', function () {
    $server = mapServer('Fleet', 'fleet.example.com');
    Http::fake(fakeTraccar('fleet.example.com', [['id' => 1, 'name' => 'Truck', 'lastUpdate' => '2026-10-02T11:00:30Z']], [position(1)]));

    // 12:00:00 -> the check-in is 59 m 30 s old: fresh.
    $fresh = (new GetTraccarDeviceMap)([$server])['devices'][0]['stale'];

    // 12:00:40 is still inside the 60 s cache window (served from cache, no new request),
    // but the check-in is now 60 m 10 s old: stale.
    Carbon::setTestNow('2026-10-02 12:00:40');
    $stale = (new GetTraccarDeviceMap)([$server])['devices'][0]['stale'];

    expect($fresh)->toBeFalse()->and($stale)->toBeTrue();
    Http::assertSentCount(2); // one devices + one positions request, total
});

test('failures log only fixed strings with the server id and status', function () {
    Log::spy();
    $failing = mapServer('Bad', 'bad.example.com', 'tok-secret');
    $unreachable = mapServer('Down', 'down.example.com');
    Http::fake([
        'bad.example.com/api/devices' => Http::response([], 500),
        'bad.example.com/api/positions' => Http::response([]),
        'down.example.com/*' => fn () => throw new ConnectionException('secret-host refused'),
    ]);

    (new GetTraccarDeviceMap)([$failing, $unreachable]);

    Log::shouldHaveReceived('warning')->with('Traccar device map: server returned an error', ['traccar_server_id' => $failing->id, 'status' => 500])->once();
    Log::shouldHaveReceived('warning')->with('Traccar device map: server unreachable', ['traccar_server_id' => $unreachable->id])->once();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/GetTraccarDeviceMapTest.php`
Expected: FAIL — class `App\Actions\Traccar\GetTraccarDeviceMap` not found.

- [ ] **Step 3: Implement `app/Actions/Traccar/GetTraccarDeviceMap.php`**

```php
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
```

- [ ] **Step 4: Run to verify it passes**

Run: `tests/Feature/GetTraccarDeviceMapTest.php`
Expected: all PASS. (If `Http::pool` + `Http::fake` returns a differently-shaped entry for a thrown connection exception in this Laravel version, adapt only how `normalize()` recognises it — the `instanceof Response` check is the contract: anything that is not a Response is "unreachable".)

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Traccar/GetTraccarDeviceMap.php tests/Feature/GetTraccarDeviceMapTest.php
git commit -m "Add GetTraccarDeviceMap, the live device map data for the Traccar Servers page

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The map widget on the Traccar Servers page

**Files:**
- Create: `app/Filament/Dashboard/Resources/TraccarServers/Widgets/TraccarDevicesMapWidget.php`, `resources/views/filament/dashboard/widgets/traccar-devices-map.blade.php`, `resources/views/filament/partials/leaflet-loader.blade.php`
- Modify: `app/Filament/Dashboard/Resources/TraccarServers/TraccarServerResource.php`, `app/Filament/Dashboard/Resources/TraccarServers/Pages/ListTraccarServers.php`
- Test: `tests/Feature/TraccarDevicesMapWidgetTest.php`

**Interfaces:**
- Consumes: `GetTraccarDeviceMap::__invoke(iterable): array` (Task 1).
- Produces: `TraccarDevicesMapWidget::devices(): array` returning the Task 1 structure plus `'has_servers' => bool`.

Read `.ai/rules/filament.md` before editing anything under `app/Filament`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TraccarDevicesMapWidgetTest.php`:

```php
<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\ListTraccarServers;
use App\Filament\Dashboard\Resources\TraccarServers\Widgets\TraccarDevicesMapWidget;
use App\Models\TraccarServer;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($this->user);
});

function widgetTraccarFake(string $host, string $deviceName): array
{
    return [
        "{$host}/api/devices" => Http::response([['id' => 1, 'name' => $deviceName, 'lastUpdate' => now()->toIso8601String()]]),
        "{$host}/api/positions" => Http::response([['deviceId' => 1, 'latitude' => 40.0, 'longitude' => -105.0, 'serverTime' => now()->toIso8601String()]]),
    ];
}

test('the widget returns only the signed-in subscriber\'s devices', function () {
    TraccarServer::factory()->for($this->user)->create(['label' => 'Mine', 'base_url' => 'https://mine.example.com']);
    TraccarServer::factory()->create(['label' => 'Theirs', 'base_url' => 'https://theirs.example.com']);
    Http::fake([...widgetTraccarFake('mine.example.com', 'My truck'), ...widgetTraccarFake('theirs.example.com', 'Their truck')]);

    $data = livewire(TraccarDevicesMapWidget::class)->instance()->devices();

    expect(collect($data['devices'])->pluck('name')->all())->toBe(['My truck'])
        ->and($data['has_servers'])->toBeTrue()
        ->and($data['unreachable'])->toBe([]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'theirs.example.com'));
});

test('with no servers the widget reports it and makes no requests', function () {
    Http::fake();

    $data = livewire(TraccarDevicesMapWidget::class)->instance()->devices();

    expect($data)->toBe(['devices' => [], 'unplaced' => 0, 'unreachable' => [], 'has_servers' => false]);
    Http::assertNothingSent();
});

test('the widget payload never contains a token or a server url', function () {
    TraccarServer::factory()->for($this->user)->create(['label' => 'Mine', 'base_url' => 'https://mine.example.com', 'api_token' => 'secret-token-123']);
    Http::fake(widgetTraccarFake('mine.example.com', 'Truck'));

    $json = json_encode(livewire(TraccarDevicesMapWidget::class)->instance()->devices());

    expect($json)->not->toContain('secret-token-123')->not->toContain('mine.example.com');
});

test('the widget renders a map container that Livewire leaves alone, and loads nothing from Traccar while rendering', function () {
    TraccarServer::factory()->for($this->user)->create(['base_url' => 'https://mine.example.com']);
    Http::fake();

    livewire(TraccarDevicesMapWidget::class)
        ->assertSeeHtml('data-traccar-map')
        ->assertSeeHtml('data-traccar-caption')
        ->assertSeeHtml('wire:ignore');

    Http::assertNothingSent();
});

test('the Traccar Servers list page shows the map, with servers and with none', function () {
    livewire(ListTraccarServers::class)->assertSeeLivewire(TraccarDevicesMapWidget::class);

    TraccarServer::factory()->for($this->user)->create();

    livewire(ListTraccarServers::class)->assertSeeLivewire(TraccarDevicesMapWidget::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/TraccarDevicesMapWidgetTest.php`
Expected: FAIL — widget class not found.

- [ ] **Step 3: The widget class**

`app/Filament/Dashboard/Resources/TraccarServers/Widgets/TraccarDevicesMapWidget.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Widgets;

use App\Actions\Traccar\GetTraccarDeviceMap;
use Filament\Widgets\Widget;

/**
 * The device map above the Traccar Servers table. The page renders the map
 * container immediately; the browser then calls devices() (which fetches live
 * from the subscriber's servers, cached 60 s per server) so one slow or dead
 * Traccar server can never delay the page itself. Only the signed-in
 * subscriber's own servers are ever queried.
 */
class TraccarDevicesMapWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.traccar-devices-map';

    protected int | string | array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /**
     * @return array{devices: array<int, array<string, mixed>>, unplaced: int, unreachable: array<int, string>, has_servers: bool}
     */
    public function devices(): array
    {
        $servers = auth()->user()->traccarServers()->get();

        return [
            ...app(GetTraccarDeviceMap::class)($servers),
            'has_servers' => $servers->isNotEmpty(),
        ];
    }
}
```

- [ ] **Step 4: Register and place the widget**

`TraccarServerResource.php`: add `use App\Filament\Dashboard\Resources\TraccarServers\Widgets\TraccarDevicesMapWidget;` and this method (Filament registers a resource's widgets as Livewire components from `getWidgets()`):

```php
    /**
     * @return array<class-string>
     */
    public static function getWidgets(): array
    {
        return [
            TraccarDevicesMapWidget::class,
        ];
    }
```

`ListTraccarServers.php`: add the same `use` and:

```php
    protected function getHeaderWidgets(): array
    {
        return [
            TraccarDevicesMapWidget::class,
        ];
    }
```

- [ ] **Step 5: The shared Leaflet loader partial**

`resources/views/filament/partials/leaflet-loader.blade.php` (modelled on the admin shapefile map's loader; it reuses the same CDN, version and `pinpoint:leaflet:loaded` event so the two never load Leaflet twice):

```blade
<script>
    // Defined once per page, as a plain global function: Alpine/Livewire evaluate
    // x-init expressions in a wrapped scope that does not reliably resolve globals
    // such as `L` (see resources/views/filament/admin/pages/shapefile-layer-map.blade.php).
    if (! window.whenLeafletReady) {
        window.whenLeafletReady = function (callback) {
            if (window.L) { callback(); return; }

            document.addEventListener('pinpoint:leaflet:loaded', callback, { once: true });

            if (window.leafletLoading) { return; }

            window.leafletLoading = true;

            if (! document.getElementById('leaflet-css')) {
                const link = document.createElement('link');
                link.id = 'leaflet-css';
                link.rel = 'stylesheet';
                link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(link);
            }

            const script = document.createElement('script');
            script.id = 'leaflet-js';
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = function () {
                window.leafletLoading = false;
                document.dispatchEvent(new CustomEvent('pinpoint:leaflet:loaded'));
            };
            document.head.appendChild(script);
        };
    }
</script>
```

- [ ] **Step 6: The widget view**

`resources/views/filament/dashboard/widgets/traccar-devices-map.blade.php`:

```blade
<x-filament-widgets::widget>
    <x-filament::section heading="Device map" compact>
        @include('filament.partials.leaflet-loader')

        {{-- wire:ignore on the root: Leaflet owns the map div and JS owns the caption. --}}
        <div
            wire:ignore
            x-data="{ state: {}, destroy() { window.destroyTraccarDevicesMap(this.state); } }"
            x-init="window.initTraccarDevicesMap($el, $wire, state)"
        >
            <div data-traccar-map style="height: 380px; border-radius: 0.5rem; overflow: hidden; position: relative; z-index: 0;"></div>
            <p data-traccar-caption class="mt-2 text-sm text-gray-500 dark:text-gray-400"></p>
        </div>

        <script>
            window.destroyTraccarDevicesMap = function (state) {
                state.destroyed = true;

                if (state.timer) { clearInterval(state.timer); state.timer = null; }
                if (state.map) { state.map.remove(); state.map = null; }
            };

            window.initTraccarDevicesMap = function (root, $wire, state) {
                const mapEl = root.querySelector('[data-traccar-map]');
                const captionEl = root.querySelector('[data-traccar-caption]');
                const DEFAULT_CENTER = [39.8283, -98.5795];
                const DEFAULT_ZOOM = 4;
                const REFRESH_MS = 60000;
                const COLORS = { fresh: '#dc2626', stale: '#9ca3af' };

                state.markers = new Map();
                state.fitted = false;
                state.destroyed = false;

                // The only markup built as HTML: a pin whose colour comes from the constants above.
                const pinIcon = (color) => L.divIcon({
                    className: '',
                    html: '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">'
                        + '<path d="M13 0C5.8 0 0 5.8 0 13c0 9.5 13 23 13 23s13-13.5 13-23C26 5.8 20.2 0 13 0z" fill="' + color + '" stroke="#ffffff" stroke-width="2"/>'
                        + '<circle cx="13" cy="13" r="5" fill="#ffffff"/></svg>',
                    iconSize: [26, 36],
                    iconAnchor: [13, 36],
                    popupAnchor: [0, -32],
                });

                const relativeTime = (date) => {
                    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
                    const abs = Math.abs(seconds);
                    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

                    if (abs < 60) { return rtf.format(seconds, 'second'); }
                    if (abs < 3600) { return rtf.format(Math.round(seconds / 60), 'minute'); }
                    if (abs < 86400) { return rtf.format(Math.round(seconds / 3600), 'hour'); }

                    return rtf.format(Math.round(seconds / 86400), 'day');
                };

                const formatCheckin = (iso) => {
                    if (! iso) { return 'unknown'; }

                    const date = new Date(iso);

                    if (isNaN(date.getTime())) { return 'unknown'; }

                    const absolute = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);

                    return absolute + ' · ' + relativeTime(date);
                };

                // DOM nodes and textContent only: server and device names are external strings.
                const popupContent = (device) => {
                    const wrap = document.createElement('div');

                    const line = (label, value) => {
                        const row = document.createElement('div');
                        const strong = document.createElement('strong');
                        strong.textContent = label + ' ';
                        row.appendChild(strong);
                        row.appendChild(document.createTextNode(value));
                        wrap.appendChild(row);
                    };

                    line('Server:', device.server);
                    line('Device:', device.name);
                    line('Last check-in:', formatCheckin(device.last_checkin));

                    if (device.stale) {
                        const note = document.createElement('div');
                        note.textContent = 'No check-in for over an hour';
                        note.style.color = '#6b7280';
                        wrap.appendChild(note);
                    }

                    return wrap;
                };

                const caption = (data) => {
                    const parts = [];

                    if (! data.has_servers) {
                        parts.push('Add a Traccar server to see its devices here.');
                    } else if (data.devices.length === 0) {
                        parts.push('No devices with a position yet.');
                    }

                    if (data.unplaced > 0) {
                        parts.push(data.unplaced + (data.unplaced === 1 ? ' device has' : ' devices have') + ' no position yet.');
                    }

                    if (data.unreachable.length > 0) {
                        parts.push("Couldn't reach: " + data.unreachable.join(', ') + '.');
                    }

                    captionEl.textContent = parts.join(' ');
                };

                const render = (data) => {
                    const seen = new Set();
                    const points = [];

                    data.devices.forEach((device) => {
                        seen.add(device.key);
                        points.push([device.lat, device.lng]);

                        const icon = pinIcon(device.stale ? COLORS.stale : COLORS.fresh);
                        const existing = state.markers.get(device.key);

                        if (existing) {
                            existing.setLatLng([device.lat, device.lng]);
                            existing.setIcon(icon);
                            existing.setPopupContent(popupContent(device));
                        } else {
                            const marker = L.marker([device.lat, device.lng], { icon: icon })
                                .bindPopup(popupContent(device))
                                .addTo(state.map);
                            state.markers.set(device.key, marker);
                        }
                    });

                    state.markers.forEach((marker, key) => {
                        if (! seen.has(key)) {
                            marker.remove();
                            state.markers.delete(key);
                        }
                    });

                    if (points.length > 0 && ! state.fitted) {
                        state.map.fitBounds(L.latLngBounds(points), { padding: [40, 40], maxZoom: 12 });
                        state.fitted = true;
                    }

                    caption(data);
                };

                const refresh = async () => {
                    let data;

                    try {
                        data = await $wire.devices();
                    } catch (error) {
                        if (! state.destroyed) { captionEl.textContent = "Couldn't refresh the map."; }

                        return;
                    }

                    if (state.destroyed || ! state.map) { return; }

                    render(data);
                };

                window.whenLeafletReady(() => {
                    if (state.destroyed) { return; }

                    state.map = L.map(mapEl).setView(DEFAULT_CENTER, DEFAULT_ZOOM);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                    }).addTo(state.map);

                    refresh();
                    state.timer = setInterval(refresh, REFRESH_MS);
                });
            };
        </script>
    </x-filament::section>
</x-filament-widgets::widget>
```

- [ ] **Step 7: Run to verify the tests pass**

Run: `tests/Feature/TraccarDevicesMapWidgetTest.php tests/Feature/GetTraccarDeviceMapTest.php tests/Feature/TraccarServerResourceTest.php`
Expected: all PASS, including the existing `TraccarServerResourceTest` (the list page's existing tests must still pass). If `assertSeeLivewire` fails with "Unable to find component", the widget is not being registered: confirm `TraccarServerResource::getWidgets()` returns it and the resource is auto-discovered (`DashboardPanelProvider::discoverResources`); do not work around it by registering it on the dashboard home.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Dashboard/Resources/TraccarServers resources/views/filament/dashboard/widgets/traccar-devices-map.blade.php resources/views/filament/partials/leaflet-loader.blade.php tests/Feature/TraccarDevicesMapWidgetTest.php
git commit -m "Show a Leaflet map of all Traccar devices above the Traccar Servers table

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Post-implementation verification (controller, in a real browser)

Livewire/PHP tests cannot prove the JavaScript. After both tasks:

1. Start a throwaway stub Traccar server on the host serving `GET /api/devices` and `GET /api/positions` (one fresh device checked in minutes ago, one stale device checked in hours ago, one device with no position, one disabled device), reachable from the app container as `http://host.docker.internal:<port>` (the front-end container has `extra_hosts: host.docker.internal:host-gateway`). Create a Traccar server pointing at it for a Plus user (via tinker or the UI; the form requires the stub to answer `/api/positions` with 2xx).
2. Open `/traccar-servers` in the in-app browser and confirm: the map appears, one **red** and one **gray** pin, the footer says one device has no position, clicking a pin shows Server / Device / Last check-in with a relative time, and the disabled device is absent.
3. Confirm the zero-server state (a user with no servers): the map still shows at the default US view with "Add a Traccar server to see its devices here."
4. Point a second server at a dead port: the caption names it ("Couldn't reach: …") while the good server's pins remain.
5. Leave the page open >60 s (or shorten the interval temporarily) and confirm a refresh keeps the view and an open pop-up; navigate away and back and confirm there is no console error and no duplicated timers.
6. Check the browser console for errors throughout (`read_console_messages`).
7. Delete the stub server and any test servers/users created for the check.

---

## Self-Review (run against the spec)

**Spec coverage:** live fetch after page load + 60 s per-server cache keyed on `updated_at` → Task 1; parallel fetch, 5 s timeout, no redirects → Task 1; last check-in = `lastUpdate` with `serverTime` fallback, ISO strings → Task 1; stale threshold 1 h with the exact-boundary rule and unknown-is-stale → Task 1; disabled skipped, unplaced counted, unreachable named, malformed voids only that server, fixed-string logs → Task 1; token never in the payload → Tasks 1–2; scoped to the signed-in user's servers → Task 2; header widget above the table, full width, `getWidgets()` registration → Task 2; Leaflet via the shared lazy loader (no new dependency), OSM tiles, default US view → Task 2; SVG red/gray pins, `textContent` pop-up with server/device/last check-in (absolute + relative, "unknown", stale note) → Task 2; auto-refresh every 60 s updating markers in place, fit-bounds on first load only, teardown clearing the interval and map → Task 2; caption states (no servers, no devices, unplaced, unreachable, failed refresh) → Task 2; zero-server map still shown → Task 2 tests + verification; non-goals respected (no clustering/trails/guard); the `TraccarUrlGuard` follow-up is explicitly out of scope. Real-browser verification of markers/pop-up/refresh is a controller step.

**Placeholder scan:** none.

**Type consistency:** the payload keys (`server`, `key`, `name`, `lat`, `lng`, `last_checkin`, `stale`, `unplaced`, `unreachable`, `has_servers`) match between the action, the widget method, the tests and the JavaScript. `devices()` is the Livewire method name used by `$wire.devices()`.
