<?php

use App\Actions\Traccar\PollTraccarServer;
use App\Models\TraccarServer;
use App\Support\TraccarUrlGuard;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
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

test('a position Traccar flags as invalid is skipped without voiding the rest of the batch', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response([
            ['deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786, 'fixTime' => null, 'valid' => true],
            ['deviceId' => 2, 'latitude' => 41.8781, 'longitude' => -87.6298, 'fixTime' => null, 'valid' => false],
            ['deviceId' => 3, 'latitude' => 35.0, 'longitude' => -100.0, 'fixTime' => null],
        ]),
    ]);

    $positions = (new PollTraccarServer)($server);

    expect(array_keys($positions))->toBe([1, 3]);
});

test('a position at exactly 0,0 is skipped, but a single zero coordinate is a real position', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response([
            ['deviceId' => 1, 'latitude' => 0, 'longitude' => 0, 'fixTime' => null],
            ['deviceId' => 2, 'latitude' => 0.0, 'longitude' => 0.0, 'fixTime' => null],
            ['deviceId' => 3, 'latitude' => 0, 'longitude' => -80.0, 'fixTime' => null],
            ['deviceId' => 4, 'latitude' => 25.0, 'longitude' => 0, 'fixTime' => null],
        ]),
    ]);

    expect(array_keys((new PollTraccarServer)($server)))->toBe([3, 4]);
});

test('an out-of-range or non-finite coordinate voids the whole response, as malformed data does', function (mixed $latitude, mixed $longitude) {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response([
            ['deviceId' => 1, 'latitude' => 39.0, 'longitude' => -94.0, 'fixTime' => null],
            ['deviceId' => 2, 'latitude' => $latitude, 'longitude' => $longitude, 'fixTime' => null],
        ]),
    ]);

    expect((new PollTraccarServer)($server))->toBeNull();
})->with([
    'latitude above 90' => [90.5, -94.0],
    'latitude below -90' => [-91, -94.0],
    'longitude above 180' => [39.0, 180.5],
    'non-finite latitude' => ['1e999', -94.0],
]);

test('the request is pinned to the addresses the guard vetted', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com:8443']);
    $sent = null;

    Http::fake(['traccar.example.com:8443/api/positions' => function ($request, array $options) use (&$sent) {
        $sent = $options;

        return Http::response([]);
    }]);

    (new PollTraccarServer)($server);

    expect($sent['curl'][CURLOPT_RESOLVE])->toBe(['traccar.example.com:8443:93.184.216.34']);
});

test('a host name that does not resolve is refused before any request is sent', function () {
    app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(fn (string $host): array => []));
    config(['wxnotify.mode' => 'selfhost']);
    $server = TraccarServer::factory()->create(['base_url' => 'http://traccar:8082']);

    Http::fake();

    expect((new PollTraccarServer)($server))->toBeNull();
    Http::assertNothingSent();
});

test('poll() reports a specific, fixed reason for each kind of failure', function (string $case, string $expected) {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'api_token' => 'secret-token']);

    Http::fake(['traccar.example.com/api/positions' => match ($case) {
        '401' => Http::response('', 401),
        '403' => Http::response('', 403),
        '404' => Http::response('', 404),
        '429' => Http::response('', 429),
        '502' => Http::response('', 502),
        '302' => Http::response('', 302, ['Location' => 'https://elsewhere.example.com']),
        '418' => Http::response('', 418),
        'malformed body' => Http::response('not json', 200),
        'malformed entry' => Http::response([['deviceId' => 1, 'latitude' => 'x', 'longitude' => 2]]),
        'timeout' => fn () => throw new ConnectionException('cURL error 28: https://traccar.example.com/api/positions secret-token', 0, new ConnectException('x', new Request('GET', '/'), null, ['errno' => 28])),
        'dns' => fn () => throw new ConnectionException('cURL error 6', 0, new ConnectException('x', new Request('GET', '/'), null, ['errno' => 6])),
        'refused' => fn () => throw new ConnectionException('cURL error 7', 0, new ConnectException('x', new Request('GET', '/'), null, ['errno' => 7])),
        'tls' => fn () => throw new ConnectionException('cURL error 60', 0, new ConnectException('x', new Request('GET', '/'), null, ['errno' => 60])),
        'other' => fn () => throw new ConnectionException('boom https://traccar.example.com secret-token'),
    }]);

    $result = (new PollTraccarServer)->poll($server);

    expect($result['positions'])->toBeNull()
        ->and($result['error'])->toBe($expected)
        ->and($result['error'])->not->toContain('secret-token')->not->toContain('traccar.example.com');
})->with([
    '401' => ['401', 'The server rejected the API token (HTTP 401). Check that the token is still valid.'],
    '403' => ['403', 'The server rejected the API token (HTTP 403). Check that the token is still valid.'],
    '404' => ['404', 'The server answered HTTP 404. Check the server URL.'],
    '429' => ['429', 'The server is rate limiting requests (HTTP 429). Polling will retry.'],
    '502' => ['502', 'The Traccar server reported an error (HTTP 502).'],
    '302' => ['302', 'The server redirected the request (HTTP 302). Use the server\'s final URL.'],
    '418' => ['418', 'The server answered HTTP 418.'],
    'malformed body' => ['malformed body', 'The server sent a response WxNotify could not understand.'],
    'malformed entry' => ['malformed entry', 'The server sent a response WxNotify could not understand.'],
    'timeout' => ['timeout', 'The server took too long to respond.'],
    'dns' => ['dns', 'The server\'s host name could not be found.'],
    'refused' => ['refused', 'The server refused the connection. Check the host and port.'],
    'tls' => ['tls', 'Could not make a secure (HTTPS) connection. Check the server\'s certificate.'],
    'other' => ['other', 'Could not connect to the server.'],
]);

test('poll() reports the guard\'s message for a URL it refuses, and for a host that does not resolve', function () {
    config(['wxnotify.mode' => 'cloud']);
    $server = TraccarServer::factory()->create(['base_url' => 'http://traccar.internal.example.com:8082']);
    Http::fake();

    app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(fn (string $host): array => ['10.0.0.5']));
    $private = (new PollTraccarServer)->poll($server);

    app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(fn (string $host): array => []));
    config(['wxnotify.mode' => 'selfhost']);
    $unresolved = (new PollTraccarServer)->poll($server);

    expect($private['error'])->toBe('That address resolves to a private or reserved network, which is not allowed.')
        ->and($unresolved['error'])->toBe('That host name could not be resolved.');
    Http::assertNothingSent();
});

test('poll() reports no error on success', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    Http::fake(['traccar.example.com/api/positions' => Http::response([])]);

    expect((new PollTraccarServer)->poll($server))->toBe(['positions' => [], 'error' => null]);
});
