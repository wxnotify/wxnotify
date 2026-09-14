<?php

use App\Actions\Weather\GetGridpointForecastData;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeGridpointResponses(): void
{
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast',
                'forecastGridData' => 'https://api.weather.gov/gridpoints/TOP/1,1',
            ],
        ]),
        'api.weather.gov/gridpoints/TOP/1,1/forecast' => Http::response([
            'properties' => [
                'periods' => [
                    ['name' => 'Today', 'isDaytime' => true, 'startTime' => '2026-09-14T06:00:00-05:00', 'endTime' => '2026-09-14T18:00:00-05:00', 'temperature' => 90, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny'],
                    ['name' => 'Tonight', 'isDaytime' => false, 'startTime' => '2026-09-14T18:00:00-05:00', 'endTime' => '2026-09-15T06:00:00-05:00', 'temperature' => 65, 'temperatureUnit' => 'F', 'shortForecast' => 'Clear'],
                ],
            ],
        ]),
        'api.weather.gov/gridpoints/TOP/1,1' => Http::response([
            'properties' => [
                'temperature' => ['values' => [
                    ['validTime' => '2026-09-14T11:00:00+00:00/PT1H', 'value' => 20.0],
                    ['validTime' => '2026-09-14T20:00:00+00:00/PT1H', 'value' => 32.22222222222222],
                ]],
                'apparentTemperature' => ['values' => [
                    ['validTime' => '2026-09-14T20:00:00+00:00/PT1H', 'value' => 33.0],
                ]],
                'windSpeed' => ['values' => [
                    ['validTime' => '2026-09-14T20:00:00+00:00/PT1H', 'value' => 16.0934],
                ]],
                'windGust' => ['values' => [
                    ['validTime' => '2026-09-14T20:00:00+00:00/PT1H', 'value' => 32.1868],
                ]],
                'dewpoint' => ['values' => [
                    ['validTime' => '2026-09-14T20:00:00+00:00/PT1H', 'value' => 15.0],
                ]],
                'relativeHumidity' => ['values' => [
                    ['validTime' => '2026-09-14T20:00:00+00:00/PT1H', 'value' => 55],
                ]],
                'probabilityOfPrecipitation' => ['values' => [
                    ['validTime' => '2026-09-14T20:00:00+00:00/PT2H', 'value' => 10],
                    ['validTime' => '2026-09-14T22:00:00+00:00/PT2H', 'value' => null],
                ]],
            ],
        ]),
    ]);
}

test('it fetches and converts gridpoint series into Fahrenheit/mph, plus today\'s periods', function () {
    fakeGridpointResponses();

    $location = Location::factory()->for(User::factory())->create(['lat' => 39.0, 'lng' => -95.0]);

    $data = (new GetGridpointForecastData)($location);

    expect($data)->not->toBeNull()
        ->and($data['periods'])->toHaveCount(2)
        ->and($data['series']['temperature'][1]['value'])->toBe(90.0)
        ->and($data['series']['feels_like'][0]['value'])->toBeGreaterThan(91.0)->toBeLessThan(92.0)
        ->and($data['series']['wind_speed'][0]['value'])->toBe(10.0)
        ->and($data['series']['gusts'][0]['value'])->toBe(20.0)
        ->and($data['series']['humidity'][0]['value'])->toBe(55.0)
        ->and($data['series']['precip_chance'])->toHaveCount(1); // the null-value entry is dropped
});

test('it returns null when the points lookup fails', function () {
    Http::fake(['api.weather.gov/points/*' => Http::response([], 503)]);

    $location = Location::factory()->for(User::factory())->create();

    expect((new GetGridpointForecastData)($location))->toBeNull();
});

test('it returns null when the gridpoint data fetch fails', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast',
                'forecastGridData' => 'https://api.weather.gov/gridpoints/TOP/1,1',
            ],
        ]),
        'api.weather.gov/gridpoints/TOP/1,1' => Http::response([], 503),
        'api.weather.gov/gridpoints/TOP/1,1/forecast' => Http::response(['properties' => ['periods' => []]]),
    ]);

    $location = Location::factory()->for(User::factory())->create();

    expect((new GetGridpointForecastData)($location))->toBeNull();
});
