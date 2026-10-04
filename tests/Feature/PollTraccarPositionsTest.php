<?php

use App\Jobs\ReverseGeocodeLocation;
use App\Models\Location;
use App\Models\TraccarServer;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

test('a due mobile location is updated with its server\'s reported position', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_update_interval_minutes' => 15,
    ]);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response([
            ['deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786, 'fixTime' => '2026-09-14T12:00:00Z'],
        ]),
    ]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    $location->refresh();
    expect((float) $location->lat)->toBe(39.0997);
    expect((float) $location->lng)->toBe(-94.5786);
    expect($location->mobile_position_updated_at)->not->toBeNull();
});

test('a successful poll does not spam the activity log', function () {
    // Location's activity log doesn't exclude lat/lng (they're real,
    // audit-worthy data for a subscriber's own stationary-location edits),
    // so without disableLogging() around the poll's write, this exact
    // command would create a fresh Activity row every 5-30 minutes forever
    // per mobile location. This proves that doesn't happen.
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->create(['traccar_server_id' => $server->id, 'traccar_device_id' => 1]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786, 'fixTime' => null],
    ])]);

    // The Location::factory()->mobile()->create() call above already logs
    // its own 'created' Activity row (Location's activitylog options log
    // every event, only mobile_position_updated_at is excluded) -- that row
    // is unrelated to the poll and would make an exists() check meaningless.
    // Compare a before/after count instead, so this only proves what it's
    // meant to: the poll's own lat/lng write doesn't add a new row.
    $activityCountBefore = Activity::where('subject_type', Location::class)->where('subject_id', $location->id)->count();

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect(Activity::where('subject_type', Location::class)->where('subject_id', $location->id)->count())->toBe($activityCountBefore);
});

test('a location polled more recently than its own interval is left alone', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->withFix(10.0, 10.0)->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_update_interval_minutes' => 30,
        'mobile_position_updated_at' => now()->subMinutes(5),
    ]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 99.0, 'longitude' => 99.0, 'fixTime' => null],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    $location->refresh();
    expect((float) $location->lat)->toBe(10.0);
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'traccar.example.com'));
});

test('a server with no due locations is never queried', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    Location::factory()->mobile()->withFix(10.0, 10.0)->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_update_interval_minutes' => 30,
        'mobile_position_updated_at' => now()->subMinutes(5),
    ]);

    Http::fake();

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    Http::assertNothingSent();
});

test('one server\'s failure does not block a second server\'s successful poll', function () {
    $badServer = TraccarServer::factory()->create(['base_url' => 'https://bad.example.com']);
    $goodServer = TraccarServer::factory()->create(['base_url' => 'https://good.example.com']);
    $badLocation = Location::factory()->mobile()->create(['traccar_server_id' => $badServer->id, 'traccar_device_id' => 1]);
    $goodLocation = Location::factory()->mobile()->create(['traccar_server_id' => $goodServer->id, 'traccar_device_id' => 5]);

    Http::fake([
        'bad.example.com/*' => Http::response(['error' => 'nope'], 500),
        'good.example.com/*' => Http::response([
            ['deviceId' => 5, 'latitude' => 1.0, 'longitude' => 2.0, 'fixTime' => null],
        ]),
    ]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($goodLocation->refresh()->lat)->not->toBeNull();
    expect($badLocation->refresh()->lat)->toBeNull();
    expect($badServer->refresh()->last_poll_error)->not->toBeNull();
    expect($goodServer->refresh()->last_poll_error)->toBeNull();
});

test('a subsequent successful poll clears a previous poll error', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'last_poll_error' => 'previous failure']);
    Location::factory()->mobile()->create(['traccar_server_id' => $server->id, 'traccar_device_id' => 1]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 1.0, 'longitude' => 2.0, 'fixTime' => null],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($server->refresh()->last_poll_error)->toBeNull();
});

test('a mobile location with no update interval set is never polled', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    Location::factory()->mobile()->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_update_interval_minutes' => null,
    ]);

    Http::fake();

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    Http::assertNothingSent();
});

test('a location that is no longer mobile is never polled, even if it still carries Traccar ids', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_update_interval_minutes' => 15,
    ]);

    // A legacy row: switched to stationary before saving a stationary location cleared its Traccar link.
    DB::table('locations')->where('id', $location->id)->update(['type' => 'stationary', 'lat' => 40.0, 'lng' => -105.0]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 10.0, 'longitude' => 10.0, 'fixTime' => null],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    $location->refresh();
    expect((float) $location->lat)->toBe(40.0)
        ->and((float) $location->lng)->toBe(-105.0)
        ->and($location->mobile_position_updated_at)->toBeNull();
    Http::assertNothingSent();
});

test('a device reporting an invalid position keeps its last good fix and is not stamped as updated', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->withFix(39.0997, -94.5786)->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_update_interval_minutes' => 15,
        'mobile_position_updated_at' => now()->subMinutes(30),
    ]);
    $stampBefore = $location->fresh()->mobile_position_updated_at;

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 0, 'longitude' => 0, 'fixTime' => null, 'valid' => false],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    $location->refresh();
    expect((float) $location->lat)->toBe(39.0997)
        ->and((float) $location->lng)->toBe(-94.5786)
        ->and($location->mobile_position_updated_at->equalTo($stampBefore))->toBeTrue();
});

test('a failed poll stores the specific reason, and the next success clears it', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    Location::factory()->mobile()->create(['traccar_server_id' => $server->id, 'traccar_device_id' => 1]);

    Http::fake(['traccar.example.com/api/positions' => Http::sequence()->push('', 401)->push([])]);
    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($server->refresh()->last_poll_error)->toBe('The server rejected the API token (HTTP 401). Check that the token is still valid.');

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($server->refresh()->last_poll_error)->toBeNull();
});

test('a poll stores when Traccar says the device was located, apart from when it was polled', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->create(['traccar_server_id' => $server->id, 'traccar_device_id' => 1]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786, 'fixTime' => '2026-09-14T12:00:31.000+00:00'],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    $location->refresh();
    expect($location->mobile_position_fixed_at->toIso8601String())->toBe('2026-09-14T12:00:31+00:00')
        ->and($location->mobile_position_updated_at->isToday())->toBeTrue();
});

test('a position without a usable fixTime keeps the previously stored fix time', function (?string $fixTime) {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_position_fixed_at' => '2026-09-01 08:00:00',
    ]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786, 'fixTime' => $fixTime],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($location->refresh()->mobile_position_fixed_at->toDateTimeString())->toBe('2026-09-01 08:00:00')
        ->and($location->lat)->not->toBeNull();
})->with(['null' => [null], 'junk' => ['not a date']]);

test('a poll with no position for the device leaves the fix time untouched', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_position_fixed_at' => '2026-09-01 08:00:00',
    ]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($location->refresh()->mobile_position_fixed_at->toDateTimeString())->toBe('2026-09-01 08:00:00');
});

/**
 * @param  array<int, array<string, mixed>>  $positions
 * @param  array<int, array<string, mixed>>|int  $devices  the device list, or an HTTP status for a failing /api/devices
 */
function fakeTraccarServer(array $positions, array|int $devices): void
{
    Http::fake([
        'traccar.example.com/api/positions' => Http::response($positions),
        'traccar.example.com/api/devices' => is_int($devices) ? Http::response('', $devices) : Http::response($devices),
    ]);
}

function dueMobileLocation(TraccarServer $server, int $deviceId, array $attributes = []): Location
{
    return Location::factory()->mobile()->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => $deviceId,
        'mobile_update_interval_minutes' => 15,
        ...$attributes,
    ]);
}

test('a device the server\'s device list no longer contains is marked missing, keeping the first time it was noticed', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = dueMobileLocation($server, 33);
    fakeTraccarServer([], [['id' => 38, 'name' => 'iPhone']]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    $firstNoticed = $location->refresh()->traccar_device_missing_at;
    expect($firstNoticed)->not->toBeNull();

    $this->travel(20)->minutes();
    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($location->refresh()->traccar_device_missing_at->equalTo($firstNoticed))->toBeTrue();
});

test('a device that exists but has no position yet is not marked missing, and a stale mark is cleared', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = dueMobileLocation($server, 38, ['traccar_device_missing_at' => now()->subDay()]);
    fakeTraccarServer([], [['id' => 38, 'name' => 'iPhone']]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($location->refresh()->traccar_device_missing_at)->toBeNull();
});

test('a position arriving for the device clears the mark and the devices list is not requested', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = dueMobileLocation($server, 38, ['traccar_device_missing_at' => now()->subDay()]);
    fakeTraccarServer([['deviceId' => 38, 'latitude' => 40.0, 'longitude' => -105.0, 'fixTime' => '2026-10-04T02:59:31.000+00:00']], []);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($location->refresh()->traccar_device_missing_at)->toBeNull();
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/devices'));
});

test('the devices list is not requested when every due location got a position', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    dueMobileLocation($server, 1);
    fakeTraccarServer([['deviceId' => 1, 'latitude' => 40.0, 'longitude' => -105.0, 'fixTime' => null]], []);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/devices'));
});

test('a failing devices request changes nothing, since whether the device exists is unknown', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $marked = dueMobileLocation($server, 33, ['traccar_device_missing_at' => now()->subDay()->startOfSecond()]);
    $unmarked = dueMobileLocation($server, 34);
    fakeTraccarServer([], 500);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($marked->refresh()->traccar_device_missing_at)->not->toBeNull()
        ->and($unmarked->refresh()->traccar_device_missing_at)->toBeNull()
        ->and($server->refresh()->last_poll_error)->toBeNull();
});

test('only the locations whose device is gone are marked, and marking does not touch the activity log', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $gone = dueMobileLocation($server, 33);
    $present = dueMobileLocation($server, 38);
    fakeTraccarServer([], [['id' => 38, 'name' => 'iPhone']]);
    $before = Activity::where('subject_type', Location::class)->count();

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($gone->refresh()->traccar_device_missing_at)->not->toBeNull()
        ->and($present->refresh()->traccar_device_missing_at)->toBeNull()
        ->and(Activity::where('subject_type', Location::class)->count())->toBe($before);
});

test('a new position queues an address lookup only when the location was never resolved or moved 100 m', function (array $attributes, bool $queued) {
    Queue::fake();
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = dueMobileLocation($server, 1, $attributes);
    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 40.0, 'longitude' => -105.0, 'fixTime' => null],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    $queued
        ? Queue::assertPushed(ReverseGeocodeLocation::class, fn (ReverseGeocodeLocation $job) => $job->locationId === $location->id)
        : Queue::assertNothingPushed();
})->with([
    'never resolved' => [[], true],
    'resolved at this position' => [['address_geocoded_lat' => 40.0, 'address_geocoded_lng' => -105.0], false],
    'resolved 55 m away' => [['address_geocoded_lat' => 40.0005, 'address_geocoded_lng' => -105.0], false],
    'resolved 111 m away' => [['address_geocoded_lat' => 40.0010, 'address_geocoded_lng' => -105.0], true],
]);

test('no address lookup is queued while reverse geocoding is turned off', function () {
    Queue::fake();
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = dueMobileLocation($server, 1);
    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 40.0, 'longitude' => -105.0, 'fixTime' => null],
    ])]);
    setIntegrationSetting('reverse_geocoding_enabled', false);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    Queue::assertNothingPushed();
    expect((float) $location->refresh()->lat)->toBe(40.0);
});

test('only locations that got a position are queued for an address lookup', function () {
    Queue::fake();
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $withPosition = dueMobileLocation($server, 1);
    dueMobileLocation($server, 2);
    Http::fake([
        'traccar.example.com/api/positions' => Http::response([['deviceId' => 1, 'latitude' => 40.0, 'longitude' => -105.0, 'fixTime' => null]]),
        'traccar.example.com/api/devices' => Http::response([['id' => 2, 'name' => 'x']]),
    ]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    Queue::assertPushedTimes(ReverseGeocodeLocation::class, 1);
    Queue::assertPushed(ReverseGeocodeLocation::class, fn (ReverseGeocodeLocation $job) => $job->locationId === $withPosition->id);
});

test('a queue that cannot take the lookup does not fail the poll', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = dueMobileLocation($server, 1);
    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 40.0, 'longitude' => -105.0, 'fixTime' => null],
    ])]);
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    app()->bind(Dispatcher::class, fn () => throw new RuntimeException('queue down'));

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect((float) $location->refresh()->lat)->toBe(40.0);
});
