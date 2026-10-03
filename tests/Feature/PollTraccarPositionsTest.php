<?php

use App\Models\Location;
use App\Models\TraccarServer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
