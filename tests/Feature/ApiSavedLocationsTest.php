<?php

use App\Actions\Weather\ResolveTimezoneFromCoordinates;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 5;
    $limits->save();

    $this->user = User::factory()->create(['timezone' => 'America/Chicago']);
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
});

function locationCall($test, string $method, string $uri, array $body = [], ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->phone->plainTextToken)->json($method, $uri, $body);
}

test('the location endpoints need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/locations')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    locationCall($this, 'GET', '/api/v1/locations', [], $beacon)->assertForbidden();
});

test('the list is the subscriber\'s own locations by name with the limit information, and carries nothing internal', function () {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $work = Location::factory()->for($this->user)->create(['label' => 'Work']);
    Location::factory()->create(['label' => 'Theirs']);

    $response = locationCall($this, 'GET', '/api/v1/locations')->assertOk();

    expect(collect($response->json('data'))->pluck('label')->all())->toBe(['Home', 'Work'])
        ->and($response->json('meta'))->toBe(['can_create' => true, 'limit' => 5, 'can_share_phone' => true, 'phone_limit' => 1])
        ->and(collect($response->json('data'))->firstWhere('id', $home->id))->toMatchArray(['type' => 'stationary', 'source' => null, 'linked' => null, 'stale' => null])
        ->and($response->getContent())->not->toContain('app_token_id')->not->toContain('traccar_server_id');
});

test('mobile locations say whether they are linked to a phone, whose device they follow and whether their position is stale', function () {
    $linked = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9', 'mobile_position_updated_at' => now()->subMinutes(5), 'mobile_position_fixed_at' => now()->subMinutes(5), 'lat' => 35.2, 'lng' => -97.4]);
    $lost = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'Old phone', 'mobile_position_updated_at' => now()->subHours(3), 'lat' => 35.2, 'lng' => -97.4]);
    // mobile() resolves its own user, so the owner goes in as an attribute (->for() would create a second user).
    $traccar = Location::factory()->mobile()->create(['user_id' => $this->user->id, 'label' => 'Truck', 'traccar_device_name' => 'F-150']);

    $byId = collect(locationCall($this, 'GET', '/api/v1/locations')->assertOk()->json('data'))->keyBy('id');

    expect($byId[$linked->id])->toMatchArray(['type' => 'mobile', 'source' => 'app', 'source_label' => 'WxNotify app', 'linked' => true, 'has_missing_device' => false, 'has_fix' => true, 'stale' => false])
        ->and($byId[$lost->id])->toMatchArray(['linked' => false, 'has_missing_device' => true, 'stale' => true])
        ->and($byId[$traccar->id])->toMatchArray(['source' => 'traccar', 'linked' => null, 'device_name' => 'F-150', 'has_fix' => false, 'stale' => true]);
});

test('another subscriber\'s location is a 404', function () {
    $theirs = Location::factory()->create();

    locationCall($this, 'GET', "/api/v1/locations/{$theirs->id}")->assertNotFound();
    locationCall($this, 'PUT', "/api/v1/locations/{$theirs->id}", ['label' => 'x'])->assertNotFound();
    locationCall($this, 'DELETE', "/api/v1/locations/{$theirs->id}")->assertNotFound();
});

test('a stationary location is created with the address and timezone it is given', function () {
    $response = locationCall($this, 'POST', '/api/v1/locations', [
        'label' => 'Cabin', 'lat' => 35.2226, 'lng' => -97.4395, 'address' => '1 Lake Road, Norman, Oklahoma', 'timezone' => 'America/Denver',
    ])->assertCreated();

    $location = Location::where('user_id', $this->user->id)->sole();

    expect($response->json('data.id'))->toBe($location->id)
        ->and($location->type)->toBe(LocationType::Stationary)
        ->and((float) $location->lat)->toBe(35.2226)
        ->and($location->address)->toBe('1 Lake Road, Norman, Oklahoma')
        ->and($location->timezone)->toBe('America/Denver');
});

test('without a timezone the server resolves it from the coordinates, then falls back to the profile timezone', function () {
    $this->mock(ResolveTimezoneFromCoordinates::class, fn ($mock) => $mock->shouldReceive('__invoke')->andReturn('America/New_York', null));

    locationCall($this, 'POST', '/api/v1/locations', ['label' => 'East', 'lat' => 40.7, 'lng' => -74.0])->assertCreated();
    locationCall($this, 'POST', '/api/v1/locations', ['label' => 'Offshore', 'lat' => 30.0, 'lng' => -60.0])->assertCreated();

    expect(Location::where('label', 'East')->sole()->timezone)->toBe('America/New_York')
        ->and(Location::where('label', 'Offshore')->sole()->timezone)->toBe('America/Chicago');
});

test('at the plan limit a new location is refused with location_limit and nothing is created', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();
    Location::factory()->for($this->user)->create();

    locationCall($this, 'POST', '/api/v1/locations', ['label' => 'One more', 'lat' => 35.0, 'lng' => -97.0])
        ->assertUnprocessable()->assertJsonPath('error', 'location_limit');

    expect(Location::where('user_id', $this->user->id)->count())->toBe(1);
});

test('a phone location does not use up the saved-location limit', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();
    Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create();

    locationCall($this, 'POST', '/api/v1/locations', ['label' => 'Cabin', 'lat' => 35.0, 'lng' => -97.0])->assertCreated();
    locationCall($this, 'GET', '/api/v1/locations')->assertJsonPath('meta.can_share_phone', false);
});

test('a create is refused when the details are not valid', function (array $body, string $field) {
    locationCall($this, 'POST', '/api/v1/locations', [...['label' => 'Cabin', 'lat' => 35.2, 'lng' => -97.4], ...$body])
        ->assertUnprocessable()->assertJsonValidationErrorFor($field);
})->with([
    'no name' => [['label' => ''], 'label'],
    'a name that is too long' => [['label' => str_repeat('a', 256)], 'label'],
    'a latitude out of range' => [['lat' => 91], 'lat'],
    'a longitude out of range' => [['lng' => -181], 'lng'],
    'no latitude' => [['lat' => null], 'lat'],
    'an address that is too long' => [['address' => str_repeat('a', 256)], 'address'],
    'an unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
]);

test('a stationary location can be renamed, moved and given a new timezone, and a rename alone keeps its position', function () {
    $location = Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 35.0, 'lng' => -97.0, 'address' => 'Old', 'timezone' => 'America/Chicago']);

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'Home base'])->assertOk()->assertJsonPath('data.label', 'Home base');
    expect($location->fresh())->label->toBe('Home base')->address->toBe('Old');

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'Home base', 'lat' => 36.1, 'lng' => -96.2, 'address' => 'New place', 'timezone' => 'America/Denver'])->assertOk();

    $location->refresh();
    expect((float) $location->lat)->toBe(36.1)->and($location->address)->toBe('New place')->and($location->timezone)->toBe('America/Denver');

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'Home base', 'lat' => 36.1])->assertUnprocessable()->assertJsonValidationErrorFor('lng');
});

test('a mobile location changes only its name and update interval, and an app location stays linked to its phone', function () {
    $location = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4]);

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'My phone', 'update_interval_minutes' => 10, 'lat' => 1.0, 'lng' => 1.0, 'type' => 'stationary'])->assertOk();

    $location->refresh();
    expect($location->label)->toBe('My phone')
        ->and($location->mobile_update_interval_minutes)->toBe(10)
        ->and($location->type)->toBe(LocationType::Mobile)
        ->and($location->mobile_source)->toBe(MobileSource::App)
        ->and($location->app_token_id)->toBe($this->phone->accessToken->id)
        ->and((float) $location->lat)->toBe(35.2);

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'My phone', 'update_interval_minutes' => 7])->assertUnprocessable()->assertJsonValidationErrorFor('update_interval_minutes');
});

test('a location no alert uses is deleted', function () {
    $location = Location::factory()->for($this->user)->create();

    locationCall($this, 'DELETE', "/api/v1/locations/{$location->id}")->assertNoContent();

    expect(Location::find($location->id))->toBeNull();
});

test('a location an alert still uses is refused with the alert names and survives', function () {
    $location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    AlertRule::factory()->for($this->user)->create(['location_id' => $location->id, 'name' => 'Freeze warning']);
    $weather = AlertRule::factory()->for($this->user)->create(['location_id' => null, 'name' => 'Storm watch']);
    $weather->locations()->attach($location->id);

    $response = locationCall($this, 'DELETE', "/api/v1/locations/{$location->id}")->assertStatus(409);

    expect($response->json('error'))->toBe('location_in_use')
        ->and($response->json('alerts'))->toBe(['Freeze warning', 'Storm watch'])
        ->and($response->json('message'))->toContain('Home')->toContain('Freeze warning')
        ->and(Location::find($location->id))->not->toBeNull();
});

test('a location linked to a phone is refused, an unlinked app location and a Traccar one are deleted', function () {
    $linked = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create();
    $unlinked = Location::factory()->for($this->user)->mobileApp()->create();
    $traccar = Location::factory()->mobile()->create(['user_id' => $this->user->id]);

    locationCall($this, 'DELETE', "/api/v1/locations/{$linked->id}")->assertStatus(409)->assertJsonPath('error', 'location_is_a_phone');
    locationCall($this, 'DELETE', "/api/v1/locations/{$unlinked->id}")->assertNoContent();
    locationCall($this, 'DELETE', "/api/v1/locations/{$traccar->id}")->assertNoContent();

    expect(Location::find($linked->id))->not->toBeNull()
        ->and(Location::find($unlinked->id))->toBeNull()
        ->and(Location::find($traccar->id))->toBeNull();
});

test('writes are logged with the subscriber as the causer', function () {
    $id = locationCall($this, 'POST', '/api/v1/locations', ['label' => 'Cabin', 'lat' => 35.2, 'lng' => -97.4])->json('data.id');
    locationCall($this, 'PUT', "/api/v1/locations/{$id}", ['label' => 'Cabin 2']);
    locationCall($this, 'DELETE', "/api/v1/locations/{$id}");

    $causers = Activity::query()->where('subject_type', Location::class)->pluck('causer_id')->unique()->all();

    expect($causers)->toBe([$this->user->id]);
});

test('during maintenance a write answers 503 with the maintenance message as plain text', function () {
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = true;
    $settings->maintenance_message = 'Back soon.';
    $settings->save();

    $response = locationCall($this, 'POST', '/api/v1/locations', ['label' => 'Cabin', 'lat' => 35.2, 'lng' => -97.4]);

    $response->assertStatus(503);
    expect($response->getContent())->toBe('Back soon.')->and(Location::where('user_id', $this->user->id)->count())->toBe(0);
});
