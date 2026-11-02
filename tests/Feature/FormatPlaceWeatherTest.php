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
        ],
        'hours' => [
            ['time' => '2026-11-01T15:00:00-07:00', 'temperature_c' => 26.7, 'precip_chance' => 30, 'condition' => 'Rain', 'condition_code' => 'Rain', 'is_daylight' => true],
            ['time' => '2026-11-01T16:00:00-07:00', 'temperature_c' => 25.0, 'precip_chance' => null, 'condition' => 'Clear', 'condition_code' => 'Clear', 'is_daylight' => null],
        ],
        'days' => [
            ['date' => '2026-11-01', 'high_c' => 27.2, 'low_c' => 20.6, 'precip_chance' => 40, 'condition' => 'Rain', 'condition_code' => 'Rain', 'sunrise' => '2026-11-01T06:48:00-07:00', 'sunset' => '2026-11-01T17:12:00-07:00'],
            ['date' => '2026-11-02', 'high_c' => 20.0, 'low_c' => 10.0, 'precip_chance' => null, 'condition' => 'Clear', 'condition_code' => 'Clear', 'sunrise' => null, 'sunset' => null],
        ],
    ];
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
        ])
        ->and($card['hours'][0])->toBe(['label' => '3 PM', 'time' => '2026-11-01T15:00:00-07:00', 'temperature' => '80°', 'precip_chance' => '30%', 'condition_code' => 'Rain', 'is_daylight' => true])
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
        ->and($card['days'][0]['high'])->toBe('27°');
});

test('omits fields the source did not send', function () {
    $weather = fpwWeather();
    $weather['as_of'] = null;
    $weather['current'] = array_merge($weather['current'], ['wind_kph' => null, 'uv_index' => null, 'humidity' => null, 'feels_like_c' => null, 'is_daylight' => null]);

    $card = FormatPlaceWeather::forUser($weather, new Units, $this->fpwLocation);

    expect($card)->not->toHaveKey('as_of')
        ->and($card['current'])->not->toHaveKeys(['wind', 'uv_index', 'humidity', 'feels_like', 'is_daylight'])
        ->and($card['hours'][1])->not->toHaveKeys(['precip_chance', 'is_daylight'])
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
