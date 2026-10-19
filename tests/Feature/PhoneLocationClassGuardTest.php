<?php

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Enums\Tier;
use App\Exceptions\LocationLimitReached;
use App\Filament\Dashboard\Resources\Locations\Pages\EditLocation;
use App\Models\Location;
use App\Models\User;
use App\Settings\TierThresholdSettings;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->free_phone_locations = 1;
    $limits->plus_locations = 5;
    $limits->plus_phone_locations = 2;
    $limits->save();

    $this->user = User::factory()->create(['tier_override' => Tier::Free]);
    $this->actingAs($this->user);
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
});

function sharePhone($test, string $bearer, string $method = 'PUT')
{
    app('auth')->forgetGuards();

    return $test->withToken($bearer)->json($method, '/api/v1/location-sharing');
}

test('turning a phone location into a stationary place is refused when the saved places are full, so the phone slot is not freed', function () {
    Location::factory()->for($this->user)->create();
    $phoneLocation = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9']);

    livewire(EditLocation::class, ['record' => $phoneLocation->id])
        ->fillForm(['type' => LocationType::Stationary->value, 'lat' => 35.2, 'lng' => -97.4, 'timezone' => 'America/Chicago'])
        ->call('save')
        ->assertNotified();

    expect($phoneLocation->fresh()->type)->toBe(LocationType::Mobile)
        ->and($phoneLocation->fresh()->mobile_source)->toBe(MobileSource::App);

    $second = $this->user->createToken('Galaxy', ['app']);
    sharePhone($this, $second->plainTextToken)->assertUnprocessable()->assertJsonPath('error', 'location_limit');

    expect($this->user->locations()->phoneApp()->count())->toBe(1)
        ->and($this->user->locations()->notPhoneApp()->count())->toBe(1);
});

test('a phone location may become a stationary place when the saved places have room', function () {
    $phoneLocation = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9']);

    livewire(EditLocation::class, ['record' => $phoneLocation->id])
        ->fillForm(['type' => LocationType::Stationary->value, 'lat' => 35.2, 'lng' => -97.4, 'timezone' => 'America/Chicago'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($phoneLocation->fresh()->type)->toBe(LocationType::Stationary)
        ->and($this->user->canCreateLocation())->toBeFalse();
});

test('a tampered mobile_source cannot move a stationary place into the phone class', function () {
    $place = Location::factory()->for($this->user)->create();

    livewire(EditLocation::class, ['record' => $place->id])
        ->fillForm(['type' => LocationType::Mobile->value, 'mobile_source' => MobileSource::App->value, 'mobile_update_interval_minutes' => 5])
        ->call('save');

    expect($place->fresh()->mobile_source)->not->toBe(MobileSource::App)
        ->and($this->user->locations()->phoneApp()->count())->toBe(0);
});

test('the model refuses to move a location between the classes however it is saved', function () {
    $place = Location::factory()->for($this->user)->create();

    expect(fn () => $place->update(['type' => LocationType::Mobile, 'mobile_source' => MobileSource::App]))
        ->toThrow(LocationLimitReached::class);

    Location::factory()->for($this->user)->create(['label' => 'Second']);
    $phoneLocation = Location::factory()->for($this->user)->mobileApp()->create();

    expect(fn () => $phoneLocation->update(['type' => LocationType::Stationary]))->toThrow(LocationLimitReached::class);
});

test('the API update cannot change the type or source of a location', function () {
    $place = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $phoneLocation = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9']);
    app('auth')->forgetGuards();

    $this->withToken($this->phone->plainTextToken)
        ->putJson('/api/v1/locations/'.$phoneLocation->id, ['label' => 'Renamed', 'update_interval_minutes' => 10, 'type' => 'stationary', 'lat' => 35.0, 'lng' => -97.0])
        ->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($this->phone->plainTextToken)
        ->putJson('/api/v1/locations/'.$place->id, ['label' => 'Home', 'lat' => 35.0, 'lng' => -97.0, 'type' => 'mobile', 'mobile_source' => 'app'])
        ->assertOk();

    expect($phoneLocation->fresh()->type)->toBe(LocationType::Mobile)
        ->and($phoneLocation->fresh()->mobile_source)->toBe(MobileSource::App)
        ->and($place->fresh()->type)->toBe(LocationType::Stationary)
        ->and($place->fresh()->mobile_source)->toBeNull();
});

test('a Plus user lowered to Free keeps both phones working but cannot add a third', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $second = $this->user->createToken('Galaxy', ['app']);
    sharePhone($this, $this->phone->plainTextToken)->assertOk();
    sharePhone($this, $second->plainTextToken)->assertOk();

    $this->user->forceFill(['tier_override' => Tier::Free])->save();

    sharePhone($this, $this->phone->plainTextToken)->assertOk();
    sharePhone($this, $second->plainTextToken)->assertOk();
    sharePhone($this, $this->user->createToken('Moto', ['app'])->plainTextToken)->assertUnprocessable();

    expect($this->user->locations()->phoneApp()->count())->toBe(2);
});
