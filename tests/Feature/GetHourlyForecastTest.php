<?php

use App\Actions\Weather\GetHourlyForecast;
use App\Models\Location;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 09:00 America/Denver
    $this->location = Location::factory()->create(['lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => 'America/Denver']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array<int, array<string, mixed>>
 */
function hourlyPeriods(int $count, string $firstStart = '2026-10-02T07:00:00-06:00'): array
{
    return collect(range(0, $count - 1))->map(function (int $i) use ($firstStart): array {
        $start = Carbon::parse($firstStart)->addHours($i);

        return [
            'startTime' => $start->toIso8601String(),
            'endTime' => $start->copy()->addHour()->toIso8601String(),
            'isDaytime' => true,
            'temperature' => 50 + $i,
            'temperatureUnit' => 'F',
            'probabilityOfPrecipitation' => ['unitCode' => 'wmoUnit:percent', 'value' => $i === 0 ? null : 10 * $i],
            'windSpeed' => '5 to 10 mph',
            'windDirection' => 'NW',
            'icon' => 'https://api.weather.gov/icons/land/day/few?size=small',
            'shortForecast' => 'Sunny',
        ];
    })->all();
}

function fakeHourly(array $periods): void
{
    nwsFreshHttp();

    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => Http::response(['properties' => ['periods' => $periods]]),
    ]);
}

test('it drops periods that have ended and returns the next twelve in order', function () {
    fakeHourly(hourlyPeriods(16)); // 07:00 .. 22:00 local; 07:00 and 08:00 have ended by 09:00

    $hourly = (new GetHourlyForecast)($this->location);

    expect($hourly)->toHaveCount(12)
        ->and(Carbon::parse($hourly[0]['time'])->format('H:i'))->toBe('09:00')
        ->and(Carbon::parse($hourly[11]['time'])->format('H:i'))->toBe('20:00');
});

test('each entry carries the display fields, with a null precip chance kept null', function () {
    fakeHourly(hourlyPeriods(16, '2026-10-02T09:00:00-06:00'));

    $first = (new GetHourlyForecast)($this->location)[0];

    expect($first)->toBe([
        'time' => '2026-10-02T09:00:00-06:00',
        'icon' => ['period' => 'day', 'code' => 'few'],
        'temperature' => 50,
        'unit' => 'F',
        'precip_chance' => null,
        'wind' => '5 to 10 mph NW',
        'summary' => 'Sunny',
    ]);
});

test('it returns what exists when fewer than twelve periods remain', function () {
    fakeHourly(hourlyPeriods(5, '2026-10-02T09:00:00-06:00'));

    expect((new GetHourlyForecast)($this->location))->toHaveCount(5);
});

test('it returns null when nothing remains, the lookup fails, or there is no hourly url', function () {
    fakeHourly(hourlyPeriods(2, '2026-10-02T05:00:00-06:00')); // both ended
    expect((new GetHourlyForecast)($this->location))->toBeNull();

    Cache::flush();
    nwsFreshHttp();
    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => Http::response([], 500),
    ]);
    expect((new GetHourlyForecast)($this->location))->toBeNull();

    Cache::flush();
    nwsFreshHttp();
    Http::fake(['api.weather.gov/points/*' => Http::response(nwsPoint(['forecastHourly' => null]))]);
    expect((new GetHourlyForecast)($this->location))->toBeNull();
});

test('a connection failure returns null without logging the exception or url', function () {
    Log::spy();
    nwsFreshHttp();
    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => fn () => throw new ConnectionException('cURL error 6: secret-host'),
    ]);

    expect((new GetHourlyForecast)($this->location))->toBeNull();

    Log::shouldHaveReceived('warning')->once()->with('NWS hourly forecast connection failed');
});

test('a failed fetch is not cached, so the next call retries', function () {
    nwsFreshHttp();
    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => Http::sequence()
            ->push([], 500)
            ->push(['properties' => ['periods' => hourlyPeriods(3, '2026-10-02T09:00:00-06:00')]]),
    ]);

    expect((new GetHourlyForecast)($this->location))->toBeNull()
        ->and((new GetHourlyForecast)($this->location))->toHaveCount(3);
});
