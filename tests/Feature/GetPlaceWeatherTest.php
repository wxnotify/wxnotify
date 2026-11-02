<?php

use App\Actions\Weather\GetPlaceWeather;
use App\Actions\Weather\GetWeatherAttribution;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function gpwConfigure(): void
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $private);

    config(['services.weatherkit' => [
        'team_id' => 'TEAM',
        'service_id' => 'SERVICE',
        'key_id' => 'KEY123',
        'private_key' => $private,
        'private_key_path' => null,
    ]]);
}

/**
 * A WeatherKit weather response in Apple's documented shape (CurrentWeatherData,
 * HourlyForecastData.hours[] of HourWeatherConditions, DailyForecastData.days[] of
 * DayWeatherConditions), for New York on 2026-11-01 (the day DST ends) at 15:20 UTC.
 *
 * @return array<string, mixed>
 */
function gpwFixture(): array
{
    $hours = [];
    foreach (range(13, 22) as $index => $hour) {
        $hours[] = [
            'forecastStart' => sprintf('2026-11-01T%02d:00:00Z', $hour),
            'cloudCover' => 0.5,
            'conditionCode' => $index % 2 === 0 ? 'MostlyCloudy' : 'ScatteredThunderstorms',
            'daylight' => $hour < 20,
            'humidity' => 0.6,
            'precipitationAmount' => 0,
            'precipitationChance' => 0.1 * $index,
            'precipitationType' => 'clear',
            'pressure' => 1015.2,
            'pressureTrend' => 'steady',
            'temperature' => 10.0 + $index,
            'temperatureApparent' => 9.0 + $index,
            'temperatureDewPoint' => 4.1,
            'uvIndex' => 2,
            'visibility' => 20000,
            'windDirection' => 250,
            'windGust' => 30.1,
            'windSpeed' => 15.2,
        ];
    }

    $days = [];
    foreach (range(0, 6) as $offset) {
        $start = Carbon::parse('2026-10-31 00:00', 'America/New_York')->addDays($offset);
        $days[] = [
            'forecastStart' => $start->copy()->utc()->toIso8601ZuluString(),
            'forecastEnd' => $start->copy()->addDay()->utc()->toIso8601ZuluString(),
            'conditionCode' => $offset === 1 ? 'MostlyCloudy' : 'Clear',
            'maxUvIndex' => 3,
            'moonPhase' => 'waningCrescent',
            'precipitationAmount' => 0,
            'precipitationChance' => 0.3,
            'precipitationType' => 'clear',
            'snowfallAmount' => 0,
            'sunrise' => $start->copy()->setTime(6, 28)->utc()->toIso8601ZuluString(),
            'sunset' => $start->copy()->setTime(16, 55)->utc()->toIso8601ZuluString(),
            'temperatureMax' => round(18.4 + $offset, 1),
            'temperatureMin' => round(7.2 + $offset, 1),
        ];
    }

    return [
        'currentWeather' => [
            'name' => 'CurrentWeather',
            'metadata' => ['attributionURL' => 'https://developer.apple.com/weatherkit/data-source-attribution/', 'latitude' => 40.71, 'longitude' => -74.01, 'units' => 'm', 'version' => 1],
            'asOf' => '2026-11-01T15:18:00Z',
            'cloudCover' => 0.7,
            'conditionCode' => 'MostlyCloudy',
            'daylight' => true,
            'humidity' => 0.74,
            'precipitationIntensity' => 0,
            'pressure' => 1016.4,
            'pressureTrend' => 'falling',
            'temperature' => 12.6,
            'temperatureApparent' => 11.1,
            'temperatureDewPoint' => 8.0,
            'uvIndex' => 3,
            'visibility' => 24000,
            'windDirection' => 240,
            'windGust' => 33.5,
            'windSpeed' => 14.8,
        ],
        'forecastHourly' => ['name' => 'HourlyForecast', 'hours' => $hours],
        'forecastDaily' => ['name' => 'DailyForecast', 'days' => $days],
    ];
}

function gpwLocation(array $attributes = []): Location
{
    return Location::factory()->create(array_merge([
        'lat' => 40.7128,
        'lng' => -74.0060,
        'timezone' => 'America/New_York',
    ], $attributes));
}

function gpwWeatherRequests(): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), '/weather/'))->count();
}

beforeEach(function () {
    Cache::flush();
    gpwConfigure();
    Carbon::setTestNow('2026-11-01T15:20:00Z');
    Log::shouldReceive('warning')->zeroOrMoreTimes();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('normalises current conditions with unit conversions', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);

    $weather = app(GetPlaceWeather::class)(gpwLocation());

    expect($weather['timezone'])->toBe('America/New_York')
        ->and($weather['as_of'])->toBe('2026-11-01T10:18:00-05:00')
        ->and($weather['current'])->toBe([
            'temperature_c' => 12.6,
            'feels_like_c' => 11.1,
            'condition' => 'Mostly cloudy',
            'condition_code' => 'MostlyCloudy',
            'wind_kph' => 14.8,
            'wind_direction' => 240,
            'humidity' => 74,
            'uv_index' => 3,
            'is_daylight' => true,
        ]);
});

test('keeps the next six hours, skipping hours already started', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);

    $hours = app(GetPlaceWeather::class)(gpwLocation())['hours'];

    expect($hours)->toHaveCount(6)
        ->and(array_column($hours, 'time'))->toBe([
            '2026-11-01T11:00:00-05:00',
            '2026-11-01T12:00:00-05:00',
            '2026-11-01T13:00:00-05:00',
            '2026-11-01T14:00:00-05:00',
            '2026-11-01T15:00:00-05:00',
            '2026-11-01T16:00:00-05:00',
        ])
        ->and($hours[0])->toBe([
            'time' => '2026-11-01T11:00:00-05:00',
            'temperature_c' => 13.0,
            'precip_chance' => 30,
            'condition' => 'Scattered thunderstorms',
            'condition_code' => 'ScatteredThunderstorms',
            'is_daylight' => true,
        ])
        ->and($hours[1]['condition'])->toBe('Mostly cloudy');
});

test('each hour carries WeatherKit\'s daylight flag, false after dark', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);

    $hours = app(GetPlaceWeather::class)(gpwLocation())['hours'];

    expect(array_column($hours, 'is_daylight'))->toBe([true, true, true, true, false, false]);
});

test('keeps today plus the next four days in the place timezone', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);

    $days = app(GetPlaceWeather::class)(gpwLocation())['days'];

    expect(array_column($days, 'date'))->toBe(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'])
        ->and($days[0])->toBe([
            'date' => '2026-11-01',
            'high_c' => 19.4,
            'low_c' => 8.2,
            'precip_chance' => 30,
            'condition' => 'Mostly cloudy',
            'condition_code' => 'MostlyCloudy',
            'sunrise' => '2026-11-01T06:28:00-05:00',
            'sunset' => '2026-11-01T16:55:00-05:00',
        ]);
});

test('a value WeatherKit did not send is null, never zero', function () {
    $fixture = gpwFixture();
    Arr::forget($fixture, ['currentWeather.uvIndex', 'currentWeather.temperatureApparent', 'currentWeather.windDirection', 'currentWeather.daylight']);
    foreach ($fixture['forecastHourly']['hours'] as $i => $hour) {
        unset($fixture['forecastHourly']['hours'][$i]['precipitationChance'], $fixture['forecastHourly']['hours'][$i]['daylight']);
    }
    foreach ($fixture['forecastDaily']['days'] as $i => $day) {
        unset($fixture['forecastDaily']['days'][$i]['precipitationChance'], $fixture['forecastDaily']['days'][$i]['sunrise'], $fixture['forecastDaily']['days'][$i]['temperatureMax']);
    }
    Http::fake(['weatherkit.apple.com/*' => Http::response($fixture)]);

    $weather = app(GetPlaceWeather::class)(gpwLocation());

    expect($weather['current']['uv_index'])->toBeNull()
        ->and($weather['current']['feels_like_c'])->toBeNull()
        ->and($weather['current']['wind_direction'])->toBeNull()
        ->and($weather['current']['is_daylight'])->toBeNull()
        ->and($weather['hours'][0]['precip_chance'])->toBeNull()
        ->and($weather['hours'][0]['is_daylight'])->toBeNull()
        ->and($weather['days'][0]['precip_chance'])->toBeNull()
        ->and($weather['days'][0]['sunrise'])->toBeNull()
        ->and($weather['days'][0]['high_c'])->toBeNull()
        ->and($weather['days'][0]['sunset'])->toBe('2026-11-01T16:55:00-05:00');
});

test('fewer hours and days than expected are returned as sent', function () {
    $fixture = gpwFixture();
    $fixture['forecastHourly']['hours'] = array_slice($fixture['forecastHourly']['hours'], 0, 4);
    unset($fixture['forecastDaily']);
    Http::fake(['weatherkit.apple.com/*' => Http::response($fixture)]);

    $weather = app(GetPlaceWeather::class)(gpwLocation());

    expect($weather['hours'])->toHaveCount(1)
        ->and($weather['days'])->toBe([]);
});

test('a response without a current temperature is unavailable', function () {
    $fixture = gpwFixture();
    unset($fixture['currentWeather']['temperature']);
    Http::fake(['weatherkit.apple.com/*' => Http::response($fixture)]);

    expect(app(GetPlaceWeather::class)(gpwLocation()))->toBeNull();
});

test('a second call within ten minutes makes no request, one after does', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);
    $location = gpwLocation();

    app(GetPlaceWeather::class)($location);
    Carbon::setTestNow(now()->addMinutes(9));
    app(GetPlaceWeather::class)($location);

    expect(gpwWeatherRequests())->toBe(1);

    Carbon::setTestNow(now()->addMinutes(2));
    app(GetPlaceWeather::class)($location);

    expect(gpwWeatherRequests())->toBe(2);
});

test('nearby locations share one lookup with rounded coordinates', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);

    $first = app(GetPlaceWeather::class)(gpwLocation(['lat' => 40.7128, 'lng' => -74.0060]));
    $second = app(GetPlaceWeather::class)(gpwLocation(['lat' => 40.7138, 'lng' => -74.0050]));

    expect($second)->toBe($first)
        ->and(gpwWeatherRequests())->toBe(1);

    Http::assertSent(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/api/v1/weather/en/40.71/-74.01');
});

test('a failed lookup is cached as unavailable for sixty seconds', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::sequence()
        ->push('', 500)
        ->push(gpwFixture())]);
    $location = gpwLocation();

    expect(app(GetPlaceWeather::class)($location))->toBeNull();

    Carbon::setTestNow(now()->addSeconds(59));
    expect(app(GetPlaceWeather::class)($location))->toBeNull()
        ->and(gpwWeatherRequests())->toBe(1);

    Carbon::setTestNow(now()->addSeconds(2));
    expect(app(GetPlaceWeather::class)($location))->toBeArray()
        ->and(gpwWeatherRequests())->toBe(2);
});

test('timezone is the location, else the owner, else the app timezone', function (?string $locationTz, ?string $userTz, string $expected) {
    config(['app.timezone' => 'UTC']);
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);
    $user = User::factory()->create(['timezone' => $userTz]);

    $weather = app(GetPlaceWeather::class)(gpwLocation(['user_id' => $user->id, 'timezone' => $locationTz]));

    expect($weather['timezone'])->toBe($expected);

    Http::assertSent(function (Request $request) use ($expected) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['timezone'] === $expected;
    });
})->with([
    'location' => ['America/Chicago', 'America/Denver', 'America/Chicago'],
    'owner' => [null, 'America/Denver', 'America/Denver'],
    'app' => [null, null, 'UTC'],
]);

test('attribution maps the logos, name and legal link and is cached for a day', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response([
        'logoDark@1x' => '/assets/branding/en/Apple_Weather_wht_en_1X.png',
        'logoDark@2x' => '/assets/branding/en/Apple_Weather_wht_en_2X.png',
        'logoDark@3x' => '/assets/branding/en/Apple_Weather_wht_en_3X.png',
        'logoLight@1x' => '/assets/branding/en/Apple_Weather_blk_en_1X.png',
        'logoLight@2x' => '/assets/branding/en/Apple_Weather_blk_en_2X.png',
        'logoLight@3x' => '/assets/branding/en/Apple_Weather_blk_en_3X.png',
        'logoSquare@1x' => '/assets/branding/square-mark.png',
        'serviceName' => 'Apple Weather',
    ])]);

    $attribution = app(GetWeatherAttribution::class)();

    expect($attribution)->toBe([
        'name' => 'Apple Weather',
        'logo_light_url' => 'https://weatherkit.apple.com/assets/branding/en/Apple_Weather_blk_en_3X.png',
        'logo_dark_url' => 'https://weatherkit.apple.com/assets/branding/en/Apple_Weather_wht_en_3X.png',
        'legal_url' => 'https://developer.apple.com/weatherkit/data-source-attribution/',
    ]);

    Carbon::setTestNow(now()->addHours(23));
    app(GetWeatherAttribution::class)();
    Http::assertSentCount(1);

    Carbon::setTestNow(now()->addHours(2));
    app(GetWeatherAttribution::class)();
    Http::assertSentCount(2);
});

test('attribution without logos or a name still names the service', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(['logoLight@1x' => '/assets/light.png'])]);

    expect(app(GetWeatherAttribution::class)())->toBe([
        'name' => 'Apple Weather',
        'logo_light_url' => 'https://weatherkit.apple.com/assets/light.png',
        'logo_dark_url' => null,
        'legal_url' => 'https://developer.apple.com/weatherkit/data-source-attribution/',
    ]);
});

test('a failed attribution is the static Apple Weather credit and is retried after sixty seconds', function () {
    $fallback = [
        'name' => 'Apple Weather',
        'logo_light_url' => null,
        'logo_dark_url' => null,
        'legal_url' => 'https://developer.apple.com/weatherkit/data-source-attribution/',
    ];
    Http::fake(['weatherkit.apple.com/*' => Http::sequence()
        ->push('', 500)
        ->push(['serviceName' => 'Weather'])]);

    expect(app(GetWeatherAttribution::class)())->toBe($fallback);

    Carbon::setTestNow(now()->addSeconds(59));
    expect(app(GetWeatherAttribution::class)())->toBe($fallback);
    Http::assertSentCount(1);

    Carbon::setTestNow(now()->addSeconds(2));
    expect(app(GetWeatherAttribution::class)())->toBe([
        'name' => 'Weather',
        'logo_light_url' => null,
        'logo_dark_url' => null,
        'legal_url' => 'https://developer.apple.com/weatherkit/data-source-attribution/',
    ]);
});

test('a malformed largest logo scale falls through to the next scale', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response([
        'logoLight@3x' => 'not-a-path.png',
        'logoLight@2x' => '/assets/light@2x.png',
        'logoDark@3x' => 'http://insecure.example/dark.png',
        'logoDark@1x' => '/assets/dark@1x.png',
    ])]);

    $attribution = app(GetWeatherAttribution::class)();

    expect($attribution['logo_light_url'])->toBe('https://weatherkit.apple.com/assets/light@2x.png')
        ->and($attribution['logo_dark_url'])->toBe('https://weatherkit.apple.com/assets/dark@1x.png');
});
