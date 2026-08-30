<?php

use App\Actions\Weather\GetSevenDayForecast;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('it resolves the gridpoint then fetches forecast periods', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => ['forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast'],
        ]),
        'api.weather.gov/gridpoints/*' => Http::response([
            'properties' => [
                'periods' => [
                    ['name' => 'Today', 'temperature' => 75, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny'],
                ],
            ],
        ]),
    ]);

    $location = Location::factory()->for(User::factory())->create(['lat' => 39.8283, 'lng' => -98.5795]);

    $periods = (new GetSevenDayForecast)($location);

    expect($periods)->toHaveCount(1)
        ->and($periods[0]['name'])->toBe('Today');

    Http::assertSentCount(2);
});

test('it returns an empty array when the gridpoint lookup fails', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response([], 503),
    ]);

    $location = Location::factory()->for(User::factory())->create();

    $periods = (new GetSevenDayForecast)($location);

    expect($periods)->toBe([]);
});

test('it caches the forecast so repeated calls do not refetch within the hour', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => ['forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast'],
        ]),
        'api.weather.gov/gridpoints/*' => Http::response([
            'properties' => ['periods' => [['name' => 'Today']]],
        ]),
    ]);

    $location = Location::factory()->for(User::factory())->create();

    (new GetSevenDayForecast)($location);
    (new GetSevenDayForecast)($location);

    Http::assertSentCount(2);
});
