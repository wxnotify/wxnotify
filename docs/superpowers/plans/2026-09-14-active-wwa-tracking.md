# CAP Ingestion & Active WWA Tracking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Parse the CAP (Common Alerting Protocol) XML alert NWS already broadcasts alongside every VTEC-class Watch/Warning/Advisory text product, and safely fold it into live alert dispatch via a new "Active WWA tracking" table that de-duplicates a text issuance from its paired CAP payload without regressing continuation-statement delivery (SVS/FFS/etc.).

**Architecture:** Two new nullable-column groups on `ingested_products` (shared VTEC identity + CAP-only fields), a new `active_wwas` table (one row per logical VTEC event, PostGIS polygon column), and a `SyncActiveWwa` action that `IngestedProductObserver` routes VTEC-class rows through instead of dispatching directly — text rows always upsert-and-dispatch (preserving today's behavior exactly), CAP rows enrich the same event row without re-dispatching unless their paired text row is missing.

**Tech Stack:** Laravel 13, Filament v5, PHP 8.4, Pest, PostgreSQL/PostGIS.

**Design doc:** `docs/superpowers/specs/2026-09-14-active-wwa-tracking-design.md` — read it first for the full rationale, especially the real CAP payload structure (verified against live NWWS traffic) and why this is scoped to structured fields only, not the narrative hazard/source/impact bullet-splitter Custom Alert Templates will eventually need.

**Scope note:** `nwws-go-client` (the Go NWWS client) needs zero changes — confirmed directly against its source that it has no product-type filtering anywhere and already ships CAP messages through its existing pipeline unconditionally. This plan is 100% PHP-side.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/..._add_vtec_and_cap_metadata_to_ingested_products_table.php` | New columns: shared VTEC identity + CAP-only fields |
| `database/migrations/..._create_active_wwas_table.php` | New table: one row per logical VTEC event |
| `app/Models/ActiveWwa.php` | New model |
| `database/factories/ActiveWwaFactory.php` | Test factory |
| `app/Models/IngestedProduct.php` | Modify: Fillable + casts for new columns |
| `app/Actions/Pipeline/ParseVtecString.php` | New: parses a VTEC string into its components — shared by text and CAP extraction |
| `app/Actions/Pipeline/ExtractProductMetadata.php` | Modify: also populate the shared VTEC columns via `ParseVtecString` |
| `app/Actions/Pipeline/ExtractCapMetadata.php` | New: parses a CAP XML product body into everything `ingested_products` needs |
| `app/Http/Controllers/IngestNwwsProductController.php` | Modify: detect CAP via `awipsid` prefix, run the right extractor |
| `app/Actions/Pipeline/SyncActiveWwa.php` | New: upserts `active_wwas`, decides whether to (re-)trigger matching |
| `app/Observers/IngestedProductObserver.php` | Modify: route VTEC-class rows to `SyncActiveWwa`, everything else unchanged |
| `app/Actions/Weather/MatchWeatherAlertRules.php` | Modify: Polygon-mode matching consults `active_wwas`'s persisted polygon when available |
| `app/Jobs/DeliverWeatherAlert.php` | Modify: CAP-sourced rows use `description` as body content instead of raw XML `text` |

---

## Task 1: Schema — VTEC and CAP metadata columns on `ingested_products`

**Files:**
- Create: `database/migrations/2026_09_14_050000_add_vtec_and_cap_metadata_to_ingested_products_table.php`
- Modify: `app/Models/IngestedProduct.php`

No failing-test step for this task — it's schema and model plumbing only, verified via a real migration run and a Tinker round-trip, matching this codebase's own convention for schema-only tasks (see `docs/superpowers/plans/2026-09-08-weather-alert-engine.md` Task 1).

- [x] **Step 1: Create the migration**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration add_vtec_and_cap_metadata_to_ingested_products_table --no-interaction"
```

Rename the generated file to exactly `database/migrations/2026_09_14_050000_add_vtec_and_cap_metadata_to_ingested_products_table.php` (Artisan timestamps it with the real current time — this plan needs an exact, ordered timestamp), then set its content to:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            // Shared VTEC identity -- populated for any VTEC-class row,
            // whether it arrived as text or CAP. vtec_office is deliberately
            // separate from the existing `cccc` column: `cccc` is KWBC (the
            // national gateway) for every CAP row, never the originating WFO,
            // so it can't be relied on for VTEC office identity.
            $table->string('vtec_office')->nullable()->after('risk_levels');
            $table->string('vtec_phenomenon')->nullable()->after('vtec_office');
            $table->string('vtec_significance')->nullable()->after('vtec_phenomenon');
            $table->string('vtec_event_number', 4)->nullable()->after('vtec_significance');
            $table->unsignedSmallInteger('vtec_year')->nullable()->after('vtec_event_number');
            $table->string('vtec_action')->nullable()->after('vtec_year');
            $table->timestamp('vtec_begin')->nullable()->after('vtec_action');
            $table->timestamp('vtec_end')->nullable()->after('vtec_begin');

            // CAP-only fields, null on every text row.
            $table->string('format')->default('text')->after('vtec_end');
            $table->string('msg_type')->nullable()->after('format');
            $table->string('event')->nullable()->after('msg_type');
            $table->string('severity')->nullable()->after('event');
            $table->string('urgency')->nullable()->after('severity');
            $table->string('certainty')->nullable()->after('urgency');
            $table->text('headline')->nullable()->after('certainty');
            $table->text('description')->nullable()->after('headline');
            $table->text('instruction')->nullable()->after('description');
            $table->string('event_motion_description')->nullable()->after('instruction');

            // Correlation: the specific paired text issuance this CAP
            // payload cross-references, via CAP's own AWIPSidentifier and
            // WMOidentifier parameters.
            $table->string('awips_identifier')->nullable()->after('event_motion_description');
            $table->string('wmo_identifier')->nullable()->after('awips_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropColumn([
                'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number',
                'vtec_year', 'vtec_action', 'vtec_begin', 'vtec_end',
                'format', 'msg_type', 'event', 'severity', 'urgency', 'certainty',
                'headline', 'description', 'instruction', 'event_motion_description',
                'awips_identifier', 'wmo_identifier',
            ]);
        });
    }
};
```

- [x] **Step 2: Update the `IngestedProduct` model**

In `app/Models/IngestedProduct.php`, change the `#[Fillable]` attribute from:

```php
#[Fillable(['cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'received_at', 'product_id', 'ugc_codes', 'polygon', 'risk_levels'])]
```

to:

```php
#[Fillable([
    'cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'received_at',
    'product_id', 'ugc_codes', 'polygon', 'risk_levels',
    'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'vtec_action', 'vtec_begin', 'vtec_end',
    'format', 'msg_type', 'event', 'severity', 'urgency', 'certainty',
    'headline', 'description', 'instruction', 'event_motion_description',
    'awips_identifier', 'wmo_identifier',
])]
```

Change `casts()` from:

```php
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'ugc_codes' => 'array',
            'polygon' => 'array',
            'risk_levels' => 'array',
        ];
    }
```

to:

```php
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'ugc_codes' => 'array',
            'polygon' => 'array',
            'risk_levels' => 'array',
            'vtec_begin' => 'datetime',
            'vtec_end' => 'datetime',
        ];
    }
```

- [x] **Step 3: Run the migration and verify with Tinker**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

Expected: runs with no errors.

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute='
\$p = App\Models\IngestedProduct::factory()->create([
    \"vtec_office\" => \"KOAX\", \"vtec_phenomenon\" => \"TO\", \"vtec_significance\" => \"W\",
    \"vtec_event_number\" => \"0055\", \"vtec_year\" => 2026, \"format\" => \"cap\", \"event\" => \"Tornado Warning\",
]);
echo \$p->vtec_office . \" \" . \$p->format . \" \" . \$p->event . PHP_EOL;
\$default = App\Models\IngestedProduct::factory()->create();
echo \$default->format . PHP_EOL;
'"
```

Expected: prints `KOAX cap Tornado Warning` then `text` (confirms the `format` default applies when not explicitly set).

- [x] **Step 4: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations/2026_09_14_050000_add_vtec_and_cap_metadata_to_ingested_products_table.php app/Models/IngestedProduct.php
git commit -m "$(cat <<'EOF'
Add VTEC and CAP metadata columns to ingested_products

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Schema — `active_wwas` table, model, factory

**Files:**
- Create: `database/migrations/2026_09_14_050001_create_active_wwas_table.php`
- Create: `app/Models/ActiveWwa.php`
- Create: `database/factories/ActiveWwaFactory.php`

No failing-test step — schema and model plumbing, verified via migration + Tinker.

- [x] **Step 1: Create the migration**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_active_wwas_table --no-interaction"
```

Rename to exactly `database/migrations/2026_09_14_050001_create_active_wwas_table.php`, content:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_wwas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Composite VTEC key -- this is the persistent event identity.
            $table->string('vtec_office');
            $table->string('vtec_phenomenon');
            $table->string('vtec_significance');
            $table->string('vtec_event_number', 4);
            $table->unsignedSmallInteger('vtec_year');

            $table->string('status')->default('Active'); // Active | Cancelled | Expired
            $table->geometry('polygon', 'POLYGON', 4269)->nullable();
            $table->json('ugc_codes')->nullable();
            $table->timestamp('effective')->nullable();
            $table->timestamp('expires')->nullable();
            $table->text('headline')->nullable();

            // Parsed from CAP's eventMotionDescription -- null until a CAP
            // payload enriches this row (no motion vector is ever parsed
            // from raw text).
            $table->unsignedSmallInteger('motion_direction_degrees')->nullable();
            $table->unsignedSmallInteger('motion_speed_kt')->nullable();
            $table->decimal('motion_reference_lat', 8, 5)->nullable();
            $table->decimal('motion_reference_lon', 8, 5)->nullable();
            $table->timestamp('motion_observed_at')->nullable();

            $table->string('source')->nullable(); // text | cap -- which extraction currently backs the fields above
            $table->foreignId('latest_ingested_product_id')->nullable()->constrained('ingested_products')->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year'],
                'active_wwas_vtec_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_wwas');
    }
};
```

- [x] **Step 2: Create the model**

```php
<?php

namespace App\Models;

use Database\Factories\ActiveWwaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'status', 'ugc_codes', 'effective', 'expires', 'headline',
    'motion_direction_degrees', 'motion_speed_kt', 'motion_reference_lat', 'motion_reference_lon', 'motion_observed_at',
    'source', 'latest_ingested_product_id',
])]
class ActiveWwa extends Model
{
    /** @use HasFactory<ActiveWwaFactory> */
    use HasFactory;

    /**
     * `polygon` is deliberately not fillable or cast here -- it's a native
     * PostGIS geometry column, set/read via raw SQL (see SyncActiveWwa and
     * MatchWeatherAlertRules), never materialized into a PHP value.
     */
    protected function casts(): array
    {
        return [
            'ugc_codes' => 'array',
            'effective' => 'datetime',
            'expires' => 'datetime',
            'motion_observed_at' => 'datetime',
            'motion_reference_lat' => 'float',
            'motion_reference_lon' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function latestIngestedProduct(): BelongsTo
    {
        return $this->belongsTo(IngestedProduct::class, 'latest_ingested_product_id');
    }
}
```

- [x] **Step 3: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\ActiveWwa;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActiveWwa>
 */
class ActiveWwaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'vtec_office' => 'K'.strtoupper(fake()->lexify('???')),
            'vtec_phenomenon' => strtoupper(fake()->lexify('??')),
            'vtec_significance' => strtoupper(fake()->lexify('?')),
            'vtec_event_number' => str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'vtec_year' => 2026,
            'status' => 'Active',
            'effective' => now(),
            'expires' => now()->addHour(),
            'source' => 'text',
        ];
    }
}
```

- [x] **Step 4: Run the migration and verify with Tinker**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

Expected: runs with no errors (confirms PostGIS is available for the `geometry()` column — it already is, per the AWIPS Shapefile migrations using the same method).

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute='
\$w = App\Models\ActiveWwa::factory()->create();
echo \$w->vtec_office . \" \" . \$w->status . PHP_EOL;
DB::statement(\"UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?\", [\"POLYGON((-96 41, -96 42, -95 42, -95 41, -96 41))\", \$w->id]);
\$contains = DB::selectOne(\"SELECT ST_Contains(polygon, ST_SetSRID(ST_MakePoint(-95.5, 41.5), 4269)) AS contains FROM active_wwas WHERE id = ?\", [\$w->id]);
echo var_export(\$contains->contains, true) . PHP_EOL;
'"
```

Expected: prints the office+status line, then `true` (confirms the geometry column round-trips through raw SQL correctly).

- [x] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations/2026_09_14_050001_create_active_wwas_table.php app/Models/ActiveWwa.php database/factories/ActiveWwaFactory.php
git commit -m "$(cat <<'EOF'
Add active_wwas table, model, and factory

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `ParseVtecString` — shared VTEC-string parser

**Files:**
- Create: `app/Actions/Pipeline/ParseVtecString.php`
- Test: `tests/Feature/ParseVtecStringTest.php`

The VTEC format is `/k.aaa.cccc.pp.s.####.YYMMDDTHHNNZ-YYMMDDTHHNNZ/` (product class, action, office, phenomenon, significance, event number, begin, end). This is used identically whether the string was found via regex in raw text or read from CAP's `VTEC` parameter — one parser, two callers.

- [x] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Pipeline\ParseVtecString;
use Illuminate\Support\Carbon;

test('parses every component of a NEW VTEC string with a real begin time', function () {
    $result = (new ParseVtecString)('/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/');

    expect($result)->toBe([
        'office' => 'KOKX',
        'phenomenon' => 'TO',
        'significance' => 'W',
        'event_number' => '0042',
        'year' => 2026,
        'action' => 'NEW',
        'begin' => Carbon::parse('2026-09-06T18:00:00Z'),
        'end' => Carbon::parse('2026-09-06T19:00:00Z'),
    ]);
});

test('an all-zero begin time (already in effect) parses to a null begin, not epoch zero', function () {
    $result = (new ParseVtecString)('/O.CAN.KOAX.SV.W.0413.000000T0000Z-260915T0015Z/');

    expect($result['begin'])->toBeNull()
        ->and($result['end'])->toEqual(Carbon::parse('2026-09-15T00:15:00Z'))
        ->and($result['action'])->toBe('CAN');
});

test('the year is derived from the end timestamp, not hardcoded or guessed', function () {
    $result = (new ParseVtecString)('/O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/');

    expect($result['year'])->toBe(2026);
});

test('returns null when the text contains no VTEC string at all', function () {
    $result = (new ParseVtecString)('AREA FORECAST DISCUSSION with no VTEC line here.');

    expect($result)->toBeNull();
});

test('finds the VTEC string embedded among other text', function () {
    $text = "WFUS51 KOKX 061800\nTORKOKX\n\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\nTornado Warning text follows.";

    $result = (new ParseVtecString)($text);

    expect($result['office'])->toBe('KOKX')
        ->and($result['event_number'])->toBe('0042');
});
```

- [x] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ParseVtecStringTest.php --compact"
```

Expected: FAIL — the class doesn't exist.

- [x] **Step 3: Implement the action**

```php
<?php

namespace App\Actions\Pipeline;

use Illuminate\Support\Carbon;

/**
 * Parses a P-VTEC string (/k.aaa.cccc.pp.s.####.YYMMDDTHHNNZ-YYMMDDTHHNNZ/)
 * into its components. Used identically whether the string came from a
 * regex match against raw bulletin text or from CAP's own VTEC parameter --
 * the format is the same either way.
 */
class ParseVtecString
{
    private const PATTERN = '/\/[A-Z]\.([A-Z]{3})\.([A-Z]{4})\.([A-Z]{2})\.([A-Z])\.(\d{4})\.(\d{6}T\d{4}Z)-(\d{6}T\d{4}Z)\//';

    /**
     * @return ?array{office: string, phenomenon: string, significance: string, event_number: string, year: int, action: string, begin: ?Carbon, end: Carbon}
     */
    public function __invoke(string $text): ?array
    {
        if (! preg_match(self::PATTERN, $text, $matches)) {
            return null;
        }

        [, $action, $office, $phenomenon, $significance, $eventNumber, $beginRaw, $endRaw] = $matches;

        $end = Carbon::createFromFormat('ymd\THi\Z', $endRaw, 'UTC');

        return [
            'office' => $office,
            'phenomenon' => $phenomenon,
            'significance' => $significance,
            'event_number' => $eventNumber,
            'year' => $end->year,
            'action' => $action,
            'begin' => str_starts_with($beginRaw, '000000T0000') ? null : Carbon::createFromFormat('ymd\THi\Z', $beginRaw, 'UTC'),
            'end' => $end,
        ];
    }
}
```

- [x] **Step 4: Run the test to verify it passes**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ParseVtecStringTest.php --compact"
```

Expected: PASS (5 tests).

- [x] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Pipeline/ParseVtecString.php tests/Feature/ParseVtecStringTest.php
git commit -m "$(cat <<'EOF'
Add ParseVtecString action

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Wire `ParseVtecString` into `ExtractProductMetadata`

**Files:**
- Modify: `app/Actions/Pipeline/ExtractProductMetadata.php`
- Test: `tests/Feature/ExtractProductMetadataTest.php`

- [x] **Step 1: Write the failing tests**

Add to `tests/Feature/ExtractProductMetadataTest.php` (add `use App\Actions\Pipeline\ParseVtecString;` if referenced directly — not needed here, the test only asserts `ExtractProductMetadata`'s output):

```php
test('a VTEC-tagged product also populates the shared vtec_* fields', function () {
    $text = <<<'TEXT'
    WFUS51 KOKX 061800
    TORKOKX

    /O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, 'torokx');

    expect($metadata['vtec_office'])->toBe('KOKX')
        ->and($metadata['vtec_phenomenon'])->toBe('TO')
        ->and($metadata['vtec_significance'])->toBe('W')
        ->and($metadata['vtec_event_number'])->toBe('0042')
        ->and($metadata['vtec_year'])->toBe(2026)
        ->and($metadata['vtec_action'])->toBe('NEW')
        ->and($metadata['vtec_begin'])->not->toBeNull()
        ->and($metadata['vtec_end'])->not->toBeNull();
});

test('a product with no VTEC line leaves every vtec_* field null', function () {
    $metadata = (new ExtractProductMetadata)("AREA FORECAST DISCUSSION\nNo VTEC line here.", 'afdokx');

    expect($metadata['vtec_office'])->toBeNull()
        ->and($metadata['vtec_phenomenon'])->toBeNull()
        ->and($metadata['vtec_event_number'])->toBeNull()
        ->and($metadata['vtec_year'])->toBeNull()
        ->and($metadata['vtec_action'])->toBeNull()
        ->and($metadata['vtec_begin'])->toBeNull()
        ->and($metadata['vtec_end'])->toBeNull();
});

test('an all-zero VTEC begin time resolves to a null vtec_begin', function () {
    $text = '/O.CAN.KOAX.SV.W.0413.000000T0000Z-260915T0015Z/';

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['vtec_begin'])->toBeNull()
        ->and($metadata['vtec_action'])->toBe('CAN');
});
```

- [x] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php --compact"
```

Expected: the 3 new tests FAIL (the keys don't exist in the returned array yet); all pre-existing tests still PASS.

- [x] **Step 3: Wire `ParseVtecString` into `ExtractProductMetadata`**

In `app/Actions/Pipeline/ExtractProductMetadata.php`, add the import:

```php
use App\Actions\Pipeline\ParseVtecString;
```

Change the `__invoke` method from:

```php
    public function __invoke(string $text, ?string $awipsid): array
    {
        [$productId, $code] = $this->resolveProduct($text, $awipsid);

        return [
            'product_id' => $productId,
            'ugc_codes' => $this->parseUgcCodes($text),
            'polygon' => $this->parsePolygon($text),
            'risk_levels' => $code === self::CONVECTIVE_OUTLOOK_CODE ? $this->extractConvectiveOutlookRiskLevels($text) : null,
        ];
    }
```

to:

```php
    public function __invoke(string $text, ?string $awipsid): array
    {
        [$productId, $code] = $this->resolveProduct($text, $awipsid);
        $vtec = (new ParseVtecString)($text);

        return [
            'product_id' => $productId,
            'ugc_codes' => $this->parseUgcCodes($text),
            'polygon' => $this->parsePolygon($text),
            'risk_levels' => $code === self::CONVECTIVE_OUTLOOK_CODE ? $this->extractConvectiveOutlookRiskLevels($text) : null,
            'vtec_office' => $vtec['office'] ?? null,
            'vtec_phenomenon' => $vtec['phenomenon'] ?? null,
            'vtec_significance' => $vtec['significance'] ?? null,
            'vtec_event_number' => $vtec['event_number'] ?? null,
            'vtec_year' => $vtec['year'] ?? null,
            'vtec_action' => $vtec['action'] ?? null,
            'vtec_begin' => $vtec['begin'] ?? null,
            'vtec_end' => $vtec['end'] ?? null,
        ];
    }
```

Also update the class-level `@return` PHPDoc above `__invoke` to include the new keys:

```php
    /**
     * @return array{product_id: ?int, ugc_codes: ?array<int, string>, polygon: ?array<int, array{lat: float, lon: float}>, risk_levels: ?array<int, string>, vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int, vtec_action: ?string, vtec_begin: ?\Illuminate\Support\Carbon, vtec_end: ?\Illuminate\Support\Carbon}
     */
```

- [x] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php --compact"
```

Expected: PASS, every test in the file (22 pre-existing + 3 new = 25).

- [x] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Pipeline/ExtractProductMetadata.php tests/Feature/ExtractProductMetadataTest.php
git commit -m "$(cat <<'EOF'
Populate shared VTEC fields in ExtractProductMetadata

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `ExtractCapMetadata` — CAP XML parser

**Files:**
- Create: `app/Actions/Pipeline/ExtractCapMetadata.php`
- Test: `tests/Feature/ExtractCapMetadataTest.php`

The archived product text for a CAP message includes the same WMO-style preamble every product has (a byte-count line, the WMO header, the AWIPS id) *before* the actual `<?xml ...?><alert>...` document — confirmed directly against a real captured NWWS session. This parser locates the XML portion first, then parses it.

- [x] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\Pipeline\ExtractCapMetadata;
use App\Models\Product;

/**
 * A real Flash Flood Warning CAP payload, trimmed of unrelated BLOCKCHANNEL/
 * EAS-ORG parameters but otherwise verbatim from a captured live NWWS
 * session (KTWC issuing FFW). Includes the classic HAZARD/SOURCE/IMPACT
 * bullet text inside <description> -- CAP does not split these out, it
 * just wraps the same narrative prose in XML (see the design doc).
 */
function realCapAlertBody(): string
{
    return <<<'TEXT'
    480

    XOUS55 KWBC 142354

    CAPTWC

    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
        <identifier>urn:oid:2.49.0.1.840.0.01f300b1d26e1daada39c43e6a411499aacab5db.001.1</identifier>
        <sender>w-nws.webmaster@noaa.gov</sender>
        <sent>2026-09-14T16:54:00-07:00</sent>
        <status>Actual</status>
        <msgType>Alert</msgType>
        <source>211803 v2.3.0</source>
        <scope>Public</scope>
        <code>IPAWSv1.0</code>
        <info>
            <language>en-US</language>
            <category>Met</category>
            <event>Flash Flood Warning</event>
            <responseType>Avoid</responseType>
            <urgency>Immediate</urgency>
            <severity>Severe</severity>
            <certainty>Likely</certainty>
            <effective>2026-09-14T16:54:00-07:00</effective>
            <onset>2026-09-14T16:54:00-07:00</onset>
            <expires>2026-09-14T20:00:00-07:00</expires>
            <senderName>NWS Tucson AZ</senderName>
            <headline>Flash Flood Warning issued September 14 at 4:54PM MST until September 14 at 8:00PM MST by NWS Tucson AZ</headline>
            <description>FFWTWC

    The National Weather Service in Tucson has issued a

    * Flash Flood Warning for...
    Southeastern Pinal County in southeastern Arizona...

    HAZARD...Flash flooding caused by thunderstorms.

    SOURCE...Radar indicated.

    IMPACT...Flash flooding of small creeks and streams.
    </description>
            <instruction>Turn around, don't drown when encountering flooded roads.</instruction>
            <web>http://www.weather.gov</web>
            <parameter><valueName>AWIPSidentifier</valueName><value>FFWTWC</value></parameter>
            <parameter><valueName>WMOidentifier</valueName><value>WGUS55 KTWC 142354</value></parameter>
            <parameter><valueName>VTEC</valueName><value>/O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/</value></parameter>
            <area>
                <areaDesc>Pinal, AZ</areaDesc>
                <polygon>32.51,-110.56 32.57,-110.64 32.61,-110.58 32.51,-110.56</polygon>
                <geocode><valueName>SAME</valueName><value>004021</value></geocode>
                <geocode><valueName>UGC</valueName><value>AZC021</value></geocode>
            </area>
        </info>
    </alert>
    TEXT;
}

test('extracts CAP envelope and info fields', function () {
    Product::factory()->create(['code' => 'FF.W']);
    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['format'])->toBe('cap')
        ->and($metadata['msg_type'])->toBe('Alert')
        ->and($metadata['event'])->toBe('Flash Flood Warning')
        ->and($metadata['severity'])->toBe('Severe')
        ->and($metadata['urgency'])->toBe('Immediate')
        ->and($metadata['certainty'])->toBe('Likely')
        ->and($metadata['headline'])->toContain('Flash Flood Warning issued')
        ->and($metadata['description'])->toContain('HAZARD...Flash flooding')
        ->and($metadata['instruction'])->toContain("Turn around, don't drown");
});

test('resolves product_id via the embedded VTEC parameter, same as a text product would', function () {
    $flashFloodWarning = Product::factory()->create(['code' => 'FF.W']);

    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['product_id'])->toBe($flashFloodWarning->id)
        ->and($metadata['vtec_office'])->toBe('KTWC')
        ->and($metadata['vtec_phenomenon'])->toBe('FF')
        ->and($metadata['vtec_significance'])->toBe('W')
        ->and($metadata['vtec_event_number'])->toBe('0209')
        ->and($metadata['vtec_year'])->toBe(2026)
        ->and($metadata['vtec_action'])->toBe('NEW');
});

test('extracts the polygon into the same {lat, lon} array shape ExtractProductMetadata uses', function () {
    Product::factory()->create(['code' => 'FF.W']);
    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['polygon'])->toBe([
        ['lat' => 32.51, 'lon' => -110.56],
        ['lat' => 32.57, 'lon' => -110.64],
        ['lat' => 32.61, 'lon' => -110.58],
        ['lat' => 32.51, 'lon' => -110.56],
    ]);
});

test('extracts UGC codes from the area geocode, not the SAME/FIPS code', function () {
    Product::factory()->create(['code' => 'FF.W']);
    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['ugc_codes'])->toBe(['AZC021']);
});

test('extracts the correlation identifiers verbatim', function () {
    Product::factory()->create(['code' => 'FF.W']);
    $metadata = (new ExtractCapMetadata)(realCapAlertBody());

    expect($metadata['awips_identifier'])->toBe('FFWTWC')
        ->and($metadata['wmo_identifier'])->toBe('WGUS55 KTWC 142354');
});

test('a Cancel-type CAP message with no polygon or area leaves those fields null', function () {
    Product::factory()->create(['code' => 'SV.W']);
    $body = <<<'TEXT'
    479

    XOUS53 KWBC 142354

    CAPOAX

    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
        <identifier>urn:oid:2.49.0.1.840.0.f08e5b5780145a3dee03b18f3f5022a912a78da0.001.1</identifier>
        <sender>w-nws.webmaster@noaa.gov</sender>
        <sent>2026-09-14T18:54:00-05:00</sent>
        <status>Actual</status>
        <msgType>Cancel</msgType>
        <source>211803 v2.3.0</source>
        <scope>Public</scope>
        <code>IPAWSv1.0</code>
        <info>
            <language>en-US</language>
            <category>Met</category>
            <event>Severe Thunderstorm Warning</event>
            <responseType>AllClear</responseType>
            <urgency>Past</urgency>
            <severity>Minor</severity>
            <certainty>Observed</certainty>
            <effective>2026-09-14T18:54:00-05:00</effective>
            <expires>2026-09-14T19:09:52-05:00</expires>
            <senderName>NWS Omaha/Valley NE</senderName>
            <headline>The Severe Thunderstorm Warning has been cancelled.</headline>
            <description>The Severe Thunderstorm Warning has been cancelled and is no longer in effect.</description>
            <web>http://www.weather.gov</web>
            <parameter><valueName>AWIPSidentifier</valueName><value>SVSOAX</value></parameter>
            <parameter><valueName>WMOidentifier</valueName><value>WWUS53 KOAX 142354</value></parameter>
            <parameter><valueName>eventMotionDescription</valueName><value>2026-09-14T23:53:00-00:00...storm...231DEG...34KT...41.31,-96.18</value></parameter>
            <parameter><valueName>VTEC</valueName><value>/O.CAN.KOAX.SV.W.0413.000000T0000Z-260915T0015Z/</value></parameter>
        </info>
    </alert>
    TEXT;

    $metadata = (new ExtractCapMetadata)($body);

    expect($metadata['polygon'])->toBeNull()
        ->and($metadata['ugc_codes'])->toBeNull()
        ->and($metadata['description'])->not->toContain('HAZARD')
        ->and($metadata['event_motion_description'])->toBe('2026-09-14T23:53:00-00:00...storm...231DEG...34KT...41.31,-96.18')
        ->and($metadata['vtec_begin'])->toBeNull()
        ->and($metadata['vtec_action'])->toBe('CAN');
});

test('a VTEC phenomenon+significance with no matching catalog product resolves no product_id', function () {
    $body = str_replace('/O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/', '/O.NEW.KTWC.ZZ.Q.0001.260914T2354Z-260915T0300Z/', realCapAlertBody());

    $metadata = (new ExtractCapMetadata)($body);

    expect($metadata['product_id'])->toBeNull();
});
```

- [x] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractCapMetadataTest.php --compact"
```

Expected: FAIL — the class doesn't exist.

- [x] **Step 3: Implement the action**

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\Product;
use SimpleXMLElement;

/**
 * Parses a CAP (Common Alerting Protocol) XML product body -- the payload
 * NWS broadcasts on NWWS alongside every VTEC-class text product, under an
 * awipsid starting with "CAP" (see IngestNwwsProductController for the
 * detection check).
 *
 * Mirrors ExtractProductMetadata's output shape wherever the two overlap
 * (product_id, ugc_codes, polygon, the shared vtec_* fields) so both
 * extractors feed IngestedProduct identically. CAP does NOT split
 * hazard/source/impact out of <description> -- that narrative text arrives
 * exactly as bulleted in the raw text product, just wrapped in XML. This
 * class only extracts what CAP already gives us structured: it does not
 * attempt the narrative-bullet-splitting problem.
 *
 * A single <info>/<area> block is assumed, matching every real CAP alert
 * confirmed against live NWWS traffic so far. A multi-area CAP alert (if
 * one is ever observed) would need this revisited -- not attempted here,
 * not guessed at.
 */
class ExtractCapMetadata
{
    /**
     * @return array{format: string, msg_type: ?string, event: ?string, severity: ?string, urgency: ?string, certainty: ?string, headline: ?string, description: ?string, instruction: ?string, event_motion_description: ?string, awips_identifier: ?string, wmo_identifier: ?string, product_id: ?int, ugc_codes: ?array<int, string>, polygon: ?array<int, array{lat: float, lon: float}>, risk_levels: null, vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int, vtec_action: ?string, vtec_begin: ?\Illuminate\Support\Carbon, vtec_end: ?\Illuminate\Support\Carbon}
     */
    public function __invoke(string $text): array
    {
        $xmlStart = strpos($text, '<?xml');
        $xml = new SimpleXMLElement($xmlStart !== false ? substr($text, $xmlStart) : $text);
        $info = $xml->info;

        $parameters = $this->collectParameters($info);
        $vtecString = $parameters['VTEC'] ?? null;
        $vtec = $vtecString !== null ? (new ParseVtecString)($vtecString) : null;

        $productId = null;
        if ($vtec !== null) {
            $product = Product::where('code', "{$vtec['phenomenon']}.{$vtec['significance']}")->first();
            $productId = $product?->id;
        }

        $area = isset($info->area) ? $info->area : null;

        return [
            'format' => 'cap',
            'msg_type' => (string) $xml->msgType ?: null,
            'event' => (string) $info->event ?: null,
            'severity' => (string) $info->severity ?: null,
            'urgency' => (string) $info->urgency ?: null,
            'certainty' => (string) $info->certainty ?: null,
            'headline' => (string) $info->headline ?: null,
            'description' => (string) $info->description ?: null,
            'instruction' => (string) $info->instruction ?: null,
            'event_motion_description' => $parameters['eventMotionDescription'] ?? null,
            'awips_identifier' => $parameters['AWIPSidentifier'] ?? null,
            'wmo_identifier' => $parameters['WMOidentifier'] ?? null,
            'product_id' => $productId,
            'ugc_codes' => $area !== null ? $this->extractUgcCodes($area) : null,
            'polygon' => $area !== null ? $this->extractPolygon($area) : null,
            'risk_levels' => null,
            'vtec_office' => $vtec['office'] ?? null,
            'vtec_phenomenon' => $vtec['phenomenon'] ?? null,
            'vtec_significance' => $vtec['significance'] ?? null,
            'vtec_event_number' => $vtec['event_number'] ?? null,
            'vtec_year' => $vtec['year'] ?? null,
            'vtec_action' => $vtec['action'] ?? null,
            'vtec_begin' => $vtec['begin'] ?? null,
            'vtec_end' => $vtec['end'] ?? null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function collectParameters(SimpleXMLElement $info): array
    {
        $parameters = [];

        foreach ($info->parameter as $parameter) {
            $parameters[(string) $parameter->valueName] = (string) $parameter->value;
        }

        return $parameters;
    }

    /**
     * @return ?array<int, string>
     */
    private function extractUgcCodes(SimpleXMLElement $area): ?array
    {
        $codes = [];

        foreach ($area->geocode as $geocode) {
            if ((string) $geocode->valueName === 'UGC') {
                $codes[] = (string) $geocode->value;
            }
        }

        return $codes === [] ? null : $codes;
    }

    /**
     * CAP's <polygon> is a single space-separated "lat,lon lat,lon ..."
     * string -- much simpler to decode than the raw text LAT...LON format
     * (no multi-digit coordinate encoding to reverse).
     *
     * @return ?array<int, array{lat: float, lon: float}>
     */
    private function extractPolygon(SimpleXMLElement $area): ?array
    {
        $raw = trim((string) $area->polygon);

        if ($raw === '') {
            return null;
        }

        return collect(explode(' ', $raw))
            ->map(function (string $pair): array {
                [$lat, $lon] = explode(',', $pair);

                return ['lat' => (float) $lat, 'lon' => (float) $lon];
            })
            ->all();
    }
}
```

- [x] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractCapMetadataTest.php --compact"
```

Expected: PASS (7 tests).

- [x] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Pipeline/ExtractCapMetadata.php tests/Feature/ExtractCapMetadataTest.php
git commit -m "$(cat <<'EOF'
Add ExtractCapMetadata action

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Wire CAP detection into `IngestNwwsProductController`

**Files:**
- Modify: `app/Http/Controllers/IngestNwwsProductController.php`
- Test: `tests/Feature/IngestNwwsProductControllerTest.php`

`awipsid` starting with `CAP` is the detection signal — confirmed twice against real NWWS traffic (`CAPOAX`, `CAPTWC`) — checked before any body parsing.

- [x] **Step 1: Write the failing test**

Add to `tests/Feature/IngestNwwsProductControllerTest.php` (add `use App\Actions\Pipeline\ExtractCapMetadata;` if needed — not required, this test only checks persisted column values):

```php
test('ingesting a CAP product runs the CAP extractor and persists its fields', function () {
    Product::factory()->create(['code' => 'FF.W']);
    File::ensureDirectoryExists($this->productsRoot.'/kwbc');
    $body = <<<'TEXT'
    480

    XOUS55 KWBC 142354

    CAPTWC

    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
        <msgType>Alert</msgType>
        <info>
            <event>Flash Flood Warning</event>
            <severity>Severe</severity>
            <description>Flash flooding text.</description>
            <parameter><valueName>AWIPSidentifier</valueName><value>FFWTWC</value></parameter>
            <parameter><valueName>WMOidentifier</valueName><value>WGUS55 KTWC 142354</value></parameter>
            <parameter><valueName>VTEC</valueName><value>/O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/</value></parameter>
            <area>
                <areaDesc>Pinal, AZ</areaDesc>
                <polygon>32.51,-110.56 32.57,-110.64 32.61,-110.58 32.51,-110.56</polygon>
                <geocode><valueName>UGC</valueName><value>AZC021</value></geocode>
            </area>
        </info>
    </alert>
    TEXT;
    File::put($this->productsRoot.'/kwbc/kwbc_xous55-captwc.142354_1.txt', $body);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kwbc/kwbc_xous55-captwc.142354_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $product = IngestedProduct::sole();

    expect($product->format)->toBe('cap')
        ->and($product->event)->toBe('Flash Flood Warning')
        ->and($product->awips_identifier)->toBe('FFWTWC')
        ->and($product->vtec_office)->toBe('KTWC')
        ->and($product->ugc_codes)->toBe(['AZC021']);
});

test('ingesting a plain text product still runs the text extractor, not the CAP one', function () {
    File::ensureDirectoryExists($this->productsRoot.'/okx');
    File::put($this->productsRoot.'/okx/okx_fxus61-afdokx.061800_1.txt', "AREA FORECAST DISCUSSION\n");

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'okx/okx_fxus61-afdokx.061800_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    expect(IngestedProduct::sole()->format)->toBe('text');
});
```

- [x] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestNwwsProductControllerTest.php --compact"
```

Expected: the first new test FAILS (CAP fields never populated, since the controller doesn't branch yet). The second passes already (the default `format` is `text`), confirming the baseline.

- [x] **Step 3: Wire the branch into the controller**

In `app/Http/Controllers/IngestNwwsProductController.php`, add the import:

```php
use App\Actions\Pipeline\ExtractCapMetadata;
```

Change the constructor and body from:

```php
    public function __construct(private readonly ExtractProductMetadata $extractProductMetadata) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $root = realpath(config('services.pipeline.products_path'));
        $absolutePath = $root !== false
            ? realpath($root.DIRECTORY_SEPARATOR.$validated['path'])
            : false;

        if ($root === false || $absolutePath === false || ! str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $parsed = IngestedProduct::parseFilename(basename($absolutePath));

        if ($parsed['cccc'] === null) {
            Log::warning('Ingested an NWWS product with an unparseable filename', [
                'path' => $validated['path'],
            ]);
        }

        $text = file_get_contents($absolutePath);
        $metadata = ($this->extractProductMetadata)($text, $parsed['awipsid']);

        IngestedProduct::updateOrCreate(
            ['file_path' => $validated['path']],
            [
                ...$parsed,
                ...$metadata,
                'text' => $text,
                'received_at' => now(),
            ],
        );

        return response()->noContent();
    }
```

to:

```php
    public function __construct(
        private readonly ExtractProductMetadata $extractProductMetadata,
        private readonly ExtractCapMetadata $extractCapMetadata,
    ) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $root = realpath(config('services.pipeline.products_path'));
        $absolutePath = $root !== false
            ? realpath($root.DIRECTORY_SEPARATOR.$validated['path'])
            : false;

        if ($root === false || $absolutePath === false || ! str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $parsed = IngestedProduct::parseFilename(basename($absolutePath));

        if ($parsed['cccc'] === null) {
            Log::warning('Ingested an NWWS product with an unparseable filename', [
                'path' => $validated['path'],
            ]);
        }

        $text = file_get_contents($absolutePath);
        $isCap = $parsed['awipsid'] !== null && str_starts_with($parsed['awipsid'], 'cap');
        $metadata = $isCap
            ? ($this->extractCapMetadata)($text)
            : ($this->extractProductMetadata)($text, $parsed['awipsid']);

        IngestedProduct::updateOrCreate(
            ['file_path' => $validated['path']],
            [
                ...$parsed,
                ...$metadata,
                'text' => $text,
                'received_at' => now(),
            ],
        );

        return response()->noContent();
    }
```

Note `$parsed['awipsid']` is already lowercased by `IngestedProduct::parseFilename()` (it lowercases the whole filename), so the check is `str_starts_with($parsed['awipsid'], 'cap')`, not `'CAP'`.

- [x] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestNwwsProductControllerTest.php --compact"
```

Expected: PASS, every test in the file (9 pre-existing + 2 new = 11).

- [x] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Http/Controllers/IngestNwwsProductController.php tests/Feature/IngestNwwsProductControllerTest.php
git commit -m "$(cat <<'EOF'
Detect and parse CAP products in IngestNwwsProductController

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: `SyncActiveWwa` — upsert and dispatch-trigger decision

**Files:**
- Create: `app/Actions/Pipeline/SyncActiveWwa.php`
- Test: `tests/Feature/SyncActiveWwaTest.php`

This is the core of the design: text rows always upsert `active_wwas` and always trigger matching (preserving today's per-issuance dispatch, including continuations). CAP rows correlate to their paired text row via `awips_identifier`/`wmo_identifier`; if found, they enrich without re-dispatching; if not found, they dispatch on their own.

- [x] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\Pipeline\SyncActiveWwa;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;

function createIngestedProductWithoutMatching(array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create($attributes));
}

test('a text row creates a new active_wwas row and always triggers matching', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::factory()->create(['code' => 'TO.W']);
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'ugc_codes' => ['NYZ069'], 'polygon' => [['lat' => 40.0, 'lon' => -73.0]],
    ]);

    (new SyncActiveWwa)($ingested);

    $wwa = ActiveWwa::sole();
    expect($wwa->vtec_office)->toBe('KOKX')
        ->and($wwa->vtec_event_number)->toBe('0042')
        ->and($wwa->product_id)->toBe($product->id)
        ->and($wwa->ugc_codes)->toBe(['NYZ069'])
        ->and($wwa->source)->toBe('text')
        ->and($wwa->latest_ingested_product_id)->toBe($ingested->id);
    $spy->shouldHaveReceived('__invoke')->once()->with($ingested);
});

test('a continuation text row (no polygon of its own) does not clobber a previously known polygon', function () {
    Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::factory()->create(['code' => 'TO.W']);
    $wwa = ActiveWwa::factory()->create([
        'product_id' => $product->id, 'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);
    DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', ['POLYGON((-74 40, -74 41, -73 41, -73 40, -74 40))', $wwa->id]);

    $continuation = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026, 'vtec_action' => 'CON',
        'polygon' => null, 'ugc_codes' => ['NYZ069'],
    ]);

    (new SyncActiveWwa)($continuation);

    $stillHasPolygon = DB::selectOne('SELECT polygon IS NOT NULL AS has_polygon FROM active_wwas WHERE id = ?', [$wwa->id]);
    expect($stillHasPolygon->has_polygon)->toBeTrue();
});

test('a CAP row with a matching paired text row enriches active_wwas without re-dispatching', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::factory()->create(['code' => 'FF.W']);
    $textRow = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'format' => 'text',
        'cccc' => 'ktwc', 'ttaaii' => 'wgus55', 'awipsid' => 'ffwtwc', 'wmo_ddhhmm' => '142354',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0209', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
    ]);
    (new SyncActiveWwa)($textRow);
    $spy->shouldHaveReceived('__invoke')->once();

    $capRow = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0209', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'awips_identifier' => 'FFWTWC', 'wmo_identifier' => 'WGUS55 KTWC 142354',
        'ugc_codes' => ['AZC021'], 'polygon' => [['lat' => 32.5, 'lon' => -110.5]],
    ]);

    (new SyncActiveWwa)($capRow);

    expect(ActiveWwa::count())->toBe(1);
    $wwa = ActiveWwa::sole();
    expect($wwa->source)->toBe('cap')
        ->and($wwa->ugc_codes)->toBe(['AZC021'])
        ->and($wwa->latest_ingested_product_id)->toBe($textRow->id);
    $spy->shouldHaveReceived('__invoke')->once(); // still only the one call from the text row
});

test('a CAP row with no matching paired text row dispatches on its own', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::factory()->create(['code' => 'FF.W']);
    $capRow = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0209', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'awips_identifier' => 'FFWTWC', 'wmo_identifier' => 'WGUS55 KTWC 142354',
    ]);

    (new SyncActiveWwa)($capRow);

    $spy->shouldHaveReceived('__invoke')->once()->with($capRow);
    expect(ActiveWwa::sole()->latest_ingested_product_id)->toBe($capRow->id);
});
```

- [x] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SyncActiveWwaTest.php --compact"
```

Expected: FAIL — the class doesn't exist.

- [x] **Step 3: Implement the action**

```php
<?php

namespace App\Actions\Pipeline;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Upserts the "current state" active_wwas row for a VTEC-class IngestedProduct
 * and decides whether this ingestion should trigger a fresh match-and-dispatch
 * pass. Text rows always dispatch -- this is what preserves today's behavior
 * of notifying on every issuance including continuations (SVS/FFS/etc, which
 * resolve to the same product_id as their parent warning). CAP rows only
 * dispatch when their paired text row can't be found, since the normal case
 * is CAP arriving a few seconds after text already dispatched.
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
        $wwa = ActiveWwa::updateOrCreate(
            $this->vtecKey($product),
            [
                'product_id' => $product->product_id,
                'status' => $this->resolveStatus($product->vtec_action),
                'ugc_codes' => $product->ugc_codes,
                'effective' => $product->vtec_begin,
                'expires' => $product->vtec_end,
                'latest_ingested_product_id' => $product->id,
                'source' => 'text',
            ],
        );

        if ($product->polygon !== null) {
            $wkt = $this->polygonToWkt($product->polygon);
            DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $wwa->id]);
        }

        app(MatchWeatherAlertRules::class)($product);
    }

    private function syncFromCap(IngestedProduct $product): void
    {
        $pairedText = $this->findPairedTextProduct($product);

        $wwa = ActiveWwa::updateOrCreate(
            $this->vtecKey($product),
            [
                'product_id' => $product->product_id,
                'status' => $this->resolveStatus($product->vtec_action),
                'ugc_codes' => $product->ugc_codes,
                'effective' => $product->vtec_begin,
                'expires' => $product->vtec_end,
                'headline' => $product->headline,
                'source' => 'cap',
                ...($pairedText === null ? ['latest_ingested_product_id' => $product->id] : []),
                ...$this->parseMotion($product->event_motion_description),
            ],
        );

        if ($product->polygon !== null) {
            $wkt = $this->polygonToWkt($product->polygon);
            DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $wwa->id]);
        }

        if ($pairedText === null) {
            app(MatchWeatherAlertRules::class)($product);
        }
    }

    /**
     * @return array{vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int}
     */
    private function vtecKey(IngestedProduct $product): array
    {
        return [
            'vtec_office' => $product->vtec_office,
            'vtec_phenomenon' => $product->vtec_phenomenon,
            'vtec_significance' => $product->vtec_significance,
            'vtec_event_number' => $product->vtec_event_number,
            'vtec_year' => $product->vtec_year,
        ];
    }

    private function resolveStatus(?string $vtecAction): string
    {
        return match ($vtecAction) {
            'CAN' => 'Cancelled',
            'EXP' => 'Expired',
            default => 'Active',
        };
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
            ->first();
    }

    /**
     * @param  array<int, array{lat: float, lon: float}>  $points
     */
    private function polygonToWkt(array $points): string
    {
        $coords = collect($points)->map(fn (array $p): string => "{$p['lon']} {$p['lat']}");

        if ($coords->first() !== $coords->last()) {
            $coords->push($coords->first());
        }

        return 'POLYGON(('.$coords->join(', ').'))';
    }

    /**
     * Parses CAP's eventMotionDescription parameter, e.g.
     * "2026-09-14T23:53:00-00:00...storm...231DEG...34KT...41.31,-96.18",
     * into active_wwas's discrete motion_* columns. Built from a single real
     * confirmed example -- if a real product's format ever varies, this
     * needs revisiting, not guessed at further.
     *
     * @return array{motion_observed_at?: \Illuminate\Support\Carbon, motion_direction_degrees?: int, motion_speed_kt?: int, motion_reference_lat?: float, motion_reference_lon?: float}
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

- [x] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SyncActiveWwaTest.php --compact"
```

Expected: PASS (4 tests).

- [x] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Pipeline/SyncActiveWwa.php tests/Feature/SyncActiveWwaTest.php
git commit -m "$(cat <<'EOF'
Add SyncActiveWwa action

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Wire `SyncActiveWwa` into `IngestedProductObserver`

**Files:**
- Modify: `app/Observers/IngestedProductObserver.php`
- Test: `tests/Feature/IngestNwwsProductControllerTest.php`

Reuses the existing spy-based observer-wiring test pattern already in this file.

- [x] **Step 1: Write the failing test**

Add to `tests/Feature/IngestNwwsProductControllerTest.php`:

```php
test('a newly ingested VTEC product dispatches SyncActiveWwa instead of MatchWeatherAlertRules directly', function () {
    $syncSpy = Mockery::spy(SyncActiveWwa::class);
    app()->instance(SyncActiveWwa::class, $syncSpy);
    $matchSpy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $matchSpy);

    File::ensureDirectoryExists($this->productsRoot.'/kokx');
    $text = "NYZ069-081200-\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\n";
    File::put($this->productsRoot.'/kokx/kokx_wfus51-torokx.061800_1.txt', $text);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kokx/kokx_wfus51-torokx.061800_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $syncSpy->shouldHaveReceived('__invoke')->once();
    $matchSpy->shouldNotHaveReceived('__invoke');
});
```

Add `use App\Actions\Pipeline\SyncActiveWwa;` to the top of the test file.

- [x] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestNwwsProductControllerTest.php --compact"
```

Expected: FAILS — `SyncActiveWwa` is never called yet, since the observer still calls `MatchWeatherAlertRules` for everything. Confirm the pre-existing AFD-based observer test ("a newly ingested product dispatches MatchWeatherAlertRules exactly once") still passes — it must keep passing unmodified, since AFD isn't VTEC-class.

- [x] **Step 3: Branch the observer**

Read `app/Observers/IngestedProductObserver.php` first. Change it from:

```php
<?php

namespace App\Observers;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;

class IngestedProductObserver
{
    public function created(IngestedProduct $product): void
    {
        app(MatchWeatherAlertRules::class)($product);
    }
}
```

to:

```php
<?php

namespace App\Observers;

use App\Actions\Pipeline\SyncActiveWwa;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;

class IngestedProductObserver
{
    public function created(IngestedProduct $product): void
    {
        if ($product->vtec_office !== null) {
            app(SyncActiveWwa::class)($product);

            return;
        }

        app(MatchWeatherAlertRules::class)($product);
    }
}
```

- [x] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestNwwsProductControllerTest.php --compact"
```

Expected: PASS, every test in the file (11 pre-existing + 1 new = 12). Confirm specifically that "a newly ingested product dispatches MatchWeatherAlertRules exactly once" (the AFD test) still passes unmodified.

- [x] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Observers/IngestedProductObserver.php tests/Feature/IngestNwwsProductControllerTest.php
git commit -m "$(cat <<'EOF'
Route VTEC-class ingested products through SyncActiveWwa

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: `MatchWeatherAlertRules` — consult `active_wwas`'s persisted polygon

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php`
- Test: `tests/Feature/MatchWeatherAlertRulesTest.php`

When a persisted `active_wwas` polygon exists for the triggering row's VTEC key, Polygon-mode matching uses it instead of the triggering row's own (possibly-null-on-continuation) polygon. Every existing test in this file is unaffected — none of their fixtures set `vtec_office`, so the new lookup finds nothing and falls through to today's unchanged behavior.

- [x] **Step 1: Write the failing tests**

Add to `tests/Feature/MatchWeatherAlertRulesTest.php` (add `use App\Models\ActiveWwa;` to the imports):

```php
test('a Polygon-mode rule matches against active_wwas\'s persisted polygon when the triggering row has none of its own', function () {
    // Simulates a continuation statement (e.g. SVS) that carries no LAT...LON
    // of its own, but the event's polygon is already known from an earlier
    // issuance -- see SyncActiveWwaTest for how that polygon gets there.
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['lat' => 40.5, 'lng' => -74.0]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    $wwa = ActiveWwa::factory()->create([
        'product_id' => $product->id, 'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);
    DB::statement(
        'UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?',
        ['POLYGON((-74.5 40, -74.5 41, -73.5 41, -73.5 40, -74.5 40))', $wwa->id],
    );

    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'polygon' => null,
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule does not match a location outside active_wwas\'s persisted polygon', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['lat' => 10.0, 'lng' => -10.0]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    $wwa = ActiveWwa::factory()->create([
        'product_id' => $product->id, 'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);
    DB::statement(
        'UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?',
        ['POLYGON((-74.5 40, -74.5 41, -73.5 41, -73.5 40, -74.5 40))', $wwa->id],
    );

    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'polygon' => null,
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('when active_wwas has no persisted polygon for this VTEC key, matching falls back to the triggering row\'s own polygon field', function () {
    // vtec_office is set (so the lookup runs) but no active_wwas row exists
    // at all yet -- must not throw, must fall through cleanly.
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'polygon' => null, 'ugc_codes' => ['NYZ072'],
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0099', 'vtec_year' => 2026,
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class); // falls back to Location-style zone matching, same as today
});
```

- [x] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php --compact"
```

Expected: the first 2 new tests FAIL (matching doesn't consult `active_wwas` yet, so both currently fall back to zone matching — which happens to make the first test incidentally pass for the wrong reason if the rule's location also has zone data, so double check the fixture has NO `forecast_zone_ugc` set, which it doesn't above, so it correctly fails as "not pushed" since neither polygon nor zone data resolves). The third new test passes already (it's exercising the existing fallback path). All 21 pre-existing tests still pass unmodified.

- [x] **Step 3: Update `matchesPolygon()`**

Read `app/Actions/Weather/MatchWeatherAlertRules.php` first. Add the import:

```php
use App\Models\ActiveWwa;
```

Change `matchesPolygon()` from:

```php
    private function matchesPolygon(AlertRule $rule, IngestedProduct $product): ?bool
    {
        if ($product->polygon === null || $rule->location === null || ! $rule->location->hasFix()) {
            return null;
        }

        $wkt = $this->polygonToWkt($product->polygon);
        $lng = (float) $rule->location->lng;
        $lat = (float) $rule->location->lat;

        $result = DB::selectOne(
            'SELECT ST_Contains(ST_SetSRID(ST_GeomFromText(?), 4269), ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains',
            [$wkt, $lng, $lat],
        );

        // Not filter_var(..., FILTER_VALIDATE_BOOLEAN): it doesn't recognize
        // Postgres's own 't'/'f' text encoding for a boolean column ('f' is
        // non-empty, so a naive (bool) cast is equally wrong) -- the pgsql
        // PDO driver happens to hand back a native bool today, but a driver
        // or connection-option change silently turning a real match into a
        // dropped alert is the worst failure mode for this system, so both
        // representations are checked explicitly.
        return $result !== null && ($result->contains === true || $result->contains === 't');
    }
```

to:

```php
    private function matchesPolygon(AlertRule $rule, IngestedProduct $product): ?bool
    {
        if ($rule->location === null || ! $rule->location->hasFix()) {
            return null;
        }

        $lng = (float) $rule->location->lng;
        $lat = (float) $rule->location->lat;

        $activeWwaResult = $this->matchesActiveWwaPolygon($product, $lng, $lat);
        if ($activeWwaResult !== null) {
            return $activeWwaResult;
        }

        if ($product->polygon === null) {
            return null;
        }

        $wkt = $this->polygonToWkt($product->polygon);

        $result = DB::selectOne(
            'SELECT ST_Contains(ST_SetSRID(ST_GeomFromText(?), 4269), ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains',
            [$wkt, $lng, $lat],
        );

        return $this->resultIsTrue($result);
    }

    /**
     * A VTEC-class product's persisted active_wwas polygon takes priority
     * over the triggering row's own (possibly null on a continuation
     * statement like SVS/FFS) polygon -- see SyncActiveWwa, which is what
     * keeps that polygon current across an event's whole lifetime. Returns
     * null (not false) both when the product isn't VTEC-class at all and
     * when no active_wwas row with a polygon exists yet, so the caller falls
     * back to the row's own polygon field either way.
     */
    private function matchesActiveWwaPolygon(IngestedProduct $product, float $lng, float $lat): ?bool
    {
        if ($product->vtec_office === null) {
            return null;
        }

        $activeWwa = ActiveWwa::query()
            ->where('vtec_office', $product->vtec_office)
            ->where('vtec_phenomenon', $product->vtec_phenomenon)
            ->where('vtec_significance', $product->vtec_significance)
            ->where('vtec_event_number', $product->vtec_event_number)
            ->where('vtec_year', $product->vtec_year)
            ->whereNotNull('polygon')
            ->first();

        if ($activeWwa === null) {
            return null;
        }

        $result = DB::selectOne(
            'SELECT ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains FROM active_wwas WHERE id = ?',
            [$lng, $lat, $activeWwa->id],
        );

        return $this->resultIsTrue($result);
    }

    /**
     * Not filter_var(..., FILTER_VALIDATE_BOOLEAN): it doesn't recognize
     * Postgres's own 't'/'f' text encoding for a boolean column ('f' is
     * non-empty, so a naive (bool) cast is equally wrong) -- the pgsql PDO
     * driver happens to hand back a native bool today, but a driver or
     * connection-option change silently turning a real match into a dropped
     * alert is the worst failure mode for this system, so both
     * representations are checked explicitly.
     */
    private function resultIsTrue(?object $result): bool
    {
        return $result !== null && ($result->contains === true || $result->contains === 't');
    }
```

This preserves the existing malformed-polygon regression test exactly: `matchesActiveWwaPolygon()` returns `null` immediately for any fixture that doesn't set `vtec_office` (every pre-existing test in this file), so `matchesPolygon()` falls through unchanged to the original `$product->polygon` path, including the `[]` → `ST_GeomFromText` parse-error case.

- [x] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php --compact"
```

Expected: PASS, every test in the file (21 pre-existing + 3 new = 24). Specifically confirm `'an exception evaluating one rule\'s geography does not prevent a second, valid rule from matching'` still passes unmodified.

- [x] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/MatchWeatherAlertRulesTest.php
git commit -m "$(cat <<'EOF'
Consult active_wwas's persisted polygon in Polygon-mode matching

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: `DeliverWeatherAlert` — CAP-description body fallback

**Files:**
- Modify: `app/Jobs/DeliverWeatherAlert.php`
- Test: `tests/Feature/DeliverWeatherAlertTest.php`

Only reached on the rare path where a CAP payload dispatches without a paired text row (`SyncActiveWwa`'s fallback case). `RenderAlertSubject` needs no change — it never reads `text`/`description`.

- [x] **Step 1: Write the failing test**

Add to `tests/Feature/DeliverWeatherAlertTest.php`:

```php
test('a CAP-sourced product with no paired text uses its description as the message body', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create([
        'format' => 'cap',
        'text' => '<?xml version="1.0"?><alert>raw CAP XML, never meant to reach a subscriber</alert>',
        'description' => 'Flash flooding is ongoing across the warned area. Seek higher ground immediately.',
    ]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Flash flooding is ongoing across the warned area. Seek higher ground immediately.');
});

test('a text-sourced product still uses its raw text as the message body, unaffected by the CAP fallback', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create(['format' => 'text', 'text' => 'Tornado Warning issued for the area.']);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Tornado Warning issued for the area.');
});
```

- [x] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverWeatherAlertTest.php --compact"
```

Expected: the first new test FAILS (body is still built from `$product->text`, the raw XML); the second passes already (matches current behavior).

- [x] **Step 3: Wire the body-source branch**

Read `app/Jobs/DeliverWeatherAlert.php` first. Change:

```php
        $delivered = $client->notify(
            url: $channel->apprise_url,
            body: Str::limit($product->text, $rule->effectiveMessageBodyStyle() === MessageBodyStyle::Brief ? 280 : 1500, preserveWords: true),
            title: app(RenderAlertSubject::class)($rule, $product->product),
        );
```

to:

```php
        $bodySource = $product->format === 'cap' ? $product->description : $product->text;

        $delivered = $client->notify(
            url: $channel->apprise_url,
            body: Str::limit($bodySource, $rule->effectiveMessageBodyStyle() === MessageBodyStyle::Brief ? 280 : 1500, preserveWords: true),
            title: app(RenderAlertSubject::class)($rule, $product->product),
        );
```

- [x] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverWeatherAlertTest.php --compact"
```

Expected: PASS, every test in the file (11 pre-existing + 2 new = 13).

- [x] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Jobs/DeliverWeatherAlert.php tests/Feature/DeliverWeatherAlertTest.php
git commit -m "$(cat <<'EOF'
Use CAP description as delivery body for CAP-only dispatches

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Full suite verification

**Files:** none (verification only)

- [x] **Step 1: Run the complete test suite**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"
```

Expected: 100% pass, no regressions anywhere in the suite. In particular, re-confirm every AFD/SPC-WPC test in `MatchWeatherAlertRulesTest.php` and the AFD observer-wiring test in `IngestNwwsProductControllerTest.php` are unaffected — those are the non-VTEC-class paths this whole plan is explicit about leaving untouched.

- [x] **Step 2: Run Pint across the whole diff**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

Expected: `{"tool":"pint","result":"passed"}`.

- [x] **Step 3: Manual verification against the real captured NWWS traffic**

Using the real CAP examples already captured in this session's investigation (a KOAX Severe Thunderstorm Warning cancellation and a KTWC Flash Flood Warning), manually POST both the paired text and CAP bodies through `/api/pipeline/nwws-products` (or via Tinker directly instantiating `IngestNwwsProductController`'s dependencies) against a real Postgres/PostGIS connection, and confirm: exactly one `active_wwas` row results per event, its `source` ends as `cap`, and no duplicate `DeliverWeatherAlert` dispatch occurred for a rule matching that product (check `DeliveryLog`/queued-job count, not just that the row exists).

- [x] **Step 4: Final commit (if step 3 surfaced any fixes)**

```bash
git add -A
git commit -m "Fix issues found during manual verification of Active WWA tracking"
```

(Skip this step entirely if step 3 found nothing to fix.)

Step 3 found nothing to fix. Manual verification used the real captured KTWC Flash Flood Warning (text + paired CAP) from this session's original NWWS debug-log investigation, POSTed through the real `/api/pipeline/nwws-products` webhook (not a test double) against the real dev Postgres/PostGIS database: two `IngestedProduct` rows resulted (`format=text` and `format=cap`, both correctly VTEC-parsed to the same `product_id`), collapsing to exactly one `active_wwas` row (`source=cap`, `status=Active`, `headline` and `ugc_codes` correctly enriched from CAP, `polygon` persisted as real PostGIS geometry matching the captured coordinates, and — critically — `latest_ingested_product_id` pointing at the **text** row's id, not the CAP row's, exactly as designed). No warnings or errors appeared in the application log during ingestion. No `AlertRule` existed to match against, so this pass deliberately didn't exercise a real Apprise dispatch (avoiding an external HTTP call from a manual verification script) — dispatch-decision correctness for this exact scenario is already covered by `SyncActiveWwaTest`'s spy-based tests. All verification data was cleaned up afterward (rows deleted, product files removed) — the dev database returned to its exact pre-verification state (2190 `IngestedProduct` rows, 0 `ActiveWwa` rows).

---

## Post-implementation: review history

Every task in this plan went through the standard two-stage review (spec compliance, then code quality), with re-review loops until each passed cleanly. Two tasks required follow-up fixes beyond their first review pass, both worth recording:

**Task 7 (`SyncActiveWwa`)** — the highest-stakes task in the plan, and the only one to receive deliberately elevated scrutiny (an `opus`-level code-quality reviewer, explicitly told to err toward flagging anything uncertain as Important). Went through four commits across three hardening rounds before final approval: the first review found a stale/unbounded CAP-to-text pairing lookup that could silently suppress a real dispatch (fixed by bounding the lookup to a time window and ordering deterministically), a conditional-spread bug that left `latest_ingested_product_id` null on a reachable path, and completely unguarded database work sitting in front of the actual notification dispatch (fixed with try/catch). A second review round found the try/catch alone didn't prevent Postgres transaction-poisoning the way this codebase's own established convention (`MatchWeatherAlertRules::matchesGeographySafely()`) already accounts for, and that the bounded-lookup fix itself had no test coverage — both fixed and verified empirically (the transaction fix was confirmed by deliberately reproducing the poisoning against the real database, both with and without the fix in place).

**Task 8 → Task 7 (live-wiring surfaced a pre-existing gap)** — Task 8 itself (wiring `SyncActiveWwa` into `IngestedProductObserver`) was a clean, minimal 4-line change that passed review immediately. But making `SyncActiveWwa` reachable in production for the first time is what surfaced one more real gap in Task 7's own code: `syncFromCap()`'s pairing-lookup query ran *before* the method's try/catch block, so a transient query failure there would propagate uncaught through the whole ingest webhook — and because the triggering row was already committed by that point, a retry would hit the database's UPDATE path rather than CREATE, silently and permanently skipping matching for that product with no way to recover. Fixed by moving the lookup inside the guarded block and treating a lookup failure the same as "no pair found" (the safe default — dispatch anyway, accepting a rare possible duplicate rather than risking a silently-dropped real warning).

**Task 10 (`DeliverWeatherAlert` CAP-description fallback)** — passed spec review immediately, but code-quality review caught a real, empirically-verified crash: CAP's `<description>` element is optional per the CAP 1.2 spec, and a CAP row reaching the no-paired-text delivery fallback with a null `description` would throw an uncaught `TypeError` (`Str::limit(null, ...)` silently passes `null` through; `AppriseApiClient::notify()`'s non-nullable `string $body` parameter then throws), aborting the job before any `DeliveryLog` audit row was written. Fixed with a one-line fallback to the raw text in that case.

No issues survived to this final verification pass — every finding above was resolved, re-reviewed, and confirmed fixed before moving to the next task.

---

## Self-Review Notes

**Spec coverage:** Every section of the design doc has a corresponding task — real CAP payload structure (Task 5's fixtures, taken verbatim from the design doc's captured examples), CAP detection (Task 6), shared VTEC parsing (Task 3/4), both new schema pieces (Tasks 1-2), the dispatch-trigger redesign including the text-always-dispatches / CAP-enriches-without-redispatch / CAP-fallback-dispatches rules (Task 7), the matching-accuracy side effect (Task 9), and the CAP-only delivery fallback (Task 10). The design doc's "no motion vector parsing from raw text" decision is honored — `ParseVtecString`/`ExtractProductMetadata` never touch `event_motion_description`, only `ExtractCapMetadata`/`SyncActiveWwa` do.

**Placeholder scan:** No TBD/TODO markers. Every code block is complete, runnable code, not a description of what to write.

**Type consistency:** `ExtractProductMetadata` and `ExtractCapMetadata` return the identical shared `vtec_*` key names (`vtec_office`, `vtec_phenomenon`, etc.), consumed identically by `SyncActiveWwa`'s `vtecKey()` and `MatchWeatherAlertRules`'s `matchesActiveWwaPolygon()` — verified these three call sites all use the same five column names in the same order throughout. `IngestedProduct::$format` (`'text'`/`'cap'`) is the single signal both `SyncActiveWwa::__invoke()` and `DeliverWeatherAlert::handle()` branch on — no second, competing "is this CAP" check was introduced anywhere (Task 6's controller-level detection uses the raw `awipsid` prefix only to *choose which extractor to run*, and that extractor is what sets `format` — the two never disagree by construction, since only `ExtractCapMetadata` ever sets `format => 'cap'`).

**Real-data grounding:** Task 5's and Task 6's CAP fixtures are trimmed-but-verbatim copies of the two real CAP payloads captured from a live NWWS session during this feature's brainstorm (a KOAX SVS cancellation and a KTWC FFW), not synthesized from documentation. `ParseVtecString`'s regex and `SyncActiveWwa::parseMotion()`'s regex are both built directly from those same real examples, with an explicit docblock caveat (matching this codebase's existing convention in `ExtractProductMetadata`) that the motion-vector format is confirmed against exactly one real example and may need revisiting if a differently-shaped one is ever observed.
