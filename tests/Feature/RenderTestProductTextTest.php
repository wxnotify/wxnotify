<?php

use App\Actions\Pipeline\ExtractProductMetadata;
use App\Actions\Testing\RenderTestProductText;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    Carbon::setTestNow();
});

function insertPahBoundary(): void
{
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'PAH', 'cwa' => 'PAH', 'city' => 'Paducah', 'st' => 'KY', 'citystate' => 'Paducah KY',
        'lon' => -88.77, 'lat' => 37.06,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-90 36, -87 36, -87 38, -90 38, -90 36))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('renders a warning-shape product with a real-looking VTEC line, UGC line, and hazard bullets', function () {
    Carbon::setTestNow('2026-09-18 00:52:00');
    $product = Product::where('code', 'SV.W')->sole();

    $text = (new RenderTestProductText)($product, [
        'wfo' => 'PAH',
        'vtec_action' => 'NEW',
        'vtec_event_number' => '9001',
        'expires_minutes' => 23,
        'ugc_codes' => ['MOC023', 'MOC181'],
        'hazard' => '60 mph wind gusts.',
        'hazard_source' => 'Radar indicated.',
        'impact' => 'Expect damage to roofs, siding, and trees.',
        'locations_impacted' => null,
        'call_to_action' => 'For your protection move to an interior room on the lowest floor of a building.',
        'polygon_mode' => 'point_radius',
        'polygon_center_lat' => 36.83,
        'polygon_center_lon' => -89.12,
        'polygon_radius_miles' => 8,
        'damage_tags' => ['HAIL THREAT...RADAR INDICATED'],
    ]);

    expect($text)->toContain('/O.NEW.KPAH.SV.W.9001.260918T0052Z-260918T0115Z/')
        ->and($text)->toContain('MOC023-MOC181-')
        ->and($text)->toContain('HAZARD...60 mph wind gusts.')
        ->and($text)->toContain('SOURCE...Radar indicated.')
        ->and($text)->toContain('IMPACT...Expect damage to roofs, siding, and trees.')
        ->and($text)->toContain('PRECAUTIONARY/PREPAREDNESS ACTIONS...')
        ->and($text)->toContain('LAT...LON')
        ->and($text)->toContain('HAIL THREAT...RADAR INDICATED')
        ->and($text)->toContain('$$');
});

test('the motion line reflects the scenario\'s pinned issued_at, not wall-clock time at render', function () {
    // "now" (wall clock) is deliberately different from the scenario's own
    // issued_at, so a motion line built from Carbon::now() instead of the
    // pinned $issuedAt would show 0500Z here, not 0052Z.
    Carbon::setTestNow('2026-09-18 05:00:00');
    $product = Product::where('code', 'SV.W')->sole();

    $text = (new RenderTestProductText)($product, [
        'issued_at' => '2026-09-18 00:52:00',
        'wfo' => 'PAH',
        'vtec_action' => 'NEW',
        'vtec_event_number' => '9003',
        'expires_minutes' => 23,
        'ugc_codes' => ['MOC023'],
        'hazard' => '60 mph wind gusts.',
        'hazard_source' => 'Radar indicated.',
        'impact' => 'Expect damage to roofs, siding, and trees.',
        'locations_impacted' => null,
        'call_to_action' => 'Take cover now.',
        'polygon_mode' => 'point_radius',
        'polygon_center_lat' => 36.83,
        'polygon_center_lon' => -89.12,
        'polygon_radius_miles' => 8,
        'damage_tags' => [],
        'motion_direction_degrees' => 270,
        'motion_speed_kt' => 25,
    ]);

    expect($text)->toContain('TIME...MOT...LOC 0052Z 270DEG 25KT')
        ->and($text)->not->toContain('0500Z');
});

test('renders the office\'s real city/state name when a cwa_boundaries row exists for the WFO', function () {
    Carbon::setTestNow('2026-09-18 00:52:00');
    insertPahBoundary();
    $product = Product::where('code', 'SV.W')->sole();

    $text = (new RenderTestProductText)($product, [
        'wfo' => 'PAH',
        'vtec_action' => 'NEW',
        'vtec_event_number' => '9004',
        'expires_minutes' => 23,
        'ugc_codes' => ['MOC023'],
        'hazard' => '60 mph wind gusts.',
        'hazard_source' => 'Radar indicated.',
        'impact' => 'Expect damage to roofs, siding, and trees.',
        'locations_impacted' => null,
        'call_to_action' => 'Take cover now.',
        'polygon_mode' => 'point_radius',
        'polygon_center_lat' => 36.83,
        'polygon_center_lon' => -89.12,
        'polygon_radius_miles' => 8,
        'damage_tags' => [],
    ]);

    expect($text)->toContain('National Weather Service Paducah KY')
        ->and($text)->not->toContain('National Weather Service PAH');
});

test('a warning-shape render round-trips through the real extraction pipeline correctly', function () {
    Carbon::setTestNow('2026-09-18 00:52:00');
    $product = Product::where('code', 'FF.W')->sole();

    $text = (new RenderTestProductText)($product, [
        'wfo' => 'TWC',
        'vtec_action' => 'NEW',
        'vtec_event_number' => '9002',
        'expires_minutes' => 60,
        'ugc_codes' => ['AZC021'],
        'hazard' => 'Flash flooding caused by thunderstorms.',
        'hazard_source' => 'Radar indicated.',
        'impact' => 'Flash flooding of small creeks and streams.',
        'locations_impacted' => null,
        'call_to_action' => 'Turn around, don\'t drown.',
        'polygon_mode' => 'point_radius',
        'polygon_center_lat' => 32.22,
        'polygon_center_lon' => -110.97,
        'polygon_radius_miles' => 5,
        'damage_tags' => [],
    ]);

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['vtec_office'])->toBe('KTWC')
        ->and($metadata['vtec_phenomenon'])->toBe('FF')
        ->and($metadata['vtec_significance'])->toBe('W')
        ->and($metadata['vtec_event_number'])->toBe('9002')
        ->and($metadata['ugc_codes'])->toBe(['AZC021'])
        ->and($metadata['hazard'])->toBe('Flash flooding caused by thunderstorms.')
        ->and($metadata['hazard_source'])->toBe('Radar indicated.')
        ->and($metadata['impact'])->toBe('Flash flooding of small creeks and streams.')
        ->and($metadata['polygon'])->toHaveCount(4);
});

test('renders a discussion-shape product whose polygon round-trips through the fixed concatenated-format parser', function () {
    Carbon::setTestNow('2026-09-18 23:55:00');
    $product = Product::where('code', 'SPC.MD')->sole();

    $text = (new RenderTestProductText)($product, [
        'wfo' => 'WNS',
        'discussion_number' => '9001',
        'areas_affected' => 'CENTRAL/SOUTHERN AZ',
        'ugc_codes' => ['AZZ000'],
        'discussion_text' => 'Isolated storms are possible this evening.',
        'concerning' => 'SEVERE POTENTIAL...WATCH UNLIKELY',
        'polygon_mode' => 'point_radius',
        'polygon_center_lat' => 33.5,
        'polygon_center_lon' => -112.5,
        'polygon_radius_miles' => 20,
        'attn_wfos' => ['TWC', 'PSR'],
    ]);

    $metadata = (new ExtractProductMetadata)($text, 'swomcd');

    expect($metadata['product_id'])->toBe($product->id)
        ->and($metadata['polygon'])->toHaveCount(4)
        ->and($text)->toContain('11:55 PM');
});

test('renders a plain-text-shape product with no VTEC line and a UGC line when the product has one', function () {
    Carbon::setTestNow('2026-09-18 18:22:00');
    $product = Product::where('code', 'SPS')->sole();

    $text = (new RenderTestProductText)($product, [
        'wfo' => 'TOP',
        'ugc_codes' => ['KSZ038'],
        'body_text' => 'Strong thunderstorms are moving through the area.',
    ]);

    expect($text)->not->toContain('/NEW.')
        ->and($text)->toContain('KSZ038-')
        ->and($text)->toContain('Strong thunderstorms are moving through the area.');
});

test('renders a plain-text-shape product with no UGC line when the product has none (e.g. AFD)', function () {
    Carbon::setTestNow('2026-09-18 03:00:00');
    $product = Product::where('code', 'AFD')->sole();

    $text = (new RenderTestProductText)($product, [
        'wfo' => 'PIH',
        'ugc_codes' => [],
        'body_text' => 'Clear skies expected through the week.',
    ]);

    expect($text)->not->toContain('-')  // no UGC line rendered at all
        ->and($text)->toContain('AREA FORECAST DISCUSSION');
});

test('a discussion omits the AREAS AFFECTED and CONCERNING lines when those inputs are empty', function () {
    Carbon::setTestNow('2026-09-18 23:55:00');
    $product = Product::where('code', 'SPC.MD')->sole();

    $text = (new RenderTestProductText)($product, [
        'wfo' => 'WNS',
        'ugc_codes' => ['AZZ000'],
        'discussion_text' => 'Isolated storms are possible.',
        'polygon_mode' => 'point_radius',
        'polygon_center_lat' => 33.5,
        'polygon_center_lon' => -112.5,
        'polygon_radius_miles' => 20,
    ]);

    expect($text)->not->toContain('AREAS AFFECTED')
        ->and($text)->not->toContain('CONCERNING')
        ->and($text)->toContain('DISCUSSION...Isolated storms');
});

test('a rendered warning inside a Livewire-marked Blade compile contains no morph markers and no bogus motion line', function () {
    $product = Product::where('code', 'TO.W')->sole();

    $text = (new RenderTestProductText)($product, [
        'wfo' => 'TWC', 'expires_minutes' => 30, 'ugc_codes' => ['AZZ019'],
        'hazard' => 'Tornado.', 'hazard_source' => 'Radar indicated.', 'impact' => 'Damage.', 'call_to_action' => 'Take cover.',
        'locations_impacted' => 'Tucson', 'damage_tags' => ['tornado' => 'RADAR INDICATED'],
        'motion_direction_degrees' => 0, 'motion_speed_kt' => 0,
    ]);

    expect($text)->not->toContain('<!--')
        ->and($text)->not->toContain('TIME...MOT...LOC')
        ->and($text)->toContain("Locations impacted include...\n  Tucson");
});

test('a motion line is only rendered with a positive speed and a known location', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $base = [
        'wfo' => 'TWC', 'expires_minutes' => 30, 'ugc_codes' => ['AZZ019'],
        'hazard' => 'T.', 'hazard_source' => 'R.', 'impact' => 'D.', 'call_to_action' => 'C.',
        'motion_direction_degrees' => 250, 'motion_speed_kt' => 30,
    ];

    expect((new RenderTestProductText)($product, $base))->not->toContain('TIME...MOT...LOC')
        ->and((new RenderTestProductText)($product, $base + ['polygon_center_lat' => 32.5, 'polygon_center_lon' => -110.5]))
        ->toContain('250DEG 30KT 3250 11050');
});
