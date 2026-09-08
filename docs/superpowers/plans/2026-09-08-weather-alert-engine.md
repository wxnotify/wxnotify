# Weather Alert Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the NWWS-driven Weather Alert Engine that matches an ingested NWS product against subscribers' `AlertRule`s and delivers notifications, per `docs/superpowers/specs/2026-09-06-weather-alert-engine-design.md`.

**Architecture:** `IngestNwwsProductController` writes an `IngestedProduct` row, enriched at ingest time by a new `ExtractProductMetadata` action (VTEC → `product_id`, UGC codes, polygon). An `IngestedProduct::created` observer dispatches `MatchWeatherAlertRules`, which queries active `AlertRule`s, checks geography (using real PostGIS `ST_Contains` queries against the AWIPS Shapefile Management feature's `public_zones`/`counties`/`fire_zones`/`cwa_boundaries` tables — no external API), checks quiet hours, and dispatches one `DeliverWeatherAlert` queued job per matched rule × channel, which calls `AppriseApiClient` and writes a `DeliveryLog` row. This mirrors the existing Visibility dispatch engine (`MatchActivityAlertRules` → `SendActivityAlert`) throughout.

**Tech Stack:** Laravel 13, Filament v5, Pest 5 (`pest-plugin-livewire`), Postgres + PostGIS (raw SQL — no ORM geometry cast is used anywhere in this codebase; `clickbar/laravel-magellan` is installed but unused, so this plan follows the existing raw-SQL convention, not the Magellan facade), `tapp/filament-timezone-field` (new).

**Scope decision (carried from the design doc, made concrete here):** `AlertCategory::SpcWpc` is **not wired into `MatchWeatherAlertRules` in this plan**. The design doc's own "Known gaps" flagged SPC/WPC AWIPS-ID prefixes as unconfirmed against real data — re-verified during planning (2026-09-08): the live `ingested_products` table has 613 rows spanning 2026-09-02 to 2026-09-03 (the NWWS pipe is not currently running — no new data since), and a full scan of every distinct 3-letter `awipsid` prefix in that table shows zero `swo`/`mcd`/`fwo`/`mpd` (SPC/WPC) products. `AFD`'s prefix (`afd`, 14 real rows) is the only non-VTEC prefix confirmed. Building a crosswalk entry or a risk-level text parser for SPC/WPC now would mean guessing an unverified format — exactly what the design doc says not to do. `SpcWpc`-category `AlertRule`s can still be created via the existing UI; they simply won't ever match until a follow-up plan confirms real SPC/WPC prefixes and risk-level text format and extends `ExtractProductMetadata`'s crosswalk. This plan implements `WeatherAlerts` and `ForecastDiscussion` fully.

---

## File Structure

**New:**
- `database/migrations/2026_09_08_000001_add_weather_alert_metadata_to_ingested_products_table.php`
- `database/migrations/2026_09_08_000002_add_zone_and_timezone_to_locations_table.php`
- `app/Actions/Pipeline/ExtractProductMetadata.php`
- `app/Actions/Weather/ResolveLocationZones.php`
- `app/Actions/Weather/MatchWeatherAlertRules.php`
- `app/Jobs/DeliverWeatherAlert.php`
- `app/Observers/IngestedProductObserver.php`
- `app/Observers/LocationZoneObserver.php`
- `tests/Feature/ExtractProductMetadataTest.php`
- `tests/Feature/ResolveLocationZonesTest.php`
- `tests/Feature/MatchWeatherAlertRulesTest.php`
- `tests/Feature/DeliverWeatherAlertTest.php`

**Modified:**
- `app/Models/IngestedProduct.php` — add `product_id`/`ugc_codes`/`polygon` to `Fillable` + casts, add `product()` relation.
- `app/Models/Location.php` — add `timezone` to `Fillable` (the three zone-UGC columns are internal-only, never mass-assigned).
- `app/Http/Controllers/IngestNwwsProductController.php` — call `ExtractProductMetadata` before `updateOrCreate`.
- `app/Providers/AppServiceProvider.php` — register `IngestedProductObserver` and `LocationZoneObserver`.
- `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php` — add `TimezoneSelect`.
- `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` — replace `countyZoneField()` with a real picker, add WFO auto-suggestion.
- `tests/Feature/LocationResourceTest.php` — add timezone field test.
- `tests/Feature/AlertRuleResourceTest.php` — add CountyZone picker + WFO auto-suggestion tests.
- `composer.json` — add `tapp/filament-timezone-field`.

**Not touched:** `Threshold`/`DailySummary`/`Lightning` categories (separate designs per the spec's Context section), `AlertCategory::SpcWpc` matching (see Scope decision above), `app/Libraries/AwipsProduct.php` (dead code, referenced only for its real polygon-decode math, not modified or reused directly).

---

## Task 1: Schema — new columns on `ingested_products` and `locations`

**Files:**
- Create: `database/migrations/2026_09_08_000001_add_weather_alert_metadata_to_ingested_products_table.php`
- Create: `database/migrations/2026_09_08_000002_add_zone_and_timezone_to_locations_table.php`
- Modify: `app/Models/IngestedProduct.php`
- Modify: `app/Models/Location.php`

This task is schema-only (no behavior yet), so there's no failing-test step — verify via `php artisan migrate` succeeding and a quick Tinker round-trip instead.

- [ ] **Step 1: Write the `ingested_products` migration**

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
            $table->foreignId('product_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->json('ugc_codes')->nullable()->after('text');
            $table->json('polygon')->nullable()->after('ugc_codes');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['ugc_codes', 'polygon']);
        });
    }
};
```

- [ ] **Step 2: Write the `locations` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('county_ugc')->nullable()->after('lng');
            $table->string('forecast_zone_ugc')->nullable()->after('county_ugc');
            $table->string('fire_weather_zone_ugc')->nullable()->after('forecast_zone_ugc');
            $table->string('timezone')->nullable()->after('fire_weather_zone_ugc');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn(['county_ugc', 'forecast_zone_ugc', 'fire_weather_zone_ugc', 'timezone']);
        });
    }
};
```

- [ ] **Step 3: Run the migration**

Run (this project uses Sail/Docker — host-level `php artisan` fails with a DNS error resolving `pgsql`):
```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate"
```
Expected: both new migrations run successfully.

- [ ] **Step 4: Update `IngestedProduct`**

Edit `app/Models/IngestedProduct.php`:

```php
<?php

namespace App\Models;

use Database\Factories\IngestedProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'received_at', 'product_id', 'ugc_codes', 'polygon'])]
class IngestedProduct extends Model
{
    /** @use HasFactory<IngestedProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'ugc_codes' => 'array',
            'polygon' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ...parseFilename() unchanged, keep as-is...
}
```

Keep the existing `parseFilename()` method exactly as-is — only the class-level `#[Fillable]`, `casts()`, and the new `product()` relation change.

- [ ] **Step 5: Update `Location`**

Edit `app/Models/Location.php` — change only the `#[Fillable]` line:

```php
#[Fillable(['user_id', 'label', 'type', 'address', 'lat', 'lng', 'timezone'])]
```

`county_ugc`/`forecast_zone_ugc`/`fire_weather_zone_ugc` are deliberately **not** added to `Fillable` — they're never mass-assigned from a form, only written internally by `ResolveLocationZones` (Task 6) via `forceFill()`.

- [ ] **Step 6: Verify with Tinker**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute='
\$p = App\Models\IngestedProduct::factory()->create([\"ugc_codes\" => [\"NYZ001\"], \"polygon\" => [[\"lat\" => 40.1, \"lon\" => -80.1]]]);
echo \$p->ugc_codes[0].PHP_EOL;
echo \$p->polygon[0][\"lat\"].PHP_EOL;
'"
```
Expected: prints `NYZ001` then `40.1` — confirms the `array` casts round-trip correctly.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_08_000001_add_weather_alert_metadata_to_ingested_products_table.php database/migrations/2026_09_08_000002_add_zone_and_timezone_to_locations_table.php app/Models/IngestedProduct.php app/Models/Location.php
git commit -m "$(cat <<'EOF'
Add Weather Alert Engine schema: ingested_products enrichment + location zones/timezone

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `ExtractProductMetadata` — VTEC and AFD-prefix → `product_id`

**Files:**
- Create: `app/Actions/Pipeline/ExtractProductMetadata.php`
- Test: `tests/Feature/ExtractProductMetadataTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\Pipeline\ExtractProductMetadata;
use App\Models\Product;

test('a VTEC-tagged product resolves product_id from its phenomena and significance codes', function () {
    $tornadoWarning = Product::factory()->create(['code' => 'TO.W']);
    $text = <<<'TEXT'
    WFUS51 KOKX 061800
    TORKOKX

    BULLETIN - EAS ACTIVATION REQUESTED
    Tornado Warning
    National Weather Service New York NY
    200 PM EDT Sun Sep 6 2026

    /O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, 'torokx');

    expect($metadata['product_id'])->toBe($tornadoWarning->id);
});

test('a product with two VTEC lines resolves product_id from the first one', function () {
    $tornadoWarning = Product::factory()->create(['code' => 'TO.W']);
    Product::factory()->create(['code' => 'SV.W']);
    $text = <<<'TEXT'
    /O.CAN.KOKX.SV.W.0041.000000T0000Z-260906T1800Z/
    /O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['product_id'])->toBe($tornadoWarning->id);
});

test('an AFD product resolves product_id via its AWIPS-ID prefix, not VTEC', function () {
    $afd = Product::factory()->create(['code' => 'AFD']);
    $text = "AREA FORECAST DISCUSSION\nNational Weather Service New York NY\n200 PM EDT Sun Sep 6 2026";

    $metadata = (new ExtractProductMetadata)($text, 'afdokx');

    expect($metadata['product_id'])->toBe($afd->id);
});

test('a product with neither a VTEC line nor a confirmed AWIPS-ID prefix resolves no product_id', function () {
    // e.g. real RWR* river/water-resources chatter, confirmed abundant in
    // live NWWS traffic and correctly outside the product catalog.
    $metadata = (new ExtractProductMetadata)('RIVER STAGE DATA...', 'rwrokx');

    expect($metadata['product_id'])->toBeNull();
});

test('a VTEC phenomena+significance with no matching catalog product resolves no product_id', function () {
    $text = '/O.NEW.KOKX.ZZ.Q.0001.260906T1800Z-260906T1900Z/';

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['product_id'])->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php"
```
Expected: FAIL — `Class "App\Actions\Pipeline\ExtractProductMetadata" not found`.

- [ ] **Step 3: Implement `ExtractProductMetadata` (VTEC/AFD portion only)**

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\Product;

/**
 * Extracts VTEC-resolved product_id, UGC zone/county codes, and a parsed
 * severe-weather polygon from a raw NWWS product's text, for storage on
 * IngestedProduct at ingest time (see IngestNwwsProductController).
 *
 * Non-VTEC AWIPS-ID prefix matching only covers AFD today. SPC/WPC prefixes
 * (SPC.OUTLOOK/SPC.MD/SPC.FIRE/WPC.MPD) were unconfirmed against real live
 * NWWS traffic at planning time (a 613-row live sample had zero SPC/WPC
 * products) and are deliberately left out rather than guessed — see the
 * design doc's "Known gaps" section and this plan's "Scope decision".
 * AlertCategory::SpcWpc rules can still be created; they just won't resolve
 * a product_id or match until this crosswalk is extended with confirmed
 * prefixes.
 */
class ExtractProductMetadata
{
    private const AWIPS_PREFIX_CROSSWALK = [
        'afd' => 'AFD',
    ];

    /**
     * @return array{product_id: ?int, ugc_codes: ?array<int, string>, polygon: ?array<int, array{lat: float, lon: float}>}
     */
    public function __invoke(string $text, ?string $awipsid): array
    {
        return [
            'product_id' => $this->resolveProductId($text, $awipsid),
            'ugc_codes' => null,
            'polygon' => null,
        ];
    }

    private function resolveProductId(string $text, ?string $awipsid): ?int
    {
        if (preg_match('/\/[A-Z]\.[A-Z]{3}\.[A-Z]{4}\.([A-Z]{2})\.([A-Z])\.\d{4}\.\d{6}T\d{4}Z-\d{6}T\d{4}Z\//', $text, $matches)) {
            $product = Product::where('code', "{$matches[1]}.{$matches[2]}")->first();

            if ($product !== null) {
                return $product->id;
            }
        }

        $prefix = $awipsid !== null ? substr(strtolower($awipsid), 0, 3) : null;
        $code = $prefix !== null ? (self::AWIPS_PREFIX_CROSSWALK[$prefix] ?? null) : null;

        return $code !== null ? Product::where('code', $code)->first()?->id : null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php"
```
Expected: PASS (5/5).

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Pipeline/ExtractProductMetadata.php tests/Feature/ExtractProductMetadataTest.php
git commit -m "$(cat <<'EOF'
Add ExtractProductMetadata: VTEC and AFD-prefix product_id resolution

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `ExtractProductMetadata` — UGC zone/county code parsing

**Files:**
- Modify: `app/Actions/Pipeline/ExtractProductMetadata.php`
- Modify: `tests/Feature/ExtractProductMetadataTest.php`

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/ExtractProductMetadataTest.php`:

```php
test('a UGC line with a range and a repeated-prefix code expands into individual zone codes', function () {
    $text = <<<'TEXT'
    NYZ069-070>075-081200-
    /O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['ugc_codes'])->toBe(['NYZ069', 'NYZ070', 'NYZ071', 'NYZ072', 'NYZ073', 'NYZ074', 'NYZ075']);
});

test('a UGC line mixing a county code and a zone code with a shared purge time parses both', function () {
    $text = "NYC061-NJZ103-081200-\n";

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['ugc_codes'])->toBe(['NYC061', 'NJZ103']);
});

test('a product with no UGC line resolves no ugc_codes', function () {
    $metadata = (new ExtractProductMetadata)("AREA FORECAST DISCUSSION\nNo UGC line here.", null);

    expect($metadata['ugc_codes'])->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php"
```
Expected: the 3 new tests FAIL (`ugc_codes` is always `null`); the 5 from Task 2 still PASS.

- [ ] **Step 3: Implement UGC parsing**

In `app/Actions/Pipeline/ExtractProductMetadata.php`, change the `__invoke()` body and add the new private method:

```php
    public function __invoke(string $text, ?string $awipsid): array
    {
        return [
            'product_id' => $this->resolveProductId($text, $awipsid),
            'ugc_codes' => $this->parseUgcCodes($text),
            'polygon' => null,
        ];
    }
```

```php
    /**
     * Standard NWS UGC line: dash-separated zone/county codes, immediately
     * before the VTEC line, terminated by a 6-digit DDHHMM purge timestamp
     * (e.g. "NYZ069-070>075-081200-"). A bare 3-digit token continues the
     * most recently seen state+type prefix; a "070>075" token expands to
     * every code in that numeric range under the current prefix.
     *
     * @return ?array<int, string>
     */
    private function parseUgcCodes(string $text): ?array
    {
        if (! preg_match('/^((?:[A-Z]{2}[CZ]\d{3}|\d{3}(?:>\d{3})?)(?:-(?:[A-Z]{2}[CZ]\d{3}|\d{3}(?:>\d{3})?))*)-\d{6}-\s*$/m', $text, $matches)) {
            return null;
        }

        $codes = [];
        $prefix = null;

        foreach (explode('-', $matches[1]) as $token) {
            if (preg_match('/^([A-Z]{2}[CZ])(\d{3})$/', $token, $m)) {
                $prefix = $m[1];
                $codes[] = "{$prefix}{$m[2]}";
            } elseif ($prefix !== null && preg_match('/^(\d{3})>(\d{3})$/', $token, $m)) {
                foreach (range((int) $m[1], (int) $m[2]) as $n) {
                    $codes[] = $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
                }
            } elseif ($prefix !== null && preg_match('/^\d{3}$/', $token)) {
                $codes[] = $prefix.$token;
            }
        }

        return $codes === [] ? null : $codes;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php"
```
Expected: PASS (8/8).

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Pipeline/ExtractProductMetadata.php tests/Feature/ExtractProductMetadataTest.php
git commit -m "$(cat <<'EOF'
Add UGC zone/county code parsing to ExtractProductMetadata

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `ExtractProductMetadata` — polygon parsing

**Files:**
- Modify: `app/Actions/Pipeline/ExtractProductMetadata.php`
- Modify: `tests/Feature/ExtractProductMetadataTest.php`

The decode math below is verified against the real reference implementation, `app/Libraries/AwipsProduct::getAffectedAreaCoords()` (lines 399–466 of that file) — a 4-digit value inserts the decimal after 2 digits, a 5-digit value after 3, and longitude is always negated.

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/ExtractProductMetadataTest.php`:

```php
test('a LAT...LON polygon line decodes 4-digit coordinate pairs into real lat/lon', function () {
    $text = <<<'TEXT'
    LAT...LON 3982 8066 3989 8064 3992 8047 3978 8050
    TIME...MOT...LOC 1800Z 123 456
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['polygon'])->toBe([
        ['lat' => 39.82, 'lon' => -80.66],
        ['lat' => 39.89, 'lon' => -80.64],
        ['lat' => 39.92, 'lon' => -80.47],
        ['lat' => 39.78, 'lon' => -80.50],
    ]);
});

test('a LAT...LON line wrapping onto a continuation line is fully collected', function () {
    $text = <<<'TEXT'
    LAT...LON 3982 8066 3989 8064
    3992 8047 3978 8050

    TEXT;

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['polygon'])->toHaveCount(4);
});

test('a 5-digit coordinate part decodes with the decimal after 3 digits, for longitudes past 100 degrees', function () {
    $text = "LAT...LON 4046 10066 4050 10070 4048 10040\n";

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['polygon'][0])->toBe(['lat' => 40.46, 'lon' => -100.66]);
});

test('a product with no LAT...LON line resolves no polygon', function () {
    $metadata = (new ExtractProductMetadata)("AREA FORECAST DISCUSSION\nNo polygon here.", null);

    expect($metadata['polygon'])->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php"
```
Expected: the 4 new tests FAIL (`polygon` is always `null`); the 8 prior tests still PASS.

- [ ] **Step 3: Implement polygon parsing**

In `app/Actions/Pipeline/ExtractProductMetadata.php`, update `__invoke()`:

```php
    public function __invoke(string $text, ?string $awipsid): array
    {
        return [
            'product_id' => $this->resolveProductId($text, $awipsid),
            'ugc_codes' => $this->parseUgcCodes($text),
            'polygon' => $this->parsePolygon($text),
        ];
    }
```

Add:

```php
    /**
     * Finds a "LAT...LON" line, collects wrapped continuation lines until a
     * blank line or a "TIME..." line, and decodes each 4-or-5-digit
     * coordinate pair. Doesn't pre-filter by product type (the legacy
     * reference implementation gates this on a fixed list of AWIPS product
     * types) — scanning every product's text for the line and returning
     * null when absent is simpler and has the same practical effect, since
     * most VTEC products never carry one.
     *
     * @return ?array<int, array{lat: float, lon: float}>
     */
    private function parsePolygon(string $text): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $collecting = false;
        $numbers = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (! $collecting) {
                if (! str_starts_with($trimmed, 'LAT...LON')) {
                    continue;
                }

                $collecting = true;
                $trimmed = trim(substr($trimmed, strlen('LAT...LON')));
            } elseif ($trimmed === '' || str_starts_with($trimmed, 'TIME...')) {
                break;
            }

            if (preg_match_all('/\d{4,5}/', $trimmed, $matches)) {
                array_push($numbers, ...$matches[0]);
            }
        }

        if (count($numbers) < 6 || count($numbers) % 2 !== 0) {
            return null;
        }

        return collect(array_chunk($numbers, 2))
            ->map(fn (array $pair): array => [
                'lat' => self::decodeCoordinatePart($pair[0]),
                'lon' => -self::decodeCoordinatePart($pair[1]),
            ])
            ->all();
    }

    /**
     * Mirrors App\Libraries\AwipsProduct::getAffectedAreaCoords()'s real
     * decode math: a 4-digit value inserts the decimal after 2 digits
     * (e.g. "3982" -> 39.82), a 5-digit value inserts it after 3 (e.g.
     * "10066" -> 100.66 -- needed for longitudes >= 100 degrees).
     */
    private static function decodeCoordinatePart(string $raw): float
    {
        return strlen($raw) === 5
            ? (float) (substr($raw, 0, 3).'.'.substr($raw, 3))
            : (float) (substr($raw, 0, 2).'.'.substr($raw, 2));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ExtractProductMetadataTest.php"
```
Expected: PASS (12/12).

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Pipeline/ExtractProductMetadata.php tests/Feature/ExtractProductMetadataTest.php
git commit -m "$(cat <<'EOF'
Add polygon parsing to ExtractProductMetadata

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Wire ingest-time enrichment and the match-dispatch observer

**Files:**
- Modify: `app/Http/Controllers/IngestNwwsProductController.php`
- Create: `app/Observers/IngestedProductObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: create `tests/Feature/IngestNwwsProductControllerTest.php` if one doesn't already exist covering this controller — check first with `ls tests/Feature/IngestNwwsProductControllerTest.php`; if it exists, add to it instead of creating a new file.

- [ ] **Step 1: Check for an existing controller test**

```bash
ls /Users/jimmy/Documents/WxNotify/wxnotify-dashboard/tests/Feature/IngestNwwsProductControllerTest.php 2>&1
```
If it exists, read it first and add the two tests below into it, matching its existing setup (fixture directory, `config(['services.pipeline.products_path' => ...])`, etc.) instead of duplicating that setup. If it doesn't exist, create it fresh per Step 2 below (which includes a minimal self-contained setup).

- [ ] **Step 2: Write the failing tests**

```php
<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->root = storage_path('app/test-fixtures/nwws-ingest-'.uniqid());
    File::ensureDirectoryExists($this->root);
    config(['services.pipeline.products_path' => $this->root]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('ingesting a VTEC product enriches product_id/ugc_codes/polygon before the row is written', function () {
    Product::factory()->create(['code' => 'TO.W']);
    $text = "NYZ069-081200-\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\nLAT...LON 3982 8066 3989 8064 3992 8047\n";
    File::put("{$this->root}/kokx_wfus51-torokx.061800_1.txt", $text);

    $this->postJson('/api/pipeline/ingest', ['path' => 'kokx_wfus51-torokx.061800_1.txt'])
        ->assertNoContent();

    $product = IngestedProduct::sole();

    expect($product->product_id)->not->toBeNull()
        ->and($product->ugc_codes)->toBe(['NYZ069'])
        ->and($product->polygon)->toHaveCount(3);
});

test('a newly ingested product dispatches MatchWeatherAlertRules exactly once', function () {
    Queue::fake();
    // MatchWeatherAlertRules itself is synchronous (see Task 8) — asserting
    // its effect here would require real AlertRule fixtures, which belongs
    // in MatchWeatherAlertRulesTest. This test only proves the observer
    // wiring fires on create, not on the later `updateOrCreate` update path,
    // by checking a DeliveryLog side effect is impossible to fake cheaply
    // here; instead assert indirectly via a spy-like count using Log.
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);

    $text = "AREA FORECAST DISCUSSION\n";
    File::put("{$this->root}/okx_fxus61-afdokx.061800_1.txt", $text);

    $this->postJson('/api/pipeline/ingest', ['path' => 'okx_fxus61-afdokx.061800_1.txt'])
        ->assertNoContent();

    $spy->shouldHaveReceived('__invoke')->once();

    // Re-posting the same path hits updateOrCreate's update branch, not
    // create — confirms the observer is bound to `created`, not a generic
    // save hook, matching the design's "only fires once per real product".
    $this->postJson('/api/pipeline/ingest', ['path' => 'okx_fxus61-afdokx.061800_1.txt'])
        ->assertNoContent();

    $spy->shouldHaveReceived('__invoke')->once();
});
```

Before writing these, confirm the real route path/name for `IngestNwwsProductController` — check `php artisan route:list` (via docker exec) rather than assuming `/api/pipeline/ingest`:
```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan route:list" | grep -i ingest
```
Use the real path/method printed there in place of `/api/pipeline/ingest` above.

- [ ] **Step 3: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestNwwsProductControllerTest.php"
```
Expected: FAIL — `product_id` stays null (no enrichment wired yet) and the `MatchWeatherAlertRules` spy is never called.

- [ ] **Step 4: Wire `ExtractProductMetadata` into the controller**

Replace `app/Http/Controllers/IngestNwwsProductController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Pipeline\ExtractProductMetadata;
use App\Models\IngestedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class IngestNwwsProductController extends Controller
{
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
}
```

- [ ] **Step 5: Create the observer**

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

This references `App\Actions\Weather\MatchWeatherAlertRules`, which doesn't exist until Task 8. Create a minimal placeholder now so this task's tests can run in isolation, to be fully implemented in Task 8:

```php
<?php

namespace App\Actions\Weather;

use App\Models\IngestedProduct;

class MatchWeatherAlertRules
{
    public function __invoke(IngestedProduct $product): void
    {
        // Implemented in Task 8.
    }
}
```

- [ ] **Step 6: Register the observer**

In `app/Providers/AppServiceProvider.php`, alongside the existing `Activity::observe(...)`/`User::observe(...)` lines in `boot()`, add:

```php
use App\Models\IngestedProduct;
use App\Observers\IngestedProductObserver;
```

```php
IngestedProduct::observe(IngestedProductObserver::class);
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestNwwsProductControllerTest.php"
```
Expected: PASS (2/2).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/IngestNwwsProductController.php app/Observers/IngestedProductObserver.php app/Actions/Weather/MatchWeatherAlertRules.php app/Providers/AppServiceProvider.php tests/Feature/IngestNwwsProductControllerTest.php
git commit -m "$(cat <<'EOF'
Wire ExtractProductMetadata into ingest and dispatch MatchWeatherAlertRules on create

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: `ResolveLocationZones` — local PostGIS zone/county resolution

**Files:**
- Create: `app/Actions/Weather/ResolveLocationZones.php`
- Create: `app/Observers/LocationZoneObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/ResolveLocationZonesTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\Weather\ResolveLocationZones;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

function insertTestZonePolygon(string $table, array $attributes, string $wkt): void
{
    DB::table($table)->insert([
        ...$attributes,
        'lon' => 0, 'lat' => 0,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('{$wkt}')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    // A 1x1-degree square roughly over eastern New York, real enough to
    // contain a test point without colliding with any other fixture.
    insertTestZonePolygon('public_zones', [
        'state' => 'NY', 'cwa' => 'ALY', 'zone' => '001', 'name' => 'Test Zone',
        'state_zone' => 'NY001',
    ], 'POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))');

    insertTestZonePolygon('counties', [
        'state' => 'NY', 'cwa' => 'ALY', 'countyname' => 'Test County', 'fips' => '36001',
    ], 'POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))');

    insertTestZonePolygon('fire_zones', [
        'state' => 'NY', 'cwa' => 'ALY', 'zone' => '001', 'name' => 'Test Fire Zone',
        'state_zone' => 'NY001',
    ], 'POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))');
});

test('a location inside the fixture polygons resolves all three UGC codes', function () {
    $location = Location::factory()->create(['lat' => 42.5, 'lng' => -73.5]);

    (new ResolveLocationZones)($location);

    expect($location->forecast_zone_ugc)->toBe('NYZ001')
        ->and($location->county_ugc)->toBe('NYC001')
        ->and($location->fire_weather_zone_ugc)->toBe('NYZ001');
});

test('a location outside every fixture polygon resolves all three codes to null', function () {
    $location = Location::factory()->create(['lat' => 0.0, 'lng' => 0.0]);

    (new ResolveLocationZones)($location);

    expect($location->forecast_zone_ugc)->toBeNull()
        ->and($location->county_ugc)->toBeNull()
        ->and($location->fire_weather_zone_ugc)->toBeNull();
});

test('the resolved codes are persisted, not just set on the in-memory model', function () {
    $location = Location::factory()->create(['lat' => 42.5, 'lng' => -73.5]);

    (new ResolveLocationZones)($location);

    expect($location->fresh()->county_ugc)->toBe('NYC001');
});

test('changing a location\'s coordinates invalidates its previously resolved zones', function () {
    $location = Location::factory()->create(['lat' => 42.5, 'lng' => -73.5]);
    (new ResolveLocationZones)($location);
    expect($location->fresh()->county_ugc)->toBe('NYC001');

    $location->update(['lat' => 0.0, 'lng' => 0.0]);

    expect($location->fresh()->county_ugc)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ResolveLocationZonesTest.php"
```
Expected: FAIL — `Class "App\Actions\Weather\ResolveLocationZones" not found`.

- [ ] **Step 3: Implement `ResolveLocationZones`**

```php
<?php

namespace App\Actions\Weather;

use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a Location's forecast zone, county, and fire weather zone UGC
 * codes locally via PostGIS ST_Contains, against the AWIPS Shapefile
 * Management feature's public_zones/counties/fire_zones tables — replacing
 * what the original design sourced from the external api.weather.gov/points
 * API. Shapefile geometry is stored SRID 4269 (NAD83); the query point is
 * built directly in that SRID rather than transformed from 4326, since the
 * datum difference is sub-meter and irrelevant at zone-polygon scale.
 *
 * Each table's natural key isn't itself in NWS UGC format: public_zones and
 * fire_zones store state_zone as e.g. "NY001" (no type letter), and counties
 * has no zone-letter code at all, only a 5-digit FIPS. Real UGC codes
 * ("NYZ001"/"NYC001") are derived here from the state + zone/fips columns.
 */
class ResolveLocationZones
{
    public function __invoke(Location $location): void
    {
        $lng = (float) $location->lng;
        $lat = (float) $location->lat;

        $location->forceFill([
            'forecast_zone_ugc' => $this->resolveZoneUgc('public_zones', $lng, $lat),
            'county_ugc' => $this->resolveCountyUgc($lng, $lat),
            'fire_weather_zone_ugc' => $this->resolveZoneUgc('fire_zones', $lng, $lat),
        ])->save();
    }

    private function resolveZoneUgc(string $table, float $lng, float $lat): ?string
    {
        $row = DB::selectOne(
            "SELECT state, zone FROM {$table} WHERE ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4269)) LIMIT 1",
            [$lng, $lat],
        );

        return $row === null ? null : strtoupper($row->state).'Z'.$row->zone;
    }

    private function resolveCountyUgc(float $lng, float $lat): ?string
    {
        $row = DB::selectOne(
            'SELECT state, fips FROM counties WHERE ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4269)) LIMIT 1',
            [$lng, $lat],
        );

        return $row === null ? null : strtoupper($row->state).'C'.substr($row->fips, -3);
    }
}
```

`$table` is interpolated directly into the SQL string in `resolveZoneUgc()` — safe here since it's only ever called with the two hardcoded literals `'public_zones'`/`'fire_zones'` from within this same class, never external input, matching the existing raw-SQL convention already used in `app/Actions/Shapefiles/ImportShapefileVersion.php`.

- [ ] **Step 4: Create `LocationZoneObserver`**

```php
<?php

namespace App\Observers;

use App\Models\Location;

/**
 * Nulls the cached zone/county UGC codes whenever a Location's coordinates
 * change, so MatchWeatherAlertRules's lazy ResolveLocationZones call
 * re-resolves them against the new point rather than silently matching
 * against a stale zone after a subscriber moves their pin.
 */
class LocationZoneObserver
{
    public function updating(Location $location): void
    {
        if ($location->isDirty(['lat', 'lng'])) {
            $location->forecast_zone_ugc = null;
            $location->county_ugc = null;
            $location->fire_weather_zone_ugc = null;
        }
    }
}
```

- [ ] **Step 5: Register the observer**

In `app/Providers/AppServiceProvider.php`, add alongside the others:

```php
use App\Models\Location;
use App\Observers\LocationZoneObserver;
```

```php
Location::observe(LocationZoneObserver::class);
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ResolveLocationZonesTest.php"
```
Expected: PASS (4/4).

- [ ] **Step 7: Commit**

```bash
git add app/Actions/Weather/ResolveLocationZones.php app/Observers/LocationZoneObserver.php app/Providers/AppServiceProvider.php tests/Feature/ResolveLocationZonesTest.php
git commit -m "$(cat <<'EOF'
Add ResolveLocationZones: local PostGIS zone/county resolution for locations

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Subscriber-facing timezone field on `LocationForm`

**Files:**
- Modify: `composer.json` (via `composer require`)
- Modify: `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php`
- Modify: `tests/Feature/LocationResourceTest.php`

- [ ] **Step 1: Install the package**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && composer require tapp/filament-timezone-field"
```
Expected: installs successfully (confirmed compatible with Filament 5.x during planning).

- [ ] **Step 2: Write the failing test**

Append to `tests/Feature/LocationResourceTest.php`:

```php
test('creating a location persists an explicitly chosen timezone', function () {
    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'Home',
            'lat' => 39.8283,
            'lng' => -98.5795,
            'address' => 'Somewhere, KS',
            'timezone' => 'America/Chicago',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $location = Location::where('label', 'Home')->sole();

    expect($location->timezone)->toBe('America/Chicago');
});

test('creating a location without a timezone is rejected', function () {
    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'Home',
            'lat' => 39.8283,
            'lng' => -98.5795,
        ])
        ->call('create')
        ->assertHasFormErrors(['timezone']);
});
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/LocationResourceTest.php"
```
Expected: FAIL — `timezone` isn't a known form field yet.

- [ ] **Step 4: Add the field to `LocationForm`**

Edit `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\Locations\Schemas;

use App\Enums\LocationType;
use Fahiem\FilamentPinpoint\Pinpoint;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Tapp\FilamentTimezoneField\Forms\Components\TimezoneSelect;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(self::fields());
    }

    /**
     * @return array<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('label')
                ->label('Name this location')
                ->placeholder("Home, Work, Mom's house…")
                ->required()
                ->maxLength(255),
            Hidden::make('type')
                ->default(LocationType::Stationary),
            Pinpoint::make('coordinates')
                ->label('Location')
                ->provider('leaflet')
                ->latField('lat')
                ->lngField('lng')
                ->addressField('address')
                ->dehydrated(false),
            Hidden::make('lat')
                ->required(),
            Hidden::make('lng')
                ->required(),
            TextInput::make('address')
                ->label('Resolved address')
                ->readOnly()
                ->helperText('Filled in automatically from the map above — search, drag the marker, or use your current location.'),
            TimezoneSelect::make('timezone')
                ->label('Timezone')
                ->searchable()
                ->getTimezoneFromBrowser()
                ->required()
                ->helperText('Defaults to your browser\'s detected timezone — change it if this location is somewhere else (e.g. a relative\'s house). Used for this location\'s quiet hours.'),
        ];
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/LocationResourceTest.php"
```
Expected: PASS (all tests in the file, including the pre-existing ones).

- [ ] **Step 6: Format and commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add composer.json composer.lock app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php tests/Feature/LocationResourceTest.php
git commit -m "$(cat <<'EOF'
Add a subscriber-editable timezone field to LocationForm

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: `MatchWeatherAlertRules` — Nationwide/Wfo/ForecastDiscussion + quiet hours + `DeliveryLog`

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php` (replace the Task 5 placeholder)
- Create: `tests/Feature/MatchWeatherAlertRulesTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('a nationwide weather alerts rule matches any product referencing its product', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
    ]);
    $rule->products()->attach($product);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $rule->notificationChannels()->attach($channel);
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class, fn ($job) => $job->ruleId === $rule->id && $job->channelId === $channel->id && $job->ingestedProductId === $ingested->id);
});

test('a weather alerts rule for a different product never matches', function () {
    Queue::fake();
    $tornado = Product::factory()->create(['code' => 'TO.W']);
    $flood = Product::factory()->create(['code' => 'FF.W']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach($flood);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $tornado->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a Wfo-mode rule matches on the issuing office, not the product', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'OKX', 'location_id' => null,
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id, 'cccc' => 'KOKX']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Wfo-mode rule for a different office never matches', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'BUF', 'location_id' => null]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id, 'cccc' => 'KOKX']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a forecast discussion rule matches by wfo alone, with no product filter', function () {
    Queue::fake();
    $rule = AlertRule::factory()->create([
        'category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'OKX', 'location_id' => null,
    ]);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => null, 'cccc' => 'KOKX']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('an inactive rule never matches', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null, 'is_active' => false]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('an spc_wpc rule never matches, since no product resolves that category yet', function () {
    // Documents the deliberate v1 scope decision (see plan header) rather
    // than leaving it an untested silent gap.
    Queue::fake();
    $outlook = Product::factory()->create(['code' => 'SPC.OUTLOOK']);
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $outlook->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a matched rule within its quiet hours window is suppressed and logged, not dispatched', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $location = App\Models\Location::factory()->create(['timezone' => 'America/New_York']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id,
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $rule->products()->attach($product);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $rule->notificationChannels()->attach($channel);
    // Location mode also needs ugc_codes overlap — see Task 9 for the real
    // geometry path; here the rule matches trivially because both the
    // location's forecast_zone_ugc and the product's ugc_codes already agree.
    $location->forceFill(['forecast_zone_ugc' => 'NYZ072'])->save();
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    $log = DeliveryLog::sole();
    expect($log->status)->toBe('suppressed')
        ->and($log->alert_rule_id)->toBe($rule->id)
        ->and($log->notification_channel_id)->toBe($channel->id);
});

test('a nationwide rule with quiet hours set always delivers, since it has no location to resolve a timezone from', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59',
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php"
```
Expected: FAIL — the Task 5 placeholder does nothing.

- [ ] **Step 3: Implement `MatchWeatherAlertRules` (Nationwide/Wfo/ForecastDiscussion + quiet hours only for now)**

Replace `app/Actions/Weather/MatchWeatherAlertRules.php`:

```php
<?php

namespace App\Actions\Weather;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Matches a newly ingested NWWS product against active AlertRules and
 * dispatches DeliverWeatherAlert per matched rule x notification channel.
 * Mirrors App\Actions\Visibility\MatchActivityAlertRules's shape.
 *
 * AlertCategory::SpcWpc is deliberately excluded from the category filter —
 * see this feature's plan document's "Scope decision" for why.
 */
class MatchWeatherAlertRules
{
    public function __invoke(IngestedProduct $product): void
    {
        $categories = [AlertCategory::ForecastDiscussion];
        if ($product->product_id !== null) {
            $categories[] = AlertCategory::WeatherAlerts;
        }

        try {
            $rules = AlertRule::query()
                ->where('is_active', true)
                ->whereIn('category', $categories)
                ->where(function (Builder $query) use ($product) {
                    $query->where('category', AlertCategory::ForecastDiscussion)
                        ->orWhere(fn (Builder $q) => $q->where('category', AlertCategory::WeatherAlerts)
                            ->whereHas('products', fn (Builder $q) => $q->whereKey($product->product_id)));
                })
                ->get()
                ->filter(fn (AlertRule $rule): bool => $this->matchesGeography($rule, $product));
        } catch (Throwable $e) {
            logger()->error('Failed to query matching AlertRules', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $rules->each(function (AlertRule $rule) use ($product): void {
            $this->dispatchOrSuppress($rule, $product);
        });
    }

    private function dispatchOrSuppress(AlertRule $rule, IngestedProduct $product): void
    {
        $suppressed = $this->isWithinQuietHours($rule);

        $rule->notificationChannels->each(function (NotificationChannel $channel) use ($rule, $product, $suppressed): void {
            try {
                if ($suppressed) {
                    DeliveryLog::create([
                        'alert_rule_id' => $rule->id,
                        'notification_channel_id' => $channel->id,
                        'user_id' => $rule->user_id,
                        'channel_type' => $channel->channel_type,
                        'status' => 'suppressed',
                        'dispatched_at' => now(),
                    ]);

                    return;
                }

                DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
            } catch (Throwable $e) {
                logger()->error('Failed to dispatch DeliverWeatherAlert', [
                    'rule_id' => $rule->id,
                    'channel_id' => $channel->id,
                    'ingested_product_id' => $product->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    private function matchesGeography(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->category === AlertCategory::ForecastDiscussion) {
            return $this->matchesWfo($rule->wfo, $product->cccc);
        }

        return match ($rule->matching_mode) {
            MatchingMode::Nationwide => true,
            MatchingMode::Wfo => $this->matchesWfo($rule->wfo, $product->cccc),
            MatchingMode::Location, MatchingMode::CountyZone, MatchingMode::Polygon => false, // implemented in Tasks 9-10
            null => false,
        };
    }

    private function matchesWfo(?string $wfo, ?string $cccc): bool
    {
        if ($wfo === null || $cccc === null) {
            return false;
        }

        return strtoupper($wfo) === strtoupper(substr($cccc, 1));
    }

    /**
     * Nationwide/Wfo/ForecastDiscussion rules have no Location at all —
     * "quiet hours" implicitly means "my nighttime", which has no
     * well-defined answer without one. These always deliver regardless of
     * any quiet_hours_start/end value on the row.
     */
    private function isWithinQuietHours(AlertRule $rule): bool
    {
        if ($rule->quiet_hours_start === null || $rule->quiet_hours_end === null) {
            return false;
        }

        if (! in_array($rule->matching_mode, [MatchingMode::Location, MatchingMode::Polygon, MatchingMode::CountyZone], true)) {
            return false;
        }

        $timezone = $rule->location?->timezone;
        if ($timezone === null) {
            return false;
        }

        $now = Carbon::now($timezone);
        $start = $now->copy()->setTimeFromTimeString($rule->quiet_hours_start);
        $end = $now->copy()->setTimeFromTimeString($rule->quiet_hours_end);

        if ($start->lessThanOrEqualTo($end)) {
            return $now->between($start, $end);
        }

        return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php"
```
Expected: PASS — all tests except the quiet-hours-suppression test, which needs `Location`/`CountyZone` matching (Task 9) to actually match first. Confirm this specific failure mode (not an error) before proceeding.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/MatchWeatherAlertRulesTest.php
git commit -m "$(cat <<'EOF'
Implement MatchWeatherAlertRules: Nationwide/Wfo/ForecastDiscussion + quiet hours

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: `MatchWeatherAlertRules` — `Location`/`CountyZone` matching modes

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php`
- Modify: `tests/Feature/MatchWeatherAlertRulesTest.php`

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/MatchWeatherAlertRulesTest.php`:

```php
test('a Location-mode rule matches when the location\'s resolved zone overlaps the product\'s ugc_codes', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $location = App\Models\Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Location-mode rule with no zone overlap never matches', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $location = App\Models\Location::factory()->create(['forecast_zone_ugc' => 'NYZ999']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a CountyZone-mode rule matches on direct UGC overlap with the stored codes', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'county_zone', 'location_id' => null,
        'county_zone_codes' => 'NYZ072,NYC061',
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id, 'ugc_codes' => ['NYC061']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a CountyZone-mode rule with no overlap never matches', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'county_zone', 'location_id' => null,
        'county_zone_codes' => 'NYZ072',
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id, 'ugc_codes' => ['NJZ103']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});
```

Also un-skip the quiet-hours-suppression test from Task 8 by re-running the full file (it already used `matching_mode => 'location'` and set `forecast_zone_ugc` directly, so it should now pass once `Location` mode is implemented).

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php"
```
Expected: the 4 new tests FAIL; the quiet-hours-suppression test from Task 8 still fails too (same root cause).

- [ ] **Step 3: Implement `Location`/`CountyZone` matching**

In `app/Actions/Weather/MatchWeatherAlertRules.php`, add these imports:

```php
use App\Models\Location;
```

Change the `matchesGeography()` match arm:

```php
        return match ($rule->matching_mode) {
            MatchingMode::Nationwide => true,
            MatchingMode::Wfo => $this->matchesWfo($rule->wfo, $product->cccc),
            MatchingMode::Location => $this->matchesLocationZones($rule, $product),
            MatchingMode::CountyZone => $this->matchesCountyZone($rule, $product),
            MatchingMode::Polygon => false, // implemented in Task 10
            null => false,
        };
```

Add the two new private methods:

```php
    private function matchesLocationZones(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->location === null || $product->ugc_codes === null) {
            return false;
        }

        $location = $this->ensureZonesResolved($rule->location);
        $zones = array_filter([$location->forecast_zone_ugc, $location->county_ugc]);

        return array_intersect($zones, $product->ugc_codes) !== [];
    }

    private function matchesCountyZone(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->county_zone_codes === null || $product->ugc_codes === null) {
            return false;
        }

        $ruleCodes = explode(',', $rule->county_zone_codes);

        return array_intersect($ruleCodes, $product->ugc_codes) !== [];
    }

    private function ensureZonesResolved(Location $location): Location
    {
        if ($location->forecast_zone_ugc !== null || $location->county_ugc !== null) {
            return $location;
        }

        app(ResolveLocationZones::class)($location);

        return $location->refresh();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php"
```
Expected: PASS — all tests in the file so far.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/MatchWeatherAlertRulesTest.php
git commit -m "$(cat <<'EOF'
Implement Location/CountyZone geography matching in MatchWeatherAlertRules

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: `MatchWeatherAlertRules` — `Polygon` matching mode

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php`
- Modify: `tests/Feature/MatchWeatherAlertRulesTest.php`

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/MatchWeatherAlertRulesTest.php`:

```php
test('a Polygon-mode rule matches when the location falls inside the product\'s parsed polygon', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $location = App\Models\Location::factory()->create(['lat' => 40.5, 'lng' => -74.0]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create([
        'product_id' => $product->id,
        'polygon' => [
            ['lat' => 40.0, 'lon' => -74.5], ['lat' => 41.0, 'lon' => -74.5],
            ['lat' => 41.0, 'lon' => -73.5], ['lat' => 40.0, 'lon' => -73.5],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule does not match a location outside the product\'s parsed polygon', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $location = App\Models\Location::factory()->create(['lat' => 10.0, 'lng' => -10.0]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create([
        'product_id' => $product->id,
        'polygon' => [
            ['lat' => 40.0, 'lon' => -74.5], ['lat' => 41.0, 'lon' => -74.5],
            ['lat' => 41.0, 'lon' => -73.5], ['lat' => 40.0, 'lon' => -73.5],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule falls back to Location-style zone matching when the product has no polygon', function () {
    Queue::fake();
    $product = Product::factory()->create(['code' => 'TO.W']);
    $location = App\Models\Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id, 'polygon' => null, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php"
```
Expected: the 3 new tests FAIL (`Polygon` currently always returns `false`).

- [ ] **Step 3: Implement `Polygon` matching**

In `app/Actions/Weather/MatchWeatherAlertRules.php`, add:

```php
use Illuminate\Support\Facades\DB;
```

Change the match arm:

```php
            MatchingMode::Polygon => $this->matchesPolygon($rule, $product) ?? $this->matchesLocationZones($rule, $product),
```

Add:

```php
    /**
     * Returns null (not false) when the product has no polygon, so the
     * caller can fall back to matchesLocationZones() — most VTEC products
     * don't carry a polygon, and a subscriber in Polygon mode shouldn't be
     * silently missed on a warning type that's UGC-only.
     */
    private function matchesPolygon(AlertRule $rule, IngestedProduct $product): ?bool
    {
        if ($product->polygon === null || $rule->location === null) {
            return null;
        }

        $wkt = $this->polygonToWkt($product->polygon);
        $lng = (float) $rule->location->lng;
        $lat = (float) $rule->location->lat;

        $result = DB::selectOne(
            'SELECT ST_Contains(ST_SetSRID(ST_GeomFromText(?), 4269), ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains',
            [$wkt, $lng, $lat],
        );

        return $result !== null && filter_var($result->contains, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<int, array{lat: float, lon: float}> $points
     */
    private function polygonToWkt(array $points): string
    {
        $coords = collect($points)->map(fn (array $p): string => "{$p['lon']} {$p['lat']}");

        if ($coords->first() !== $coords->last()) {
            $coords->push($coords->first()); // WKT polygons must be closed rings
        }

        return 'POLYGON(('.$coords->join(', ').'))';
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php"
```
Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/MatchWeatherAlertRulesTest.php
git commit -m "$(cat <<'EOF'
Implement Polygon geography matching (real PostGIS point-in-polygon) in MatchWeatherAlertRules

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: `DeliverWeatherAlert` job

**Files:**
- Create: `app/Jobs/DeliverWeatherAlert.php`
- Test: `tests/Feature/DeliverWeatherAlertTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Support\Facades\Http;

test('it delivers via apprise and writes a success DeliveryLog row', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning issued for...']);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['urls'] === 'discord://token/id');

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('success')
        ->and($log->error_detail)->toBeNull()
        ->and($log->alert_rule_id)->toBe($rule->id)
        ->and($log->notification_channel_id)->toBe($channel->id)
        ->and($log->channel_type)->toBe($channel->channel_type);
});

test('a non-delivery from apprise writes a failure DeliveryLog row instead of throwing', function () {
    Http::fake(['*/notify/*' => Http::response(status: 204)]);

    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('failure')
        ->and($log->error_detail)->not->toBeNull();
});

test('it does nothing if the rule no longer exists', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert(999999, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
    expect(DeliveryLog::count())->toBe(0);
});

test('it does nothing if the channel no longer exists', function () {
    Http::fake();
    $rule = AlertRule::factory()->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, 999999, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
});

test('it does nothing if the product no longer exists', function () {
    Http::fake();
    $rule = AlertRule::factory()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, 999999))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
});

test('it does nothing if the rule has since been deactivated', function () {
    Http::fake();
    $rule = AlertRule::factory()->create(['is_active' => false]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverWeatherAlertTest.php"
```
Expected: FAIL — `Class "App\Jobs\DeliverWeatherAlert" not found`.

- [ ] **Step 3: Implement the job**

```php
<?php

namespace App\Jobs;

use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Dispatched once per AlertRule x NotificationChannel matched by
 * App\Actions\Weather\MatchWeatherAlertRules. Takes ids rather than model
 * instances so a rule/channel/product deleted between dispatch and
 * processing is a silent, intentional no-op.
 *
 * Unlike App\Jobs\SendActivityAlert, a delivery failure here is a terminal
 * outcome recorded in DeliveryLog, not a retried job — the design doc
 * specifies writing a failure row, not retry-and-throw.
 */
class DeliverWeatherAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly int $ingestedProductId,
    ) {}

    public function handle(AppriseApiClient $client): void
    {
        $rule = AlertRule::find($this->ruleId);
        $channel = NotificationChannel::find($this->channelId);
        $product = IngestedProduct::find($this->ingestedProductId);

        if ($rule === null || $channel === null || $product === null || ! $rule->is_active) {
            return;
        }

        $delivered = $client->notify(
            url: $channel->apprise_url,
            body: Str::limit($product->text, 1500),
            title: $rule->displayName(),
        );

        DeliveryLog::create([
            'alert_rule_id' => $rule->id,
            'notification_channel_id' => $channel->id,
            'user_id' => $rule->user_id,
            'channel_type' => $channel->channel_type,
            'status' => $delivered ? 'success' : 'failure',
            'error_detail' => $delivered ? null : 'Apprise reported non-delivery.',
            'dispatched_at' => now(),
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverWeatherAlertTest.php"
```
Expected: PASS (6/6).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/DeliverWeatherAlert.php tests/Feature/DeliverWeatherAlertTest.php
git commit -m "$(cat <<'EOF'
Add DeliverWeatherAlert job

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: `AlertRuleForm` — real `CountyZone` picker

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`
- Modify: `tests/Feature/AlertRuleResourceTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AlertRuleResourceTest.php`:

```php
test('the CountyZone picker options include real counties and zones for the filtered state', function () {
    DB::table('counties')->insert([
        'state' => 'NY', 'cwa' => 'OKX', 'countyname' => 'Suffolk', 'fips' => '36103',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('public_zones')->insert([
        'state' => 'NY', 'cwa' => 'OKX', 'zone' => '072', 'name' => 'Suffolk', 'state_zone' => 'NY072',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'county_zone',
            'county_zone_state' => 'NY',
            'county_zone_codes' => ['NYC103', 'NYZ072'],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->county_zone_codes)->toBe('NYC103,NYZ072');
});

test('the CountyZone picker requires at least one code', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'county_zone',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['county_zone_codes']);
});
```

Add `use Illuminate\Support\Facades\DB;` to the top of `tests/Feature/AlertRuleResourceTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php"
```
Expected: the 2 new tests FAIL — `county_zone_codes` is currently a plain `TextInput` expecting a string, not an array.

- [ ] **Step 3: Replace `countyZoneField()` with a real picker**

In `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`, add imports:

```php
use Illuminate\Support\Facades\DB;
```

Replace the `matchingModeFields()` line that renders the county/zone field:

```php
            self::countyZoneStateField()
                ->visible(fn (Get $get): bool => $get('matching_mode') === MatchingMode::CountyZone->value),
            self::countyZoneField()
                ->visible(fn (Get $get): bool => $get('matching_mode') === MatchingMode::CountyZone->value),
```

Replace the `countyZoneField()` method (and add the three new ones after it) at the bottom of the class:

```php
    protected static function countyZoneStateField(): Select
    {
        return Select::make('county_zone_state')
            ->label('Filter by state')
            ->dehydrated(false)
            ->live()
            ->searchable()
            ->options(fn (): array => DB::table('public_zones')->distinct()->orderBy('state')->pluck('state', 'state')->all())
            ->helperText('Narrows the list below to one state at a time — the codes you pick can still span multiple states.');
    }

    protected static function countyZoneField(): Select
    {
        return Select::make('county_zone_codes')
            ->label('Counties / zones (UGC)')
            ->multiple()
            ->searchable()
            ->required()
            ->options(fn (Get $get): array => self::countyZoneOptions($get('county_zone_state'), self::currentCountyZoneCodes($get)))
            ->afterStateHydrated(function (Select $component, mixed $state): void {
                $component->state(is_string($state) && $state !== '' ? explode(',', $state) : ($state ?? []));
            })
            ->dehydrateStateUsing(fn (?array $state): ?string => filled($state) ? implode(',', $state) : null)
            ->helperText('Pick the counties/forecast zones this alert should watch.');
    }

    /**
     * @return array<int, string>
     */
    protected static function currentCountyZoneCodes(Get $get): array
    {
        $codes = $get('county_zone_codes');

        return is_array($codes) ? $codes : [];
    }

    /**
     * counties.fips is a raw 5-digit FIPS code and public_zones.state_zone
     * omits the type letter (e.g. "NY001", not "NYZ001") -- neither is
     * itself a real UGC code, so both are derived here from state + the
     * zone/fips columns, matching ResolveLocationZones's derivation.
     *
     * @return array<string, string>
     */
    protected static function countyZoneOptions(?string $state, array $currentCodes): array
    {
        $states = collect($currentCodes)
            ->map(fn (string $code): string => substr($code, 0, 2))
            ->push($state)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($states === []) {
            return [];
        }

        $countyOptions = DB::table('counties')->whereIn('state', $states)->get(['fips', 'state', 'countyname'])
            ->mapWithKeys(function ($c): array {
                $code = strtoupper($c->state).'C'.substr($c->fips, -3);

                return [$code => "{$code} — {$c->countyname} County, {$c->state}"];
            });

        $zoneOptions = DB::table('public_zones')->whereIn('state', $states)->get(['state_zone', 'state', 'zone', 'name'])
            ->mapWithKeys(function ($z): array {
                $code = strtoupper($z->state).'Z'.$z->zone;

                return [$code => "{$code} — {$z->name}, {$z->state}"];
            });

        return $countyOptions->union($zoneOptions)->all();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php"
```
Expected: PASS (all tests in the file, including pre-existing ones — confirm no regression).

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php tests/Feature/AlertRuleResourceTest.php
git commit -m "$(cat <<'EOF'
Replace CountyZone free-text input with a real state/county/zone picker

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: `AlertRuleForm` — WFO auto-suggestion for Forecast Discussions

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`
- Modify: `tests/Feature/AlertRuleResourceTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/AlertRuleResourceTest.php`:

```php
test('the forecast discussion wfo field defaults to the subscriber\'s most recent location\'s CWA', function () {
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'OKX', 'cwa' => 'OKX', 'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->location->update(['lat' => 40.5, 'lng' => -72.5]);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'forecast_discussion'])
        ->assertFormSet(['wfo' => 'OKX']);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php --filter='defaults to the subscriber'"
```
Expected: FAIL — `wfo` has no default, so it starts empty.

- [ ] **Step 3: Add the auto-suggestion**

In `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`, change `forecastDiscussionFields()`:

```php
    protected static function forecastDiscussionFields(): array
    {
        return [
            self::wfoField()
                ->default(fn (): ?string => self::suggestedWfo())
                ->helperText('Your local NWS office\'s 3-letter identifier. Look yours up at weather.gov if you\'re not sure.'),
        ];
    }
```

Add, near `countyZoneOptions()`:

```php
    /**
     * Suggests a WFO from the subscriber's most recently added Location via
     * a real ST_Contains lookup against cwa_boundaries -- a convenience
     * default only (the subscriber can still type over it), since this
     * wizard step has no location picker of its own for ForecastDiscussion
     * rules.
     */
    protected static function suggestedWfo(): ?string
    {
        $location = auth()->user()->locations()->latest()->first();

        if ($location === null) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT cwa FROM cwa_boundaries WHERE ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4269)) LIMIT 1',
            [(float) $location->lng, (float) $location->lat],
        );

        return $row?->cwa;
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php"
```
Expected: PASS (all tests in the file).

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php tests/Feature/AlertRuleResourceTest.php
git commit -m "$(cat <<'EOF'
Auto-suggest a WFO for Forecast Discussion rules via cwa_boundaries

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"
```
Expected: 100% pass, no regressions in any pre-existing test (including `Tests\Feature\ExampleTest`, already fixed by the user earlier this session).

- [ ] **Step 2: Run Pint on everything touched**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```
Expected: no unfixed style issues. If it reformats anything, re-run the affected test file(s) to confirm nothing broke, then amend the relevant commit's changes into a fresh commit (per this project's git convention: never `--amend` published commits — stage the Pint fixes and commit them as their own small commit).

- [ ] **Step 3: Manually verify quiet hours math and route wiring once, live**

This is the one piece hard to fully cover with fakes: confirm quiet-hours suppression really works with a real overnight-wrapping window (e.g. `22:00`–`06:00`) and a real timezone, and that the real ingest route path used in Task 5 matches production config. Run:
```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute='
\$rule = new App\Models\AlertRule([\"quiet_hours_start\" => \"22:00:00\", \"quiet_hours_end\" => \"06:00:00\", \"matching_mode\" => App\Enums\MatchingMode::Location]);
\$location = new App\Models\Location([\"timezone\" => \"America/New_York\"]);
\$rule->setRelation(\"location\", \$location);
echo (new ReflectionMethod(App\Actions\Weather\MatchWeatherAlertRules::class, \"isWithinQuietHours\"))->invoke(new App\Actions\Weather\MatchWeatherAlertRules, \$rule) ? \"suppressed\" : \"delivers\";
'"
```
Expected: prints `suppressed` or `delivers` consistent with the current real time in `America/New_York` relative to the 22:00–06:00 window — sanity-check by eye against the current wall clock.

- [ ] **Step 4: Ask the user to run the complete suite themselves**

Per this project's Pest convention, ask the user to run `php artisan test --compact` (inside the container) themselves as a final independent confirmation before considering this plan done.

---

## Self-Review Notes

- **Spec coverage**: every section of `docs/superpowers/specs/2026-09-06-weather-alert-engine-design.md` maps to a task — ingest-time enrichment (Tasks 2-4), the `IngestedProduct::created` wiring (Task 5), local zone/timezone resolution (Tasks 6-7), `MatchWeatherAlertRules`'s full geography matrix (Tasks 8-10), delivery/`DeliveryLog` (Task 11), the `CountyZone` picker (Task 12), WFO auto-suggestion (Task 13). The two "Known gaps" (SPC/WPC prefixes, risk-level parsing) are explicitly scoped out, not silently dropped — documented in the plan header and re-verified against live data during planning (613-row sample, zero SPC/WPC prefixes found).
- **Placeholder scan**: no TBD/TODO/"add appropriate X" anywhere above — every step has real, complete code, grounded in this session's direct reads of the actual models, migrations, forms, and the real `AwipsProduct::getAffectedAreaCoords()` reference implementation (for the polygon decode math) and real `\d psql` schema dumps (for the shapefile tables' exact columns/geometry type).
- **Type consistency**: `ExtractProductMetadata::__invoke()`'s return shape (`product_id`/`ugc_codes`/`polygon`) matches what `IngestNwwsProductController` spreads into `updateOrCreate()` and what `MatchWeatherAlertRules`/tests consume throughout. `ResolveLocationZones`'s three column names match `Location`'s migration (Task 1) and every later read of them (Tasks 8-10, 12). `DeliverWeatherAlert`'s constructor args match every `DeliverWeatherAlert::dispatch(...)` call site in `MatchWeatherAlertRules`.
- **Real-data grounding**: UGC-format derivation (`{state}Z{zone}`, `{state}C{fips-suffix}`) was verified against real rows from `counties`/`public_zones`/`fire_zones` (queried live during planning), not assumed from the shapefile attribute map alone — `public_zones.state_zone` turned out to already omit the type letter, which the original design doc didn't anticipate.
