<?php

use App\Actions\Pipeline\ApplySegmentToCoverage;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 16:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Applies one segment (unsaved, like the ones SyncActiveWwa builds for a bulletin with none) of a bulletin
 * received at the given time. $segment overrides the Hurricane Watch defaults.
 */
function coverageApply(array $segment, array $bulletin = [], array $event = []): ActiveWwa
{
    $bulletinModel = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['format' => 'text', 'received_at' => now(), 'is_test' => false, ...$bulletin]));
    $segmentModel = new IngestedProductSegment([
        'position' => 0, 'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009',
        'vtec_year' => 2026, 'vtec_action' => 'NEW', 'ugc_codes' => ['FLZ206'], ...$segment,
    ]);

    return app(ApplySegmentToCoverage::class)($bulletinModel, $segmentModel, $event);
}

function coverageAreaOf(ActiveWwa $wwa, string $zone): ?ActiveWwaArea
{
    return $wwa->areas()->where('ugc_code', $zone)->first();
}

function coveragePolygonText(ActiveWwaArea $area): ?string
{
    return DB::selectOne('SELECT ST_AsText(polygon) AS wkt FROM active_wwa_areas WHERE id = ?', [$area->id])->wkt;
}

const COVERAGE_SQUARE_A = 'POLYGON((-87 30,-87 31,-86 31,-86 30,-87 30))';
const COVERAGE_SQUARE_B = 'POLYGON((-85 30,-85 31,-84 31,-84 30,-85 30))';

function coveragePoints(string $wkt): array
{
    preg_match_all('/(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)/', $wkt, $matches, PREG_SET_ORDER);

    return array_map(fn (array $m): array => ['lat' => (float) $m[2], 'lon' => (float) $m[1]], array_slice($matches, 0, -1));
}

test('a NEW segment creates the event with one Active row per zone and derives the event from them', function () {
    $wwa = coverageApply([
        'ugc_codes' => ['FLZ206', 'FLZ203'], 'vtec_begin' => Carbon::parse('2026-10-07 15:38:00'), 'vtec_end' => Carbon::parse('2026-10-09 08:00:00'),
    ], event: ['source' => 'text', 'latest_ingested_product_id' => null]);

    expect($wwa->status)->toBe('Active')
        ->and($wwa->effective)->toEqual(Carbon::parse('2026-10-07 15:38:00'))
        ->and($wwa->expires)->toEqual(Carbon::parse('2026-10-09 08:00:00'))
        ->and($wwa->source)->toBe('text')
        ->and($wwa->ugc_codes)->toBe(['FLZ206', 'FLZ203'])
        ->and($wwa->areas()->pluck('ugc_code')->sort()->values()->all())->toBe(['FLZ203', 'FLZ206'])
        ->and($wwa->areas()->pluck('status')->unique()->all())->toBe(['Active'])
        ->and(coverageAreaOf($wwa, 'FLZ206')->expires)->toEqual(Carbon::parse('2026-10-09 08:00:00'));
});

test('the segment\'s catalog product and the bulletin\'s test flag are written on the event', function () {
    $hurricaneWatch = Product::where('code', 'HU.A')->sole();

    $wwa = coverageApply(['product_id' => $hurricaneWatch->id], ['is_test' => true]);

    expect($wwa->product_id)->toBe($hurricaneWatch->id)
        ->and($wwa->is_test)->toBeTrue();
});

test('zones arriving in separate segments accumulate on one event instead of replacing each other', function () {
    coverageApply(['ugc_codes' => ['FLZ206']]);
    coverageApply(['ugc_codes' => ['FLZ203']]);
    $wwa = coverageApply(['ugc_codes' => ['ALZ266', 'ALZ265']]);

    expect(ActiveWwa::count())->toBe(1)
        ->and($wwa->areas()->pluck('ugc_code')->sort()->values()->all())->toBe(['ALZ265', 'ALZ266', 'FLZ203', 'FLZ206']);
});

test('the event end is the latest end of its active zones, and none when any active zone has none', function () {
    coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_end' => Carbon::parse('2026-10-08 12:00:00')]);
    $withEnds = coverageApply(['ugc_codes' => ['FLZ203'], 'vtec_end' => Carbon::parse('2026-10-09 08:00:00')]);
    expect($withEnds->expires)->toEqual(Carbon::parse('2026-10-09 08:00:00'));

    $openEnded = coverageApply(['ugc_codes' => ['ALZ266'], 'vtec_end' => null]);
    expect($openEnded->expires)->toBeNull();

    $afterCancel = coverageApply(['ugc_codes' => ['ALZ266'], 'vtec_action' => 'CAN']);
    expect($afterCancel->expires)->toEqual(Carbon::parse('2026-10-09 08:00:00'));
});

test('a continuation that carries no times does not clear the times already known', function () {
    coverageApply(['vtec_begin' => Carbon::parse('2026-10-07 15:38:00'), 'vtec_end' => Carbon::parse('2026-10-09 08:00:00')]);

    $wwa = coverageApply(['vtec_action' => 'CON', 'vtec_begin' => null, 'vtec_end' => null]);

    expect(coverageAreaOf($wwa, 'FLZ206')->effective)->toEqual(Carbon::parse('2026-10-07 15:38:00'))
        ->and(coverageAreaOf($wwa, 'FLZ206')->expires)->toEqual(Carbon::parse('2026-10-09 08:00:00'))
        ->and($wwa->expires)->toEqual(Carbon::parse('2026-10-09 08:00:00'));
});

test('cancelling one zone ends only that zone, and the event stays Active', function () {
    coverageApply(['ugc_codes' => ['FLZ206', 'FLZ203'], 'vtec_end' => Carbon::parse('2026-10-09 08:00:00')]);

    $wwa = coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_action' => 'CAN']);

    expect($wwa->status)->toBe('Active')
        ->and(coverageAreaOf($wwa, 'FLZ206')->status)->toBe('Cancelled')
        ->and(coverageAreaOf($wwa, 'FLZ203')->status)->toBe('Active');
});

test('cancelling the last active zone cancels the event', function () {
    coverageApply(['ugc_codes' => ['FLZ206', 'FLZ203']]);
    coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_action' => 'CAN']);

    $wwa = coverageApply(['ugc_codes' => ['FLZ203'], 'vtec_action' => 'CAN']);

    expect($wwa->status)->toBe('Cancelled')
        ->and($wwa->areas()->pluck('status')->unique()->all())->toBe(['Cancelled']);
});

test('an upgrade ends its own zones only', function () {
    coverageApply(['vtec_phenomenon' => 'TR', 'ugc_codes' => ['FLZ206', 'FLZ203']]);

    $wwa = coverageApply(['vtec_phenomenon' => 'TR', 'ugc_codes' => ['FLZ206'], 'vtec_action' => 'UPG']);

    expect(coverageAreaOf($wwa, 'FLZ206')->status)->toBe('Cancelled')
        ->and(coverageAreaOf($wwa, 'FLZ203')->status)->toBe('Active')
        ->and($wwa->status)->toBe('Active');
});

test('an expiry ends the zone as Expired, and an event with every zone expired is Expired', function () {
    coverageApply(['ugc_codes' => ['FLZ206']]);

    $wwa = coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_action' => 'EXP']);

    expect(coverageAreaOf($wwa, 'FLZ206')->status)->toBe('Expired')
        ->and($wwa->status)->toBe('Expired');
});

test('an event with no active zone takes the status of the last terminal action', function () {
    coverageApply(['ugc_codes' => ['FLZ206', 'FLZ203']]);
    coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_action' => 'CAN'], ['received_at' => now()->addMinute()]);

    $wwa = coverageApply(['ugc_codes' => ['FLZ203'], 'vtec_action' => 'EXP'], ['received_at' => now()->addMinutes(2)]);

    expect($wwa->status)->toBe('Expired');
});

test('a late, older bulletin does not undo a newer state, but still adds the zones the newer one never mentioned', function () {
    coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_action' => 'CAN'], ['received_at' => now()]);

    $wwa = coverageApply(['ugc_codes' => ['FLZ206', 'FLZ203'], 'vtec_action' => 'NEW'], ['received_at' => now()->subMinutes(10)]);

    expect(coverageAreaOf($wwa, 'FLZ206')->status)->toBe('Cancelled')
        ->and(coverageAreaOf($wwa, 'FLZ203')->status)->toBe('Active');
});

test('a segment with a polygon sets it on its zones, and a later text segment without one keeps it', function () {
    coverageApply(['ugc_codes' => ['FLZ206'], 'polygon' => coveragePoints(COVERAGE_SQUARE_A)]);

    $wwa = coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_action' => 'CON', 'polygon' => null]);

    expect(coveragePolygonText(coverageAreaOf($wwa, 'FLZ206')))->toBe(COVERAGE_SQUARE_A);
});

test('a later text segment replaces the polygon of a zone', function () {
    coverageApply(['ugc_codes' => ['FLZ206'], 'polygon' => coveragePoints(COVERAGE_SQUARE_A)]);

    $wwa = coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_action' => 'CON', 'polygon' => coveragePoints(COVERAGE_SQUARE_B)]);

    expect(coveragePolygonText(coverageAreaOf($wwa, 'FLZ206')))->toBe(COVERAGE_SQUARE_B);
});

test('a CAP polygon fills a zone that has none and never replaces a text polygon', function () {
    coverageApply(['ugc_codes' => ['FLZ206'], 'polygon' => coveragePoints(COVERAGE_SQUARE_A)]);
    coverageApply(['ugc_codes' => ['FLZ203'], 'polygon' => null]);

    $wwa = coverageApply(
        ['ugc_codes' => ['FLZ206', 'FLZ203'], 'vtec_action' => 'CON', 'polygon' => coveragePoints(COVERAGE_SQUARE_B)],
        ['format' => 'cap'],
    );

    expect(coveragePolygonText(coverageAreaOf($wwa, 'FLZ206')))->toBe(COVERAGE_SQUARE_A)
        ->and(coveragePolygonText(coverageAreaOf($wwa, 'FLZ203')))->toBe(COVERAGE_SQUARE_B);
});

test('a cancel never touches a zone\'s polygon', function () {
    coverageApply(['ugc_codes' => ['FLZ206'], 'polygon' => coveragePoints(COVERAGE_SQUARE_A)]);

    $wwa = coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_action' => 'CAN', 'polygon' => coveragePoints(COVERAGE_SQUARE_B)]);

    expect(coveragePolygonText(coverageAreaOf($wwa, 'FLZ206')))->toBe(COVERAGE_SQUARE_A);
});

test('the event row keeps the latest segment\'s polygon for the code that still falls back to it', function () {
    $wwa = coverageApply(['ugc_codes' => ['FLZ206'], 'polygon' => coveragePoints(COVERAGE_SQUARE_B)]);

    $stored = DB::selectOne('SELECT ST_AsText(polygon) AS wkt FROM active_wwas WHERE id = ?', [$wwa->id]);

    expect($stored->wkt)->toBe(COVERAGE_SQUARE_B);
});

test('a segment with no zones gives the event the status and times of its action, as before', function () {
    $cancelled = coverageApply(['ugc_codes' => null, 'vtec_action' => 'CAN', 'vtec_end' => Carbon::parse('2026-10-09 08:00:00')]);

    expect($cancelled->status)->toBe('Cancelled')
        ->and($cancelled->expires)->toEqual(Carbon::parse('2026-10-09 08:00:00'))
        ->and($cancelled->areas()->count())->toBe(0);
});

test('an event whose recorded end has already passed is Expired', function () {
    $wwa = coverageApply(['ugc_codes' => ['FLZ206'], 'vtec_end' => Carbon::parse('2026-10-07 12:00:00')]);

    expect($wwa->status)->toBe('Expired');
});

test('a saved segment is linked from the zones it set', function () {
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['received_at' => now()]));
    $segment = IngestedProductSegment::factory()->for($bulletin)->create(['ugc_codes' => ['FLZ206', 'FLZ203']]);

    $wwa = app(ApplySegmentToCoverage::class)($bulletin, $segment);

    expect($wwa->areas()->pluck('latest_segment_id')->unique()->all())->toBe([$segment->id]);
});

test('a zone repeated in the segment\'s list is applied once, without error', function () {
    $wwa = coverageApply(['ugc_codes' => ['FLZ206', 'FLZ206']]);

    expect($wwa->areas()->pluck('ugc_code')->all())->toBe(['FLZ206']);
});
