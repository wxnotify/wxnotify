<?php

use App\Actions\Map\GetLiveWarnings;
use App\Actions\Pipeline\ReplayIngestedProduct;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProduct;
use App\Support\LiveWarningKinds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00');
    $this->box = ['south' => 38.0, 'west' => -107.0, 'north' => 42.0, 'east' => -101.0];
});

afterEach(function () {
    Carbon::setTestNow();
});

const LIVE_INSIDE = 'POLYGON((-105.5 39.5, -105.5 40.5, -104.5 40.5, -104.5 39.5, -105.5 39.5))';      // Denver, inside the box
const LIVE_OUTSIDE = 'POLYGON((-98.5 39.5, -98.5 40.5, -97.5 40.5, -97.5 39.5, -98.5 39.5))';         // Kansas, east of the box
const LIVE_STRADDLING = 'POLYGON((-101.5 39.5, -101.5 40.5, -100.5 40.5, -100.5 39.5, -101.5 39.5))'; // over the east edge

function liveWarningsEvent(string $phenomenon, string $significance = 'W', array $attributes = []): ActiveWwa
{
    return ActiveWwa::factory()->create([
        'product_id' => null,
        'vtec_phenomenon' => $phenomenon,
        'vtec_significance' => $significance,
        'status' => 'Active',
        'is_test' => false,
        ...$attributes,
    ]);
}

function liveWarningsArea(ActiveWwa $wwa, ?string $polygonWkt = LIVE_INSIDE, array $attributes = []): ActiveWwaArea
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

function liveWarnings(array $bounds, string $kind = LiveWarningKinds::SHORT_FUSE): array
{
    return (new GetLiveWarnings)($bounds, $kind);
}

function liveWarningsNames(array $result): array
{
    return collect($result['events'])->pluck('event')->sort()->values()->all();
}

/**
 * The bindings of the one query that reads active_wwa_areas while $callback runs.
 *
 * @return list<list<mixed>>
 */
function liveWarningsAreaQueries(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'active_wwa_areas'));
    DB::disableQueryLog();

    return $queries->pluck('bindings')->values()->all();
}

test('the seven short-fuse warnings in the box are returned and other events are not', function () {
    foreach (['TO', 'SV', 'FF', 'MA', 'EW', 'SQ', 'DS'] as $phenomenon) {
        liveWarningsArea(liveWarningsEvent($phenomenon));
    }

    liveWarningsArea(liveWarningsEvent('FL'));
    liveWarningsArea(liveWarningsEvent('WI', 'Y'));
    liveWarningsArea(liveWarningsEvent('TO', 'A'));

    expect(liveWarningsNames(liveWarnings($this->box)))->toBe([
        'Dust Storm Warning', 'Extreme Wind Warning', 'Flash Flood Warning', 'Severe Thunderstorm Warning',
        'Snow Squall Warning', 'Special Marine Warning', 'Tornado Warning',
    ]);
});

test('the watches kind returns only tornado and severe thunderstorm watches', function () {
    liveWarningsArea(liveWarningsEvent('TO', 'A'));
    liveWarningsArea(liveWarningsEvent('SV', 'A'));
    liveWarningsArea(liveWarningsEvent('FF', 'A'));
    liveWarningsArea(liveWarningsEvent('TO', 'W'));

    expect(liveWarningsNames(liveWarnings($this->box, LiveWarningKinds::WATCHES)))->toBe(['Severe Thunderstorm Watch', 'Tornado Watch']);
});

test('an event outside the box, a lapsed or cancelled area, an inactive event and a test event are left out', function () {
    liveWarningsArea(liveWarningsEvent('TO'), LIVE_STRADDLING, ['ugc_code' => 'XXZ001']);
    liveWarningsArea(liveWarningsEvent('SV'), LIVE_OUTSIDE);
    liveWarningsArea(liveWarningsEvent('FF'), LIVE_INSIDE, ['expires' => now()->subMinute()]);
    liveWarningsArea(liveWarningsEvent('MA'), LIVE_INSIDE, ['status' => 'Cancelled']);
    liveWarningsArea(liveWarningsEvent('EW', 'W', ['status' => 'Cancelled']));
    liveWarningsArea(liveWarningsEvent('SQ', 'W', ['is_test' => true]));

    expect(liveWarningsNames(liveWarnings($this->box)))->toBe(['Tornado Warning']);
});

test('a replayed tornado warning never appears', function () {
    $original = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'cccc' => 'kbou', 'ttaaii' => 'wfus53', 'awipsid' => 'torbou', 'wmo_ddhhmm' => '021450',
        'text' => "WFUS53 KBOU 021450\nTORBOU\nCOZ039-021550-\n/O.NEW.KBOU.TO.W.0009.261002T1450Z-261002T1550Z/\n\nBULLETIN\nTornado Warning\n\nLAT...LON 3950 10550 4050 10550 4050 10450 3950 10450\n\n\$\$",
        'source' => 'emwin',
        'wmo_header_key' => 'wfus53 kbou 021450 torbou',
    ]));

    app(ReplayIngestedProduct::class)($original);

    expect(liveWarnings($this->box))->toBe(['events' => [], 'truncated' => false]);
});

test('the payload has the map warning shape and a storm polygon repeated on its zones is drawn once', function () {
    $product = IngestedProduct::factory()->create(['view_slug' => 'abc123']);
    $storm = liveWarningsEvent('TO', 'W', ['headline' => 'Tornado Warning issued', 'latest_ingested_product_id' => $product->id]);
    liveWarningsArea($storm, LIVE_INSIDE, ['ugc_code' => 'COC001']);
    liveWarningsArea($storm, LIVE_INSIDE, ['ugc_code' => 'COC005']);
    liveWarningsArea($storm, LIVE_STRADDLING, ['ugc_code' => 'COC009']);

    $event = liveWarnings($this->box)['events'][0];

    expect($event)->toMatchArray([
        'id' => $storm->id, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000',
        'headline' => 'Tornado Warning issued', 'view_url' => $product->viewUrl(),
        'expires' => '2026-10-02T16:00:00+00:00',
    ])
        ->and(array_keys($event))->toBe(['id', 'event', 'family', 'color', 'headline', 'expires', 'view_url', 'geometry'])
        ->and($event['geometry']['type'])->toBe('GeometryCollection')
        ->and($event['geometry']['geometries'])->toHaveCount(2);
});

test('a zone-only warning takes its geometry from the zone it names', function () {
    DB::table('public_zones')->insert([
        'state' => 'CO', 'cwa' => 'BOU', 'zone' => '040', 'name' => 'Front Range', 'state_zone' => 'CO040', 'lon' => 0, 'lat' => 0,
        'boundary' => DB::raw("ST_Multi(ST_SetSRID(ST_GeomFromText('".LIVE_INSIDE."'), 4269))"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    liveWarningsArea(liveWarningsEvent('SQ'), null, ['ugc_code' => 'COZ040']);
    liveWarningsArea(liveWarningsEvent('DS'), null, ['ugc_code' => 'COZ999']);

    $events = liveWarnings($this->box)['events'];

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('Snow Squall Warning')
        ->and($events[0]['geometry']['geometries'])->toHaveCount(1);
});

test('events are ordered by expiry, soonest first and open-ended last', function () {
    liveWarningsArea(liveWarningsEvent('TO'), LIVE_INSIDE, ['expires' => null]);
    liveWarningsArea(liveWarningsEvent('SV'), LIVE_INSIDE, ['expires' => now()->addHours(2)]);
    liveWarningsArea(liveWarningsEvent('FF'), LIVE_INSIDE, ['expires' => now()->addMinutes(20)]);

    expect(collect(liveWarnings($this->box)['events'])->pluck('event')->all())
        ->toBe(['Flash Flood Warning', 'Severe Thunderstorm Warning', 'Tornado Warning']);
});

test('more events than the cap are cut to the soonest-ending ones and marked truncated', function () {
    config(['wxnotify.map.max_live_warnings' => 2]);
    liveWarningsArea(liveWarningsEvent('TO'), LIVE_INSIDE, ['expires' => now()->addHours(3)]);
    liveWarningsArea(liveWarningsEvent('SV'), LIVE_INSIDE, ['expires' => now()->addHour()]);
    liveWarningsArea(liveWarningsEvent('FF'), LIVE_INSIDE, ['expires' => now()->addHours(2)]);

    $result = liveWarnings($this->box);

    expect(collect($result['events'])->pluck('event')->all())->toBe(['Severe Thunderstorm Warning', 'Flash Flood Warning'])
        ->and($result['truncated'])->toBeTrue();
});

test('exactly the cap is not truncated', function () {
    config(['wxnotify.map.max_live_warnings' => 2]);
    liveWarningsArea(liveWarningsEvent('TO'));
    liveWarningsArea(liveWarningsEvent('SV'));

    $result = liveWarnings($this->box);

    expect($result['events'])->toHaveCount(2)
        ->and($result['truncated'])->toBeFalse();
});

test('the cap defaults to 150', function () {
    expect(config('wxnotify.map.max_live_warnings'))->toBe(150);
});

test('a huge box is cut to 120 by 60 degrees around its centre, in one query, and leaves out what lies beyond', function () {
    liveWarningsArea(liveWarningsEvent('TO'));
    liveWarningsArea(liveWarningsEvent('SV'), 'POLYGON((-150.5 74.5, -150.5 75.5, -149.5 75.5, -149.5 74.5, -150.5 74.5))');

    $result = null;
    $queries = liveWarningsAreaQueries(function () use (&$result) {
        $result = liveWarnings(['south' => 0.0, 'west' => -170.0, 'north' => 80.0, 'east' => -40.0]);
    });

    expect(liveWarningsNames($result))->toBe(['Tornado Warning'])
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toContain(-165.0, 10.0, -45.0, 70.0);
});

test('invalid bounds are clamped and still queried within the limits', function (array $bounds, array $envelope) {
    $queries = liveWarningsAreaQueries(fn () => liveWarnings($bounds));

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain(...$envelope);
})->with([
    'non-finite' => [['south' => NAN, 'west' => -INF, 'north' => INF, 'east' => NAN], [-60.0, -30.0, 60.0, 30.0]],
    'missing keys' => [[], [-60.0, -30.0, 60.0, 30.0]],
    'beyond the poles and the antimeridian' => [['south' => -100.0, 'west' => -200.0, 'north' => -80.0, 'east' => -170.0], [-180.0, -90.0, -170.0, -80.0]],
    'south above north' => [['south' => 42.0, 'west' => -107.0, 'north' => 38.0, 'east' => -101.0], [-107.0, 38.0, -101.0, 42.0]],
    'across the antimeridian keeps the west edge and stops at 180' => [['south' => 50.0, 'west' => 170.0, 'north' => 60.0, 'east' => -170.0], [170.0, 50.0, 180.0, 60.0]],
]);

test('south above north is read as the box it names', function () {
    liveWarningsArea(liveWarningsEvent('TO'));

    expect(liveWarningsNames(liveWarnings(['south' => 42.0, 'west' => -107.0, 'north' => 38.0, 'east' => -101.0])))->toBe(['Tornado Warning']);
});

test('snow squall and dust storm warnings have their own colours', function () {
    liveWarningsArea(liveWarningsEvent('SQ'));
    liveWarningsArea(liveWarningsEvent('DS'));

    expect(collect(liveWarnings($this->box)['events'])->pluck('color', 'event')->all())
        ->toEqual(['Snow Squall Warning' => '#c71585', 'Dust Storm Warning' => '#ffe4c4']);
});

test('the short-fuse kind names its seven warnings for the filter chips', function () {
    expect(LiveWarningKinds::eventNames(LiveWarningKinds::SHORT_FUSE))->toBe([
        'Tornado Warning', 'Severe Thunderstorm Warning', 'Flash Flood Warning', 'Special Marine Warning',
        'Extreme Wind Warning', 'Snow Squall Warning', 'Dust Storm Warning',
    ])
        ->and(LiveWarningKinds::eventNames(LiveWarningKinds::WATCHES))->toBe(['Tornado Watch', 'Severe Thunderstorm Watch']);
});

const LIVE_SECOND = 'POLYGON((-103.5 39.5, -103.5 40.5, -102.5 40.5, -102.5 39.5, -103.5 39.5))'; // also inside, east of Denver
const LIVE_THIRD = 'POLYGON((-106.5 38.2, -106.5 39.2, -105.5 39.2, -105.5 38.2, -106.5 38.2))';  // also inside, south-west

function liveWarningsWatchRow(string $office, string $number = '0679', array $attributes = []): ActiveWwa
{
    return liveWarningsEvent('TO', 'A', ['vtec_office' => $office, 'vtec_event_number' => $number, ...$attributes]);
}

function liveWarningsWatches(array $bounds): array
{
    return liveWarnings($bounds, LiveWarningKinds::WATCHES);
}

test('a watch held by two offices and the SPC row is one event without the SPC-only counties', function () {
    $cae = liveWarningsWatchRow('KCAE');
    liveWarningsArea($cae, LIVE_INSIDE, ['ugc_code' => 'COC001']);
    $chs = liveWarningsWatchRow('KCHS');
    liveWarningsArea($chs, LIVE_SECOND, ['ugc_code' => 'COC003']);
    $spc = liveWarningsWatchRow('KWNS');
    liveWarningsArea($spc, LIVE_INSIDE, ['ugc_code' => 'COC001']);
    liveWarningsArea($spc, LIVE_SECOND, ['ugc_code' => 'COC003']);
    liveWarningsArea($spc, LIVE_THIRD, ['ugc_code' => 'COC005']);

    $events = liveWarningsWatches($this->box)['events'];

    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe($cae->id)
        ->and($events[0]['event'])->toBe('Tornado Watch')
        ->and($events[0]['geometry']['geometries'])->toHaveCount(2)
        ->and(json_encode($events[0]['geometry']))->not->toContain('-106.5');
});

test('a watch with only its SPC row is drawn from it', function () {
    $spc = liveWarningsWatchRow('KWNS', '0700');
    liveWarningsArea($spc, LIVE_INSIDE, ['ugc_code' => 'COC001']);
    liveWarningsArea($spc, LIVE_THIRD, ['ugc_code' => 'COC005']);

    $events = liveWarningsWatches($this->box)['events'];

    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe($spc->id)
        ->and($events[0]['geometry']['geometries'])->toHaveCount(2);
});

test('a county its office cancelled is not drawn even while the SPC row still holds it', function () {
    $office = liveWarningsWatchRow('KMPX', '0698');
    liveWarningsArea($office, LIVE_INSIDE, ['ugc_code' => 'COC001']);
    liveWarningsArea($office, LIVE_THIRD, ['ugc_code' => 'COC005', 'status' => 'Cancelled']);
    $spc = liveWarningsWatchRow('KWNS', '0698');
    liveWarningsArea($spc, LIVE_INSIDE, ['ugc_code' => 'COC001']);
    liveWarningsArea($spc, LIVE_THIRD, ['ugc_code' => 'COC005']);

    $events = liveWarningsWatches($this->box)['events'];

    expect($events)->toHaveCount(1)
        ->and($events[0]['geometry']['geometries'])->toHaveCount(1)
        ->and(json_encode($events[0]['geometry']))->not->toContain('-106.5');
});

test('a watch whose offices have all cleared it is not revived from the SPC row', function () {
    $office = liveWarningsWatchRow('KJAX', '0678', ['status' => 'Cancelled']);
    liveWarningsArea($office, LIVE_INSIDE, ['ugc_code' => 'COC001', 'status' => 'Cancelled']);
    liveWarningsArea(liveWarningsWatchRow('KWNS', '0678'), LIVE_INSIDE, ['ugc_code' => 'COC001']);

    expect(liveWarningsWatches($this->box)['events'])->toBe([]);
});

test('a merged watch keeps the lowest office row id whichever row arrived first or is in view, the latest row\'s headline and the end over all its rows', function () {
    $spc = liveWarningsWatchRow('KWNS', '0679', ['headline' => 'SPC issued', 'updated_at' => now()->subMinutes(30)]);
    liveWarningsArea($spc, LIVE_INSIDE, ['ugc_code' => 'COC001']);
    $later = liveWarningsWatchRow('KCHS', '0679', ['headline' => 'Charleston extended', 'updated_at' => now()->subMinutes(5)]);
    liveWarningsArea($later, LIVE_SECOND, ['ugc_code' => 'COC003', 'expires' => now()->addHours(4)]);
    $earlier = liveWarningsWatchRow('KCAE', '0679', ['headline' => 'Columbia', 'updated_at' => now()->subMinutes(20)]);
    liveWarningsArea($earlier, LIVE_INSIDE, ['ugc_code' => 'COC001', 'expires' => now()->addHours(2)]);

    $first = liveWarningsWatches($this->box)['events'];
    $second = liveWarningsWatches(['south' => 38.0, 'west' => -106.0, 'north' => 42.0, 'east' => -104.0])['events'];

    expect($first)->toHaveCount(1)
        ->and($first[0]['id'])->toBe($later->id)
        ->and($first[0]['headline'])->toBe('Charleston extended')
        ->and($first[0]['expires'])->toBe('2026-10-02T19:00:00+00:00')
        ->and($second)->toHaveCount(1)
        ->and($second[0]['id'])->toBe($later->id)
        ->and($second[0]['expires'])->toBe('2026-10-02T19:00:00+00:00')
        ->and($second[0]['geometry']['geometries'])->toHaveCount(1);
});

test('the cap counts merged watches, not their rows', function () {
    config(['wxnotify.map.max_live_warnings' => 1]);
    foreach (['KCAE', 'KCHS', 'KFFC'] as $office) {
        liveWarningsArea(liveWarningsWatchRow($office), LIVE_INSIDE, ['ugc_code' => 'COC001']);
    }

    $result = liveWarningsWatches($this->box);

    expect($result['events'])->toHaveCount(1)
        ->and($result['truncated'])->toBeFalse();
});

test('an event ends when its last area ends, inside the box or not, wherever the box is', function () {
    $wwa = liveWarningsEvent('FF');
    liveWarningsArea($wwa, LIVE_INSIDE, ['ugc_code' => 'COZ001', 'expires' => '2026-10-02 14:00:00']);
    liveWarningsArea($wwa, LIVE_OUTSIDE, ['ugc_code' => 'KSZ001', 'expires' => '2026-10-02 16:00:00']);
    Carbon::setTestNow('2026-10-02 13:00:00');

    $here = liveWarnings($this->box)['events'];
    $there = liveWarnings(['south' => 38.0, 'west' => -100.0, 'north' => 42.0, 'east' => -96.0])['events'];

    expect($here[0]['expires'])->toBe('2026-10-02T16:00:00+00:00')
        ->and($here[0]['geometry']['geometries'])->toHaveCount(1)
        ->and($there[0]['expires'])->toBe('2026-10-02T16:00:00+00:00');
});

test('short-fuse warnings of one VTEC number from two offices stay two events', function () {
    liveWarningsArea(liveWarningsEvent('TO', 'W', ['vtec_office' => 'KBOU', 'vtec_event_number' => '0009']));
    liveWarningsArea(liveWarningsEvent('TO', 'W', ['vtec_office' => 'KPUB', 'vtec_event_number' => '0009']), LIVE_SECOND);

    expect(liveWarnings($this->box)['events'])->toHaveCount(2);
});
