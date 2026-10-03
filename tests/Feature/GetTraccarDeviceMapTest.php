<?php

use App\Actions\Traccar\GetTraccarDeviceMap;
use App\Models\TraccarServer;
use App\Support\TraccarUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

function traccarPosition(int $deviceId, float $lat = 39.74, float $lng = -104.99, ?string $serverTime = '2026-10-02T11:59:00Z'): array
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
        ], [traccarPosition(1, 40.0, -105.0), traccarPosition(2, 41.0, -106.0)]),
        ...fakeTraccar('boat.example.com', [['id' => 7, 'name' => 'Dinghy', 'lastUpdate' => '2026-10-02T11:59:00Z']], [traccarPosition(7, 25.7, -80.1)]),
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
    ], [traccarPosition(1), traccarPosition(2), traccarPosition(3)]));

    $stale = collect((new GetTraccarDeviceMap)([$server])['devices'])->pluck('stale', 'name')->all();

    expect($stale)->toBe(['Exactly an hour' => false, 'A second over' => true, 'Recent' => false]);
});

test('a missing lastUpdate falls back to the position serverTime, and no time at all is stale and unknown', function () {
    $server = mapServer('Fleet', 'fleet.example.com');
    Http::fake(fakeTraccar('fleet.example.com', [
        ['id' => 1, 'name' => 'Fallback', 'lastUpdate' => null],
        ['id' => 2, 'name' => 'Nothing', 'lastUpdate' => null],
    ], [traccarPosition(1, serverTime: '2026-10-02T11:58:00Z'), traccarPosition(2, serverTime: null)]));

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
    ], [traccarPosition(1), traccarPosition(3)]));

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
        'a redirect' => ['bad.example.com/api/devices' => Http::response('', 302, ['Location' => 'https://elsewhere.example.com/x']), 'bad.example.com/api/positions' => Http::response([])],
        'a non-json body' => ['bad.example.com/api/devices' => Http::response('<html>hi</html>'), 'bad.example.com/api/positions' => Http::response([])],
        'non-numeric coordinates' => fakeTraccar('bad.example.com', [['id' => 1, 'name' => 'X']], [['deviceId' => 1, 'latitude' => 'north', 'longitude' => 'west']]),
        'a malformed device' => fakeTraccar('bad.example.com', ['not-a-device'], []),
        'an array-typed name' => fakeTraccar('bad.example.com', [['id' => 1, 'name' => ['x']]], []),
        'an array-typed id' => fakeTraccar('bad.example.com', [['id' => [1], 'name' => 'X']], []),
        'a junk string id' => fakeTraccar('bad.example.com', [['id' => 'abc', 'name' => 'X']], []),
        'an array-typed deviceId' => fakeTraccar('bad.example.com', [['id' => 1, 'name' => 'X']], [['deviceId' => [1], 'latitude' => 1, 'longitude' => 1]]),
        'a latitude of 91' => fakeTraccar('bad.example.com', [['id' => 1, 'name' => 'X']], [['deviceId' => 1, 'latitude' => 91, 'longitude' => 0]]),
        'a longitude of -181' => fakeTraccar('bad.example.com', [['id' => 1, 'name' => 'X']], [['deviceId' => 1, 'latitude' => 0, 'longitude' => -181]]),
        'an infinite coordinate' => fakeTraccar('bad.example.com', [['id' => 1, 'name' => 'X']], [['deviceId' => 1, 'latitude' => '1e999', 'longitude' => 0]]),
        'an infinite JSON number' => [
            'bad.example.com/api/devices' => Http::response([['id' => 1, 'name' => 'X']]),
            'bad.example.com/api/positions' => Http::response('[{"deviceId":1,"latitude":0,"longitude":1e999}]', 200, ['Content-Type' => 'application/json']),
        ],
        'a connection failure' => ['bad.example.com/*' => fn () => throw new ConnectionException('Connection refused')],
    };

    Http::fake([...fakeTraccar('good.example.com', [['id' => 1, 'name' => 'Fine', 'lastUpdate' => '2026-10-02T11:59:00Z']], [traccarPosition(1)]), ...$badFakes]);

    $result = (new GetTraccarDeviceMap)([$good, $bad]);

    expect(collect($result['devices'])->pluck('name')->all())->toBe(['Fine'])
        ->and($result['unreachable'])->toBe(['Bad'])
        ->and(Cache::has("traccar-device-map:{$bad->id}:{$bad->updated_at->getTimestamp()}"))->toBeFalse();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'elsewhere.example.com'));
})->with([
    'a 500', 'a 401', 'a redirect', 'a non-json body', 'non-numeric coordinates', 'a malformed device',
    'an array-typed name', 'an array-typed id', 'a junk string id', 'an array-typed deviceId',
    'a latitude of 91', 'a longitude of -181', 'an infinite coordinate', 'an infinite JSON number', 'a connection failure',
]);

test('requests carry the bearer token, and the token never appears in the result', function () {
    $server = mapServer('Fleet', 'fleet.example.com', 'super-secret-token');
    Http::fake(fakeTraccar('fleet.example.com', [['id' => 1, 'name' => 'Truck', 'lastUpdate' => '2026-10-02T11:59:00Z']], [traccarPosition(1)]));

    $result = (new GetTraccarDeviceMap)([$server]);

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => ! $request->hasHeader('Authorization', 'Bearer super-secret-token'));
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
        ...fakeTraccar('fleet.example.com', [['id' => 1, 'name' => 'Truck', 'lastUpdate' => '2026-10-02T11:59:00Z']], [traccarPosition(1)]),
        ...fakeTraccar('boat.example.com', [['id' => 7, 'name' => 'Dinghy', 'lastUpdate' => '2026-10-02T11:59:00Z']], [traccarPosition(7)]),
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
        'fleet.example.com/api/positions' => Http::response([traccarPosition(1)]),
    ]);

    $first = (new GetTraccarDeviceMap)([$server]);
    $second = (new GetTraccarDeviceMap)([$server]);

    expect($first['unreachable'])->toBe(['Fleet'])
        ->and(collect($second['devices'])->pluck('name')->all())->toBe(['Truck'])
        ->and($second['unreachable'])->toBe([]);
});

test('staleness is recomputed on every call, even from cache', function () {
    $server = mapServer('Fleet', 'fleet.example.com');
    Http::fake(fakeTraccar('fleet.example.com', [['id' => 1, 'name' => 'Truck', 'lastUpdate' => '2026-10-02T11:00:30Z']], [traccarPosition(1)]));

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

test('a server whose base_url cannot be parsed is unreachable, and never takes the others down', function () {
    Log::spy();
    $good = mapServer('Good', 'good.example.com');
    $bad = mapServer('Bad', 'bad.example.com:80800');
    Http::fake(fakeTraccar('good.example.com', [['id' => 1, 'name' => 'Fine', 'lastUpdate' => '2026-10-02T11:59:00Z']], [traccarPosition(1)]));

    $result = (new GetTraccarDeviceMap)([$bad, $good]);

    expect(collect($result['devices'])->pluck('name')->all())->toBe(['Fine'])
        ->and($result['unreachable'])->toBe(['Bad'])
        ->and(Cache::has("traccar-device-map:{$bad->id}:{$bad->updated_at->getTimestamp()}"))->toBeFalse();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'bad.example.com'));
    Log::shouldHaveReceived('warning')->with('Traccar device map: server misconfigured', ['traccar_server_id' => $bad->id])->once();
});

test('a server whose api_token cannot be decrypted is unreachable, and never takes the others down', function () {
    Log::spy();
    $good = mapServer('Good', 'good.example.com');
    $bad = mapServer('Bad', 'bad.example.com');
    DB::table('traccar_servers')->where('id', $bad->id)->update(['api_token' => 'not-encrypted-at-all']);
    $bad = $bad->fresh();
    Http::fake(fakeTraccar('good.example.com', [['id' => 1, 'name' => 'Fine', 'lastUpdate' => '2026-10-02T11:59:00Z']], [traccarPosition(1)]));

    $result = (new GetTraccarDeviceMap)([$good, $bad]);

    expect(collect($result['devices'])->pluck('name')->all())->toBe(['Fine'])
        ->and($result['unreachable'])->toBe(['Bad'])
        ->and(Cache::has("traccar-device-map:{$bad->id}:{$bad->updated_at->getTimestamp()}"))->toBeFalse();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'bad.example.com'));
    Log::shouldHaveReceived('warning')->with('Traccar device map: server misconfigured', ['traccar_server_id' => $bad->id])->once();
});

test('a server whose URL violates the guard is excluded without affecting the others', function () {
    config(['wxnotify.mode' => 'cloud']);
    app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(
        fn (string $host): array => $host === 'internal.example.com' ? ['10.0.0.5'] : ['93.184.216.34'],
    ));
    $bad = mapServer('Internal', 'internal.example.com');
    $good = mapServer('Fleet', 'fleet.example.com');
    Http::fake([
        ...fakeTraccar('fleet.example.com', [['id' => 1, 'name' => 'Truck 1', 'lastUpdate' => '2026-10-02T11:55:00Z']], [traccarPosition(1)]),
        ...fakeTraccar('internal.example.com', [['id' => 9, 'name' => 'Leaked', 'lastUpdate' => '2026-10-02T11:55:00Z']], [traccarPosition(9)]),
    ]);

    $result = (new GetTraccarDeviceMap)([$bad, $good]);

    expect(collect($result['devices'])->pluck('server')->unique()->all())->toBe(['Fleet'])
        ->and($result['unreachable'])->toBe(['Internal']);
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'internal.example.com'));
});

test('a device whose position is flagged invalid or sits at 0,0 is counted as unplaced, not drawn', function () {
    $server = mapServer('Fleet', 'fleet.example.com');
    Http::fake(fakeTraccar('fleet.example.com', [
        ['id' => 1, 'name' => 'Placed', 'lastUpdate' => '2026-10-02T11:59:00Z'],
        ['id' => 2, 'name' => 'No GPS fix', 'lastUpdate' => '2026-10-02T11:59:00Z'],
        ['id' => 3, 'name' => 'Null Island', 'lastUpdate' => '2026-10-02T11:59:00Z'],
        ['id' => 4, 'name' => 'On the equator', 'lastUpdate' => '2026-10-02T11:59:00Z'],
    ], [
        traccarPosition(1),
        [...traccarPosition(2), 'valid' => false],
        traccarPosition(3, 0.0, 0.0),
        traccarPosition(4, 0.0, -78.5),
    ]));

    $result = (new GetTraccarDeviceMap)([$server]);

    expect(collect($result['devices'])->pluck('name')->all())->toBe(['Placed', 'On the equator'])
        ->and($result['unplaced'])->toBe(2);
});
