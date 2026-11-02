<?php

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;

    $this->place = fn (string $label, string $zone, array $overrides = []) => Location::factory()->for($this->user)->create([
        'label' => $label, 'lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => $zone, 'county_ugc' => 'COC999',
        ...$overrides,
    ]);

    $this->event = fn (string $name, string $family, string $zone, array $attributes = []) => ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => $name, 'family' => $family])->id,
        'ugc_codes' => [$zone],
        'expires' => now()->addHours(2),
        ...$attributes,
    ]);

    $this->call = function (?string $token = null) {
        app('auth')->forgetGuards();

        return $this->withToken($token ?? $this->token)->getJson('/api/v1/active-alerts');
    };
});

test('it needs an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/active-alerts')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    ($this->call)($beacon)->assertForbidden();
});

test('a subscriber with no located place gets no events and has_places false', function () {
    Location::factory()->for($this->user)->create(['label' => 'Nowhere', 'lat' => null, 'lng' => null]);

    ($this->call)()->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.has_places', false)
        ->assertJsonStructure(['meta' => ['generated_at']]);
});

test('a subscriber with places but nothing in effect gets an empty list and has_places true', function () {
    ($this->place)('Home', 'COZ040');

    ($this->call)()->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.has_places', true);
});

test('an event in effect at a place is listed with its name, family, place and end', function () {
    ($this->place)('Home', 'COZ040');
    $tornado = ($this->event)('Tornado Warning', 'warning', 'COZ040');

    $response = ($this->call)()->assertOk();

    $response->assertJsonPath('data.0.id', $tornado->id)
        ->assertJsonPath('data.0.event', 'Tornado Warning')
        ->assertJsonPath('data.0.family', 'warning')
        ->assertJsonPath('data.0.places', ['Home'])
        ->assertJsonPath('data.0.view_url', null);

    expect($response->json('data.0.expires'))->toBeString();
});

test('an event at two places is listed once with both labels, sorted', function () {
    ($this->place)('Home', 'COZ040');
    ($this->place)('Cabin', 'COZ040');
    ($this->event)('Flood Watch', 'watch', 'COZ040');

    $response = ($this->call)()->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.places'))->toBe(['Cabin', 'Home']);
});

test('each event lists the ids of the places it affects, in the order of its labels', function () {
    $home = ($this->place)('Home', 'COZ040');
    $cabin = ($this->place)('Cabin', 'COZ040');
    $farm = ($this->place)('Farm', 'COZ041');
    $flood = ($this->event)('Flood Watch', 'watch', 'COZ040');
    $wind = ($this->event)('Wind Advisory', 'advisory', 'COZ041');
    $other = User::factory()->create();
    Location::factory()->for($other)->create(['label' => 'Theirs', 'lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC999']);

    $data = collect(($this->call)()->assertOk()->json('data'))->keyBy('id');

    expect($data[$flood->id]['places'])->toBe(['Cabin', 'Home'])
        ->and($data[$flood->id]['place_ids'])->toBe([$cabin->id, $home->id])
        ->and($data[$wind->id]['places'])->toBe(['Farm'])
        ->and($data[$wind->id]['place_ids'])->toBe([$farm->id]);
});

test('the end is the latest across the places, and none when any place has none', function () {
    ($this->place)('Home', 'COZ040');
    ($this->place)('Cabin', 'COZ041');

    $later = ($this->event)('Flood Watch', 'watch', 'COZ099', ['expires' => now()->addHours(1)]);
    ActiveWwaArea::factory()->for($later)->create(['ugc_code' => 'COZ040', 'expires' => now()->addHours(1)]);
    ActiveWwaArea::factory()->for($later)->create(['ugc_code' => 'COZ041', 'expires' => now()->addHours(5)]);

    $open = ($this->event)('Wind Advisory', 'advisory', 'COZ099', ['expires' => null]);
    ActiveWwaArea::factory()->for($open)->create(['ugc_code' => 'COZ040', 'expires' => now()->addHours(1)]);
    ActiveWwaArea::factory()->for($open)->create(['ugc_code' => 'COZ041', 'expires' => null]);

    $rows = collect(($this->call)()->assertOk()->json('data'))->keyBy('event');

    expect(strtotime($rows['Flood Watch']['expires']))->toBe(now()->addHours(5)->getTimestamp())
        ->and($rows['Flood Watch']['places'])->toBe(['Cabin', 'Home'])
        ->and($rows['Wind Advisory']['expires'])->toBeNull();
});

test('only warnings, watches and advisories are listed, and never a test product, an expired or a cancelled event', function () {
    ($this->place)('Home', 'COZ040');
    ($this->event)('Tornado Warning', 'warning', 'COZ040');
    ($this->event)('Severe Weather Statement', 'statement', 'COZ040');
    ($this->event)('SPC Outlook', 'spc_wpc', 'COZ040');
    ($this->event)('Test Warning', 'warning', 'COZ040', ['is_test' => true]);
    ($this->event)('Expired Warning', 'warning', 'COZ040', ['expires' => now()->subMinute()]);
    ($this->event)('Cancelled Warning', 'warning', 'COZ040', ['status' => 'Cancelled']);
    ($this->event)('Other Place Warning', 'warning', 'COZ777');

    expect(collect(($this->call)()->assertOk()->json('data'))->pluck('event')->all())->toBe(['Tornado Warning']);
});

test('events are ordered warning, watch, advisory, then by soonest end, then id', function () {
    ($this->place)('Home', 'COZ040');
    ($this->event)('Wind Advisory', 'advisory', 'COZ040', ['expires' => now()->addHour()]);
    ($this->event)('Flood Watch', 'watch', 'COZ040', ['expires' => now()->addHours(3)]);
    ($this->event)('Flood Warning', 'warning', 'COZ040', ['expires' => now()->addHours(4)]);
    ($this->event)('Tornado Warning', 'warning', 'COZ040', ['expires' => now()->addHours(1)]);
    ($this->event)('Open Warning', 'warning', 'COZ040', ['expires' => null]);

    expect(collect(($this->call)()->assertOk()->json('data'))->pluck('event')->all())
        ->toBe(['Tornado Warning', 'Flood Warning', 'Open Warning', 'Flood Watch', 'Wind Advisory']);
});

test('only the first 25 located places are looked at, and another user\'s places never count', function () {
    foreach (range(1, 25) as $n) {
        ($this->place)("Place {$n}", 'COZ040');
    }
    ($this->place)('Place 26', 'COZ555');
    ($this->event)('Far Warning', 'warning', 'COZ555');

    $other = User::factory()->create();
    Location::factory()->for($other)->create(['label' => 'Theirs', 'lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ888', 'county_ugc' => 'COC999']);
    ($this->event)('Their Warning', 'warning', 'COZ888');

    expect(($this->call)()->assertOk()->json('data'))->toBe([]);
});

test('the calling device\'s location is looked at even past 25 other places, with a cache entry per token', function () {
    foreach (range(1, 25) as $n) {
        ($this->place)("Place {$n}", 'COZ040');
    }
    $tokenB = $this->user->createToken('iPad', ['app']);
    $tokenA = $this->user->tokens()->where('name', 'iPhone')->first();
    $phoneB = Location::factory()->for($this->user)->mobileApp($tokenB->accessToken)->withFix(40.0, -105.0)->create(['label' => 'Phone B', 'forecast_zone_ugc' => 'COZ555', 'county_ugc' => 'COC999']);
    $phoneA = Location::factory()->for($this->user)->mobileApp($tokenA)->withFix(40.0, -105.0)->create(['label' => 'Phone A', 'forecast_zone_ugc' => 'COZ556', 'county_ugc' => 'COC999']);
    ($this->event)('B Warning', 'warning', 'COZ555');
    ($this->event)('A Warning', 'warning', 'COZ556');

    $asB = ($this->call)($tokenB->plainTextToken)->assertOk();
    $asA = ($this->call)()->assertOk();

    expect(collect($asB->json('data'))->pluck('event')->all())->toContain('B Warning', 'A Warning')
        ->and(collect($asA->json('data'))->pluck('event')->all())->toContain('B Warning', 'A Warning')
        ->and(collect($asB->json('data'))->firstWhere('event', 'B Warning')['place_ids'])->toBe([$phoneB->id])
        ->and(collect($asA->json('data'))->firstWhere('event', 'A Warning')['place_ids'])->toBe([$phoneA->id])
        ->and(Cache::has("app-active-alerts:v3:{$this->user->id}:{$tokenB->accessToken->id}"))->toBeTrue()
        ->and(Cache::has("app-active-alerts:v3:{$this->user->id}:{$tokenA->id}"))->toBeTrue();
});

test('view_url is the latest bulletin page, and the payload carries nothing but the documented fields', function () {
    ($this->place)('Home', 'COZ040');
    $tornado = ($this->event)('Tornado Warning', 'warning', 'COZ040', ['headline' => 'SECRET-HEADLINE']);

    $bulletin = IngestedProduct::factory()->createQuietly(['view_slug' => 'kbou-tor-202610121500', 'text' => 'SECRET-BULLETIN-BODY']);
    $tornado->forceFill(['latest_ingested_product_id' => $bulletin->id])->save();

    $response = ($this->call)()->assertOk();

    expect($response->json('data.0.view_url'))->toBe($bulletin->fresh()->viewUrl())->not->toBeNull()
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'event', 'family', 'places', 'place_ids', 'expires', 'view_url'])
        ->and($response->getContent())->not->toContain('SECRET-HEADLINE')->not->toContain('SECRET-BULLETIN-BODY')->not->toContain('COZ040');
});

test('view_url is the bulletin covering the caller\'s own zone, not the event\'s latest bulletin for other zones', function () {
    ($this->place)('Home', 'COZ040');
    $advisory = ($this->event)('Wind Advisory', 'advisory', 'COZ040');
    $ours = IngestedProduct::factory()->createQuietly(['view_slug' => 'kbou-wi-ours']);
    $elsewhere = IngestedProduct::factory()->createQuietly(['view_slug' => 'kbou-wi-cancelled-elsewhere']);
    $oursSegment = IngestedProductSegment::factory()->create(['ingested_product_id' => $ours->id]);
    $elsewhereSegment = IngestedProductSegment::factory()->create(['ingested_product_id' => $elsewhere->id]);
    ActiveWwaArea::factory()->create(['active_wwa_id' => $advisory->id, 'ugc_code' => 'COZ040', 'latest_segment_id' => $oursSegment->id]);
    ActiveWwaArea::factory()->create(['active_wwa_id' => $advisory->id, 'ugc_code' => 'COZ888', 'latest_segment_id' => $elsewhereSegment->id]);
    $advisory->forceFill(['latest_ingested_product_id' => $elsewhere->id])->save();

    expect(($this->call)()->assertOk()->json('data.0.view_url'))->toBe($ours->fresh()->viewUrl());
});

test('view_url falls back to the event\'s latest bulletin when the covering zone rows name no bulletin', function () {
    ($this->place)('Home', 'COZ040');
    $advisory = ($this->event)('Wind Advisory', 'advisory', 'COZ040');
    $latest = IngestedProduct::factory()->createQuietly(['view_slug' => 'kbou-wi-latest']);
    ActiveWwaArea::factory()->create(['active_wwa_id' => $advisory->id, 'ugc_code' => 'COZ040', 'latest_segment_id' => null]);
    $advisory->forceFill(['latest_ingested_product_id' => $latest->id])->save();

    expect(($this->call)()->assertOk()->json('data.0.view_url'))->toBe($latest->fresh()->viewUrl());
});

test('with several places covered by different bulletins, view_url is the newest of them', function () {
    ($this->place)('Home', 'COZ040');
    ($this->place)('Cabin', 'COZ041');
    $advisory = ($this->event)('Wind Advisory', 'advisory', 'COZ040');
    $older = IngestedProduct::factory()->createQuietly(['view_slug' => 'kbou-wi-older']);
    $newer = IngestedProduct::factory()->createQuietly(['view_slug' => 'kbou-wi-newer']);
    $olderSegment = IngestedProductSegment::factory()->create(['ingested_product_id' => $older->id]);
    $newerSegment = IngestedProductSegment::factory()->create(['ingested_product_id' => $newer->id]);
    ActiveWwaArea::factory()->create(['active_wwa_id' => $advisory->id, 'ugc_code' => 'COZ040', 'latest_segment_id' => $olderSegment->id]);
    ActiveWwaArea::factory()->create(['active_wwa_id' => $advisory->id, 'ugc_code' => 'COZ041', 'latest_segment_id' => $newerSegment->id]);

    expect(($this->call)()->assertOk()->json('data.0.view_url'))->toBe($newer->fresh()->viewUrl());
});

test('the result is cached for 60 seconds per subscriber', function () {
    ($this->place)('Home', 'COZ040');
    ($this->event)('Tornado Warning', 'warning', 'COZ040');

    expect(($this->call)()->assertOk()->json('data'))->toHaveCount(1);

    ($this->event)('Flood Warning', 'warning', 'COZ040');

    expect(($this->call)()->assertOk()->json('data'))->toHaveCount(1);

    $this->travel(61)->seconds();

    expect(($this->call)()->assertOk()->json('data'))->toHaveCount(2);

    $other = User::factory()->create();
    expect(Cache::has("app-active-alerts:v3:{$other->id}:none"))->toBeFalse();
});

test('a place with a fire weather zone sees a Red Flag Warning issued for that fire zone', function () {
    ($this->place)('Ranch', 'COZ040', ['fire_weather_zone_ugc' => 'COZ214']);
    $redFlag = ($this->event)('Red Flag Warning', 'warning', 'COZ999');
    ActiveWwaArea::factory()->for($redFlag)->create(['ugc_code' => 'COZ214', 'expires' => now()->addHours(2)]);

    ($this->call)()->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event', 'Red Flag Warning')
        ->assertJsonPath('data.0.places', ['Ranch']);
});
