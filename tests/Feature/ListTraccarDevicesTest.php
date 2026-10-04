<?php

use App\Actions\Traccar\ListTraccarDevices;
use App\Models\TraccarServer;
use App\Support\TraccarUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('a successful response resolves to a list of devices', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'api_token' => 'tok123']);

    Http::fake([
        'traccar.example.com/api/devices' => Http::response([
            ['id' => 1, 'name' => 'My Truck', 'uniqueId' => 'abc123'],
            ['id' => 2, 'name' => 'My Van', 'uniqueId' => 'def456'],
        ]),
    ]);

    $devices = (new ListTraccarDevices)($server);

    expect($devices)->toBe([
        ['id' => 1, 'name' => 'My Truck'],
        ['id' => 2, 'name' => 'My Van'],
    ]);
});

test('the request is authenticated with the server\'s bearer token', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'api_token' => 'secret-token']);

    Http::fake(['traccar.example.com/api/devices' => Http::response([])]);

    (new ListTraccarDevices)($server);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

test('a non-2xx response resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake(['traccar.example.com/api/devices' => Http::response(['error' => 'Unauthorized'], 401)]);

    expect((new ListTraccarDevices)($server))->toBeNull();
});

test('a connection failure resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://unreachable.example.com']);

    Http::fake(['unreachable.example.com/*' => fn () => throw new ConnectionException('Connection refused')]);

    expect((new ListTraccarDevices)($server))->toBeNull();
});

test('an empty body resolves to null, not an empty device list', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake(['traccar.example.com/api/devices' => Http::response('', 200)]);

    expect((new ListTraccarDevices)($server))->toBeNull();
});

test('a non-array JSON body resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake(['traccar.example.com/api/devices' => Http::response(['unexpected' => 'object'], 200)]);

    expect((new ListTraccarDevices)($server))->toBeNull();
});

test('a device entry missing required fields resolves the whole response to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake([
        'traccar.example.com/api/devices' => Http::response([
            ['id' => 1, 'name' => 'Good Device'],
            ['id' => 2], // missing name
        ]),
    ]);

    expect((new ListTraccarDevices)($server))->toBeNull();
});

test('a genuinely empty device list (a valid JSON array with zero entries) resolves to an empty array, not null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake(['traccar.example.com/api/devices' => Http::response([], 200)]);

    expect((new ListTraccarDevices)($server))->toBe([]);
});

test('a server whose URL violates the guard is never called and resolves to null', function () {
    config(['wxnotify.mode' => 'cloud']);
    app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(fn (string $host): array => ['10.0.0.5']));
    $server = TraccarServer::factory()->create(['base_url' => 'http://traccar.internal.example.com:8082']);

    Http::fake();

    expect((new ListTraccarDevices)($server))->toBeNull();
    Http::assertNothingSent();
});

test('a redirect is not followed and resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake([
        'traccar.example.com/api/devices' => Http::response('', 302, ['Location' => 'https://elsewhere.example.com/api/devices']),
        'elsewhere.example.com/*' => Http::response([['id' => 1, 'name' => 'Leaked']]),
    ]);

    expect((new ListTraccarDevices)($server))->toBeNull();
    Http::assertSentCount(1);
});

test('the request is pinned to the addresses the guard vetted', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com:8443']);
    $sent = null;

    Http::fake(['traccar.example.com:8443/api/devices' => function ($request, array $options) use (&$sent) {
        $sent = $options;

        return Http::response([]);
    }]);

    (new ListTraccarDevices)($server);

    expect($sent['curl'][CURLOPT_RESOLVE])->toBe(['traccar.example.com:8443:93.184.216.34']);
});

test('a host name that does not resolve is refused before any request is sent', function () {
    app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(fn (string $host): array => []));
    config(['wxnotify.mode' => 'selfhost']);
    $server = TraccarServer::factory()->create(['base_url' => 'http://traccar:8082']);

    Http::fake();

    expect((new ListTraccarDevices)($server))->toBeNull();
    Http::assertNothingSent();
});
