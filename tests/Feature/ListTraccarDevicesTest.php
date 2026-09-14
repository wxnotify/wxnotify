<?php

use App\Actions\Traccar\ListTraccarDevices;
use App\Models\TraccarServer;
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
