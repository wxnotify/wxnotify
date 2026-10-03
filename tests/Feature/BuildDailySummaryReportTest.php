<?php

use App\Actions\Weather\BuildDailySummaryReport;
use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Enums\UnitSystem;
use App\Models\ActiveWwa;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 09:00 America/Denver
    $this->location = Location::factory()->create([
        'label' => 'Home', 'lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => 'America/Denver',
        'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('it assembles every section for a location', function () {
    nwsFakeEverything();
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Wind Advisory', 'family' => 'advisory'])->id,
        'ugc_codes' => ['COZ040'],
    ]);

    $report = (new BuildDailySummaryReport)($this->location);

    expect($report['location'])->toBe('Home')
        ->and($report['timezone'])->toBe('America/Denver')
        ->and($report['sun'])->toHaveKeys(['sunrise', 'sunset'])
        ->and($report['alerts'])->toHaveCount(1)
        ->and($report['alerts'][0]['event'])->toBe('Wind Advisory')
        ->and($report['current']['station']['id'])->toBe('KEIK')
        ->and($report['radar_site'])->toBe('KFTG')
        ->and($report['hourly'])->toHaveCount(12)
        ->and($report['periods'])->toHaveCount(3);
});

test('periods are labelled High for daytime and Low for nighttime with icon codes and a local date', function () {
    nwsFakeEverything();

    $periods = (new BuildDailySummaryReport)($this->location)['periods'];

    expect($periods[0])->toBe([
        'name' => 'Today', 'date' => '2026-10-02T09:00:00-06:00', 'summary' => 'Sunny, with a high near 79. East southeast wind 2 to 6 mph.', 'short' => 'Sunny', 'label' => 'High',
        'temperature' => 79, 'unit' => 'F', 'icon' => ['period' => 'day', 'code' => 'few'],
    ])->and($periods[1]['label'])->toBe('Low')
        ->and($periods[1]['icon'])->toBe(['period' => 'night', 'code' => 'few']);
});

test('it returns null when the seven-day forecast is unavailable', function () {
    nwsFakeEverything(['api.weather.gov/gridpoints/BOU/64,73/forecast' => Http::response([], 503)]);

    expect((new BuildDailySummaryReport)($this->location))->toBeNull();
});

test('a failing optional section is omitted without losing the rest of the report', function () {
    nwsFakeEverything([
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => Http::response([], 500),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Http::response([], 500),
    ]);

    $report = (new BuildDailySummaryReport)($this->location);

    expect($report['hourly'])->toBeNull()
        ->and($report['current'])->toBeNull()
        ->and($report['periods'])->toHaveCount(3)
        ->and($report['sun'])->not->toBeNull()
        ->and($report['alerts'])->toBe([]);
});

test('a failed alert lookup is null, not an empty list, while the other sections survive', function () {
    nwsFakeEverything();
    $this->mock(GetActiveAlertsForLocation::class, fn ($mock) => $mock->shouldReceive('__invoke')->andThrow(new RuntimeException('db down')));
    Log::spy();

    $report = (new BuildDailySummaryReport)($this->location);

    expect($report['alerts'])->toBeNull()
        ->and($report['periods'])->toHaveCount(3)
        ->and($report['current'])->not->toBeNull();
    Log::shouldHaveReceived('warning')->with('Daily summary alerts section failed', ['exception' => RuntimeException::class])->once();
});

test('an exception thrown inside a section is swallowed into that section being empty', function () {
    nwsFakeEverything([
        'api.weather.gov/gridpoints/BOU/64,73/stations' => fn () => throw new RuntimeException('boom'),
    ]);

    $report = (new BuildDailySummaryReport)($this->location);

    expect($report['current'])->toBeNull()
        ->and($report['periods'])->toHaveCount(3);
});

test('an invalid or missing radar station is dropped, never passed on', function () {
    nwsFakeEverything(['api.weather.gov/points/*' => Http::response(nwsPoint(['radarStation' => '../evil']))]);
    expect((new BuildDailySummaryReport)($this->location)['radar_site'])->toBeNull();

    Cache::flush();
    nwsFakeEverything(['api.weather.gov/points/*' => Http::response(nwsPoint(['radarStation' => null]))]);
    expect((new BuildDailySummaryReport)($this->location)['radar_site'])->toBeNull();

    Cache::flush();
    nwsFakeEverything(['api.weather.gov/points/*' => Http::response(nwsPoint(['radarStation' => "KFTG\n"]))]);
    expect((new BuildDailySummaryReport)($this->location)['radar_site'])->toBeNull();
});

test('the report is plain data that survives serialization, with no objects in it', function () {
    nwsFakeEverything();

    $report = (new BuildDailySummaryReport)($this->location);

    array_walk_recursive($report, function ($value): void {
        expect(is_scalar($value) || $value === null)->toBeTrue();
    });
    expect(unserialize(serialize($report)))->toBe($report)
        ->and(json_decode(json_encode($report), true))->toEqual($report);
});

test('test alerts are included only when the caller says the owner is a test account', function () {
    nwsFakeEverything();
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Synthetic Warning', 'family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'], 'is_test' => true,
    ]);

    expect((new BuildDailySummaryReport)($this->location)['alerts'])->toBe([])
        ->and((new BuildDailySummaryReport)($this->location, includeTestAlerts: true)['alerts'])->toHaveCount(1);
});

test('a connection failure fetching the seven-day forecast returns null and logs only the exception class', function () {
    Log::spy();
    nwsFakeEverything([
        'api.weather.gov/gridpoints/BOU/64,73/forecast' => fn () => throw new ConnectionException('timeout'),
    ]);

    expect((new BuildDailySummaryReport)($this->location))->toBeNull();

    Log::shouldHaveReceived('warning')->once()->with(
        'Daily summary forecast section failed',
        ['exception' => ConnectionException::class],
    );
});

test('a period with no detailed forecast falls back to its short forecast for the summary text', function () {
    $periods = nwsForecastPeriods();
    $periods[0]['detailedForecast'] = '  ';
    unset($periods[1]['detailedForecast']);
    nwsFakeEverything(['api.weather.gov/gridpoints/BOU/64,73/forecast' => Http::response(['properties' => ['periods' => $periods]])]);

    $report = (new BuildDailySummaryReport)($this->location);

    expect($report['periods'][0]['summary'])->toBe('Sunny')
        ->and($report['periods'][0]['short'])->toBe('Sunny')
        ->and($report['periods'][1]['summary'])->toBe('Mostly Clear')
        ->and($report['periods'][2]['summary'])->toBe('Sunny. High near 84, with temperatures falling to around 81 in the afternoon.');
});

test('the report records the unit system it was built for, defaulting to imperial', function () {
    nwsFakeEverything();

    expect((new BuildDailySummaryReport)($this->location)['units'])->toBe('imperial')
        ->and((new BuildDailySummaryReport)($this->location, units: UnitSystem::Metric)['units'])->toBe('metric');
});

/**
 * A report for a stationary Denver-area location whose own timezone and owner's profile timezone are given,
 * with one active alert so every time-bearing section (alerts, current, hourly, periods, sun) is non-empty.
 *
 * @return array<string, mixed>
 */
function reportForTimezones(?string $locationTimezone, ?string $profileTimezone): array
{
    nwsFakeEverything();
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Wind Advisory', 'family' => 'advisory'])->id,
        'ugc_codes' => ['COZ040'],
        'expires' => '2026-10-02 21:00:00',
    ]);
    $location = Location::factory()->create([
        'user_id' => User::factory()->create(['timezone' => $profileTimezone])->id,
        'lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => $locationTimezone,
        'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014',
    ]);

    return (new BuildDailySummaryReport)($location);
}

test('a location with no timezone is rendered in the owner\'s profile timezone', function () {
    $report = reportForTimezones(null, 'America/Denver');

    expect($report['timezone'])->toBe('America/Denver')
        ->and($report['periods'][0]['date'])->toBe('2026-10-02T09:00:00-06:00')
        ->and($report['sun']['sunrise'])->toEndWith('-06:00')
        ->and($report['hourly'][0]['time'])->toBe('2026-10-02T09:00:00-06:00')
        ->and($report['alerts'][0]['expires'])->toBe('2026-10-02T15:00:00-06:00')
        ->and($report['current']['observed_at'])->toEndWith('-06:00');
});

test('a location with no timezone and an owner with none falls back to the app timezone', function () {
    $report = reportForTimezones(null, null);

    expect($report['timezone'])->toBe(config('app.timezone'))
        ->and($report['periods'][0]['date'])->toEndWith('+00:00')
        ->and($report['hourly'][0]['time'])->toBe('2026-10-02T15:00:00+00:00')
        ->and($report['alerts'][0]['expires'])->toBe('2026-10-02T21:00:00+00:00')
        ->and($report['current']['observed_at'])->toEndWith('+00:00');
});

test('a location with its own timezone ignores the owner\'s profile timezone', function () {
    $report = reportForTimezones('America/Denver', 'Pacific/Honolulu');

    expect($report['timezone'])->toBe('America/Denver')
        ->and($report['periods'][0]['date'])->toBe('2026-10-02T09:00:00-06:00')
        ->and($report['sun']['sunrise'])->toEndWith('-06:00')
        ->and($report['hourly'][0]['time'])->toBe('2026-10-02T09:00:00-06:00')
        ->and($report['alerts'][0]['expires'])->toBe('2026-10-02T15:00:00-06:00')
        ->and($report['current']['observed_at'])->toEndWith('-06:00');
});
