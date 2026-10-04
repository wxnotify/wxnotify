<?php

use App\Actions\Locations\ReverseGeocodeCoordinates;
use App\Support\Delivery\HeartbeatUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    RateLimiter::clear('reverse-geocode');
    config(['filament-pinpoint.leaflet.nominatim_url' => 'https://nominatim.example.test']);
});

function geocode(float $lat = 40.0032, float $lng = -104.9969): array
{
    return app(ReverseGeocodeCoordinates::class)($lat, $lng);
}

test('a Nominatim answer becomes the address, requested at building level with an identifying User-Agent', function () {
    config(['app.name' => 'WxNotify', 'app.url' => 'https://wx.example.test']);
    Http::fake(['nominatim.example.test/*' => Http::response(['display_name' => '16879, West 169th Avenue, Broomfield, Colorado, 80023, United States'])]);

    expect(geocode())->toBe(['status' => 'found', 'address' => '16879, West 169th Avenue, Broomfield, Colorado, 80023, United States']);

    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://nominatim.example.test/reverse?')
            && $query['format'] === 'jsonv2' && $query['zoom'] === '18' && (float) $query['lat'] === 40.0032 && (float) $query['lon'] === -104.9969
            && $request->header('User-Agent')[0] === 'WxNotify (+https://wx.example.test)';
    });
});

test('an address longer than the column is cut to 255 characters', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(['display_name' => str_repeat('a', 400)])]);

    expect(mb_strlen(geocode()['address']))->toBe(255);
});

test('"unable to geocode" is a definite no-address answer, and is cached', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(['error' => 'Unable to geocode'])]);

    expect(geocode())->toBe(['status' => 'none', 'address' => null])
        ->and(geocode())->toBe(['status' => 'none', 'address' => null]);
    Http::assertSentCount(1);
});

test('positions within about 11 m share one cached answer, further ones do not', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(['display_name' => 'Somewhere'])]);

    geocode(40.00320, -104.99690);
    geocode(40.00321, -104.99691);
    RateLimiter::clear('reverse-geocode');
    geocode(40.00420, -104.99690);

    Http::assertSentCount(2);
});

test('transport errors, error statuses and unreadable bodies fail, are not cached, and are logged without coordinates', function (int|string $outcome) {
    Log::spy();
    $calls = 0;
    Http::fake(['nominatim.example.test/*' => function () use (&$calls, $outcome) {
        $calls++;

        return match (true) {
            $outcome === 'timeout' => throw new ConnectionException('cURL error 28 for https://nominatim.example.test/reverse?lat=40.0032'),
            is_int($outcome) => Http::response('', $outcome),
            default => Http::response($outcome),
        };
    }]);

    expect(geocode()['status'])->toBe('failed');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => ! str_contains(json_encode([$message, $context]), '40.00'));

    RateLimiter::clear('reverse-geocode');
    geocode();

    expect($calls)->toBe(2);
})->with([
    'timeout' => 'timeout',
    '429' => 429,
    '503' => 503,
    '403' => 403,
    'html body' => '<html>nope</html>',
    'json without address or error' => '{"foo":"bar"}',
]);

test('only one request per second goes out, the next is throttled', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(['display_name' => 'A'])]);

    expect(geocode(40.0, -105.0)['status'])->toBe('found')
        ->and(geocode(41.0, -106.0))->toBe(['status' => 'throttled', 'address' => null]);
    Http::assertSentCount(1);
});

test('a cached answer is served without spending the rate limit', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(['display_name' => 'A'])]);

    geocode(40.0, -105.0);

    expect(geocode(40.0, -105.0)['status'])->toBe('found');
    Http::assertSentCount(1);
});

test('the admin-set endpoint is used, pinned, and is part of the cache key', function () {
    setIntegrationSetting('reverse_geocoding_endpoint', 'https://geo.internal.example.test:8088/');
    $sent = null;
    Http::fake(['geo.internal.example.test:8088/*' => function ($request, array $options) use (&$sent) {
        $sent = $options;

        return Http::response(['display_name' => 'Self-hosted answer']);
    }]);

    expect(geocode()['address'])->toBe('Self-hosted answer')
        ->and($sent['curl'][CURLOPT_RESOLVE])->toBe(['geo.internal.example.test:8088:93.184.216.34'])
        ->and($sent['allow_redirects'])->toBeFalse();
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://geo.internal.example.test:8088/reverse?'));
});

test('an endpoint the guard refuses is never called', function () {
    setIntegrationSetting('reverse_geocoding_endpoint', 'http://169.254.169.254');
    Http::fake();

    expect(geocode()['status'])->toBe('failed');
    Http::assertNothingSent();
});

test('an endpoint that does not resolve is never called', function () {
    app()->instance(HeartbeatUrlGuard::class, new HeartbeatUrlGuard(fn (string $host): array => []));
    Http::fake();

    expect(geocode()['status'])->toBe('failed');
    Http::assertNothingSent();
});

test('a redirect is not followed', function () {
    Http::fake([
        'nominatim.example.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest']),
        '169.254.169.254/*' => Http::response(['display_name' => 'metadata']),
    ]);

    expect(geocode()['status'])->toBe('failed');
    Http::assertSentCount(1);
});
