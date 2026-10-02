<?php

use App\Actions\Weather\BuildDailySummaryMessage;
use App\Enums\UnitSystem;
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

test('a metric subscriber gets the Brief temperatures in celsius', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response(['properties' => ['forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast']]),
        'api.weather.gov/gridpoints/*' => Http::response(['properties' => ['periods' => [
            ['name' => 'Today', 'isDaytime' => true, 'temperature' => 88, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny'],
            ['name' => 'Tonight', 'isDaytime' => false, 'temperature' => 64, 'temperatureUnit' => 'F', 'shortForecast' => 'Clear'],
        ]]]),
    ]);
    $location = Location::factory()->for(User::factory())->create();

    expect((new BuildDailySummaryMessage)($location, UnitSystem::Metric))
        ->toBe("Today: Sunny, high 31\u{b0}C. Tonight: Clear, low 18\u{b0}C.");
});

/**
 * @param  array<int, array<string, mixed>>  $periods
 */
function fakeBriefPeriods(array $periods): Location
{
    Http::fake([
        'api.weather.gov/points/*' => Http::response(['properties' => ['forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast']]),
        'api.weather.gov/gridpoints/*' => Http::response(['properties' => ['periods' => $periods]]),
    ]);

    return Location::factory()->for(User::factory())->create();
}

test('a period with no numeric temperature omits its high or low clause', function (UnitSystem $system) {
    $location = fakeBriefPeriods([
        ['name' => 'Today', 'isDaytime' => true, 'temperature' => null, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny'],
        ['name' => 'Tonight', 'isDaytime' => false, 'temperatureUnit' => 'F', 'shortForecast' => 'Clear'],
    ]);

    expect((new BuildDailySummaryMessage)($location, $system))->toBe('Today: Sunny. Tonight: Clear.');
})->with([UnitSystem::Imperial, UnitSystem::Metric]);

test('only the period missing a temperature loses its clause', function () {
    $location = fakeBriefPeriods([
        ['name' => 'Today', 'isDaytime' => true, 'temperature' => 88, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny'],
        ['name' => 'Tonight', 'isDaytime' => false, 'temperature' => null, 'temperatureUnit' => 'F', 'shortForecast' => 'Clear'],
    ]);

    expect((new BuildDailySummaryMessage)($location))->toBe("Today: Sunny, high 88\u{b0}F. Tonight: Clear.");
});

test('a Celsius-flagged period is shown correctly in either system', function () {
    $periods = [['name' => 'Today', 'isDaytime' => true, 'temperature' => 20, 'temperatureUnit' => 'C', 'shortForecast' => 'Sunny']];

    expect((new BuildDailySummaryMessage)(fakeBriefPeriods($periods)))->toBe("Today: Sunny, high 68\u{b0}F.")
        ->and((new BuildDailySummaryMessage)(fakeBriefPeriods($periods), UnitSystem::Metric))->toBe("Today: Sunny, high 20\u{b0}C.");
});
