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
