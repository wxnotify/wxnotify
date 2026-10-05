<?php

use App\Jobs\ReverseGeocodeLocation;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
    $this->location = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9']);
    $this->beacon = $this->user->createToken('Location beacon - Pixel 9', ['location'], now()->addDays(90));
    $this->beacon->accessToken->forceFill(['parent_token_id' => $this->phone->accessToken->id])->save();
});

function beaconPoint(array $overrides = []): array
{
    return $overrides + ['latitude' => 35.2, 'longitude' => -97.4, 'accuracy' => 8.5, 'timestamp' => now()->subMinute()->toIso8601String()];
}

function beaconPost($test, array $points, ?string $bearer = null)
{
    // The guard caches the first request's user for the rest of the test.
    app('auth')->forgetGuards();

    return $test->withToken($bearer ?? $test->beacon->plainTextToken)->postJson('/api/v1/location', ['points' => $points]);
}

test('the newest point of a batch moves the linked location', function () {
    $this->travelTo(now()->startOfSecond());

    beaconPost($this, [
        beaconPoint(['latitude' => 10.0, 'longitude' => 10.0, 'timestamp' => now()->subMinutes(5)->toIso8601String()]),
        beaconPoint(['latitude' => 35.2, 'longitude' => -97.4, 'timestamp' => now()->subMinute()->toIso8601String()]),
    ])->assertNoContent();

    $fresh = $this->location->fresh();
    expect((float) $fresh->lat)->toBe(35.2)
        ->and((float) $fresh->lng)->toBe(-97.4)
        ->and($fresh->mobile_position_fixed_at->equalTo(now()->subMinute()))->toBeTrue()
        ->and($fresh->mobile_position_updated_at->equalTo(now()))->toBeTrue();
});

test('the fix time is stored in UTC whatever offset the beacon sends', function () {
    $this->travelTo(now()->startOfSecond());
    beaconPost($this, [beaconPoint(['timestamp' => now()->utc()->subMinute()->setTimezone('+02:00')->toIso8601String()])])->assertNoContent();

    $expected = now()->utc()->subMinute();
    expect($this->location->fresh()->mobile_position_fixed_at->utc()->equalTo($expected))->toBeTrue()
        ->and(DB::table('locations')->where('id', $this->location->id)->value('mobile_position_fixed_at'))
        ->toStartWith($expected->format('Y-m-d H:i:s'));
});

test('a point older than the stored fix is accepted and ignored', function () {
    $this->travelTo(now()->startOfSecond());
    $this->location->forceFill(['lat' => 35.2, 'lng' => -97.4, 'mobile_position_fixed_at' => now()])->save();

    beaconPost($this, [beaconPoint(['latitude' => 10.0, 'timestamp' => now()->subHour()->toIso8601String()])])->assertNoContent();

    expect((float) $this->location->fresh()->lat)->toBe(35.2);
});

test('the request is validated', function (string $case) {
    $points = match ($case) {
        'empty batch' => [],
        'too many points' => array_fill(0, 51, beaconPoint()),
        'latitude out of range' => [beaconPoint(['latitude' => 91])],
        'longitude out of range' => [beaconPoint(['longitude' => -181])],
        'not numeric' => [beaconPoint(['latitude' => 'north'])],
        'no timestamp' => [['latitude' => 35.2, 'longitude' => -97.4]],
        'unparseable timestamp' => [beaconPoint(['timestamp' => 'yesterday-ish'])],
        'timestamp too far ahead' => [beaconPoint(['timestamp' => now()->addMinutes(10)->toIso8601String()])],
    };

    beaconPost($this, $points)->assertUnprocessable();

    expect($this->location->fresh()->lat)->toBeNull();
})->with([
    'empty batch',
    'too many points',
    'latitude out of range',
    'longitude out of range',
    'not numeric',
    'no timestamp',
    'unparseable timestamp',
    'timestamp too far ahead',
]);

test('an unlinked phone gets a 404 and nothing is written', function () {
    $this->location->forceFill(['app_token_id' => null])->save();

    beaconPost($this, [beaconPoint()])->assertNotFound();

    expect($this->location->fresh()->lat)->toBeNull();
});

test('a beacon token with no parent app token never reaches an unlinked location', function () {
    $unlinked = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'Signed out phone']);
    $orphan = $this->user->createToken('Location beacon - orphan', ['location'], now()->addDays(90));

    beaconPost($this, [beaconPoint()], $orphan->plainTextToken)->assertNotFound();

    $fresh = $unlinked->fresh();
    expect($fresh->lat)->toBeNull()
        ->and($fresh->lng)->toBeNull();
});

test('a location that belongs to another account is never written', function () {
    $this->location->forceFill(['user_id' => User::factory()->create()->id])->save();

    beaconPost($this, [beaconPoint()])->assertNotFound();

    expect($this->location->fresh()->lat)->toBeNull();
});

test('the two token abilities do not cross', function () {
    beaconPost($this, [beaconPoint()], $this->phone->plainTextToken)->assertForbidden();

    app('auth')->forgetGuards();
    $this->withToken($this->beacon->plainTextToken)->getJson('/api/v1/me')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($this->beacon->plainTextToken)->putJson('/api/v1/push-token', ['token' => 'x', 'platform' => 'ios'])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($this->beacon->plainTextToken)->deleteJson('/api/v1/auth/token')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($this->beacon->plainTextToken)->getJson('/api/v1/location-sharing')->assertForbidden();
});

test('a request without a token is refused', function () {
    $this->postJson('/api/v1/location', ['points' => [beaconPoint()]])->assertUnauthorized();
});

test('using the beacon token keeps it alive for as long as it is used', function () {
    $this->travelTo(now()->startOfSecond());
    $this->beacon->accessToken->forceFill(['expires_at' => now()->addDays(80)])->save();

    beaconPost($this, [beaconPoint()])->assertNoContent();

    expect($this->beacon->accessToken->fresh()->expires_at->toDateString())->toBe(now()->addDays(90)->toDateString());
});

test('a position write adds no activity row', function () {
    $before = Activity::count();

    beaconPost($this, [beaconPoint()])->assertNoContent();

    expect(Activity::count())->toBe($before);
});

test('a first position queues the address lookup', function () {
    Queue::fake();
    setIntegrationSetting('reverse_geocoding_enabled', true);

    beaconPost($this, [beaconPoint()])->assertNoContent();

    Queue::assertPushed(ReverseGeocodeLocation::class);
});

test('the endpoint is rate limited per token', function () {
    $other = $this->user->createToken('Location beacon - other', ['location'], now()->addDays(90));
    $other->accessToken->forceFill(['parent_token_id' => $this->phone->accessToken->id])->save();

    foreach (range(1, 30) as $i) {
        beaconPost($this, [beaconPoint()])->assertNoContent();
    }

    beaconPost($this, [beaconPoint()])->assertStatus(429);

    beaconPost($this, [beaconPoint()], $other->plainTextToken)->assertNoContent();
});
