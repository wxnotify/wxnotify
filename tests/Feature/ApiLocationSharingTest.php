<?php

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Filament\Dashboard\Pages\SignedInDevices;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->phone = $this->user->createToken('Pixel 9', ['app']);

    // Room for several locations; the limit test lowers it.
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 10;
    $limits->save();
});

function sharingCall($test, string $method, ?string $bearer = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($bearer ?? $test->phone->plainTextToken)->json($method, '/api/v1/location-sharing');
}

test('turning sharing on creates the phone\'s location and a narrow beacon token', function () {
    $response = sharingCall($this, 'PUT')->assertOk();

    $location = Location::query()->sole();
    $beacon = PersonalAccessToken::query()->where('abilities', json_encode(['location']))->sole();

    expect($location->user_id)->toBe($this->user->id)
        ->and($location->label)->toBe('Pixel 9')
        ->and($location->type)->toBe(LocationType::Mobile)
        ->and($location->mobile_source)->toBe(MobileSource::App)
        ->and($location->mobile_update_interval_minutes)->toBe(5)
        ->and($location->app_token_id)->toBe($this->phone->accessToken->id)
        ->and($beacon->parent_token_id)->toBe($this->phone->accessToken->id)
        ->and($beacon->tokenable_id)->toBe($this->user->id)
        ->and($beacon->expires_at)->not->toBeNull();

    $response->assertJsonPath('data.location_id', $location->id)
        ->assertJsonPath('data.interval_seconds', 300)
        ->assertJsonPath('data.url', route('api.v1.location.store'));
    expect($response->json('data.beacon_token'))->toStartWith($beacon->id.'|');
});

test('turning it on again reuses the location and replaces the beacon token', function () {
    sharingCall($this, 'PUT')->assertOk();
    $first = PersonalAccessToken::query()->where('abilities', json_encode(['location']))->sole();

    sharingCall($this, 'PUT')->assertOk();

    expect(Location::count())->toBe(1)
        ->and(PersonalAccessToken::query()->where('abilities', json_encode(['location']))->count())->toBe(1)
        ->and(PersonalAccessToken::find($first->id))->toBeNull();
});

test('signing in again on the same phone re-links its unlinked location instead of duplicating it', function () {
    $old = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4]);
    $rule = AlertRule::factory()->for($this->user)->create();
    $rule->locations()->attach($old->id);

    sharingCall($this, 'PUT')->assertOk()->assertJsonPath('data.location_id', $old->id);

    // The alert rule factory makes a location of its own for another user, so count this user's.
    expect($this->user->locations()->count())->toBe(1)
        ->and($old->fresh()->app_token_id)->toBe($this->phone->accessToken->id)
        ->and((float) $old->fresh()->lat)->toBe(35.2)
        ->and($rule->locations()->count())->toBe(1);
});

test('an unlinked location with another name is left alone and a new one is created', function () {
    $other = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'iPad']);

    sharingCall($this, 'PUT')->assertOk();

    expect(Location::count())->toBe(2)->and($other->fresh()->app_token_id)->toBeNull();
});

test('another account\'s unlinked location is never re-linked', function () {
    $foreign = Location::factory()->for(User::factory())->mobileApp()->create(['label' => 'Pixel 9']);

    sharingCall($this, 'PUT')->assertOk();

    expect($foreign->fresh()->app_token_id)->toBeNull()->and(Location::count())->toBe(2);
});

test('at the plan\'s location limit a new location is refused but an existing one is still re-linked', function () {
    // One location allowed, one already used.
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();
    Location::factory()->for($this->user)->create();

    sharingCall($this, 'PUT')->assertUnprocessable()->assertJsonPath('error', 'location_limit');
    expect(PersonalAccessToken::query()->where('abilities', json_encode(['location']))->count())->toBe(0);

    $existing = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'Pixel 9']);

    sharingCall($this, 'PUT')->assertOk()->assertJsonPath('data.location_id', $existing->id);
});

test('at the plan\'s limit the one unlinked phone location is re-linked even when it was renamed', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();
    $renamed = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'Jim\'s phone', 'lat' => 35.2, 'lng' => -97.4]);
    $rule = AlertRule::factory()->for($this->user)->create();
    $rule->locations()->attach($renamed->id);

    sharingCall($this, 'PUT')->assertOk()->assertJsonPath('data.location_id', $renamed->id);

    expect($this->user->locations()->count())->toBe(1)
        ->and($renamed->fresh()->app_token_id)->toBe($this->phone->accessToken->id)
        ->and((float) $renamed->fresh()->lat)->toBe(35.2)
        ->and($rule->locations()->count())->toBe(1);
});

test('at the plan\'s limit two unlinked phone locations of other names are ambiguous and refused', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 2;
    $limits->save();
    Location::factory()->for($this->user)->mobileApp()->create(['label' => 'iPad']);
    Location::factory()->for($this->user)->mobileApp()->create(['label' => 'Old phone']);

    sharingCall($this, 'PUT')->assertUnprocessable()->assertJsonPath('error', 'location_limit');

    expect(Location::query()->whereNotNull('app_token_id')->count())->toBe(0)
        ->and(PersonalAccessToken::query()->where('abilities', json_encode(['location']))->count())->toBe(0);
});

test('below the plan\'s limit an unlinked location of another name is not taken over', function () {
    $other = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'iPad']);

    sharingCall($this, 'PUT')->assertOk();

    expect($this->user->locations()->count())->toBe(2)
        ->and($other->fresh()->app_token_id)->toBeNull();
});

test('at the plan\'s limit another account\'s unlinked phone location is never taken', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();
    Location::factory()->for($this->user)->create();
    $foreign = Location::factory()->for(User::factory())->mobileApp()->create(['label' => 'Jim\'s phone']);

    sharingCall($this, 'PUT')->assertUnprocessable()->assertJsonPath('error', 'location_limit');

    expect($foreign->fresh()->app_token_id)->toBeNull();
});

test('GET does not report a location linked by token but no longer an app location', function (?MobileSource $source) {
    Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['mobile_source' => $source]);

    sharingCall($this, 'GET')->assertOk()
        ->assertExactJson(['data' => ['linked' => false, 'location_id' => null, 'interval_seconds' => null]]);
})->with([
    'traccar' => MobileSource::Traccar,
    'none' => null,
]);

test('GET says whether the phone is linked and never returns a token', function () {
    sharingCall($this, 'GET')->assertOk()
        ->assertExactJson(['data' => ['linked' => false, 'location_id' => null, 'interval_seconds' => null]]);

    sharingCall($this, 'PUT')->assertOk();
    $location = Location::query()->sole();
    $location->update(['mobile_update_interval_minutes' => 15]);

    sharingCall($this, 'GET')->assertOk()
        ->assertExactJson(['data' => ['linked' => true, 'location_id' => $location->id, 'interval_seconds' => 900]]);
});

test('turning sharing off revokes the beacon token and unlinks the location, which keeps its rules', function () {
    sharingCall($this, 'PUT')->assertOk();
    $location = Location::query()->sole();
    $rule = AlertRule::factory()->for($this->user)->create();
    $rule->locations()->attach($location->id);

    sharingCall($this, 'DELETE')->assertNoContent();
    sharingCall($this, 'DELETE')->assertNoContent();

    expect(PersonalAccessToken::query()->where('abilities', json_encode(['location']))->count())->toBe(0)
        ->and($location->fresh()->app_token_id)->toBeNull()
        ->and(DB::table('alert_rule_location')->where('location_id', $location->id)->count())->toBe(1);
});

test('signing out removes the beacon token and unlinks the location', function () {
    sharingCall($this, 'PUT')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($this->phone->plainTextToken)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect(PersonalAccessToken::query()->where('abilities', json_encode(['location']))->count())->toBe(0)
        ->and(Location::query()->sole()->app_token_id)->toBeNull();
});

test('a beacon token cannot manage sharing and an api token is refused', function () {
    $response = sharingCall($this, 'PUT')->assertOk();
    $beacon = $response->json('data.beacon_token');
    $script = $this->user->createToken('My script', ['api'])->plainTextToken;

    foreach (['GET', 'PUT', 'DELETE'] as $method) {
        sharingCall($this, $method, $beacon)->assertForbidden();
        sharingCall($this, $method, $script)->assertForbidden();
    }
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->json('GET', '/api/v1/location-sharing')->assertUnauthorized();
});

test('the beacon token is never written to the activity log and the creation is attributed to the subscriber', function () {
    $token = sharingCall($this, 'PUT')->assertOk()->json('data.beacon_token');

    expect(Activity::query()->get()->toJson())->not->toContain($token);
    $created = Activity::query()->where('subject_type', Location::class)->where('event', 'created')->first();
    expect($created)->not->toBeNull()->and($created->causer_id)->toBe($this->user->id);
});

test('beacon tokens do not appear on the Signed-in devices page', function () {
    $beacon = PersonalAccessToken::query()->findOrFail(
        (int) explode('|', sharingCall($this, 'PUT')->assertOk()->json('data.beacon_token'))[0],
    );

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->actingAs($this->user);

    livewire(SignedInDevices::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$this->phone->accessToken])
        ->assertCanNotSeeTableRecords([$beacon])
        ->assertSee('Pixel 9')
        ->assertDontSee('Location beacon');
});

test('a refused PUT leaves the phone\'s existing beacon token in place', function () {
    sharingCall($this, 'PUT')->assertOk();
    $beacon = PersonalAccessToken::query()->where('abilities', json_encode(['location']))->sole();

    // Lose the linked location and fill the plan, so the next PUT would need a new one and is refused.
    Location::query()->delete();
    Location::factory()->for($this->user)->create();
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();

    sharingCall($this, 'PUT')->assertUnprocessable()->assertJsonPath('error', 'location_limit');

    expect(PersonalAccessToken::find($beacon->id))->not->toBeNull();
});
