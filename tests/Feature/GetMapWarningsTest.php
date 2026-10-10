<?php

use App\Actions\Map\GetMapWarnings;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00');
    $this->anchors = [['lat' => 40.0, 'lng' => -105.0]];
});

afterEach(function () {
    Carbon::setTestNow();
});

const MAP_NEAR = 'POLYGON((-103.9 39.9, -103.9 40.1, -103.7 40.1, -103.7 39.9, -103.9 39.9))';   // about 95 km east
const MAP_EDGE_OUT = 'POLYGON((-101.9 39.9, -101.9 40.1, -101.7 40.1, -101.7 39.9, -101.9 39.9))'; // about 265 km east, beyond 150 miles
const MAP_COVERING = 'POLYGON((-105.5 39.5, -105.5 40.5, -104.5 40.5, -104.5 39.5, -105.5 39.5))';

function mapEvent(string $name, string $family, array $attributes = []): ActiveWwa
{
    return ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => $name, 'family' => $family])->id,
        'status' => 'Active',
        'is_test' => false,
        ...$attributes,
    ]);
}

function mapArea(ActiveWwa $wwa, ?string $polygonWkt = null, array $attributes = []): ActiveWwaArea
{
    $factory = ActiveWwaArea::factory()->state([
        'active_wwa_id' => $wwa->id,
        'ugc_code' => 'XXZ000',
        'status' => 'Active',
        'expires' => now()->addHour(),
        ...$attributes,
    ]);

    return ($polygonWkt === null ? $factory : $factory->withPolygon($polygonWkt))->create();
}

function mapZoneRow(string $table, array $attributes, string $wkt): void
{
    DB::table($table)->insert([
        ...$attributes,
        'lon' => 0,
        'lat' => 0,
        'boundary' => DB::raw("ST_Multi(ST_SetSRID(ST_GeomFromText('{$wkt}'), 4269))"),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function mapEvents(array $anchors): array
{
    return (new GetMapWarnings)($anchors);
}

test('a polygon warning within 150 miles is included and one just beyond is not', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_NEAR);
    mapArea(mapEvent('Severe Thunderstorm Warning', 'warning'), MAP_EDGE_OUT);

    $events = mapEvents($this->anchors);

    expect(collect($events)->pluck('event')->all())->toBe(['Tornado Warning']);
});

test('the payload carries the name, family, colour, headline, end, product page and simplified GeoJSON', function () {
    $product = IngestedProduct::factory()->create(['view_slug' => 'abc123']);
    $wwa = mapEvent('Tornado Warning', 'warning', ['headline' => 'Tornado Warning issued', 'latest_ingested_product_id' => $product->id]);
    mapArea($wwa, MAP_COVERING);

    $event = mapEvents($this->anchors)[0];

    expect($event)->toMatchArray([
        'id' => $wwa->id, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000',
        'headline' => 'Tornado Warning issued', 'view_url' => $product->viewUrl(),
    ])
        ->and($event['expires'])->toBe(now()->addHour()->toIso8601String())
        ->and($event['geometry']['type'])->toBe('GeometryCollection')
        ->and($event['geometry']['geometries'][0]['type'])->toBe('Polygon');
});

test('only warnings and watches are drawn', function () {
    mapArea(mapEvent('Winter Weather Advisory', 'advisory'), MAP_COVERING);
    mapArea(mapEvent('Special Weather Statement', 'statement'), MAP_COVERING);
    mapArea(mapEvent('Tornado Watch', 'watch'), MAP_COVERING);
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_COVERING);

    expect(collect(mapEvents($this->anchors))->pluck('event')->all())->toBe(['Tornado Warning', 'Tornado Watch']);
});

test('test events, cancelled or expired events and cancelled or lapsed areas are left out', function () {
    mapArea(mapEvent('Test Warning', 'warning', ['is_test' => true]), MAP_COVERING);
    mapArea(mapEvent('Cancelled Event', 'warning', ['status' => 'Cancelled']), MAP_COVERING);
    mapArea(mapEvent('Cancelled Area', 'warning'), MAP_COVERING, ['status' => 'Cancelled']);
    mapArea(mapEvent('Lapsed Area', 'warning'), MAP_COVERING, ['expires' => now()->subMinute()]);
    mapArea(mapEvent('Open Ended', 'warning'), MAP_COVERING, ['expires' => null]);

    expect(collect(mapEvents($this->anchors))->pluck('event')->all())->toBe(['Open Ended']);
});

test('an event with several areas is listed once, with the latest end, or none when an area has none', function () {
    $later = mapEvent('Flash Flood Warning', 'warning');
    mapArea($later, MAP_COVERING, ['ugc_code' => 'XXZ001', 'expires' => now()->addHour()]);
    mapArea($later, MAP_NEAR, ['ugc_code' => 'XXZ002', 'expires' => now()->addHours(3)]);
    $open = mapEvent('Tornado Warning', 'warning');
    mapArea($open, MAP_COVERING, ['ugc_code' => 'XXZ003', 'expires' => now()->addHour()]);
    mapArea($open, MAP_NEAR, ['ugc_code' => 'XXZ004', 'expires' => null]);

    $events = collect(mapEvents($this->anchors))->keyBy('event');

    expect($events)->toHaveCount(2)
        ->and($events['Flash Flood Warning']['expires'])->toBe(now()->addHours(3)->toIso8601String())
        ->and($events['Flash Flood Warning']['geometry']['geometries'])->toHaveCount(2)
        ->and($events['Tornado Warning']['expires'])->toBeNull();
});

test('a zone-only event takes its geometry from the public zone, county, fire zone or marine zone it names', function () {
    mapZoneRow('public_zones', ['state' => 'CO', 'cwa' => 'BOU', 'zone' => '040', 'name' => 'Front Range', 'state_zone' => 'CO040'], MAP_NEAR);
    mapZoneRow('counties', ['state' => 'NM', 'cwa' => 'ABQ', 'countyname' => 'Elsewhere', 'fips' => '35031'], MAP_EDGE_OUT);
    mapZoneRow('counties', ['state' => 'CO', 'cwa' => 'BOU', 'countyname' => 'Denver', 'fips' => '08031'], MAP_COVERING);
    mapZoneRow('fire_zones', ['state' => 'CO', 'zone' => '215', 'cwa' => 'BOU', 'name' => 'Fire Zone', 'state_zone' => 'CO215'], MAP_COVERING);
    mapZoneRow('marine_zones_coastal', ['zone_type' => 'coastal', 'zone_id' => 'GMZ655', 'name' => 'Marine Zone'], MAP_COVERING);

    foreach (['COZ040' => 'Zone Warning', 'COC031' => 'County Warning', 'COZ215' => 'Fire Warning', 'GMZ655' => 'Marine Warning'] as $ugc => $name) {
        mapArea(mapEvent($name, 'warning'), null, ['ugc_code' => $ugc]);
    }

    mapArea(mapEvent('Unknown Zone Warning', 'warning'), null, ['ugc_code' => 'COZ999']);

    expect(collect(mapEvents($this->anchors))->pluck('event')->sort()->values()->all())
        ->toBe(['County Warning', 'Fire Warning', 'Marine Warning', 'Zone Warning']);
});

test('a zone-only event takes its geometry from the offshore or high seas marine zone it names', function () {
    mapZoneRow('marine_zones_offshore', ['zone_type' => 'offshore', 'zone_id' => 'GMZ850', 'name' => 'Offshore Zone'], MAP_NEAR);
    mapZoneRow('marine_zones_high_seas', ['zone_type' => 'high_seas', 'zone_id' => 'PHZ170', 'name' => 'High Seas Zone'], MAP_COVERING);

    foreach (['GMZ850' => 'Offshore Warning', 'PHZ170' => 'High Seas Warning'] as $ugc => $name) {
        mapArea(mapEvent($name, 'warning'), null, ['ugc_code' => $ugc]);
    }

    expect(collect(mapEvents($this->anchors))->pluck('event')->sort()->values()->all())->toBe(['High Seas Warning', 'Offshore Warning']);
});

test('the nearest anchor of several decides the distance, and an event near any anchor is in', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_EDGE_OUT);

    expect(mapEvents($this->anchors))->toBe([])
        ->and(collect(mapEvents([...$this->anchors, ['lat' => 40.0, 'lng' => -102.0]]))->pluck('event')->all())->toBe(['Tornado Warning']);
});

test('events are ordered by family, then distance, then id, and capped', function () {
    config(['wxnotify.map.max_warnings' => 3]);
    mapArea(mapEvent('Tornado Watch', 'watch'), MAP_COVERING);
    mapArea(mapEvent('Far Warning', 'warning'), MAP_NEAR);
    mapArea(mapEvent('Near Warning', 'warning'), MAP_COVERING);
    mapArea(mapEvent('Extra Warning', 'warning'), MAP_NEAR);

    expect(collect(mapEvents($this->anchors))->pluck('event')->all())->toBe(['Near Warning', 'Far Warning', 'Extra Warning']);
});

test('geometry is rounded to four decimals', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), 'POLYGON((-105.123456789 39.1234567891, -105.123456789 40.5, -104.5 40.5, -104.5 39.1234567891, -105.123456789 39.1234567891))');

    $json = json_encode(mapEvents($this->anchors)[0]['geometry']);

    expect($json)->toContain('-105.1235')->not->toContain('-105.123456');
});

test('with no anchors there are no warnings and nothing is queried', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_COVERING);

    expect(mapEvents([]))->toBe([]);
});

test('geometry is simplified: wobble under the tolerance is dropped and a real bend is kept', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), 'POLYGON((-105.5 39.5, -105.3 39.501, -105.1 39.499, -104.9 39.501, -104.7 39.499, -104.5 39.5, -104.45 40.0, -104.5 40.5, -105.5 40.5, -105.5 39.5))');

    $ring = mapEvents($this->anchors)[0]['geometry']['geometries'][0]['coordinates'][0];

    expect($ring)->toHaveCount(6)
        ->and(collect($ring)->contains([-104.45, 40.0]))->toBeTrue();
});

test('geometries carry no per-geometry crs member', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_COVERING);
    mapZoneRow('public_zones', ['state' => 'CO', 'cwa' => 'BOU', 'zone' => '040', 'name' => 'Front Range', 'state_zone' => 'CO040'], MAP_NEAR);
    mapArea(mapEvent('Zone Warning', 'warning'), null, ['ugc_code' => 'COZ040']);

    $geometries = collect(mapEvents($this->anchors))->flatMap(fn (array $event): array => $event['geometry']['geometries']);

    expect($geometries)->toHaveCount(2)
        ->and($geometries->every(fn (array $geometry): bool => ! array_key_exists('crs', $geometry)))->toBeTrue();
});

test('an event without a catalog product takes its family from the VTEC significance letter', function () {
    foreach (['W', 'A', 'Y', 'S'] as $letter) {
        mapArea(ActiveWwa::factory()->create(['product_id' => null, 'vtec_phenomenon' => 'TO', 'vtec_significance' => $letter, 'status' => 'Active', 'is_test' => false]), MAP_COVERING);
    }

    expect(collect(mapEvents($this->anchors))->map(fn (array $event): string => $event['event'].'/'.$event['family'])->all())
        ->toBe(['Tornado Warning/warning', 'Tornado Watch/watch']);
});

test('only the first 50 distinct anchors are considered', function () {
    mapArea(mapEvent('Flash Flood Warning', 'warning'), MAP_COVERING);
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_EDGE_OUT);

    $fillers = fn (int $count): array => collect(range(1, $count))->map(fn (int $i): array => ['lat' => 10 + $i / 10, 'lng' => -150.0])->all();
    $nearEdge = ['lat' => 40.0, 'lng' => -102.0];

    $sixtieth = collect(mapEvents([...$this->anchors, ...$fillers(58), $nearEdge]))->pluck('event')->all();
    $fiftieth = collect(mapEvents([...$this->anchors, ...$fillers(48), $nearEdge]))->pluck('event')->sort()->values()->all();

    expect($sixtieth)->toBe(['Flash Flood Warning'])
        ->and($fiftieth)->toBe(['Flash Flood Warning', 'Tornado Warning']);
});

test('the radius holds at high latitude, where a degree of longitude is short', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), 'POLYGON((-143.6 70.9, -143.6 71.1, -143.2 71.1, -143.2 70.9, -143.6 70.9))');
    mapArea(mapEvent('Severe Thunderstorm Warning', 'warning'), 'POLYGON((-142.9 70.9, -142.9 71.1, -142.5 71.1, -142.5 70.9, -142.9 70.9))');

    expect(collect(mapEvents([['lat' => 71.0, 'lng' => -150.0]]))->pluck('event')->all())->toBe(['Tornado Warning']);
});

test('an event whose areas share one polygon is drawn once, and different polygons are all kept', function () {
    $storm = mapEvent('Tornado Warning', 'warning');
    mapArea($storm, MAP_COVERING, ['ugc_code' => 'XXC001']);
    mapArea($storm, MAP_COVERING, ['ugc_code' => 'XXC002']);
    mapArea($storm, MAP_COVERING, ['ugc_code' => 'XXC003']);
    $zones = mapEvent('Flash Flood Warning', 'warning');
    mapArea($zones, MAP_COVERING, ['ugc_code' => 'XXZ001']);
    mapArea($zones, MAP_NEAR, ['ugc_code' => 'XXZ002']);

    $events = collect(mapEvents($this->anchors))->keyBy('event');

    expect($events['Tornado Warning']['geometry']['geometries'])->toHaveCount(1)
        ->and($events['Flash Flood Warning']['geometry']['geometries'])->toHaveCount(2);
});

test('the exact distance, not just the search box, decides: a box corner beyond the radius is out and one within is in', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), 'POLYGON((-102.6 41.9, -102.6 42.1, -102.4 42.1, -102.4 41.9, -102.6 41.9))');
    mapArea(mapEvent('Severe Thunderstorm Warning', 'warning'), 'POLYGON((-103.2 41.0, -103.2 41.1, -103.1 41.1, -103.1 41.0, -103.2 41.0))');

    expect(collect(mapEvents($this->anchors))->pluck('event')->all())->toBe(['Severe Thunderstorm Warning']);
});
