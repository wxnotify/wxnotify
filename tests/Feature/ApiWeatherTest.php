<?php

use App\Enums\UnitSystem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function apwWeatherBody(float $celsius = 12.6): array
{
    return [
        'currentWeather' => [
            'asOf' => '2026-11-01T15:18:00Z',
            'conditionCode' => 'MostlyCloudy',
            'daylight' => true,
            'humidity' => 0.74,
            'temperature' => $celsius,
            'temperatureApparent' => 11.1,
            'uvIndex' => 3,
            'windDirection' => 240,
            'windSpeed' => 14.8,
        ],
        'forecastHourly' => ['hours' => []],
        'forecastDaily' => ['days' => []],
    ];
}

function apwFakeWeatherKit(int $status = 200): void
{
    Http::fake([
        'weatherkit.apple.com/api/v1/weather/*' => Http::response($status === 200 ? apwWeatherBody() : [], $status),
        'weatherkit.apple.com/attribution/*' => Http::response([
            'serviceName' => 'Weather',
            'logoLight@2x' => '/assets/light@2x.png',
            'logoDark@2x' => '/assets/dark@2x.png',
        ]),
    ]);
}

function apwWeatherCalls(): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), '/api/v1/weather/'))->count();
}

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow('2026-11-01 15:20:00');

    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $private);
    config(['services.weatherkit' => [
        'team_id' => 'TEAM', 'service_id' => 'SERVICE', 'key_id' => 'KEY123',
        'private_key' => $private, 'private_key_path' => null,
    ]]);

    $this->user = User::factory()->create(['unit_system' => UnitSystem::Imperial]);
    $this->token = $this->user->createToken('iPhone', ['app']);

    $this->place = fn (string $label, float $lat, float $lng, ?User $owner = null) => Location::factory()
        ->for($owner ?? $this->user)->create(['label' => $label, 'lat' => $lat, 'lng' => $lng]);

    $this->call = function (string $query = '', ?string $token = null) {
        app('auth')->forgetGuards();

        return $this->withToken($token ?? $this->token->plainTextToken)->getJson('/api/v1/weather'.$query);
    };
});

test('it needs an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/weather')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    ($this->call)('', $beacon)->assertForbidden();
});

test('a subscriber with no located place gets no card and no WeatherKit request', function () {
    Http::fake();
    Location::factory()->for($this->user)->create(['label' => 'Nowhere', 'lat' => null, 'lng' => null]);

    ($this->call)()->assertOk()
        ->assertJsonPath('data.places', [])
        ->assertJsonPath('data.selected', null)
        ->assertJsonPath('data.attribution', null)
        ->assertJsonPath('meta.has_places', false)
        ->assertJsonStructure(['meta' => ['generated_at']]);

    Http::assertNothingSent();
});

test('the phone is listed first and is the default card', function () {
    apwFakeWeatherKit();
    $home = ($this->place)('Home', 40.7128, -74.006);
    $phone = Location::factory()->for($this->user)->mobileApp($this->token->accessToken)->withFix(34.05, -118.24)->create(['label' => 'My phone']);

    $response = ($this->call)()->assertOk();

    $response->assertJsonPath('data.places', [
        ['id' => $phone->id, 'label' => 'My phone', 'is_phone' => true],
        ['id' => $home->id, 'label' => 'Home', 'is_phone' => false],
    ])
        ->assertJsonPath('data.selected.id', $phone->id)
        ->assertJsonPath('data.selected.available', true)
        ->assertJsonPath('data.attribution.name', 'Weather')
        ->assertJsonPath('meta.has_places', true);

    expect(apwWeatherCalls())->toBe(1);
});

test('the calling device\'s own location is listed first, whichever device that is', function () {
    apwFakeWeatherKit();
    $tokenB = $this->user->createToken('iPad', ['app']);
    $home = ($this->place)('Home', 40.7128, -74.006);
    $phoneA = Location::factory()->for($this->user)->mobileApp($this->token->accessToken)->withFix(34.05, -118.24)->create(['label' => 'Phone A']);
    $phoneB = Location::factory()->for($this->user)->mobileApp($tokenB->accessToken)->withFix(35.0, -119.0)->create(['label' => 'Phone B']);

    $asB = ($this->call)('', $tokenB->plainTextToken)->assertOk();
    expect(collect($asB->json('data.places'))->pluck('id')->all())->toBe([$phoneB->id, $phoneA->id, $home->id]);
    $asB->assertJsonPath('data.selected.id', $phoneB->id)->assertJsonPath('data.places.0.is_phone', true)->assertJsonPath('data.places.1.is_phone', true);

    $asA = ($this->call)()->assertOk();
    expect(collect($asA->json('data.places'))->pluck('id')->all())->toBe([$phoneA->id, $phoneB->id, $home->id]);
});

test('a token with no linked location gets the phones first by id, then the rest', function () {
    apwFakeWeatherKit();
    $tokenB = $this->user->createToken('iPad', ['app']);
    $home = ($this->place)('Home', 40.7128, -74.006);
    $phoneA = Location::factory()->for($this->user)->mobileApp($this->token->accessToken)->withFix(34.05, -118.24)->create(['label' => 'Phone A']);

    $response = ($this->call)('', $tokenB->plainTextToken)->assertOk();

    expect(collect($response->json('data.places'))->pluck('id')->all())->toBe([$phoneA->id, $home->id]);
});

test('another subscriber\'s location never appears, even with a matching token id', function () {
    apwFakeWeatherKit();
    $other = User::factory()->create();
    $otherToken = $other->createToken('iPhone', ['app']);
    $theirs = Location::factory()->for($other)->mobileApp($otherToken->accessToken)->withFix(30.0, -90.0)->create(['label' => 'Theirs']);
    $home = ($this->place)('Home', 40.7128, -74.006);

    $ids = collect(($this->call)()->assertOk()->json('data.places'))->pluck('id')->all();

    expect($ids)->toBe([$home->id])->not->toContain($theirs->id);
});

test('the location parameter selects another place with exactly one WeatherKit weather call', function () {
    apwFakeWeatherKit();
    ($this->place)('Home', 40.7128, -74.006);
    $cabin = ($this->place)('Cabin', 39.0, -105.0);

    ($this->call)('?location='.$cabin->id)->assertOk()
        ->assertJsonPath('data.selected.id', $cabin->id)
        ->assertJsonPath('data.selected.label', 'Cabin');

    expect(apwWeatherCalls())->toBe(1);
});

test('an unusable location parameter is refused', function (string $kind) {
    Http::fake();
    ($this->place)('Home', 40.7128, -74.006);
    $someoneElse = ($this->place)('Theirs', 41.0, -74.0, User::factory()->create());
    $unlocated = Location::factory()->for($this->user)->create(['label' => 'Nowhere', 'lat' => null, 'lng' => null]);

    $query = match ($kind) {
        'another user' => '?location='.$someoneElse->id,
        'no coordinates' => '?location='.$unlocated->id,
        'not an integer' => '?location=abc',
    };

    ($this->call)($query)->assertStatus($kind === 'not an integer' ? 422 : 404);

    Http::assertNothingSent();
})->with(['another user', 'no coordinates', 'not an integer']);

test('a WeatherKit failure still answers 200 with an unavailable card and no attribution', function () {
    apwFakeWeatherKit(500);
    $home = ($this->place)('Home', 40.7128, -74.006);

    ($this->call)()->assertOk()
        ->assertJsonPath('data.selected', ['id' => $home->id, 'label' => 'Home', 'available' => false])
        ->assertJsonPath('data.attribution', null)
        ->assertJsonPath('data.places.0.label', 'Home');
});

test('an attribution failure still credits Apple Weather next to an available card', function () {
    Http::fake([
        'weatherkit.apple.com/api/v1/weather/*' => Http::response(apwWeatherBody()),
        'weatherkit.apple.com/attribution/*' => Http::response('', 500),
    ]);
    ($this->place)('Home', 40.7128, -74.006);

    ($this->call)()->assertOk()
        ->assertJsonPath('data.selected.available', true)
        ->assertJsonPath('data.attribution', [
            'name' => 'Apple Weather',
            'logo_light_url' => null,
            'logo_dark_url' => null,
            'legal_url' => 'https://developer.apple.com/weatherkit/data-source-attribution/',
        ]);
});

test('metric and imperial subscribers get their own strings from one cached lookup', function () {
    apwFakeWeatherKit();
    ($this->place)('Home', 40.7128, -74.006);

    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    ($this->place)('Home', 40.7128, -74.006, $metric);
    $metricToken = $metric->createToken('iPhone', ['app'])->plainTextToken;

    $imperial = ($this->call)()->assertOk()->json('data.selected.current.temperature');
    $other = ($this->call)('', $metricToken)->assertOk()->json('data.selected.current.temperature');

    expect($imperial)->toBe('55°')->and($other)->toBe('13°');
    expect(apwWeatherCalls())->toBe(1);
});
