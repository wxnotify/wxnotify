<?php

use App\Actions\Pipeline\ParseBulletinSegments;
use App\Models\Product;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function bulletinSegmentsFixture(string $name): string
{
    return file_get_contents(base_path("database/product_examples/bulletins/{$name}.txt"));
}

test('a Hurricane Local Statement gives one segment per zone section and VTEC line, in bulletin order', function () {
    $segments = app(ParseBulletinSegments::class)(bulletinSegmentsFixture('TCV_MOB'));

    // Seven sections carry a Hurricane Watch and a Storm Surge Watch, five only the Hurricane Watch.
    expect($segments)->toHaveCount(19)
        ->and(collect($segments)->pluck('vtec_event_number')->unique()->all())->toBe(['1009'])
        ->and(collect($segments)->take(4)->map(fn (array $s): string => $s['vtec_phenomenon'].'.'.$s['vtec_significance'].' '.implode(',', $s['ugc_codes']))->all())
        ->toBe(['HU.A ALZ266', 'SS.A ALZ266', 'HU.A ALZ265', 'SS.A ALZ265']);
});

test('the zone Destin sits in has a Hurricane Watch segment of its own', function () {
    $destin = collect(app(ParseBulletinSegments::class)(bulletinSegmentsFixture('TCV_MOB')))
        ->filter(fn (array $s): bool => $s['ugc_codes'] === ['FLZ206']);

    expect($destin->map(fn (array $s): string => $s['vtec_phenomenon'].'.'.$s['vtec_significance'])->values()->all())->toBe(['HU.A', 'SS.A']);
});

test('a segment links the catalog product, and none for an event the catalog lacks', function () {
    $coastalFlood = Product::where('code', 'CF.Y')->sole();
    // The catalog is a curated subset: it has no Rip Current Statement (RP.S).
    expect(Product::where('code', 'RP.S')->exists())->toBeFalse();

    $segments = collect(app(ParseBulletinSegments::class)(bulletinSegmentsFixture('CFW_JAX')));

    expect($segments->first(fn (array $s): bool => $s['vtec_phenomenon'] === 'CF')['product_id'])->toBe($coastalFlood->id)
        ->and($segments->first(fn (array $s): bool => $s['vtec_phenomenon'] === 'RP')['product_id'])->toBeNull();
});

test('a section with two VTEC lines gives two segments sharing its zones, and the next section its own zones', function () {
    Carbon::setTestNow('2026-10-07T16:00:00Z');

    $segments = app(ParseBulletinSegments::class)(bulletinSegmentsFixture('MWW_MOB'));

    expect($segments)->toHaveCount(3)
        ->and($segments[0]['ugc_codes'])->toBe(['GMZ650', 'GMZ655', 'GMZ670', 'GMZ675'])
        ->and($segments[1]['ugc_codes'])->toBe(['GMZ650', 'GMZ655', 'GMZ670', 'GMZ675'])
        ->and([$segments[0]['vtec_phenomenon'], $segments[0]['vtec_action'], $segments[0]['vtec_event_number']])->toBe(['HU', 'NEW', '1009'])
        ->and([$segments[1]['vtec_phenomenon'], $segments[1]['vtec_action'], $segments[1]['vtec_event_number']])->toBe(['SC', 'CON', '0031'])
        ->and($segments[1]['vtec_begin'])->toBeNull()
        ->and($segments[1]['vtec_end'])->toEqual(Carbon::parse('2026-10-09T08:00:00Z'))
        ->and($segments[0]['vtec_begin'])->toEqual(Carbon::parse('2026-10-07T15:38:00Z'))
        ->and($segments[0]['vtec_end'])->toBeNull()
        ->and($segments[2]['ugc_codes'])->toBe(['GMZ630', 'GMZ631', 'GMZ632', 'GMZ633', 'GMZ634', 'GMZ635', 'GMZ636']);
});

test('a bulletin whose zone line is a full-code range gets its zones', function () {
    $segments = app(ParseBulletinSegments::class)(bulletinSegmentsFixture('NPW_FGZ'));

    expect($segments)->toHaveCount(1)
        ->and($segments[0]['vtec_phenomenon'].'.'.$segments[0]['vtec_significance'])->toBe('WI.Y')
        ->and($segments[0]['vtec_action'])->toBe('COR')
        ->and($segments[0]['ugc_codes'])->toHaveCount(19);
});

test('a Coastal Hazard Message keeps each section\'s own events and zones', function () {
    $segments = app(ParseBulletinSegments::class)(bulletinSegmentsFixture('CFW_JAX'));

    expect(collect($segments)->map(fn (array $s): string => $s['vtec_phenomenon'].'.'.$s['vtec_significance'].'#'.$s['vtec_event_number'])->all())
        ->toBe(['CF.Y#0003', 'RP.S#0032', 'CF.Y#0004', 'CF.Y#0003', 'RP.S#0032'])
        ->and($segments[0]['ugc_codes'])->toBe(['FLZ125'])
        ->and($segments[2]['ugc_codes'])->toBe(['FLZ132', 'FLZ137', 'FLZ325', 'FLZ633']);
});

test('each section keeps its own polygon, and a section without one has none', function () {
    $text = <<<'TEXT'

    WGUS51 KOKX 061800
    FFWOKX

    NYZ069-070-081200-
    /O.NEW.KOKX.FF.W.0042.260906T1800Z-260906T2100Z/
    Flash Flood Warning for Westchester.

    LAT...LON 4031 10526 4040 10501 4012 10490 3995 10510

    $$

    NYZ071-081200-
    /O.NEW.KOKX.FF.W.0043.260906T1800Z-260906T2100Z/
    Flash Flood Warning for Putnam.

    $$
    TEXT;

    $segments = app(ParseBulletinSegments::class)($text);

    expect($segments)->toHaveCount(2)
        ->and($segments[0]['polygon'])->toHaveCount(4)
        ->and($segments[0]['polygon'][0])->toEqual(['lat' => 40.31, 'lon' => -105.26])
        ->and($segments[0]['ugc_codes'])->toBe(['NYZ069', 'NYZ070'])
        ->and($segments[1]['polygon'])->toBeNull()
        ->and($segments[1]['ugc_codes'])->toBe(['NYZ071']);
});

test('a section with a VTEC line but no readable zone line still gives a segment, with no zones', function () {
    $segments = app(ParseBulletinSegments::class)("Some header\n/O.NEW.KOKX.FF.W.0042.260906T1800Z-260906T2100Z/\nNo zones here.\n\n\$\$\n");

    expect($segments)->toHaveCount(1)
        ->and($segments[0]['ugc_codes'])->toBeNull();
});

test('text without a VTEC line, or a section with zones but no VTEC line, gives no segments', function () {
    expect(app(ParseBulletinSegments::class)('Area Forecast Discussion. No VTEC here.'))->toBe([])
        ->and(app(ParseBulletinSegments::class)("NYZ069-081200-\nNo VTEC line in this section.\n\n\$\$\n"))->toBe([]);
});
