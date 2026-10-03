<?php

use App\Actions\Traccar\PollTraccarServer;
use App\Models\TraccarServer;
use App\Support\TraccarUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('a successful response resolves to positions keyed by deviceId', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'api_token' => 'tok123']);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response([
            ['id' => 501, 'deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786, 'fixTime' => '2026-09-14T12:00:00Z'],
            ['id' => 502, 'deviceId' => 2, 'latitude' => 41.8781, 'longitude' => -87.6298, 'fixTime' => '2026-09-14T12:01:00Z'],
        ]),
    ]);

    $positions = (new PollTraccarServer)($server);

    expect($positions)->toBe([
        1 => ['lat' => 39.0997, 'lng' => -94.5786, 'fixTime' => '2026-09-14T12:00:00Z'],
        2 => ['lat' => 41.8781, 'lng' => -87.6298, 'fixTime' => '2026-09-14T12:01:00Z'],
    ]);
});

test('the request is authenticated with the server\'s bearer token', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'api_token' => 'secret-token']);

    Http::fake(['traccar.example.com/api/positions' => Http::response([])]);

    (new PollTraccarServer)($server);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

test('a non-2xx response resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake(['traccar.example.com/api/positions' => Http::response(['error' => 'Unauthorized'], 401)]);

    expect((new PollTraccarServer)($server))->toBeNull();
});

test('a connection failure resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://unreachable.example.com']);

    Http::fake(['unreachable.example.com/*' => fn () => throw new ConnectionException('Connection refused')]);

    expect((new PollTraccarServer)($server))->toBeNull();
});

test('an empty body resolves to null, not an empty position list', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake(['traccar.example.com/api/positions' => Http::response('', 200)]);

    expect((new PollTraccarServer)($server))->toBeNull();
});

test('a position entry missing required fields resolves the whole response to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response([
            ['deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786],
            ['deviceId' => 2, 'latitude' => 41.8781], // missing longitude
        ]),
    ]);

    expect((new PollTraccarServer)($server))->toBeNull();
});

test('a genuinely empty position list (a valid JSON array with zero entries) resolves to an empty array, not null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake(['traccar.example.com/api/positions' => Http::response([], 200)]);

    expect((new PollTraccarServer)($server))->toBe([]);
});

test('a position entry with a non-numeric latitude resolves the whole response to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response([
            ['deviceId' => 1, 'latitude' => 'not-a-number', 'longitude' => -94.5786],
        ]),
    ]);

    expect((new PollTraccarServer)($server))->toBeNull();
});

test('a server whose URL violates the guard is never called and resolves to null', function () {
    config(['wxnotify.mode' => 'cloud']);
    app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(fn (string $host): array => ['10.0.0.5']));
    $server = TraccarServer::factory()->create(['base_url' => 'http://traccar.internal.example.com:8082']);

    Http::fake();

    expect((new PollTraccarServer)($server))->toBeNull();
    Http::assertNothingSent();
});

test('a redirect is not followed and resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response('', 302, ['Location' => 'https://elsewhere.example.com/api/positions']),
        'elsewhere.example.com/*' => Http::response([['deviceId' => 1, 'latitude' => 39.0, 'longitude' => -94.0]]),
    ]);

    expect((new PollTraccarServer)($server))->toBeNull();
    Http::assertSentCount(1);
});
