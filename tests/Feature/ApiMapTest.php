<?php

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
});

function apiMapCall($test, ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->phone->plainTextToken)->getJson('/api/v1/map');
}

test('the map needs an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/map')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    apiMapCall($this, $beacon)->assertForbidden();
});

test('with no locations the snapshot has no centre, phone, places or warnings and still carries the layers', function () {
    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['center'])->toBe(['lat' => null, 'lng' => null, 'source' => null, 'label' => null])
        ->and($data['phone'])->toBeNull()
        ->and($data['places'])->toBe([])
        ->and($data['warnings'])->toBe([])
        ->and($data['layers']['basemaps'][0])->toBe(['key' => 'topo', 'label' => 'Topo', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16])
        ->and($data['layers']['radar'])->toBe(['wms_url' => 'https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows', 'layer' => 'conus_bref_qcd'])
        ->and($data['layers']['attribution'])->toBe('USGS The National Map · NOAA/NWS MRMS')
        ->and($data['generated_at'])->not->toBeNull();
});

test('the phone is the location kept current by this token and it is the centre', function () {
    $mine = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create([
        'label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4, 'mobile_position_updated_at' => now()->subMinutes(5), 'mobile_position_fixed_at' => now()->subMinutes(5),
    ]);
    $otherPhoneToken = $this->user->createToken('iPad', ['app']);
    Location::factory()->for($this->user)->mobileApp($otherPhoneToken->accessToken)->create(['label' => 'iPad', 'lat' => 30.0, 'lng' => -90.0, 'mobile_position_updated_at' => now(), 'mobile_position_fixed_at' => now()]);
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 39.7, 'lng' => -105.0]);

    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['phone'])->toMatchArray(['lat' => 35.2, 'lng' => -97.4, 'stale' => false])
        ->and($data['center'])->toBe(['lat' => 35.2, 'lng' => -97.4, 'source' => 'phone', 'label' => 'Pixel 9'])
        ->and(collect($data['places'])->pluck('label')->all())->toBe(['Home', 'iPad', 'Pixel 9'])
        ->and(collect($data['places'])->firstWhere('id', $mine->id))->toMatchArray(['type' => 'mobile', 'stale' => false]);
});

test('without a phone fix the centre is the first place with a position, and a place without one is left out', function () {
    Location::factory()->for($this->user)->create(['label' => 'Zed', 'lat' => 40.0, 'lng' => -100.0]);
    Location::factory()->for($this->user)->create(['label' => 'Alpha', 'lat' => 41.0, 'lng' => -101.0]);
    Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'No fix', 'lat' => null, 'lng' => null]);

    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['phone'])->toBeNull()
        ->and($data['center'])->toBe(['lat' => 41, 'lng' => -101, 'source' => 'place', 'label' => 'Alpha'])
        ->and(collect($data['places'])->pluck('label')->all())->toBe(['Alpha', 'Zed']);
});

test('a stale phone and a stale mobile place are flagged; a stationary place never is', function () {
    Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4, 'mobile_position_updated_at' => now()->subHours(3), 'mobile_position_fixed_at' => now()->subHours(3)]);
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 39.7, 'lng' => -105.0]);

    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['phone']['stale'])->toBeTrue()
        ->and(collect($data['places'])->firstWhere('label', 'Home')['stale'])->toBeFalse();
});

test('another subscriber\'s locations never appear, and nothing internal leaves', function () {
    Location::factory()->create(['label' => 'Theirs', 'lat' => 33.0, 'lng' => -96.0]);
    Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4, 'mobile_position_updated_at' => now(), 'mobile_position_fixed_at' => now()]);

    $response = apiMapCall($this)->assertOk();

    expect(collect($response->json('data.places'))->pluck('label')->all())->toBe(['Pixel 9'])
        ->and($response->getContent())->not->toContain('Theirs')
        ->not->toContain('app_token_id')->not->toContain('traccar')->not->toContain('mobile_source');
});

test('warnings near the phone and the places are included, with the layers from config', function () {
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 40.0, 'lng' => -105.0]);
    $wwa = ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tornado Warning', 'family' => 'warning'])->id,
        'status' => 'Active', 'is_test' => false,
    ]);
    ActiveWwaArea::factory()->state(['active_wwa_id' => $wwa->id, 'ugc_code' => 'XXZ000', 'expires' => now()->addHour()])
        ->withPolygon('POLYGON((-105.5 39.5, -105.5 40.5, -104.5 40.5, -104.5 39.5, -105.5 39.5))')->create();

    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['warnings'])->toHaveCount(1)
        ->and($data['warnings'][0])->toMatchArray(['id' => $wwa->id, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000'])
        ->and(array_keys($data['warnings'][0]))->toBe(['id', 'event', 'family', 'color', 'headline', 'expires', 'view_url', 'geometry']);
});

test('a user with more than the anchor cap of places still gets warnings near a phone whose label sorts last', function () {
    Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create([
        'label' => 'Zzz phone', 'lat' => 35.2, 'lng' => -97.4, 'mobile_position_updated_at' => now(), 'mobile_position_fixed_at' => now(),
    ]);
    foreach (range(1, 55) as $i) {
        Location::factory()->for($this->user)->create(['label' => sprintf('Place %02d', $i), 'lat' => 45.0 + $i * 0.05, 'lng' => -70.0]);
    }
    $wwa = ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tornado Warning', 'family' => 'warning'])->id,
        'status' => 'Active', 'is_test' => false,
    ]);
    ActiveWwaArea::factory()->state(['active_wwa_id' => $wwa->id, 'ugc_code' => 'XXZ000', 'expires' => now()->addHour()])
        ->withPolygon('POLYGON((-97.9 34.7, -97.9 35.7, -96.9 35.7, -96.9 34.7, -97.9 34.7))')->create();

    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['places'])->toHaveCount(56)
        ->and(collect($data['places'])->last()['label'])->toBe('Zzz phone')
        ->and(collect($data['warnings'])->pluck('id')->all())->toBe([$wwa->id]);
});
