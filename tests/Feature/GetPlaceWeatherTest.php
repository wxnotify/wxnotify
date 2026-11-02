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
    foreach (range(0, 29) as $index) {
        $start = Carbon::parse('2026-11-01T13:00:00Z')->addHours($index);
        $hours[] = [
            'forecastStart' => $start->toIso8601ZuluString(),
            'cloudCover' => 0.5,
            'conditionCode' => $index % 2 === 0 ? 'MostlyCloudy' : 'ScatteredThunderstorms',
            'daylight' => $start->hour >= 12 && $start->hour < 20,
            'humidity' => 0.6,
            'precipitationAmount' => 0,
            'precipitationChance' => 0.1 * ($index % 10),
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
        'forecastNextHour' => gpwNextHour(),
    ];
}

/**
 * Apple's NextHourForecastData: a minute array (ForecastMinute) starting one minute before "now"
 * (15:19Z, already over at 15:20Z), dry for the first eleven minutes then raining, and its
 * ForecastPeriodSummary list.
 *
 * @return array<string, mixed>
 */
function gpwNextHour(): array
{
    $minutes = [];
    foreach (range(0, 89) as $offset) {
        $minutes[] = [
            'startTime' => Carbon::parse('2026-11-01T15:19:00Z')->addMinutes($offset)->toIso8601ZuluString(),
            'precipitationChance' => $offset < 11 ? 0.05 : 0.8,
            'precipitationIntensity' => $offset < 11 ? 0 : 1.25,
            'perceivedPrecipitationIntensity' => $offset < 11 ? 0 : 0.4,
        ];
    }

    return [
        'name' => 'NextHourForecast',
        'forecastStart' => '2026-11-01T15:19:00Z',
        'forecastEnd' => '2026-11-01T16:49:00Z',
        'summary' => [
            ['startTime' => '2026-11-01T15:19:00Z', 'endTime' => '2026-11-01T15:30:00Z', 'condition' => 'clear', 'precipitationChance' => 0, 'precipitationIntensity' => 0],
            ['startTime' => '2026-11-01T15:30:00Z', 'condition' => 'rain', 'precipitationChance' => 0.8, 'precipitationIntensity' => 1.25],
        ],
        'minutes' => $minutes,
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
            'gust_kph' => 33.5,
            'pressure_hpa' => 1016.4,
            'pressure_trend' => 'falling',
            'visibility_km' => 24.0,
            'dew_point_c' => 8.0,
            'cloud_cover' => 70,
        ]);
});

test('an unknown pressure trend is null', function () {
    $fixture = gpwFixture();
    $fixture['currentWeather']['pressureTrend'] = 'sideways';
    Http::fake(['weatherkit.apple.com/*' => Http::response($fixture)]);

    expect(app(GetPlaceWeather::class)(gpwLocation())['current']['pressure_trend'])->toBeNull();
});

test('keeps the next twenty-four hours, skipping hours already started', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);

    $hours = app(GetPlaceWeather::class)(gpwLocation())['hours'];

    expect($hours)->toHaveCount(24)
        ->and($hours[0]['time'])->toBe('2026-11-01T11:00:00-05:00')
        ->and($hours[23]['time'])->toBe('2026-11-02T10:00:00-05:00')
        ->and($hours[0])->toBe([
            'time' => '2026-11-01T11:00:00-05:00',
            'temperature_c' => 13.0,
            'precip_chance' => 30,
            'condition' => 'Scattered thunderstorms',
            'condition_code' => 'ScatteredThunderstorms',
            'is_daylight' => true,
            'wind_kph' => 15.2,
        ])
        ->and($hours[1]['condition'])->toBe('Mostly cloudy');
});

test('keeps the next-hour minutes still to come with their start times, and the summary', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);

    $nextHour = app(GetPlaceWeather::class)(gpwLocation())['next_hour'];

    expect($nextHour['minutes'])->toHaveCount(75)
        ->and($nextHour['minutes'][0])->toBe(['start' => '2026-11-01T10:20:00-05:00', 'chance' => 5, 'intensity_mm_h' => 0.0])
        ->and($nextHour['minutes'][10])->toBe(['start' => '2026-11-01T10:30:00-05:00', 'chance' => 80, 'intensity_mm_h' => 1.25])
        ->and($nextHour['summary'])->toBe([
            ['start' => '2026-11-01T10:19:00-05:00', 'end' => '2026-11-01T10:30:00-05:00', 'condition' => 'clear'],
            ['start' => '2026-11-01T10:30:00-05:00', 'end' => null, 'condition' => 'rain'],
        ]);
});

test('next_hour is null when WeatherKit sends no next-hour forecast for the place', function () {
    $fixture = gpwFixture();
    unset($fixture['forecastNextHour']);
    Http::fake(['weatherkit.apple.com/*' => Http::response($fixture)]);

    $weather = app(GetPlaceWeather::class)(gpwLocation());

    expect($weather)->toHaveKey('next_hour')
        ->and($weather['next_hour'])->toBeNull()
        ->and($weather['current']['temperature_c'])->toBe(12.6);
});

test('each hour carries WeatherKit\'s daylight flag, false after dark', function () {
    Http::fake(['weatherkit.apple.com/*' => Http::response(gpwFixture())]);

    $hours = app(GetPlaceWeather::class)(gpwLocation())['hours'];

    expect(array_slice(array_column($hours, 'is_daylight'), 0, 6))->toBe([true, true, true, true, false, false]);
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
    Arr::forget($fixture, ['currentWeather.uvIndex', 'currentWeather.temperatureApparent', 'currentWeather.windDirection', 'currentWeather.daylight',
        'currentWeather.windGust', 'currentWeather.pressure', 'currentWeather.pressureTrend', 'currentWeather.visibility', 'currentWeather.temperatureDewPoint', 'currentWeather.cloudCover']);
    foreach ($fixture['forecastHourly']['hours'] as $i => $hour) {
        unset($fixture['forecastHourly']['hours'][$i]['precipitationChance'], $fixture['forecastHourly']['hours'][$i]['daylight'], $fixture['forecastHourly']['hours'][$i]['windSpeed']);
    }
    foreach ($fixture['forecastNextHour']['minutes'] as $i => $minute) {
        unset($fixture['forecastNextHour']['minutes'][$i]['precipitationChance'], $fixture['forecastNextHour']['minutes'][$i]['precipitationIntensity']);
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
        ->and($weather['hours'][0]['wind_kph'])->toBeNull()
        ->and(Arr::only($weather['current'], ['gust_kph', 'pressure_hpa', 'pressure_trend', 'visibility_km', 'dew_point_c', 'cloud_cover']))->each->toBeNull()
        ->and($weather['next_hour']['minutes'][0])->toBe(['start' => '2026-11-01T10:20:00-05:00', 'chance' => null, 'intensity_mm_h' => null])
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
