<?php

use App\Actions\Locations\ReverseGeocodeCoordinates;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    RateLimiter::clear(ReverseGeocodeCoordinates::RATE_LIMIT_KEY);
    config(['filament-pinpoint.leaflet.nominatim_url' => 'https://nominatim.example.test']);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Pixel 9', ['app'])->plainTextToken;
});

function placeCall($test, string $uri)
{
    app('auth')->forgetGuards();

    return $test->withToken($test->token)->getJson($uri);
}

function nominatimItems(int $count): array
{
    return collect(range(1, $count))->map(fn (int $i): array => [
        'display_name' => "{$i} Main Street, Norman, Oklahoma, United States",
        'lat' => '35.22'.$i,
        'lon' => '-97.43'.$i,
    ])->all();
}

test('the place endpoints need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/places/search?q=norman')->assertUnauthorized();
    $this->getJson('/api/v1/places/reverse?lat=35&lng=-97')->assertUnauthorized();
});

test('a search asks the configured Nominatim once and returns at most five places with numeric coordinates', function () {
    config(['app.name' => 'WxNotify', 'app.url' => 'https://wx.example.test']);
    Http::fake(['nominatim.example.test/*' => Http::response(nominatimItems(7))]);

    $response = placeCall($this, '/api/v1/places/search?q=Main+Street+Norman')->assertOk();

    expect($response->json('data'))->toHaveCount(5)
        ->and($response->json('data.0'))->toBe(['label' => '1 Main Street, Norman, Oklahoma, United States', 'lat' => 35.221, 'lng' => -97.431]);

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://nominatim.example.test/search?')
            && $query['q'] === 'Main Street Norman' && $query['format'] === 'jsonv2' && $query['limit'] === '5'
            && $request->header('User-Agent')[0] === 'WxNotify (+https://wx.example.test)'
            && $request->hasHeader('Authorization') === false;
    });
});

test('items without a name or a usable coordinate are skipped and an over-long name is cut to 255 characters', function () {
    Http::fake(['nominatim.example.test/*' => Http::response([
        ['display_name' => str_repeat('a', 400), 'lat' => '35.1', 'lon' => '-97.1'],
        ['lat' => '35.2', 'lon' => '-97.2'],
        ['display_name' => 'No longitude', 'lat' => '35.3'],
        'not an object',
    ])]);

    $places = placeCall($this, '/api/v1/places/search?q=anything')->assertOk()->json('data');

    expect($places)->toHaveCount(1)->and(mb_strlen($places[0]['label']))->toBe(255);
});

test('items with a non-finite or out-of-range coordinate are skipped', function () {
    Http::fake(['nominatim.example.test/*' => Http::response([
        ['display_name' => 'Infinite latitude', 'lat' => '1e999', 'lon' => '-97.1'],
        ['display_name' => 'Latitude too high', 'lat' => '91', 'lon' => '-97.1'],
        ['display_name' => 'Longitude too low', 'lat' => '35.1', 'lon' => '-180.5'],
        ['display_name' => 'Edge of the range', 'lat' => '-90', 'lon' => '180'],
    ])]);

    $places = placeCall($this, '/api/v1/places/search?q=anything')->assertOk()->json('data');

    expect($places)->toEqual([['label' => 'Edge of the range', 'lat' => -90.0, 'lng' => 180.0]]);
});

test('no match is an empty list and the same search is answered from the cache', function () {
    Http::fake(['nominatim.example.test/*' => Http::response([])]);

    expect(placeCall($this, '/api/v1/places/search?q=nowhere')->assertOk()->json('data'))->toBe([]);
    expect(placeCall($this, '/api/v1/places/search?q=NOWHERE')->assertOk()->json('data'))->toBe([]);

    Http::assertSentCount(1);
});

test('a spent rate limit answers 429 with Retry-After and sends nothing', function () {
    RateLimiter::attempt(ReverseGeocodeCoordinates::RATE_LIMIT_KEY, 1, fn () => true, 60);
    Http::fake();

    placeCall($this, '/api/v1/places/search?q=norman')->assertStatus(429)->assertJsonPath('error', 'busy')->assertHeader('Retry-After', '1');

    Http::assertNothingSent();
});

test('a failing or refused geocoder answers 502', function () {
    Http::fake(['nominatim.example.test/*' => Http::response('boom', 500)]);

    placeCall($this, '/api/v1/places/search?q=norman')->assertStatus(502)->assertJsonPath('error', 'unavailable');

    // The cloud metadata address is refused by the URL guard before anything is sent (stray requests would throw).
    config(['filament-pinpoint.leaflet.nominatim_url' => 'http://169.254.169.254']);

    placeCall($this, '/api/v1/places/search?q=other')->assertStatus(502);
    Http::assertSentCount(1);
});

test('a redirect from the endpoint is not followed and answers 502', function () {
    Http::fake([
        'nominatim.example.test/*' => Http::response('', 302, ['Location' => 'https://elsewhere.example.test/search']),
        'elsewhere.example.test/*' => Http::response(nominatimItems(1)),
    ]);

    placeCall($this, '/api/v1/places/search?q=norman')->assertStatus(502)->assertJsonPath('error', 'unavailable');

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'elsewhere'));
});

test('the search text is never logged or put in the activity log', function () {
    Log::spy();
    Http::fake(['nominatim.example.test/*' => Http::response('boom', 500)]);
    $activityBefore = Activity::count();

    placeCall($this, '/api/v1/places/search?q=Hidden+Query+Road')->assertStatus(502);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ! str_contains($message.json_encode($context), 'Hidden'));
    expect(Activity::count())->toBe($activityBefore);
});

test('a search needs at least three characters', function () {
    placeCall($this, '/api/v1/places/search')->assertUnprocessable()->assertJsonValidationErrorFor('q');
    placeCall($this, '/api/v1/places/search?q=ab')->assertUnprocessable()->assertJsonValidationErrorFor('q');
    placeCall($this, '/api/v1/places/search?q='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrorFor('q');
});

test('a reverse lookup returns the address, or null when there is none', function () {
    Http::fake(['nominatim.example.test/*' => Http::sequence()
        ->push(['display_name' => '1 Main Street, Norman, Oklahoma'])
        ->push(['error' => 'Unable to geocode'])]);

    expect(placeCall($this, '/api/v1/places/reverse?lat=35.2&lng=-97.4')->assertOk()->json('data'))->toBe(['address' => '1 Main Street, Norman, Oklahoma']);

    RateLimiter::clear(ReverseGeocodeCoordinates::RATE_LIMIT_KEY);

    expect(placeCall($this, '/api/v1/places/reverse?lat=1&lng=1')->assertOk()->json('data'))->toBe(['address' => null]);
});

test('a reverse lookup that is throttled or fails answers like a search, and bad coordinates are refused', function () {
    RateLimiter::attempt(ReverseGeocodeCoordinates::RATE_LIMIT_KEY, 1, fn () => true, 60);

    // Nothing is faked yet: a request sent while throttled would throw as a stray request.
    placeCall($this, '/api/v1/places/reverse?lat=35.2&lng=-97.4')->assertStatus(429)->assertJsonPath('error', 'busy');

    RateLimiter::clear(ReverseGeocodeCoordinates::RATE_LIMIT_KEY);
    Http::fake(['nominatim.example.test/*' => Http::response('boom', 500)]);

    placeCall($this, '/api/v1/places/reverse?lat=35.3&lng=-97.4')->assertStatus(502);
    placeCall($this, '/api/v1/places/reverse?lat=91&lng=0')->assertUnprocessable()->assertJsonValidationErrorFor('lat');
    placeCall($this, '/api/v1/places/reverse?lat=0&lng=181')->assertUnprocessable()->assertJsonValidationErrorFor('lng');
});

test('the place endpoints have their own per-token limit', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(nominatimItems(1))]);

    foreach (range(1, 20) as $i) {
        placeCall($this, '/api/v1/places/search?q=norman')->assertOk();
    }

    placeCall($this, '/api/v1/places/search?q=norman')->assertStatus(429);
});
