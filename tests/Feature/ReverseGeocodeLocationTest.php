<?php

use App\Actions\Locations\ReverseGeocodeCoordinates;
use App\Enums\LocationType;
use App\Jobs\ReverseGeocodeLocation;
use App\Models\Location;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    RateLimiter::clear('reverse-geocode');
    config(['filament-pinpoint.leaflet.nominatim_url' => 'https://nominatim.example.test']);
});

function runGeocodeJob(Location $location): ReverseGeocodeLocation
{
    $job = new ReverseGeocodeLocation($location->id);
    $job->withFakeQueueInteractions();
    $job->handle(app(ReverseGeocodeCoordinates::class));

    return $job;
}

function geocodableLocation(array $attributes = []): Location
{
    return Location::factory()->mobile()->withFix(40.0032, -104.9969)->create($attributes);
}

test('the address and the position it was resolved for are stored, without touching the activity log', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(['display_name' => '1 Main St, Broomfield'])]);
    $location = geocodableLocation();
    $before = Activity::where('subject_type', Location::class)->count();

    runGeocodeJob($location);

    $location->refresh();
    expect($location->address)->toBe('1 Main St, Broomfield')
        ->and((float) $location->address_geocoded_lat)->toBe(40.0032)
        ->and((float) $location->address_geocoded_lng)->toBe(-104.9969)
        ->and(Activity::where('subject_type', Location::class)->count())->toBe($before);
});

test('a definite no-address answer clears the old address and is remembered, so it is not asked again', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(['error' => 'Unable to geocode'])]);
    $location = geocodableLocation(['address' => 'Old address']);

    runGeocodeJob($location);

    $location->refresh();
    expect($location->address)->toBeNull()
        ->and($location->address_geocoded_lat)->not->toBeNull()
        ->and($location->needsAddressLookup())->toBeFalse();
});

test('a failing geocoder keeps the old address and releases the job to retry', function () {
    Http::fake(['nominatim.example.test/*' => Http::response('', 503)]);
    $location = geocodableLocation(['address' => 'Old address']);

    $job = runGeocodeJob($location);

    expect($location->refresh()->address)->toBe('Old address')
        ->and($location->address_geocoded_lat)->toBeNull();
    $job->assertReleased(delay: 60);
});

test('a throttled request releases the job for a moment and writes nothing', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(['display_name' => 'A'])]);
    RateLimiter::hit('reverse-geocode', 1);
    $location = geocodableLocation();

    $job = runGeocodeJob($location);

    expect($location->refresh()->address)->toBeNull();
    $job->assertReleased(delay: 2);
    Http::assertNothingSent();
});

test('a job for a location that no longer needs a lookup, is stationary, is gone, or is disabled does nothing', function () {
    Http::fake();
    $resolved = geocodableLocation(['address' => 'Here', 'address_geocoded_lat' => 40.0032, 'address_geocoded_lng' => -104.9969]);
    $stationary = Location::factory()->create();
    $waiting = Location::factory()->mobile()->create();
    $disabled = geocodableLocation();
    $gone = geocodableLocation();
    $goneId = $gone->id;
    $gone->delete();

    runGeocodeJob($resolved);
    runGeocodeJob($stationary);
    runGeocodeJob($waiting);
    (new ReverseGeocodeLocation($goneId))->handle(app(ReverseGeocodeCoordinates::class));
    setIntegrationSetting('reverse_geocoding_enabled', false);
    runGeocodeJob($disabled);

    Http::assertNothingSent();
});

test('the job is unique per location and retries for half an hour', function () {
    $job = new ReverseGeocodeLocation(42);

    expect($job->uniqueId())->toBe('42')
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->retryUntil()->diffInMinutes(now(), true))->toBeGreaterThan(29);
});

test('a mobile location needs a lookup when never resolved, or after moving 100 m from the resolved position', function (array $attributes, bool $expected) {
    $location = Location::factory()->mobile()->withFix(40.0000, -105.0000)->create($attributes);

    expect($location->needsAddressLookup())->toBe($expected);
})->with([
    'never resolved' => [[], true],
    'resolved here' => [['address_geocoded_lat' => 40.0, 'address_geocoded_lng' => -105.0], false],
    'moved about 55 m' => [['address_geocoded_lat' => 40.0005, 'address_geocoded_lng' => -105.0], false],
    'moved about 111 m' => [['address_geocoded_lat' => 40.0010, 'address_geocoded_lng' => -105.0], true],
]);

test('a mobile location without a position and a stationary location never need a lookup', function () {
    expect(Location::factory()->mobile()->create()->needsAddressLookup())->toBeFalse()
        ->and(Location::factory()->create()->needsAddressLookup())->toBeFalse();
});

test('saving a location as stationary forgets where its address was resolved', function () {
    $location = geocodableLocation(['address_geocoded_lat' => 40.0, 'address_geocoded_lng' => -105.0]);

    $location->update(['type' => LocationType::Stationary]);

    expect($location->refresh()->address_geocoded_lat)->toBeNull()->and($location->address_geocoded_lng)->toBeNull();
});
