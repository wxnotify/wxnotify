# Test/Synthetic Product Generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give admins a tool to fabricate a synthetic NWS product (warning, SPC/WPC discussion, or plain-text bulletin), send it through the real ingestion → matching → dispatch pipeline against a dedicated fixture account, and review or replay past test runs as reusable, editable scenarios.

**Architecture:** A new `TestProductScenario` model/Filament Resource (admin-only, hidden in production) drives a 4-step wizard whose saved JSON input renders one of three Blade templates into raw NWS-format text. That text is fed through the exact same parsing/ingestion code path a real product uses (`IngestProductText`, extracted from the real controller), tagged `is_test = true`, which triggers the real `IngestedProductObserver` → `SyncActiveWwa`/`MatchWeatherAlertRules` → `DeliverWeatherAlert` chain, scoped to a single designated fixture `User`.

**Tech Stack:** Laravel 13, Filament v5 (Actions/Schemas/Tables namespaces), Pest, PostgreSQL + PostGIS (`ST_Contains` for the WFO sanity check).

**Spec:** `docs/superpowers/specs/2026-09-16-test-product-generator-design.md`

**One addition beyond the spec, locked in while mapping out files:** the spec's `has_ugc`/`has_polygon` flags alone can't tell `SVS` (a real VTEC-warning continuation) apart from `SPS` (Special Weather Statement, no VTEC) — both are `family = 'statement'` and both have `has_polygon = true`. A new `products.has_vtec` boolean (same pattern as the other two flags) resolves this and drives which shape a product renders as.

---

### File Structure

**Migrations** (in order):
- `add_is_test_account_to_users_table.php`
- `add_generator_metadata_to_products_table.php` — `has_vtec`, `has_ugc`, `damage_tag_options`, backfilled
- `add_is_test_to_ingested_products_table.php`
- `create_test_product_scenarios_table.php`
- `add_test_product_scenario_id_to_ingested_products_table.php` (separate migration — depends on the table above existing first)
- `add_is_test_to_active_wwas_table.php`

**Models:**
- `app/Models/TestProductScenario.php` (new)
- `app/Models/User.php`, `app/Models/Product.php`, `app/Models/IngestedProduct.php`, `app/Models/ActiveWwa.php` (modified)

**Actions:**
- `app/Actions/Pipeline/IngestProductText.php` (new — extracted shared ingestion logic)
- `app/Actions/Testing/GenerateTestProductPolygon.php` (new — point+radius → box, and raw-coordinate parsing)
- `app/Actions/Testing/CheckPolygonWithinWfo.php` (new — the WFO sanity-check query)
- `app/Actions/Testing/RenderTestProductText.php` (new — dispatches to the right Blade template)
- `app/Actions/Testing/GenerateTestProduct.php` (new — orchestrator: render → build identity → ingest → tag is_test)
- `app/Actions/Pipeline/ExtractProductMetadata.php` (modified — `parsePolygon()` bug fix)
- `app/Actions/Pipeline/SyncActiveWwa.php` (modified — propagate `is_test`)
- `app/Actions/Weather/MatchWeatherAlertRules.php` (modified — scope to fixture account when `is_test`)
- `app/Http/Controllers/IngestNwwsProductController.php` (modified — delegates to `IngestProductText`)

**Views:**
- `resources/views/filament/admin/test-products/warning.blade.php`
- `resources/views/filament/admin/test-products/discussion.blade.php`
- `resources/views/filament/admin/test-products/plain-text.blade.php`

**Filament (admin panel):**
- `app/Filament/Admin/Resources/TestProductScenarios/TestProductScenarioResource.php`
- `app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php`
- `app/Filament/Admin/Resources/TestProductScenarios/Tables/TestProductScenariosTable.php`
- `app/Filament/Admin/Resources/TestProductScenarios/Pages/{ListTestProductScenarios,CreateTestProductScenario,EditTestProductScenario}.php`
- `app/Filament/Admin/Resources/Products/Schemas/ProductForm.php` (modified — new toggles/tags input)
- `app/Filament/Admin/Resources/Users/Schemas/UserForm.php` (modified — `is_test_account` toggle)
- `app/Filament/Admin/Resources/IngestedProducts/Tables/IngestedProductsTable.php` (modified — `is_test` filter + scenario link)

**Factories:** `database/factories/TestProductScenarioFactory.php` (new)

---

### Task 1: Fix `ExtractProductMetadata::parsePolygon()` for SPC MD/WPC MPD's concatenated coordinate format

Real SPC MD/WPC MPD bulletins encode each lat/lon pair as one concatenated 8-digit token (e.g. `33311429`) with no internal space, unlike warning-family's two separate 4-5 digit tokens (e.g. `3650 9082`). This is confirmed against this repo's own real captured fixture, `database/product_examples/SWO.txt`. The current regex silently mis-parses this format. This task must land before Task 11 (the discussion-shape template), which depends on it to prove correctness against real formatting.

**Files:**
- Modify: `app/Actions/Pipeline/ExtractProductMetadata.php`
- Test: `tests/Feature/ExtractProductMetadataTest.php`

- [ ] **Step 1: Write the failing test using the real SWO.txt fixture**

```php
test('a concatenated 8-digit LAT...LON token (SPC MD/WPC MPD format) decodes correctly', function () {
    $text = file_get_contents(base_path('database/product_examples/SWO.txt'));

    $metadata = (new ExtractProductMetadata)($text, 'swomcd');

    expect($metadata['polygon'])->toBe([
        ['lat' => 33.31, 'lon' => -114.29],
        ['lat' => 34.15, 'lon' => -114.73],
        ['lat' => 34.54, 'lon' => -114.89],
        ['lat' => 35.2, 'lon' => -115.15],
        ['lat' => 35.55, 'lon' => -114.99],
        ['lat' => 35.32, 'lon' => -113.91],
        ['lat' => 35.08, 'lon' => -113.1],
        ['lat' => 34.62, 'lon' => -112.2],
        ['lat' => 34.34, 'lon' => -111.41],
        ['lat' => 33.81, 'lon' => -110.53],
        ['lat' => 32.73, 'lon' => -109.67],
        ['lat' => 32.47, 'lon' => -109.95],
        ['lat' => 32.39, 'lon' => -110.71],
        ['lat' => 32.5, 'lon' => -111.98],
        ['lat' => 33.05, 'lon' => -113.99],
        ['lat' => 33.31, 'lon' => -114.29],
    ]);
});

test('a warning-family separate-token LAT...LON line still decodes exactly as before (no regression)', function () {
    $text = "LAT...LON 3650 9082 3666 9079 3663 9055 3650 9058\nTIME...MOT...LOC 0052Z 278DEG 15KT 3656 9073\n";

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['polygon'])->toBe([
        ['lat' => 36.5, 'lon' => -90.82],
        ['lat' => 36.66, 'lon' => -90.79],
        ['lat' => 36.63, 'lon' => -90.55],
        ['lat' => 36.5, 'lon' => -90.58],
    ]);
});
```

- [ ] **Step 2: Run the tests to verify the first one fails**

Run (inside the Sail container): `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter='concatenated 8-digit' --compact"`
Expected: FAIL — actual polygon is truncated/misaligned (each 8-digit token gets chopped into a 5-digit and a leftover 3-digit fragment that fails the `\d{4,5}` pattern and is dropped).

- [ ] **Step 3: Fix `parsePolygon()`**

Replace the existing `parsePolygon()` method and add two new private static helpers in `app/Actions/Pipeline/ExtractProductMetadata.php`:

```php
    /**
     * Finds a "LAT...LON" line, collects wrapped continuation lines until a
     * blank line or a "TIME..." line, and decodes each coordinate token.
     * Doesn't pre-filter by product type (the legacy reference implementation
     * gates this on a fixed list of AWIPS product types) — scanning every
     * product's text for the line and returning null when absent is simpler
     * and has the same practical effect, since most VTEC products never
     * carry one.
     *
     * @return ?array<int, array{lat: float, lon: float}>
     */
    private function parsePolygon(string $text): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $collecting = false;
        $tokens = [];

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
            } elseif (! preg_match('/^\d/', $trimmed)) {
                // Mirrors the legacy reference's ^\s*\d+ gate on continuation
                // lines: a non-digit-leading line (e.g. stray remarks text
                // before TIME...) is skipped, not appended -- otherwise a
                // preg_match_all() scanning the whole line could splice
                // unrelated digits into the polygon instead of failing safe.
                continue;
            }

            if (preg_match_all('/\d{4,9}/', $trimmed, $matches)) {
                array_push($tokens, ...$matches[0]);
            }
        }

        if ($tokens === []) {
            return null;
        }

        // SPC MD/WPC MPD products encode each lat/lon pair as one
        // concatenated 8-9 digit token (e.g. "33311429") rather than
        // warning-family's two separate space-delimited 4-5 digit tokens
        // (e.g. "3650 9082") -- confirmed against this repo's own real SPC
        // MCD fixture, database/product_examples/SWO.txt. A product uses
        // one convention consistently, so the presence of any combined
        // token switches parsing for the whole line.
        $isCombinedFormat = collect($tokens)->contains(fn (string $t): bool => strlen($t) >= 8);

        if ($isCombinedFormat) {
            $points = collect($tokens)->map(fn (string $t): array => self::decodeCombinedToken($t))->all();

            return count($points) >= 3 ? $points : null;
        }

        if (count($tokens) < 6 || count($tokens) % 2 !== 0) {
            return null;
        }

        return collect(array_chunk($tokens, 2))
            ->map(fn (array $pair): array => [
                'lat' => self::decodeCoordinatePart($pair[0]),
                'lon' => -self::decodeCoordinatePart($pair[1]),
            ])
            ->all();
    }

    /**
     * Decodes one combined lat/lon token: the first 4 digits are latitude
     * (decimal after 2, as usual), the rest is longitude. A 4-digit
     * remainder always represents a longitude >= 100 degrees with its
     * leading "1" digit dropped -- confirmed against this repo's own real
     * SPC MCD fixture (database/product_examples/SWO.txt: token "33311429"
     * only decodes to a real Arizona/Nevada/California-border coordinate,
     * matching that MD's own "AREAS AFFECTED...CENTRAL/SOUTHERN AZ...FAR
     * ERN CA...FAR SRN NV" text, if the longitude half is read as "114.29"
     * rather than "14.29"). Built from a single real confirmed example --
     * if a real MD/MPD example is ever found using a different convention,
     * this needs revisiting, not guessed at further.
     *
     * @return array{lat: float, lon: float}
     */
    private static function decodeCombinedToken(string $token): array
    {
        $lat = substr($token, 0, 4);
        $lonDigits = substr($token, 4);
        $lon = strlen($lonDigits) === 4 ? '1'.$lonDigits : $lonDigits;

        return [
            'lat' => self::decodeCoordinatePart($lat),
            'lon' => -self::decodeCoordinatePart($lon),
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=ExtractProductMetadataTest --compact"`
Expected: PASS, all tests including the two new ones and every pre-existing one (confirms no regression on warning-family parsing).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Pipeline/ExtractProductMetadata.php tests/Feature/ExtractProductMetadataTest.php
git commit -m "Fix parsePolygon() to handle SPC MD/WPC MPD's concatenated coordinate format"
```

---

### Task 2: `is_test_account` on `users`

**Files:**
- Create: `database/migrations/2026_09_18_000001_add_is_test_account_to_users_table.php`
- Modify: `app/Models/User.php`
- Create: `tests/Feature/UserModelTest.php` (doesn't exist yet)

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_test_account')->default(false)->after('is_staff');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_test_account');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate"`
Expected: `Migrating: ..._add_is_test_account_to_users_table` then `Migrated:` with no errors.

- [ ] **Step 3: Write a failing test for the model cast**

```php
<?php

use App\Models\User;

test('is_test_account casts to boolean', function () {
    $user = User::factory()->create(['is_test_account' => true]);

    expect($user->fresh()->is_test_account)->toBeTrue();
});
```

- [ ] **Step 4: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter='is_test_account casts to boolean' --compact"`
Expected: FAIL — `is_test_account` isn't `#[Fillable]`, so `User::factory()->create(['is_test_account' => true])` silently drops it (mass-assignment guard), and the assertion fails against the default `false`.

- [ ] **Step 5: Add the column to `User`'s Fillable and casts**

In `app/Models/User.php`, change:

```php
#[Fillable(['name', 'email', 'password'])]
```

to:

```php
#[Fillable(['name', 'email', 'password', 'is_test_account'])]
```

and in `casts()`, add the new line:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_staff' => 'boolean',
            'is_test_account' => 'boolean',
            'tier_override' => Tier::class,
        ];
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter='is_test_account casts to boolean' --compact"`
Expected: PASS

- [ ] **Step 7: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations/2026_09_18_000001_add_is_test_account_to_users_table.php app/Models/User.php tests/Feature/UserModelTest.php
git commit -m "Add is_test_account flag for the Test Product Generator's fixture account"
```

---

### Task 3: Generator metadata columns on `products` (`has_vtec`, `has_ugc`, `damage_tag_options`)

**Files:**
- Create: `database/migrations/2026_09_18_000002_add_generator_metadata_to_products_table.php`
- Modify: `app/Models/Product.php`
- Modify: `app/Filament/Admin/Resources/Products/Schemas/ProductForm.php`
- Test: `tests/Feature/ProductModelTest.php` (create if it doesn't exist)

- [ ] **Step 1: Write the migration, with backfill for the real seeded catalog**

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
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('has_vtec')->default(false)->after('has_polygon');
            $table->boolean('has_ugc')->default(false)->after('has_vtec');
            $table->json('damage_tag_options')->nullable()->after('risk_level_options');
        });

        // Backfill for the real seeded catalog (see the products table
        // migration): Warning/Watch/Advisory always carry VTEC and UGC.
        // Statement-family is mixed -- SVS is a VTEC-warning continuation,
        // SPS is not -- so it's excluded from the blanket update and set
        // explicitly per code below.
        DB::table('products')->whereIn('family', ['warning', 'watch', 'advisory'])
            ->update(['has_vtec' => true, 'has_ugc' => true]);

        DB::table('products')->where('code', 'SVS')->update(['has_vtec' => true, 'has_ugc' => true]);
        DB::table('products')->where('code', 'SPS')->update(['has_vtec' => false, 'has_ugc' => true]);

        DB::table('products')->where('code', 'SV.W')->update([
            'damage_tag_options' => json_encode([
                'HAIL THREAT...RADAR INDICATED',
                'HAIL THREAT...OBSERVED',
                'MAX HAIL SIZE...<.75 IN',
                'MAX HAIL SIZE...1.00 IN',
                'MAX HAIL SIZE...1.75 IN',
                'WIND THREAT...RADAR INDICATED',
                'WIND THREAT...OBSERVED',
                'MAX WIND GUST...60 MPH',
                'MAX WIND GUST...70 MPH',
                'THUNDERSTORM DAMAGE THREAT...CONSIDERABLE',
                'THUNDERSTORM DAMAGE THREAT...DESTRUCTIVE',
            ]),
        ]);

        DB::table('products')->where('code', 'TO.W')->update([
            'damage_tag_options' => json_encode([
                'TORNADO...RADAR INDICATED',
                'TORNADO...OBSERVED',
                'TORNADO DAMAGE THREAT...CONSIDERABLE',
                'TORNADO DAMAGE THREAT...CATASTROPHIC',
                'FLASH FLOOD...RADAR INDICATED',
            ]),
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['has_vtec', 'has_ugc', 'damage_tag_options']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate"`
Expected: `Migrated:` with no errors.

- [ ] **Step 3: Write failing tests for the backfill and the model casts**

```php
<?php

use App\Models\Product;

test('the backfill sets has_vtec/has_ugc correctly for the real seeded catalog, including the SVS/SPS split', function () {
    expect(Product::where('code', 'TO.W')->sole()->has_vtec)->toBeTrue()
        ->and(Product::where('code', 'TO.W')->sole()->has_ugc)->toBeTrue()
        ->and(Product::where('code', 'SVS')->sole()->has_vtec)->toBeTrue()
        ->and(Product::where('code', 'SPS')->sole()->has_vtec)->toBeFalse()
        ->and(Product::where('code', 'SPS')->sole()->has_ugc)->toBeTrue()
        ->and(Product::where('code', 'AFD')->sole()->has_vtec)->toBeFalse()
        ->and(Product::where('code', 'AFD')->sole()->has_ugc)->toBeFalse()
        ->and(Product::where('code', 'SPC.MD')->sole()->has_vtec)->toBeFalse();
});

test('damage_tag_options casts to an array and is fillable', function () {
    $product = Product::factory()->create(['damage_tag_options' => ['TAG A', 'TAG B']]);

    expect($product->fresh()->damage_tag_options)->toBe(['TAG A', 'TAG B']);
});

test('has_vtec and has_ugc cast to boolean and are fillable', function () {
    $product = Product::factory()->create(['has_vtec' => true, 'has_ugc' => true]);

    expect($product->fresh()->has_vtec)->toBeTrue()
        ->and($product->fresh()->has_ugc)->toBeTrue();
});
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=ProductModelTest --compact"`
Expected: the backfill test PASSES already (pure DB state, no model changes needed), but the two Fillable/cast tests FAIL — the new columns aren't in `Product`'s `#[Fillable]` yet, so `create()` silently drops them.

- [ ] **Step 5: Update `Product`'s Fillable and casts**

In `app/Models/Product.php`, change:

```php
#[Fillable(['code', 'name', 'family', 'category', 'has_polygon', 'risk_level_options', 'status', 'sort_order', 'description', 'example', 'wea_warning'])]
```

to:

```php
#[Fillable(['code', 'name', 'family', 'category', 'has_polygon', 'has_vtec', 'has_ugc', 'risk_level_options', 'damage_tag_options', 'status', 'sort_order', 'description', 'example', 'wea_warning'])]
```

and in `casts()`:

```php
    protected function casts(): array
    {
        return [
            'family' => ProductFamily::class,
            'category' => ProductCategory::class,
            'has_polygon' => 'boolean',
            'has_vtec' => 'boolean',
            'has_ugc' => 'boolean',
            'risk_level_options' => 'array',
            'damage_tag_options' => 'array',
            'status' => ProductStatus::class,
            'wea_warning' => 'boolean',
        ];
    }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=ProductModelTest --compact"`
Expected: PASS

- [ ] **Step 7: Add the new fields to the admin `ProductForm`**

In `app/Filament/Admin/Resources/Products/Schemas/ProductForm.php`, add two new fields right after the existing `Toggle::make('has_polygon')`:

```php
                Toggle::make('has_polygon'),
                Toggle::make('has_vtec')
                    ->label('Carries a VTEC line')
                    ->helperText('Drives the Test Product Generator\'s "warning" shape and whether this product supports the continue-existing-event flow.'),
                Toggle::make('has_ugc')
                    ->label('Carries a UGC/zone line')
                    ->helperText('Independent of VTEC — e.g. Special Weather Statements carry UGC with no VTEC line.'),
                Toggle::make('wea_warning')
                    ->helperText('Activates the Wireless Emergency Alerts (cell-phone) system when issued.'),
                TagsInput::make('risk_level_options')
                    ->helperText('Leave blank for products with no risk-level filter.'),
                TagsInput::make('damage_tag_options')
                    ->label('Damage tags')
                    ->helperText('Exact NWS-format lines selectable in the Test Product Generator (e.g. "TORNADO...RADAR INDICATED"). Leave blank for products with no damage tags.'),
```

- [ ] **Step 8: Manually verify the form in the browser**

Log in as a staff user, visit `/admin/products/{id}/edit` for `TO.W`, confirm the four new/changed fields render and save correctly (toggle both booleans on, add a tag to Damage tags, save, reload, confirm persisted).

- [ ] **Step 9: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations/2026_09_18_000002_add_generator_metadata_to_products_table.php app/Models/Product.php app/Filament/Admin/Resources/Products/Schemas/ProductForm.php tests/Feature/ProductModelTest.php
git commit -m "Add has_vtec/has_ugc/damage_tag_options metadata to the product catalog"
```

---

### Task 4: `is_test` on `ingested_products` + `test_product_scenarios` table + `test_product_scenario_id` FK

**Files:**
- Create: `database/migrations/2026_09_18_000003_add_is_test_to_ingested_products_table.php`
- Create: `database/migrations/2026_09_18_000004_create_test_product_scenarios_table.php`
- Create: `database/migrations/2026_09_18_000005_add_test_product_scenario_id_to_ingested_products_table.php`
- Create: `app/Models/TestProductScenario.php`
- Create: `database/factories/TestProductScenarioFactory.php`
- Modify: `app/Models/IngestedProduct.php`
- Test: `tests/Feature/TestProductScenarioModelTest.php`

- [ ] **Step 1: Write the `is_test` migration**

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
            $table->boolean('is_test')->default(false)->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropColumn('is_test');
        });
    }
};
```

- [ ] **Step 2: Write the `test_product_scenarios` table migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_product_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('continues_scenario_id')->nullable()->constrained('test_product_scenarios')->nullOnDelete();
            $table->json('input');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_product_scenarios');
    }
};
```

- [ ] **Step 3: Write the `test_product_scenario_id` FK migration (separate — depends on the table above)**

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
            $table->foreignId('test_product_scenario_id')->nullable()->after('is_test')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('test_product_scenario_id');
        });
    }
};
```

- [ ] **Step 4: Run the migrations**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate"`
Expected: all three migrate cleanly, in order (the third depends on the second's table existing).

- [ ] **Step 5: Write the failing model/factory test**

```php
<?php

use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;

test('a scenario belongs to a product, can continue another scenario, and casts input to an array', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $original = TestProductScenario::factory()->for($product)->create(['input' => ['wfo' => 'TWC']]);
    $followUp = TestProductScenario::factory()->for($product)->create(['continues_scenario_id' => $original->id]);

    expect($followUp->fresh()->continuesScenario->is($original))->toBeTrue()
        ->and($original->fresh()->product->is($product))->toBeTrue()
        ->and($original->fresh()->input)->toBe(['wfo' => 'TWC']);
});

test('a scenario tracks every ingested product it has spawned', function () {
    $scenario = TestProductScenario::factory()->create();
    $product = IngestedProduct::factory()->create(['test_product_scenario_id' => $scenario->id, 'is_test' => true]);

    expect($scenario->fresh()->ingestedProducts->pluck('id')->all())->toBe([$product->id]);
});

test('deleting a scenario nulls out is_test rows that reference it, rather than deleting them', function () {
    $scenario = TestProductScenario::factory()->create();
    $product = IngestedProduct::factory()->create(['test_product_scenario_id' => $scenario->id, 'is_test' => true]);

    $scenario->delete();

    expect($product->fresh()->test_product_scenario_id)->toBeNull()
        ->and(IngestedProduct::whereKey($product->id)->exists())->toBeTrue();
});
```

- [ ] **Step 6: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioModelTest --compact"`
Expected: FAIL — `TestProductScenario` class doesn't exist yet.

- [ ] **Step 7: Create the `TestProductScenario` model**

```php
<?php

namespace App\Models;

use Database\Factories\TestProductScenarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'product_id', 'continues_scenario_id', 'input'])]
class TestProductScenario extends Model
{
    /** @use HasFactory<TestProductScenarioFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'input' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function continuesScenario(): BelongsTo
    {
        return $this->belongsTo(self::class, 'continues_scenario_id');
    }

    public function ingestedProducts(): HasMany
    {
        return $this->hasMany(IngestedProduct::class);
    }
}
```

- [ ] **Step 8: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\TestProductScenario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestProductScenario>
 */
class TestProductScenarioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->optional()->sentence(3),
            'product_id' => Product::factory(),
            'input' => [
                'mode' => 'new',
                'wfo' => 'TWC',
                'expires_minutes' => 60,
            ],
        ];
    }
}
```

- [ ] **Step 9: Add `test_product_scenario_id`/`is_test` to `IngestedProduct`'s Fillable, add the `scenario()` relation**

In `app/Models/IngestedProduct.php`, change the `#[Fillable]` attribute from:

```php
#[Fillable([
    'cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'received_at',
    'product_id', 'ugc_codes', 'polygon', 'risk_levels',
    'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'vtec_action', 'vtec_begin', 'vtec_end',
    'format', 'msg_type', 'event', 'severity', 'urgency', 'certainty',
    'headline', 'description', 'instruction', 'event_motion_description',
    'awips_identifier', 'wmo_identifier',
    'hazard', 'hazard_source', 'impact', 'locations_impacted', 'call_to_action',
])]
```

to (adding `is_test` and `test_product_scenario_id` at the end):

```php
#[Fillable([
    'cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'received_at',
    'product_id', 'ugc_codes', 'polygon', 'risk_levels',
    'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'vtec_action', 'vtec_begin', 'vtec_end',
    'format', 'msg_type', 'event', 'severity', 'urgency', 'certainty',
    'headline', 'description', 'instruction', 'event_motion_description',
    'awips_identifier', 'wmo_identifier',
    'hazard', 'hazard_source', 'impact', 'locations_impacted', 'call_to_action',
    'is_test', 'test_product_scenario_id',
])]
```

Add `'is_test' => 'boolean',` to the `casts()` array (alongside the existing entries), and add a new relation method after `product()`:

```php
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(TestProductScenario::class, 'test_product_scenario_id');
    }
```

(add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` — already imported in this file for `product()`, so no new import needed.)

- [ ] **Step 10: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioModelTest --compact"`
Expected: PASS

- [ ] **Step 11: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations/2026_09_18_000003_add_is_test_to_ingested_products_table.php database/migrations/2026_09_18_000004_create_test_product_scenarios_table.php database/migrations/2026_09_18_000005_add_test_product_scenario_id_to_ingested_products_table.php app/Models/TestProductScenario.php app/Models/IngestedProduct.php database/factories/TestProductScenarioFactory.php tests/Feature/TestProductScenarioModelTest.php
git commit -m "Add TestProductScenario model and is_test/scenario link on ingested_products"
```

---

### Task 5: `is_test` on `active_wwas`, propagated by `SyncActiveWwa`

**Files:**
- Create: `database/migrations/2026_09_18_000006_add_is_test_to_active_wwas_table.php`
- Modify: `app/Models/ActiveWwa.php`
- Modify: `app/Actions/Pipeline/SyncActiveWwa.php`
- Test: `tests/Feature/SyncActiveWwaTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('active_wwas', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('active_wwas', function (Blueprint $table): void {
            $table->dropColumn('is_test');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate"`
Expected: `Migrated:` with no errors.

- [ ] **Step 3: Write the failing test**

Add to `tests/Feature/SyncActiveWwaTest.php`. This file already declares `createIngestedProductWithoutMatchingForSync()` (it suppresses `IngestedProductObserver`, since a plain `IngestedProduct::factory()->create()` on a VTEC-tagged row would otherwise auto-fire `SyncActiveWwa` once via the observer and a second time via this file's own explicit `(new SyncActiveWwa)(...)` calls) — reuse it rather than calling `IngestedProduct::factory()->create()` directly. Its existing imports (`ActiveWwa`, `IngestedProduct`, `SyncActiveWwa`) already cover everything these two tests need:

```php
test('propagates is_test from the triggering product onto the active_wwas row', function () {
    $product = createIngestedProductWithoutMatchingForSync([
        'format' => 'text',
        'is_test' => true,
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '9001', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'text' => "/O.NEW.KTWC.SV.W.9001.260918T0000Z-260918T0100Z/\n",
    ]);

    (new SyncActiveWwa)($product);

    expect(ActiveWwa::where('vtec_event_number', '9001')->sole()->is_test)->toBeTrue();
});

test('a real (non-test) product leaves is_test false on its active_wwas row', function () {
    $product = createIngestedProductWithoutMatchingForSync([
        'format' => 'text',
        'is_test' => false,
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '9002', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'text' => "/O.NEW.KTWC.SV.W.9002.260918T0000Z-260918T0100Z/\n",
    ]);

    (new SyncActiveWwa)($product);

    expect(ActiveWwa::where('vtec_event_number', '9002')->sole()->is_test)->toBeFalse();
});
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=SyncActiveWwaTest --compact"`
Expected: FAIL on the first new test — `is_test` isn't in `ActiveWwa::updateOrCreate()`'s payload yet, so the column stays at its default `false`.

- [ ] **Step 5: Add `is_test` to `ActiveWwa`'s Fillable/casts and to `SyncActiveWwa`'s two upsert payloads**

In `app/Models/ActiveWwa.php`, change:

```php
#[Fillable([
    'product_id', 'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'status', 'ugc_codes', 'effective', 'expires', 'headline',
    'motion_direction_degrees', 'motion_speed_kt', 'motion_reference_lat', 'motion_reference_lon', 'motion_observed_at',
    'source', 'latest_ingested_product_id',
])]
```

to (adding `'is_test'`):

```php
#[Fillable([
    'product_id', 'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'status', 'is_test', 'ugc_codes', 'effective', 'expires', 'headline',
    'motion_direction_degrees', 'motion_speed_kt', 'motion_reference_lat', 'motion_reference_lon', 'motion_observed_at',
    'source', 'latest_ingested_product_id',
])]
```

and add `'is_test' => 'boolean',` to `casts()`.

In `app/Actions/Pipeline/SyncActiveWwa.php`, both `syncFromText()` and `syncFromCap()` build an `ActiveWwa::updateOrCreate()` payload — add `'is_test' => $product->is_test,` to each, right after `'status' => $this->resolveStatus($product->vtec_action),`:

```php
                $wwa = ActiveWwa::updateOrCreate(
                    ActiveWwa::vtecKeyFor($product),
                    [
                        'product_id' => $product->product_id,
                        'status' => $this->resolveStatus($product->vtec_action),
                        'is_test' => $product->is_test,
                        'latest_ingested_product_id' => $product->id,
                        'source' => 'text',
                        ...($product->ugc_codes !== null ? ['ugc_codes' => $product->ugc_codes] : []),
                        ...($product->vtec_begin !== null ? ['effective' => $product->vtec_begin] : []),
                        ...($product->vtec_end !== null ? ['expires' => $product->vtec_end] : []),
                        ...app(ParseTextMotionDescription::class)($product->text, $product->received_at),
                    ],
                );
```

(same addition, `'is_test' => $product->is_test,` right after the `'status' => ...` line, in `syncFromCap()`'s payload too.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=SyncActiveWwaTest --compact"`
Expected: PASS, including every pre-existing test in the file (no regression).

- [ ] **Step 7: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations/2026_09_18_000006_add_is_test_to_active_wwas_table.php app/Models/ActiveWwa.php app/Actions/Pipeline/SyncActiveWwa.php tests/Feature/SyncActiveWwaTest.php
git commit -m "Propagate is_test onto active_wwas so synthetic events are queryable for the continue-existing-event flow"
```

---

### Task 6: Extract `IngestProductText`, the shared ingestion action

**Files:**
- Create: `app/Actions/Pipeline/IngestProductText.php`
- Modify: `app/Http/Controllers/IngestNwwsProductController.php`
- Test: `tests/Feature/IngestProductTextTest.php`
- Test: `tests/Feature/IngestNwwsProductControllerTest.php` (must keep passing unmodified — this is a pure refactor)

- [ ] **Step 1: Write the failing test for the new action**

```php
<?php

use App\Actions\Pipeline\IngestProductText;
use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

test('ingests a text product and returns the created row', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $text = "NYZ069-081200-\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\n";

    $result = app(IngestProductText::class)($text, 'test/path.txt', [
        'cccc' => 'kokx', 'ttaaii' => 'wfus51', 'awipsid' => 'torokx', 'wmo_ddhhmm' => '061800', 'nwws_id' => '1',
    ]);

    expect($result)->not->toBeNull()
        ->and($result->product_id)->toBe($tornadoWarning->id)
        ->and($result->ugc_codes)->toBe(['NYZ069'])
        ->and($result->file_path)->toBe('test/path.txt')
        ->and($result->text)->toBe($text)
        ->and($result->received_at)->not->toBeNull();
});

test('merges extra fields (e.g. is_test) into the created row', function () {
    $result = app(IngestProductText::class)('AREA FORECAST DISCUSSION', 'test/afd.txt', [
        'cccc' => 'okx', 'ttaaii' => 'fxus61', 'awipsid' => 'afdokx', 'wmo_ddhhmm' => '061800', 'nwws_id' => '1',
    ], extra: ['is_test' => true]);

    expect($result->is_test)->toBeTrue();
});

test('is idempotent for the same file_path, matching the real controller\'s existing behavior', function () {
    app(IngestProductText::class)('original text', 'test/path.txt', ['cccc' => null, 'ttaaii' => null, 'awipsid' => null, 'wmo_ddhhmm' => null, 'nwws_id' => null]);
    app(IngestProductText::class)('updated text', 'test/path.txt', ['cccc' => null, 'ttaaii' => null, 'awipsid' => null, 'wmo_ddhhmm' => null, 'nwws_id' => null]);

    expect(IngestedProduct::count())->toBe(1)
        ->and(IngestedProduct::sole()->text)->toBe('updated text');
});

test('runs the CAP extractor when $isCap is true, and returns null without creating a row on a malformed CAP payload', function () {
    Log::spy();

    $result = app(IngestProductText::class)("<?xml version=\"1.0\"?><alert><unclosed>\n", 'test/cap.txt', [
        'cccc' => 'kwbc', 'ttaaii' => 'xous55', 'awipsid' => 'captwc', 'wmo_ddhhmm' => '142354', 'nwws_id' => '1',
    ], isCap: true);

    expect($result)->toBeNull()
        ->and(IngestedProduct::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=IngestProductTextTest --compact"`
Expected: FAIL — `App\Actions\Pipeline\IngestProductText` doesn't exist yet.

- [ ] **Step 3: Create `IngestProductText`, extracting the logic already in the controller**

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parses a product's raw text and writes the resulting IngestedProduct row
 * -- the single shared code path between the real NWWS intake controller
 * (IngestNwwsProductController) and the Test Product Generator, so a
 * synthetic product is parsed by the exact same code a real one is, not
 * merely similarly-shaped code.
 */
class IngestProductText
{
    public function __construct(
        private readonly ExtractProductMetadata $extractProductMetadata,
        private readonly ExtractCapMetadata $extractCapMetadata,
    ) {}

    /**
     * @param  array{cccc: ?string, ttaaii: ?string, awipsid: ?string, wmo_ddhhmm: ?string, nwws_id: ?string}  $identity
     * @param  array<string, mixed>  $extra  Additional columns to merge into the row (e.g. is_test, test_product_scenario_id).
     */
    public function __invoke(string $text, string $filePath, array $identity, bool $isCap = false, array $extra = []): ?IngestedProduct
    {
        if ($isCap) {
            try {
                $metadata = ($this->extractCapMetadata)($text);
            } catch (Throwable $e) {
                Log::warning('Failed to parse a CAP product; skipping ingestion', [
                    'path' => $filePath,
                    'exception' => $e->getMessage(),
                ]);

                return null;
            }
        } else {
            $metadata = ($this->extractProductMetadata)($text, $identity['awipsid']);
        }

        return IngestedProduct::updateOrCreate(
            ['file_path' => $filePath],
            [...$identity, ...$metadata, ...$extra, 'text' => $text, 'received_at' => now()],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=IngestProductTextTest --compact"`
Expected: PASS

- [ ] **Step 5: Rewrite the controller to delegate to the new action**

Replace `app/Http/Controllers/IngestNwwsProductController.php` entirely with:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\Pipeline\IngestProductText;
use App\Models\IngestedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class IngestNwwsProductController extends Controller
{
    public function __construct(
        private readonly IngestProductText $ingestProductText,
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

        ($this->ingestProductText)($text, $validated['path'], $parsed, $isCap);

        return response()->noContent();
    }
}
```

- [ ] **Step 6: Run the full existing controller test suite to confirm zero regression**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=IngestNwwsProductControllerTest --compact"`
Expected: PASS — every pre-existing test in this file passes unchanged, confirming the refactor preserved identical behavior.

- [ ] **Step 7: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Pipeline/IngestProductText.php app/Http/Controllers/IngestNwwsProductController.php tests/Feature/IngestProductTextTest.php
git commit -m "Extract IngestProductText, the ingestion logic shared with the Test Product Generator"
```

---

### Task 7: Scope `MatchWeatherAlertRules` to the fixture test account for `is_test` products

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php`
- Test: `tests/Feature/MatchWeatherAlertRulesTest.php` (open it first to match its existing setup/helper conventions)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/MatchWeatherAlertRulesTest.php`. This file already declares `createIngestedProductWithoutMatching()` (suppresses `IngestedProductObserver`'s automatic dispatch, matching `Queue::fake()`'s already-established per-test placement in this file) — use it instead of `IngestedProduct::factory()->create()` directly. `Queue`, `AlertRule`, `NotificationChannel`, `Product`, `IngestedProduct` are already imported; add `use App\Models\User;`, which isn't yet:

```php
test('an is_test product only matches AlertRules belonging to a user flagged is_test_account, not real subscribers', function () {
    Queue::fake();
    $realUser = User::factory()->create(['is_test_account' => false]);
    $testUser = User::factory()->create(['is_test_account' => true]);
    $realChannel = NotificationChannel::factory()->for($realUser)->create();
    $testChannel = NotificationChannel::factory()->for($testUser)->create();

    $realRule = AlertRule::factory()->for($realUser)->create(['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);
    $realRule->notificationChannels()->attach($realChannel);
    $testRule = AlertRule::factory()->for($testUser)->create(['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);
    $testRule->notificationChannels()->attach($testChannel);

    $ingestedProduct = createIngestedProductWithoutMatching(['cccc' => 'ktwc', 'is_test' => true]);

    (new MatchWeatherAlertRules)($ingestedProduct);

    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id);
});

test('a real (non-test) product matches every active subscriber as before, regardless of is_test_account', function () {
    Queue::fake();
    $realUser = User::factory()->create(['is_test_account' => false]);
    $channel = NotificationChannel::factory()->for($realUser)->create();
    $rule = AlertRule::factory()->for($realUser)->create(['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);
    $rule->notificationChannels()->attach($channel);

    $ingestedProduct = createIngestedProductWithoutMatching(['cccc' => 'ktwc', 'is_test' => false]);

    (new MatchWeatherAlertRules)($ingestedProduct);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $rule->id);
});
```

- [ ] **Step 2: Run tests to verify the first one fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=MatchWeatherAlertRulesTest --compact"`
Expected: FAIL on the new "is_test product only matches..." test — today's query has no `is_test_account` scoping at all, so it dispatches to both rules.

- [ ] **Step 3: Add the scoping to the `AlertRule` query**

In `app/Actions/Weather/MatchWeatherAlertRules.php`, change the query inside `__invoke()`:

```php
        try {
            $rules = AlertRule::query()
                ->where('is_active', true)
                ->whereIn('category', $categories)
                ->where(function (Builder $query) use ($product) {
                    $query->where('category', AlertCategory::ForecastDiscussion)
                        ->orWhere(fn (Builder $q) => $q->whereIn('category', self::PRODUCT_MATCHED_CATEGORIES)
                            ->whereHas('products', fn (Builder $q) => $q->whereKey($product->product_id)));
                })
                ->when($product->is_test, fn (Builder $query) => $query->whereHas('user', fn (Builder $q) => $q->where('is_test_account', true)))
                ->get();
        } catch (Throwable $e) {
```

`AlertRule::user(): BelongsTo` already exists (`app/Models/AlertRule.php:73`) — no new relation or import needed.

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=MatchWeatherAlertRulesTest --compact"`
Expected: PASS, including every pre-existing test in the file (confirms real-product matching is completely unaffected).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/MatchWeatherAlertRulesTest.php
git commit -m "Scope is_test product matching to the fixture test account only"
```

---

### Task 8: `GenerateTestProductPolygon` — point+radius box, and raw-coordinate parsing

**Files:**
- Create: `app/Actions/Testing/GenerateTestProductPolygon.php`
- Test: `tests/Feature/GenerateTestProductPolygonTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\Testing\GenerateTestProductPolygon;

test('generates a 4-point diamond around a center point at the given radius', function () {
    $points = (new GenerateTestProductPolygon)->fromPointAndRadius(lat: 36.0, lon: -94.0, radiusMiles: 10);

    expect($points)->toHaveCount(4);
    // North point: same lon, higher lat by roughly radius/69 degrees.
    expect($points[0]['lat'])->toBeGreaterThan(36.0)
        ->and($points[0]['lon'])->toEqual(-94.0, 0.0001);
    // South point: same lon, lower lat.
    expect($points[2]['lat'])->toBeLessThan(36.0);
    // East/West points: same lat, differing lon.
    expect($points[1]['lat'])->toEqual(36.0, 0.0001)
        ->and($points[3]['lat'])->toEqual(36.0, 0.0001);
});

test('parses a raw lat,lon-per-line textarea into points, skipping malformed lines', function () {
    $raw = "36.0,-94.0\nnot a coordinate\n36.5,-94.5\n\n37.0,-95.0";

    $points = (new GenerateTestProductPolygon)->fromRawText($raw);

    expect($points)->toBe([
        ['lat' => 36.0, 'lon' => -94.0],
        ['lat' => 36.5, 'lon' => -94.5],
        ['lat' => 37.0, 'lon' => -95.0],
    ]);
});

test('raw text parsing returns null when fewer than 3 valid points are present', function () {
    $points = (new GenerateTestProductPolygon)->fromRawText("36.0,-94.0\n36.5,-94.5");

    expect($points)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=GenerateTestProductPolygonTest --compact"`
Expected: FAIL — the class doesn't exist yet.

- [ ] **Step 3: Implement the action**

```php
<?php

namespace App\Actions\Testing;

/**
 * Builds the polygon points for a synthetic warning-family product's
 * LAT...LON line -- either a simple box generated around an admin-picked
 * center point (the guided path), or parsed directly from a pasted raw
 * coordinate list (the advanced path). Deliberately a flat-earth
 * approximation (69 miles per degree of latitude, adjusted for longitude by
 * the cosine of latitude) -- accurate enough for a test-data box at the
 * radii this tool supports, not meant for real geodesy.
 */
class GenerateTestProductPolygon
{
    private const MILES_PER_DEGREE_LATITUDE = 69.0;

    /**
     * @return array<int, array{lat: float, lon: float}>
     */
    public function fromPointAndRadius(float $lat, float $lon, float $radiusMiles): array
    {
        $latOffset = $radiusMiles / self::MILES_PER_DEGREE_LATITUDE;
        $lonOffset = $radiusMiles / (self::MILES_PER_DEGREE_LATITUDE * max(cos(deg2rad($lat)), 0.01));

        return [
            ['lat' => $lat + $latOffset, 'lon' => $lon],
            ['lat' => $lat, 'lon' => $lon + $lonOffset],
            ['lat' => $lat - $latOffset, 'lon' => $lon],
            ['lat' => $lat, 'lon' => $lon - $lonOffset],
        ];
    }

    /**
     * @return ?array<int, array{lat: float, lon: float}>
     */
    public function fromRawText(string $raw): ?array
    {
        $points = collect(preg_split('/\r\n|\r|\n/', $raw))
            ->map(fn (string $line): ?array => $this->parseLine($line))
            ->filter()
            ->values()
            ->all();

        return count($points) >= 3 ? $points : null;
    }

    /**
     * @return ?array{lat: float, lon: float}
     */
    private function parseLine(string $line): ?array
    {
        if (! preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $line, $m)) {
            return null;
        }

        return ['lat' => (float) $m[1], 'lon' => (float) $m[2]];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=GenerateTestProductPolygonTest --compact"`
Expected: PASS

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Testing/GenerateTestProductPolygon.php tests/Feature/GenerateTestProductPolygonTest.php
git commit -m "Add GenerateTestProductPolygon for the Test Product Generator's Geography step"
```

---

### Task 9: `CheckPolygonWithinWfo` — the WFO sanity-check query

**Files:**
- Create: `app/Actions/Testing/CheckPolygonWithinWfo.php`
- Test: `tests/Feature/CheckPolygonWithinWfoTest.php`

- [ ] **Step 1: Write the failing tests**

`cwa_boundaries` carries no seeded data of its own (it's populated only by the separate, admin-driven AWIPS Shapefile Management feature) — the existing suite's own established pattern (see `AlertRuleResourceTest.php`'s `DB::table('cwa_boundaries')->insert([...])` calls) is to insert a real fixture row directly in each test that needs one, geometry column included via `DB::raw(...ST_GeomFromText...)`. Match that exact pattern here:

```php
<?php

use App\Actions\Testing\CheckPolygonWithinWfo;
use Illuminate\Support\Facades\DB;

function insertTwcBoundary(): void
{
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'TWC', 'cwa' => 'TWC', 'city' => 'Tucson', 'st' => 'AZ', 'citystate' => 'Tucson AZ',
        'lon' => -110.9, 'lat' => 32.2,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-112 31, -109 31, -109 34, -112 34, -112 31))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('returns true when a polygon\'s centroid falls within the given WFO\'s boundary', function () {
    insertTwcBoundary();

    $points = [
        ['lat' => 32.22, 'lon' => -110.98],
        ['lat' => 32.24, 'lon' => -110.96],
        ['lat' => 32.20, 'lon' => -110.96],
    ];

    expect((new CheckPolygonWithinWfo)($points, 'TWC'))->toBeTrue();
});

test('returns false when a polygon\'s centroid falls well outside the given WFO\'s boundary', function () {
    insertTwcBoundary();

    // Far outside the fixture's boundary box -- middle of the Atlantic ocean.
    $points = [
        ['lat' => 35.0, 'lon' => -40.0],
        ['lat' => 35.2, 'lon' => -40.2],
        ['lat' => 34.8, 'lon' => -40.2],
    ];

    expect((new CheckPolygonWithinWfo)($points, 'TWC'))->toBeFalse();
});

test('returns false for an unknown WFO code', function () {
    $points = [['lat' => 32.22, 'lon' => -110.98], ['lat' => 32.24, 'lon' => -110.96], ['lat' => 32.20, 'lon' => -110.96]];

    expect((new CheckPolygonWithinWfo)($points, 'ZZZ'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=CheckPolygonWithinWfoTest --compact"`
Expected: FAIL — the class doesn't exist yet.

- [ ] **Step 3: Implement the action**

```php
<?php

namespace App\Actions\Testing;

use Illuminate\Support\Facades\DB;

/**
 * Non-blocking sanity check for the Geography step: does a synthetic
 * warning's polygon actually sit within the WFO the admin picked in Step 1?
 * Checks the polygon's simple average-lat/average-lon centroid against
 * cwa_boundaries via a real ST_Contains -- not a hard validation rule (a
 * real warning legitimately spans multiple CWAs), just a signal surfaced to
 * catch an obvious mismatch like the wrong point being clicked.
 *
 * @param  array<int, array{lat: float, lon: float}>  $points
 */
class CheckPolygonWithinWfo
{
    public function __invoke(array $points, string $wfo): bool
    {
        if ($points === []) {
            return false;
        }

        $centroidLat = collect($points)->avg('lat');
        $centroidLon = collect($points)->avg('lon');

        $row = DB::selectOne(
            'SELECT ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains FROM cwa_boundaries WHERE cwa = ?',
            [$centroidLon, $centroidLat, $wfo],
        );

        return $row !== null && ($row->contains === true || $row->contains === 't');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=CheckPolygonWithinWfoTest --compact"`
Expected: PASS — the fixture boundary box spans lon -112 to -109, lat 31 to 34, and the "within" test's points (around 32.2, -110.9) sit well inside that box by construction.

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Testing/CheckPolygonWithinWfo.php tests/Feature/CheckPolygonWithinWfoTest.php
git commit -m "Add the non-blocking polygon-within-WFO sanity check"
```

---

### Task 10: Three Blade templates + `RenderTestProductText`

This is the core templating task. Each template is modeled directly on a real fixture (`SVW.txt`, `SWO.txt`, `AFD.txt`) confirmed earlier in this plan's research. `RenderTestProductText` builds the exact view-data array each template needs from a scenario's `input` array plus its `Product`, and dispatches to the right one based on `has_vtec`/`family`.

**Files:**
- Create: `resources/views/filament/admin/test-products/warning.blade.php`
- Create: `resources/views/filament/admin/test-products/discussion.blade.php`
- Create: `resources/views/filament/admin/test-products/plain-text.blade.php`
- Create: `app/Actions/Testing/RenderTestProductText.php`
- Test: `tests/Feature/RenderTestProductTextTest.php`

- [ ] **Step 1: Write the warning-shape Blade template**

```blade
{{-- resources/views/filament/admin/test-products/warning.blade.php --}}
{{-- $wfo, $ttaaii, $productAwipsAbbr, $issuedAt (Carbon), $vtecAction, $vtecOffice, $vtecPhenomenon,
     $vtecSignificance, $vtecEventNumber, $vtecYear, $vtecBegin (Carbon), $vtecEnd (Carbon),
     $productName, $officeCity, $ugcLine, $hazard, $hazardSource, $impact, $locationsImpacted,
     $callToAction, $polygonLine, $motionLine, $damageTagLines (array<string>) --}}
{{ strtoupper($ttaaii) }} K{{ $wfo }} {{ $issuedAt->format('dHi') }}
{{ strtoupper($productAwipsAbbr) }}{{ $wfo }}
{{ $ugcLine }}
/{{ $vtecAction }}.{{ $vtecOffice }}.{{ $vtecPhenomenon }}.{{ $vtecSignificance }}.{{ $vtecEventNumber }}.{{ $vtecBegin->format('ymd\THi\Z') }}-{{ $vtecEnd->format('ymd\THi\Z') }}/

BULLETIN - IMMEDIATE BROADCAST REQUESTED
{{ $productName }}
National Weather Service {{ $officeCity }}
{{ $issuedAt->format('g:i A T D M j Y') }}

The National Weather Service in {{ $officeCity }} has issued a

* {{ $productName }}.

* Until {{ $vtecEnd->format('g:i A T') }}.

* At {{ $issuedAt->format('g:i A T') }}, this was located near the warned area.

  HAZARD...{{ $hazard }}

  SOURCE...{{ $hazardSource }}

  IMPACT...{{ $impact }}
@if($locationsImpacted)

* Locations impacted include...
  {{ $locationsImpacted }}
@endif

PRECAUTIONARY/PREPAREDNESS ACTIONS...

{{ $callToAction }}

&&

{{ $polygonLine }}
@if($motionLine)
{{ $motionLine }}
@endif
@if(count($damageTagLines) > 0)

@foreach($damageTagLines as $line)
{{ $line }}
@endforeach
@endif

$$
```

- [ ] **Step 2: Write the discussion-shape Blade template**

```blade
{{-- resources/views/filament/admin/test-products/discussion.blade.php --}}
{{-- $ttaaii, $wfo (SPC/WPC's own 3-letter office, e.g. WNS), $issuedAt (Carbon), $discussionNumber,
     $productLabel (e.g. "MESOSCALE DISCUSSION"), $officeName, $areasAffected, $ugcLine,
     $discussionText, $riskLevel, $polygonLine, $attnWfos (array<string>) --}}
ACUS11 K{{ $wfo }} {{ $issuedAt->format('dHi') }}
SWOMCD
SPC MCD {{ $issuedAt->format('dHi') }}
{{ $ugcLine }}

{{ $productLabel }} {{ $discussionNumber }}
{{ $officeName }}
{{ $issuedAt->format('gi A T D M j Y') }}

AREAS AFFECTED...{{ $areasAffected }}

CONCERNING...{{ $riskLevel }}

VALID {{ $issuedAt->format('dHi') }}Z

DISCUSSION...{{ $discussionText }}

ATTN...WFO...{{ implode('...', $attnWfos) }}...

{{ $polygonLine }}
```

- [ ] **Step 3: Write the plain-text-shape Blade template**

```blade
{{-- resources/views/filament/admin/test-products/plain-text.blade.php --}}
{{-- $ttaaii, $wfo, $issuedAt (Carbon), $productName, $officeCity, $ugcLine (nullable), $bodyText --}}
{{ strtoupper($ttaaii) }} K{{ $wfo }} {{ $issuedAt->format('dHi') }}

{{ strtoupper($productName) }}
NATIONAL WEATHER SERVICE {{ strtoupper($officeCity) }}
{{ $issuedAt->format('g:i A T D M j Y') }}
@if($ugcLine)

{{ $ugcLine }}
@endif

{{ $bodyText }}

$$
```

- [ ] **Step 4: Write the failing test for `RenderTestProductText`**

```php
<?php

use App\Actions\Testing\RenderTestProductText;
use App\Models\Product;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

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

    expect($text)->toContain('/NEW.KPAH.SV.W.9001.260918T0052Z-260918T0115Z/')
        ->and($text)->toContain('MOC023-MOC181-')
        ->and($text)->toContain('HAZARD...60 mph wind gusts.')
        ->and($text)->toContain('SOURCE...Radar indicated.')
        ->and($text)->toContain('IMPACT...Expect damage to roofs, siding, and trees.')
        ->and($text)->toContain('PRECAUTIONARY/PREPAREDNESS ACTIONS...')
        ->and($text)->toContain('LAT...LON')
        ->and($text)->toContain('HAIL THREAT...RADAR INDICATED')
        ->and($text)->toContain('$$');
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

    $metadata = (new App\Actions\Pipeline\ExtractProductMetadata)($text, null);

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
        'risk_level' => 'SEVERE POTENTIAL...WATCH UNLIKELY',
        'polygon_mode' => 'point_radius',
        'polygon_center_lat' => 33.5,
        'polygon_center_lon' => -112.5,
        'polygon_radius_miles' => 20,
        'attn_wfos' => ['TWC', 'PSR'],
    ]);

    $metadata = (new App\Actions\Pipeline\ExtractProductMetadata)($text, 'swomcd');

    expect($metadata['product_id'])->toBe($product->id)
        ->and($metadata['polygon'])->toHaveCount(4);
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
```

- [ ] **Step 5: Run tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=RenderTestProductTextTest --compact"`
Expected: FAIL — `RenderTestProductText` doesn't exist yet.

- [ ] **Step 6: Implement `RenderTestProductText`**

```php
<?php

namespace App\Actions\Testing;

use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;

/**
 * Renders one of three Blade templates -- warning, discussion, plain-text --
 * into realistic raw NWS product text from a scenario's stored wizard input
 * plus its Product. Shape is derived from the product's own has_vtec/family
 * flags (see Product Catalog's generator metadata columns), not chosen
 * directly by the admin.
 *
 * @param  array<string, mixed>  $input
 */
class RenderTestProductText
{
    public function __invoke(Product $product, array $input): string
    {
        // Honors an explicit input.issued_at when the caller set one (e.g. a
        // hand-built test fixture); otherwise defaults to now -- matches the
        // wizard's own DateTimePicker default(now()) on Create, and
        // EditTestProductScenario::mutateFormDataBeforeFill() (Task 16)
        // resets this field to now every time an existing scenario is
        // reopened, so replaying/editing a scenario always looks
        // freshly issued rather than replaying a stale original timestamp.
        $issuedAt = isset($input['issued_at']) ? Carbon::parse($input['issued_at']) : Carbon::now();

        return match (true) {
            $product->family->value === 'spc_wpc' => $this->renderDiscussion($product, $input, $issuedAt),
            $product->has_vtec => $this->renderWarning($product, $input, $issuedAt),
            default => $this->renderPlainText($product, $input, $issuedAt),
        };
    }

    private function renderWarning(Product $product, array $input, Carbon $issuedAt): string
    {
        $vtecBegin = $issuedAt->copy();
        $vtecEnd = $issuedAt->copy()->addMinutes((int) ($input['expires_minutes'] ?? 60));

        $polygonPoints = $this->resolvePolygon($input);
        $ugcCodes = $input['ugc_codes'] ?? [];

        return View::make('filament.admin.test-products.warning', [
            'wfo' => $input['wfo'],
            'ttaaii' => 'wuus53',
            'productAwipsAbbr' => strtolower(str_replace('.', '', $product->code)),
            'issuedAt' => $issuedAt,
            'vtecAction' => $input['vtec_action'] ?? 'NEW',
            'vtecOffice' => 'K'.$input['wfo'],
            'vtecPhenomenon' => explode('.', $product->code)[0],
            'vtecSignificance' => explode('.', $product->code)[1] ?? 'W',
            'vtecEventNumber' => $input['vtec_event_number'],
            'vtecYear' => $issuedAt->year,
            'vtecBegin' => $vtecBegin,
            'vtecEnd' => $vtecEnd,
            'productName' => $product->name,
            'officeCity' => $this->officeCityFor($input['wfo']),
            'ugcLine' => $this->buildUgcLine($ugcCodes, $vtecEnd),
            'hazard' => $input['hazard'] ?? '',
            'hazardSource' => $input['hazard_source'] ?? '',
            'impact' => $input['impact'] ?? '',
            'locationsImpacted' => $input['locations_impacted'] ?? null,
            'callToAction' => $input['call_to_action'] ?? '',
            'polygonLine' => $this->buildPolygonLine($polygonPoints),
            'motionLine' => $this->buildMotionLine($input),
            'damageTagLines' => $input['damage_tags'] ?? [],
        ])->render();
    }

    private function renderDiscussion(Product $product, array $input, Carbon $issuedAt): string
    {
        $polygonPoints = $this->resolvePolygon($input);

        return View::make('filament.admin.test-products.discussion', [
            'ttaaii' => 'acus11',
            'wfo' => $input['wfo'],
            'issuedAt' => $issuedAt,
            'discussionNumber' => $input['discussion_number'] ?? '9001',
            'productLabel' => $product->code === 'WPC.MPD' ? 'MESOSCALE PRECIPITATION DISCUSSION' : 'MESOSCALE DISCUSSION',
            'officeName' => $product->code === 'WPC.MPD' ? 'NWS WEATHER PREDICTION CENTER COLLEGE PARK MD' : 'NWS STORM PREDICTION CENTER NORMAN OK',
            'areasAffected' => $input['areas_affected'] ?? '',
            'ugcLine' => $this->buildUgcLine($input['ugc_codes'] ?? [], $issuedAt->copy()->addHours(2)),
            'discussionText' => $input['discussion_text'] ?? '',
            'riskLevel' => $input['risk_level'] ?? '',
            'polygonLine' => $this->buildCombinedPolygonLine($polygonPoints),
            'attnWfos' => $input['attn_wfos'] ?? [$input['wfo']],
        ])->render();
    }

    private function renderPlainText(Product $product, array $input, Carbon $issuedAt): string
    {
        $ugcCodes = $input['ugc_codes'] ?? [];

        return View::make('filament.admin.test-products.plain-text', [
            'ttaaii' => 'fxus61',
            'wfo' => $input['wfo'],
            'issuedAt' => $issuedAt,
            'productName' => $product->name,
            'officeCity' => $this->officeCityFor($input['wfo']),
            'ugcLine' => $ugcCodes !== [] ? $this->buildUgcLine($ugcCodes, $issuedAt->copy()->addHours(2)) : null,
            'bodyText' => $input['body_text'] ?? '',
        ])->render();
    }

    /**
     * @return ?array<int, array{lat: float, lon: float}>
     */
    private function resolvePolygon(array $input): ?array
    {
        // The wizard's Geography step (Task 14) writes a boolean
        // input.polygon_advanced (the "Advanced: paste raw coordinates"
        // toggle); input.polygon_mode === 'raw' is also accepted so a
        // caller building $input by hand (as this file's own tests, and
        // GenerateTestProductTest/the end-to-end test, do) can use whichever
        // is more convenient -- both must keep working, not just one.
        $usesRawCoordinates = ($input['polygon_advanced'] ?? false) || (($input['polygon_mode'] ?? null) === 'raw');

        if ($usesRawCoordinates) {
            return app(GenerateTestProductPolygon::class)->fromRawText($input['polygon_raw'] ?? '');
        }

        if (! isset($input['polygon_center_lat'], $input['polygon_center_lon'], $input['polygon_radius_miles'])) {
            return null;
        }

        return app(GenerateTestProductPolygon::class)->fromPointAndRadius(
            (float) $input['polygon_center_lat'],
            (float) $input['polygon_center_lon'],
            (float) $input['polygon_radius_miles'],
        );
    }

    private function buildUgcLine(array $codes, Carbon $purgeTime): string
    {
        if ($codes === []) {
            return '';
        }

        return implode('-', $codes).'-'.$purgeTime->format('dHi').'-';
    }

    /**
     * Warning-family format: separate space-delimited 4-5 digit tokens per
     * value (see ExtractProductMetadata::parsePolygon()).
     */
    private function buildPolygonLine(?array $points): string
    {
        if ($points === null || $points === []) {
            return '';
        }

        $tokens = collect($points)->flatMap(fn (array $p): array => [
            $this->encodeCoordinatePart(abs($p['lat'])),
            $this->encodeCoordinatePart(abs($p['lon'])),
        ]);

        return 'LAT...LON '.$tokens->implode(' ');
    }

    /**
     * SPC MD/WPC MPD format: one concatenated 8-9 digit token per pair (see
     * ExtractProductMetadata::decodeCombinedToken()) -- longitudes here are
     * always >= 100 degrees (SPC/WPC's CONUS coverage), so the leading "1"
     * is always dropped to match that method's decode convention.
     */
    private function buildCombinedPolygonLine(?array $points): string
    {
        if ($points === null || $points === []) {
            return '';
        }

        $tokens = collect($points)->map(function (array $p): string {
            $latToken = $this->encodeCoordinatePart(abs($p['lat']));
            $lonToken = $this->encodeCoordinatePart(abs($p['lon']));

            return $latToken.substr($lonToken, 1);
        });

        return 'LAT...LON   '.$tokens->implode(' ');
    }

    private function encodeCoordinatePart(float $value): string
    {
        return str_replace('.', '', number_format($value, 2));
    }

    private function buildMotionLine(array $input): ?string
    {
        if (! isset($input['motion_direction_degrees'], $input['motion_speed_kt'])) {
            return null;
        }

        $lat = $input['polygon_center_lat'] ?? 0.0;
        $lon = abs($input['polygon_center_lon'] ?? 0.0);

        return sprintf(
            'TIME...MOT...LOC %s %dDEG %dKT %s %s',
            Carbon::now()->format('Hi\Z'),
            $input['motion_direction_degrees'],
            $input['motion_speed_kt'],
            $this->encodeCoordinatePart(abs($lat)),
            $this->encodeCoordinatePart($lon),
        );
    }

    private function officeCityFor(string $wfo): string
    {
        $row = \Illuminate\Support\Facades\DB::table('cwa_boundaries')->where('cwa', $wfo)->first();

        return $row?->citystate ?? $wfo;
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=RenderTestProductTextTest --compact"`
Expected: PASS. If the discussion-shape or warning-shape round-trip tests fail on exact string formatting (e.g. `encodeCoordinatePart`'s zero-padding not matching what `decodeCoordinatePart`/`decodeCombinedToken` expect for a 4-vs-5-digit boundary), adjust `encodeCoordinatePart` so a value under 100 always produces exactly 4 digits and a value >= 100 always produces exactly 5 digits (pad with `str_pad` on the integer part before removing the decimal point) — verify by round-tripping a known value through both encode and the real `ExtractProductMetadata`'s private decode logic via the `RenderTestProductTextTest` assertions already written, not by guessing.

- [ ] **Step 8: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add resources/views/filament/admin/test-products/ app/Actions/Testing/RenderTestProductText.php tests/Feature/RenderTestProductTextTest.php
git commit -m "Add the three shape-specific Blade templates and RenderTestProductText"
```

---

### Task 11: `GenerateTestProduct` — the orchestrator action

**Files:**
- Create: `app/Actions/Testing/GenerateTestProduct.php`
- Test: `tests/Feature/GenerateTestProductTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Actions\Testing\GenerateTestProduct;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;

test('generates a warning-shape product, marks it is_test, and links it to the scenario', function () {
    $product = Product::where('code', 'SV.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => [
            'mode' => 'new',
            'vtec_action' => 'NEW',
            'vtec_event_number' => '9010',
            'wfo' => 'PAH',
            'expires_minutes' => 30,
            'ugc_codes' => ['MOC023'],
            'hazard' => '60 mph wind gusts.',
            'hazard_source' => 'Radar indicated.',
            'impact' => 'Tree damage possible.',
            'call_to_action' => 'Move to an interior room.',
            'polygon_mode' => 'point_radius',
            'polygon_center_lat' => 36.83,
            'polygon_center_lon' => -89.12,
            'polygon_radius_miles' => 8,
            'damage_tags' => [],
        ],
    ]);

    $result = app(GenerateTestProduct::class)($scenario);

    expect($result)->toBeInstanceOf(IngestedProduct::class)
        ->and($result->is_test)->toBeTrue()
        ->and($result->test_product_scenario_id)->toBe($scenario->id)
        ->and($result->vtec_office)->toBe('KPAH')
        ->and($result->vtec_event_number)->toBe('9010')
        ->and($result->product_id)->toBe($product->id);
});

test('a second generation from the same scenario creates a second, distinct IngestedProduct row', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Clear skies expected.'],
    ]);

    $first = app(GenerateTestProduct::class)($scenario);
    $second = app(GenerateTestProduct::class)($scenario);

    expect($first->id)->not->toBe($second->id)
        ->and($scenario->fresh()->ingestedProducts)->toHaveCount(2)
        ->and(IngestedProduct::where('is_test', true)->count())->toBe(2);
});

test('draws warning-family event numbers from the reserved 9000-9999 range, never colliding with a real event', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => [
            'mode' => 'new', 'vtec_action' => 'NEW', 'wfo' => 'TWC', 'expires_minutes' => 45,
            'ugc_codes' => ['AZC019'], 'hazard' => 'a', 'hazard_source' => 'b', 'impact' => 'c', 'call_to_action' => 'd',
            'polygon_mode' => 'point_radius', 'polygon_center_lat' => 32.2, 'polygon_center_lon' => -110.9, 'polygon_radius_miles' => 5,
            'damage_tags' => [],
        ],
    ]);

    $result = app(GenerateTestProduct::class)($scenario);

    expect((int) $result->vtec_event_number)->toBeGreaterThanOrEqual(9000)
        ->and((int) $result->vtec_event_number)->toBeLessThanOrEqual(9999);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=GenerateTestProductTest --compact"`
Expected: FAIL — the class doesn't exist yet.

- [ ] **Step 3: Implement `GenerateTestProduct`**

```php
<?php

namespace App\Actions\Testing;

use App\Actions\Pipeline\IngestProductText;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\TestProductScenario;
use Illuminate\Support\Str;

/**
 * Orchestrates one "Generate" click: renders the scenario's stored input
 * into raw text via RenderTestProductText, then feeds it into the exact
 * same IngestProductText action a real product uses -- tagged is_test and
 * linked back to the scenario that produced it.
 */
class GenerateTestProduct
{
    public function __construct(
        private readonly RenderTestProductText $renderTestProductText,
        private readonly IngestProductText $ingestProductText,
    ) {}

    public function __invoke(TestProductScenario $scenario): ?IngestedProduct
    {
        $product = $scenario->product;
        $input = $scenario->input;

        if (($input['mode'] ?? 'new') === 'new' && $product->has_vtec && blank($input['vtec_event_number'] ?? null)) {
            $input['vtec_event_number'] = (string) $this->nextReservedEventNumber();
            $input['vtec_action'] = 'NEW';
        }

        $text = ($this->renderTestProductText)($product, $input);

        $wfo = $input['wfo'];
        $isCap = false;
        $filePath = "test-generated/{$scenario->id}/".Str::uuid()->toString().'.txt';

        $identity = [
            'cccc' => 'k'.strtolower($wfo),
            'ttaaii' => match (true) {
                $product->family->value === 'spc_wpc' => 'acus11',
                $product->has_vtec => 'wuus53',
                default => 'fxus61',
            },
            'awipsid' => $this->synthesizeAwipsId($product, $wfo),
            'wmo_ddhhmm' => now()->format('dHi'),
            'nwws_id' => 'TEST-'.Str::random(8),
        ];

        return ($this->ingestProductText)($text, $filePath, $identity, $isCap, [
            'is_test' => true,
            'test_product_scenario_id' => $scenario->id,
        ]);
    }

    /**
     * Draws a 4-digit VTEC event number from a reserved high range never
     * used by real NWS sequential numbering (see the design doc), so a
     * synthetic event's VTEC key can never collide with a real active one.
     */
    private function nextReservedEventNumber(): int
    {
        do {
            $candidate = random_int(9000, 9999);
        } while (ActiveWwa::where('vtec_event_number', (string) $candidate)->where('status', 'Active')->exists());

        return $candidate;
    }

    /**
     * Matches ExtractProductMetadata's real AWIPS crosswalk exactly for the
     * non-VTEC products that depend on it to resolve product_id (SPC MD,
     * WPC MPD, AFD) -- for a has_vtec product, the value here is never
     * actually consulted by the parser (VTEC-line resolution short-circuits
     * first), so it only needs to look plausible.
     */
    private function synthesizeAwipsId(\App\Models\Product $product, string $wfo): string
    {
        return match ($product->code) {
            'SPC.MD' => 'swomcd',
            'WPC.MPD' => 'ffgmpd',
            'AFD' => 'afd'.strtolower($wfo),
            default => strtolower(str_replace('.', '', $product->code)).'test',
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=GenerateTestProductTest --compact"`
Expected: PASS

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Testing/GenerateTestProduct.php tests/Feature/GenerateTestProductTest.php
git commit -m "Add GenerateTestProduct, the Test Product Generator's orchestrator action"
```

---

### Task 12: `TestProductScenarioResource` skeleton with environment/staff gating

**Files:**
- Create: `app/Filament/Admin/Resources/TestProductScenarios/TestProductScenarioResource.php`
- Create: `app/Filament/Admin/Resources/TestProductScenarios/Pages/ListTestProductScenarios.php`
- Create: `app/Filament/Admin/Resources/TestProductScenarios/Pages/CreateTestProductScenario.php`
- Create: `app/Filament/Admin/Resources/TestProductScenarios/Pages/EditTestProductScenario.php`
- Create: `app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php` (empty steps for now — filled in Tasks 13-16)
- Create: `app/Filament/Admin/Resources/TestProductScenarios/Tables/TestProductScenariosTable.php` (minimal for now — filled in Task 17)
- Test: `tests/Feature/TestProductScenarioResourceAccessTest.php`

- [ ] **Step 1: Write the failing gating test**

```php
<?php

use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the resource is hidden and inaccessible when APP_ENV is production', function () {
    config(['app.env' => 'production']);
    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff);

    expect(TestProductScenarioResource::shouldRegisterNavigation())->toBeFalse()
        ->and(TestProductScenarioResource::canAccess())->toBeFalse();
});

test('a staff user can access the resource outside production', function () {
    config(['app.env' => 'testing']);
    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff);

    expect(TestProductScenarioResource::shouldRegisterNavigation())->toBeTrue()
        ->and(TestProductScenarioResource::canAccess())->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioResourceAccessTest --compact"`
Expected: FAIL — the class doesn't exist yet.

- [ ] **Step 3: Create the minimal Form and Table classes (fleshed out in later tasks)**

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Schemas;

use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;

class TestProductScenarioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(null)
            ->components([
                Wizard::make(self::steps())->contained(false),
            ]);
    }

    /**
     * @return array<Step>
     */
    public static function steps(): array
    {
        return [
            Step::make('Product & Timing'),
            Step::make('Geography'),
            Step::make('Content'),
            Step::make('Review & Generate'),
        ];
    }
}
```

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TestProductScenariosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->placeholder('Untitled scenario'),
                TextColumn::make('product.name')
                    ->label('Product'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
```

- [ ] **Step 4: Create the Resource class with environment/staff gating**

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios;

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\EditTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\ListTestProductScenarios;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\Tables\TestProductScenariosTable;
use App\Models\TestProductScenario;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TestProductScenarioResource extends Resource
{
    protected static ?string $model = TestProductScenario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Test Product Generator';

    protected static ?string $modelLabel = 'test product scenario';

    /**
     * Hidden entirely in production -- not merely disabled -- so there is
     * no code path by which a generated product can enter a live
     * environment. See the design doc's Safety Model section.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return ! app()->environment('production');
    }

    public static function canAccess(): bool
    {
        return ! app()->environment('production');
    }

    public static function form(Schema $schema): Schema
    {
        return TestProductScenarioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TestProductScenariosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTestProductScenarios::route('/'),
            'create' => CreateTestProductScenario::route('/create'),
            'edit' => EditTestProductScenario::route('/{record}/edit'),
        ];
    }
}
```

- [ ] **Step 5: Create the three page classes**

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use Filament\Resources\Pages\ListRecords;

class ListTestProductScenarios extends ListRecords
{
    protected static string $resource = TestProductScenarioResource::class;
}
```

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class CreateTestProductScenario extends CreateRecord
{
    use HasWizard;

    protected static string $resource = TestProductScenarioResource::class;

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return TestProductScenarioForm::steps();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
```

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class EditTestProductScenario extends EditRecord
{
    use HasWizard;

    protected static string $resource = TestProductScenarioResource::class;

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return TestProductScenarioForm::steps();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
```

(`CreateTestProductScenario`'s `getRedirectUrl()` override follows this same codebase's established convention — see the earlier "redirect Alert/Location creation to the list page" fix — new Create pages should be built with this from the start, not patched afterward.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioResourceAccessTest --compact"`
Expected: PASS

- [ ] **Step 7: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Admin/Resources/TestProductScenarios/ tests/Feature/TestProductScenarioResourceAccessTest.php
git commit -m "Add the TestProductScenario admin resource skeleton, hidden outside non-production"
```

---

### Task 13: Wizard Step 1 — Product & Timing (New / Continue existing event)

**Files:**
- Modify: `app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php`
- Test: `tests/Feature/TestProductScenarioWizardStep1Test.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Models\ActiveWwa;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    \Filament::setCurrentPanel('admin');
});

test('the product select only offers v1-eligible products, including draft ones', function () {
    $draftWarning = Product::factory()->create(['family' => 'warning', 'status' => 'draft', 'has_vtec' => true]);
    $outOfScope = Product::factory()->create(['family' => 'aviation']);

    livewire(CreateTestProductScenario::class)
        ->assertFormFieldExists('product_id');

    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    expect(\App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm::eligibleProductsQuery()->pluck('id'))
        ->toContain($tornadoWarning->id)
        ->toContain($draftWarning->id)
        ->not->toContain($outOfScope->id);
});

test('the "continue existing event" option only appears once an open synthetic event exists, keyed by its originating scenario', function () {
    livewire(CreateTestProductScenario::class)
        ->assertFormFieldExists('mode');

    $product = Product::where('code', 'TO.W')->sole();
    $scenario = \App\Models\TestProductScenario::factory()->for($product)->create();
    $ingestedProduct = \App\Models\IngestedProduct::factory()->create([
        'is_test' => true, 'test_product_scenario_id' => $scenario->id,
    ]);
    $wwa = ActiveWwa::factory()->create([
        'product_id' => $product->id, 'is_test' => true, 'status' => 'Active',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '9020', 'vtec_year' => now()->year,
        'latest_ingested_product_id' => $ingestedProduct->id,
    ]);

    $options = \App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm::openSyntheticEventOptions();

    expect($options)->toHaveCount(1)
        ->and($options)->toHaveKey($scenario->id);
});

test('choosing a continued event inherits its product, WFO, event number, and prior content into form state', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $scenario = \App\Models\TestProductScenario::factory()->for($product)->create([
        'input' => ['hazard' => 'Large hail.', 'ugc_codes' => ['AZC019']],
    ]);
    $ingestedProduct = \App\Models\IngestedProduct::factory()->create([
        'is_test' => true, 'test_product_scenario_id' => $scenario->id,
    ]);
    ActiveWwa::factory()->create([
        'product_id' => $product->id, 'is_test' => true, 'status' => 'Active',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '9020', 'vtec_year' => now()->year,
        'latest_ingested_product_id' => $ingestedProduct->id,
    ]);

    livewire(CreateTestProductScenario::class)
        ->set('data.mode', 'continue')
        ->set('data.continues_scenario_id', $scenario->id)
        ->assertFormSet([
            'product_id' => $product->id,
            'input.wfo' => 'TWC',
            'input.vtec_event_number' => '9020',
            'input.hazard' => 'Large hail.',
            'input.ugc_codes' => ['AZC019'],
        ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioWizardStep1Test --compact"`
Expected: FAIL — `product_id`/`mode` fields don't exist on the form yet, and the two static helper methods don't exist.

- [ ] **Step 3: Build Step 1**

Replace `app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php` with:

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Schemas;

use App\Models\ActiveWwa;
use App\Models\Product;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class TestProductScenarioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(null)
            ->components([
                Wizard::make(self::steps())->contained(false),
            ]);
    }

    /**
     * @return array<Step>
     */
    public static function steps(): array
    {
        return [
            self::productAndTimingStep(),
            Step::make('Geography'),
            Step::make('Content'),
            Step::make('Review & Generate'),
        ];
    }

    /**
     * Known v1 simplification: a real CON/EXP/CAN continuation is issued as
     * its own distinct product (e.g. an "SVS" Severe Weather Statement,
     * not another "SV.W" Severe Thunderstorm Warning) with its own MND
     * header wording and bullet shape. The "Continue" flow here instead
     * re-renders the ORIGINAL event's product (inherited via
     * inheritFromContinuedScenario() below) through the same warning
     * template, just with vtec_action changed -- close enough to exercise
     * the VTEC lifecycle (NEW -> CON -> EXP/CAN) and matching/dispatch
     * logic end to end, but the raw text's own product name/wording won't
     * read as a textbook-accurate SVS. Revisit with a dedicated
     * continuation template if that precision is ever needed.
     */
    protected static function productAndTimingStep(): Step
    {
        return Step::make('Product & Timing')
            ->schema([
                Radio::make('mode')
                    ->label('')
                    ->options(['new' => 'New event', 'continue' => 'Continue existing event'])
                    ->default('new')
                    ->live()
                    ->visible(fn (): bool => self::openSyntheticEventOptions() !== []),
                Select::make('continues_scenario_id')
                    ->label('Continue which event?')
                    ->options(fn (): array => self::openSyntheticEventOptions())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::inheritFromContinuedScenario($set, $get))
                    ->visible(fn (Get $get): bool => $get('mode') === 'continue'),
                Select::make('input.vtec_action')
                    ->label('Action')
                    ->options(['CON' => 'Continue (CON)', 'EXP' => 'Expire (EXP)', 'CAN' => 'Cancel (CAN)'])
                    ->required()
                    ->visible(fn (Get $get): bool => $get('mode') === 'continue'),
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn (): array => self::eligibleProductsQuery()
                        ->orderBy('sort_order')->get()
                        ->groupBy(fn (Product $p): string => $p->family->label())
                        ->map(fn ($products) => $products->pluck('name', 'id')->all())
                        ->all())
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => $get('mode') !== 'continue'),
                Select::make('input.wfo')
                    ->label('NWS office (WFO)')
                    ->searchable()
                    ->required()
                    ->options(fn (): array => \Illuminate\Support\Facades\DB::table('cwa_boundaries')->orderBy('cwa')->get(['cwa', 'citystate'])
                        ->mapWithKeys(fn ($row): array => [$row->cwa => "{$row->cwa} — {$row->citystate}"])
                        ->all())
                    ->visible(fn (Get $get): bool => $get('mode') !== 'continue'),
                DateTimePicker::make('input.issued_at')
                    ->label('Issuance time')
                    ->default(now())
                    ->seconds(false)
                    ->required(),
                TextInput::make('input.expires_minutes')
                    ->label('Expires in (minutes)')
                    ->numeric()
                    ->minValue(1)
                    ->default(60)
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
            ]);
    }

    /**
     * v1-eligible products: warning/watch/advisory (VTEC), spc_wpc
     * restricted to has_polygon (MD/MPD -- excludes the shapefile-based
     * Outlook family, which shares the same family value but is out of
     * scope), forecast_discussion (AFD), and SPS specifically (the one
     * plain-text "statement" that stands on its own). SVS is deliberately
     * excluded here even though it's has_vtec -- a real SVS's VTEC
     * phenomenon/significance is always inherited from the warning it
     * continues, so it has no meaningful "New event" shape of its own; it's
     * only ever reachable through the "Continue existing event" flow
     * (Task 13's inheritFromContinuedScenario()), which is exactly how a
     * real SVS comes to exist. Every status, including draft, is included
     * for the eligible families -- this is the concrete use case draft
     * status was built for.
     */
    public static function eligibleProductsQuery(): Builder
    {
        return Product::query()
            ->where(function (Builder $q): void {
                $q->whereIn('family', ['warning', 'watch', 'advisory'])
                    ->orWhere(fn (Builder $q2) => $q2->where('family', 'spc_wpc')->where('has_polygon', true))
                    ->orWhere('family', 'forecast_discussion')
                    ->orWhere('code', 'SPS');
            });
    }

    /**
     * Keyed by TestProductScenario id (via each open event's most recent
     * IngestedProduct -> its originating scenario), NOT by ActiveWwa id --
     * test_product_scenarios.continues_scenario_id is a self-referencing FK
     * to test_product_scenarios, so the Select's stored value must be a
     * scenario id for a direct save to persist the right relationship.
     * Excludes any ActiveWwa row whose latest IngestedProduct has no
     * scenario link at all (defense-in-depth; every row created through
     * GenerateTestProduct always sets one).
     *
     * @return array<int, string>
     */
    public static function openSyntheticEventOptions(): array
    {
        return ActiveWwa::query()
            ->where('is_test', true)
            ->where('status', 'Active')
            ->whereHas('latestIngestedProduct', fn (Builder $q) => $q->whereNotNull('test_product_scenario_id'))
            ->with(['product', 'latestIngestedProduct'])
            ->latest('updated_at')
            ->get()
            ->mapWithKeys(fn (ActiveWwa $wwa): array => [
                $wwa->latestIngestedProduct->test_product_scenario_id => sprintf(
                    '%s — %s — #%s — issued %s',
                    $wwa->product?->name ?? $wwa->vtec_phenomenon.'.'.$wwa->vtec_significance,
                    $wwa->vtec_office,
                    $wwa->vtec_event_number,
                    $wwa->updated_at->diffForHumans(),
                ),
            ])
            ->all();
    }

    /**
     * Copies the continued event's product/WFO/event-number identity plus
     * its most recent geography/content fields into this new scenario's
     * form state, so the admin only has to change what's actually different
     * for the follow-up (e.g. an updated impact statement) rather than
     * re-entering everything.
     */
    protected static function inheritFromContinuedScenario(Set $set, Get $get): void
    {
        $continuedScenarioId = $get('continues_scenario_id');
        if ($continuedScenarioId === null) {
            return;
        }

        $continuedScenario = \App\Models\TestProductScenario::find($continuedScenarioId);
        $wwa = ActiveWwa::query()
            ->where('is_test', true)
            ->where('status', 'Active')
            ->whereHas('latestIngestedProduct', fn (Builder $q) => $q->where('test_product_scenario_id', $continuedScenarioId))
            ->first();

        if ($continuedScenario === null || $wwa === null) {
            return;
        }

        $set('product_id', $continuedScenario->product_id);
        $set('input.wfo', $wwa->vtec_office !== null ? substr($wwa->vtec_office, 1) : null);
        $set('input.vtec_event_number', $wwa->vtec_event_number);

        foreach (['ugc_codes', 'hazard', 'hazard_source', 'impact', 'locations_impacted', 'call_to_action', 'polygon_center_lat', 'polygon_center_lon', 'polygon_radius_miles', 'polygon_advanced', 'polygon_raw', 'damage_tags'] as $key) {
            if (array_key_exists($key, $continuedScenario->input)) {
                $set("input.{$key}", $continuedScenario->input[$key]);
            }
        }
    }

    protected static function selectedProductHasVtec(Get $get): bool
    {
        if ($get('mode') === 'continue') {
            return true;
        }

        $product = Product::find($get('product_id'));

        return $product?->has_vtec ?? false;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioWizardStep1Test --compact"`
Expected: PASS

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php tests/Feature/TestProductScenarioWizardStep1Test.php
git commit -m "Build wizard Step 1: Product & Timing, with the continue-existing-event flow"
```

---

### Task 14: Wizard Step 2 — Geography (UGC picker scoped to WFO, polygon point+radius/raw toggle, WFO sanity check)

**Files:**
- Modify: `app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php`
- Test: `tests/Feature/TestProductScenarioWizardStep2Test.php`

- [ ] **Step 1: Write the failing tests**

`public_zones` carries no seeded data of its own — matching Task 9's fix above and `AlertRuleResourceTest.php`'s own established pattern (e.g. its "the CountyZone picker options include real counties and zones for the filtered state" test), insert real fixture rows directly rather than relying on ambient data:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    \Filament::setCurrentPanel('admin');
});

test('UGC options are scoped to zones/counties whose cwa matches the chosen WFO', function () {
    DB::table('public_zones')->insert([
        'state' => 'AZ', 'cwa' => 'TWC', 'zone' => '019', 'name' => 'Pima', 'state_zone' => 'AZ019',
        'lon' => -110.9, 'lat' => 32.2,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-112 31, -109 31, -109 34, -112 34, -112 31))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('public_zones')->insert([
        'state' => 'NY', 'cwa' => 'OKX', 'zone' => '072', 'name' => 'Suffolk', 'state_zone' => 'NY072',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $options = \App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm::wfoScopedUgcOptions('TWC');

    expect($options)->toHaveKey('AZZ019')
        ->and($options)->not->toHaveKey('NYZ072');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioWizardStep2Test --compact"`
Expected: FAIL — `wfoScopedUgcOptions()` doesn't exist yet.

- [ ] **Step 3: Add the Geography step**

In `app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php`:

Add these imports at the top:

```php
use App\Actions\Testing\CheckPolygonWithinWfo;
use App\Actions\Testing\GenerateTestProductPolygon;
use App\Models\ActiveWwa;
use App\Models\Product;
use Fahiem\FilamentPinpoint\Pinpoint;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
```

Replace the `steps()` method's placeholder `Step::make('Geography'),` with `self::geographyStep(),`, and add the new method:

```php
    protected static function geographyStep(): Step
    {
        return Step::make('Geography')
            ->schema([
                CheckboxList::make('input.ugc_codes')
                    ->label('Counties / zones (UGC)')
                    ->columns(2)
                    ->options(fn (Get $get): array => self::wfoScopedUgcOptions($get('input.wfo') ?? ''))
                    ->visible(fn (Get $get): bool => self::selectedProductHasUgc($get)),
                Toggle::make('input.polygon_advanced')
                    ->label('Advanced: paste raw coordinates')
                    ->live()
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get)),
                Pinpoint::make('input.polygon_center')
                    ->label('Center point')
                    ->provider('leaflet')
                    ->latField('input.polygon_center_lat')
                    ->lngField('input.polygon_center_lon')
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && ! $get('input.polygon_advanced')),
                TextInput::make('input.polygon_center_lat')
                    ->hidden()
                    ->live()
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && ! $get('input.polygon_advanced')),
                TextInput::make('input.polygon_center_lon')
                    ->hidden()
                    ->live()
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && ! $get('input.polygon_advanced')),
                TextInput::make('input.polygon_radius_miles')
                    ->label('Radius (miles)')
                    ->numeric()
                    ->minValue(1)
                    ->default(10)
                    ->live()
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && ! $get('input.polygon_advanced')),
                Textarea::make('input.polygon_raw')
                    ->label('Coordinates (one "lat,lon" pair per line)')
                    ->rows(4)
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && $get('input.polygon_advanced')),
                Placeholder::make('wfo_sanity_warning')
                    ->label('')
                    ->content(fn (Get $get): ?string => self::wfoSanityWarning($get))
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && self::wfoSanityWarning($get) !== null),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function wfoScopedUgcOptions(string $wfo): array
    {
        if ($wfo === '') {
            return [];
        }

        $countyOptions = \Illuminate\Support\Facades\DB::table('counties')->where('cwa', 'like', "%{$wfo}%")->get(['fips', 'state', 'countyname'])
            ->mapWithKeys(function ($c): array {
                $code = strtoupper($c->state).'C'.substr($c->fips, -3);

                return [$code => "{$code} — {$c->countyname} County, {$c->state}"];
            });

        $zoneOptions = \Illuminate\Support\Facades\DB::table('public_zones')->where('cwa', 'like', "%{$wfo}%")->get(['state_zone', 'state', 'zone', 'name'])
            ->mapWithKeys(function ($z): array {
                $code = strtoupper($z->state).'Z'.$z->zone;

                return [$code => "{$code} — {$z->name}, {$z->state}"];
            });

        return $countyOptions->union($zoneOptions)->all();
    }

    protected static function selectedProductHasUgc(Get $get): bool
    {
        $product = Product::find($get('product_id'));

        return $product?->has_ugc ?? false;
    }

    protected static function selectedProductHasPolygon(Get $get): bool
    {
        $product = Product::find($get('product_id'));

        return $product?->has_polygon ?? false;
    }

    protected static function wfoSanityWarning(Get $get): ?string
    {
        $wfo = $get('input.wfo');
        if (blank($wfo)) {
            return null;
        }

        $points = self::resolveGeographyPolygon($get);
        if ($points === null) {
            return null;
        }

        $within = app(CheckPolygonWithinWfo::class)($points, $wfo);

        return $within ? null : "This point falls outside {$wfo}'s forecast area — real warnings occasionally span multiple offices, but double check this is what you intended.";
    }

    /**
     * @return ?array<int, array{lat: float, lon: float}>
     */
    protected static function resolveGeographyPolygon(Get $get): ?array
    {
        if ($get('input.polygon_advanced')) {
            return app(GenerateTestProductPolygon::class)->fromRawText($get('input.polygon_raw') ?? '');
        }

        $lat = $get('input.polygon_center_lat');
        $lon = $get('input.polygon_center_lon');
        $radius = $get('input.polygon_radius_miles');

        if (blank($lat) || blank($lon) || blank($radius)) {
            return null;
        }

        return app(GenerateTestProductPolygon::class)->fromPointAndRadius((float) $lat, (float) $lon, (float) $radius);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioWizardStep2Test --compact"`
Expected: PASS

- [ ] **Step 5: Manually verify the step in the browser**

Log in as a staff user, visit `/admin/test-product-scenarios/create`, pick Tornado Warning, move to Geography, confirm the UGC list narrows once a WFO is picked, confirm the Pinpoint map + radius appear, toggle Advanced and confirm the textarea swaps in, and confirm a point far outside the chosen WFO shows the warning text without blocking navigation to the next step.

- [ ] **Step 6: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php tests/Feature/TestProductScenarioWizardStep2Test.php
git commit -m "Build wizard Step 2: Geography, with WFO-scoped UGC and the polygon sanity check"
```

---

### Task 15: Wizard Step 3 — Content (shape-driven narrative fields, damage tags, motion vector)

**Files:**
- Modify: `app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php`
- Test: `tests/Feature/TestProductScenarioWizardStep3Test.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    \Filament::setCurrentPanel('admin');
});

test('damage tag checkboxes only appear for a product with damage_tag_options configured', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->fillForm(['product_id' => $tornadoWarning->id])
        ->assertFormFieldVisible('input.damage_tags');
});

test('damage tag checkboxes are absent for a product with no damage_tag_options', function () {
    $flashFloodWarning = Product::where('code', 'FF.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->fillForm(['product_id' => $flashFloodWarning->id])
        ->assertFormFieldHidden('input.damage_tags');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioWizardStep3Test --compact"`
Expected: FAIL — `input.damage_tags` field doesn't exist yet.

- [ ] **Step 3: Add the Content step**

Add this import: `use App\Enums\ProductFamily;`

Replace the `steps()` method's placeholder `Step::make('Content'),` with `self::contentStep(),`, and add:

```php
    protected static function contentStep(): Step
    {
        return Step::make('Content')
            ->schema([
                // Warning-shaped
                TextInput::make('input.hazard')
                    ->label('Hazard')
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                TextInput::make('input.hazard_source')
                    ->label('Source')
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                TextInput::make('input.impact')
                    ->label('Impact')
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                TextInput::make('input.locations_impacted')
                    ->label('Locations impacted (optional)')
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                Textarea::make('input.call_to_action')
                    ->label('Call to action')
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                CheckboxList::make('input.damage_tags')
                    ->label('Damage tags')
                    ->options(fn (Get $get): array => self::selectedProductDamageTagOptions($get))
                    ->visible(fn (Get $get): bool => self::selectedProductDamageTagOptions($get) !== []),
                TextInput::make('input.motion_direction_degrees')
                    ->label('Motion direction (degrees, optional)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(359)
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                TextInput::make('input.motion_speed_kt')
                    ->label('Motion speed (knots, optional)')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),

                // Discussion-shaped (SPC MD / WPC MPD)
                Textarea::make('input.discussion_text')
                    ->label('Discussion text')
                    ->required()
                    ->rows(4)
                    ->visible(fn (Get $get): bool => self::selectedProductIsDiscussion($get)),
                Select::make('input.risk_level')
                    ->label('Risk level')
                    ->options(fn (Get $get): array => collect(Product::find($get('product_id'))?->risk_level_options ?? [])->mapWithKeys(fn (string $l) => [$l => $l])->all())
                    ->visible(fn (Get $get): bool => self::selectedProductIsDiscussion($get)
                        && Product::find($get('product_id'))?->risk_level_options !== null),

                // Plain-text-shaped (AFD, SPS)
                Textarea::make('input.body_text')
                    ->label('Body text')
                    ->required()
                    ->rows(6)
                    ->visible(fn (Get $get): bool => self::selectedProductIsPlainText($get)),
            ]);
    }

    /**
     * @return array<string, string>
     */
    protected static function selectedProductDamageTagOptions(Get $get): array
    {
        $product = Product::find($get('product_id'));
        $options = $product?->damage_tag_options ?? [];

        return collect($options)->mapWithKeys(fn (string $tag): array => [$tag => $tag])->all();
    }

    protected static function selectedProductIsDiscussion(Get $get): bool
    {
        return Product::find($get('product_id'))?->family === ProductFamily::SpcWpc;
    }

    protected static function selectedProductIsPlainText(Get $get): bool
    {
        $product = Product::find($get('product_id'));

        return $product !== null && ! $product->has_vtec && $product->family !== ProductFamily::SpcWpc;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioWizardStep3Test --compact"`
Expected: PASS

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php tests/Feature/TestProductScenarioWizardStep3Test.php
git commit -m "Build wizard Step 3: Content, shape-driven with damage tags and motion vector"
```

---

### Task 16: Wizard Step 4 — Review & Generate

**Files:**
- Modify: `app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php`
- Modify: `app/Filament/Admin/Resources/TestProductScenarios/Pages/CreateTestProductScenario.php`
- Modify: `app/Filament/Admin/Resources/TestProductScenarios/Pages/EditTestProductScenario.php`
- Test: `tests/Feature/TestProductScenarioGenerateTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\EditTestProductScenario;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    \Filament::setCurrentPanel('admin');
});

test('clicking Generate on Create saves the scenario and creates one IngestedProduct', function () {
    $product = Product::where('code', 'AFD')->sole();

    livewire(CreateTestProductScenario::class)
        ->fillForm([
            'product_id' => $product->id,
            'input.wfo' => 'PIH',
            'input.issued_at' => now(),
            'input.body_text' => 'Clear skies expected through the week.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $scenario = TestProductScenario::sole();

    expect($scenario->product_id)->toBe($product->id)
        ->and(IngestedProduct::where('test_product_scenario_id', $scenario->id)->count())->toBe(1)
        ->and(IngestedProduct::sole()->is_test)->toBeTrue();
});

test('clicking Generate on Edit updates the stored input and creates a second IngestedProduct', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);
    app(\App\Actions\Testing\GenerateTestProduct::class)($scenario);

    livewire(EditTestProductScenario::class, ['record' => $scenario->id])
        ->fillForm(['input.body_text' => 'Updated text.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($scenario->fresh()->input['body_text'])->toBe('Updated text.')
        ->and($scenario->fresh()->ingestedProducts)->toHaveCount(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioGenerateTest --compact"`
Expected: FAIL — `create`/`save` currently just persist the record with no generation side effect, and the Review step doesn't exist.

- [ ] **Step 3: Add the Review & Generate step**

Add this import: `use App\Actions\Testing\RenderTestProductText;`

Replace the `steps()` method's placeholder `Step::make('Review & Generate'),` with `self::reviewStep(),`, and add:

```php
    protected static function reviewStep(): Step
    {
        return Step::make('Review & Generate')
            ->schema([
                Placeholder::make('rendered_preview')
                    ->label('Preview')
                    ->content(function (Get $get): string {
                        $product = Product::find($get('product_id'));
                        if ($product === null) {
                            return 'Select a product to see a preview.';
                        }

                        try {
                            return app(RenderTestProductText::class)($product, self::currentInput($get));
                        } catch (\Throwable) {
                            return 'Fill in the required fields above to see a preview.';
                        }
                    })
                    ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap']),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function currentInput(Get $get): array
    {
        return $get('input') ?? [];
    }
```

- [ ] **Step 4: Wire Generate into the Create page**

Replace `app/Filament/Admin/Resources/TestProductScenarios/Pages/CreateTestProductScenario.php` with:

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Actions\Testing\GenerateTestProduct;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\TestProductScenario;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class CreateTestProductScenario extends CreateRecord
{
    use HasWizard;

    protected static string $resource = TestProductScenarioResource::class;

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return TestProductScenarioForm::steps();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        /** @var TestProductScenario $scenario */
        $scenario = $this->record;

        app(GenerateTestProduct::class)($scenario);

        Notification::make()
            ->title('Test product generated')
            ->body('It was ingested through the real pipeline and matched against the fixture test account.')
            ->success()
            ->send();
    }
}
```

- [ ] **Step 5: Wire Generate into the Edit page**

Replace `app/Filament/Admin/Resources/TestProductScenarios/Pages/EditTestProductScenario.php` with:

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Actions\Testing\GenerateTestProduct;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\TestProductScenario;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class EditTestProductScenario extends EditRecord
{
    use HasWizard;

    protected static string $resource = TestProductScenarioResource::class;

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return TestProductScenarioForm::steps();
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * A test dispatch is meant to look freshly issued every time it's sent
     * -- see the design doc's "Repeat & Reuse" section. Every other stored
     * input field hydrates as-is; only issued_at is reset here, each time
     * this scenario is opened, rather than replaying its stale original
     * value.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['input']['issued_at'] = now();

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var TestProductScenario $scenario */
        $scenario = $this->record;

        app(GenerateTestProduct::class)($scenario);

        Notification::make()
            ->title('Test product generated')
            ->body('It was ingested through the real pipeline and matched against the fixture test account.')
            ->success()
            ->send();
    }
}
```

(Filament's `EditRecord` default save button label reads "Save changes" — leave the actual button label as Filament's own default for now; relabeling it to "Generate" is a small polish item, not required for correct behavior, and can be done via `EditRecord::getSaveFormAction()` if desired once the core flow is verified working end-to-end.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenarioGenerateTest --compact"`
Expected: PASS

- [ ] **Step 7: Manually verify the full wizard in the browser**

Log in as staff, walk through all 4 steps for a Tornado Warning (Step 1: product + WFO + timing, Step 2: UGC + polygon, Step 3: hazard/source/impact/damage tags, Step 4: confirm the preview shows realistic raw text), click Generate, confirm the success notification and a new row in Ingested Products filtered to `is_test`.

- [ ] **Step 8: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Admin/Resources/TestProductScenarios/Schemas/TestProductScenarioForm.php app/Filament/Admin/Resources/TestProductScenarios/Pages/CreateTestProductScenario.php app/Filament/Admin/Resources/TestProductScenarios/Pages/EditTestProductScenario.php tests/Feature/TestProductScenarioGenerateTest.php
git commit -m "Build wizard Step 4: Review & Generate, wired into Create and Edit"
```

---

### Task 17: `TestProductScenariosTable` — Send again, Duplicate, Delete

**Files:**
- Modify: `app/Filament/Admin/Resources/TestProductScenarios/Tables/TestProductScenariosTable.php`
- Test: `tests/Feature/TestProductScenariosTableTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\ListTestProductScenarios;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    \Filament::setCurrentPanel('admin');
});

test('send again re-runs Generate against the scenario unchanged', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('send_again')->table($scenario))
        ->assertNotified();

    expect($scenario->fresh()->ingestedProducts)->toHaveCount(1)
        ->and($scenario->fresh()->input['body_text'])->toBe('Original text.');
});

test('duplicate clones a scenario into a new, unsent copy', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'name' => 'Original',
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Some text.'],
    ]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('duplicate')->table($scenario))
        ->assertNotified();

    expect(TestProductScenario::count())->toBe(2);
    $copy = TestProductScenario::where('id', '!=', $scenario->id)->sole();
    expect($copy->input)->toBe($scenario->input)
        ->and($copy->ingestedProducts)->toHaveCount(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenariosTableTest --compact"`
Expected: FAIL — the `send_again`/`duplicate` actions don't exist yet.

- [ ] **Step 3: Build the table**

Replace `app/Filament/Admin/Resources/TestProductScenarios/Tables/TestProductScenariosTable.php` with:

```php
<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Tables;

use App\Actions\Testing\GenerateTestProduct;
use App\Models\TestProductScenario;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TestProductScenariosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->placeholder('Untitled scenario'),
                TextColumn::make('product.name')
                    ->label('Product'),
                TextColumn::make('ingested_products_count')
                    ->label('Times sent')
                    ->counts('ingestedProducts'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('send_again')
                    ->label('Send again')
                    ->icon('heroicon-o-paper-airplane')
                    ->action(function (TestProductScenario $record): void {
                        app(GenerateTestProduct::class)($record);

                        Notification::make()
                            ->title('Test product generated')
                            ->success()
                            ->send();
                    }),
                EditAction::make()
                    ->label('Edit & resend'),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (TestProductScenario $record): void {
                        TestProductScenario::create([
                            'name' => $record->name !== null ? "{$record->name} (copy)" : null,
                            'product_id' => $record->product_id,
                            'input' => $record->input,
                        ]);

                        Notification::make()
                            ->title('Scenario duplicated')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make(),
            ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductScenariosTableTest --compact"`
Expected: PASS

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Admin/Resources/TestProductScenarios/Tables/TestProductScenariosTable.php tests/Feature/TestProductScenariosTableTest.php
git commit -m "Add Send again, Edit & resend, and Duplicate actions to the scenarios list"
```

---

### Task 18: Ingested Products review screen — `is_test` filter and scenario link

**Files:**
- Modify: `app/Filament/Admin/Resources/IngestedProducts/Tables/IngestedProductsTable.php`
- Create: `tests/Feature/IngestedProductsTableTest.php` (doesn't exist yet)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Filament\Admin\Resources\IngestedProducts\Pages\ListIngestedProducts;
use App\Models\IngestedProduct;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    \Filament::setCurrentPanel('admin');
});

test('the is_test filter isolates synthetic test runs from real archived products', function () {
    $real = IngestedProduct::factory()->create(['is_test' => false]);
    $test = IngestedProduct::factory()->create(['is_test' => true]);

    livewire(ListIngestedProducts::class)
        ->filterTable('is_test', true)
        ->assertCanSeeTableRecords([$test])
        ->assertCanNotSeeTableRecords([$real]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=IngestedProductsTableTest --compact"`
Expected: FAIL — no `is_test` filter exists on the table yet.

- [ ] **Step 3: Add the filter**

In `app/Filament/Admin/Resources/IngestedProducts/Tables/IngestedProductsTable.php`, add the import `use Filament\Tables\Filters\TernaryFilter;` and add to the `->filters([...])` array:

```php
            ->filters([
                TernaryFilter::make('is_test')
                    ->label('Test products'),
                SelectFilter::make('cccc')
                    ->label('Office')
                    ->options(fn (): array => IngestedProduct::query()
                        ->whereNotNull('cccc')
                        ->distinct()
                        ->orderBy('cccc')
                        ->pluck('cccc', 'cccc')
                        ->all()),
                Filter::make('received_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('received_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('received_at', '<=', $date))),
            ])
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=IngestedProductsTableTest --compact"`
Expected: PASS

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Admin/Resources/IngestedProducts/Tables/IngestedProductsTable.php tests/Feature/IngestedProductsTableTest.php
git commit -m "Add an is_test filter to the Ingested Products review screen"
```

---

### Task 19: Admin `UserResource` — `is_test_account` toggle

**Files:**
- Modify: `app/Filament/Admin/Resources/Users/Schemas/UserForm.php`
- Test: `tests/Feature/UserResourceTest.php` (open it first to match existing conventions)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/UserResourceTest.php`:

```php
test('staff can flag a user as the fixture test account', function () {
    $target = User::factory()->create(['is_test_account' => false]);

    livewire(EditUser::class, ['record' => $target->id])
        ->fillForm(['is_test_account' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->is_test_account)->toBeTrue();
});
```

`EditUser` and `User` are already imported in this file, and its existing `beforeEach` already authenticates a staff user (`$this->staff`) and sets the admin panel — no setup changes needed beyond adding this test.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter='staff can flag a user as the fixture test account' --compact"`
Expected: FAIL — `is_test_account` isn't on the form yet.

- [ ] **Step 3: Add the toggle**

In `app/Filament/Admin/Resources/Users/Schemas/UserForm.php`, add right after `Toggle::make('is_staff'),`:

```php
                Toggle::make('is_staff'),
                Toggle::make('is_test_account')
                    ->label('Fixture test account')
                    ->helperText('The Test Product Generator only ever matches synthetic products against alert rules belonging to whichever user has this flag set.'),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter='staff can flag a user as the fixture test account' --compact"`
Expected: PASS

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Admin/Resources/Users/Schemas/UserForm.php tests/Feature/UserResourceTest.php
git commit -m "Add an is_test_account toggle to the admin User form"
```

---

### Task 20: End-to-end integration test

Proves the whole chain works together: generate a warning-family scenario → real `IngestedProduct` created → observer fires → matches the fixture test account's `AlertRule` → `DeliverWeatherAlert` dispatched — and that a real subscriber's identical rule is never touched.

**Files:**
- Test: `tests/Feature/TestProductGeneratorEndToEndTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

use App\Actions\Testing\GenerateTestProduct;
use App\Enums\Tier;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('a generated warning-family product matches the fixture test account and dispatches a real notification, never a real subscriber', function () {
    Queue::fake();

    $testAccount = User::factory()->create(['is_test_account' => true, 'tier_override' => Tier::Plus]);
    $testChannel = NotificationChannel::factory()->for($testAccount)->create();
    $product = Product::where('code', 'SV.W')->sole();
    $testRule = AlertRule::factory()->for($testAccount)->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'wfo',
        'wfo' => 'PAH',
    ]);
    $testRule->products()->attach($product);
    $testRule->notificationChannels()->attach($testChannel);

    $realSubscriber = User::factory()->create(['is_test_account' => false, 'tier_override' => Tier::Plus]);
    $realChannel = NotificationChannel::factory()->for($realSubscriber)->create();
    $realRule = AlertRule::factory()->for($realSubscriber)->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'wfo',
        'wfo' => 'PAH',
    ]);
    $realRule->products()->attach($product);
    $realRule->notificationChannels()->attach($realChannel);

    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => [
            'mode' => 'new',
            'vtec_action' => 'NEW',
            'wfo' => 'PAH',
            'expires_minutes' => 30,
            'ugc_codes' => ['MOC023'],
            'hazard' => '60 mph wind gusts.',
            'hazard_source' => 'Radar indicated.',
            'impact' => 'Tree damage possible.',
            'call_to_action' => 'Move to an interior room.',
            'polygon_mode' => 'point_radius',
            'polygon_center_lat' => 36.83,
            'polygon_center_lon' => -89.12,
            'polygon_radius_miles' => 8,
            'damage_tags' => [],
        ],
    ]);

    $result = app(GenerateTestProduct::class)($scenario);

    expect($result->is_test)->toBeTrue();

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
});
```

- [ ] **Step 2: Run the test**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --filter=TestProductGeneratorEndToEndTest --compact"`
Expected: PASS. If it fails, use `superpowers:systematic-debugging` — this test exercises the full real chain (`GenerateTestProduct` → `IngestProductText` → `ExtractProductMetadata`/`IngestedProductObserver` → `SyncActiveWwa` → `MatchWeatherAlertRules` → `DeliverWeatherAlert::dispatch`), so a failure here means one of the individual pieces built in Tasks 1-11 doesn't compose correctly with the others even though each passed in isolation — trace which specific hop breaks (e.g. does `IngestedProduct::sole()->product_id` actually resolve? does `ActiveWwa`'s row get `is_test = true`? does the `AlertRule` query actually scope correctly for a `weather_alerts`-category rule specifically, not just the `forecast_discussion` case Task 7's own tests covered?) rather than guessing at the fix.

- [ ] **Step 3: Run the full test suite**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan test --compact"`
Expected: PASS, every test in the suite (confirms zero regressions across the whole feature).

- [ ] **Step 4: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add tests/Feature/TestProductGeneratorEndToEndTest.php
git commit -m "Add end-to-end test proving the Test Product Generator's full real-pipeline dispatch"
```

---

### Final Manual Verification (in the browser)

After all 20 tasks are complete and the full suite passes:

1. As a staff user in a **non-production** environment, confirm "Test Product Generator" appears in the `/admin` Operations nav group; confirm it's absent when `APP_ENV=production` is set locally.
2. Flag a user as the fixture test account via Users; impersonate into `/dashboard` as them and configure a real Tornado Warning alert rule and a verified notification channel.
3. Generate a Tornado Warning scenario for that account's WFO — confirm the real formatted notification actually lands on the configured channel.
4. Generate a Severe Thunderstorm Warning, then use "Continue existing event" to issue a CON, then an EXP — confirm each produces a distinct `IngestedProduct` and the `active_wwas` row's status updates correctly.
5. Generate an SPC Mesoscale Discussion — confirm its polygon round-trips correctly through Ingested Products' view page (proving Task 1's parser fix works end-to-end, not just in the unit test).
6. Generate an Area Forecast Discussion — confirm no VTEC/UGC line appears in the raw text and no polygon is asked for.
7. Use "Send again" and "Edit & resend" from the scenarios list; confirm both create new rows without disturbing the original scenario's history.
8. Filter Ingested Products to `is_test = true`; confirm every generated row appears there and links back to its scenario.
