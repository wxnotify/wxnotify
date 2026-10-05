<?php

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->appToken = $this->user->createToken('Pixel 9', ['app'])->accessToken;
});

test('the app is a mobile source', function () {
    expect(MobileSource::from('app'))->toBe(MobileSource::App)
        ->and(MobileSource::App->label())->toBe('WxNotify app');
});

test('an app location is linked to its phone and has no Traccar fields', function () {
    $location = Location::factory()->for($this->user)->mobileApp($this->appToken)->create();

    expect($location->mobile_source)->toBe(MobileSource::App)
        ->and($location->type)->toBe(LocationType::Mobile)
        ->and($location->appToken->is($this->appToken))->toBeTrue()
        ->and($location->traccar_server_id)->toBeNull()
        ->and($location->mobile_update_interval_minutes)->toBe(5);
});

test('removing the phone unlinks its location but never deletes it or its rule links', function () {
    $location = Location::factory()->for($this->user)->mobileApp($this->appToken)->create();
    $rule = AlertRule::factory()->for($this->user)->create();
    $rule->locations()->attach($location->id);

    $this->appToken->delete();

    $location->refresh();
    expect($location->app_token_id)->toBeNull()
        ->and(Location::find($location->id))->not->toBeNull()
        ->and(DB::table('alert_rule_location')->where('location_id', $location->id)->count())->toBe(1);
});

test('one phone can be linked to one location only', function () {
    Location::factory()->for($this->user)->mobileApp($this->appToken)->create();

    Location::factory()->for($this->user)->mobileApp($this->appToken)->create();
})->throws(UniqueConstraintViolationException::class);

test('a location made stationary drops its phone link', function () {
    $location = Location::factory()->for($this->user)->mobileApp($this->appToken)->create();

    $location->update(['type' => LocationType::Stationary, 'lat' => 35.1, 'lng' => -97.1, 'address' => '1 Main St']);

    expect($location->fresh())
        ->app_token_id->toBeNull()
        ->mobile_source->toBeNull()
        ->mobile_update_interval_minutes->toBeNull();
});

test('an app location counts as missing its device exactly while no phone is linked', function () {
    $linked = Location::factory()->for($this->user)->mobileApp($this->appToken)->create();
    $unlinked = Location::factory()->for($this->user)->mobileApp()->create();
    $traccar = Location::factory()->for($this->user)->mobile()->create();
    $traccarGone = Location::factory()->for($this->user)->mobile()->create(['traccar_device_missing_at' => now()]);

    expect($linked->hasMissingDevice())->toBeFalse()
        ->and($unlinked->hasMissingDevice())->toBeTrue()
        ->and($traccar->hasMissingDevice())->toBeFalse()
        ->and($traccarGone->hasMissingDevice())->toBeTrue();
});

test('a beacon token is removed with the app token that minted it', function () {
    $beacon = $this->user->createToken('Location beacon - Pixel 9', ['location'])->accessToken;
    $beacon->forceFill(['parent_token_id' => $this->appToken->id])->save();
    $other = $this->user->createToken('Location beacon - iPad', ['location'])->accessToken;

    $this->appToken->delete();

    expect(DB::table('personal_access_tokens')->where('id', $beacon->id)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('id', $other->id)->exists())->toBeTrue();
});
