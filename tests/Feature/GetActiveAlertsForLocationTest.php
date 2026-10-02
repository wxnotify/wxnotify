<?php

use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00');

    $this->location = Location::factory()->create([
        'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver',
        'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function alertFor(string $name, string $family, array $attributes = []): ActiveWwa
{
    return ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => $name, 'family' => $family])->id,
        ...$attributes,
    ]);
}

function setAlertPolygon(ActiveWwa $wwa, string $wkt): void
{
    DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $wwa->id]);
}

const INSIDE_POLYGON = 'POLYGON((-105.5 39.5, -105.5 40.5, -104.5 40.5, -104.5 39.5, -105.5 39.5))';
const OUTSIDE_POLYGON = 'POLYGON((-100.5 35.5, -100.5 36.5, -99.5 36.5, -99.5 35.5, -100.5 35.5))';

test('a polygon alert is included only when the polygon contains the location', function () {
    $inside = alertFor('Tornado Warning', 'warning', ['ugc_codes' => ['COC999'], 'headline' => 'Tornado Warning issued']);
    setAlertPolygon($inside, INSIDE_POLYGON);
    $outside = alertFor('Severe Thunderstorm Warning', 'warning', ['ugc_codes' => ['COC999']]);
    setAlertPolygon($outside, OUTSIDE_POLYGON);

    $alerts = (new GetActiveAlertsForLocation)($this->location);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0])->toMatchArray(['event' => 'Tornado Warning', 'headline' => 'Tornado Warning issued', 'family' => 'warning']);
});

test('the polygon wins over UGC overlap: a polygon that excludes the location is excluded even if its zone codes overlap', function () {
    $wwa = alertFor('Flash Flood Warning', 'warning', ['ugc_codes' => ['COZ040', 'COC014']]);
    setAlertPolygon($wwa, OUTSIDE_POLYGON);

    expect((new GetActiveAlertsForLocation)($this->location))->toBe([]);
});

test('a polygon-less alert matches by forecast zone or county code', function () {
    alertFor('Winter Weather Advisory', 'advisory', ['ugc_codes' => ['COZ040']]);
    alertFor('Red Flag Warning', 'warning', ['ugc_codes' => ['COC014']]);
    alertFor('Wind Advisory', 'advisory', ['ugc_codes' => ['COZ099']]);

    $events = collect((new GetActiveAlertsForLocation)($this->location))->pluck('event')->all();

    expect($events)->toContain('Winter Weather Advisory', 'Red Flag Warning')
        ->and($events)->not->toContain('Wind Advisory');
});

test('expired, cancelled and past-expiry alerts are excluded; a null expiry stays in effect', function () {
    alertFor('Expired One', 'warning', ['ugc_codes' => ['COZ040'], 'status' => 'Expired']);
    alertFor('Cancelled One', 'warning', ['ugc_codes' => ['COZ040'], 'status' => 'Cancelled']);
    alertFor('Lapsed One', 'warning', ['ugc_codes' => ['COZ040'], 'expires' => now()->subMinute()]);
    alertFor('Open Ended', 'statement', ['ugc_codes' => ['COZ040'], 'expires' => null]);

    expect(collect((new GetActiveAlertsForLocation)($this->location))->pluck('event')->all())->toBe(['Open Ended']);
});

test('test alerts appear only when the owner is a test account', function () {
    alertFor('Synthetic Warning', 'warning', ['ugc_codes' => ['COZ040'], 'is_test' => true]);
    alertFor('Real Advisory', 'advisory', ['ugc_codes' => ['COZ040'], 'is_test' => false]);

    expect(collect((new GetActiveAlertsForLocation)($this->location, false))->pluck('event')->all())->toBe(['Real Advisory'])
        ->and(collect((new GetActiveAlertsForLocation)($this->location, true))->pluck('event')->all())->toBe(['Synthetic Warning', 'Real Advisory']);
});

test('alerts are ordered by family severity, then soonest expiry', function () {
    alertFor('Advisory Late', 'advisory', ['ugc_codes' => ['COZ040'], 'expires' => now()->addHours(5)]);
    alertFor('Watch', 'watch', ['ugc_codes' => ['COZ040'], 'expires' => now()->addHours(2)]);
    alertFor('Warning Late', 'warning', ['ugc_codes' => ['COZ040'], 'expires' => now()->addHours(4)]);
    alertFor('Warning Soon', 'warning', ['ugc_codes' => ['COZ040'], 'expires' => now()->addHour()]);
    alertFor('Statement', 'statement', ['ugc_codes' => ['COZ040'], 'expires' => now()->addMinutes(30)]);

    expect(collect((new GetActiveAlertsForLocation)($this->location))->pluck('event')->all())
        ->toBe(['Warning Soon', 'Warning Late', 'Watch', 'Advisory Late', 'Statement']);
});

test('expiry is an ISO string in the location\'s timezone', function () {
    alertFor('Warning', 'warning', ['ugc_codes' => ['COZ040'], 'expires' => Carbon::parse('2026-10-02 21:00:00')]); // 15:00 MDT

    expect((new GetActiveAlertsForLocation)($this->location)[0]['expires'])->toBe('2026-10-02T15:00:00-06:00');
});

test('a location with no fix returns an empty list', function () {
    alertFor('Warning', 'warning', ['ugc_codes' => ['COZ040']]);

    expect((new GetActiveAlertsForLocation)(Location::factory()->mobile()->create()))->toBe([]);
});

test('a location whose zones cannot be resolved matches only by polygon and never errors', function () {
    $location = Location::factory()->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => null, 'county_ugc' => null]);
    alertFor('Zone Only', 'advisory', ['ugc_codes' => ['COZ040']]);
    $polygon = alertFor('Polygon One', 'warning', ['ugc_codes' => ['COZ040']]);
    setAlertPolygon($polygon, INSIDE_POLYGON);

    expect(collect((new GetActiveAlertsForLocation)($location))->pluck('event')->all())->toBe(['Polygon One']);
});

test('alerts with the same family and expiry come out in id order', function () {
    $expires = now()->addHours(2);
    $first = alertFor('Wind Advisory', 'advisory', ['ugc_codes' => ['COZ040'], 'expires' => $expires, 'headline' => 'first']);
    $second = alertFor('Frost Advisory', 'advisory', ['ugc_codes' => ['COZ040'], 'expires' => $expires, 'headline' => 'second']);
    $third = alertFor('Dense Fog Advisory', 'advisory', ['ugc_codes' => ['COZ040'], 'expires' => $expires, 'headline' => 'third']);

    expect(collect((new GetActiveAlertsForLocation)($this->location))->pluck('headline')->all())->toBe(['first', 'second', 'third'])
        ->and($first->id)->toBeLessThan($second->id)
        ->and($second->id)->toBeLessThan($third->id);
});
