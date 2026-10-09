# Per-Zone Coverage for Active Events Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Active now list and the daily summary show every event in effect at a location, because an event's geography is kept per zone and one zone's cancel, expiry or upgrade ends only that zone.

**Architecture:** A new `active_wwa_areas` table holds one row per (event, zone) with its own status, times and optional polygon. `ApplySegmentToCoverage` applies one bulletin segment (project 1) to an event: it finds or creates the `active_wwas` event row, upserts or ends the segment's zones by its VTEC action, then derives the event row's status, start and end from its zones. `SyncActiveWwa` applies every segment of a bulletin through it. `GetActiveAlertsForLocation` and the follow-up-statement polygon lookup read the area rows; an event with no area rows falls back to its own columns. A migration back-fills areas, and `active-wwas:rebuild` replays stored segments to repair events that were already collapsed.

**Tech Stack:** Laravel app (PHP 8.4), Pest, PostgreSQL/PostGIS, queue (sync in tests).

**Spec:** `docs/superpowers/specs/2026-10-07-active-event-zone-coverage-design.md` (read it first; the plan argues from it). It builds on `docs/superpowers/plans/2026-10-07-bulletin-sections-and-matching.md` (project 1): execute that plan first. This plan relies on its `ingested_product_segments` table, `IngestedProductSegment` model, `IngestedProduct::segments()`, `BuildBulletinSegments` and `bulletins:parse-segments`.

## Global Constraints

- Backend tests run in the container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest <files>'` (start containers with `docker compose up -d front-end pgsql redis` if stopped). Format the PHP files you changed with `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent <the files you changed>'`; name the files, never use `--dirty` (the owner's unrelated work-in-progress files may be dirty).
- Do not push; commits go on `main`. Every commit message ends with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Read `.ai/rules/index.md` and the rule files that match the paths you touch before editing: `pipeline.md` (`app/Actions/Pipeline/**`, `MatchWeatherAlertRules`), `actions.md`, `models.md` (`ActiveWwa`), `feature.md` (`tests/Feature/**`), `pages.md` (the factory-reset actions).
- `active_wwas` stays one row per VTEC event. Its `ugc_codes` and `polygon` columns keep being set from the latest segment but nothing reads them for coverage any more, except as the fallback for an event with no area rows (legacy rows, an odd bulletin).
- A cancel (`CAN`), upgrade (`UPG`) or expiry (`EXP`) section ends only its own zones. `NEW`, `CON`, `EXT`, `EXA`, `EXB`, `COR` and `ROU` upsert the segment's zones as Active. The event's `status` is `Active` while any zone is Active; otherwise `Cancelled` or `Expired` by the last terminal action; an event that never had a zone takes the action's status as before. `expires` is the latest end among active zones, `null` if any active zone has none. `effective` is the earliest.
- A segment older than a zone row's `applied_at` (the receiving bulletin's `received_at`) is ignored for that row.
- A CAP polygon is attached to a zone that has none and never replaces an existing one; a text segment's polygon replaces the zone's; a text segment without a polygon never clears one.
- Coverage readers: a zone row with a polygon covers a point only if the polygon contains it (the polygon is authoritative); a row without one covers a location whose zone or county code equals the row's zone. Each event appears once, with the expiry of its covering rows (the latest; `null` if any covering row has none).
- `SyncActiveWwa` keeps its dispatch rules: a text bulletin always triggers matching; a CAP message triggers it only when its paired text bulletin cannot be found. Replays still never write `active_wwas`. Motion is applied to the bulletin's first segment's event only.
- Tests: use factories; `IngestedProduct::withoutEvents(...)`/`createQuietly()` when a test wants no observer (the observer queues `ProcessIngestedProduct`, which builds segments and syncs events); `Queue::fake([DeliverWeatherAlert::class])` (not `Queue::fake()`) when the observer's job must really run; Pest helper functions live in the global namespace, so give every helper a name no other test file uses (this plan prefixes them); never name a test property `$this->app`.
- Real bulletins used as fixtures are in `database/product_examples/bulletins/` (`TCV_MOB.txt`, `MWW_MOB.txt`, `NPW_FGZ.txt`, `CFW_JAX.txt`). They are read-only.

## Plan decisions beyond the spec (rulings made while planning; the reviewer should hold the plan to them)

1. **`ApplySegmentToCoverage` takes the bulletin, the segment and a list of extra event columns.** `SyncActiveWwa` passes `source`, `headline`, `latest_ingested_product_id` and (first segment only) the motion fields; the rebuild command passes none, so a replay never rewrites those.
2. **A bulletin with no stored segments is applied through an unsaved segment built from its own columns**, so a single-VTEC bulletin behaves as it did before and the existing `SyncActiveWwaTest` stays untouched.
3. **A zone row's `effective` and `expires` are written only when the segment carries them**, as the event row always did (`vtec_begin`/`vtec_end` of `000000T0000Z` are null and must not clear a known time). A new row takes the segment's values, null included.
4. **The migration back-fills only `Active` events**, and `active-wwas:rebuild` selects only `Active` events, so cancelled and expired events are left alone.
5. **`matchesActiveWwaPolygon` and `activeFor` fall back to the event's own columns only when the event has no area rows at all.** An event whose area rows exist but have no polygon is "no polygon" for the follow-up lookup (`null`), and an event with polygon-less zone rows is matched by zone.
6. **The covering expiry is set on the in-memory `ActiveWwa` the lookup returns** (never saved), so `sorted()` and every caller keep reading `$wwa->expires`.
7. **`UPG` ending its zones is a deliberate change**: today an upgraded event stays Active until it expires.

## File Structure

Create:
- `database/migrations/2026_10_07_200001_create_active_wwa_areas_table.php`
- `app/Models/ActiveWwaArea.php`, `database/factories/ActiveWwaAreaFactory.php`
- `app/Actions/Pipeline/ApplySegmentToCoverage.php`
- `app/Console/Commands/RebuildActiveWwaCoverage.php` (`active-wwas:rebuild`)
- Tests (`tests/Feature/`): `ActiveWwaAreaTest`, `ApplySegmentToCoverageTest`, `SyncActiveWwaSegmentsTest`, `ActiveEventCoverageReadersTest`, `RebuildActiveWwaCoverageTest`

Modify:
- `app/Models/ActiveWwa.php` (`areas()`), `app/Actions/Pipeline/SyncActiveWwa.php`
- `app/Actions/Weather/GetActiveAlertsForLocation.php`, `app/Actions/Weather/MatchWeatherAlertRules.php` (`matchesActiveWwaPolygon()`)
- `app/Actions/Admin/FactoryReset/WipePipelineData.php`, `PreviewFactoryReset.php`
- `.ai/rules/pipeline.md`, `.ai/rules/models.md`
- Tests: `FactoryResetActionsTest`

---

### Task 1: The `active_wwa_areas` table, model and back-fill

**Files:**
- Create: `database/migrations/2026_10_07_200001_create_active_wwa_areas_table.php`, `app/Models/ActiveWwaArea.php`, `database/factories/ActiveWwaAreaFactory.php`
- Modify: `app/Models/ActiveWwa.php`, `app/Actions/Admin/FactoryReset/WipePipelineData.php`, `app/Actions/Admin/FactoryReset/PreviewFactoryReset.php`
- Test: `tests/Feature/ActiveWwaAreaTest.php` (create), `tests/Feature/FactoryResetActionsTest.php`

**Interfaces:**
- Consumes (project 1): table `ingested_product_segments`.
- Produces: table `active_wwa_areas` (`id`, `active_wwa_id` FK cascade, `ugc_code`, `status` default `Active`, `polygon` geometry(Polygon,4269) nullable with a GiST index, `effective`, `expires`, `latest_segment_id` FK nullOnDelete, `applied_at`, timestamps; unique (`active_wwa_id`,`ugc_code`), index on `ugc_code`); `ActiveWwaArea` (fillable `active_wwa_id, ugc_code, status, effective, expires, latest_segment_id, applied_at`; datetime casts on `effective`, `expires`, `applied_at`; relations `activeWwa()`, `latestSegment()`; factory with state `withPolygon(string $wkt)`); `ActiveWwa::areas(): HasMany`; the wipe/preview arrays gain `active_wwa_areas` (between `active_wwas` and `delivery_logs`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ActiveWwaAreaTest.php`:

```php
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
```

In `tests/Feature/FactoryResetActionsTest.php` (as changed by project 1; add `use App\Models\ActiveWwaArea;` with the other model imports):

1. Replace the `ActiveWwa::factory()->create(['latest_ingested_product_id' => $ingested->first()->id]);` line in `frSeedPipelineData()` with:

```php
    $wwa = ActiveWwa::factory()->create(['latest_ingested_product_id' => $ingested->first()->id]);
    ActiveWwaArea::factory()->for($wwa)->create();
```
2. In the wipe test replace `['ingested_products' => 3, 'ingested_product_segments' => 1, 'active_wwas' => 1, 'delivery_logs' => 2]` with `['ingested_products' => 3, 'ingested_product_segments' => 1, 'active_wwas' => 1, 'active_wwa_areas' => 1, 'delivery_logs' => 2]` and add `->and(ActiveWwaArea::count())->toBe(0)` to that chain.
3. Make the same replacement in the preview test's `expect($preview['pipeline'])->toBe([...])`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ActiveWwaAreaTest.php tests/Feature/FactoryResetActionsTest.php'`
Expected: FAIL (`ActiveWwaArea` class not found).

- [ ] **Step 3: Create the migration, model and factory**

Create `database/migrations/2026_10_07_200001_create_active_wwa_areas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (event, zone): an NWS event is issued as many bulletins, one per zone group, and a
        // cancel or expiry usually ends only some of its zones, so the zones carry the state.
        Schema::create('active_wwa_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('active_wwa_id')->constrained()->cascadeOnDelete();
            $table->string('ugc_code');
            $table->string('status')->default('Active'); // Active | Cancelled | Expired
            $table->geometry('polygon', 'POLYGON', 4269)->nullable();
            $table->timestamp('effective')->nullable();
            $table->timestamp('expires')->nullable();
            $table->foreignId('latest_segment_id')->nullable()->constrained('ingested_product_segments')->nullOnDelete();
            // The receiving bulletin's received_at: a late, older bulletin must not undo a newer state.
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['active_wwa_id', 'ugc_code']);
            $table->index('ugc_code');
            $table->spatialIndex('polygon');
        });

        // Active events as they stand today become zone rows, so the readers are correct the moment
        // this deploys; active-wwas:rebuild then repairs the ones whose zones were already lost.
        DB::statement(<<<'SQL'
            INSERT INTO active_wwa_areas (active_wwa_id, ugc_code, status, polygon, effective, expires, applied_at, created_at, updated_at)
            SELECT w.id, z.code, 'Active', w.polygon, w.effective, w.expires, w.updated_at, now(), now()
            FROM active_wwas w
            CROSS JOIN LATERAL json_array_elements_text(w.ugc_codes) AS z(code)
            WHERE w.status = 'Active' AND w.ugc_codes IS NOT NULL AND json_typeof(w.ugc_codes) = 'array'
            ON CONFLICT (active_wwa_id, ugc_code) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('active_wwa_areas');
    }
};
```

Create `app/Models/ActiveWwaArea.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ActiveWwaAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One zone of an active event, with its own status, times and optional polygon. This, not the
 * event row's ugc_codes/polygon, is what says where an event is in effect. `polygon` is a native
 * PostGIS geometry column, deliberately not fillable or cast: it is written and read with raw SQL
 * (see ApplySegmentToCoverage and GetActiveAlertsForLocation).
 */
#[Fillable(['active_wwa_id', 'ugc_code', 'status', 'effective', 'expires', 'latest_segment_id', 'applied_at'])]
class ActiveWwaArea extends Model
{
    /** @use HasFactory<ActiveWwaAreaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'effective' => 'datetime',
            'expires' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function activeWwa(): BelongsTo
    {
        return $this->belongsTo(ActiveWwa::class);
    }

    public function latestSegment(): BelongsTo
    {
        return $this->belongsTo(IngestedProductSegment::class, 'latest_segment_id');
    }
}
```

Create `database/factories/ActiveWwaAreaFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<ActiveWwaArea>
 */
class ActiveWwaAreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'active_wwa_id' => ActiveWwa::factory(),
            'ugc_code' => 'FLZ206',
            'status' => 'Active',
            'effective' => now(),
            'expires' => now()->addHour(),
            'applied_at' => now(),
        ];
    }

    /**
     * Sets the zone's polygon from WKT (SRID 4269), e.g. 'POLYGON((-87 30, -87 31, -86 31, -86 30, -87 30))'.
     */
    public function withPolygon(string $wkt): static
    {
        return $this->afterCreating(function (ActiveWwaArea $area) use ($wkt): void {
            DB::statement('UPDATE active_wwa_areas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $area->id]);
        });
    }
}
```

- [ ] **Step 4: Add the relation and include the table in the factory-reset actions**

In `app/Models/ActiveWwa.php` add `use Illuminate\Database\Eloquent\Relations\HasMany;` with the other relation import and this method after `latestIngestedProduct()`:

```php
    /**
     * The zones this event is (or was) in effect in. Coverage is read from here, not from this row's
     * own ugc_codes/polygon, which only describe the latest bulletin.
     */
    public function areas(): HasMany
    {
        return $this->hasMany(ActiveWwaArea::class);
    }
```

Replace `app/Actions/Admin/FactoryReset/WipePipelineData.php`'s method and docblock with:

```php
    /**
     * @return array{ingested_products: int, ingested_product_segments: int, active_wwas: int, active_wwa_areas: int, delivery_logs: int}
     */
    public function __invoke(): array
    {
        $counts = [
            'ingested_products' => DB::table('ingested_products')->count(),
            'ingested_product_segments' => DB::table('ingested_product_segments')->count(),
            'active_wwas' => DB::table('active_wwas')->count(),
            'active_wwa_areas' => DB::table('active_wwa_areas')->count(),
            'delivery_logs' => DB::table('delivery_logs')->count(),
        ];

        DB::statement('TRUNCATE TABLE ingested_products, ingested_product_segments, delivery_log_segment, active_wwas, active_wwa_areas, delivery_logs RESTART IDENTITY');

        return $counts;
    }
```

In `app/Actions/Admin/FactoryReset/PreviewFactoryReset.php` replace the `$preview['pipeline']` array with:

```php
            $preview['pipeline'] = [
                'ingested_products' => DB::table('ingested_products')->count(),
                'ingested_product_segments' => DB::table('ingested_product_segments')->count(),
                'active_wwas' => DB::table('active_wwas')->count(),
                'active_wwa_areas' => DB::table('active_wwa_areas')->count(),
                'delivery_logs' => DB::table('delivery_logs')->count(),
            ];
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ActiveWwaAreaTest.php tests/Feature/FactoryResetActionsTest.php tests/Feature/ActiveWwaModelTest.php'`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Models/ActiveWwaArea.php app/Models/ActiveWwa.php database/factories/ActiveWwaAreaFactory.php database/migrations/2026_10_07_200001_create_active_wwa_areas_table.php app/Actions/Admin/FactoryReset tests/Feature/ActiveWwaAreaTest.php tests/Feature/FactoryResetActionsTest.php'
git add app/Models/ActiveWwaArea.php app/Models/ActiveWwa.php database/factories/ActiveWwaAreaFactory.php database/migrations/2026_10_07_200001_create_active_wwa_areas_table.php app/Actions/Admin tests/Feature/ActiveWwaAreaTest.php tests/Feature/FactoryResetActionsTest.php
git commit -m "Active events: add per-zone area rows, back-filled from the current events

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Apply one segment to an event's zones

**Files:**
- Create: `app/Actions/Pipeline/ApplySegmentToCoverage.php`
- Test: `tests/Feature/ApplySegmentToCoverageTest.php` (create)

**Interfaces:**
- Consumes: `ActiveWwa`, `ActiveWwaArea`, `IngestedProduct`, `IngestedProductSegment` (project 1), `ActiveWwa::vtecKeyFor()`.
- Produces: `app(ApplySegmentToCoverage::class)(IngestedProduct $bulletin, IngestedProductSegment $segment, array $eventAttributes = []): ActiveWwa`: finds or creates the event by the segment's VTEC key, applies the segment's action to its zones, sets the event polygon from the segment's, derives the event's `status`, `effective` and `expires`, and returns the event. `$eventAttributes` are extra `active_wwas` columns to write (`source`, `headline`, `latest_ingested_product_id`, `motion_*`). It never calls the matcher.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ApplySegmentToCoverageTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ApplySegmentToCoverageTest.php'`
Expected: FAIL (class `ApplySegmentToCoverage` not found).

- [ ] **Step 3: Create `ApplySegmentToCoverage`**

Create `app/Actions/Pipeline/ApplySegmentToCoverage.php`:

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use Illuminate\Support\Facades\DB;

/**
 * Applies one segment (one event of one section of a bulletin) to its active event: finds or
 * creates the active_wwas row by the segment's VTEC key, sets or ends the segment's zones in
 * active_wwa_areas by its VTEC action, then derives the event row's status, start and end from
 * its zones. It never matches alert rules and never sends anything: SyncActiveWwa calls it for
 * every segment of a live bulletin, and active-wwas:rebuild replays stored segments through it.
 *
 * NEW, CON, EXT, EXA, EXB, COR and ROU set the segment's zones Active; CAN and UPG (an upgrade
 * ends the old event) set them Cancelled; EXP sets them Expired. Other zones of the event are
 * untouched, which is the point: one zone's cancel must not end the whole event. A zone row
 * already written by a newer bulletin (its applied_at is later than this bulletin's received_at)
 * is left alone.
 */
class ApplySegmentToCoverage
{
    private const ACTIVE = 'Active';

    private const CANCELLED = 'Cancelled';

    private const EXPIRED = 'Expired';

    /**
     * @param  array<string, mixed>  $eventAttributes  extra active_wwas columns to write: source, headline, latest_ingested_product_id, motion_*
     */
    public function __invoke(IngestedProduct $bulletin, IngestedProductSegment $segment, array $eventAttributes = []): ActiveWwa
    {
        $wwa = ActiveWwa::updateOrCreate(
            ActiveWwa::vtecKeyFor($segment),
            [
                'product_id' => $segment->product_id,
                // A freshly created (not ->fresh()'d) IngestedProduct has is_test === null in memory --
                // Eloquent doesn't reload the row after insert, so the column's `false` default never
                // reaches the attribute. active_wwas.is_test is NOT NULL, so coalesce rather than let the
                // insert throw (SyncActiveWwa would swallow it and drop the whole event).
                'is_test' => $bulletin->is_test ?? false,
                ...($segment->ugc_codes !== null ? ['ugc_codes' => $segment->ugc_codes] : []),
                ...$eventAttributes,
            ],
        );

        $this->applyToZones($wwa, $bulletin, $segment);

        if ($segment->polygon !== null) {
            DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$this->polygonToWkt($segment->polygon), $wwa->id]);
        }

        $this->deriveEvent($wwa, $segment);

        return $wwa;
    }

    private function applyToZones(ActiveWwa $wwa, IngestedProduct $bulletin, IngestedProductSegment $segment): void
    {
        $zones = $segment->ugc_codes ?? [];

        if ($zones === []) {
            return;
        }

        $status = $this->statusFor($segment->vtec_action);
        $appliedAt = $bulletin->received_at ?? now();
        $existing = $wwa->areas()->whereIn('ugc_code', $zones)->get()->keyBy('ugc_code');

        $zones = array_values(array_filter($zones, fn (string $zone): bool => ! ($existing->get($zone)?->applied_at?->greaterThan($appliedAt) ?? false)));

        if ($zones === []) {
            return;
        }

        $rows = array_map(fn (string $zone): array => [
            'active_wwa_id' => $wwa->id,
            'ugc_code' => $zone,
            'status' => $status,
            'effective' => $segment->vtec_begin,
            'expires' => $segment->vtec_end,
            'latest_segment_id' => $segment->exists ? $segment->id : null,
            'applied_at' => $appliedAt,
        ], $zones);

        // A time the segment does not carry (a CON's "until further notice" end is null) never clears one already known.
        ActiveWwaArea::upsert($rows, ['active_wwa_id', 'ugc_code'], [
            'status', 'latest_segment_id', 'applied_at',
            ...($segment->vtec_begin !== null ? ['effective'] : []),
            ...($segment->vtec_end !== null ? ['expires'] : []),
        ]);

        if ($segment->polygon !== null && $status === self::ACTIVE) {
            $this->setPolygon($wwa, $zones, $segment->polygon, keepExisting: $bulletin->format === 'cap');
        }
    }

    /**
     * A text segment's polygon is the section's own and replaces the zone's; a CAP message's only
     * fills a zone that has none. A segment without a polygon never calls this, so a polygon-less
     * continuation statement keeps the polygon already known.
     *
     * @param  list<string>  $zones
     * @param  array<int, array{lat: float, lon: float}>  $polygon
     */
    private function setPolygon(ActiveWwa $wwa, array $zones, array $polygon, bool $keepExisting): void
    {
        $geometry = 'ST_SetSRID(ST_GeomFromText(?), 4269)';
        $set = $keepExisting ? "COALESCE(polygon, {$geometry})" : $geometry;
        $placeholders = implode(', ', array_fill(0, count($zones), '?'));

        DB::statement(
            "UPDATE active_wwa_areas SET polygon = {$set} WHERE active_wwa_id = ? AND ugc_code IN ({$placeholders})",
            [$this->polygonToWkt($polygon), $wwa->id, ...$zones],
        );
    }

    /**
     * The event row follows its zones: Active while any zone is, else the status of the zone most
     * recently ended; its end is the latest end of the active zones, or none if any active zone has
     * none ("until further notice"); its start is the earliest. An event that has no zone rows
     * (a bulletin without a readable zone line) takes the action's status and times, as it always did.
     */
    private function deriveEvent(ActiveWwa $wwa, IngestedProductSegment $segment): void
    {
        $areas = $wwa->areas()->get();

        if ($areas->isEmpty()) {
            $wwa->update([
                'status' => $this->statusFor($segment->vtec_action),
                ...($segment->vtec_begin !== null ? ['effective' => $segment->vtec_begin] : []),
                ...($segment->vtec_end !== null ? ['expires' => $segment->vtec_end] : []),
            ]);
        } else {
            $active = $areas->where('status', self::ACTIVE);
            $considered = $active->isNotEmpty() ? $active : $areas;
            $lastEnded = $areas->sortByDesc(fn (ActiveWwaArea $area): array => [$area->applied_at?->getTimestamp() ?? 0, $area->id])->first();
            $earliestStart = $areas->pluck('effective')->filter()->min();

            $wwa->update([
                'status' => $active->isNotEmpty() ? self::ACTIVE : $lastEnded->status,
                'expires' => $considered->contains(fn (ActiveWwaArea $area): bool => $area->expires === null) ? null : $considered->max('expires'),
                ...($earliestStart !== null ? ['effective' => $earliestStart] : []),
            ]);
        }

        // NWS often lets an event lapse with no EXP product ever issued: once the recorded end has passed, it is over.
        if ($wwa->status === self::ACTIVE && $wwa->expires !== null && $wwa->expires->isPast()) {
            $wwa->update(['status' => self::EXPIRED]);
        }
    }

    private function statusFor(?string $vtecAction): string
    {
        return match ($vtecAction) {
            'CAN', 'UPG' => self::CANCELLED,
            'EXP' => self::EXPIRED,
            default => self::ACTIVE,
        };
    }

    /**
     * A PostGIS POLYGON ring needs at least 4 coordinate pairs with the
     * first repeated as the last. Real LAT...LON/CAP polygons always carry
     * well more than that, but degenerate input (as low as a single point)
     * is padded by repeating the last point rather than rejected -- this
     * mirrors ST_GeomFromText's own tolerance for a zero-area ring (a ring
     * of identical points is accepted), confirmed directly against this
     * database.
     *
     * @param  array<int, array{lat: float, lon: float}>  $points
     */
    private function polygonToWkt(array $points): string
    {
        $coords = collect($points)->map(fn (array $p): string => "{$p['lon']} {$p['lat']}");

        if ($coords->first() !== $coords->last()) {
            $coords->push($coords->first());
        }

        while ($coords->count() < 4) {
            $coords->push($coords->last());
        }

        return 'POLYGON(('.$coords->join(', ').'))';
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ApplySegmentToCoverageTest.php'`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Actions/Pipeline/ApplySegmentToCoverage.php tests/Feature/ApplySegmentToCoverageTest.php'
git add app/Actions/Pipeline/ApplySegmentToCoverage.php tests/Feature/ApplySegmentToCoverageTest.php
git commit -m "Active events: apply a bulletin segment to its event's zones

A cancel, expiry or upgrade ends only its own zones; the event row follows its zones.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `SyncActiveWwa` applies every segment of a bulletin

**Files:**
- Modify: `app/Actions/Pipeline/SyncActiveWwa.php`
- Test: `tests/Feature/SyncActiveWwaSegmentsTest.php` (create); `tests/Feature/SyncActiveWwaTest.php` is the regression guard and is not edited unless a test in it asserts the behaviour this task deliberately changes (an upgrade, a per-bulletin status flip), in which case update only that test and say so in the commit message.

**Interfaces:**
- Consumes: `ApplySegmentToCoverage` (Task 2), `IngestedProduct::segments()` (project 1).
- Produces: `SyncActiveWwa::__invoke(IngestedProduct)` writes an event row and zone rows for every segment of the bulletin (or one built from the bulletin's own columns when it has none); dispatch rules unchanged.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SyncActiveWwaSegmentsTest.php`:

```php
<?php

use App\Actions\Pipeline\IngestProductText;
use App\Actions\Pipeline\SyncActiveWwa;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

/** A CAP message for one zone of the Mobile Hurricane Watch, through the observer (so segments are built and the event synced). */
function syncSegmentsCapMessage(string $zone, string $action = 'NEW', ?Carbon $receivedAt = null): IngestedProduct
{
    return IngestedProduct::factory()->create([
        'format' => 'cap', 'text' => '<?xml version="1.0"?><alert/>', 'cccc' => 'kwbc',
        'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009',
        'vtec_year' => now()->year, 'vtec_action' => $action, 'ugc_codes' => [$zone], 'received_at' => $receivedAt ?? now(),
    ]);
}

test('a Hurricane Local Statement gives each of its events a row with every zone of its sections', function () {
    app(IngestProductText::class)(
        file_get_contents(base_path('database/product_examples/bulletins/TCV_MOB.txt')),
        'test/tcvmob.txt',
        ['cccc' => 'kmob', 'ttaaii' => 'wtus84', 'awipsid' => 'tcvmob', 'wmo_ddhhmm' => '071609', 'nwws_id' => '1'],
    );

    $watch = ActiveWwa::where('vtec_phenomenon', 'HU')->sole();
    $surge = ActiveWwa::where('vtec_phenomenon', 'SS')->sole();
    expect(ActiveWwa::count())->toBe(2)
        ->and($watch->areas()->count())->toBe(12)
        ->and($watch->areas()->where('ugc_code', 'FLZ206')->exists())->toBeTrue()
        ->and($surge->areas()->count())->toBe(7)
        ->and($surge->areas()->where('ugc_code', 'FLZ206')->exists())->toBeTrue()
        ->and($watch->status)->toBe('Active');
});

test('a section\'s second VTEC line gets its own event row', function () {
    app(IngestProductText::class)(
        file_get_contents(base_path('database/product_examples/bulletins/MWW_MOB.txt')),
        'test/mwwmob.txt',
        ['cccc' => 'kmob', 'ttaaii' => 'whus74', 'awipsid' => 'mwwmob', 'wmo_ddhhmm' => '071538', 'nwws_id' => '1'],
    );

    $advisory = ActiveWwa::where('vtec_phenomenon', 'SC')->sole();
    expect($advisory->vtec_event_number)->toBe('0031')
        ->and($advisory->areas()->pluck('ugc_code')->sort()->values()->all())->toBe(['GMZ650', 'GMZ655', 'GMZ670', 'GMZ675'])
        ->and(ActiveWwa::where('vtec_phenomenon', 'HU')->sole()->areas()->count())->toBe(11);
});

test('CAP messages for one zone each add up on one event instead of overwriting each other', function () {
    syncSegmentsCapMessage('FLZ206');
    syncSegmentsCapMessage('FLZ203');
    syncSegmentsCapMessage('ALZ266');

    $wwa = ActiveWwa::sole();
    expect($wwa->areas()->pluck('ugc_code')->sort()->values()->all())->toBe(['ALZ266', 'FLZ203', 'FLZ206'])
        ->and($wwa->source)->toBe('cap')
        ->and($wwa->status)->toBe('Active');
});

test('a CAP cancel for one zone ends that zone and not the event', function () {
    syncSegmentsCapMessage('FLZ206');
    syncSegmentsCapMessage('FLZ203');

    syncSegmentsCapMessage('FLZ206', 'CAN', now()->addMinute());

    $wwa = ActiveWwa::sole();
    expect($wwa->status)->toBe('Active')
        ->and($wwa->areas()->where('ugc_code', 'FLZ206')->sole()->status)->toBe('Cancelled')
        ->and($wwa->areas()->where('ugc_code', 'FLZ203')->sole()->status)->toBe('Active');
});

test('an upgrade bulletin ends its zones', function () {
    $watch = IngestedProduct::factory()->create([
        'format' => 'text', 'text' => 'Tropical Storm Watch', 'product_id' => Product::where('code', 'TR.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'TR', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => now()->year,
        'vtec_action' => 'NEW', 'ugc_codes' => ['FLZ206'], 'received_at' => now(),
    ]);

    IngestedProduct::factory()->create([
        'format' => 'text', 'text' => 'Upgraded to a Tropical Storm Warning', 'product_id' => $watch->product_id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'TR', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => now()->year,
        'vtec_action' => 'UPG', 'ugc_codes' => ['FLZ206'], 'received_at' => now()->addMinute(),
    ]);

    expect(ActiveWwa::sole()->status)->toBe('Cancelled');
});

test('a text bulletin with no stored segments is applied from its own columns, as before', function () {
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'format' => 'text', 'product_id' => Product::where('code', 'TO.W')->sole()->id,
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
        'vtec_action' => 'NEW', 'ugc_codes' => ['NYZ069'], 'received_at' => now(),
    ]));
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));

    (new SyncActiveWwa)($bulletin);

    $wwa = ActiveWwa::sole();
    expect($wwa->areas()->pluck('ugc_code')->all())->toBe(['NYZ069'])
        ->and($wwa->latest_ingested_product_id)->toBe($bulletin->id);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/SyncActiveWwaSegmentsTest.php'`
Expected: FAIL (`SyncActiveWwa` still writes one event row per bulletin from its first VTEC line and keeps no zone rows).

- [ ] **Step 3: Rewrite `SyncActiveWwa` around `ApplySegmentToCoverage`**

Replace the whole of `app/Actions/Pipeline/SyncActiveWwa.php` with:

```php
<?php

namespace App\Actions\Pipeline;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Updates the "current state" active_wwas rows for a VTEC-class IngestedProduct -- one event per
 * VTEC line of every section of the bulletin, each applied through ApplySegmentToCoverage so
 * its zones accumulate and end one by one -- and decides whether this ingestion should trigger
 * a fresh match-and-dispatch pass. Text rows always dispatch -- this is what preserves today's
 * behavior of notifying on every issuance including continuations (SVS/FFS/etc, which resolve to
 * the same product_id as their parent warning). CAP rows only dispatch when their paired text row
 * can't be found, since the normal case is CAP arriving a few seconds after text already dispatched.
 *
 * A bulletin without stored segments (a factory row, or one BuildBulletinSegments failed on) is
 * applied through one unsaved segment built from its own columns, which is what every bulletin
 * was before segments existed.
 *
 * Both syncFromText() and syncFromCap() write motion_* fields when their respective source
 * carries one (a text row's own TIME...MOT...LOC line, or CAP's eventMotionDescription
 * parameter), onto the bulletin's FIRST event only -- text is what actually populates it in the
 * common case, since dispatch (and therefore anything consuming these columns, e.g. the Custom
 * Alert Templates Motion section) usually fires off the text row before CAP has a chance to
 * arrive at all.
 */
class SyncActiveWwa
{
    public function __invoke(IngestedProduct $product): void
    {
        if ($product->format === 'cap') {
            $this->syncFromCap($product);

            return;
        }

        $this->syncFromText($product);
    }

    private function syncFromText(IngestedProduct $product): void
    {
        try {
            DB::transaction(function () use ($product): void {
                $motion = app(ParseTextMotionDescription::class)($product->text, $product->received_at);

                foreach ($this->segmentsOf($product) as $index => $segment) {
                    app(ApplySegmentToCoverage::class)($product, $segment, [
                        'source' => 'text',
                        'latest_ingested_product_id' => $product->id,
                        ...($index === 0 ? $motion : []),
                    ]);
                }
            });
        } catch (Throwable $e) {
            logger()->error('Failed to sync active_wwas for a text-sourced VTEC product', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);
        }

        app(MatchWeatherAlertRules::class)($product);
    }

    private function syncFromCap(IngestedProduct $product): void
    {
        $pairedText = null;
        $lookupFailed = false;

        try {
            $pairedText = $this->findPairedTextProduct($product);

            DB::transaction(function () use ($product, $pairedText): void {
                $motion = $this->parseMotion($product->event_motion_description);

                foreach ($this->segmentsOf($product) as $index => $segment) {
                    app(ApplySegmentToCoverage::class)($product, $segment, [
                        'headline' => $product->headline,
                        'source' => 'cap',
                        'latest_ingested_product_id' => $pairedText?->id ?? $product->id,
                        ...($index === 0 ? $motion : []),
                    ]);
                }
            });
        } catch (Throwable $e) {
            $lookupFailed = true;
            logger()->error('Failed to sync active_wwas for a CAP-sourced VTEC product', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);
        }

        if ($pairedText === null || $lookupFailed) {
            app(MatchWeatherAlertRules::class)($product);
        }
    }

    /**
     * The bulletin's stored segments in bulletin order, or -- for a bulletin that has none -- one
     * unsaved segment carrying its own columns.
     *
     * @return Collection<int, IngestedProductSegment>
     */
    private function segmentsOf(IngestedProduct $product): Collection
    {
        $segments = $product->segments()->get();

        if ($segments->isNotEmpty()) {
            return $segments;
        }

        return collect([new IngestedProductSegment([
            'position' => 0,
            'vtec_office' => $product->vtec_office,
            'vtec_phenomenon' => $product->vtec_phenomenon,
            'vtec_significance' => $product->vtec_significance,
            'vtec_event_number' => $product->vtec_event_number,
            'vtec_year' => $product->vtec_year,
            'vtec_action' => $product->vtec_action,
            'vtec_begin' => $product->vtec_begin,
            'vtec_end' => $product->vtec_end,
            'product_id' => $product->product_id,
            'ugc_codes' => $product->ugc_codes,
            'polygon' => $product->polygon,
        ])]);
    }

    /**
     * Correlates a CAP row to the specific text issuance it cross-references,
     * via CAP's own AWIPSidentifier + WMOidentifier parameters (which decode
     * to exactly the awipsid/ttaaii/cccc/wmo_ddhhmm columns a text row
     * already stores).
     */
    private function findPairedTextProduct(IngestedProduct $capProduct): ?IngestedProduct
    {
        if ($capProduct->awips_identifier === null || $capProduct->wmo_identifier === null) {
            return null;
        }

        $parts = explode(' ', $capProduct->wmo_identifier);
        if (count($parts) !== 3) {
            return null;
        }

        [$ttaaii, $cccc, $ddhhmm] = $parts;

        return IngestedProduct::query()
            ->where('format', 'text')
            ->where('awipsid', strtolower($capProduct->awips_identifier))
            ->where('cccc', strtolower($cccc))
            ->where('ttaaii', strtolower($ttaaii))
            ->where('wmo_ddhhmm', $ddhhmm)
            ->where('received_at', '>=', $capProduct->received_at->copy()->subMinutes(10))
            ->where('received_at', '<=', $capProduct->received_at)
            ->latest('received_at')
            ->first();
    }

    /**
     * Parses CAP's eventMotionDescription parameter, e.g.
     * "2026-09-14T23:53:00-00:00...storm...231DEG...34KT...41.31,-96.18",
     * into active_wwas's discrete motion_* columns. Built from a single real
     * confirmed example -- if a real product's format ever varies, this
     * needs revisiting, not guessed at further.
     *
     * @return array{motion_observed_at?: Carbon, motion_direction_degrees?: int, motion_speed_kt?: int, motion_reference_lat?: float, motion_reference_lon?: float}
     */
    private function parseMotion(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (! preg_match('/^(?<observed>[\d\-T:+]+)\.\.\.\w+\.\.\.(?<deg>\d+)DEG\.\.\.(?<kt>\d+)KT\.\.\.(?<lat>-?\d+\.\d+),(?<lon>-?\d+\.\d+)$/', $raw, $m)) {
            return [];
        }

        return [
            'motion_observed_at' => Carbon::parse($m['observed']),
            'motion_direction_degrees' => (int) $m['deg'],
            'motion_speed_kt' => (int) $m['kt'],
            'motion_reference_lat' => (float) $m['lat'],
            'motion_reference_lon' => (float) $m['lon'],
        ];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/SyncActiveWwaSegmentsTest.php tests/Feature/SyncActiveWwaTest.php tests/Feature/CapIngestionIntegrationTest.php tests/Feature/ProcessIngestedProductTest.php tests/Feature/BackfillOutlookRiskAreasTest.php tests/Feature/TestProductGeneratorEndToEndTest.php tests/Feature/ComposeTemplatedAlertBodyTest.php'`
Expected: PASS. If a test in `SyncActiveWwaTest.php` fails, read it before touching anything: it must either be asserting the old collapsed-state behaviour this plan removes on purpose (a CAN or UPG ending the whole event), or you have a bug. Update only the former, and say which in the commit message.

- [ ] **Step 5: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Actions/Pipeline/SyncActiveWwa.php tests/Feature/SyncActiveWwaSegmentsTest.php'
git add app/Actions/Pipeline/SyncActiveWwa.php tests/Feature/SyncActiveWwaSegmentsTest.php
git commit -m "Active events: sync every section and VTEC line of a bulletin, zone by zone

The zones of an event now accumulate instead of the last bulletin overwriting them, and a
second VTEC line gets its own event row.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

### Task 4: Read coverage from the zone rows

**Files:**
- Modify: `app/Actions/Weather/GetActiveAlertsForLocation.php`, `app/Actions/Weather/MatchWeatherAlertRules.php`
- Test: `tests/Feature/ActiveEventCoverageReadersTest.php` (create); `GetActiveAlertsForLocationTest.php`, `GetActiveNowAtMyLocationsTest.php` and `MatchWeatherAlertRulesTest.php` are the regression guards for the fallback (their events have no zone rows) and are not edited.

**Interfaces:**
- Consumes: `ActiveWwa::areas()`, `ActiveWwaArea` (Task 1).
- Produces: `GetActiveAlertsForLocation::activeFor()` lists an event once when an Active, unexpired zone row covers the location (the row's polygon, when it has one, must contain the point; a row without one covers a location whose zone or county code is the row's), with `expires` set to the latest end of the covering rows (`null` if any has none); an event with no zone rows is read from its own columns as before. `MatchWeatherAlertRules::matchesActiveWwaPolygon()` reads the event's zone polygons.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ActiveEventCoverageReadersTest.php`:

```php
<?php

use App\Actions\Alerts\GetActiveNowAtMyLocations;
use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\DeliverWeatherAlert;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 16:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

const COVERAGE_READER_FAR_SQUARE = 'POLYGON((-91 34,-91 35,-90 35,-90 34,-91 34))';
const COVERAGE_READER_DESTIN_SQUARE = 'POLYGON((-86.6 30.2,-86.6 30.6,-86.3 30.6,-86.3 30.2,-86.6 30.2))';

function coverageReaderDestin(array $attributes = []): Location
{
    return Location::factory()->create([
        'label' => 'Destin', 'lat' => 30.39, 'lng' => -86.45, 'timezone' => 'America/Chicago',
        'forecast_zone_ugc' => 'FLZ206', 'county_ugc' => 'FLC091', ...$attributes,
    ]);
}

/** The Mobile Hurricane Watch as the old code left it: the event row names only the last zone it saw. */
function coverageReaderWatch(array $attributes = []): ActiveWwa
{
    return ActiveWwa::factory()->create([
        'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
        'ugc_codes' => ['FLZ203'], 'expires' => null, ...$attributes,
    ]);
}

test('Destin is covered by the Hurricane Watch through its own zone row, though the event row names another zone', function () {
    $destin = coverageReaderDestin();
    $watch = coverageReaderWatch();
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ203', 'expires' => null]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => null]);

    $events = app(GetActiveAlertsForLocation::class)->activeFor($destin);

    expect($events->modelKeys())->toBe([$watch->id])
        ->and($events->first()->eventName())->toBe('Hurricane Watch');
});

test('a zone that was cancelled no longer covers the location', function () {
    $watch = coverageReaderWatch();
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ203', 'expires' => null]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'status' => 'Cancelled', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->isEmpty())->toBeTrue();
});

test('a zone whose end has passed no longer covers the location', function () {
    $watch = coverageReaderWatch();
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => now()->subMinute()]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->isEmpty())->toBeTrue();
});

test('a county row covers the location by its county code', function () {
    $watch = coverageReaderWatch();
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLC091', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->modelKeys())->toBe([$watch->id]);
});

test('a zone row with a polygon is authoritative: inside covers, outside does not, though the zone is listed', function () {
    $outside = coverageReaderWatch();
    ActiveWwaArea::factory()->for($outside)->withPolygon(COVERAGE_READER_FAR_SQUARE)->create(['ugc_code' => 'FLZ206', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->isEmpty())->toBeTrue();

    $outside->areas()->delete();
    ActiveWwaArea::factory()->for($outside)->withPolygon(COVERAGE_READER_DESTIN_SQUARE)->create(['ugc_code' => 'FLZ206', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->modelKeys())->toBe([$outside->id]);
});

test('an event with no zone rows is read from its own zones and polygon, as before', function () {
    $legacy = coverageReaderWatch(['ugc_codes' => ['FLZ206']]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->modelKeys())->toBe([$legacy->id]);
});

test('an event ended as a whole is not listed even when its zone rows were left Active', function () {
    $watch = coverageReaderWatch(['status' => 'Cancelled']);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->isEmpty())->toBeTrue();
});

test('the event is listed once with the latest end of the rows that cover the location, ignoring rows that do not', function () {
    $watch = coverageReaderWatch(['expires' => now()->addHours(9)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => now()->addHours(2)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLC091', 'expires' => now()->addHours(5)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ203', 'expires' => now()->addHours(9)]);

    $events = app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin());

    expect($events)->toHaveCount(1)
        ->and($events->first()->expires)->toEqual(now()->addHours(5));
});

test('an open-ended covering row makes the event open-ended at that location', function () {
    $watch = coverageReaderWatch(['expires' => now()->addHours(9)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => now()->addHours(2)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLC091', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->first()->expires)->toBeNull();
});

test('the Active now list and the daily summary agree on the event and its end', function () {
    $destin = coverageReaderDestin();
    $watch = coverageReaderWatch(['expires' => now()->addHours(9)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => now()->addHours(4)]);

    $summary = app(GetActiveAlertsForLocation::class)($destin);
    $activeNow = (new GetActiveNowAtMyLocations)($destin->user)['events'];

    expect($summary)->toHaveCount(1)
        ->and($activeNow)->toHaveCount(1)
        ->and($summary[0]['event'])->toBe('Hurricane Watch')
        ->and($activeNow[0]['event'])->toBe('Hurricane Watch')
        ->and($summary[0]['expires'])->toBe($activeNow[0]['expires'])
        ->and($summary[0]['expires'])->toBe(now()->addHours(4)->setTimezone('America/Chicago')->toIso8601String());
});

/** A flash flood follow-up statement (no polygon of its own) for the event whose zone row carries a polygon around 40,-105. */
function coverageReaderStatementSetup(): array
{
    Queue::fake();
    $flashFlood = Product::where('code', 'FF.W')->sole();
    $key = ['vtec_office' => 'KBOU', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026];
    $event = ActiveWwa::factory()->create(['product_id' => $flashFlood->id, ...$key, 'ugc_codes' => ['COZ040']]);
    ActiveWwaArea::factory()->for($event)->withPolygon('POLYGON((-105.1 39.9,-105.1 40.1,-104.9 40.1,-104.9 39.9,-105.1 39.9))')->create(['ugc_code' => 'COZ040']);
    $statement = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        ...$key, 'product_id' => $flashFlood->id, 'ugc_codes' => ['COZ040'], 'polygon' => null, 'vtec_action' => 'CON', 'cccc' => 'kbou',
    ]));

    return [$flashFlood, $statement];
}

function coverageReaderPolygonRule(Location $location, Product $product): AlertRule
{
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => null]);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    return $rule;
}

test('a follow-up statement with no polygon is matched against the event\'s zone polygons', function () {
    [$flashFlood, $statement] = coverageReaderStatementSetup();
    $inside = Location::factory()->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ099']);
    $outside = Location::factory()->create(['lat' => 41.5, 'lng' => -103.0, 'forecast_zone_ugc' => 'COZ040']);
    $insideRule = coverageReaderPolygonRule($inside, $flashFlood);
    $outsideRule = coverageReaderPolygonRule($outside, $flashFlood);

    (new MatchWeatherAlertRules)($statement);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $insideRule->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $outsideRule->id);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ActiveEventCoverageReadersTest.php'`
Expected: FAIL (the reader still looks at the event row's own `ugc_codes` and `polygon`).

- [ ] **Step 3: Read the zone rows in `GetActiveAlertsForLocation`**

In `app/Actions/Weather/GetActiveAlertsForLocation.php`: add `use App\Models\ActiveWwaArea;`; replace the class docblock with:

```php
/**
 * Watches/warnings/advisories/statements currently in effect at a location,
 * read from our own active_wwas table (the same live data alert rules match
 * against), independent of any subscriber's alert rules.
 *
 * Where an event is in effect is its zone rows (active_wwa_areas), not the event row's own
 * ugc_codes/polygon, which only describe the latest bulletin of an event issued as many. An
 * Active, unexpired zone row covers the location when its polygon (if it has one) contains the
 * point -- the polygon is the authoritative extent, so a polygon that excludes the point excludes
 * the alert even if the zone is listed -- or, for a row without a polygon, when its zone is one of
 * the location's zone or county codes. The event is listed once, with the latest end of the rows
 * that cover the location (none if any has none), set on the returned model for this lookup only.
 * An event with no zone rows at all (a row from before they existed) falls back to its own polygon
 * and zones, and its own end.
 */
```

and replace the `activeFor()` method with the following, adding the three helper methods after it (before `sorted()`):

```php
    /**
     * @return Collection<int, ActiveWwa> with product loaded, sorted by family rank, expiry, id
     */
    public function activeFor(Location $location, bool $includeTest = false): Collection
    {
        if (! $location->hasFix()) {
            return new Collection;
        }

        $zones = $this->zonesFor($location);
        $lng = (float) $location->lng;
        $lat = (float) $location->lat;

        $alerts = ActiveWwa::query()
            ->with('product')
            ->where('status', 'Active')
            ->when(! $includeTest, fn (Builder $query) => $query->where('is_test', false))
            ->where(function (Builder $query) use ($zones, $lng, $lat): void {
                $query->whereHas('areas', fn (Builder $areas) => $this->coveringAreas($areas, $zones, $lng, $lat))
                    ->orWhere(fn (Builder $own) => $this->coveredByOwnColumns($own->whereDoesntHave('areas'), $zones, $lng, $lat));
            })
            ->get();

        $this->useCoveringExpiry($alerts, $zones, $lng, $lat);

        return self::sorted($alerts);
    }

    /**
     * Zone rows that are Active, not yet ended, and cover the point (see the class docblock).
     * Columns are qualified because this is also used inside a subquery on active_wwas.
     *
     * @param  Builder<ActiveWwaArea>  $areas
     * @param  array<int, string>  $zones
     * @return Builder<ActiveWwaArea>
     */
    private function coveringAreas(Builder $areas, array $zones, float $lng, float $lat): Builder
    {
        return $areas
            ->where('active_wwa_areas.status', 'Active')
            ->where(fn (Builder $ends) => $ends->whereNull('active_wwa_areas.expires')->orWhere('active_wwa_areas.expires', '>', now()))
            ->where(function (Builder $query) use ($zones, $lng, $lat): void {
                $query->whereRaw('(active_wwa_areas.polygon IS NOT NULL AND ST_Contains(active_wwa_areas.polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)))', [$lng, $lat]);

                if ($zones !== []) {
                    $query->orWhere(fn (Builder $byZone) => $byZone->whereNull('active_wwa_areas.polygon')->whereIn('active_wwa_areas.ugc_code', $zones));
                }
            });
    }

    /**
     * The pre-zone-row rule, kept for an event that has no zone rows: its own polygon when it has
     * one, else its own zone codes, and its own end.
     *
     * @param  Builder<ActiveWwa>  $query
     * @param  array<int, string>  $zones
     * @return Builder<ActiveWwa>
     */
    private function coveredByOwnColumns(Builder $query, array $zones, float $lng, float $lat): Builder
    {
        return $query
            ->where(fn (Builder $ends) => $ends->whereNull('expires')->orWhere('expires', '>', now()))
            ->where(function (Builder $covers) use ($zones, $lng, $lat): void {
                $covers->whereRaw('(polygon IS NOT NULL AND ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)))', [$lng, $lat]);

                if ($zones !== []) {
                    $covers->orWhere(function (Builder $byZone) use ($zones): void {
                        $byZone->whereNull('polygon')->where(function (Builder $codes) use ($zones): void {
                            foreach ($zones as $zone) {
                                $codes->orWhereJsonContains('ugc_codes', $zone);
                            }
                        });
                    });
                }
            });
    }

    /**
     * Replaces each event's end with the latest end of the zone rows that cover the location (none
     * when any covering row has none). The models are never saved; an event read from its own
     * columns (no covering rows) keeps its own end.
     *
     * @param  Collection<int, ActiveWwa>  $alerts
     * @param  array<int, string>  $zones
     */
    private function useCoveringExpiry(Collection $alerts, array $zones, float $lng, float $lat): void
    {
        if ($alerts->isEmpty()) {
            return;
        }

        $covering = $this->coveringAreas(ActiveWwaArea::query(), $zones, $lng, $lat)
            ->whereIn('active_wwa_areas.active_wwa_id', $alerts->modelKeys())
            ->get(['active_wwa_areas.active_wwa_id', 'active_wwa_areas.expires'])
            ->groupBy('active_wwa_id');

        foreach ($alerts as $wwa) {
            $rows = $covering->get($wwa->id);

            if ($rows !== null) {
                $wwa->expires = $rows->contains(fn (ActiveWwaArea $row): bool => $row->expires === null) ? null : $rows->max('expires');
            }
        }
    }
```

- [ ] **Step 4: Read the zone polygons in the follow-up-statement lookup**

In `app/Actions/Weather/MatchWeatherAlertRules.php` replace `matchesActiveWwaPolygon()` and its docblock with:

```php
    /**
     * A VTEC-class product's persisted zone polygons take priority over the triggering row's own
     * (possibly null on a continuation statement like SVS/FFS) polygon -- see
     * ApplySegmentToCoverage, which is what keeps them current across an event's whole lifetime.
     * The location is covered if any zone row's polygon contains it. Returns null (not false) both
     * when the product isn't VTEC-class at all and when the event has no polygon, so the caller
     * falls back to the row's own polygon field either way. An event with no zone rows at all
     * (a row from before they existed) is read from its own polygon column.
     */
    private function matchesActiveWwaPolygon(IngestedProduct $product, float $lng, float $lat): ?bool
    {
        if ($product->vtec_office === null) {
            return null;
        }

        $activeWwa = ActiveWwa::query()
            ->forProduct($product)
            ->first();

        if ($activeWwa === null) {
            return null;
        }

        $areas = DB::selectOne(
            'SELECT count(*) AS zones, count(polygon) AS polygons, coalesce(bool_or(ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269))), false) AS contains FROM active_wwa_areas WHERE active_wwa_id = ?',
            [$lng, $lat, $activeWwa->id],
        );

        if ((int) $areas->zones > 0) {
            return (int) $areas->polygons > 0 ? $this->resultIsTrue($areas) : null;
        }

        $own = DB::selectOne(
            'SELECT ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains FROM active_wwas WHERE id = ? AND polygon IS NOT NULL',
            [$lng, $lat, $activeWwa->id],
        );

        return $own === null ? null : $this->resultIsTrue($own);
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ActiveEventCoverageReadersTest.php tests/Feature/GetActiveAlertsForLocationTest.php tests/Feature/GetActiveNowAtMyLocationsTest.php tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/BulletinSegmentMatchingTest.php tests/Feature/BuildDailySummaryReportTest.php'`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Actions/Weather/GetActiveAlertsForLocation.php app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/ActiveEventCoverageReadersTest.php'
git add app/Actions/Weather/GetActiveAlertsForLocation.php app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/ActiveEventCoverageReadersTest.php
git commit -m "Active now: read where an event is in effect from its zone rows

Destin's Hurricane Watch is listed because its own zone is a row, however many bulletins
arrived after it. A follow-up statement is matched against the zone polygons.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `active-wwas:rebuild` repairs events from their stored segments

**Files:**
- Create: `app/Console/Commands/RebuildActiveWwaCoverage.php`
- Test: `tests/Feature/RebuildActiveWwaCoverageTest.php` (create)

**Interfaces:**
- Consumes: `ApplySegmentToCoverage` (Task 2), `IngestedProductSegment`/`BuildBulletinSegments` (project 1).
- Produces: artisan command `active-wwas:rebuild {--days=2}`: for every `Active` event that has a segment from a bulletin stored in the last N days, deletes its zone rows and replays all its stored segments (oldest bulletin first) through `ApplySegmentToCoverage`. Idempotent. Sends nothing, dispatches no job, writes no delivery log, and touches no cancelled or expired event.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/RebuildActiveWwaCoverageTest.php`:

```php
<?php

use App\Actions\Pipeline\BuildBulletinSegments;
use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 22:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A stored CAP message for one zone of the Mobile Hurricane Watch, with its segment built, made without the observer. */
function rebuildStoredMessage(string $zone, string $action, Carbon $receivedAt, array $attributes = []): IngestedProduct
{
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'format' => 'cap', 'text' => '<?xml version="1.0"?><alert/>', 'cccc' => 'kwbc', 'is_test' => false,
        'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
        'vtec_action' => $action, 'ugc_codes' => [$zone], 'received_at' => $receivedAt, ...$attributes,
    ]));
    app(BuildBulletinSegments::class)($bulletin);

    return $bulletin;
}

/** The event as the old code left it: one zone, the last one seen. */
function rebuildCollapsedEvent(array $attributes = []): ActiveWwa
{
    $event = ActiveWwa::factory()->create([
        'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
        'ugc_codes' => ['FLZ203'], 'expires' => null, 'status' => 'Active', 'is_test' => false, ...$attributes,
    ]);
    ActiveWwaArea::factory()->for($event)->create(['ugc_code' => 'FLZ203', 'expires' => null]);

    return $event;
}

function rebuildZoneStates(ActiveWwa $event): array
{
    return $event->areas()->orderBy('ugc_code')->pluck('status', 'ugc_code')->all();
}

test('restores the zones a collapsed event lost, so Destin sees the Hurricane Watch again', function () {
    $destin = Location::factory()->create(['lat' => 30.39, 'lng' => -86.45, 'forecast_zone_ugc' => 'FLZ206', 'county_ugc' => 'FLC091']);
    $event = rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6));
    rebuildStoredMessage('ALZ266', 'NEW', now()->subHours(6)->addMinute());
    rebuildStoredMessage('FLZ203', 'CON', now()->subHour());
    expect(app(GetActiveAlertsForLocation::class)->activeFor($destin)->isEmpty())->toBeTrue();

    $this->artisan('active-wwas:rebuild')->expectsOutputToContain('Rebuilt the zones of 1 active event')->assertSuccessful();

    expect(rebuildZoneStates($event))->toBe(['ALZ266' => 'Active', 'FLZ203' => 'Active', 'FLZ206' => 'Active'])
        ->and(app(GetActiveAlertsForLocation::class)->activeFor($destin)->modelKeys())->toBe([$event->id]);
});

test('replays the stored bulletins oldest first, so a cancel ends only its own zone', function () {
    $event = rebuildCollapsedEvent();
    // Stored out of order on purpose: the replay orders by when each bulletin was received.
    rebuildStoredMessage('FLZ206', 'CAN', now()->subHour());
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6));
    rebuildStoredMessage('FLZ203', 'NEW', now()->subHours(6));

    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    expect(rebuildZoneStates($event))->toBe(['FLZ203' => 'Active', 'FLZ206' => 'Cancelled'])
        ->and($event->fresh()->status)->toBe('Active');
});

test('an event whose stored bulletins end every zone is brought up to date as ended', function () {
    $event = rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ203', 'NEW', now()->subHours(6));
    rebuildStoredMessage('FLZ203', 'CAN', now()->subHour());

    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    expect($event->fresh()->status)->toBe('Cancelled');
});

test('running it again changes nothing', function () {
    $event = rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6));
    rebuildStoredMessage('FLZ203', 'NEW', now()->subHours(6));

    $this->artisan('active-wwas:rebuild')->assertSuccessful();
    $first = rebuildZoneStates($event);
    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    expect(rebuildZoneStates($event))->toBe($first)
        ->and($event->areas()->count())->toBe(2);
});

test('events that are cancelled, or have no stored bulletin in the window, are left alone', function () {
    $cancelled = rebuildCollapsedEvent(['status' => 'Cancelled', 'vtec_event_number' => '2001']);
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6), ['vtec_event_number' => '2001']);
    $stale = rebuildCollapsedEvent(['vtec_event_number' => '2002']);
    rebuildStoredMessage('FLZ206', 'NEW', now()->subDays(5), ['vtec_event_number' => '2002']);

    $this->artisan('active-wwas:rebuild')->expectsOutputToContain('Rebuilt the zones of 0 active events')->assertSuccessful();

    expect(rebuildZoneStates($cancelled))->toBe(['FLZ203' => 'Active'])
        ->and(rebuildZoneStates($stale))->toBe(['FLZ203' => 'Active']);
});

test('--days widens the window', function () {
    $stale = rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ206', 'NEW', now()->subDays(5));

    $this->artisan('active-wwas:rebuild', ['--days' => 7])->assertSuccessful();

    expect(rebuildZoneStates($stale))->toBe(['FLZ206' => 'Active']);
});

test('a test event is rebuilt only from test bulletins', function () {
    $real = rebuildCollapsedEvent(['is_test' => false]);
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6), ['is_test' => true]);
    rebuildStoredMessage('ALZ266', 'NEW', now()->subHours(6));

    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    expect(rebuildZoneStates($real))->toBe(['ALZ266' => 'Active']);
});

test('sends nothing, queues nothing and writes no delivery log', function () {
    Queue::fake();
    rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6));

    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(DeliveryLog::count())->toBe(0);
});

test('--days must be at least 1', function () {
    $this->artisan('active-wwas:rebuild', ['--days' => 0])
        ->expectsOutputToContain('--days must be at least 1')
        ->assertFailed();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/RebuildActiveWwaCoverageTest.php'`
Expected: FAIL (the command does not exist).

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/RebuildActiveWwaCoverage.php`:

```php
<?php

namespace App\Console\Commands;

use App\Actions\Pipeline\ApplySegmentToCoverage;
use App\Models\ActiveWwa;
use App\Models\IngestedProductSegment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-derives the zones of the active events from their stored bulletin segments. An event that
 * the old code had collapsed to the last bulletin's zones (21% of them) is brought back to every
 * zone its stored bulletins name, and each zone ends where a stored cancel, expiry or upgrade
 * ended it. Needs the segments to exist first: run bulletins:parse-segments for at least the same
 * number of days.
 *
 * For every Active event with a segment from a bulletin stored in the last N days it deletes the
 * event's zone rows and replays ALL of the event's stored segments, oldest bulletin first,
 * through ApplySegmentToCoverage in one transaction. Cancelled and expired events are left alone.
 * Idempotent, and it only writes active_wwas and active_wwa_areas: no matching, no job, no
 * delivery log, so it cannot send a notification.
 */
class RebuildActiveWwaCoverage extends Command
{
    protected $signature = 'active-wwas:rebuild {--days=2 : Rebuild the active events that have a stored bulletin from the last N days}';

    protected $description = 'Re-derive the zones of the active events from their stored bulletin segments; sends nothing';

    public function handle(ApplySegmentToCoverage $apply): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $rebuilt = 0;

        ActiveWwa::query()
            ->where('status', 'Active')
            ->whereExists(function ($query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from('ingested_product_segments as s')
                    ->join('ingested_products as b', 'b.id', '=', 's.ingested_product_id')
                    ->whereColumn('s.vtec_office', 'active_wwas.vtec_office')
                    ->whereColumn('s.vtec_phenomenon', 'active_wwas.vtec_phenomenon')
                    ->whereColumn('s.vtec_significance', 'active_wwas.vtec_significance')
                    ->whereColumn('s.vtec_event_number', 'active_wwas.vtec_event_number')
                    ->whereColumn('s.vtec_year', 'active_wwas.vtec_year')
                    ->whereColumn('b.is_test', 'active_wwas.is_test')
                    ->where('b.received_at', '>=', $cutoff);
            })
            ->chunkById(100, function ($events) use ($apply, &$rebuilt): void {
                foreach ($events as $wwa) {
                    DB::transaction(function () use ($wwa, $apply): void {
                        $wwa->areas()->delete();

                        foreach ($this->storedSegments($wwa) as $segment) {
                            $apply($segment->ingestedProduct, $segment);
                        }
                    });

                    $rebuilt++;
                }
            });

        $this->info("Rebuilt the zones of {$rebuilt} active ".Str::plural('event', $rebuilt).'.');

        return self::SUCCESS;
    }

    /**
     * Every stored segment of the event (same VTEC key, same test flag), oldest bulletin first.
     *
     * @return Collection<int, IngestedProductSegment>
     */
    private function storedSegments(ActiveWwa $wwa): Collection
    {
        $key = collect(ActiveWwa::vtecKeyFor($wwa))
            ->mapWithKeys(fn (mixed $value, string $column): array => ["ingested_product_segments.{$column}" => $value])
            ->all();

        return IngestedProductSegment::query()
            ->select('ingested_product_segments.*')
            ->join('ingested_products as bulletins', 'bulletins.id', '=', 'ingested_product_segments.ingested_product_id')
            ->where($key)
            ->where('bulletins.is_test', $wwa->is_test)
            ->orderBy('bulletins.received_at')
            ->orderBy('bulletins.id')
            ->orderBy('ingested_product_segments.position')
            ->with('ingestedProduct')
            ->get();
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/RebuildActiveWwaCoverageTest.php'`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --format agent app/Console/Commands/RebuildActiveWwaCoverage.php tests/Feature/RebuildActiveWwaCoverageTest.php'
git add app/Console/Commands/RebuildActiveWwaCoverage.php tests/Feature/RebuildActiveWwaCoverageTest.php
git commit -m "Active events: add active-wwas:rebuild to repair collapsed events from stored segments

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Record the rules, then verify the whole change

**Files:**
- Modify: `.ai/rules/pipeline.md`, `.ai/rules/models.md`

- [ ] **Step 1: Record the rules**

Append this section to `.ai/rules/pipeline.md`:

```markdown

## An active event is many zones: coverage lives in active_wwa_areas, not on the event row
`active_wwas` is one row per VTEC event, but NWS issues an event as many bulletins (the CAP feed sends one message per zone group), so the row's own `ugc_codes` and `polygon` only describe the LAST bulletin and used to overwrite each other: 21% of active events had lost zones, which is how a Hurricane Watch in effect at Destin went missing from Active now. Where an event is in effect is `active_wwa_areas` (one row per event and zone, with its own status, end and optional polygon). `ApplySegmentToCoverage`, called by `SyncActiveWwa` for every segment of a bulletin, is the only writer: NEW/CON/EXT/EXA/EXB/COR/ROU set the segment's zones Active, CAN and UPG set them Cancelled, EXP sets them Expired, each only for the segment's OWN zones, and the event row's status, start and end are derived from its zones, never set from the last bulletin's action. A zone row written by a newer bulletin (`applied_at`) is not overwritten by a late older one, a CAP polygon only fills a zone that has none, and a text segment without a polygon never clears one. Readers (`GetActiveAlertsForLocation`, the polygon lookup for a follow-up statement) read the rows, the polygon authoritative per row; the event's own `ugc_codes`/`polygon` are a fallback for an event with no rows only. `active-wwas:rebuild` repairs events from their stored segments (run `bulletins:parse-segments` first) and, like `ApplySegmentToCoverage`, sends nothing. An upgrade (UPG) now ends its zones; it used to leave the old event Active until it expired.
```

Append this section to `.ai/rules/models.md`:

```markdown

## ActiveWwa coverage is read through its areas
Whether an event covers a place comes from `active_wwa_areas` (`ActiveWwa::areas()`). `active_wwas.ugc_codes` and `polygon` describe only the latest bulletin and are a fallback for events with no rows: do not add a new reader of them. Filter zone rows by their own `status` and `expires`, never by the event's `expires` (the event's end is the latest end of its active zones, so it would keep a zone that ended earlier). A new lookup of an event from a bulletin still goes through `forProduct()`, or `forSegment()` for one event of a bulletin, so the `is_test` scope applies.
```

- [ ] **Step 2: Run the affected tests**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ActiveWwaAreaTest.php tests/Feature/ApplySegmentToCoverageTest.php tests/Feature/SyncActiveWwaSegmentsTest.php tests/Feature/SyncActiveWwaTest.php tests/Feature/ActiveEventCoverageReadersTest.php tests/Feature/RebuildActiveWwaCoverageTest.php tests/Feature/GetActiveAlertsForLocationTest.php tests/Feature/GetActiveNowAtMyLocationsTest.php tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/FactoryResetActionsTest.php'`
Expected: PASS.

- [ ] **Step 3: Run the whole suite in parallel**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`
Expected: PASS (about 3 minutes). A failure in a test this plan did not touch is a regression to investigate: the likely cause is a test that asserts the old collapsed behaviour (a cancel or upgrade ending a whole event).

- [ ] **Step 4: Commit the rules**

```bash
git add .ai/rules/pipeline.md .ai/rules/models.md
git commit -m "Docs: record that an active event's coverage is its zone rows

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Hand the deployment steps to the owner (do not run them)**

Report these in the final message; the owner runs them, after project 1's own deployment steps:

1. `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && php artisan migrate'` (creates `active_wwa_areas` and back-fills it from the current active events).
2. `docker restart wxnotify-pipeline-api wxnotify-nextgen-front-end-1`.
3. `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && php artisan bulletins:parse-segments --days=2'` (project 1; skip if already run), then `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && php artisan active-wwas:rebuild --days=2'`. Neither sends anything.
4. Check Destin: the Hurricane Watch should now appear in the Active now widget.

---

## Self-review (spec coverage)

| Spec requirement | Task |
| --- | --- |
| `active_wwa_areas` table (cascade, status, polygon + GiST, effective, expires, latest segment, applied_at, unique pair, `ugc_code` index), `ActiveWwaArea`, `ActiveWwa::areas()` | 1 |
| Migration back-fills areas from every active event's zones and polygon | 1 |
| `WipePipelineData` / `PreviewFactoryReset` include the table | 1 |
| `ApplySegmentToCoverage`: find/create the event by the segment's key; NEW/CON/EXT/EXA/EXB/COR/ROU Active; CAN, UPG Cancelled; EXP Expired; only the segment's zones | 2 |
| Late older bulletin ignored by `applied_at`; CAP polygon fills but never replaces; text polygon replaces; derived status / expires / effective | 2 |
| Every VTEC line gets its event row; motion on the first segment's event only | 3 |
| `SyncActiveWwa` applies each segment, dispatch rules unchanged | 3 |
| `activeFor()` through covering rows, polygon authoritative per row, once per event with the covering rows' expiry, fallback to own columns | 4 |
| `matchesActiveWwaPolygon()` through the zone polygons | 4 |
| `active-wwas:rebuild {--days=2}`: replay stored segments oldest first, idempotent, sends nothing, leaves ended events | 5 |
| Rules recorded (`pipeline.md`, `models.md`) | 6 |
| Out of scope respected (per-zone motion, dropping the legacy columns, catalog additions, a scheduled expiry sweep) | not touched |
