<?php

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProductSegment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('an event lists its zone rows, and deleting the event deletes them', function () {
    $wwa = ActiveWwa::factory()->create();
    ActiveWwaArea::factory()->for($wwa)->create(['ugc_code' => 'FLZ206']);
    ActiveWwaArea::factory()->for($wwa)->create(['ugc_code' => 'FLZ203']);

    expect($wwa->areas()->pluck('ugc_code')->sort()->values()->all())->toBe(['FLZ203', 'FLZ206']);

    $wwa->delete();

    expect(ActiveWwaArea::count())->toBe(0);
});

test('a zone appears once per event', function () {
    $wwa = ActiveWwa::factory()->create();
    ActiveWwaArea::factory()->for($wwa)->create(['ugc_code' => 'FLZ206']);

    expect(fn () => ActiveWwaArea::factory()->for($wwa)->create(['ugc_code' => 'FLZ206']))->toThrow(UniqueConstraintViolationException::class);
});

test('deleting the segment a zone was last set by keeps the zone and forgets the link', function () {
    $segment = IngestedProductSegment::factory()->create();
    $area = ActiveWwaArea::factory()->create(['latest_segment_id' => $segment->id]);

    $segment->delete();

    expect($area->fresh()->latest_segment_id)->toBeNull();
});

test('a zone row can carry a polygon', function () {
    $area = ActiveWwaArea::factory()->withPolygon('POLYGON((-87 30, -87 31, -86 31, -86 30, -87 30))')->create();

    $contains = DB::selectOne('SELECT ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains FROM active_wwa_areas WHERE id = ?', [-86.5, 30.5, $area->id]);

    expect($contains->contains)->toBeTrue();
});

test('the migration back-fills one Active zone row per zone of every active event, with the event polygon on each', function () {
    $active = ActiveWwa::factory()->create(['ugc_codes' => ['FLZ206', 'FLZ203'], 'status' => 'Active', 'expires' => now()->addHours(3)]);
    DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', ['POLYGON((-87 30, -87 31, -86 31, -86 30, -87 30))', $active->id]);
    ActiveWwa::factory()->create(['ugc_codes' => ['FLZ111'], 'status' => 'Cancelled']);
    ActiveWwa::factory()->create(['ugc_codes' => null, 'status' => 'Active']);
    Schema::dropIfExists('active_wwa_areas');

    (require database_path('migrations/2026_10_07_200001_create_active_wwa_areas_table.php'))->up();

    $areas = DB::table('active_wwa_areas')->orderBy('ugc_code')->get();
    expect($areas->pluck('ugc_code')->all())->toBe(['FLZ203', 'FLZ206'])
        ->and($areas->pluck('status')->unique()->all())->toBe(['Active'])
        ->and($areas->pluck('active_wwa_id')->unique()->all())->toBe([$active->id])
        ->and(DB::table('active_wwa_areas')->whereNotNull('polygon')->count())->toBe(2);
});
