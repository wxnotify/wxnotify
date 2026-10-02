<?php

use App\Actions\Weather\GetCurrentConditions;
use App\Actions\Weather\GetNwsPoint;
use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 09:00 America/Denver

    $this->location = Location::factory()->create([
        'label' => 'Home', 'lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => 'America/Denver',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $observations  station id => decoded body or [status]
 */
function ccFake(array $stationIds, array $observations): void
{
    nwsFreshHttp();

    $fakes = [
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Http::response(nwsStations(array_map(fn (string $id): array => ['id' => $id], $stationIds))),
    ];

    foreach ($observations as $id => $body) {
        $fakes["api.weather.gov/stations/{$id}/observations/latest"] = is_int($body) ? Http::response([], $body) : Http::response($body);
    }

    Http::fake($fakes);
}

test('GetNwsPoint returns the five properties the summary needs and caches per location', function () {
    Http::fake(['api.weather.gov/points/*' => Http::response(nwsPoint())]);

    $first = (new GetNwsPoint)($this->location);
    $second = (new GetNwsPoint)($this->location);

    expect($first)->toBe([
        'forecast' => 'https://api.weather.gov/gridpoints/BOU/64,73/forecast',
        'forecastHourly' => 'https://api.weather.gov/gridpoints/BOU/64,73/forecast/hourly',
        'observationStations' => 'https://api.weather.gov/gridpoints/BOU/64,73/stations',
        'radarStation' => 'KFTG',
        'timeZone' => 'America/Denver',
    ])->and($second)->toBe($first);
    Http::assertSentCount(1);
});

test('GetNwsPoint rounds coordinates to four decimals for the request, and returns null on failure without caching it', function () {
    Http::fake(['api.weather.gov/points/*' => Http::response([], 503)]);

    expect((new GetNwsPoint)($this->location))->toBeNull();
    expect((new GetNwsPoint)($this->location))->toBeNull();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.weather.gov/points/40.0032,-104.9970');
});

test('GetNwsPoint returns null for a location with no fix and sends nothing', function () {
    Http::fake();

    expect((new GetNwsPoint)(Location::factory()->mobile()->create()))->toBeNull();
    Http::assertNothingSent();
});

test('it normalizes the nearest station\'s observation into imperial display values', function () {
    ccFake(['KEIK', 'KBJC'], ['KEIK' => nwsObservation(), 'KBJC' => nwsObservation()]);

    $current = (new GetCurrentConditions)($this->location);

    expect($current['station'])->toMatchArray(['id' => 'KEIK', 'name' => 'Erie Municipal Airport', 'lat' => 40.01169, 'lng' => -105.05033])
        ->and($current['station']['elevation_ft'])->toEqualWithDelta(5085.0, 0.5)
        ->and($current['observed_at'])->toBe('2026-10-02T08:35:00-06:00')
        ->and($current['description'])->toBe('Clear')
        ->and($current['icon'])->toBe(['period' => 'day', 'code' => 'skc'])
        ->and($current['temperature_f'])->toEqualWithDelta(53.6, 0.01)
        ->and($current['dewpoint_f'])->toEqualWithDelta(46.4, 0.01)
        ->and($current['humidity_pct'])->toEqualWithDelta(76.5, 0.01)
        ->and($current['barometer_inhg'])->toEqualWithDelta(30.27, 0.01)
        ->and($current['visibility_mi'])->toEqualWithDelta(10.0, 0.01);
});

test('feels-like prefers wind chill, then heat index, then the plain temperature', function () {
    ccFake(['KEIK'], ['KEIK' => nwsObservation(['windChill' => ['value' => 0.0], 'heatIndex' => ['value' => 30.0]])]);
    expect((new GetCurrentConditions)($this->location)['feels_like_f'])->toEqualWithDelta(32.0, 0.01);

    Cache::flush();
    ccFake(['KEIK'], ['KEIK' => nwsObservation(['heatIndex' => ['value' => 30.0]])]);
    expect((new GetCurrentConditions)($this->location)['feels_like_f'])->toEqualWithDelta(86.0, 0.01);

    Cache::flush();
    ccFake(['KEIK'], ['KEIK' => nwsObservation()]);
    expect((new GetCurrentConditions)($this->location)['feels_like_f'])->toEqualWithDelta(53.6, 0.01);
});

test('wind is a compass direction with speed, gust only when reported, and calm has no direction', function () {
    ccFake(['KEIK'], ['KEIK' => nwsObservation([
        'windDirection' => ['value' => 45], 'windSpeed' => ['value' => 16.0934], 'windGust' => ['value' => 32.1868],
    ])]);
    $windy = (new GetCurrentConditions)($this->location)['wind'];

    expect($windy['direction'])->toBe('NE')
        ->and($windy['speed_mph'])->toEqualWithDelta(10.0, 0.01)
        ->and($windy['gust_mph'])->toEqualWithDelta(20.0, 0.01);

    Cache::flush();
    ccFake(['KEIK'], ['KEIK' => nwsObservation()]);
    $calm = (new GetCurrentConditions)($this->location)['wind'];

    expect($calm['direction'])->toBeNull()
        ->and($calm['speed_mph'])->toEqualWithDelta(0.0, 0.01)
        ->and($calm['gust_mph'])->toBeNull();
});

test('missing optional readings come back as null, never zero', function () {
    ccFake(['KEIK'], ['KEIK' => nwsObservation([
        'relativeHumidity' => ['value' => null], 'barometricPressure' => ['value' => null],
        'dewpoint' => ['value' => null], 'visibility' => ['value' => null],
    ])]);

    $current = (new GetCurrentConditions)($this->location);

    expect($current['humidity_pct'])->toBeNull()
        ->and($current['barometer_inhg'])->toBeNull()
        ->and($current['dewpoint_f'])->toBeNull()
        ->and($current['visibility_mi'])->toBeNull();
});

test('a station reporting no temperature, or a stale observation, is skipped for the next nearest', function () {
    ccFake(['KEIK', 'KBJC', 'KBDU'], [
        'KEIK' => nwsObservation(['temperature' => ['value' => null]]),
        'KBJC' => nwsObservation(['timestamp' => '2026-10-02T09:00:00+00:00']), // 6h old
        'KBDU' => nwsObservation(['textDescription' => 'Fog']),
    ]);

    $current = (new GetCurrentConditions)($this->location);

    expect($current['station']['id'])->toBe('KBDU')
        ->and($current['description'])->toBe('Fog');
});

test('a station whose observation request fails is skipped', function () {
    ccFake(['KEIK', 'KBJC'], ['KEIK' => 500, 'KBJC' => nwsObservation()]);

    expect((new GetCurrentConditions)($this->location)['station']['id'])->toBe('KBJC');
});

test('only the first five stations are tried, and null comes back when none qualifies', function () {
    $ids = ['KAAA', 'KBBB', 'KCCC', 'KDDD', 'KEEE', 'KFFF'];
    ccFake($ids, array_fill_keys($ids, nwsObservation(['temperature' => ['value' => null]])));

    expect((new GetCurrentConditions)($this->location))->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/stations/KFFF/'));
});

test('a station identifier that is not a plain code never reaches a url', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Http::response(nwsStations([['id' => '../evil'], ['id' => 'KBJC']])),
        'api.weather.gov/stations/KBJC/observations/latest' => Http::response(nwsObservation()),
    ]);

    expect((new GetCurrentConditions)($this->location)['station']['id'])->toBe('KBJC');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil'));
});

test('it returns null when the point lookup or the station list fails', function () {
    Http::fake(['api.weather.gov/points/*' => Http::response([], 503)]);
    expect((new GetCurrentConditions)($this->location))->toBeNull();

    Cache::flush();
    nwsFreshHttp();
    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Http::response([], 500),
    ]);
    expect((new GetCurrentConditions)($this->location))->toBeNull();
});

test('the result is cached for the location', function () {
    ccFake(['KEIK'], ['KEIK' => nwsObservation()]);

    (new GetCurrentConditions)($this->location);
    (new GetCurrentConditions)($this->location);

    Http::assertSentCount(3); // point + station list + one observation, once
});
