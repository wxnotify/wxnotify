# AWIPS Basemap Shapefile Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Poll NOAA's AWIPS Shapefile pages for all 7 named layers (9 actual tracked rows), stage new versions, let an admin review a diff and activate/rollback/dismiss them via an atomic table swap, and let admin-entered attribute overrides survive every reimport.

**Architecture:** PostGIS replaces plain Postgres. One code-level registry (`ShapefileLayerDefinitions`) holds the per-layer scraping/parsing/table config; three small DB tables (`shapefile_layers`, `shapefile_layer_versions`, `shapefile_record_overrides`) track state; one generic pipeline (`ScanShapefileLayer` → `ImportShapefileVersion` → `ActivateShapefileVersion`/`RollbackShapefileLayer`/`DismissShapefileVersion`) drives all 9 layers through per-geography live/staging table pairs swapped with `ALTER TABLE ... RENAME`. One `ShapefileLayerResource` in the `admin` Filament panel exposes it.

**Tech Stack:** Laravel 13 / PHP 8.5, PostgreSQL via `imresamu/postgis:18-3.6` (a multi-arch fork of `postgis/postgis` — the official image has no arm64 build, which fails outright on Apple Silicon dev machines; corrected during Task 1's execution), `clickbar/laravel-magellan` (Eloquent geometry casts), `symfony/dom-crawler` + `symfony/css-selector` (HTML scraping), `phpmyadmin/shapefile` (`.shp`/`.dbf` parsing), Filament v5, Pest.

---

## Context (read this first — do not re-derive)

Full rationale lives in `docs/superpowers/specs/2026-09-07-awips-shapefile-management-design.md` — read it if anything below needs more background. This section repeats only what implementers must not re-verify.

**Verified facts, don't re-check these:**

- This app's `pgsql` service is currently plain `postgres:18-alpine` with PostGIS unavailable to install. Safe to swap images — `APP_ENV=local`, no deployed instance, no production data.
- **Marine Zones' page has 3 rows, not 2** — Coastal, Offshore, and High Seas Marine Zones, all on `/gis/MarineZones`, sharing one `.dbf` attribute schema. This corrects an error in the original design pass (caught and fixed before this plan was written).
- **Every layer's real attribute schema is verified** (fetched each NOAA page's own "Shapefile Attributes" table, cross-checked Public Zones against a real downloaded `.dbf` header) — see the per-migration tasks below for exact fields. LON/LAT column widths vary slightly between what different pages document (10,5 vs 19,11) — this plan normalizes every LON/LAT column to `decimal(12, 7)` everywhere rather than copying NOAA's inconsistent per-page widths.
- **The download-table HTML structure is real and consistent** across all 6 shapefile pages: the *first* `<table>` on the page has `<tbody><tr>` rows where a "new version group" row has **7 `<td>`s** (description, date, zip link, record count, checksum+upload-date, change-history link, metadata link — the last two `rowspan="2"`) and a "same group, older/newer version" continuation row has exactly **4 `<td>`s** (date, zip link, record count, checksum+upload-date). Verified directly against saved fixture HTML for Public Zones (1 group) and Marine Zones (3 groups) — see Task 15.
- The Zone–County Correlation page (`/gis/ZoneCounty`) has a *different*, simpler 3-column table with no `<thead>` — its header row is a plain `<tr>` whose first `<td>` text is literally "Description". No checksum is published for this file; change detection there is filename-only. Its actual downloaded content is pipe-delimited text: `STATE|ZONE|CWA|NAME|STATE_ZONE|COUNTY|FIPS|TIME_ZONE|FE_AREA|LAT|LON` — verified against a real downloaded file (4,836 real data lines, no header row).
- Every shapefile's `.prj` sidecar declares **NAD83 (GCS_North_American_1983)**, i.e. **EPSG:4269** — not WGS84/4326. All geometry columns in this plan use SRID 4269 to match the source data exactly, avoiding an unnecessary reprojection. (Downstream consumers doing `ST_Contains`/`ST_DWithin` against WGS84 points will need `ST_Transform` — that's the Alert Engine's future concern, not this plan's.)
- **`fahiem/filament-pinpoint` (this app's existing Leaflet convention) cannot render the boundary map** — verified by reading its source (`Pinpoint`/`PinpointEntry` are single-point marker pickers/viewers only, no GeoJSON/MultiPolygon layer support). Task 31 instead writes a small custom Leaflet view loading Leaflet 1.9.4 from the same public CDN (`unpkg.com/leaflet@1.9.4`) Pinpoint already uses, guarded by the same element ids so it won't double-load.
- `clickbar/laravel-magellan` v2.2.0 is the real, current, actively-maintained package (`clickbar/laravel-eloquent-spatial` is dead — a 404). Verified real class names: `Clickbar\Magellan\Data\Geometries\MultiPolygon` (Eloquent cast target), and this app's target Laravel version already ships native `Blueprint::geometry($column, $subtype = null, $srid = 0)` — verified by reading `vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php:1516`. This plan uses that native method (Magellan's own docs recommend it over its bespoke helpers) rather than Magellan-specific migration helpers.
- **Foundational fix made during Task 20's execution — the `dbase` PHP extension is required and was missing**: `phpmyadmin/shapefile` only reads/writes `.dbf` attribute data through PHP's `dbase_*` functions (confirmed by reading `ShapeFile.php`'s `createDBFFile()`/`openDBFFile()` directly) — without the `dbase` extension loaded, those methods silently no-op (`return true` with no file written/read) rather than erroring, so the failure surfaced downstream as a missing `.dbf` file, not a clear error. The stock Sail 8.5 image doesn't ship this extension, and it isn't available via apt (only PECL). Verified it *does* compile cleanly against PHP 8.5 (`pecl install dbase` succeeds, produces a working `dbase.so`). Fixed by publishing Sail's runtime Dockerfile into a project-owned copy — `docker/8.5/` (copied from `vendor/laravel/sail/runtimes/8.5/`, plus `RUN pecl install dbase && echo "extension=dbase.so" > /etc/php/8.5/cli/conf.d/99-dbase.ini` inserted after the main package-install block) — and repointing both `compose.yaml` build contexts (`laravel.test` and `queue`, which share the `sail-8.5/app` image) from the vendor path to `./docker/8.5`. This is now a **prerequisite step before Task 20 can run**: rebuild with `docker compose build laravel.test && docker compose up -d laravel.test queue`, then confirm with `docker exec wxnotify-dashboard-laravel.test-1 php -m | grep dbase`.
- `phpmyadmin/shapefile` v4.0.1's real, verified (read from its own source and official examples) write/read API:
  - Read: `(new ShapeFile(ShapeType::Polygon))->loadFromFile($globPath)` (`$globPath` like `/tmp/xyz/z_18mr25.*`) then iterate `$shp->records` — each `$record->dbfData` is an associative array of DBF fields (e.g. `$record->dbfData['STATE']`), and `$record->shpData['parts']` is a list of `['points' => [['x' => float, 'y' => float], ...]]` per ring.
  - Write (used to build small test fixtures — no giant national files get committed): `new ShapeFile(ShapeType::Polygon, ['xmin'=>.., 'ymin'=>.., 'xmax'=>.., 'ymax'=>..])`, `new ShapeRecord(ShapeType::Polygon)`, `$record->addPoint(['x'=>.., 'y'=>..], $partIndex)` per ring point, `$shp->addRecord($record)`, `$shp->setDBFHeader([['FIELDNAME', 'C', $width, $decimals], ...])`, `$shp->records[$i]->dbfData['FIELD'] = $value`, `$shp->saveToFile($globPath)`.
  - The ESRI shapefile Polygon type packs multiple rings ("parts") per record; by the ESRI spec, an outer ring is wound clockwise and a hole is wound counter-clockwise. Task 19 builds a small, real ring-assembler using the standard shoelace-sum sign test to correctly group holes with their enclosing ring into proper `MultiPolygon` WKT — this is not a placeholder, it is the textbook-correct approach.
- Geometry is written via raw parameterized `ST_GeomFromText('MULTIPOLYGON(...)', 4269)` SQL (guaranteed-correct, no dependency on an unverified ORM geometry-builder API for the bulk write path) and read back through Magellan's `MultiPolygon` Eloquent cast (verified API) wherever a model needs to display it; the admin records table and map endpoint both read geometry via plain `ST_AsGeoJSON` — no Magellan dependency needed there either.
- **Testing a Filament resource that lives on the `admin` panel** (this app's default panel is `dashboard`) needs `Filament::setCurrentPanel('admin')` in the test's `beforeEach()` — without it `livewire()` throws `RouteNotFoundException`. (Recorded in `.ai/rules/filament.md`.)
- `app/Filament/Admin/Resources` is auto-discovered by `AdminPanelProvider` — a new `ShapefileLayerResource` under that path needs no manual registration.
- No scheduler exists yet anywhere in this app (`routes/console.php` only has the stock `inspire` command; `bootstrap/app.php` has no `->withSchedule()` call). Task 18 adds the first one.
- Tests run inside the Sail container: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && <command>"`. `vendor/bin/pest` / `php artisan` fail with a DNS error if run on the bare host (Docker-internal hostnames like `pgsql` don't resolve there).
- **`tests/Pest.php` only binds `RefreshDatabase` to `tests/Feature`, not `tests/Unit`** — confirmed by reading it directly. Any test that touches Eloquent/factories/the database belongs in `tests/Feature`, matching this project's own stated convention ("Write a unit test only for logic that does not use the framework"). This was caught (and fixed, via `git mv`) after Task 3 was originally executed with its DB-backed test misplaced under `tests/Unit/` — every `tests/Unit/...` path named below has already been corrected to `tests/Feature/...` wherever the test actually needs the database (Tasks 3, 5, and 14); the genuinely framework-independent ones (Task 6's registry test, Task 15/16's HTML scrapers, Task 19's polygon math, Task 21's pipe-delimited parser) correctly stay under `tests/Unit/`.
- **Related, separate gotcha in the same area — `base_path()` also fails inside `tests/Unit/`**: since `tests/Unit/` never boots the Laravel app container (only `tests/Feature/` does, per the point above), `app()` there returns a bare `Illuminate\Container\Container`, not `Illuminate\Foundation\Application`, so `basePath()` doesn't exist and any Unit test calling the `base_path()` helper fails with `Call to undefined method Container::basePath()`. Tasks 15 and 16's Unit tests read fixture HTML from `tests/Fixtures/awips-shapefiles/` and originally used `base_path('tests/Fixtures/...')` like a Feature test would — both were corrected in-flight to `file_get_contents(dirname(__DIR__).'/Fixtures/awips-shapefiles/<file>.html')` instead (portable, no framework dependency; `dirname(__DIR__)` from `tests/Unit/SomeTest.php` resolves to `tests/`). The code below already reflects this fix. Task 17's `tests/Feature/ScanShapefileLayerTest.php` reads the same fixture files via plain `base_path(...)` further down — that one is correct as written, since it's a Feature test.

**Marine gets 3 separate physical table pairs, not one shared table** — a correction made while writing this plan (caught during Task 25's design, before any code was written, not after). The design doc's original sketch had Coastal/Offshore/High Seas sharing one `marine_zones` table with a `zone_type` discriminator. That breaks the atomic-swap activation model: `ALTER TABLE ... RENAME` swaps an entire physical table, and the 3 Marine sub-layers scan/import/activate independently (NOAA can update Coastal without touching Offshore) — activating Coastal alone would swap Offshore's and High Seas's live rows out from under them too, since all three would live in the same physical table. Giving each sub-layer its own table pair (`marine_zones_coastal`/`_staging`, `marine_zones_offshore`/`_staging`, `marine_zones_high_seas`/`_staging`) makes Marine behave exactly like every other layer — one table pair, one independent swap. Each table still carries a `zone_type` column (constant per table) for labeling/future-UNION convenience, but it's no longer part of the natural key. No consumer in this plan queries "all marine zones" as one set — that's left for whenever the future Alert Engine needs it (YAGNI: don't build a cross-table view with no current reader, especially since a naive `VIEW` over these tables would itself go stale across a rename-swap, needing its own recreate-after-swap step that has no user yet).

**Naming used throughout this plan** (all 9 `shapefile_layers` rows, matching `ShapefileLayerDefinitions` keys):

| `key` | live table | format | natural key |
|---|---|---|---|
| `public_zones` | `public_zones` | shapefile | `state_zone` |
| `counties` | `counties` | shapefile | `fips` |
| `cwa_boundaries` | `cwa_boundaries` | shapefile | `cwa` |
| `marine_zones_coastal` | `marine_zones_coastal` | shapefile | `zone_id` |
| `marine_zones_offshore` | `marine_zones_offshore` | shapefile | `zone_id` |
| `marine_zones_high_seas` | `marine_zones_high_seas` | shapefile | `zone_id` |
| `fire_zones` | `fire_zones` | shapefile | `state_zone` |
| `us_states` | `us_states` | shapefile | `state` |
| `zone_county_correlation` | `zone_county_correlations` | correlation | `state_zone`+`fips` |

**Correction made during execution (Task 10)**: NOAA's real Marine Zones DBF schema names its zone-identifier field `ID` — the original plan mapped this straight to a db column also named `id`, which collides with Laravel's own auto-increment primary key column (`$table->id()`), also named `id`, on the same table. Postgres rejects a `CREATE TABLE` with two columns sharing a name. Fixed by renaming the mapped column to `zone_id` everywhere it appears: the 3 Marine `ShapefileLayerDefinition` entries' `naturalKeyColumns` and `attributeMap` (Task 6 — already committed at that point, patched directly), the 3 Marine table migrations (Task 10), and every later reference to it in diff/override/test code below.

---

### Task 1: Adopt PostGIS — swap the Docker image

**Files:**
- Modify: `compose.yaml`

- [ ] **Step 1: Swap the `pgsql` service image**

In `compose.yaml`, change:

```yaml
    pgsql:
        image: 'postgres:18-alpine'
```

to:

```yaml
    pgsql:
        image: 'imresamu/postgis:18-3.6'
```

**Correction made during execution**: the official `postgis/postgis:18-3.6` image publishes no `arm64` manifest (confirmed via `docker manifest inspect postgis/postgis:18-3.6` — `amd64` only) and fails to pull outright on Apple Silicon. `imresamu/postgis:18-3.6` is a well-known, actively-maintained multi-arch fork covering exactly this gap — verified via `docker manifest inspect imresamu/postgis:18-3.6` to publish both `amd64` and `arm64` manifests. Use this image, not the official one.

- [ ] **Step 2: Recreate the container and verify PostGIS is installable**

```bash
docker compose up -d pgsql
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'dd(DB::select(\"SELECT * FROM pg_available_extensions WHERE name = \\'postgis\\'\"));'"
```

Expected: one row returned (no longer zero rows) confirming the extension is now available to install.

- [ ] **Step 3: Commit**

```bash
git add compose.yaml
git commit -m "Swap pgsql image to imresamu/postgis:18-3.6 for PostGIS support

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Install spatial/parsing packages and enable the PostGIS extension

**Files:**
- Modify: `composer.json`
- Create: `database/migrations/2026_09_07_100000_enable_postgis_extension.php`

- [ ] **Step 1: Require the packages**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && composer require clickbar/laravel-magellan symfony/dom-crawler symfony/css-selector phpmyadmin/shapefile --no-interaction"
```

- [ ] **Step 2: Write the migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration enable_postgis_extension --no-interaction"
```

Replace the generated file's contents:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS postgis');
    }
};
```

- [ ] **Step 3: Run it and verify**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'dd(DB::selectOne(\"SELECT PostGIS_Version() as v\"));'"
```

Expected: a real PostGIS version string, e.g. `3.6 ...`.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock database/migrations/2026_09_07_100000_enable_postgis_extension.php
git commit -m "Add PostGIS-adjacent packages and enable the postgis extension

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: `shapefile_layers` tracking table + model

**Files:**
- Create: `database/migrations/2026_09_07_100001_create_shapefile_layers_table.php`
- Create: `app/Models/ShapefileLayer.php`
- Create: `database/factories/ShapefileLayerFactory.php`
- Test: `tests/Feature/ShapefileLayerTest.php`

- [ ] **Step 1: Write the migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_shapefile_layers_table --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shapefile_layers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('source_url');
            $table->string('last_seen_filename')->nullable();
            $table->string('last_seen_checksum')->nullable();
            $table->date('last_seen_effective_at')->nullable();
            $table->foreignId('active_version_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shapefile_layers');
    }
};
```

- [ ] **Step 2: Model**

```php
<?php

namespace App\Models;

use Database\Factories\ShapefileLayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'source_url', 'last_seen_filename', 'last_seen_checksum', 'last_seen_effective_at', 'active_version_id'])]
class ShapefileLayer extends Model
{
    /** @use HasFactory<ShapefileLayerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_seen_effective_at' => 'date',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ShapefileLayerVersion::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(ShapefileLayerVersion::class, 'active_version_id');
    }
}
```

- [ ] **Step 3: Factory**

```php
<?php

namespace Database\Factories;

use App\Models\ShapefileLayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShapefileLayer>
 */
class ShapefileLayerFactory extends Factory
{
    protected $model = ShapefileLayer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2, false),
            'name' => fake()->words(2, true),
            'source_url' => fake()->url(),
        ];
    }
}
```

- [ ] **Step 4: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefileLayerTest --no-interaction"
```

```php
<?php

use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;

test('a layer has many versions and an active version', function () {
    $layer = ShapefileLayer::factory()->create();
    $version = ShapefileLayerVersion::factory()->for($layer, 'shapefileLayer')->create();
    $layer->update(['active_version_id' => $version->id]);

    expect($layer->versions)->toHaveCount(1)
        ->and($layer->fresh()->activeVersion->id)->toBe($version->id);
});
```

- [ ] **Step 5: Run migrations and the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerTest.php --compact"
```

Expected: FAILS at this point (`ShapefileLayerVersion` doesn't exist yet) — that's correct, Task 4 creates it. Move on.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_07_100001_create_shapefile_layers_table.php app/Models/ShapefileLayer.php database/factories/ShapefileLayerFactory.php tests/Feature/ShapefileLayerTest.php
git commit -m "Add shapefile_layers tracking table and model

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: `shapefile_layer_versions` table, status enum, and model

**Files:**
- Create: `app/Enums/ShapefileVersionStatus.php`
- Create: `database/migrations/2026_09_07_100002_create_shapefile_layer_versions_table.php`
- Create: `app/Models/ShapefileLayerVersion.php`
- Create: `database/factories/ShapefileLayerVersionFactory.php`
- Test: `tests/Feature/ShapefileLayerTest.php` (from Task 3, now passes)

- [ ] **Step 1: Enum**

```php
<?php

namespace App\Enums;

enum ShapefileVersionStatus: string
{
    case Staged = 'staged';
    case Active = 'active';
    case Superseded = 'superseded';
    case Rejected = 'rejected';
}
```

- [ ] **Step 2: Migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_shapefile_layer_versions_table --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shapefile_layer_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shapefile_layer_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('checksum')->nullable();
            $table->string('source_url');
            $table->date('effective_at');
            $table->string('status');
            $table->unsignedInteger('record_count')->nullable();
            $table->json('diff_summary')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shapefile_layer_versions');
    }
};
```

Note: `source_url` here is the *specific version's* download URL (e.g. `https://www.weather.gov/source/gis/Shapefiles/WSOM/z_18mr25.zip`) — distinct from `shapefile_layers.source_url`, which is the NOAA *page* being polled. `ImportShapefileVersion` (Task 20) needs this to know what to download.

- [ ] **Step 3: Model**

```php
<?php

namespace App\Models;

use App\Enums\ShapefileVersionStatus;
use Database\Factories\ShapefileLayerVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shapefile_layer_id', 'filename', 'checksum', 'source_url', 'effective_at', 'status', 'record_count', 'diff_summary', 'activated_at'])]
class ShapefileLayerVersion extends Model
{
    /** @use HasFactory<ShapefileLayerVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'effective_at' => 'date',
            'status' => ShapefileVersionStatus::class,
            'diff_summary' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    public function shapefileLayer(): BelongsTo
    {
        return $this->belongsTo(ShapefileLayer::class);
    }
}
```

- [ ] **Step 4: Factory**

```php
<?php

namespace Database\Factories;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShapefileLayerVersion>
 */
class ShapefileLayerVersionFactory extends Factory
{
    protected $model = ShapefileLayerVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shapefile_layer_id' => ShapefileLayer::factory(),
            'filename' => fake()->slug().'.zip',
            'checksum' => fake()->md5(),
            'source_url' => fake()->url(),
            'effective_at' => fake()->date(),
            'status' => ShapefileVersionStatus::Staged,
            'record_count' => fake()->numberBetween(1, 5000),
        ];
    }
}
```

- [ ] **Step 5: Run migrations and re-run Task 3's test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/ShapefileVersionStatus.php database/migrations/2026_09_07_100002_create_shapefile_layer_versions_table.php app/Models/ShapefileLayerVersion.php database/factories/ShapefileLayerVersionFactory.php
git commit -m "Add shapefile_layer_versions table, status enum, and model

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: `shapefile_record_overrides` table and model

**Files:**
- Create: `database/migrations/2026_09_07_100003_create_shapefile_record_overrides_table.php`
- Create: `app/Models/ShapefileRecordOverride.php`
- Create: `database/factories/ShapefileRecordOverrideFactory.php`
- Test: `tests/Feature/ShapefileRecordOverrideTest.php`

- [ ] **Step 1: Migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_shapefile_record_overrides_table --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shapefile_record_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shapefile_layer_id')->constrained()->cascadeOnDelete();
            $table->string('record_key');
            $table->string('field');
            $table->string('value');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shapefile_layer_id', 'record_key', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shapefile_record_overrides');
    }
};
```

`record_key` is the natural key serialized as a single string (single-column keys stored as-is, e.g. `"NM201"`; composite keys pipe-joined, e.g. the Zone–County Correlation layer's `"NM201|35031"`) — see `ShapefileLayerDefinition::serializeNaturalKey()` in Task 6.

- [ ] **Step 2: Model**

```php
<?php

namespace App\Models;

use Database\Factories\ShapefileRecordOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shapefile_layer_id', 'record_key', 'field', 'value', 'created_by'])]
class ShapefileRecordOverride extends Model
{
    /** @use HasFactory<ShapefileRecordOverrideFactory> */
    use HasFactory;

    public function shapefileLayer(): BelongsTo
    {
        return $this->belongsTo(ShapefileLayer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 3: Factory**

```php
<?php

namespace Database\Factories;

use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShapefileRecordOverride>
 */
class ShapefileRecordOverrideFactory extends Factory
{
    protected $model = ShapefileRecordOverride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shapefile_layer_id' => ShapefileLayer::factory(),
            'record_key' => fake()->unique()->bothify('??###'),
            'field' => 'name',
            'value' => fake()->words(2, true),
            'created_by' => User::factory(),
        ];
    }
}
```

- [ ] **Step 4: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefileRecordOverrideTest --no-interaction"
```

```php
<?php

use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;

test('an override belongs to a layer and the staff user who created it', function () {
    $layer = ShapefileLayer::factory()->create();
    $user = User::factory()->create();

    $override = ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Corrected Zone Name',
        'created_by' => $user->id,
    ]);

    expect($override->shapefileLayer->id)->toBe($layer->id)
        ->and($override->createdBy->id)->toBe($user->id);
});

test('the same field cannot be overridden twice for the same record on the same layer', function () {
    $layer = ShapefileLayer::factory()->create();
    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
    ]);

    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
    ]);
})->throws(Illuminate\Database\QueryException::class);
```

- [ ] **Step 5: Run migrations and the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileRecordOverrideTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_07_100003_create_shapefile_record_overrides_table.php app/Models/ShapefileRecordOverride.php database/factories/ShapefileRecordOverrideFactory.php tests/Feature/ShapefileRecordOverrideTest.php
git commit -m "Add shapefile_record_overrides table and model

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: `ShapefileLayerDefinition` value object and the 9-row registry

**Files:**
- Create: `app/Support/Shapefiles/ShapefileLayerDefinition.php`
- Create: `app/Support/Shapefiles/ShapefileLayerDefinitions.php`
- Test: `tests/Unit/ShapefileLayerDefinitionsTest.php`

This is the code-level config every other action reads: which table, which natural key, which DBF-field-to-column mapping, which format. It cannot be database data (same reasoning this codebase already applied to `ChannelType` classes in `app/Support/ChannelTypes/`) — it's real mapping logic, not admin-editable metadata.

- [ ] **Step 1: The value object**

```php
<?php

namespace App\Support\Shapefiles;

final class ShapefileLayerDefinition
{
    /**
     * @param array<string, string> $attributeMap source DBF/pipe field name => db column name
     * @param array<int, string> $naturalKeyColumns db column name(s) that uniquely identify a record
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $sourceUrl,
        public readonly string $format,
        public readonly string $liveTable,
        public readonly string $stagingTable,
        public readonly array $naturalKeyColumns,
        public readonly array $attributeMap,
        public readonly ?string $zoneType = null,
        public readonly ?string $groupMatchText = null,
    ) {}

    public function isShapefile(): bool
    {
        return $this->format === 'shapefile';
    }

    /**
     * @param array<string, mixed> $record a row keyed by db column name
     */
    public function serializeNaturalKey(array $record): string
    {
        return collect($this->naturalKeyColumns)
            ->map(fn (string $column): string => (string) ($record[$column] ?? ''))
            ->implode('|');
    }
}
```

- [ ] **Step 2: The registry**

```php
<?php

namespace App\Support\Shapefiles;

final class ShapefileLayerDefinitions
{
    /**
     * @return array<int, ShapefileLayerDefinition>
     */
    public static function all(): array
    {
        return [
            new ShapefileLayerDefinition(
                key: 'public_zones',
                name: 'Public Zones',
                sourceUrl: 'https://www.weather.gov/gis/PublicZones',
                format: 'shapefile',
                liveTable: 'public_zones',
                stagingTable: 'public_zones_staging',
                naturalKeyColumns: ['state_zone'],
                attributeMap: [
                    'STATE' => 'state', 'CWA' => 'cwa', 'TIME_ZONE' => 'time_zone',
                    'FE_AREA' => 'fe_area', 'ZONE' => 'zone', 'NAME' => 'name',
                    'STATE_ZONE' => 'state_zone', 'LON' => 'lon', 'LAT' => 'lat',
                    'SHORTNAME' => 'shortname',
                ],
            ),
            new ShapefileLayerDefinition(
                key: 'counties',
                name: 'Counties',
                sourceUrl: 'https://www.weather.gov/gis/Counties',
                format: 'shapefile',
                liveTable: 'counties',
                stagingTable: 'counties_staging',
                naturalKeyColumns: ['fips'],
                attributeMap: [
                    'STATE' => 'state', 'CWA' => 'cwa', 'COUNTYNAME' => 'countyname',
                    'FIPS' => 'fips', 'TIME_ZONE' => 'time_zone', 'FE_AREA' => 'fe_area',
                    'LON' => 'lon', 'LAT' => 'lat',
                ],
            ),
            new ShapefileLayerDefinition(
                key: 'cwa_boundaries',
                name: 'CWA Boundaries',
                sourceUrl: 'https://www.weather.gov/gis/CWABounds',
                format: 'shapefile',
                liveTable: 'cwa_boundaries',
                stagingTable: 'cwa_boundaries_staging',
                naturalKeyColumns: ['cwa'],
                attributeMap: [
                    'WFO' => 'wfo', 'CWA' => 'cwa', 'LON' => 'lon', 'LAT' => 'lat',
                    'REGION' => 'region', 'FULLSTAID' => 'fullstaid', 'CITYSTATE' => 'citystate',
                    'CITY' => 'city', 'STATE' => 'state', 'ST' => 'st',
                ],
            ),
            new ShapefileLayerDefinition(
                key: 'marine_zones_coastal',
                name: 'Marine Zones — Coastal',
                sourceUrl: 'https://www.weather.gov/gis/MarineZones',
                format: 'shapefile',
                liveTable: 'marine_zones_coastal',
                stagingTable: 'marine_zones_coastal_staging',
                naturalKeyColumns: ['zone_id'],
                attributeMap: self::marineAttributeMap(),
                zoneType: 'coastal',
                groupMatchText: 'Coastal',
            ),
            new ShapefileLayerDefinition(
                key: 'marine_zones_offshore',
                name: 'Marine Zones — Offshore',
                sourceUrl: 'https://www.weather.gov/gis/MarineZones',
                format: 'shapefile',
                liveTable: 'marine_zones_offshore',
                stagingTable: 'marine_zones_offshore_staging',
                naturalKeyColumns: ['zone_id'],
                attributeMap: self::marineAttributeMap(),
                zoneType: 'offshore',
                groupMatchText: 'Offshore',
            ),
            new ShapefileLayerDefinition(
                key: 'marine_zones_high_seas',
                name: 'Marine Zones — High Seas',
                sourceUrl: 'https://www.weather.gov/gis/MarineZones',
                format: 'shapefile',
                liveTable: 'marine_zones_high_seas',
                stagingTable: 'marine_zones_high_seas_staging',
                naturalKeyColumns: ['zone_id'],
                attributeMap: self::marineAttributeMap(),
                zoneType: 'high_seas',
                groupMatchText: 'High Seas',
            ),
            new ShapefileLayerDefinition(
                key: 'fire_zones',
                name: 'Fire Zones',
                sourceUrl: 'https://www.weather.gov/gis/FireZones',
                format: 'shapefile',
                liveTable: 'fire_zones',
                stagingTable: 'fire_zones_staging',
                naturalKeyColumns: ['state_zone'],
                attributeMap: [
                    'STATE' => 'state', 'ZONE' => 'zone', 'CWA' => 'cwa', 'NAME' => 'name',
                    'STATE_ZONE' => 'state_zone', 'TIME_ZONE' => 'time_zone',
                    'FE_AREA' => 'fe_area', 'LON' => 'lon', 'LAT' => 'lat',
                ],
            ),
            new ShapefileLayerDefinition(
                key: 'us_states',
                name: 'US States',
                sourceUrl: 'https://www.weather.gov/gis/USStates',
                format: 'shapefile',
                liveTable: 'us_states',
                stagingTable: 'us_states_staging',
                naturalKeyColumns: ['state'],
                attributeMap: [
                    'STATE' => 'state', 'NAME' => 'name', 'FIPS' => 'fips',
                    'LON' => 'lon', 'LAT' => 'lat',
                ],
            ),
            new ShapefileLayerDefinition(
                key: 'zone_county_correlation',
                name: 'Zone–County Correlation',
                sourceUrl: 'https://www.weather.gov/gis/ZoneCounty',
                format: 'correlation',
                liveTable: 'zone_county_correlations',
                stagingTable: 'zone_county_correlations_staging',
                naturalKeyColumns: ['state_zone', 'fips'],
                attributeMap: [
                    'STATE' => 'state', 'ZONE' => 'zone', 'CWA' => 'cwa', 'NAME' => 'name',
                    'STATE_ZONE' => 'state_zone', 'COUNTY' => 'county', 'FIPS' => 'fips',
                    'TIME_ZONE' => 'time_zone', 'FE_AREA' => 'fe_area', 'LAT' => 'lat', 'LON' => 'lon',
                ],
            ),
        ];
    }

    public static function forKey(string $key): ShapefileLayerDefinition
    {
        $definition = collect(self::all())->firstWhere('key', $key);

        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown shapefile layer key: {$key}");
        }

        return $definition;
    }

    /**
     * @return array<string, string>
     */
    private static function marineAttributeMap(): array
    {
        return [
            'ID' => 'zone_id', 'WFO' => 'wfo', 'GL_WFO' => 'gl_wfo', 'NAME' => 'name',
            'AJOIN0' => 'ajoin0', 'AJOIN1' => 'ajoin1', 'LON' => 'lon', 'LAT' => 'lat',
        ];
    }
}
```

- [ ] **Step 3: Write the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefileLayerDefinitionsTest --unit --no-interaction"
```

```php
<?php

use App\Support\Shapefiles\ShapefileLayerDefinitions;

test('there are exactly 9 layer definitions with unique keys', function () {
    $definitions = ShapefileLayerDefinitions::all();

    expect($definitions)->toHaveCount(9)
        ->and(collect($definitions)->pluck('key')->unique())->toHaveCount(9);
});

test('the 3 marine sub-layers each have their own table pair and distinct zone type', function () {
    $marine = collect(ShapefileLayerDefinitions::all())->filter(
        fn ($d) => str_starts_with($d->key, 'marine_zones_')
    );

    expect($marine)->toHaveCount(3)
        ->and($marine->pluck('liveTable')->unique())->toHaveCount(3)
        ->and($marine->pluck('zoneType')->unique()->sort()->values()->all())
        ->toBe(['coastal', 'high_seas', 'offshore']);
});

test('forKey resolves a known key and throws for an unknown one', function () {
    expect(ShapefileLayerDefinitions::forKey('public_zones')->name)->toBe('Public Zones');

    ShapefileLayerDefinitions::forKey('does_not_exist');
})->throws(InvalidArgumentException::class);

test('serializeNaturalKey joins composite keys with a pipe', function () {
    $definition = ShapefileLayerDefinitions::forKey('zone_county_correlation');

    expect($definition->serializeNaturalKey(['state_zone' => 'NM201', 'fips' => '35031']))
        ->toBe('NM201|35031');
});
```

- [ ] **Step 4: Run the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ShapefileLayerDefinitionsTest.php --compact"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Shapefiles/ShapefileLayerDefinition.php app/Support/Shapefiles/ShapefileLayerDefinitions.php tests/Unit/ShapefileLayerDefinitionsTest.php
git commit -m "Add the ShapefileLayerDefinition registry for all 9 tracked layers

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 7: `public_zones` + `public_zones_staging` tables

**Files:**
- Create: `database/migrations/2026_09_07_100010_create_public_zones_table.php`

- [ ] **Step 1: Migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_public_zones_table --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['public_zones', 'public_zones_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->string('cwa', 9);
                $t->string('time_zone', 2)->nullable();
                $t->string('fe_area', 2)->nullable();
                $t->string('zone', 3);
                $t->string('name', 254);
                $t->string('state_zone', 5)->unique();
                $t->string('shortname', 32)->nullable();
                $t->decimal('lon', 12, 7);
                $t->decimal('lat', 12, 7);
                $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_zones_staging');
        Schema::dropIfExists('public_zones');
    }
};
```

- [ ] **Step 2: Run it**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

Expected: both tables created without error.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_09_07_100010_create_public_zones_table.php
git commit -m "Add public_zones live/staging tables

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 8: `counties` + `counties_staging` tables

**Files:**
- Create: `database/migrations/2026_09_07_100011_create_counties_table.php`

- [ ] **Step 1: Migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_counties_table --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['counties', 'counties_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->string('cwa', 9);
                $t->string('countyname', 24);
                $t->string('fips', 5)->unique();
                $t->string('time_zone', 2)->nullable();
                $t->string('fe_area', 2)->nullable();
                $t->decimal('lon', 12, 7);
                $t->decimal('lat', 12, 7);
                $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('counties_staging');
        Schema::dropIfExists('counties');
    }
};
```

- [ ] **Step 2: Run it**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_09_07_100011_create_counties_table.php
git commit -m "Add counties live/staging tables

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 9: `cwa_boundaries` + `cwa_boundaries_staging` tables

**Files:**
- Create: `database/migrations/2026_09_07_100012_create_cwa_boundaries_table.php`

- [ ] **Step 1: Migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_cwa_boundaries_table --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cwa_boundaries', 'cwa_boundaries_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('wfo', 3);
                $t->string('cwa', 3)->unique();
                $t->decimal('lon', 12, 7);
                $t->decimal('lat', 12, 7);
                $t->string('region', 2)->nullable();
                $t->string('fullstaid', 4)->nullable();
                $t->string('citystate', 50)->nullable();
                $t->string('city', 50)->nullable();
                $t->string('state', 50)->nullable();
                $t->string('st', 2)->nullable();
                $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cwa_boundaries_staging');
        Schema::dropIfExists('cwa_boundaries');
    }
};
```

- [ ] **Step 2: Run it**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_09_07_100012_create_cwa_boundaries_table.php
git commit -m "Add cwa_boundaries live/staging tables

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 10: Marine Zones tables — 3 separate table pairs (Coastal, Offshore, High Seas)

**Files:**
- Create: `database/migrations/2026_09_07_100013_create_marine_zones_tables.php`

Each of the 3 Marine sub-layers gets its own live/staging table pair — **not** one shared table — so each can be scanned/imported/activated independently without one sub-layer's atomic swap clobbering another's live data (see the Context section's note above for why). All 3 share the identical attribute schema; `zone_type` is kept as a column on each table (a constant value per table) purely for labeling/future convenience, not as part of the natural key.

**Bug caught during execution, fixed here**: the AWIPS zone-identifier column is named `zone_id`, not `id` — NOAA's DBF field is literally called `ID`, but mapping it straight to a db column named `id` collides with Laravel's own auto-increment primary key (`$table->id()`), also named `id`, causing Postgres to reject the `CREATE TABLE` outright with "column \"id\" specified more than once". `zone_id` (below) is correct; Task 6's registry (already committed by the time this was caught) was patched directly to match.

- [ ] **Step 1: Migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_marine_zones_tables --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ZONE_TYPES = ['coastal', 'offshore', 'high_seas'];

    public function up(): void
    {
        foreach (self::ZONE_TYPES as $zoneType) {
            foreach (["marine_zones_{$zoneType}", "marine_zones_{$zoneType}_staging"] as $table) {
                Schema::create($table, function (Blueprint $t) {
                    $t->id();
                    $t->string('zone_type', 20);
                    $t->string('zone_id', 6)->unique();
                    $t->string('wfo', 3)->nullable();
                    $t->string('gl_wfo', 3)->nullable();
                    $t->string('name', 250);
                    $t->string('ajoin0', 5)->nullable();
                    $t->string('ajoin1', 5)->nullable();
                    $t->decimal('lon', 12, 7);
                    $t->decimal('lat', 12, 7);
                    $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                    $t->timestamps();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::ZONE_TYPES as $zoneType) {
            Schema::dropIfExists("marine_zones_{$zoneType}_staging");
            Schema::dropIfExists("marine_zones_{$zoneType}");
        }
    }
};
```

- [ ] **Step 2: Run it**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

Expected: 6 tables created (3 sub-layers × live + staging).

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_09_07_100013_create_marine_zones_tables.php
git commit -m "Add 3 separate Marine Zones table pairs (Coastal, Offshore, High Seas)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 11: `fire_zones` + `fire_zones_staging` tables

**Files:**
- Create: `database/migrations/2026_09_07_100014_create_fire_zones_table.php`

- [ ] **Step 1: Migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_fire_zones_table --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['fire_zones', 'fire_zones_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->string('zone', 3);
                $t->string('cwa', 3);
                $t->string('name', 254);
                $t->string('state_zone', 5)->unique();
                $t->string('time_zone', 2)->nullable();
                $t->string('fe_area', 2)->nullable();
                $t->decimal('lon', 12, 7);
                $t->decimal('lat', 12, 7);
                $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_zones_staging');
        Schema::dropIfExists('fire_zones');
    }
};
```

- [ ] **Step 2: Run it**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_09_07_100014_create_fire_zones_table.php
git commit -m "Add fire_zones live/staging tables

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 12: `us_states` + `us_states_staging` tables

**Files:**
- Create: `database/migrations/2026_09_07_100015_create_us_states_table.php`

- [ ] **Step 1: Migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_us_states_table --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['us_states', 'us_states_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('state', 2)->unique();
                $t->string('name', 24);
                $t->string('fips', 2)->nullable();
                $t->decimal('lon', 12, 7);
                $t->decimal('lat', 12, 7);
                $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('us_states_staging');
        Schema::dropIfExists('us_states');
    }
};
```

- [ ] **Step 2: Run it**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_09_07_100015_create_us_states_table.php
git commit -m "Add us_states live/staging tables

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 13: `zone_county_correlations` + `zone_county_correlations_staging` tables

**Files:**
- Create: `database/migrations/2026_09_07_100016_create_zone_county_correlations_table.php`

No `boundary` column — this is a plain lookup table, not a geometry source (per the design's confirmed scope: a lookup/join aid, not something the matching pipeline depends on).

- [ ] **Step 1: Migration**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_zone_county_correlations_table --no-interaction"
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['zone_county_correlations', 'zone_county_correlations_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->string('zone', 3);
                $t->string('cwa', 3);
                $t->string('name', 254);
                $t->string('state_zone', 5);
                $t->string('county', 100);
                $t->string('fips', 5);
                $t->string('time_zone', 2)->nullable();
                $t->string('fe_area', 2)->nullable();
                $t->decimal('lat', 12, 7);
                $t->decimal('lon', 12, 7);
                $t->timestamps();

                $t->unique(['state_zone', 'fips']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_county_correlations_staging');
        Schema::dropIfExists('zone_county_correlations');
    }
};
```

- [ ] **Step 2: Run it**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_09_07_100016_create_zone_county_correlations_table.php
git commit -m "Add zone_county_correlations live/staging tables

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 14: Seed the 9 `shapefile_layers` rows

**Files:**
- Create: `database/seeders/ShapefileLayerSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/ShapefileLayerSeederTest.php`

- [ ] **Step 1: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefileLayerSeederTest --no-interaction"
```

```php
<?php

use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Database\Seeders\ShapefileLayerSeeder;

test('it seeds exactly one shapefile_layers row per registry definition', function () {
    (new ShapefileLayerSeeder)->run();

    expect(ShapefileLayer::count())->toBe(9);

    foreach (ShapefileLayerDefinitions::all() as $definition) {
        $layer = ShapefileLayer::where('key', $definition->key)->first();

        expect($layer)->not->toBeNull()
            ->and($layer->source_url)->toBe($definition->sourceUrl)
            ->and($layer->name)->toBe($definition->name);
    }
});

test('running it twice does not duplicate rows', function () {
    (new ShapefileLayerSeeder)->run();
    (new ShapefileLayerSeeder)->run();

    expect(ShapefileLayer::count())->toBe(9);
});
```

- [ ] **Step 2: Run it, confirm it fails**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerSeederTest.php --compact"
```

Expected: FAIL — `Class "Database\Seeders\ShapefileLayerSeeder" not found`.

- [ ] **Step 3: Implement the seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Database\Seeder;

class ShapefileLayerSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ShapefileLayerDefinitions::all() as $definition) {
            ShapefileLayer::updateOrCreate(
                ['key' => $definition->key],
                ['name' => $definition->name, 'source_url' => $definition->sourceUrl],
            );
        }
    }
}
```

- [ ] **Step 4: Wire it into `DatabaseSeeder`**

In `database/seeders/DatabaseSeeder.php`, add to the `run()` method:

```php
        $this->call(ShapefileLayerSeeder::class);
```

(alongside the existing `$this->call(ProductCatalogSeeder::class);` line.)

- [ ] **Step 5: Run the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerSeederTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/seeders/ShapefileLayerSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/ShapefileLayerSeederTest.php
git commit -m "Seed the 9 tracked shapefile_layers rows from the registry

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 15: Generic shapefile-page scraper

**Files:**
- Create: `app/Support/Shapefiles/ShapefilePageScraper.php`
- Create: `tests/Fixtures/awips-shapefiles/public-zones-page.html`
- Create: `tests/Fixtures/awips-shapefiles/marine-zones-page.html`
- Test: `tests/Unit/ShapefilePageScraperTest.php`

- [ ] **Step 1: Save the two real fixture pages**

Create `tests/Fixtures/awips-shapefiles/public-zones-page.html` containing exactly this (the real, currently-live download table from `https://www.weather.gov/gis/PublicZones`, trimmed to just the relevant table — verified real markup):

```html
<table border="1" cellpadding="1" cellspacing="1" height="57" width="934">
	<thead>
		<tr>
			<th scope="col">Description</th>
			<th scope="col">Valid date</th>
			<th scope="col">Data Download</th>
			<th scope="col"># Records</th>
			<th scope="col">MD5SUM</th>
			<th colspan="2" scope="col">Documentation</th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td colspan="1" rowspan="2" style="white-space: nowrap; text-align: center; vertical-align: middle; height: 40px;">Public Forecast Zones</td>
			<td style="white-space: nowrap; text-align: center; vertical-align: middle; height: 40px;">18 March 2025</td>
			<td style="text-align: center; vertical-align: middle; height: 40px;"><a href="/source/gis/Shapefiles/WSOM/z_18mr25.zip">z_18mr25.zip</a></td>
			<td style="text-align: center; vertical-align: middle; height: 40px;">4114</td>
			<td style="text-align: center; vertical-align: middle; height: 40px;">388062c3036e4518d07c9fb09d68ff6f<br />
			Date last upload: 5-FEB-25</td>
			<td colspan="1" rowspan="2" style="text-align: center; vertical-align: middle; height: 40px;"><a href="/source/gis/Shapefiles/WSOM/zone_ch_log.txt">Change History</a></td>
			<td colspan="1" rowspan="2" style="text-align: center; vertical-align: middle; height: 40px;"><a href="https://www.weather.gov/gis/PublicZoneMetadata" target="_blank">Metadata</a></td>
		</tr>
		<tr>
			<td style="white-space: nowrap; text-align: center; vertical-align: middle; height: 40px;">16 April 2026</td>
			<td style="text-align: center; vertical-align: middle; height: 40px;"><a href="/source/gis/Shapefiles/WSOM/z_16ap26.zip">z_16ap26.zip</a></td>
			<td style="text-align: center; vertical-align: middle; height: 40px;">4157</td>
			<td style="text-align: center; vertical-align: middle; height: 40px;">004dc6501dc3d50e7b36652cb9d02bd3<br />
			Date last upload: 13-APR-26</td>
		</tr>
	</tbody>
</table>
```

Create `tests/Fixtures/awips-shapefiles/marine-zones-page.html` containing exactly this (the real, currently-live 3-group download table from `https://www.weather.gov/gis/MarineZones`):

```html
<table border="1" cellpadding="1" cellspacing="1" height="134" width="902">
	<thead>
		<tr>
			<th scope="col">Description</th>
			<th scope="col">Valid date</th>
			<th scope="col">Data Download</th>
			<th scope="col"># Records</th>
			<th scope="col">MD5SUM</th>
			<th colspan="2" scope="col">Documentation</th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td colspan="1" rowspan="2" style="white-space: nowrap; vertical-align: middle; height: 40px;">Coastal Marine Zones<br />
			Including the Great Lakes</td>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;">18 March 2025</td>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;"><a href="/source/gis/Shapefiles/WSOM/mz18mr25.zip">mz18mr25.zip</a></td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">566</td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">df33ce3efc028639d870a9848c636669<br />
			Date last upload:&nbsp;20-FEB-25</td>
			<td colspan="1" rowspan="2" style="vertical-align: middle; height: 40px;"><a href="/source/gis/Shapefiles/WSOM/mz_ch_log.txt">Change History</a></td>
			<td colspan="1" rowspan="2" style="vertical-align: middle; height: 40px;"><a href="https://www.weather.gov/gis/CoastalMarineMetadata" target="_blank">Metadata</a></td>
		</tr>
		<tr>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;">16 April 2026</td>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;"><a href="/source/gis/Shapefiles/WSOM/mz16ap26.zip">mz16ap26.zip</a></td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">569</td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">f191552ac0810b4bc4d11b46a87c2aeb<br />
			Date last upload:&nbsp;03-MAR-26</td>
		</tr>
		<tr>
			<td colspan="1" rowspan="2" style="white-space: nowrap; vertical-align: middle; height: 40px;">Offshore Marine Zones</td>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;">18 March 2025</td>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;"><a href="/source/gis/Shapefiles/WSOM/oz18mr25.zip">oz18mr25.zip</a></td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">130</td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">45ba9aca774938a4f8534fa798c8cb90<br />
			Date last upload: 20-FEB-25</td>
			<td colspan="1" rowspan="2" style="vertical-align: middle; height: 40px;"><a href="/source/gis/Shapefiles/WSOM/oz_ch_log.txt">Change History</a></td>
			<td colspan="1" rowspan="2" style="vertical-align: middle; height: 40px;"><a href="https://www.weather.gov/gis/OffshoreZoneMetadata" target="_blank">Metadata</a></td>
		</tr>
		<tr>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;">16 April 2026</td>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;"><a href="/source/gis/Shapefiles/WSOM/oz16ap26.zip">oz16ap26.zip</a></td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">130</td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">aa39c4bca11fcb4d949078cef292fd7d<br />
			Date last upload:&nbsp;03-MAR-26</td>
		</tr>
		<tr>
			<td colspan="1" rowspan="2" style="white-space: nowrap; vertical-align: middle; height: 40px;">High Seas Marine Zones</td>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;">20 February 2025</td>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;"><a href="/source/gis/Shapefiles/WSOM/hz20fe25.zip">hz20fe25.zip</a></td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">6</td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">33a74c3f06339ca5080ef45101bb532f<br />
			Date last upload: 20-FEB-25</td>
			<td colspan="1" rowspan="2" style="vertical-align: middle; height: 40px;"><a href="/source/gis/Shapefiles/WSOM/hz_ch_log.txt">Change History</a></td>
			<td colspan="1" rowspan="2" style="vertical-align: middle; height: 40px;"><a href="https://www.weather.gov/gis/HighSeasMetadata" target="_blank">Metadata</a></td>
		</tr>
		<tr>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;">17 February 2026</td>
			<td style="white-space: nowrap; vertical-align: middle; height: 40px; text-align: center;"><a href="/source/gis/Shapefiles/WSOM/hz17fe26.zip">hz17fe26.zip</a></td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">5</td>
			<td style="vertical-align: middle; height: 40px; text-align: center;">9d89a9b2c485418a2848861cf047eb6c<br />
			Date last upload: 12-FEB-26</td>
		</tr>
	</tbody>
</table>
```

- [ ] **Step 2: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefilePageScraperTest --unit --no-interaction"
```

```php
<?php

use App\Support\Shapefiles\ShapefilePageScraper;
use Illuminate\Support\Carbon;

test('it scans a single-group page and takes the latest version', function () {
    $html = file_get_contents(dirname(__DIR__).'/Fixtures/awips-shapefiles/public-zones-page.html');

    $groups = (new ShapefilePageScraper)->scan($html);

    expect($groups)->toHaveCount(1);
    $versions = $groups[0]['versions'];
    expect($versions)->toHaveCount(2);

    $latest = collect($versions)->sortByDesc('effective_at')->first();
    expect($latest['filename'])->toBe('z_16ap26.zip')
        ->and($latest['checksum'])->toBe('004dc6501dc3d50e7b36652cb9d02bd3')
        ->and($latest['record_count'])->toBe(4157)
        ->and($latest['url'])->toBe('https://www.weather.gov/source/gis/Shapefiles/WSOM/z_16ap26.zip')
        ->and($latest['effective_at']->equalTo(Carbon::parse('2026-04-16')))->toBeTrue();
});

test('it scans a 3-group page (Marine Zones) into 3 separate version groups', function () {
    $html = file_get_contents(dirname(__DIR__).'/Fixtures/awips-shapefiles/marine-zones-page.html');

    $groups = (new ShapefilePageScraper)->scan($html);

    expect($groups)->toHaveCount(3);

    $descriptions = collect($groups)->pluck('description');
    expect($descriptions->filter(fn ($d) => str_contains($d, 'Coastal')))->toHaveCount(1)
        ->and($descriptions->filter(fn ($d) => str_contains($d, 'Offshore')))->toHaveCount(1)
        ->and($descriptions->filter(fn ($d) => str_contains($d, 'High Seas')))->toHaveCount(1);

    $highSeas = collect($groups)->first(fn ($g) => str_contains($g['description'], 'High Seas'));
    expect($highSeas['versions'])->toHaveCount(2);
    $latest = collect($highSeas['versions'])->sortByDesc('effective_at')->first();
    expect($latest['filename'])->toBe('hz17fe26.zip')
        ->and($latest['record_count'])->toBe(5);
});
```

- [ ] **Step 3: Run it, confirm it fails**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ShapefilePageScraperTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 4: Implement the scraper**

```php
<?php

namespace App\Support\Shapefiles;

use Illuminate\Support\Carbon;
use Symfony\Component\DomCrawler\Crawler;

class ShapefilePageScraper
{
    /**
     * @return array<int, array{description: string, versions: array<int, array{effective_at: Carbon, filename: string, url: string, record_count: int, checksum: ?string}>}>
     */
    public function scan(string $html): array
    {
        $table = (new Crawler($html))->filter('table')->first();

        $groups = [];
        $currentDescription = null;

        $table->filter('tbody tr')->each(function (Crawler $row) use (&$groups, &$currentDescription): void {
            $cells = $row->filter('td');
            $cellCount = $cells->count();

            if ($cellCount >= 5) {
                $currentDescription = trim($cells->eq(0)->text());
                [$dateCell, $linkCell, $recordsCell, $checksumCell] = [
                    $cells->eq(1), $cells->eq(2), $cells->eq(3), $cells->eq(4),
                ];
            } elseif ($cellCount === 4 && $currentDescription !== null) {
                [$dateCell, $linkCell, $recordsCell, $checksumCell] = [
                    $cells->eq(0), $cells->eq(1), $cells->eq(2), $cells->eq(3),
                ];
            } else {
                return;
            }

            $link = $linkCell->filter('a');

            if ($link->count() === 0) {
                return;
            }

            $href = (string) $link->attr('href');
            $checksumText = $checksumCell->text();
            $checksum = preg_match('/[a-f0-9]{32}/i', $checksumText, $m) === 1 ? strtolower($m[0]) : null;

            $groups[$currentDescription][] = [
                'effective_at' => Carbon::parse(trim($dateCell->text())),
                'filename' => trim($link->text()),
                'url' => str_starts_with($href, 'http') ? $href : 'https://www.weather.gov'.$href,
                'record_count' => (int) trim($recordsCell->text()),
                'checksum' => $checksum,
            ];
        });

        return collect($groups)
            ->map(fn (array $versions, string $description): array => [
                'description' => $description,
                'versions' => $versions,
            ])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 5: Run the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ShapefilePageScraperTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Shapefiles/ShapefilePageScraper.php tests/Fixtures/awips-shapefiles/public-zones-page.html tests/Fixtures/awips-shapefiles/marine-zones-page.html tests/Unit/ShapefilePageScraperTest.php
git commit -m "Add the generic shapefile-page scraper for all 6 shapefile pages

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 16: Zone–County Correlation page scraper

**Files:**
- Create: `app/Support/Shapefiles/ZoneCountyCorrelationPageScraper.php`
- Create: `tests/Fixtures/awips-shapefiles/zone-county-page.html`
- Test: `tests/Unit/ZoneCountyCorrelationPageScraperTest.php`

This page has a different, simpler 3-column table with no `<thead>` — its first `<tr>` is a header row in disguise (plain `<td>`s, no `<th>`), and there's no checksum column at all.

- [ ] **Step 1: Save the fixture**

Create `tests/Fixtures/awips-shapefiles/zone-county-page.html` (the real, currently-live table from `https://www.weather.gov/gis/ZoneCounty`):

```html
<table border="1" cellpadding="1" cellspacing="1" height="57" width="934">
	<tbody>
		<tr>
			<td style="height: 40px; vertical-align: middle; text-align: center;"><strong>Description</strong></td>
			<td style="height: 40px; vertical-align: middle; text-align: center;"><strong>Valid date</strong></td>
			<td style="height: 40px; vertical-align: middle; text-align: center;"><strong>Data Download</strong></td>
		</tr>
		<tr>
			<td colspan="1" rowspan="2" style="white-space: nowrap; height: 40px; vertical-align: middle; text-align: center;">County-Public Forecast Zones Correlation file</td>
			<td colspan="1" style="white-space: nowrap; height: 40px; vertical-align: middle; text-align: center;">18 March 2025<br />
			Date last upload: 05-FEB-2025</td>
			<td colspan="1" style="white-space: nowrap; height: 40px; vertical-align: middle; text-align: center;"><a href="/source/gis/Shapefiles/County/bp18mr25.dbx">bp18mr25.dbx</a></td>
		</tr>
		<tr>
			<td colspan="1" style="white-space: nowrap; height: 40px; vertical-align: middle; text-align: center;">16 April 2026<br />
			Date last upload: 03-MAR-2026</td>
			<td colspan="1" style="white-space: nowrap; height: 40px; vertical-align: middle; text-align: center;"><a href="/source/gis/Shapefiles/County/bp16ap26.dbx">bp16ap26.dbx</a></td>
		</tr>
	</tbody>
</table>
```

- [ ] **Step 2: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ZoneCountyCorrelationPageScraperTest --unit --no-interaction"
```

```php
<?php

use App\Support\Shapefiles\ZoneCountyCorrelationPageScraper;

test('it scans the correlation page, skips the header row, and finds the latest version', function () {
    $html = file_get_contents(dirname(__DIR__).'/Fixtures/awips-shapefiles/zone-county-page.html');

    $groups = (new ZoneCountyCorrelationPageScraper)->scan($html);

    expect($groups)->toHaveCount(1);
    $versions = $groups[0]['versions'];
    expect($versions)->toHaveCount(2);

    $latest = collect($versions)->sortByDesc('effective_at')->first();
    expect($latest['filename'])->toBe('bp16ap26.dbx')
        ->and($latest['url'])->toBe('https://www.weather.gov/source/gis/Shapefiles/County/bp16ap26.dbx')
        ->and($latest['checksum'])->toBeNull()
        ->and($latest['record_count'])->toBeNull();
});
```

- [ ] **Step 3: Run it, confirm it fails**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ZoneCountyCorrelationPageScraperTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 4: Implement the scraper**

```php
<?php

namespace App\Support\Shapefiles;

use Illuminate\Support\Carbon;
use Symfony\Component\DomCrawler\Crawler;

class ZoneCountyCorrelationPageScraper
{
    /**
     * @return array<int, array{description: string, versions: array<int, array{effective_at: Carbon, filename: string, url: string, record_count: null, checksum: null}>}>
     */
    public function scan(string $html): array
    {
        $table = (new Crawler($html))->filter('table')->first();

        $groups = [];
        $currentDescription = null;

        $table->filter('tbody tr')->each(function (Crawler $row) use (&$groups, &$currentDescription): void {
            $cells = $row->filter('td');
            $cellCount = $cells->count();

            if ($cellCount > 0 && trim($cells->eq(0)->text()) === 'Description') {
                return;
            }

            if ($cellCount === 3) {
                $currentDescription = trim($cells->eq(0)->text());
                [$dateCell, $linkCell] = [$cells->eq(1), $cells->eq(2)];
            } elseif ($cellCount === 2 && $currentDescription !== null) {
                [$dateCell, $linkCell] = [$cells->eq(0), $cells->eq(1)];
            } else {
                return;
            }

            $link = $linkCell->filter('a');

            if ($link->count() === 0) {
                return;
            }

            $href = (string) $link->attr('href');
            $dateText = explode("\n", str_replace(["\r"], '', $dateCell->text()))[0];
            $dateText = trim(preg_replace('/Date last upload.*/i', '', $dateText));

            $groups[$currentDescription][] = [
                'effective_at' => Carbon::parse($dateText),
                'filename' => trim($link->text()),
                'url' => str_starts_with($href, 'http') ? $href : 'https://www.weather.gov'.$href,
                'record_count' => null,
                'checksum' => null,
            ];
        });

        return collect($groups)
            ->map(fn (array $versions, string $description): array => [
                'description' => $description,
                'versions' => $versions,
            ])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 5: Run the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ZoneCountyCorrelationPageScraperTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Shapefiles/ZoneCountyCorrelationPageScraper.php tests/Fixtures/awips-shapefiles/zone-county-page.html tests/Unit/ZoneCountyCorrelationPageScraperTest.php
git commit -m "Add the Zone-County Correlation page's own scraper

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 17: `ScanShapefileLayer` action

**Files:**
- Create: `app/Actions/Shapefiles/ScanShapefileLayer.php`
- Test: `tests/Feature/ScanShapefileLayerTest.php`

Fetches a layer's source page, picks the matching version group (the whole table for a single-group page, or the group whose description matches `groupMatchText` for Marine), takes the latest-effective-date version in that group, and — only if its identity (checksum, or filename for the correlation layer) differs from what's already recorded — stages a new `ShapefileLayerVersion` row.

- [ ] **Step 1: Write the failing tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ScanShapefileLayerTest --no-interaction"
```

```php
<?php

use App\Actions\Shapefiles\ScanShapefileLayer;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use Illuminate\Support\Facades\Http;

test('it stages a new version when the checksum changes', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/public-zones-page.html'));
    Http::fake(['www.weather.gov/gis/PublicZones' => Http::response($html)]);

    $layer = ShapefileLayer::factory()->create([
        'key' => 'public_zones',
        'source_url' => 'https://www.weather.gov/gis/PublicZones',
    ]);

    $version = (new ScanShapefileLayer)($layer);

    expect($version)->not->toBeNull()
        ->and($version->filename)->toBe('z_16ap26.zip')
        ->and($version->checksum)->toBe('004dc6501dc3d50e7b36652cb9d02bd3')
        ->and($version->status)->toBe(ShapefileVersionStatus::Staged);

    expect($layer->fresh()->last_seen_checksum)->toBe('004dc6501dc3d50e7b36652cb9d02bd3');
});

test('it does nothing when the checksum has not changed', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/public-zones-page.html'));
    Http::fake(['www.weather.gov/gis/PublicZones' => Http::response($html)]);

    $layer = ShapefileLayer::factory()->create([
        'key' => 'public_zones',
        'source_url' => 'https://www.weather.gov/gis/PublicZones',
        'last_seen_checksum' => '004dc6501dc3d50e7b36652cb9d02bd3',
    ]);

    $version = (new ScanShapefileLayer)($layer);

    expect($version)->toBeNull();
    expect($layer->versions()->count())->toBe(0);
});

test('the marine 3-group page yields the correct group for each sub-layer', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/marine-zones-page.html'));
    Http::fake(['www.weather.gov/gis/MarineZones' => Http::response($html)]);

    $layer = ShapefileLayer::factory()->create([
        'key' => 'marine_zones_high_seas',
        'source_url' => 'https://www.weather.gov/gis/MarineZones',
    ]);

    $version = (new ScanShapefileLayer)($layer);

    expect($version->filename)->toBe('hz17fe26.zip')
        ->and($version->record_count)->toBe(5);
});

test('it keys change detection on filename alone for the correlation layer', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/zone-county-page.html'));
    Http::fake(['www.weather.gov/gis/ZoneCounty' => Http::response($html)]);

    $layer = ShapefileLayer::factory()->create([
        'key' => 'zone_county_correlation',
        'source_url' => 'https://www.weather.gov/gis/ZoneCounty',
        'last_seen_filename' => 'bp16ap26.dbx',
    ]);

    $version = (new ScanShapefileLayer)($layer);

    expect($version)->toBeNull();
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ScanShapefileLayerTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Actions\Shapefiles;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use App\Support\Shapefiles\ShapefilePageScraper;
use App\Support\Shapefiles\ZoneCountyCorrelationPageScraper;
use Illuminate\Support\Facades\Http;

class ScanShapefileLayer
{
    public function __invoke(ShapefileLayer $layer): ?ShapefileLayerVersion
    {
        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        $response = Http::withHeaders(['User-Agent' => $this->userAgent()])->get($layer->source_url);

        if (! $response->successful()) {
            return null;
        }

        $groups = $definition->format === 'correlation'
            ? (new ZoneCountyCorrelationPageScraper)->scan($response->body())
            : (new ShapefilePageScraper)->scan($response->body());

        $group = $definition->groupMatchText === null
            ? ($groups[0] ?? null)
            : collect($groups)->first(fn (array $g): bool => str_contains($g['description'], $definition->groupMatchText));

        if ($group === null || $group['versions'] === []) {
            return null;
        }

        $latest = collect($group['versions'])->sortByDesc('effective_at')->first();

        $identityChanged = $definition->format === 'correlation'
            ? $latest['filename'] !== $layer->last_seen_filename
            : $latest['checksum'] !== $layer->last_seen_checksum;

        if (! $identityChanged) {
            return null;
        }

        $version = $layer->versions()->create([
            'filename' => $latest['filename'],
            'checksum' => $latest['checksum'],
            'source_url' => $latest['url'],
            'effective_at' => $latest['effective_at'],
            'status' => ShapefileVersionStatus::Staged,
            'record_count' => $latest['record_count'],
        ]);

        $layer->update([
            'last_seen_filename' => $latest['filename'],
            'last_seen_checksum' => $latest['checksum'],
            'last_seen_effective_at' => $latest['effective_at'],
        ]);

        return $version;
    }

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ScanShapefileLayerTest.php --compact"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Shapefiles/ScanShapefileLayer.php tests/Feature/ScanShapefileLayerTest.php
git commit -m "Add ScanShapefileLayer to detect and stage new NOAA versions

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 18: `shapefile:scan` command + daily schedule

**Files:**
- Create: `app/Console/Commands/ScanShapefileLayers.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/ScanShapefileLayersCommandTest.php`

Runs `ScanShapefileLayer` across all 9 rows. Per the design's confirmed cadence decision, a single daily check is cheap enough not to need per-layer scheduling.

**Bug found post-implementation (via real manual testing, after all 33 tasks were believed complete) and fixed**: this task's original command only called `ScanShapefileLayer` — it never called `ImportShapefileVersion` (Tasks 20-21), even though that class had full standalone test coverage. The result: `shapefile:scan` staged new `ShapefileLayerVersion` rows but left every staging table empty, and activating a layer in that state swapped an empty table into the live slot. Fixed by injecting `ImportShapefileVersion` into the command and calling it immediately after a successful scan, wrapped in a per-layer `try`/`catch` so one layer's download/parse failure against the real NOAA site doesn't abort the other 8 layers' scans. The corrected command:

```php
<?php

namespace App\Console\Commands;

use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Actions\Shapefiles\ScanShapefileLayer;
use App\Models\ShapefileLayer;
use Illuminate\Console\Command;
use Throwable;

class ScanShapefileLayers extends Command
{
    protected $signature = 'shapefile:scan';

    protected $description = 'Poll every tracked AWIPS shapefile layer for a new NOAA version and import it into staging';

    public function handle(ScanShapefileLayer $scan, ImportShapefileVersion $import): int
    {
        $staged = 0;

        foreach (ShapefileLayer::all() as $layer) {
            $version = $scan($layer);

            if ($version === null) {
                continue;
            }

            $staged++;

            try {
                $import($version);
                $this->info("New version staged and imported for {$layer->name}");
            } catch (Throwable $e) {
                $this->error("New version staged for {$layer->name}, but import failed: {$e->getMessage()}");
            }
        }

        $this->info("Scan complete: {$staged} layer(s) staged a new version.");

        return self::SUCCESS;
    }
}
```

A real regression test for this exact bug (using the real `public-zones-page.html` fixture and a real built zip, confirming `public_zones_staging` actually gets populated by running the command, not just that a version row gets created) was added to `tests/Feature/ScanShapefileLayersCommandTest.php` alongside the original test — see that file for the exact assertions.

- [ ] **Step 1: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ScanShapefileLayersCommandTest --no-interaction"
```

```php
<?php

use App\Models\ShapefileLayer;
use Database\Seeders\ShapefileLayerSeeder;
use Illuminate\Support\Facades\Http;

test('it scans every seeded layer and reports how many staged a new version', function () {
    (new ShapefileLayerSeeder)->run();

    Http::fake(fn () => Http::response('<table><tbody><tr><td>x</td></tr></tbody></table>'));

    $this->artisan('shapefile:scan')
        ->assertSuccessful();

    expect(ShapefileLayer::count())->toBe(9);
    Http::assertSentCount(9);
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ScanShapefileLayersCommandTest.php --compact"
```

Expected: FAIL — command `shapefile:scan` does not exist.

- [ ] **Step 3: Implement the command**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:command ScanShapefileLayers --no-interaction"
```

Replace `app/Console/Commands/ScanShapefileLayers.php`:

```php
<?php

namespace App\Console\Commands;

use App\Actions\Shapefiles\ScanShapefileLayer;
use App\Models\ShapefileLayer;
use Illuminate\Console\Command;

class ScanShapefileLayers extends Command
{
    protected $signature = 'shapefile:scan';

    protected $description = 'Poll every tracked AWIPS shapefile layer for a new NOAA version';

    public function handle(ScanShapefileLayer $scan): int
    {
        $staged = 0;

        foreach (ShapefileLayer::all() as $layer) {
            if ($scan($layer) !== null) {
                $staged++;
                $this->info("New version staged for {$layer->name}");
            }
        }

        $this->info("Scan complete: {$staged} layer(s) staged a new version.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the daily schedule**

In `bootstrap/app.php`, add the `->withSchedule()` call (this is the first scheduled task in this app):

```php
use Illuminate\Console\Scheduling\Schedule;
```

```php
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('shapefile:scan')->daily();
    })
```

placed after `->withMiddleware(...)` and before `->withExceptions(...)`.

- [ ] **Step 5: Run the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ScanShapefileLayersCommandTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Verify the schedule registered**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan schedule:list"
```

Expected: a row for `shapefile:scan` running daily.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/ScanShapefileLayers.php bootstrap/app.php tests/Feature/ScanShapefileLayersCommandTest.php
git commit -m "Add shapefile:scan command and schedule it daily

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 19: `ShapefilePolygonAssembler` — correct ring/hole grouping

**Files:**
- Create: `app/Support/Shapefiles/ShapefilePolygonAssembler.php`
- Test: `tests/Unit/ShapefilePolygonAssemblerTest.php`

An ESRI shapefile Polygon record packs multiple rings ("parts"). Per the ESRI Shapefile Technical Description, an outer ring is wound clockwise and a hole is wound counter-clockwise — this assembler groups rings into polygons (a new clockwise ring starts a new polygon; a counter-clockwise ring is a hole in the current polygon) using the standard shoelace-sum sign test, then formats the result as MultiPolygon WKT.

- [ ] **Step 1: Write the failing tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefilePolygonAssemblerTest --unit --no-interaction"
```

```php
<?php

use App\Support\Shapefiles\ShapefilePolygonAssembler;

test('a single clockwise ring becomes one polygon with no holes', function () {
    $parts = [
        ['points' => [
            ['x' => 0, 'y' => 0], ['x' => 0, 'y' => 10], ['x' => 10, 'y' => 10], ['x' => 10, 'y' => 0], ['x' => 0, 'y' => 0],
        ]],
    ];

    $wkt = ShapefilePolygonAssembler::toMultiPolygonWkt($parts);

    expect($wkt)->toBe('MULTIPOLYGON(((0 0,0 10,10 10,10 0,0 0)))');
});

test('two disjoint clockwise rings become two separate polygons', function () {
    $ringA = ['points' => [
        ['x' => 0, 'y' => 0], ['x' => 0, 'y' => 10], ['x' => 10, 'y' => 10], ['x' => 10, 'y' => 0], ['x' => 0, 'y' => 0],
    ]];
    $ringB = ['points' => [
        ['x' => 20, 'y' => 20], ['x' => 20, 'y' => 30], ['x' => 30, 'y' => 30], ['x' => 30, 'y' => 20], ['x' => 20, 'y' => 20],
    ]];

    $wkt = ShapefilePolygonAssembler::toMultiPolygonWkt([$ringA, $ringB]);

    expect($wkt)->toBe('MULTIPOLYGON(((0 0,0 10,10 10,10 0,0 0)),((20 20,20 30,30 30,30 20,20 20)))');
});

test('a counter-clockwise ring following a clockwise ring becomes a hole in it', function () {
    $outer = ['points' => [
        ['x' => 0, 'y' => 0], ['x' => 0, 'y' => 10], ['x' => 10, 'y' => 10], ['x' => 10, 'y' => 0], ['x' => 0, 'y' => 0],
    ]];
    $hole = ['points' => [
        ['x' => 2, 'y' => 2], ['x' => 8, 'y' => 2], ['x' => 8, 'y' => 8], ['x' => 2, 'y' => 8], ['x' => 2, 'y' => 2],
    ]];

    $wkt = ShapefilePolygonAssembler::toMultiPolygonWkt([$outer, $hole]);

    expect($wkt)->toBe('MULTIPOLYGON(((0 0,0 10,10 10,10 0,0 0),(2 2,8 2,8 8,2 8,2 2)))');
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ShapefilePolygonAssemblerTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support\Shapefiles;

final class ShapefilePolygonAssembler
{
    /**
     * @param array<int, array{points: array<int, array{x: float, y: float}>}> $parts
     */
    public static function toMultiPolygonWkt(array $parts): string
    {
        $polygons = self::assemble($parts);

        $polygonWkts = array_map(
            fn (array $rings): string => '('.implode(',', array_map(
                fn (array $ring): string => '('.self::ringToWkt($ring).')',
                $rings,
            )).')',
            $polygons,
        );

        return 'MULTIPOLYGON('.implode(',', $polygonWkts).')';
    }

    /**
     * @param array<int, array{points: array<int, array{x: float, y: float}>}> $parts
     * @return array<int, array<int, array<int, array{0: float, 1: float}>>>
     */
    private static function assemble(array $parts): array
    {
        $polygons = [];
        $currentPolygon = null;

        foreach ($parts as $part) {
            $ring = array_map(fn (array $point): array => [$point['x'], $point['y']], $part['points']);

            if ($currentPolygon === null || self::isClockwise($ring)) {
                if ($currentPolygon !== null) {
                    $polygons[] = $currentPolygon;
                }
                $currentPolygon = [$ring];
            } else {
                $currentPolygon[] = $ring;
            }
        }

        if ($currentPolygon !== null) {
            $polygons[] = $currentPolygon;
        }

        return $polygons;
    }

    /**
     * @param array<int, array{0: float, 1: float}> $ring
     */
    private static function isClockwise(array $ring): bool
    {
        $sum = 0.0;
        $count = count($ring);

        for ($i = 0; $i < $count; $i++) {
            [$x1, $y1] = $ring[$i];
            [$x2, $y2] = $ring[($i + 1) % $count];
            $sum += ($x2 - $x1) * ($y2 + $y1);
        }

        return $sum > 0;
    }

    /**
     * @param array<int, array{0: float, 1: float}> $ring
     */
    private static function ringToWkt(array $ring): string
    {
        return implode(',', array_map(
            fn (array $point): string => self::formatNumber($point[0]).' '.self::formatNumber($point[1]),
            $ring,
        ));
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.7f', $value), '0'), '.') ?: '0';
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ShapefilePolygonAssemblerTest.php --compact"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Shapefiles/ShapefilePolygonAssembler.php tests/Unit/ShapefilePolygonAssemblerTest.php
git commit -m "Add ShapefilePolygonAssembler for correct hole-aware WKT construction

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Prerequisite for Task 20: enable the `dbase` PHP extension (already done — reference only)

Before Task 20 can run, the Docker image needs the `dbase` extension — `phpmyadmin/shapefile` silently no-ops all `.dbf` I/O without it (see the Context section's note above). This was already done once, as a real correction during execution, not part of the original plan:

1. `mkdir -p docker/8.5 && cp vendor/laravel/sail/runtimes/8.5/{Dockerfile,start-container,supervisord.conf,php.ini} docker/8.5/`
2. In `docker/8.5/Dockerfile`, after the main `apt-get install ... postgresql-client-$POSTGRES_VERSION ...` block and before the `$PHP_EXTENSIONS` conditional block, add:
   ```dockerfile
   RUN pecl install dbase \
       && echo "extension=dbase.so" > /etc/php/8.5/cli/conf.d/99-dbase.ini
   ```
3. In `compose.yaml`, change both `laravel.test` and `queue` services' `build.context` from `./vendor/laravel/sail/runtimes/8.5` to `./docker/8.5` (they share the `sail-8.5/app` image tag).
4. `docker compose build laravel.test && docker compose up -d laravel.test queue`
5. Verify: `docker exec wxnotify-dashboard-laravel.test-1 php -m | grep dbase` → `dbase`.

If executing this plan fresh (not continuing from where this session left off), do this before Task 20's steps.

---

### Task 20: `ImportShapefileVersion` — shapefile-format branch

**Files:**
- Create: `app/Actions/Shapefiles/ImportShapefileVersion.php`
- Test: `tests/Feature/ImportShapefileVersionTest.php`

Downloads the version's zip, unzips it, parses it with `phpmyadmin/shapefile`, and repopulates the layer's own staging table. Since each layer — Marine's 3 sub-layers included, per Task 10's corrected schema — has its own dedicated table pair, the staging table is always fully truncated and repopulated on each import; there's no cross-sub-layer scoping to worry about.

**Bug found post-implementation (via a real import against live NOAA data, after all 33 tasks were believed complete) and fixed**: NOAA's actual Public Zones file has 77 of 4,157 records sharing a duplicate `state_zone` — the same logical zone split across multiple physical shapefile records (identical attributes, different disjoint geometry — e.g. Calvert County, MD's zone is two separate records for its two water-separated land areas). The original import inserted one row per raw shapefile record, which violates the unique constraint on the natural key the moment two records share one. Fixed by grouping records by natural key *before* inserting and combining every group member's polygon parts into one `MultiPolygon` via the existing `ShapefilePolygonAssembler` — no changes needed there, since concatenating parts from multiple records and assembling them once produces the same correct disjoint-polygon grouping it already handles for parts within a single record (verified with an exact-WKT test using a 2-record fixture reproducing the real Calvert County shape). This applies to every shapefile-format layer, not just Public Zones — see `app/Actions/Shapefiles/ImportShapefileVersion.php`'s `groupRecordsByNaturalKey()`/`insertShapefileRecordGroup()` for the corrected implementation.

- [ ] **Step 1: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ImportShapefileVersionTest --no-interaction"
```

```php
<?php

use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeType;

function buildFixtureZip(string $basename): string
{
    $dir = storage_path('app/test-fixtures/'.$basename);
    File::ensureDirectoryExists($dir);
    $base = $dir.'/'.$basename;

    $shp = new ShapeFile(ShapeType::Polygon, ['xmin' => 0, 'ymin' => 0, 'xmax' => 10, 'ymax' => 10]);

    $record = new \PhpMyAdmin\ShapeFile\ShapeRecord(ShapeType::Polygon);
    $record->addPoint(['x' => 0, 'y' => 0], 0);
    $record->addPoint(['x' => 0, 'y' => 1], 0);
    $record->addPoint(['x' => 1, 'y' => 1], 0);
    $record->addPoint(['x' => 1, 'y' => 0], 0);
    $record->addPoint(['x' => 0, 'y' => 0], 0);
    $shp->addRecord($record);

    $record2 = new \PhpMyAdmin\ShapeFile\ShapeRecord(ShapeType::Polygon);
    $record2->addPoint(['x' => 5, 'y' => 5], 0);
    $record2->addPoint(['x' => 5, 'y' => 6], 0);
    $record2->addPoint(['x' => 6, 'y' => 6], 0);
    $record2->addPoint(['x' => 6, 'y' => 5], 0);
    $record2->addPoint(['x' => 5, 'y' => 5], 0);
    $shp->addRecord($record2);

    $shp->setDBFHeader([
        ['STATE', 'C', 2, 0], ['CWA', 'C', 9, 0], ['TIME_ZONE', 'C', 2, 0],
        ['FE_AREA', 'C', 2, 0], ['ZONE', 'C', 3, 0], ['NAME', 'C', 254, 0],
        ['STATE_ZONE', 'C', 5, 0], ['LON', 'N', 12, 7], ['LAT', 'N', 12, 7],
        ['SHORTNAME', 'C', 32, 0],
    ]);

    $shp->records[0]->dbfData = [
        'STATE' => 'NM', 'CWA' => 'ABQ', 'TIME_ZONE' => 'M', 'FE_AREA' => 'nw',
        'ZONE' => '201', 'NAME' => 'Test Zone One', 'STATE_ZONE' => 'NM201',
        'LON' => -108.5, 'LAT' => 36.4, 'SHORTNAME' => 'Zone One',
    ];
    $shp->records[1]->dbfData = [
        'STATE' => 'NM', 'CWA' => 'ABQ', 'TIME_ZONE' => 'M', 'FE_AREA' => 'nw',
        'ZONE' => '202', 'NAME' => 'Test Zone Two', 'STATE_ZONE' => 'NM202',
        'LON' => -108.9, 'LAT' => 36.0, 'SHORTNAME' => 'Zone Two',
    ];

    $shp->saveToFile($base.'.*');

    $zipPath = $dir.'/'.$basename.'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach (['shp', 'shx', 'dbf'] as $ext) {
        $zip->addFile($base.'.'.$ext, $basename.'.'.$ext);
    }
    $zip->close();

    return $zipPath;
}

test('it imports a real small shapefile fixture into the staging table', function () {
    $zipPath = buildFixtureZip('z_test01');
    Http::fake(['weather.gov/fixture.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    expect(DB::table('public_zones_staging')->count())->toBe(2);

    $zoneOne = DB::table('public_zones_staging')->where('state_zone', 'NM201')->first();
    expect($zoneOne)->not->toBeNull()
        ->and($zoneOne->name)->toBe('Test Zone One')
        ->and($zoneOne->cwa)->toBe('ABQ');

    $geometry = DB::selectOne('SELECT ST_AsText(boundary) as wkt FROM public_zones_staging WHERE state_zone = ?', ['NM201']);
    expect($geometry->wkt)->toContain('MULTIPOLYGON');
});

test('re-importing replaces the whole staging table (each layer, including each Marine sub-layer, has its own physical table)', function () {
    DB::insert(
        "INSERT INTO public_zones_staging (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['AZ', 'PSR', '001', 'Stale Zone', 'AZ001', 0, 0, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $zipPath = buildFixtureZip('z_test03');
    Http::fake(['weather.gov/fixture3.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture3.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    expect(DB::table('public_zones_staging')->count())->toBe(2)
        ->and(DB::table('public_zones_staging')->where('state_zone', 'AZ001')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ImportShapefileVersionTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayerVersion;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use App\Support\Shapefiles\ShapefilePolygonAssembler;
use App\Support\Shapefiles\ZoneCountyCorrelationParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeType;
use ZipArchive;

class ImportShapefileVersion
{
    public function __invoke(ShapefileLayerVersion $version): void
    {
        $layer = $version->shapefileLayer;
        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        if ($definition->isShapefile()) {
            $this->importShapefile($version, $definition);
        } else {
            $this->importCorrelation($version, $definition);
        }
    }

    private function importShapefile(ShapefileLayerVersion $version, ShapefileLayerDefinition $definition): void
    {
        $tempDir = storage_path('app/shapefile-imports/'.$version->id);
        File::ensureDirectoryExists($tempDir);

        try {
            $zipPath = $tempDir.'/download.zip';
            Http::withHeaders(['User-Agent' => $this->userAgent()])->sink($zipPath)->get($version->source_url);

            $zip = new ZipArchive;
            $zip->open($zipPath);
            $zip->extractTo($tempDir);
            $zip->close();

            $shpFiles = File::glob($tempDir.'/*.shp') ?: File::glob($tempDir.'/*.SHP');
            $basePath = substr($shpFiles[0], 0, -4);

            $shp = new ShapeFile(ShapeType::Polygon);
            $shp->loadFromFile($basePath.'.*');

            DB::transaction(function () use ($shp, $definition): void {
                DB::table($definition->stagingTable)->delete();

                foreach ($shp->records as $record) {
                    $this->insertShapefileRecord($record, $definition);
                }
            });
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * @param \PhpMyAdmin\ShapeFile\ShapeRecord $record
     */
    private function insertShapefileRecord($record, ShapefileLayerDefinition $definition): void
    {
        $attributes = [];

        foreach ($definition->attributeMap as $sourceField => $column) {
            $attributes[$column] = trim((string) ($record->dbfData[$sourceField] ?? ''));
        }

        if ($definition->zoneType !== null) {
            $attributes['zone_type'] = $definition->zoneType;
        }

        $wkt = ShapefilePolygonAssembler::toMultiPolygonWkt($record->shpData['parts'] ?? []);

        $columns = array_keys($attributes);
        $sql = sprintf(
            'INSERT INTO %s (%s, boundary) VALUES (%s, ST_SetSRID(ST_GeomFromText(?), 4269))',
            $definition->stagingTable,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        );

        DB::insert($sql, [...array_values($attributes), $wkt]);
    }

    private function importCorrelation(ShapefileLayerVersion $version, ShapefileLayerDefinition $definition): void
    {
        $response = Http::withHeaders(['User-Agent' => $this->userAgent()])->get($version->source_url);
        $rows = (new ZoneCountyCorrelationParser)->parse($response->body());

        DB::transaction(function () use ($rows, $definition): void {
            DB::table($definition->stagingTable)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($definition->stagingTable)->insert(array_map(
                    fn (array $row): array => [...$row, 'created_at' => now(), 'updated_at' => now()],
                    $chunk,
                ));
            }
        });
    }

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ImportShapefileVersionTest.php --compact"
```

Expected: FAILS on the correlation-branch reference (`ZoneCountyCorrelationParser` doesn't exist yet) only if PHP eagerly resolves the use statement at parse time — it does not fail at runtime for the shapefile-only tests above since that branch is never executed by them. Confirm both shapefile tests PASS; Task 21 adds the missing parser class next.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Shapefiles/ImportShapefileVersion.php tests/Feature/ImportShapefileVersionTest.php
git commit -m "Add ImportShapefileVersion's shapefile-format import path

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 21: `ZoneCountyCorrelationParser` + correlation import branch

**Files:**
- Create: `app/Support/Shapefiles/ZoneCountyCorrelationParser.php`
- Create: `tests/Unit/ZoneCountyCorrelationParserTest.php`
- Create: `tests/Feature/ImportShapefileVersionCorrelationTest.php`

The correlation file is plain pipe-delimited text with no header row: `STATE|ZONE|CWA|NAME|STATE_ZONE|COUNTY|FIPS|TIME_ZONE|FE_AREA|LAT|LON` — verified against a real downloaded file.

**Bug found post-implementation (via a real import against live NOAA data) and fixed**: the same root cause as Task 20's split-record bug, a different shape. NOAA's real correlation file has 27 of 4,875 lines sharing a duplicate `(state_zone, fips)` pair — the same zone-county overlap split into two disjoint areas (identical attributes, different centroid), same as the shapefile-record splitting but in plain-text form. This table carries no geometry column to merge into, so the fix is simpler: `ImportShapefileVersion::importCorrelation()` now deduplicates parsed rows by natural key before inserting, keeping the first occurrence — see `deduplicateByNaturalKey()` in `app/Actions/Shapefiles/ImportShapefileVersion.php`.

- [ ] **Step 1: Write the failing parser test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ZoneCountyCorrelationParserTest --unit --no-interaction"
```

```php
<?php

use App\Support\Shapefiles\ZoneCountyCorrelationParser;

test('it parses real pipe-delimited correlation lines into column-keyed rows', function () {
    $content = "NM|201|ABQ|Northwest Plateau|NM201|McKinley|35031|M|nw|36.4270|-108.4064\n"
        ."NM|201|ABQ|Northwest Plateau|NM201|San Juan|35045|M|nw|36.4270|-108.4064";

    $rows = (new ZoneCountyCorrelationParser)->parse($content);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['state'])->toBe('NM')
        ->and($rows[0]['state_zone'])->toBe('NM201')
        ->and($rows[0]['county'])->toBe('McKinley')
        ->and($rows[0]['fips'])->toBe('35031')
        ->and($rows[0]['lat'])->toBe('36.4270')
        ->and($rows[0]['lon'])->toBe('-108.4064')
        ->and($rows[1]['county'])->toBe('San Juan');
});

test('it skips blank lines', function () {
    $content = "NM|201|ABQ|Northwest Plateau|NM201|McKinley|35031|M|nw|36.4270|-108.4064\n\n";

    expect((new ZoneCountyCorrelationParser)->parse($content))->toHaveCount(1);
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ZoneCountyCorrelationParserTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement the parser**

```php
<?php

namespace App\Support\Shapefiles;

class ZoneCountyCorrelationParser
{
    private const FIELDS = [
        'STATE', 'ZONE', 'CWA', 'NAME', 'STATE_ZONE', 'COUNTY',
        'FIPS', 'TIME_ZONE', 'FE_AREA', 'LAT', 'LON',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $content): array
    {
        $definition = ShapefileLayerDefinitions::forKey('zone_county_correlation');
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($content)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $record = array_combine(self::FIELDS, explode('|', $line));

            $row = [];
            foreach ($definition->attributeMap as $sourceField => $column) {
                $row[$column] = trim($record[$sourceField] ?? '');
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
```

- [ ] **Step 4: Run the parser test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ZoneCountyCorrelationParserTest.php --compact"
```

Expected: PASS. This also unblocks Task 20's `ImportShapefileVersion` class (its correlation branch references this class).

- [ ] **Step 5: Write the failing correlation-import test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ImportShapefileVersionCorrelationTest --no-interaction"
```

```php
<?php

use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('it imports a real correlation-file fixture into the staging table', function () {
    $content = "NM|201|ABQ|Northwest Plateau|NM201|McKinley|35031|M|nw|36.4270|-108.4064\n"
        ."NM|201|ABQ|Northwest Plateau|NM201|San Juan|35045|M|nw|36.4270|-108.4064";

    Http::fake(['weather.gov/fixture.dbx' => Http::response($content)]);

    $layer = ShapefileLayer::factory()->create(['key' => 'zone_county_correlation']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture.dbx',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    expect(DB::table('zone_county_correlations_staging')->count())->toBe(2);

    $row = DB::table('zone_county_correlations_staging')->where('county', 'McKinley')->first();
    expect($row)->not->toBeNull()
        ->and($row->state_zone)->toBe('NM201')
        ->and($row->fips)->toBe('35031');
});

test('re-importing replaces the whole correlation staging table', function () {
    DB::table('zone_county_correlations_staging')->insert([
        'state' => 'AZ', 'zone' => '001', 'cwa' => 'PSR', 'name' => 'Stale', 'state_zone' => 'AZ001',
        'county' => 'Stale County', 'fips' => '04000', 'lat' => 0, 'lon' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Http::fake(['weather.gov/fixture.dbx' => Http::response(
        'NM|201|ABQ|Northwest Plateau|NM201|McKinley|35031|M|nw|36.4270|-108.4064'
    )]);

    $layer = ShapefileLayer::factory()->create(['key' => 'zone_county_correlation']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture.dbx',
    ]);

    (new ImportShapefileVersion)($version);

    expect(DB::table('zone_county_correlations_staging')->count())->toBe(1)
        ->and(DB::table('zone_county_correlations_staging')->where('county', 'Stale County')->exists())->toBeFalse();
});
```

- [ ] **Step 6: Run it**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ImportShapefileVersionCorrelationTest.php --compact"
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Support/Shapefiles/ZoneCountyCorrelationParser.php tests/Unit/ZoneCountyCorrelationParserTest.php tests/Feature/ImportShapefileVersionCorrelationTest.php
git commit -m "Add the Zone-County Correlation parser and its import branch

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 22: `ComputeShapefileLayerDiff` — added/removed/changed + override conflicts

**Files:**
- Create: `app/Actions/Shapefiles/ComputeShapefileLayerDiff.php`
- Test: `tests/Feature/ComputeShapefileLayerDiffTest.php`

Per Known Gap #2's resolution: the diff compares the live table against the staging table's **raw NOAA values** (not post-override values) — this is what makes a disagreement between a standing override and NOAA's new data visible instead of silently swallowed.

- [ ] **Step 1: Write the failing tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ComputeShapefileLayerDiffTest --no-interaction"
```

```php
<?php

use App\Actions\Shapefiles\ComputeShapefileLayerDiff;
use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Support\Facades\DB;

function insertZoneRow(string $table, string $stateZone, string $name, string $cwa = 'ABQ'): void
{
    DB::insert(
        "INSERT INTO {$table} (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', $cwa, '201', $name, $stateZone, -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );
}

test('it counts added, removed, and changed records correctly', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $definition = ShapefileLayerDefinitions::forKey('public_zones');

    insertZoneRow('public_zones', 'NM201', 'Unchanged Zone');
    insertZoneRow('public_zones_staging', 'NM201', 'Unchanged Zone');

    insertZoneRow('public_zones', 'NM202', 'Old Name');
    insertZoneRow('public_zones_staging', 'NM202', 'New Name');

    insertZoneRow('public_zones', 'NM203', 'Removed Zone');

    insertZoneRow('public_zones_staging', 'NM204', 'Added Zone');

    $diff = (new ComputeShapefileLayerDiff)($layer, $definition);

    expect($diff['added'])->toBe(1)
        ->and($diff['removed'])->toBe(1)
        ->and($diff['changed'])->toBe(1)
        ->and($diff['override_conflicts'])->toBe([]);
});

test('it flags a standing override that disagrees with NOAA\'s new raw staged value', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $definition = ShapefileLayerDefinitions::forKey('public_zones');
    $user = User::factory()->create();

    insertZoneRow('public_zones', 'NM201', 'Admin-Corrected Name');
    insertZoneRow('public_zones_staging', 'NM201', 'NOAA Renamed It Again');

    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Admin-Corrected Name',
        'created_by' => $user->id,
    ]);

    $diff = (new ComputeShapefileLayerDiff)($layer, $definition);

    expect($diff['override_conflicts'])->toBe(['NM201']);
});

test('a marine sub-layer diffs against its own dedicated table, independent of the other two', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'marine_zones_offshore']);
    $definition = ShapefileLayerDefinitions::forKey('marine_zones_offshore');

    DB::table('marine_zones_offshore')->insert([
        'zone_type' => 'offshore', 'zone_id' => 'OZ001', 'name' => 'Offshore Zone',
        'lon' => 0, 'lat' => 0, 'boundary' => DB::raw("ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('marine_zones_offshore_staging')->insert([
        'zone_type' => 'offshore', 'zone_id' => 'OZ999', 'name' => 'Different Offshore Zone',
        'lon' => 0, 'lat' => 0, 'boundary' => DB::raw("ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('marine_zones_coastal')->insert([
        'zone_type' => 'coastal', 'zone_id' => 'OZ001', 'name' => 'Coincidentally same zone_id, different table/layer',
        'lon' => 0, 'lat' => 0, 'boundary' => DB::raw("ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $diff = (new ComputeShapefileLayerDiff)($layer, $definition);

    expect($diff['added'])->toBe(1)
        ->and($diff['removed'])->toBe(1);
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ComputeShapefileLayerDiffTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ComputeShapefileLayerDiff
{
    /**
     * @return array{added: int, removed: int, changed: int, override_conflicts: array<int, string>}
     */
    public function __invoke(ShapefileLayer $layer, ShapefileLayerDefinition $definition): array
    {
        $columns = $this->comparableColumns($definition);

        $liveRows = $this->keyedRows(DB::table($definition->liveTable), $definition, $columns);
        $stagingRows = $this->keyedRows(DB::table($definition->stagingTable), $definition, $columns);

        $addedKeys = array_diff(array_keys($stagingRows), array_keys($liveRows));
        $removedKeys = array_diff(array_keys($liveRows), array_keys($stagingRows));
        $commonKeys = array_intersect(array_keys($stagingRows), array_keys($liveRows));

        $changedKeys = array_values(array_filter(
            $commonKeys,
            fn (string $key): bool => $liveRows[$key] !== $stagingRows[$key],
        ));

        $overrideConflicts = [];

        foreach (ShapefileRecordOverride::where('shapefile_layer_id', $layer->id)->get() as $override) {
            $stagingRow = $stagingRows[$override->record_key] ?? null;

            if ($stagingRow !== null
                && array_key_exists($override->field, $stagingRow)
                && (string) $stagingRow[$override->field] !== (string) $override->value) {
                $overrideConflicts[] = $override->record_key;
            }
        }

        return [
            'added' => count($addedKeys),
            'removed' => count($removedKeys),
            'changed' => count($changedKeys),
            'override_conflicts' => array_values(array_unique($overrideConflicts)),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function comparableColumns(ShapefileLayerDefinition $definition): array
    {
        $columns = array_values($definition->attributeMap);

        if ($definition->zoneType !== null) {
            $columns[] = 'zone_type';
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param array<int, string> $columns
     * @return array<string, array<string, mixed>>
     */
    private function keyedRows(Builder $query, ShapefileLayerDefinition $definition, array $columns): array
    {
        $keyed = [];

        foreach ($query->select($columns)->get() as $row) {
            $record = (array) $row;
            $keyed[$definition->serializeNaturalKey($record)] = $record;
        }

        return $keyed;
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ComputeShapefileLayerDiffTest.php --compact"
```

Expected: PASS.

- [ ] **Step 5: Wire it into `ImportShapefileVersion`**

In `app/Actions/Shapefiles/ImportShapefileVersion.php`, add the `ComputeShapefileLayerDiff` dependency and store its result on the version after each import. Change the class to:

```php
use App\Actions\Shapefiles\ComputeShapefileLayerDiff;
```

and at the end of `__invoke()`:

```php
    public function __invoke(ShapefileLayerVersion $version): void
    {
        $layer = $version->shapefileLayer;
        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        if ($definition->isShapefile()) {
            $this->importShapefile($version, $definition);
        } else {
            $this->importCorrelation($version, $definition);
        }

        $diff = (new ComputeShapefileLayerDiff)($layer, $definition);
        $version->update(['diff_summary' => $diff]);
    }
```

- [ ] **Step 6: Add a test confirming the diff gets stored**

Add to `tests/Feature/ImportShapefileVersionTest.php`:

```php
test('it stores the computed diff summary on the version after importing', function () {
    $zipPath = buildFixtureZip('z_test02');
    Http::fake(['weather.gov/fixture2.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture2.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    expect($version->fresh()->diff_summary)->toBe([
        'added' => 2, 'removed' => 0, 'changed' => 0, 'override_conflicts' => [],
    ]);
});
```

- [ ] **Step 7: Run the full import test file**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ImportShapefileVersionTest.php --compact"
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Actions/Shapefiles/ComputeShapefileLayerDiff.php app/Actions/Shapefiles/ImportShapefileVersion.php tests/Feature/ComputeShapefileLayerDiffTest.php tests/Feature/ImportShapefileVersionTest.php
git commit -m "Compute and store the added/removed/changed diff, including override conflicts

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 23: Notify staff when a version is staged

**Files:**
- Create: `app/Notifications/ShapefileLayerUpdateAvailableNotification.php`
- Modify: `app/Actions/Shapefiles/ImportShapefileVersion.php`
- Test: `tests/Feature/ImportShapefileVersionTest.php`

Reuses the same database-notification channel already established for product retirement (`AlertRuleDisabledNotification`).

- [ ] **Step 1: The notification class**

```php
<?php

namespace App\Notifications;

use App\Models\ShapefileLayerVersion;
use Illuminate\Notifications\Notification;

class ShapefileLayerUpdateAvailableNotification extends Notification
{
    public function __construct(
        public ShapefileLayerVersion $version,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $diff = $this->version->diff_summary ?? ['added' => 0, 'removed' => 0, 'changed' => 0];
        $layer = $this->version->shapefileLayer;

        return [
            'message' => "{$layer->name} has a pending update: {$diff['added']} added, {$diff['removed']} removed, {$diff['changed']} changed — review and activate.",
            'shapefile_layer_id' => $layer->id,
            'shapefile_layer_version_id' => $this->version->id,
        ];
    }
}
```

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/ImportShapefileVersionTest.php`:

```php
use App\Models\User;
use App\Notifications\ShapefileLayerUpdateAvailableNotification;
use Illuminate\Support\Facades\Notification;

test('it notifies every staff user, and no one else, once the diff is computed', function () {
    Notification::fake();

    $staff = User::factory()->create(['is_staff' => true]);
    $subscriber = User::factory()->create(['is_staff' => false]);

    $zipPath = buildFixtureZip('z_test04');
    Http::fake(['weather.gov/fixture4.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture4.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    Notification::assertSentTo($staff, ShapefileLayerUpdateAvailableNotification::class);
    Notification::assertNotSentTo($subscriber, ShapefileLayerUpdateAvailableNotification::class);
});
```

- [ ] **Step 3: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ImportShapefileVersionTest.php --compact"
```

Expected: FAIL — no notification is dispatched yet.

- [ ] **Step 4: Wire it into `ImportShapefileVersion`**

In `app/Actions/Shapefiles/ImportShapefileVersion.php`, add:

```php
use App\Models\User;
use App\Notifications\ShapefileLayerUpdateAvailableNotification;
```

and change the end of `__invoke()` to:

```php
        $diff = (new ComputeShapefileLayerDiff)($layer, $definition);
        $version->update(['diff_summary' => $diff]);

        User::where('is_staff', true)->get()->each(
            fn (User $staff) => $staff->notify(new ShapefileLayerUpdateAvailableNotification($version)),
        );
```

- [ ] **Step 5: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ImportShapefileVersionTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Notifications/ShapefileLayerUpdateAvailableNotification.php app/Actions/Shapefiles/ImportShapefileVersion.php tests/Feature/ImportShapefileVersionTest.php
git commit -m "Notify staff when a shapefile layer has a pending update to review

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 24: `recordOverrides()` relation + `ApplyRecordOverrides` action

**Files:**
- Modify: `app/Models/ShapefileLayer.php`
- Create: `app/Actions/Shapefiles/ApplyRecordOverrides.php`
- Test: `tests/Feature/ApplyRecordOverridesTest.php`

- [ ] **Step 1: Add the relation**

In `app/Models/ShapefileLayer.php`, add alongside `versions()`:

```php
    public function recordOverrides(): HasMany
    {
        return $this->hasMany(ShapefileRecordOverride::class);
    }
```

(`HasMany` is already imported for `versions()`.)

- [ ] **Step 2: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ApplyRecordOverridesTest --no-interaction"
```

```php
<?php

use App\Actions\Shapefiles\ApplyRecordOverrides;
use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Support\Facades\DB;

test('it applies a standing override onto the live table by natural key', function () {
    DB::insert(
        "INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', 'ABQ', '201', 'NOAA Default Name', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Admin-Corrected Name',
        'created_by' => User::factory(),
    ]);

    $applied = (new ApplyRecordOverrides)($layer, ShapefileLayerDefinitions::forKey('public_zones'));

    expect($applied)->toBe(1)
        ->and(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin-Corrected Name');
});

test('it applies a composite-key override correctly', function () {
    DB::insert(
        'INSERT INTO zone_county_correlations (state, zone, cwa, name, state_zone, county, fips, lat, lon, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
        ['NM', '201', 'ABQ', 'Northwest Plateau', 'NM201', 'McKinley', '35031', 36.4, -108.5],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'zone_county_correlation']);
    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201|35031',
        'field' => 'county',
        'value' => 'Corrected County',
        'created_by' => User::factory(),
    ]);

    (new ApplyRecordOverrides)($layer, ShapefileLayerDefinitions::forKey('zone_county_correlation'));

    expect(DB::table('zone_county_correlations')->where('state_zone', 'NM201')->value('county'))->toBe('Corrected County');
});
```

- [ ] **Step 3: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ApplyRecordOverridesTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 4: Implement**

```php
<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use Illuminate\Support\Facades\DB;

class ApplyRecordOverrides
{
    public function __invoke(ShapefileLayer $layer, ShapefileLayerDefinition $definition): int
    {
        $applied = 0;

        foreach ($layer->recordOverrides as $override) {
            $keyParts = explode('|', $override->record_key);
            $query = DB::table($definition->liveTable);

            foreach ($definition->naturalKeyColumns as $index => $column) {
                $query->where($column, $keyParts[$index] ?? '');
            }

            $applied += $query->update([$override->field => $override->value]);
        }

        return $applied;
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ApplyRecordOverridesTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/ShapefileLayer.php app/Actions/Shapefiles/ApplyRecordOverrides.php tests/Feature/ApplyRecordOverridesTest.php
git commit -m "Add ApplyRecordOverrides so admin corrections survive reimport

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 25: `ActivateShapefileVersion` — the atomic swap

**Files:**
- Create: `app/Actions/Shapefiles/ActivateShapefileVersion.php`
- Test: `tests/Feature/ActivateShapefileVersionTest.php`

**Bug caught during execution, fixed below**: the constructor originally had no default for `$applyRecordOverrides`, but every test in this plan that touches `ActivateShapefileVersion` — including Task 26's rollback test, which activates twice to set up its scenario — instantiates it via a bare `new ActivateShapefileVersion` with zero arguments, which would throw `ArgumentCountError`. Fixed using PHP 8.1's "new in initializers" feature (this app targets PHP 8.5, so it's available): the promoted property defaults to `new ApplyRecordOverrides` directly in the signature. This keeps container-based DI working unchanged (Laravel still injects a resolved instance when the class is built through the container, e.g. from a Filament action) while making every bare `new ActivateShapefileVersion()` call in this plan's tests work correctly too.

Runs the 3-step `ALTER TABLE ... RENAME` dance inside a transaction, flips version statuses, updates `active_version_id`, then reapplies standing overrides. Because every layer (Marine's 3 sub-layers included, per Task 10's correction) has its own dedicated table pair, this one method works identically for all 9 layers — no special-casing needed.

- [ ] **Step 1: Write the failing tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ActivateShapefileVersionTest --no-interaction"
```

```php
<?php

use App\Actions\Shapefiles\ActivateShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('activating a staged version swaps staging into live and applies standing overrides', function () {
    DB::insert(
        "INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', 'ABQ', '201', 'Old Live Name', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );
    DB::insert(
        "INSERT INTO public_zones_staging (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', 'ABQ', '201', 'New Staged Name', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Admin Override Name',
        'created_by' => User::factory(),
    ]);

    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ActivateShapefileVersion)($version);

    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin Override Name')
        ->and(DB::table('public_zones_staging')->where('state_zone', 'NM201')->value('name'))->toBe('Old Live Name')
        ->and($version->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($version->fresh()->activated_at)->not->toBeNull()
        ->and($layer->fresh()->active_version_id)->toBe($version->id);
});

test('activating supersedes the previously active version', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'us_states']);
    $oldVersion = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Active,
    ]);
    $layer->update(['active_version_id' => $oldVersion->id]);

    $newVersion = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ActivateShapefileVersion)($newVersion);

    expect($oldVersion->fresh()->status)->toBe(ShapefileVersionStatus::Superseded)
        ->and($newVersion->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($layer->fresh()->active_version_id)->toBe($newVersion->id);
});

test('a first-ever activation with no previous active version does not error', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'cwa_boundaries']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ActivateShapefileVersion)($version);

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Active);
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ActivateShapefileVersionTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Actions\Shapefiles;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayerVersion;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Support\Facades\DB;

class ActivateShapefileVersion
{
    public function __construct(private ApplyRecordOverrides $applyRecordOverrides = new ApplyRecordOverrides) {}

    public function __invoke(ShapefileLayerVersion $version): void
    {
        $layer = $version->shapefileLayer;
        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        DB::transaction(function () use ($layer, $version, $definition): void {
            $previousVersion = $layer->activeVersion;

            $this->swap($definition);

            $previousVersion?->update(['status' => ShapefileVersionStatus::Superseded]);

            $version->update(['status' => ShapefileVersionStatus::Active, 'activated_at' => now()]);
            $layer->update(['active_version_id' => $version->id]);
        });

        ($this->applyRecordOverrides)($layer, $definition);
    }

    private function swap(ShapefileLayerDefinition $definition): void
    {
        $live = $definition->liveTable;
        $staging = $definition->stagingTable;
        $previous = "{$live}_swap_previous";

        DB::statement("ALTER TABLE {$live} RENAME TO {$previous}");
        DB::statement("ALTER TABLE {$staging} RENAME TO {$live}");
        DB::statement("ALTER TABLE {$previous} RENAME TO {$staging}");
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ActivateShapefileVersionTest.php --compact"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Shapefiles/ActivateShapefileVersion.php tests/Feature/ActivateShapefileVersionTest.php
git commit -m "Add ActivateShapefileVersion's atomic table-rename swap

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 26: `RollbackShapefileLayer`

**Files:**
- Create: `app/Actions/Shapefiles/RollbackShapefileLayer.php`
- Test: `tests/Feature/RollbackShapefileLayerTest.php`

Rollback is the identical swap run again: it restores whichever version is currently `superseded` back into the live slot, demoting the currently `active` one back to `superseded` in turn.

- [ ] **Step 1: Write the failing tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest RollbackShapefileLayerTest --no-interaction"
```

```php
<?php

use App\Actions\Shapefiles\ActivateShapefileVersion;
use App\Actions\Shapefiles\RollbackShapefileLayer;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use Illuminate\Support\Facades\DB;

test('rolling back restores the previous version and demotes the current one', function () {
    DB::insert(
        "INSERT INTO us_states_staging (state, name, fips, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', 'New Mexico v1', '35', -106.0, 34.5, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'us_states']);
    $versionOne = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);
    (new ActivateShapefileVersion)($versionOne);

    DB::insert(
        "INSERT INTO us_states_staging (state, name, fips, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', 'New Mexico v2', '35', -106.0, 34.5, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );
    $versionTwo = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);
    (new ActivateShapefileVersion)($versionTwo);

    expect(DB::table('us_states')->value('name'))->toBe('New Mexico v2');

    (new RollbackShapefileLayer)($layer->fresh());

    expect(DB::table('us_states')->value('name'))->toBe('New Mexico v1')
        ->and($versionOne->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($versionTwo->fresh()->status)->toBe(ShapefileVersionStatus::Superseded)
        ->and($layer->fresh()->active_version_id)->toBe($versionOne->id);
});

test('rolling back with nothing superseded does nothing', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'counties']);

    (new RollbackShapefileLayer)($layer);

    expect($layer->fresh()->active_version_id)->toBeNull();
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/RollbackShapefileLayerTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Actions\Shapefiles;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Support\Facades\DB;

class RollbackShapefileLayer
{
    public function __invoke(ShapefileLayer $layer): void
    {
        $currentVersion = $layer->activeVersion;

        $restoreVersion = ShapefileLayerVersion::where('shapefile_layer_id', $layer->id)
            ->where('status', ShapefileVersionStatus::Superseded)
            ->orderByDesc('activated_at')
            ->first();

        if ($restoreVersion === null) {
            return;
        }

        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        DB::transaction(function () use ($layer, $currentVersion, $restoreVersion, $definition): void {
            $this->swap($definition);

            $currentVersion?->update(['status' => ShapefileVersionStatus::Superseded]);
            $restoreVersion->update(['status' => ShapefileVersionStatus::Active, 'activated_at' => now()]);
            $layer->update(['active_version_id' => $restoreVersion->id]);
        });
    }

    private function swap(ShapefileLayerDefinition $definition): void
    {
        $live = $definition->liveTable;
        $staging = $definition->stagingTable;
        $previous = "{$live}_swap_previous";

        DB::statement("ALTER TABLE {$live} RENAME TO {$previous}");
        DB::statement("ALTER TABLE {$staging} RENAME TO {$live}");
        DB::statement("ALTER TABLE {$previous} RENAME TO {$staging}");
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/RollbackShapefileLayerTest.php --compact"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Shapefiles/RollbackShapefileLayer.php tests/Feature/RollbackShapefileLayerTest.php
git commit -m "Add RollbackShapefileLayer to restore the previous version

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 27: `DismissShapefileVersion`

**Files:**
- Create: `app/Actions/Shapefiles/DismissShapefileVersion.php`
- Test: `tests/Feature/DismissShapefileVersionTest.php`

Marks a staged version `rejected` without activating it — clears the "pending review" badge without promoting anything. Since `last_seen_checksum`/`last_seen_filename` were already updated when the version was staged (Task 17), the next scan won't re-flag the same NOAA file; a genuinely later NOAA update still gets staged normally.

- [ ] **Step 1: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest DismissShapefileVersionTest --no-interaction"
```

```php
<?php

use App\Actions\Shapefiles\DismissShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;

test('it marks a staged version rejected without touching the layer\'s active version', function () {
    $layer = ShapefileLayer::factory()->create(['active_version_id' => null]);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new DismissShapefileVersion)($version);

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Rejected)
        ->and($layer->fresh()->active_version_id)->toBeNull();
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DismissShapefileVersionTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Actions\Shapefiles;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayerVersion;

class DismissShapefileVersion
{
    public function __invoke(ShapefileLayerVersion $version): void
    {
        $version->update(['status' => ShapefileVersionStatus::Rejected]);
    }
}
```

- [ ] **Step 4: Run the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DismissShapefileVersionTest.php --compact"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Shapefiles/DismissShapefileVersion.php tests/Feature/DismissShapefileVersionTest.php
git commit -m "Add DismissShapefileVersion to reject a staged version without activating it

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 28: `pendingVersion()` relation + `ShapefileLayerResource` List page

**Files:**
- Modify: `app/Models/ShapefileLayer.php`
- Create: `app/Filament/Admin/Resources/ShapefileLayers/ShapefileLayerResource.php`
- Create: `app/Filament/Admin/Resources/ShapefileLayers/Tables/ShapefileLayersTable.php`
- Create: `app/Filament/Admin/Resources/ShapefileLayers/Pages/ListShapefileLayers.php`
- Test: `tests/Feature/ShapefileLayerResourceTest.php`

`app/Filament/Admin/Resources` is auto-discovered by `AdminPanelProvider` — no manual registration needed. Per `.ai/rules/filament.md`, testing a resource on the `admin` panel needs `Filament::setCurrentPanel('admin')` in `beforeEach()`.

- [ ] **Step 1: Add the `pendingVersion()` relation**

In `app/Models/ShapefileLayer.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

```php
    public function pendingVersion(): HasOne
    {
        return $this->hasOne(ShapefileLayerVersion::class)
            ->where('status', \App\Enums\ShapefileVersionStatus::Staged)
            ->latestOfMany();
    }
```

- [ ] **Step 2: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefileLayerResourceTest --no-interaction"
```

```php
<?php

use App\Enums\ShapefileVersionStatus;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ListShapefileLayers;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('the list page shows every tracked layer and flags one with a pending review', function () {
    $upToDate = ShapefileLayer::factory()->create(['name' => 'Up To Date Layer']);
    $pending = ShapefileLayer::factory()->create(['name' => 'Pending Layer']);
    ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $pending->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    livewire(ListShapefileLayers::class)
        ->assertCanSeeTableRecords([$upToDate, $pending])
        ->assertSee('Up to date')
        ->assertSee('Pending review');
});
```

- [ ] **Step 3: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerResourceTest.php --compact"
```

Expected: FAIL — `ShapefileLayerResource` doesn't exist.

- [ ] **Step 4: Implement the resource**

```php
<?php

namespace App\Filament\Admin\Resources\ShapefileLayers;

use App\Filament\Admin\Resources\ShapefileLayers\Pages\ListShapefileLayers;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ViewShapefileLayer;
use App\Filament\Admin\Resources\ShapefileLayers\Tables\ShapefileLayersTable;
use App\Models\ShapefileLayer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ShapefileLayerResource extends Resource
{
    protected static ?string $model = ShapefileLayer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Geography';

    protected static ?string $navigationLabel = 'Shapefile Layers';

    protected static ?string $modelLabel = 'shapefile layer';

    public static function table(Table $table): Table
    {
        return ShapefileLayersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShapefileLayers::route('/'),
            'view' => ViewShapefileLayer::route('/{record}'),
        ];
    }
}
```

(`ViewShapefileLayer` is created in Task 29 — this resource won't autoload cleanly until then; that's expected mid-task state, not a defect.)

- [ ] **Step 5: Implement the table**

**Bug caught during execution, fixed in the code below**: the original snippet derived the status badge via `TextColumn::make('pendingVersion.filename')->formatStateUsing(...)`. Filament's `getState()` (confirmed by reading `vendor/filament/support/src/Concerns/HasCellState.php`) substitutes the column's default state whenever the raw dot-relation value is blank — which it is whenever there's no pending version — *before* `formatStateUsing()` ever runs, so the "Up to date" branch never rendered. Fixed by computing the state from the record via `->state(fn (ShapefileLayer $record) => ...)` instead of a dot-relation path, which is never blank. This is now also recorded in `.ai/rules/filament.md` for future resources with the same pattern (a badge/label derived from whether a nullable relation exists).

**Added post-implementation, per user feedback after manually reviewing the running admin UI**: a "Records" column showing each layer's actual current live-table row count, not a value derived from version metadata — real ground truth via a direct query against the layer's own live table (resolved through the registry), so it stays honest even if a past import ever diverged from what NOAA's page claimed.

```php
<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Tables;

use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class ShapefileLayersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('activeVersion.effective_at')
                    ->label('Active version')
                    ->date()
                    ->placeholder('Never activated'),
                TextColumn::make('records')
                    ->label('Records')
                    ->state(fn (ShapefileLayer $record): int => DB::table(
                        ShapefileLayerDefinitions::forKey($record->key)->liveTable
                    )->count())
                    ->numeric(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ShapefileLayer $record): bool => $record->pendingVersion !== null)
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Pending review' : 'Up to date')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'success'),
            ])
            ->defaultSort('name');
    }
}
```

Note: this column calls `ShapefileLayerDefinitions::forKey($record->key)` for every rendered row, which throws if `$record->key` isn't one of the 9 real registry keys — fine for real usage (every `ShapefileLayer` row only ever comes from `ShapefileLayerSeeder`, always with a real key), but any test rendering this table needs `ShapefileLayer::factory()->create(['key' => '<a real key>'])`, not a bare `factory()->create()` (whose default `key` is a random fake slug).

- [ ] **Step 6: Implement the List page**

```php
<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Pages;

use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use Filament\Resources\Pages\ListRecords;

class ListShapefileLayers extends ListRecords
{
    protected static string $resource = ShapefileLayerResource::class;
}
```

- [ ] **Step 7: Move to Task 29 before running tests**

The List page's table links each row to the `view` route, so this test won't fully pass until `ViewShapefileLayer` exists (Task 29). Continue directly to Task 29, then come back and run this test.

---

### Task 29: `ViewShapefileLayer` page — diff display + Activate/Dismiss/Rollback

**Files:**
- Create: `app/Filament/Admin/Resources/ShapefileLayers/Pages/ViewShapefileLayer.php`
- Create: `app/Filament/Admin/Resources/ShapefileLayers/Schemas/ShapefileLayerInfolist.php`
- Test: `tests/Feature/ShapefileLayerResourceTest.php` (extends Task 28's file)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ShapefileLayerResourceTest.php`:

```php
use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ViewShapefileLayer;
use Filament\Actions\Testing\TestAction;

test('the list page links through to the view page', function () {
    $layer = ShapefileLayer::factory()->create();

    livewire(ListShapefileLayers::class)
        ->assertCanSeeTableRecords([$layer]);

    $this->get(\App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource::getUrl('view', ['record' => $layer]))
        ->assertSuccessful();
});

test('the view page shows the pending diff and an Activate action that activates it', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'us_states']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
        'diff_summary' => ['added' => 3, 'removed' => 1, 'changed' => 2, 'override_conflicts' => []],
    ]);

    livewire(ViewShapefileLayer::class, ['record' => $layer->getRouteKey()])
        ->assertSee('3')
        ->assertSee('1')
        ->assertSee('2')
        ->callAction(TestAction::make('activate')->table($version))
        ->assertNotified();

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($layer->fresh()->active_version_id)->toBe($version->id);
});

test('the Dismiss action rejects a staged version without activating it', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'counties']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    livewire(ViewShapefileLayer::class, ['record' => $layer->getRouteKey()])
        ->callAction(TestAction::make('dismiss')->table($version))
        ->assertNotified();

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Rejected)
        ->and($layer->fresh()->active_version_id)->toBeNull();
});

test('the Rollback action is only visible when a superseded version exists', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'fire_zones']);

    livewire(ViewShapefileLayer::class, ['record' => $layer->getRouteKey()])
        ->assertActionHidden('rollback');

    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Superseded,
    ]);

    livewire(ViewShapefileLayer::class, ['record' => $layer->fresh()->getRouteKey()])
        ->assertActionVisible('rollback');
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerResourceTest.php --compact"
```

Expected: FAIL — `ViewShapefileLayer` doesn't exist.

- [ ] **Step 3: Implement the infolist**

```php
<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Schemas;

use App\Models\ShapefileLayer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShapefileLayerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pending review')
                    ->visible(fn (ShapefileLayer $record): bool => $record->pendingVersion !== null)
                    ->schema([
                        TextEntry::make('pendingVersion.filename')->label('File'),
                        TextEntry::make('pendingVersion.effective_at')->label('Effective date')->date(),
                        TextEntry::make('pendingVersion.diff_summary.added')->label('Added'),
                        TextEntry::make('pendingVersion.diff_summary.removed')->label('Removed'),
                        TextEntry::make('pendingVersion.diff_summary.changed')->label('Changed'),
                        TextEntry::make('pendingVersion.diff_summary.override_conflicts')
                            ->label('Overrides in conflict with NOAA\'s new data')
                            ->formatStateUsing(fn (?array $state): string => $state === null || $state === [] ? 'None' : implode(', ', $state))
                            ->color(fn (?array $state): string => $state === null || $state === [] ? 'gray' : 'danger'),
                    ]),
                Section::make('Active version')
                    ->schema([
                        TextEntry::make('activeVersion.filename')->label('File')->placeholder('Never activated'),
                        TextEntry::make('activeVersion.effective_at')->label('Effective date')->date()->placeholder('—'),
                        TextEntry::make('activeVersion.activated_at')->label('Activated')->dateTime()->placeholder('—'),
                    ]),
            ]);
    }
}
```

- [ ] **Step 4: Implement the View page with Activate/Dismiss/Rollback actions**

```php
<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Pages;

use App\Actions\Shapefiles\ActivateShapefileVersion;
use App\Actions\Shapefiles\DismissShapefileVersion;
use App\Actions\Shapefiles\RollbackShapefileLayer;
use App\Enums\ShapefileVersionStatus;
use App\Filament\Admin\Resources\ShapefileLayers\Schemas\ShapefileLayerInfolist;
use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use App\Models\ShapefileLayerVersion;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewShapefileLayer extends ViewRecord
{
    protected static string $resource = ShapefileLayerResource::class;

    public function infolist(Schema $schema): Schema
    {
        return ShapefileLayerInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')
                ->visible(fn (): bool => $this->record->pendingVersion !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ActivateShapefileVersion::class)($this->record->pendingVersion);
                    $this->record->refresh();
                    Notification::make()->title('Layer activated')->success()->send();
                }),
            Action::make('dismiss')
                ->visible(fn (): bool => $this->record->pendingVersion !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(DismissShapefileVersion::class)($this->record->pendingVersion);
                    $this->record->refresh();
                    Notification::make()->title('Version dismissed')->success()->send();
                }),
            Action::make('rollback')
                ->visible(fn (): bool => ShapefileLayerVersion::where('shapefile_layer_id', $this->record->id)
                    ->where('status', ShapefileVersionStatus::Superseded)
                    ->exists())
                ->requiresConfirmation()
                ->action(function (): void {
                    app(RollbackShapefileLayer::class)($this->record);
                    $this->record->refresh();
                    Notification::make()->title('Layer rolled back')->success()->send();
                }),
        ];
    }
}
```

Add the missing `Notification` import:

```php
use Filament\Notifications\Notification;
```

- [ ] **Step 5: Fix the test's action-targeting syntax**

The tests above use `TestAction::make('activate')->table($version)`, which is the syntax for a *table row* action — but Activate/Dismiss/Rollback here are *page header* actions, not table row actions (there is no records table on this page, just an infolist). Replace those three calls with plain `->callAction('activate')`, `->callAction('dismiss')`, and use `assertActionHidden('rollback')` / `assertActionVisible('rollback')` as already written (those two already target page actions correctly). Corrected test bodies:

```php
test('the view page shows the pending diff and an Activate action that activates it', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'us_states']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
        'diff_summary' => ['added' => 3, 'removed' => 1, 'changed' => 2, 'override_conflicts' => []],
    ]);

    livewire(ViewShapefileLayer::class, ['record' => $layer->getRouteKey()])
        ->assertSee('3')
        ->assertSee('1')
        ->assertSee('2')
        ->callAction('activate')
        ->assertNotified();

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($layer->fresh()->active_version_id)->toBe($version->id);
});

test('the Dismiss action rejects a staged version without activating it', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'counties']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    livewire(ViewShapefileLayer::class, ['record' => $layer->getRouteKey()])
        ->callAction('dismiss')
        ->assertNotified();

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Rejected)
        ->and($layer->fresh()->active_version_id)->toBeNull();
});
```

- [ ] **Step 6: Run all the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerResourceTest.php --compact"
```

Expected: PASS (5 `test()` blocks: List, list→view link, Activate, Dismiss, and Rollback — which itself checks both the hidden and visible cases in one test).

- [ ] **Step 7: Commit**

```bash
git add app/Models/ShapefileLayer.php app/Filament/Admin/Resources/ShapefileLayers tests/Feature/ShapefileLayerResourceTest.php
git commit -m "Add the ShapefileLayerResource admin UI: list, diff view, and actions

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 30: A plain Eloquent model per geography table + `modelClass` on the registry

**Files:**
- Create: `app/Models/Shapefiles/PublicZone.php`, `Counties.php`... (9 files, one per live table)
- Modify: `app/Support/Shapefiles/ShapefileLayerDefinition.php`
- Modify: `app/Support/Shapefiles/ShapefileLayerDefinitions.php`
- Test: `tests/Unit/ShapefileLayerDefinitionsTest.php`

Filament's `Table` component is built around Eloquent — browsing a layer's live records in the admin UI (Task 31) needs a real (tiny, read-only) model per geography table, not raw query-builder access. Each model's `$table` stays fixed to the *live* table name (e.g. `'public_zones'`) — after `ActivateShapefileVersion`'s rename swap, whichever physical table now bears that name is exactly the current live data, so these models keep working correctly across every activation with no changes needed.

- [ ] **Step 1: Create the 9 models**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && mkdir -p app/Models/Shapefiles"
```

`app/Models/Shapefiles/PublicZone.php`:

```php
<?php

namespace App\Models\Shapefiles;

use Illuminate\Database\Eloquent\Model;

class PublicZone extends Model
{
    protected $table = 'public_zones';

    protected $guarded = [];
}
```

`app/Models/Shapefiles/CountyBoundary.php`:

```php
<?php

namespace App\Models\Shapefiles;

use Illuminate\Database\Eloquent\Model;

class CountyBoundary extends Model
{
    protected $table = 'counties';

    protected $guarded = [];
}
```

`app/Models/Shapefiles/CwaBoundary.php`:

```php
<?php

namespace App\Models\Shapefiles;

use Illuminate\Database\Eloquent\Model;

class CwaBoundary extends Model
{
    protected $table = 'cwa_boundaries';

    protected $guarded = [];
}
```

`app/Models/Shapefiles/MarineZoneCoastal.php`:

```php
<?php

namespace App\Models\Shapefiles;

use Illuminate\Database\Eloquent\Model;

class MarineZoneCoastal extends Model
{
    protected $table = 'marine_zones_coastal';

    protected $guarded = [];
}
```

`app/Models/Shapefiles/MarineZoneOffshore.php`:

```php
<?php

namespace App\Models\Shapefiles;

use Illuminate\Database\Eloquent\Model;

class MarineZoneOffshore extends Model
{
    protected $table = 'marine_zones_offshore';

    protected $guarded = [];
}
```

`app/Models/Shapefiles/MarineZoneHighSeas.php`:

```php
<?php

namespace App\Models\Shapefiles;

use Illuminate\Database\Eloquent\Model;

class MarineZoneHighSeas extends Model
{
    protected $table = 'marine_zones_high_seas';

    protected $guarded = [];
}
```

`app/Models/Shapefiles/FireZone.php`:

```php
<?php

namespace App\Models\Shapefiles;

use Illuminate\Database\Eloquent\Model;

class FireZone extends Model
{
    protected $table = 'fire_zones';

    protected $guarded = [];
}
```

`app/Models/Shapefiles/UsStateBoundary.php`:

```php
<?php

namespace App\Models\Shapefiles;

use Illuminate\Database\Eloquent\Model;

class UsStateBoundary extends Model
{
    protected $table = 'us_states';

    protected $guarded = [];
}
```

`app/Models/Shapefiles/ZoneCountyCorrelation.php`:

```php
<?php

namespace App\Models\Shapefiles;

use Illuminate\Database\Eloquent\Model;

class ZoneCountyCorrelation extends Model
{
    protected $table = 'zone_county_correlations';

    protected $guarded = [];
}
```

- [ ] **Step 2: Add `modelClass` to the definition value object**

In `app/Support/Shapefiles/ShapefileLayerDefinition.php`, add a new constructor parameter:

```php
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $sourceUrl,
        public readonly string $format,
        public readonly string $liveTable,
        public readonly string $stagingTable,
        public readonly array $naturalKeyColumns,
        public readonly array $attributeMap,
        public readonly string $modelClass,
        public readonly ?string $zoneType = null,
        public readonly ?string $groupMatchText = null,
    ) {}
```

(`$modelClass` inserted before the two nullable trailing parameters, matching named-argument construction already used in the registry — order relative to the nullable trailing params only matters for positional calls, and the registry uses named arguments throughout, so this is safe.)

- [ ] **Step 3: Pass `modelClass` for each of the 9 definitions**

In `app/Support/Shapefiles/ShapefileLayerDefinitions.php`, add a `modelClass: ...::class` argument to each of the 9 `new ShapefileLayerDefinition(...)` calls:

| key | `modelClass` |
|---|---|
| `public_zones` | `\App\Models\Shapefiles\PublicZone::class` |
| `counties` | `\App\Models\Shapefiles\CountyBoundary::class` |
| `cwa_boundaries` | `\App\Models\Shapefiles\CwaBoundary::class` |
| `marine_zones_coastal` | `\App\Models\Shapefiles\MarineZoneCoastal::class` |
| `marine_zones_offshore` | `\App\Models\Shapefiles\MarineZoneOffshore::class` |
| `marine_zones_high_seas` | `\App\Models\Shapefiles\MarineZoneHighSeas::class` |
| `fire_zones` | `\App\Models\Shapefiles\FireZone::class` |
| `us_states` | `\App\Models\Shapefiles\UsStateBoundary::class` |
| `zone_county_correlation` | `\App\Models\Shapefiles\ZoneCountyCorrelation::class` |

For example, the `public_zones` definition becomes:

```php
            new ShapefileLayerDefinition(
                key: 'public_zones',
                name: 'Public Zones',
                sourceUrl: 'https://www.weather.gov/gis/PublicZones',
                format: 'shapefile',
                liveTable: 'public_zones',
                stagingTable: 'public_zones_staging',
                naturalKeyColumns: ['state_zone'],
                attributeMap: [
                    'STATE' => 'state', 'CWA' => 'cwa', 'TIME_ZONE' => 'time_zone',
                    'FE_AREA' => 'fe_area', 'ZONE' => 'zone', 'NAME' => 'name',
                    'STATE_ZONE' => 'state_zone', 'LON' => 'lon', 'LAT' => 'lat',
                    'SHORTNAME' => 'shortname',
                ],
                modelClass: \App\Models\Shapefiles\PublicZone::class,
            ),
```

Apply the same `modelClass: ...` addition (as the last constructor argument, after `attributeMap` and, where present, before `zoneType`/`groupMatchText`) to all 9 definitions using the table above.

- [ ] **Step 4: Update the registry test and add a new one**

In `tests/Unit/ShapefileLayerDefinitionsTest.php`, add:

```php
test('every definition has a real, existing model class', function () {
    foreach (ShapefileLayerDefinitions::all() as $definition) {
        expect(class_exists($definition->modelClass))->toBeTrue("Missing model class for {$definition->key}: {$definition->modelClass}");
    }
});
```

- [ ] **Step 5: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/ShapefileLayerDefinitionsTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Shapefiles app/Support/Shapefiles/ShapefileLayerDefinition.php app/Support/Shapefiles/ShapefileLayerDefinitions.php tests/Unit/ShapefileLayerDefinitionsTest.php
git commit -m "Add a plain Eloquent model per geography table for admin table browsing

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 31: `ShapefileLayerRecords` page — browse live records with inline overrides

**Files:**
- Create: `app/Filament/Admin/Resources/ShapefileLayers/Pages/ShapefileLayerRecords.php`
- Create: `resources/views/filament/admin/pages/shapefile-layer-records.blade.php`
- Modify: `app/Filament/Admin/Resources/ShapefileLayers/ShapefileLayerResource.php`
- Modify: `app/Filament/Admin/Resources/ShapefileLayers/Pages/ViewShapefileLayer.php`
- Test: `tests/Feature/ShapefileLayerRecordsTest.php`

A custom resource sub-page bound to the `ShapefileLayer` record (via Filament's own `InteractsWithRecord` trait — the same one `ViewRecord`/`EditRecord` use internally), showing the layer's *current* live records (columns generated from the layer's `attributeMap`, never `boundary`/`lon`/`lat`/the natural key) with a row action to set a standing override. Applying an override both saves it (so it survives the next reimport, via `ApplyRecordOverrides` in `ActivateShapefileVersion`) and immediately reflects it in the live table.

- [ ] **Step 1: Write the failing test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefileLayerRecordsTest --no-interaction"
```

```php
<?php

use App\Filament\Admin\Resources\ShapefileLayers\Pages\ShapefileLayerRecords;
use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('it lists the layer\'s current live records', function () {
    DB::insert(
        "INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', 'ABQ', '201', 'Northwest Plateau', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);

    livewire(ShapefileLayerRecords::class, ['record' => $layer->getRouteKey()])
        ->assertSee('Northwest Plateau');
});

test('applying an override updates the live table and records the override for future reimports', function () {
    DB::insert(
        "INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', 'ABQ', '201', 'Northwest Plateau', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $record = App\Models\Shapefiles\PublicZone::where('state_zone', 'NM201')->firstOrFail();

    livewire(ShapefileLayerRecords::class, ['record' => $layer->getRouteKey()])
        ->callAction(TestAction::make('override')->table($record), data: [
            'field' => 'name',
            'value' => 'Admin-Corrected Name',
        ])
        ->assertNotified();

    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin-Corrected Name');

    $override = ShapefileRecordOverride::where('shapefile_layer_id', $layer->id)->first();
    expect($override->record_key)->toBe('NM201')
        ->and($override->field)->toBe('name')
        ->and($override->value)->toBe('Admin-Corrected Name');
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerRecordsTest.php --compact"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement the page**

```php
<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Pages;

use App\Actions\Shapefiles\ApplyRecordOverrides;
use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use App\Models\ShapefileRecordOverride;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ShapefileLayerRecords extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ShapefileLayerResource::class;

    protected string $view = 'filament.admin.pages.shapefile-layer-records';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function table(Table $table): Table
    {
        $definition = ShapefileLayerDefinitions::forKey($this->record->key);

        return $table
            ->query($definition->modelClass::query())
            ->columns($this->columnsFor($definition))
            ->recordActions([
                Action::make('override')
                    ->label('Override')
                    ->schema([
                        Select::make('field')
                            ->label('Field')
                            ->options($this->overridableFields($definition))
                            ->required(),
                        TextInput::make('value')
                            ->label('New value')
                            ->required(),
                    ])
                    ->action(function (array $data, Model $record) use ($definition): void {
                        $this->applyOverride($definition, $record, $data['field'], $data['value']);
                    }),
            ]);
    }

    /**
     * @return array<int, TextColumn>
     */
    private function columnsFor(ShapefileLayerDefinition $definition): array
    {
        return collect($definition->attributeMap)
            ->values()
            ->unique()
            ->map(fn (string $column): TextColumn => TextColumn::make($column)
                ->label(ucfirst(str_replace('_', ' ', $column)))
                ->searchable())
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function overridableFields(ShapefileLayerDefinition $definition): array
    {
        $excluded = [...$definition->naturalKeyColumns, 'lon', 'lat'];

        return collect($definition->attributeMap)
            ->values()
            ->unique()
            ->reject(fn (string $column): bool => in_array($column, $excluded, true))
            ->mapWithKeys(fn (string $column): array => [$column => ucfirst(str_replace('_', ' ', $column))])
            ->all();
    }

    private function applyOverride(ShapefileLayerDefinition $definition, Model $record, string $field, string $value): void
    {
        $recordKey = $definition->serializeNaturalKey(
            collect($definition->naturalKeyColumns)
                ->mapWithKeys(fn (string $column): array => [$column => $record->getAttribute($column)])
                ->all(),
        );

        ShapefileRecordOverride::updateOrCreate(
            ['shapefile_layer_id' => $this->record->id, 'record_key' => $recordKey, 'field' => $field],
            ['value' => $value, 'created_by' => auth()->id()],
        );

        app(ApplyRecordOverrides::class)($this->record, $definition);

        Notification::make()->title('Override applied')->success()->send();
    }
}
```

- [ ] **Step 4: The Blade view**

```blade
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
```

- [ ] **Step 5: Register the route and link to it from the View page**

In `app/Filament/Admin/Resources/ShapefileLayers/ShapefileLayerResource.php`, add the import and route:

```php
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ShapefileLayerRecords;
```

```php
    public static function getPages(): array
    {
        return [
            'index' => ListShapefileLayers::route('/'),
            'view' => ViewShapefileLayer::route('/{record}'),
            'records' => ShapefileLayerRecords::route('/{record}/records'),
        ];
    }
```

In `app/Filament/Admin/Resources/ShapefileLayers/Pages/ViewShapefileLayer.php`, add a link action alongside Activate/Dismiss/Rollback in `getHeaderActions()`:

```php
            Action::make('viewRecords')
                ->label('View records')
                ->url(fn (): string => static::getResource()::getUrl('records', ['record' => $this->record])),
```

- [ ] **Step 6: Run the tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerRecordsTest.php --compact"
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Admin/Resources/ShapefileLayers resources/views/filament/admin/pages/shapefile-layer-records.blade.php tests/Feature/ShapefileLayerRecordsTest.php
git commit -m "Add live-records browsing with inline attribute overrides

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 32: GeoJSON endpoint + read-only Leaflet boundary map

**Files:**
- Create: `app/Http/Controllers/Admin/ShapefileLayerGeoJsonController.php`
- Create: `app/Filament/Admin/Resources/ShapefileLayers/Pages/ShapefileLayerMap.php`
- Create: `resources/views/filament/admin/pages/shapefile-layer-map.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Filament/Admin/Resources/ShapefileLayers/ShapefileLayerResource.php`
- Modify: `app/Filament/Admin/Resources/ShapefileLayers/Pages/ViewShapefileLayer.php`
- Test: `tests/Feature/ShapefileLayerGeoJsonControllerTest.php`

Per the design correction recorded in the Context section, `fahiem/filament-pinpoint` cannot render polygons — this loads Leaflet 1.9.4 directly from the same CDN Pinpoint already uses (`unpkg.com/leaflet@1.9.4`), guarded by the same `leaflet-css`/`leaflet-js` element ids and the same `pinpoint:leaflet:loaded` event name so it cooperates rather than double-loading if a Pinpoint field is ever on the same page. Geometry is served as GeoJSON via plain `ST_AsGeoJSON` — no Magellan dependency needed for this read-only path.

**Bug found post-implementation (via real browser testing — the map genuinely never rendered) and fixed**: the original Blade view declared `function initMap() {...}` and `function loadLeaflet() {...}` directly inside the `x-init` attribute string. Confirmed directly in the browser console that Alpine/Livewire evaluate `x-init` expressions in a wrapped scope that does not reliably resolve bare identifiers — `ReferenceError: L is not defined` fired even though `window.L` was a genuine, fully-loaded Leaflet object at that exact moment, and `ReferenceError: initMap is not defined` fired from inside the script's own `onload` callback trying to call a function declared moments earlier in the same block. The exact same code ran correctly when re-executed as an ordinary top-level `<script>` — proving the bug was specifically about `x-init`'s expression-evaluation scoping, not the map logic itself. Fixed by moving all the real logic into a plain `<script>` tag defining `window.initShapefileLayerMap` as an ordinary global function, and reducing `x-init` to a single trivial call: `x-init="window.initShapefileLayerMap($el, @js(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $this->record->id])))"`. Verified in the browser afterward: the map renders and draws real boundary polygons (tested with Counties' 3,269 real records).

- [ ] **Step 1: Write the failing controller test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefileLayerGeoJsonControllerTest --no-interaction"
```

```php
<?php

use App\Models\ShapefileLayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('it returns a GeoJSON FeatureCollection for a shapefile-format layer', function () {
    DB::insert(
        "INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', 'ABQ', '201', 'Northwest Plateau', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $staff = User::factory()->create(['is_staff' => true]);

    $response = $this->actingAs($staff)
        ->getJson(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $layer]));

    $response->assertSuccessful()
        ->assertJsonPath('type', 'FeatureCollection')
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.geometry.type', 'MultiPolygon')
        ->assertJsonPath('features.0.properties.name', 'Northwest Plateau');
});

test('it returns an empty feature list for the non-geometry correlation layer', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'zone_county_correlation']);
    $staff = User::factory()->create(['is_staff' => true]);

    $response = $this->actingAs($staff)
        ->getJson(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $layer]));

    $response->assertSuccessful()->assertJsonCount(0, 'features');
});

test('a non-staff user is forbidden', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $subscriber = User::factory()->create(['is_staff' => false]);

    $this->actingAs($subscriber)
        ->getJson(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $layer]))
        ->assertForbidden();
});
```

- [ ] **Step 2: Run, confirm failure**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerGeoJsonControllerTest.php --compact"
```

Expected: FAIL — route `admin.shapefile-layers.geojson` not defined.

- [ ] **Step 3: Implement the controller**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ShapefileLayerGeoJsonController extends Controller
{
    public function __invoke(ShapefileLayer $shapefileLayer): JsonResponse
    {
        abort_unless(auth()->user()?->is_staff, 403);

        $definition = ShapefileLayerDefinitions::forKey($shapefileLayer->key);

        if (! $definition->isShapefile()) {
            return response()->json(['type' => 'FeatureCollection', 'features' => []]);
        }

        $columns = array_values(array_unique($definition->attributeMap));

        $rows = DB::table($definition->liveTable)
            ->select([...$columns, DB::raw('ST_AsGeoJSON(boundary) as geometry_json')])
            ->get();

        $features = $rows->map(function (object $row) use ($columns): array {
            $properties = [];

            foreach ($columns as $column) {
                $properties[$column] = $row->{$column} ?? null;
            }

            return [
                'type' => 'Feature',
                'geometry' => json_decode((string) $row->geometry_json, true),
                'properties' => $properties,
            ];
        })->values();

        return response()->json(['type' => 'FeatureCollection', 'features' => $features]);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, add:

```php
use App\Http\Controllers\Admin\ShapefileLayerGeoJsonController;

Route::middleware(['auth'])->get(
    '/admin/shapefile-layers/{shapefileLayer}/geojson',
    ShapefileLayerGeoJsonController::class,
)->name('admin.shapefile-layers.geojson');
```

- [ ] **Step 5: Run the controller tests**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefileLayerGeoJsonControllerTest.php --compact"
```

Expected: PASS.

- [ ] **Step 6: Add the map page**

```php
<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Pages;

use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class ShapefileLayerMap extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ShapefileLayerResource::class;

    protected string $view = 'filament.admin.pages.shapefile-layer-map';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }
}
```

- [ ] **Step 7: The Blade view with the Leaflet map**

```blade
<x-filament-panels::page>
    <div
        id="shapefile-layer-map"
        wire:ignore
        style="height: 600px;"
        x-data="{}"
        x-init="
            function initMap() {
                const map = L.map($el).setView([39.8283, -98.5795], 4);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(map);
                fetch(@js(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $this->record->id])))
                    .then((response) => response.json())
                    .then((geojson) => {
                        const boundaryLayer = L.geoJSON(geojson).addTo(map);
                        if (geojson.features.length > 0) {
                            map.fitBounds(boundaryLayer.getBounds());
                        }
                    });
            }

            function loadLeaflet() {
                if (window.L) { initMap(); return; }

                if (window.leafletLoading) {
                    document.addEventListener('pinpoint:leaflet:loaded', initMap, { once: true });
                    return;
                }

                window.leafletLoading = true;

                if (!document.getElementById('leaflet-css')) {
                    const link = document.createElement('link');
                    link.id = 'leaflet-css';
                    link.rel = 'stylesheet';
                    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(link);
                }

                const script = document.createElement('script');
                script.id = 'leaflet-js';
                script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                script.onload = () => {
                    window.leafletLoading = false;
                    document.dispatchEvent(new CustomEvent('pinpoint:leaflet:loaded'));
                    initMap();
                };
                document.head.appendChild(script);
            }

            loadLeaflet();
        "
    ></div>
</x-filament-panels::page>
```

- [ ] **Step 8: Register the route and link to it from the View page**

In `app/Filament/Admin/Resources/ShapefileLayers/ShapefileLayerResource.php`:

```php
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ShapefileLayerMap;
```

```php
    public static function getPages(): array
    {
        return [
            'index' => ListShapefileLayers::route('/'),
            'view' => ViewShapefileLayer::route('/{record}'),
            'records' => ShapefileLayerRecords::route('/{record}/records'),
            'map' => ShapefileLayerMap::route('/{record}/map'),
        ];
    }
```

In `app/Filament/Admin/Resources/ShapefileLayers/Pages/ViewShapefileLayer.php`, add alongside the `viewRecords` header action:

```php
            Action::make('viewMap')
                ->label('View map')
                ->url(fn (): string => static::getResource()::getUrl('map', ['record' => $this->record])),
```

- [ ] **Step 9: Manual browser verification**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'App\Models\User::where(\"is_staff\", true)->first()?->id ?? App\Models\User::factory()->create([\"is_staff\" => true])->id;'"
```

Log in as that staff user, activate the `us_states` layer (Task 25's test data or a real scan/import), then visit its "View map" page and confirm a Leaflet map renders with at least one boundary polygon drawn, no console errors, and the map auto-fits to the boundary.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Admin/ShapefileLayerGeoJsonController.php app/Filament/Admin/Resources/ShapefileLayers routes/web.php resources/views/filament/admin/pages/shapefile-layer-map.blade.php tests/Feature/ShapefileLayerGeoJsonControllerTest.php
git commit -m "Add the GeoJSON endpoint and read-only Leaflet boundary map

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 33: Full-pipeline integration test + final verification

**Files:**
- Test: `tests/Feature/ShapefilePipelineIntegrationTest.php`

Exercises the whole chain end-to-end against real fixture data: scan detects a new version → import populates staging and computes the diff → staff gets notified → admin activates → the live table reflects the import → a standing override survives a second full cycle.

- [ ] **Step 1: Write the test**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest ShapefilePipelineIntegrationTest --no-interaction"
```

```php
<?php

use App\Actions\Shapefiles\ActivateShapefileVersion;
use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Actions\Shapefiles\ScanShapefileLayer;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use App\Notifications\ShapefileLayerUpdateAvailableNotification;
use Database\Seeders\ShapefileLayerSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeRecord;
use PhpMyAdmin\ShapeFile\ShapeType;

function buildPipelineFixtureZip(string $basename, string $zoneName): string
{
    $dir = storage_path('app/test-fixtures/'.$basename);
    File::ensureDirectoryExists($dir);
    $base = $dir.'/'.$basename;

    $shp = new ShapeFile(ShapeType::Polygon, ['xmin' => 0, 'ymin' => 0, 'xmax' => 10, 'ymax' => 10]);

    $record = new ShapeRecord(ShapeType::Polygon);
    foreach ([[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]] as [$x, $y]) {
        $record->addPoint(['x' => $x, 'y' => $y], 0);
    }
    $shp->addRecord($record);

    $shp->setDBFHeader([
        ['STATE', 'C', 2, 0], ['CWA', 'C', 9, 0], ['TIME_ZONE', 'C', 2, 0],
        ['FE_AREA', 'C', 2, 0], ['ZONE', 'C', 3, 0], ['NAME', 'C', 254, 0],
        ['STATE_ZONE', 'C', 5, 0], ['LON', 'N', 12, 7], ['LAT', 'N', 12, 7],
        ['SHORTNAME', 'C', 32, 0],
    ]);

    $shp->records[0]->dbfData = [
        'STATE' => 'NM', 'CWA' => 'ABQ', 'TIME_ZONE' => 'M', 'FE_AREA' => 'nw',
        'ZONE' => '201', 'NAME' => $zoneName, 'STATE_ZONE' => 'NM201',
        'LON' => -108.5, 'LAT' => 36.4, 'SHORTNAME' => 'Zone One',
    ];

    $shp->saveToFile($base.'.*');

    $zipPath = $dir.'/'.$basename.'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach (['shp', 'shx', 'dbf'] as $ext) {
        $zip->addFile($base.'.'.$ext, $basename.'.'.$ext);
    }
    $zip->close();

    return $zipPath;
}

test('the full scan-import-notify-activate cycle works, and an override survives a second cycle', function () {
    Notification::fake();
    (new ShapefileLayerSeeder)->run();
    $staff = User::factory()->create(['is_staff' => true]);
    $admin = User::factory()->create(['is_staff' => true]);

    // Cycle 1: NOAA publishes a version, it gets scanned, imported, and activated.
    $zipOne = buildPipelineFixtureZip('pipeline_v1', 'Northwest Plateau');
    $pageOne = '<table><tbody><tr>'
        .'<td colspan="1" rowspan="1">Public Forecast Zones</td>'
        .'<td>18 March 2025</td>'
        .'<td><a href="/source/gis/Shapefiles/WSOM/pipeline_v1.zip">pipeline_v1.zip</a></td>'
        .'<td>1</td>'
        .'<td>'.md5('v1').'<br>Date last upload: 1-JAN-25</td>'
        .'</tr></tbody></table>';

    Http::fake([
        'www.weather.gov/gis/PublicZones' => Http::response($pageOne),
        'www.weather.gov/source/gis/Shapefiles/WSOM/pipeline_v1.zip' => Http::response(file_get_contents($zipOne)),
    ]);

    $layer = ShapefileLayer::where('key', 'public_zones')->firstOrFail();
    $version = (new ScanShapefileLayer)($layer);
    expect($version)->not->toBeNull();

    (new ImportShapefileVersion)($version);
    expect(DB::table('public_zones_staging')->where('state_zone', 'NM201')->value('name'))->toBe('Northwest Plateau');

    Notification::assertSentTo($staff, ShapefileLayerUpdateAvailableNotification::class);

    (new ActivateShapefileVersion)($version);
    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Northwest Plateau')
        ->and($version->fresh()->status)->toBe(ShapefileVersionStatus::Active);

    // Admin corrects the name via a standing override.
    ShapefileRecordOverride::create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Admin-Corrected Name',
        'created_by' => $admin->id,
    ]);
    app(App\Actions\Shapefiles\ApplyRecordOverrides::class)($layer, App\Support\Shapefiles\ShapefileLayerDefinitions::forKey('public_zones'));
    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin-Corrected Name');

    // Cycle 2: NOAA publishes ANOTHER new version with yet another raw name.
    // The diff must flag the conflict with the standing override, and activation
    // must reapply the override on top of the freshly-swapped-in NOAA data.
    $zipTwo = buildPipelineFixtureZip('pipeline_v2', 'NOAA Renamed It Again');
    $pageTwo = '<table><tbody><tr>'
        .'<td colspan="1" rowspan="1">Public Forecast Zones</td>'
        .'<td>16 April 2026</td>'
        .'<td><a href="/source/gis/Shapefiles/WSOM/pipeline_v2.zip">pipeline_v2.zip</a></td>'
        .'<td>1</td>'
        .'<td>'.md5('v2').'<br>Date last upload: 1-MAR-26</td>'
        .'</tr></tbody></table>';

    Http::fake([
        'www.weather.gov/gis/PublicZones' => Http::response($pageTwo),
        'www.weather.gov/source/gis/Shapefiles/WSOM/pipeline_v2.zip' => Http::response(file_get_contents($zipTwo)),
    ]);

    $versionTwo = (new ScanShapefileLayer)($layer->fresh());
    expect($versionTwo)->not->toBeNull();

    (new ImportShapefileVersion)($versionTwo);
    expect($versionTwo->fresh()->diff_summary['override_conflicts'])->toBe(['NM201']);

    (new ActivateShapefileVersion)($versionTwo);
    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin-Corrected Name');
});
```

- [ ] **Step 2: Run it**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ShapefilePipelineIntegrationTest.php --compact"
```

Expected: PASS. If the first `ScanShapefileLayer` call returns `null`, check that the fixture page's single-row markup still parses as one group under `ShapefilePageScraper`'s `>= 5 cells` rule — this fixture intentionally uses only 5 `<td>`s (no Change History/Metadata columns) since those are irrelevant here; if that breaks, add two trailing empty `<td></td>` cells to reach the shape `ShapefilePageScraper` expects, matching a real minimal single-version page (no continuation row) rather than one with a rowspan pair.

- [ ] **Step 3: Run the entire suite and Pint**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
```

Expected: full suite passes, Pint clean.

- [ ] **Step 4: Verify the schedule and routes one more time**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan schedule:list && php artisan route:list --path=admin/shapefile-layers"
```

Expected: `shapefile:scan` listed as daily; `index`/`view`/`records`/`map` routes all present under `/admin/shapefile-layers`.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/ShapefilePipelineIntegrationTest.php
git commit -m "Add a full scan-import-activate integration test covering override conflicts

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Plan self-review notes

- **Spec coverage**: every section of `docs/superpowers/specs/2026-09-07-awips-shapefile-management-design.md` maps to a task — PostGIS adoption (Tasks 1–2), the 3 tracking tables (Tasks 3–5), the 9-layer registry (Task 6), all 7 geography table pairs including Marine's corrected 3-way split (Tasks 7–13), seeding (Task 14), scraping for both page formats (Tasks 15–16), the scan/schedule loop (Tasks 17–18), correct hole-aware geometry assembly (Task 19), both import format branches (Tasks 20–21), diff + override-conflict detection (Task 22), staff notification (Task 23), override application (Task 24), activate/rollback/dismiss (Tasks 25–27), and the full admin UI — list, diff view with actions, records browsing with inline overrides, and the boundary map (Tasks 28–32). Task 33 proves the whole chain together, including the specific override-vs-reimport-conflict scenario the design called out as a hard requirement.
- **A genuine design bug was caught and fixed during planning, not after**: Marine's original "one shared table with a `zone_type` column" sketch would have broken the atomic-swap model the moment two Marine sub-layers activated independently. Resolved by giving each of the 3 sub-layers its own table pair (Task 10) before any code was written against the old shape — every downstream task (registry, import, diff, activate) reflects the corrected design.
- **Placeholder scan**: no task defers real content to "later" — every step that touches code shows the actual code; every test asserts concrete values (not `assertTrue(true)`-shaped stand-ins).
- **Type/name consistency check**: `ShapefileLayerDefinition`'s constructor parameter order, `ShapefileLayerDefinitions::forKey()`, `serializeNaturalKey()`, `ApplyRecordOverrides`, `ComputeShapefileLayerDiff`, and `ShapefileVersionStatus`'s 4 cases are used identically (same method names, same argument shapes) everywhere they're referenced across Tasks 6 through 33.

