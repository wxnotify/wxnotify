<?php

use App\Enums\UnitSystem;
use App\Http\Resources\Api\V1\PlaceWeatherResource;
use App\Models\Location;
use App\Support\FormatPlaceWeather;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @return array<string, mixed>
 */
function fpwWeather(string $timezone = 'America/Denver'): array
{
    return [
        'as_of' => '2026-11-01T09:18:00-07:00',
        'timezone' => $timezone,
        'current' => [
            'temperature_c' => 25.56,
            'feels_like_c' => 27.8,
            'condition' => 'Mostly cloudy',
            'condition_code' => 'MostlyCloudy',
            'wind_kph' => 14.4,
            'wind_direction' => 135,
            'humidity' => 74,
            'uv_index' => 3,
            'is_daylight' => true,
            'gust_kph' => 24.1,
            'pressure_hpa' => 1019.9,
            'pressure_trend' => 'rising',
            'visibility_km' => 16.09,
            'dew_point_c' => 8.9,
            'cloud_cover' => 75,
        ],
        'hours' => [
            ['time' => '2026-11-01T15:00:00-07:00', 'temperature_c' => 26.7, 'precip_chance' => 30, 'condition' => 'Rain', 'condition_code' => 'Rain', 'is_daylight' => true, 'wind_kph' => 14.4],
            ['time' => '2026-11-01T16:00:00-07:00', 'temperature_c' => 25.0, 'precip_chance' => null, 'condition' => 'Clear', 'condition_code' => 'Clear', 'is_daylight' => null, 'wind_kph' => null],
        ],
        'days' => [
            ['date' => '2026-11-01', 'high_c' => 27.2, 'low_c' => 20.6, 'precip_chance' => 40, 'condition' => 'Rain', 'condition_code' => 'Rain', 'sunrise' => '2026-11-01T06:48:00-07:00', 'sunset' => '2026-11-01T17:12:00-07:00'],
            ['date' => '2026-11-02', 'high_c' => 20.0, 'low_c' => 10.0, 'precip_chance' => null, 'condition' => 'Clear', 'condition_code' => 'Clear', 'sunrise' => null, 'sunset' => null],
        ],
        'next_hour' => null,
    ];
}

/**
 * A GetPlaceWeather next_hour value: one minute per entry from $from, wet (80%, 1.2 mm/h) at the
 * given minute indexes and dry (0%, 0 mm/h) elsewhere, under one summary period of $condition.
 *
 * @param  list<int>  $wet
 * @return array<string, mixed>
 */
function fpwNextHour(array $wet, string $condition = 'rain', int $count = 75, string $from = '2026-11-01T14:30:00Z'): array
{
    $minutes = [];
    foreach (range(0, $count - 1) as $index) {
        $minutes[] = [
            'start' => Carbon::parse($from)->addMinutes($index)->setTimezone('America/Denver')->toIso8601String(),
            'chance' => in_array($index, $wet, true) ? 80 : 0,
            'intensity_mm_h' => in_array($index, $wet, true) ? 1.2 : 0.0,
        ];
    }

    return [
        'summary' => [['start' => Carbon::parse($from)->setTimezone('America/Denver')->toIso8601String(), 'end' => null, 'condition' => $condition]],
        'minutes' => $minutes,
    ];
}

function fpwNextHourLine(?array $nextHour): ?string
{
    $weather = fpwWeather();
    $weather['next_hour'] = $nextHour;

    return FormatPlaceWeather::forUser($weather, new Units, test()->fpwLocation)['next_hour'] ?? null;
}

beforeEach(function () {
    Carbon::setTestNow('2026-11-01T14:30:00Z'); // 07:30 in Denver
    $this->fpwLocation = Location::factory()->create(['label' => 'Home']);
});

test('formats an imperial card in the place timezone', function () {
    $card = FormatPlaceWeather::forUser(fpwWeather(), new Units(UnitSystem::Imperial), $this->fpwLocation);

    expect($card['id'])->toBe($this->fpwLocation->id)
        ->and($card['label'])->toBe('Home')
        ->and($card['available'])->toBeTrue()
        ->and($card['timezone'])->toBe('America/Denver')
        ->and($card['current'])->toBe([
            'temperature' => '78°',
            'temperature_value' => 78,
            'condition' => 'Mostly cloudy',
            'condition_code' => 'MostlyCloudy',
            'feels_like' => '82°',
            'wind' => '9 mph SE',
            'humidity' => '74%',
            'uv_index' => 3,
            'is_daylight' => true,
            'gust' => '15 mph',
            'pressure' => '30.12 inHg',
            'pressure_trend' => 'rising',
            'visibility' => '10 mi',
            'dew_point' => '48°',
            'cloud_cover' => '75%',
        ])
        ->and($card['hours'][0])->toBe(['label' => '3 PM', 'time' => '2026-11-01T15:00:00-07:00', 'temperature' => '80°', 'precip_chance' => '30%', 'condition_code' => 'Rain', 'is_daylight' => true, 'wind' => '9 mph'])
        ->and($card['days'][0])->toBe([
            'label' => 'Today', 'date' => '2026-11-01', 'high' => '81°', 'low' => '69°', 'precip_chance' => '40%',
            'condition_code' => 'Rain', 'sunrise' => '6:48 AM', 'sunset' => '5:12 PM',
        ])
        ->and($card['days'][1]['label'])->toBe('Mon');
});

test('formats the same input for a metric subscriber', function () {
    $card = FormatPlaceWeather::forUser(fpwWeather(), new Units(UnitSystem::Metric), $this->fpwLocation);

    expect($card['current']['temperature'])->toBe('26°')
        ->and($card['current']['temperature_value'])->toBe(26)
        ->and($card['current']['wind'])->toBe('14 km/h SE')
        ->and($card['current']['gust'])->toBe('24 km/h')
        ->and($card['current']['pressure'])->toBe('1020 hPa')
        ->and($card['current']['visibility'])->toBe('16 km')
        ->and($card['current']['dew_point'])->toBe('9°')
        ->and($card['current']['cloud_cover'])->toBe('75%')
        ->and($card['current']['pressure_trend'])->toBe('rising')
        ->and($card['hours'][0]['wind'])->toBe('14 km/h')
        ->and($card['days'][0]['high'])->toBe('27°');
});

test('a short visibility keeps one decimal', function (string $system, string $expected) {
    $weather = fpwWeather();
    $weather['current']['visibility_km'] = 0.8;

    expect(FormatPlaceWeather::forUser($weather, new Units(UnitSystem::from($system)), $this->fpwLocation)['current']['visibility'])->toBe($expected);
})->with([['imperial', '0.5 mi'], ['metric', '0.8 km']]);

test('omits fields the source did not send', function () {
    $weather = fpwWeather();
    $weather['as_of'] = null;
    $weather['current'] = array_merge($weather['current'], ['wind_kph' => null, 'uv_index' => null, 'humidity' => null, 'feels_like_c' => null, 'is_daylight' => null,
        'gust_kph' => null, 'pressure_hpa' => null, 'pressure_trend' => null, 'visibility_km' => null, 'dew_point_c' => null, 'cloud_cover' => null]);

    $card = FormatPlaceWeather::forUser($weather, new Units, $this->fpwLocation);

    expect($card)->not->toHaveKeys(['as_of', 'next_hour'])
        ->and($card['current'])->not->toHaveKeys(['wind', 'uv_index', 'humidity', 'feels_like', 'is_daylight', 'gust', 'pressure', 'pressure_trend', 'visibility', 'dew_point', 'cloud_cover'])
        ->and($card['hours'][1])->not->toHaveKeys(['precip_chance', 'is_daylight', 'wind'])
        ->and($card['days'][1])->not->toHaveKeys(['precip_chance', 'sunrise', 'sunset']);
});

test('omits the wind direction but keeps the speed when the bearing is unknown', function () {
    $weather = fpwWeather();
    $weather['current']['wind_direction'] = null;

    $card = FormatPlaceWeather::forUser($weather, new Units, $this->fpwLocation);

    expect($card['current']['wind'])->toBe('9 mph');
});

test('Today follows the place date when UTC is already tomorrow', function () {
    Carbon::setTestNow('2026-11-02T03:30:00Z'); // 20:30 on Nov 1 in Denver
    $weather = fpwWeather();
    $weather['hours'] = [['time' => '2026-11-01T21:00:00-07:00', 'temperature_c' => 20.0, 'precip_chance' => null, 'condition' => 'Clear', 'condition_code' => 'Clear']];

    $card = FormatPlaceWeather::forUser($weather, new Units, $this->fpwLocation);

    expect($card['days'][0]['label'])->toBe('Today')
        ->and($card['hours'][0]['label'])->toBe('9 PM');
});

test('an hour after dark passes is_daylight false through', function () {
    $weather = fpwWeather();
    $weather['hours'][0]['is_daylight'] = false;

    expect(FormatPlaceWeather::forUser($weather, new Units, $this->fpwLocation)['hours'][0]['is_daylight'])->toBeFalse();
});

test('drops hours already past and days before today in the place timezone', function () {
    $weather = fpwWeather();
    array_unshift($weather['hours'], ['time' => '2026-11-01T07:00:00-07:00', 'temperature_c' => 20.0, 'precip_chance' => null, 'condition' => 'Clear', 'condition_code' => 'Clear']);
    array_unshift($weather['hours'], ['time' => '2026-11-01T07:30:00-07:00', 'temperature_c' => 20.0, 'precip_chance' => null, 'condition' => 'Clear', 'condition_code' => 'Clear']);
    array_unshift($weather['days'], ['date' => '2026-10-31', 'high_c' => 10.0, 'low_c' => 5.0, 'precip_chance' => null, 'condition' => 'Clear', 'condition_code' => 'Clear', 'sunrise' => null, 'sunset' => null]);

    $card = FormatPlaceWeather::forUser($weather, new Units, $this->fpwLocation);

    expect(array_column($card['hours'], 'label'))->toBe(['3 PM', '4 PM'])
        ->and(array_column($card['days'], 'date'))->toBe(['2026-11-01', '2026-11-02'])
        ->and($card['days'][0]['label'])->toBe('Today');
});

test('serves all twenty-four hours still to come, dropping the stale one', function () {
    $weather = fpwWeather();
    $weather['hours'] = [];
    foreach (range(0, 24) as $index) {
        $weather['hours'][] = ['time' => Carbon::parse('2026-11-01T07:00:00-07:00')->addHours($index)->toIso8601String(), 'temperature_c' => 20.0, 'precip_chance' => null, 'condition' => 'Clear', 'condition_code' => 'Clear', 'is_daylight' => true, 'wind_kph' => 10.0];
    }

    $card = FormatPlaceWeather::forUser($weather, new Units, $this->fpwLocation);

    expect($card['hours'])->toHaveCount(24)
        ->and($card['hours'][0]['label'])->toBe('8 AM')
        ->and($card['hours'][23]['label'])->toBe('7 AM');
});

test('the next-hour line', function (array $nextHour, string $expected) {
    expect(fpwNextHourLine($nextHour))->toBe($expected);
})->with([
    'raining all hour' => [fn () => fpwNextHour(range(0, 74)), 'Rain for the next hour'],
    'rain ending' => [fn () => fpwNextHour(range(0, 24)), 'Rain ending in 25 min'],
    'rain starting' => [fn () => fpwNextHour(range(12, 74)), 'Rain starting in 12 min'],
    'rain starting next minute' => [fn () => fpwNextHour([1, 2, 3]), 'Rain starting in 1 min'],
    'snow' => [fn () => fpwNextHour(range(12, 74), 'snow'), 'Snow starting in 12 min'],
    'sleet' => [fn () => fpwNextHour(range(0, 9), 'sleet'), 'Sleet ending in 10 min'],
    'hail' => [fn () => fpwNextHour(range(0, 74), 'hail'), 'Hail for the next hour'],
    'mixed is generic' => [fn () => fpwNextHour(range(5, 74), 'mixed'), 'Precipitation starting in 5 min'],
    'a clear summary over wet minutes is generic' => [fn () => fpwNextHour(range(5, 74), 'clear'), 'Precipitation starting in 5 min'],
    'clear' => [fn () => fpwNextHour([]), 'No rain expected in the next hour'],
    'rain only after the hour' => [fn () => fpwNextHour(range(60, 74)), 'No rain expected in the next hour'],
    'stale data measured from now' => [fn () => fpwNextHour(range(0, 14), from: '2026-11-01T14:20:00Z'), 'Rain ending in 5 min'],
    'rain already over in stale data' => [fn () => fpwNextHour(range(0, 5), from: '2026-11-01T14:20:00Z'), 'No rain expected in the next hour'],
    'start in stale data measured from now' => [fn () => fpwNextHour(range(25, 74), from: '2026-11-01T14:20:00Z'), 'Rain starting in 15 min'],
]);

test('a minute is wet at 50% chance or 0.1 mm/h', function (?int $chance, ?float $intensity, bool $wet) {
    $nextHour = fpwNextHour([]);
    $nextHour['minutes'][3]['chance'] = $chance;
    $nextHour['minutes'][3]['intensity_mm_h'] = $intensity;

    expect(fpwNextHourLine($nextHour))->toBe($wet ? 'Rain starting in 3 min' : 'No rain expected in the next hour');
})->with([
    'likely, no intensity' => [50, 0.0, true],
    'light but measurable' => [10, 0.1, true],
    'no chance known, measurable' => [null, 0.2, true],
    'unlikely trace' => [49, 0.05, false],
    'nothing known' => [null, null, false],
]);

test('the next-hour line is omitted when there is nothing usable to say', function (?array $nextHour) {
    expect(fpwNextHourLine($nextHour))->toBeNull();
})->with([
    'dataset absent' => [null],
    'every minute already over' => [fn () => fpwNextHour([], count: 10, from: '2026-11-01T14:00:00Z')],
    'too few minutes to promise a dry hour' => [fn () => fpwNextHour([], count: 20)],
    'too few minutes to promise rain all hour' => [fn () => fpwNextHour(range(0, 19), count: 20)],
]);

test('a short minute list still says when rain starts or ends', function () {
    expect(fpwNextHourLine(fpwNextHour([8], count: 20)))->toBe('Rain starting in 8 min')
        ->and(fpwNextHourLine(fpwNextHour(range(0, 4), count: 20)))->toBe('Rain ending in 5 min');
});

test('converts bearings to an 8-point compass', function (int $degrees, string $name) {
    expect(FormatPlaceWeather::compass($degrees))->toBe($name);
})->with([[0, 'N'], [45, 'NE'], [180, 'S'], [350, 'N'], [337, 'NW'], [22, 'N'], [23, 'NE']]);

test('unavailable carries only the place and the flag', function () {
    expect(FormatPlaceWeather::unavailable($this->fpwLocation))->toBe(['id' => $this->fpwLocation->id, 'label' => 'Home', 'available' => false]);
});

test('the resource serves the array as given', function () {
    $array = FormatPlaceWeather::unavailable($this->fpwLocation);

    expect((new PlaceWeatherResource($array))->toArray(Request::create('/')))->toBe($array);
});
