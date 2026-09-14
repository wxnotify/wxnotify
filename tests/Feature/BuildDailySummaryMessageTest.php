<?php

use App\Actions\Weather\BuildDailySummaryMessage;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('it composes a day and night summary from the fetched periods', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => ['forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast'],
        ]),
        'api.weather.gov/gridpoints/*' => Http::response([
            'properties' => [
                'periods' => [
                    ['name' => 'Today', 'isDaytime' => true, 'temperature' => 88, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny'],
                    ['name' => 'Tonight', 'isDaytime' => false, 'temperature' => 64, 'temperatureUnit' => 'F', 'shortForecast' => 'Clear'],
                ],
            ],
        ]),
    ]);

    $location = Location::factory()->for(User::factory())->create();

    $message = (new BuildDailySummaryMessage)($location);

    expect($message)->toBe("Today: Sunny, high 88\u{b0}F. Tonight: Clear, low 64\u{b0}F.");
});

test('it returns null when the forecast cannot be fetched', function () {
    Http::fake(['api.weather.gov/points/*' => Http::response([], 503)]);

    $location = Location::factory()->for(User::factory())->create();

    expect((new BuildDailySummaryMessage)($location))->toBeNull();
});
