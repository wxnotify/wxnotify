<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\Locations\Pages\CreateLocation;
use App\Filament\Dashboard\Resources\Locations\Pages\ListLocations;
use App\Models\Location;
use App\Models\TraccarServer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('a subscriber only sees their own locations', function () {
    $mine = Location::factory()->for($this->user)->create();
    $someoneElses = Location::factory()->for(User::factory())->create();

    livewire(ListLocations::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

test('creating a location attaches it to the authenticated user', function () {
    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'Home',
            'lat' => 39.8283,
            'lng' => -98.5795,
            'address' => 'Somewhere, KS',
            // In production, TimezoneSelect's browser-JS auto-detect fills this
            // before submit; Pest's headless Livewire test environment never
            // runs that JS, so it must be supplied explicitly here.
            'timezone' => 'America/Chicago',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $location = Location::where('label', 'Home')->sole();

    expect($location->user_id)->toBe($this->user->id);
});

test('a Free subscriber cannot create a second location', function () {
    Location::factory()->for($this->user)->create();

    expect($this->user->canCreateLocation())->toBeFalse();

    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'Second place',
            'lat' => 40.0,
            'lng' => -99.0,
        ])
        ->call('create');

    expect(Location::where('user_id', $this->user->id)->count())->toBe(1);
});

test('a Plus subscriber can create up to five locations', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    Location::factory(4)->for($this->user)->create();

    expect($this->user->canCreateLocation())->toBeTrue();

    Location::factory()->for($this->user)->create();

    expect($this->user->fresh()->canCreateLocation())->toBeFalse();
});

test('a Pro subscriber has no location limit', function () {
    $this->user->forceFill(['tier_override' => Tier::Pro])->save();
    Location::factory(20)->for($this->user)->create();

    expect($this->user->canCreateLocation())->toBeTrue();
});

test('creating a location persists an explicitly chosen timezone', function () {
    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'Home',
            'lat' => 39.8283,
            'lng' => -98.5795,
            'address' => 'Somewhere, KS',
            'timezone' => 'America/Chicago',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $location = Location::where('label', 'Home')->sole();

    expect($location->timezone)->toBe('America/Chicago');
});

test('creating a location without a timezone is rejected when coordinates resolve no state', function () {
    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'Home',
            'lat' => 0.0,
            'lng' => 0.0,
        ])
        ->call('create')
        ->assertHasFormErrors(['timezone']);
});

test('placing coordinates fills a blank timezone from the state they fall within', function () {
    DB::table('us_states')->insert([
        'state' => 'NY', 'name' => 'New York', 'fips' => '36', 'lon' => -73.5, 'lat' => 42.5,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    livewire(CreateLocation::class)
        ->fillForm(['lat' => 42.5, 'lng' => -73.5])
        ->assertFormSet(['timezone' => 'America/New_York']);
});

test('creating a location with coordinates but no explicit timezone persists the derived fallback', function () {
    DB::table('us_states')->insert([
        'state' => 'NY', 'name' => 'New York', 'fips' => '36', 'lon' => -73.5, 'lat' => 42.5,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'Home',
            'lat' => 42.5,
            'lng' => -73.5,
            'address' => 'Somewhere, NY',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $location = Location::where('label', 'Home')->sole();

    expect($location->timezone)->toBe('America/New_York');
});

test('the coordinate fallback never overwrites an explicitly chosen timezone', function () {
    DB::table('us_states')->insert([
        'state' => 'NY', 'name' => 'New York', 'fips' => '36', 'lon' => -73.5, 'lat' => 42.5,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    livewire(CreateLocation::class)
        ->fillForm(['timezone' => 'America/Chicago'])
        ->fillForm(['lat' => 42.5, 'lng' => -73.5])
        ->assertFormSet(['timezone' => 'America/Chicago']);
});

test('creating a mobile location requires a Traccar server and device', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $server = TraccarServer::factory()->for($user)->create(['base_url' => 'https://traccar.example.com']);
    $this->actingAs($user);

    // The device Select's options() calls ListTraccarDevices live -- fake
    // the HTTP layer so this test never makes a real network request
    // (this suite has no global Http::preventStrayRequests(), so an
    // unfaked call here would actually try to reach the network).
    Http::fake([
        'traccar.example.com/api/devices' => Http::response([
            ['id' => 7, 'name' => 'Truck', 'uniqueId' => 'abc'],
        ]),
    ]);

    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'My Truck',
            'type' => 'mobile',
            'mobile_source' => 'traccar',
            'traccar_server_id' => $server->id,
            'traccar_device_id' => 7,
            'traccar_device_name' => 'Truck',
            'mobile_update_interval_minutes' => 15,
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(Location::class, [
        'user_id' => $user->id,
        'type' => 'mobile',
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 7,
    ]);

    $location = Location::where('user_id', $user->id)->where('type', 'mobile')->sole();
    expect($location->lat)->toBeNull();
    expect($location->lng)->toBeNull();
});

test('a Plus subscriber is blocked from attaching a 6th device to one server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $server = TraccarServer::factory()->for($user)->create(['base_url' => 'https://traccar.example.com']);
    Location::factory()->count(5)->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    $this->actingAs($user);

    Http::fake([
        'traccar.example.com/api/devices' => Http::response([
            ['id' => 99, 'name' => 'Extra Device', 'uniqueId' => 'xyz'],
        ]),
    ]);

    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'One Too Many',
            'type' => 'mobile',
            'mobile_source' => 'traccar',
            'traccar_server_id' => $server->id,
            'traccar_device_id' => 99,
            'mobile_update_interval_minutes' => 15,
        ])
        ->call('create')
        ->assertNotified();

    expect(Location::where('traccar_server_id', $server->id)->count())->toBe(5);
});
