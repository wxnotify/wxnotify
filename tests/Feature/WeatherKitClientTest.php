<?php

use App\Support\WeatherKit\WeatherKitClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function wkcConfigure(bool $usable = true): void
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $private);

    config(['services.weatherkit' => [
        'team_id' => 'TEAM',
        'service_id' => 'SERVICE',
        'key_id' => 'KEY123',
        'private_key' => $usable ? $private : 'not a pem',
        'private_key_path' => null,
    ]]);
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
});

test('weather requests the exact path with data sets, timezone and a bearer token', function () {
    wkcConfigure();
    Http::fake(['weatherkit.apple.com/*' => Http::response(['currentWeather' => ['temperature' => 20.5]])]);

    $result = (new WeatherKitClient)->weather(40.7128, -74.006, 'America/New_York');

    expect($result)->toBe(['currentWeather' => ['temperature' => 20.5]]);

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return parse_url($request->url(), PHP_URL_HOST) === 'weatherkit.apple.com'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/v1/weather/en/40.7128/-74.006'
            && $query['dataSets'] === 'currentWeather,forecastHourly,forecastDaily'
            && $query['timezone'] === 'America/New_York'
            && str_starts_with($request->header('Authorization')[0], 'Bearer ey');
    });
});

test('attribution requests the attribution endpoint', function () {
    wkcConfigure();
    Http::fake(['weatherkit.apple.com/*' => Http::response(['serviceName' => 'Weather'])]);

    expect((new WeatherKitClient)->attribution())->toBe(['serviceName' => 'Weather']);

    Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/attribution/en'
        && str_starts_with($request->header('Authorization')[0], 'Bearer '));
});

test('a failure returns null and logs once per window without secrets or coordinates', function (mixed $response, string $expected) {
    wkcConfigure();
    Http::fake(['weatherkit.apple.com/*' => $response]);

    $logged = [];
    Log::shouldReceive('warning')->once()->andReturnUsing(function (string $message, array $context = []) use (&$logged) {
        $logged = [$message, $context];
    });

    $client = new WeatherKitClient;

    expect($client->weather(40.7128, -74.006, 'UTC'))->toBeNull()
        ->and($client->weather(40.7128, -74.006, 'UTC'))->toBeNull()
        ->and($client->attribution())->toBeNull();

    $text = json_encode($logged);
    expect($text)->toContain($expected)
        ->not->toContain('Bearer')
        ->not->toContain('40.7128')
        ->not->toContain('-74.006')
        ->not->toContain('PRIVATE KEY')
        ->not->toContain('KEY123');
})->with([
    '401' => [fn () => Http::response('', 401), '401'],
    '429' => [fn () => Http::response('', 429), '429'],
    '500' => [fn () => Http::response('', 500), '500'],
    'connection error' => [fn () => throw new ConnectionException('cURL error: secret detail'), 'ConnectionException'],
]);

test('non-array json returns null', function () {
    wkcConfigure();
    Http::fake(['weatherkit.apple.com/*' => Http::response('"oops"', 200)]);
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    expect((new WeatherKitClient)->weather(1.0, 2.0, 'UTC'))->toBeNull();
});

test('no usable credentials means no http request', function (bool $configured, bool $usable) {
    if ($configured) {
        wkcConfigure($usable);
    } else {
        config(['services.weatherkit' => ['team_id' => null, 'service_id' => null, 'key_id' => null, 'private_key' => null, 'private_key_path' => null]]);
    }
    Http::fake();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    $client = new WeatherKitClient;

    expect($client->weather(1.0, 2.0, 'UTC'))->toBeNull()
        ->and($client->attribution())->toBeNull();

    Http::assertNothingSent();
})->with([
    'unconfigured' => [false, false],
    'garbage pem' => [true, false],
]);
