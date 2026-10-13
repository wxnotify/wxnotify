# Per-product matching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each product on a Weather Alerts / SPC & WPC rule its own matching mode, locations, WFO and county/zone codes, edited in a table per category on the wizard's "Set it up" step.

**Architecture:** The settings live on the `alert_rule_product` pivot (plus a new `alert_rule_product_location` table). `AlertRule::settingsFor($productId)` is the one reader (the matcher, the API, the form and the table all go through it). A pivot row with no `matching_mode` *inherits* the rule's own columns, so rules and tests created the old way keep working unchanged. `SaveAlertRule` stays the one writer; it accepts a new `product_settings` list or today's flat fields (expanded to the same list). The wizard replaces the toggle list with a table-mode `Repeater` per category.

**Tech Stack:** Laravel 12 / PHP 8.4, Filament v5.7 (Repeater table mode, `visibleJs`), Pest, Postgres/PostGIS, NativePHP v4 mobile app (`mobile/`).

**Spec:** `docs/superpowers/specs/2026-10-12-per-product-matching-design.md`

## Global Constraints

- Matching mode values and labels are `App\Enums\MatchingMode` as they are: `nationwide`, `location`, `wfo`, `county_zone`, `polygon`, `risk_area`. Never change what a mode matches.
- Weather Alerts allows every mode but `risk_area`; SPC & WPC allows `nationwide`, `polygon`, `risk_area` (validation). The table's mode *options* per SPC & WPC product: a product with `risk_level_options` offers `nationwide` + `risk_area`, any other offers `nationwide` + `polygon`; a mode already stored for a product but not offered is still shown (as stored) and still accepted on save.
- Defaults for a new row: `has_polygon` product -> `polygon`, other Weather Alerts product -> `location`, SPC & WPC product with risk levels -> `risk_area`, other SPC & WPC product -> `polygon`; the subscriber's first saved location (lowest id) is preselected when they have one (for the four location-based modes).
- Rules text: read `.ai/rules/index.md` and every matching file before editing (`app/Actions/**`, `app/Models/**`, `app/Filament/**`, `app/Http/**`, `tests/Feature/**`, `app/Support/**`).
- Pint on touched PHP files: `vendor/bin/pint --dirty --format agent`. Tests run in the app container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact <paths>'`; mobile tests: `cd mobile && vendor/bin/pest --compact <paths>`.
- Dynamic Wizard step bug (`.ai/rules/filament.md`): never rely on non-native selects, `->relationship()` or field defaults in the dynamic "Set it up" step without the browser check in Task 1; populate the table's state in the Category radio's `afterStateUpdated` (create) and `mutateFormDataBeforeFill` (edit), not in field defaults.
- Commit each task on `main`, trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never push.

## Review Focus

The inputs the spec implies but the task tests would not otherwise exercise, most likely first:

1. **A rule that was saved before this change** (pivot rows with no `matching_mode`, rule-level mode and `alert_rule_location` rows) must match exactly as it did: inherit fallback, covered in Task 2 and Task 3.
2. **Editing an old rule** whose stored mode is not offered for a product (e.g. `risk_area` on a plain SPC product, `location` on a polygon-less product): the table shows it as stored and a save does not reject or change it (Task 7).
3. **A subscriber with no saved locations:** rows default with no location, the location-based modes show the existing "No saved locations yet" message, and saving an enabled such row is refused with a message, not a crash (Task 7).
4. **Deleting a location** that is used only by a per-product location row is refused by the deletion guard and named in its message (Task 10).
5. **A mixed rule saved from the mobile editor** (name, channels or quiet hours only) keeps its products, per-product settings and `risk_levels`, while a flat PUT that does carry product fields replaces everything (Task 5, Task 6, Task 11).

---

## Task 1: Browser spike: can a table-mode Repeater carry the row in the dynamic step?

Throwaway: nothing from this task is committed except a rules note. It decides how Task 7 builds the table.

**Files:**
- Create (throwaway, delete before finishing): `app/Filament/Dashboard/Pages/SpikeProductTable.php`, `resources/views/filament/dashboard/pages/spike-product-table.blade.php`
- Modify (commit): `.ai/rules/filament.md` (append the result)

**Interfaces:**
- Produces: a recorded decision, in `.ai/rules/filament.md` under "## A product-per-row table in a dynamic Wizard step", naming the Filament v5.7 component and method names Task 7 uses (Repeater `->table()` column class, the row cell layout, how per-row conditional visibility is expressed) and one of two outcomes: **A** (table-mode Repeater with Filament fields works) or **B** (it does not; Task 7 then builds the rows as a custom `Field` with a Blade view of native `<select>`/checkbox inputs and `wire:model`, with a modal action for county/zone codes). Task 7's tests are approach-agnostic: they drive the Livewire form state shape fixed in Task 7.

- [ ] **Step 1: Build a one-page spike**

A Filament page (not in the sidebar: `protected static bool $shouldRegisterNavigation = false`) with a `HasForms` form containing a `Radio::make('category')->live()` and, below it, a dynamic `Wizard` step like `AlertRuleForm::detailsStep()` whose schema is `fn (Get $get)` returning one `Repeater::make("product_settings.{$category}")->table([...])->addable(false)->deletable(false)->reorderable(false)` with, per row, these columns: `Toggle::make('enabled')`, a `Hidden::make('product_id')`, a title+description view cell, `Select::make('matching_mode')` (native, options from `MatchingMode`), a `Select::make('location_ids')->multiple()->options(auth()->user()->locations()->pluck('label','id'))` shown only for the location-based modes through `->visibleJs(...)`, `Select::make('wfo')` shown only for `wfo`, and the county/zone `Select::make('county_zone_codes')->multiple()->searchable()` shown only for `county_zone`. Populate the repeater state in the Category radio's `afterStateUpdated` with one row per Weather Alerts product (about 100 rows).

- [ ] **Step 2: Check it in the browser (local stack at http://localhost:8080)**

Log in with the dev quick-login ("User (user@host.com)"). Open the spike page and verify each of these, recording the result of each:
1. No console error "Livewire Entangle Error" after choosing a category, toggling rows, choosing modes and picking locations/WFO/zones (`read_console_messages`).
2. A value chosen in a row's select is still the submitted state after the next Livewire round trip (log `$this->data` on a "Dump state" button).
3. Switching a row's mode shows/hides its inputs without a server round trip (`read_network_requests`: none for the switch).
4. Page weight: time from choosing the category to the table being painted, and the DOM node count (`document.querySelectorAll('*').length`) with ~100 rows; note both numbers.
5. Repeater state after save keeps each row's `product_id` (rows keyed by uuid is fine).

- [ ] **Step 3: Decide and record**

If 1 to 3 and 5 pass and 4 is under about 2 seconds and 15,000 nodes: outcome **A**. Otherwise try the smallest change that fixes the failure (for example `->live(onBlur: true)` removal, a native select instead of the searchable one, or rendering only the open category's rows) and re-check; if no change passes, outcome **B**. Append the outcome, the exact component/method names that worked, and the measured numbers to `.ai/rules/filament.md`.

- [ ] **Step 4: Delete the throwaway files and commit the note**

```bash
git rm -f --ignore-unmatch app/Filament/Dashboard/Pages/SpikeProductTable.php resources/views/filament/dashboard/pages/spike-product-table.blade.php
rm -f app/Filament/Dashboard/Pages/SpikeProductTable.php resources/views/filament/dashboard/pages/spike-product-table.blade.php
git add .ai/rules/filament.md
git commit -m "Rules: product-per-row table in a dynamic wizard step (spike result)"
```

---

## Task 2: Per-product settings storage and `AlertRule::settingsFor()`

**Files:**
- Create: `database/migrations/2026_10_13_100001_add_matching_settings_to_alert_rule_product_table.php`
- Create: `app/Support/ProductMatchSettings.php`
- Modify: `app/Models/AlertRule.php` (relations, `settingsFor()`)
- Test: `tests/Feature/AlertRuleProductSettingsTest.php`

**Interfaces:**
- Produces: `AlertRule::products()` loads pivot columns `matching_mode`, `wfo`, `county_zone_codes`; `AlertRule::productLocations()` (BelongsToMany `Location` through `alert_rule_product_location`, `withPivot('product_id')`, ordered by `locations.id`); `AlertRule::settingsFor(int $productId): ?ProductMatchSettings`; `final class ProductMatchSettings { public function __construct(public readonly ?MatchingMode $mode, public readonly ?string $wfo, public readonly array $countyZoneCodes /* list<string> */, public readonly Collection $locations /* Collection<int, Location>, ordered by id */, public readonly bool $inherited) }`. `settingsFor()` returns null when the rule does not watch the product. When the product's pivot `matching_mode` is null the settings **inherit** the rule's own `matching_mode`, `wfo`, `county_zone_codes` (comma string split to a list) and `locations()` and `inherited` is true.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\MatchingMode;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

test('a product with its own settings uses them, with its own locations', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $tornado = Product::factory()->create();
    $home = Location::factory()->create(['user_id' => $rule->user_id]);
    $cabin = Location::factory()->create(['user_id' => $rule->user_id]);
    $rule->products()->attach($tornado->id, ['matching_mode' => 'polygon', 'wfo' => null, 'county_zone_codes' => null]);
    DB::table('alert_rule_product_location')->insert([
        ['alert_rule_id' => $rule->id, 'product_id' => $tornado->id, 'location_id' => $cabin->id],
        ['alert_rule_id' => $rule->id, 'product_id' => $tornado->id, 'location_id' => $home->id],
    ]);

    $settings = $rule->fresh()->settingsFor($tornado->id);

    expect($settings->mode)->toBe(MatchingMode::Polygon)
        ->and($settings->inherited)->toBeFalse()
        ->and($settings->locations->pluck('id')->all())->toBe([$home->id, $cabin->id]);
});

test('a product with no settings of its own inherits the rule\'s mode, WFO, zones and locations', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'county_zone', 'county_zone_codes' => 'NYZ072,NYC061', 'location_id' => null]);
    $product = Product::factory()->create();
    $rule->products()->attach($product->id);
    $location = Location::factory()->create(['user_id' => $rule->user_id]);
    $rule->locations()->attach($location->id);

    $settings = $rule->fresh()->settingsFor($product->id);

    expect($settings->mode)->toBe(MatchingMode::CountyZone)
        ->and($settings->inherited)->toBeTrue()
        ->and($settings->countyZoneCodes)->toBe(['NYZ072', 'NYC061'])
        ->and($settings->locations->pluck('id')->all())->toBe([$location->id]);
});

test('settingsFor is null for a product the rule does not watch', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);

    expect($rule->settingsFor(Product::factory()->create()->id))->toBeNull();
});

test('two products of one rule keep separate locations', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    [$a, $b] = Product::factory()->count(2)->create();
    [$home, $cabin] = Location::factory()->count(2)->create(['user_id' => $rule->user_id]);
    $rule->products()->attach([$a->id => ['matching_mode' => 'location'], $b->id => ['matching_mode' => 'location']]);
    DB::table('alert_rule_product_location')->insert([
        ['alert_rule_id' => $rule->id, 'product_id' => $a->id, 'location_id' => $home->id],
        ['alert_rule_id' => $rule->id, 'product_id' => $b->id, 'location_id' => $cabin->id],
    ]);

    $rule = $rule->fresh();

    expect($rule->settingsFor($a->id)->locations->pluck('id')->all())->toBe([$home->id])
        ->and($rule->settingsFor($b->id)->locations->pluck('id')->all())->toBe([$cabin->id]);
});

test('the migration backfills every existing pivot row from its rule', function () {
    // Replays the migration's SQL on rows shaped like pre-migration data.
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'TBW', 'location_id' => null]);
    $product = Product::factory()->create();
    $location = Location::factory()->create(['user_id' => $rule->user_id]);
    $rule->products()->attach($product->id);
    $rule->locations()->attach($location->id);

    $migration = require base_path('database/migrations/2026_10_13_100001_add_matching_settings_to_alert_rule_product_table.php');
    $migration->backfill();

    $row = DB::table('alert_rule_product')->where('alert_rule_id', $rule->id)->first();
    expect($row->matching_mode)->toBe('wfo')
        ->and($row->wfo)->toBe('TBW')
        ->and(DB::table('alert_rule_product_location')->where('alert_rule_id', $rule->id)->pluck('location_id')->all())->toBe([$location->id]);
});
```

- [ ] **Step 2: Run to verify it fails** (`settingsFor` undefined, missing columns).

- [ ] **Step 3: Migration**

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
        Schema::table('alert_rule_product', function (Blueprint $table) {
            $table->string('matching_mode')->nullable();
            $table->string('wfo')->nullable();
            $table->text('county_zone_codes')->nullable();
        });

        Schema::create('alert_rule_product_location', function (Blueprint $table) {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'product_id', 'location_id']);
        });

        $this->backfill();
    }

    /**
     * Every product of a product-based rule takes the rule's own mode, WFO, zones and locations, so what a
     * subscriber receives does not change at deploy.
     */
    public function backfill(): void
    {
        DB::statement("
            UPDATE alert_rule_product AS arp
            SET matching_mode = ar.matching_mode, wfo = ar.wfo, county_zone_codes = ar.county_zone_codes
            FROM alert_rules AS ar
            WHERE ar.id = arp.alert_rule_id
              AND ar.category IN ('weather_alerts', 'spc_wpc')
              AND ar.matching_mode IS NOT NULL
        ");

        DB::statement('
            INSERT INTO alert_rule_product_location (alert_rule_id, product_id, location_id)
            SELECT arp.alert_rule_id, arp.product_id, arl.location_id
            FROM alert_rule_product AS arp
            JOIN alert_rule_location AS arl ON arl.alert_rule_id = arp.alert_rule_id
            WHERE arp.matching_mode IS NOT NULL
            ON CONFLICT DO NOTHING
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rule_product_location');

        Schema::table('alert_rule_product', function (Blueprint $table) {
            $table->dropColumn(['matching_mode', 'wfo', 'county_zone_codes']);
        });
    }
};
```

- [ ] **Step 4: `ProductMatchSettings` and the model**

`app/Support/ProductMatchSettings.php`: a `final class` with the constructor in Interfaces above and a PHPDoc `@param list<string> $countyZoneCodes`, `@param Collection<int, Location> $locations`.

In `AlertRule`:

```php
public function products(): BelongsToMany
{
    return $this->belongsToMany(Product::class)->withPivot(['matching_mode', 'wfo', 'county_zone_codes']);
}

/**
 * Each product's own saved locations (the pivot's product_id says which product).
 *
 * @return BelongsToMany<Location, $this>
 */
public function productLocations(): BelongsToMany
{
    return $this->belongsToMany(Location::class, 'alert_rule_product_location')->withPivot('product_id')->orderBy('locations.id');
}

/**
 * How this rule matches one of its products: the product's own settings, else (a pivot row with no
 * mode, i.e. a rule not yet saved through the per-product table) the rule's own columns and locations.
 */
public function settingsFor(int $productId): ?ProductMatchSettings
{
    $product = $this->products->firstWhere('id', $productId);

    if ($product === null) {
        return null;
    }

    if ($product->pivot->matching_mode === null) {
        return new ProductMatchSettings(
            $this->matching_mode,
            $this->wfo,
            filled($this->county_zone_codes) ? explode(',', $this->county_zone_codes) : [],
            $this->locations,
            true,
        );
    }

    return new ProductMatchSettings(
        MatchingMode::from($product->pivot->matching_mode),
        $product->pivot->wfo,
        filled($product->pivot->county_zone_codes) ? explode(',', $product->pivot->county_zone_codes) : [],
        $this->productLocations->filter(fn (Location $location): bool => (int) $location->pivot->product_id === $productId)->values(),
        false,
    );
}
```

- [ ] **Step 5: Run the new tests and the AlertRule-related suites** (`AlertRuleLocationPivotTest`, `AlertRuleResourceTest`, `AlertRuleParityTest`, `ApiAlertRulesTest`) to confirm `withPivot` broke nothing. Pint. Commit `Alert rules: per-product matching settings storage and AlertRule::settingsFor()`.

---

## Task 3: The matcher reads each product's own settings

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php`
- Test: `tests/Feature/MatchWeatherAlertRulesTest.php` (add cases; existing ones must stay green unchanged)

**Interfaces:**
- Consumes: `AlertRule::settingsFor(int $productId): ?ProductMatchSettings` (Task 2).
- Produces: nothing new; the observable contract is below.

- [ ] **Step 1: Write failing tests (append to `MatchWeatherAlertRulesTest.php`, following that file's existing helpers for ingesting and asserting a dispatched `DeliverWeatherAlert`)**
  1. One rule with a Tornado Warning pivot row in `polygon` mode with location A (inside the product's polygon) and a Flood Watch row in `location` mode with location B: a tornado bulletin over A dispatches one delivery; a flood bulletin over A (outside B) dispatches nothing.
  2. A rule with two pivot rows in different modes (`wfo` for product 1 with `wfo = 'TBW'` and `nationwide` for product 2): a product 1 bulletin from another office does not match; a product 2 bulletin from any office matches.
  3. A bulletin whose two segments belong to two products with different settings produces ONE delivery carrying both segment ids when both match, and only the matching segment's id when one matches.
  4. A pivot row with `matching_mode = null` (inherited) behaves exactly as the rule's own mode and locations (reuse one existing test's setup and assert the same dispatch).
  5. The matched location passed to `DeliverWeatherAlert` is the lowest-id location among the matching products' own locations.

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement**

In `__invoke`, load what `settingsFor()` needs for every rule:

```php
->with(['products', 'locations', 'productLocations'])
```
(replace the `->when($segments->isNotEmpty(), fn (Builder $query) => $query->with('products'))` line).

Change `matchesGeography` to read per-product settings:

```php
private function matchesGeography(AlertRule $rule, IngestedProduct $product): GeographyMatch
{
    $settings = $product->product_id === null ? null : $rule->settingsFor((int) $product->product_id);

    if ($settings === null) {
        return GeographyMatch::notMatched();
    }

    return match ($settings->mode) {
        MatchingMode::Nationwide => GeographyMatch::matched(),
        MatchingMode::Wfo => $this->matchesWfo($settings->wfo, $product->cccc) ? GeographyMatch::matched() : GeographyMatch::notMatched(),
        MatchingMode::CountyZone => $this->matchesCountyZone($settings->countyZoneCodes, $product) ? GeographyMatch::matched() : GeographyMatch::notMatched(),
        MatchingMode::Location => $this->matchesAnyLocation($settings->locations, fn (Location $location): bool => $this->matchesLocationZones($location, $product) || $this->matchesLocationOffice($rule, $location, $product)),
        MatchingMode::Polygon => $this->matchesAnyLocation($settings->locations, fn (Location $location): bool => $this->matchesPolygon($location, $product) ?? ($this->matchesLocationZones($location, $product) || $this->matchesLocationOffice($rule, $location, $product))),
        MatchingMode::RiskArea => $this->matchesAnyLocation($settings->locations, fn (Location $location): bool => $this->matchesRiskArea($rule, $location, $product) ?? $this->matchesLocationZones($location, $product)),
        null => GeographyMatch::notMatched(),
    };
}
```

Change `matchesAnyLocation(AlertRule $rule, Closure $matchesLocation)` to `matchesAnyLocation(Collection $locations, Closure $matchesLocation)` iterating `$locations`; change `matchesCountyZone(AlertRule $rule, IngestedProduct $product)` to `matchesCountyZone(array $codes, IngestedProduct $product)` (`if ($codes === [] || $product->ugc_codes === null) { return false; }` then `array_intersect($codes, $product->ugc_codes) !== []`). Keep the docblocks accurate (they mention the rule's locations). `matchesRiskArea`, `matchesRiskLevel` and `matchesLocationOffice` keep taking the rule (they read `risk_levels` and `category`). Check `isWithinQuietHours` and the rest of the file for other reads of `$rule->locations`, `$rule->wfo`, `$rule->county_zone_codes`, `$rule->matching_mode` and move any to settings or leave them if they only ever use the matched location.

- [ ] **Step 4: Run `MatchWeatherAlertRulesTest`, `DeliverWeatherAlertTest`, `ReplayIngestedProductTest`, `BackfillOutlookRiskAreasTest`, `TestProductGeneratorEndToEndTest`.** All pass. Pint. Commit `Matcher: each product of a rule matches by its own mode and locations`.

---

## Task 4: `ProductMatching` (allowed modes and defaults)

**Files:**
- Create: `app/Support/ProductMatching.php`
- Test: `tests/Feature/ProductMatchingTest.php`

**Interfaces:**
- Produces:
  - `ProductMatching::offeredModes(AlertCategory $category, Product $product): list<MatchingMode>` (the table's options): Weather Alerts -> every mode but `RiskArea`; SPC & WPC -> `[Nationwide, RiskArea]` if `$product->risk_level_options` is non-empty else `[Nationwide, Polygon]`.
  - `ProductMatching::acceptedModes(AlertCategory $category): list<MatchingMode>` (validation): Weather Alerts -> every mode but `RiskArea`; SPC & WPC -> `[Nationwide, Polygon, RiskArea]`.
  - `ProductMatching::defaultMode(AlertCategory $category, Product $product): MatchingMode` per the Global Constraints.
  - `ProductMatching::defaultRow(User $user, AlertCategory $category, Product $product): array{product_id: int, enabled: bool, matching_mode: string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}` with `enabled` false, `location_ids` = `[first location id]` for the four... three location-based modes (`location`, `polygon`, `risk_area`) when the user has a location, else `[]`; `wfo` null; zones `[]`.

- [ ] **Step 1: Failing tests** (Pest datasets): for each of Tornado-like `has_polygon` product / plain warning / SPC product with `risk_level_options` / SPC plain product, assert `defaultMode`, `offeredModes`, `acceptedModes`; assert `defaultRow` preselects the lowest-id location, returns `[]` for a user with none, and never preselects a location for `nationwide`.
- [ ] **Step 2: Run, verify failing.**
- [ ] **Step 3: Implement** as a `final class` of static methods (`first location` = `$user->locations()->orderBy('id')->value('id')`; for efficiency `defaultRow` takes an optional `?int $firstLocationId` argument defaulting to the lookup, and Task 7 passes it once per form build).
- [ ] **Step 4: Run, Pint, commit** `Alert rules: ProductMatching modes and defaults`.

---

## Task 5: `SaveAlertRule` writes per-product settings

**Files:**
- Modify: `app/Actions/Alerts/SaveAlertRule.php`
- Test: `tests/Feature/SaveAlertRuleTest.php` (add cases; existing ones stay green)

**Interfaces:**
- Consumes: `AlertRule::products()` pivot columns, `alert_rule_product_location` (Task 2).
- Produces: `SaveAlertRule` input key `product_settings`: `list<array{product_id: int, matching_mode: string, location_ids?: list<int>, wfo?: ?string, county_zone_codes?: list<string>|string|null}>` (only the products the rule watches). The flat input (`product_ids` with `matching_mode`, `location_ids`, `wfo`, `county_zone_codes`) is expanded to the same list, one entry per product. **Keep path:** for a stored product-based rule saved with neither `product_settings` nor `product_ids` in the input, products, per-product settings and every product-based column (`matching_mode`, `wfo`, `county_zone_codes`, `risk_levels`) are left exactly as stored. Public helper `SaveAlertRule::productSettings(array $data): ?array` returns the normalised list, or null for the keep path.

Details to implement (read the existing file first; keep its structure):
- `attributes()`: for the product-based categories the rule's own `matching_mode`, `wfo`, `county_zone_codes` are set to null (they are no longer written; `CATEGORY_COLUMNS['weather_alerts'] = []`, `['spc_wpc' => ['risk_levels']]`) except on the keep path, where `ALL_CATEGORY_COLUMNS` are not touched at all and `risk_levels` is not recomputed. `risk_levels` stays flat for SPC & WPC as today.
- `syncRelations()`: for a product-based rule off the keep path, replace in one `DB::transaction`: `$rule->products()->sync([$id => ['matching_mode' => ..., 'wfo' => ..., 'county_zone_codes' => ...]])` and the rows of `alert_rule_product_location` for this rule (delete then insert) and `$rule->locations()->sync([])` (the rule's own list is no longer used). Per entry: `wfo` kept only when the mode is `wfo` (else null); `county_zone_codes` kept only for `county_zone`, stored as the comma string (array is imploded; a string is kept); `location_ids` kept only for `location`, `polygon`, `risk_area`, de-duplicated. For other categories, products and the new pivot rows are cleared as before.
- The old `MODES_WITH_LOCATIONS` constant is replaced by a per-entry check.

- [ ] **Step 1: Failing tests** covering: `product_settings` writes pivot mode/WFO/zones and per-product locations; the flat input expands to the same rows (mode applied to every product); unused settings cleared (a `nationwide` row keeps no locations, a `location` row keeps no WFO); re-saving with a changed list leaves no orphan product or location rows; the rule's own `matching_mode`/`wfo`/`county_zone_codes` end up null and `alert_rule_location` empty for a product-based rule; a category change to `threshold` clears every product row and per-product location; the keep path: a stored mixed rule saved with only `name` and `channel_ids` keeps its products, per-product settings and `risk_levels`.
- [ ] **Step 2: Run, verify failing.**
- [ ] **Step 3: Implement** as described.
- [ ] **Step 4: Run `SaveAlertRuleTest`, `AlertRuleParityTest`, `ApiAlertRulesTest`, `AlertRuleResourceTest`** (the dashboard form still saves through the flat path until Task 7, so they must stay green). Pint. Commit `Alert rules: SaveAlertRule writes per-product settings; flat input expands to them`.

---

## Task 6: API: per-product settings in, `mixed` out

**Files:**
- Modify: `app/Http/Requests/SaveAlertRuleRequest.php`, `app/Http/Resources/Api/V1/AlertRuleResource.php`, `app/Actions/Alerts/BuildAlertRuleOptions.php`, `app/Http/Controllers/Api/V1/AlertRuleController.php` (eager loads)
- Test: `tests/Feature/ApiAlertRulesTest.php`, `tests/Feature/AlertRuleParityTest.php`

**Interfaces:**
- Consumes: `SaveAlertRule::productSettings()` and the `product_settings` input (Task 5); `ProductMatching::acceptedModes()`, `::offeredModes()`, `::defaultMode()` (Task 4).
- Produces: `POST/PUT /api/v1/alert-rules` accept `product_settings` (list of the Task 5 shape) OR the flat fields, never both (422 if both); a PUT of a product-based rule with neither (and no `matching_mode`, `location_ids`, `wfo`, `county_zone_codes`, `risk_levels`) validates and keeps the stored products and settings (Task 5 keep path); a POST must carry one of them. `AlertRuleResource` adds `product_settings` (`list<{product_id, matching_mode, location_ids, wfo, county_zone_codes}>` for every watched product, from `settingsFor()`), and keeps the flat `matching_mode`, `location_ids`, `wfo`, `county_zone_codes`: when every product shares the same settings they hold that shared value; when they differ, `matching_mode` is `"mixed"`, `location_ids` is `[]`, `wfo` null, `county_zone_codes` `[]`. `BuildAlertRuleOptions` adds `modes` (list of mode values, `offeredModes`) and `default_mode` (`defaultMode`) to every product entry.

- [ ] **Step 1: Failing tests:** `product_settings` create and update; flat create still works and the resource shows the same flat values plus `product_settings`; both forms in one request -> 422; each entry's mode must be accepted for the category (a `risk_area` Weather Alerts entry -> 422; `polygon` SPC entry fine); an entry with a location mode and no `location_ids` -> 422; `wfo` unknown -> 422; zones not a known UGC code -> 422; another subscriber's location -> 422; a `mixed` rule reads `matching_mode: "mixed"`; the keep-path PUT on a mixed rule (only `category`, `name`, `channel_ids`) leaves it unchanged; options carry `modes` and `default_mode`.
- [ ] **Step 2: Run, verify failing.**
- [ ] **Step 3: Implement.** In `productRules()` branch: if the request has `product_settings`, validate `product_settings` (`required|array|min:1`), `product_settings.*.product_id` (integer, exists in the category's families, distinct), `.matching_mode` (`Rule::in(acceptedModes)`), `.location_ids` (`required_if:product_settings.*.matching_mode,location,polygon,risk_area` + `array|min:1`, items exist for the user), `.wfo` (`required_if` `wfo`, exists in `cwa_boundaries`), `.county_zone_codes` (`required_if` `county_zone`, array|min:1, items `KnownUgcCode`); add `'prohibits'` between `product_settings` and the flat keys; the flat branch keeps today's rules (including the rule-level `allowedModes`). On a PUT with none of the product/matching keys present, add no product rules (keep path). `ruleData()` passes `product_settings` through. Add `product_settings` to the controller's `WITH` loads (`productLocations`, `locations`, `products`).
- [ ] **Step 4: Run both test files, plus `RuleFormPresenterTest`/`RuleEditorTest` in `mobile/` (they must stay green).** Pint. Commit `API: per-product matching settings on alert rules`.

---

## Task 7: The wizard's product table (create and edit)

**Files:**
- Create: `app/Filament/Dashboard/Resources/AlertRules/Schemas/ProductMatchingTable.php`
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` (replace `productSections()`/`ToggleList`, matching-mode fields), `.../Pages/CreateAlertRule.php`, `.../Pages/EditAlertRule.php`
- Test: `tests/Feature/AlertRuleResourceTest.php` (migrate the create/edit cases to the new form state; keep their intent), `tests/Feature/AlertRuleParityTest.php`

**Interfaces:**
- Consumes: `ProductMatching` (Task 4), `SaveAlertRule` `product_settings` input (Task 5), the outcome and component names recorded by Task 1 in `.ai/rules/filament.md` (outcome **A**: table-mode Repeater; outcome **B**: custom Field with a Blade view; the form state and tests below are identical either way).
- Produces: form state `product_settings.{category}` for each category `ProductCategory` value: a list of rows `{product_id: int, enabled: bool, matching_mode: string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}`, one row per product of that category (all products, enabled or not), populated (a) on create by the Category radio's `afterStateUpdated` via `ProductMatching::defaultRow()` for every product of the chosen alert category, (b) on edit by `EditAlertRule::mutateFormDataBeforeFill()` from `$record->settingsFor()` for watched products (enabled true) and `defaultRow()` for the rest. `ProductMatchingTable::sections(AlertCategory $category): array<Section>` returns one collapsible `Section` per `ProductCategory` present (icon, label, collapsed rules as `productSections()` has them today, Tornado and Severe Thunderstorm expanded on create), each holding the per-category table, the risk-level checklist for SPC & WPC (`riskLevelFields()` stays), and nothing else. `CreateAlertRule::mutateFormDataBeforeCreate()` and `EditAlertRule::mutateFormDataBeforeSave()` flatten the enabled rows of every category into the `product_settings` list for `SaveAlertRule`.

Row cells, left to right: the switch (`enabled`); the product's name with its description beneath (read from the products loaded once per form build, not per row); the matching-mode select (`->options(offered modes + the row's stored mode if not offered)`, no blank option, the chosen mode's `helperText()` beneath, shown client-side from the selected value) with the mode's inputs under it, each visible only for its modes via client-side visibility (`visibleJs` or the Task 1 equivalent): the locations multi-select for `location`/`polygon`/`risk_area` (options `auth()->user()->locations()`, the existing "add a location" create action and "No saved locations yet" helper text are reused from `locationsSelect()`), the WFO select for `wfo` (`wfoField()`'s options), the state filter and zones select for `county_zone` (`countyZoneStateField()`/`countyZoneField()`'s options). A row is dimmed (client-side, `opacity-50`) while its switch is off.

Validation (dashboard): a closure rule on the first section's table, as the toggle list has today: "Select at least one product." when no row is enabled across all categories; for each enabled row: location modes need at least one location ("Choose at least one location for {product name}."), `wfo` needs an office, `county_zone` needs codes; locations must belong to the subscriber (`exists` scoped to `user_id`); the mode must be in `ProductMatching::acceptedModes($category)`.

- [ ] **Step 1: Rewrite the existing create/edit tests in `AlertRuleResourceTest.php` and `AlertRuleParityTest.php` first** to drive the new state: `fillForm(['category' => 'weather_alerts', "product_settings.{$categoryValue}" => [...rows...], 'channel_ids' => [...]])`, asserting the saved pivot rows, per-product locations and the rule-level columns being null. Add the new cases: defaults on choosing a category (a `has_polygon` product row is `polygon`, another `location`, first location preselected, none when the subscriber has no locations; SPC & WPC defaults); editing hydrates stored settings and a stored `risk_area` on a plain SPC product is kept and still saves; an old-style rule (rule-level mode, no pivot settings) edits as its inherited mode on every watched product and saves into per-product rows; validation per mode, "Select at least one product.", the no-locations message, another subscriber's location refused.
- [ ] **Step 2: Run, verify they fail.**
- [ ] **Step 3: Implement** `ProductMatchingTable`, change `AlertRuleForm::weatherAlertsFields()`/`spcWpcFields()` to use it and drop the rule-level `matchingModeFields()` for those two categories (the Radio, locations, WFO and county/zone fields move into the rows; keep their option/helper builders as shared static helpers), populate state as described, and update the two pages. Keep the scroll-to-bottom view appended to the step.
- [ ] **Step 4: Run `AlertRuleResourceTest`, `AlertRuleParityTest`, `SaveAlertRuleTest`, `ApiAlertRulesTest`.** Pint.
- [ ] **Step 5: Browser check (controller does this, not the implementer):** create and edit through the wizard on `http://localhost:8080`: no console entangle errors, each mode's inputs show and hide, a row's chosen locations survive a round trip, an edited old-style rule loads and saves, page timing as measured in Task 1.
- [ ] **Step 6: Commit** `Alert wizard: per-product matching table`.

---

## Task 8: Enable all / Disable all and search per category

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/ProductMatchingTable.php`
- Test: `tests/Feature/AlertRuleResourceTest.php`

**Interfaces:**
- Consumes: the Task 7 table and state shape.
- Produces: above each category's table, a search input that hides rows whose title and description do not contain the typed text (client-side, case-insensitive; rows keep their state while hidden) and an "Enable all" switch whose state is on when every row of this category is on; turning it on sets every row's `enabled` true, turning it off sets every row's `enabled` false, never touching another category and never changing any row's mode or inputs.

- [ ] **Step 1: Failing tests (Livewire):** setting the category switch on enables every row of that category only and leaves other categories and every row's mode/locations unchanged; off disables them all; the switch reads on exactly when every row is on (set via a row toggle) and off otherwise; the search input exists per category (assert the markup carries the per-category search and that hidden rows stay in the form state: set state, assert it saves).
- [ ] **Step 2: Run, verify failing.**
- [ ] **Step 3: Implement** with a `Toggle::make("enable_all.{$category}")->dehydrated(false)->afterStateUpdated(...)` that sets the category's rows' `enabled`, and a state sync in the other direction (a row toggle's `afterStateUpdated` recomputes the switch) unless Task 1 recorded a cheaper client-side way; the search is Alpine (`x-model` on a plain text input filtering rows by `data-search` text), not a server round trip.
- [ ] **Step 4: Run tests, Pint, browser check of search and the switch, commit** `Alert wizard: enable all and search per category`.

---

## Task 9: The example modal

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/ProductMatchingTable.php`
- Create: `resources/views/filament/dashboard/alert-rules/product-example.blade.php`
- Test: `tests/Feature/AlertRuleResourceTest.php`

**Interfaces:**
- Consumes: `Product::$example` (text, nullable; 152 of 154 products have one).
- Produces: in each row an icon button (`heroicon-o-document-text`, label "Show example") that opens a modal titled with the product name and showing the product's `example` text in a monospace block; no icon for a product with a blank `example`. The example text is read when the modal opens (a Filament `Action` registered on the form component with a `productId` argument and `->modalContent(...)`), never rendered into the page.

- [ ] **Step 1: Failing tests:** `mountAction` for a product with an example shows its text in the modal (`assertSee`); the button is absent from the markup for a product with a blank example and present for one with an example; the example text is not in the initial page HTML.
- [ ] **Step 2: Run, verify failing.**
- [ ] **Step 3: Implement.** The modal is `->modalSubmitAction(false)->modalCancelActionLabel('Close')`, the view escapes the text (`{{ }}` inside `<pre>`).
- [ ] **Step 4: Run tests, Pint, browser check, commit** `Alert wizard: product example modal`.

---

## Task 10: My Alerts list and location deletion guard

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Tables/AlertRulesTable.php`, `app/Support/LocationDeletionGuard.php`
- Test: `tests/Feature/AlertRulesTableColumnsTest.php`, `tests/Feature/LocationDeletionGuardTest.php`

**Interfaces:**
- Consumes: `AlertRule::settingsFor()`, `AlertRule::productLocations()` (Task 2).
- Produces: the matching-mode/location column shows the shared mode's short label when every product shares one mode (today's behaviour), `"Mixed"` otherwise, and its location labels are the union of the products' own locations (inherited rows use the rule's own list); `LocationDeletionGuard::alertsUsingAny()` also returns rules whose `alert_rule_product_location` rows name the location.

- [ ] **Step 1: Failing tests:** a rule whose products are all `nationwide` shows "Nationwide"; one with a `polygon` and a `location` product shows "Mixed" and the union of labels; a location used only in a per-product row blocks deletion and is named in the guard message; an old-style rule still reads as before.
- [ ] **Step 2: Run, verify failing.**
- [ ] **Step 3: Implement** (eager-load `products` and `productLocations` where the table queries; avoid N+1).
- [ ] **Step 4: Run both test files, `LocationResourceTest` if present, Pint, commit** `My Alerts: mixed matching and per-product locations in the list and the deletion guard`.

---

## Task 11: Mobile editor: mixed rules are web-only

**Files:**
- Modify: `mobile/app/Support/RuleFormPresenter.php`, `mobile/app/NativeComponents/RuleEditor.php`, `mobile/resources/views/native/rule-editor.blade.php`
- Test: `mobile/tests/Feature/RuleFormPresenterTest.php`, `mobile/tests/Feature/RuleEditorTest.php`

**Interfaces:**
- Consumes: the API's `matching_mode: "mixed"` (Task 6) and its keep path.
- Produces: `RuleFormPresenter::fromRule()` marks a draft `mixed` when `matching_mode === 'mixed'`; for a mixed draft the editor shows, in place of the products and matching sections, the line "This alert matches each product its own way. Edit its matching on the web at wxnotify.com." (no controls), validation skips the product and matching checks, and `payload()` omits `product_ids`, `matching_mode`, `location_ids`, `wfo`, `county_zone_codes` and `risk_levels` so the API keeps them.

- [ ] **Step 1: Failing tests (app):** a mixed rule's draft is flagged and its payload contains none of the six keys but still carries name, channels, quiet hours and category; a single-mode rule's payload is unchanged; the editor renders the notice for a mixed rule and no product/matching controls; saving a mixed rule posts a PUT without the keys (assert on the faked `Http` request body).
- [ ] **Step 2: Run, verify failing** (`cd mobile && vendor/bin/pest --compact <files>`).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run all `mobile/` tests that touch the editor, `php artisan native:validate` in `mobile/`, Pint, commit** `App: a rule with per-product matching is edited on the web, and a save cannot change it`.

---

## Task 12: Rules text and final verification

**Files:**
- Modify: `.ai/rules/pipeline.md`, `.ai/rules/actions.md`, `.ai/rules/filament.md` (if Task 1's note needs the final names)
- Test: none new; run the affected suites.

- [ ] **Step 1: Record the rules** (use the `record-rule` tool or edit the files): in `.ai/rules/pipeline.md`: product-based rules match each product by that product's own settings (`AlertRule::settingsFor()`), never by the rule's own columns except through a pivot row with no mode (inherit); in `.ai/rules/actions.md`: `SaveAlertRule` writes the pivot settings and the per-product locations, the flat input expands to them, the keep path for a PUT with no product fields, and the rule's own `matching_mode`/`wfo`/`county_zone_codes`/`alert_rule_location` are no longer written for product-based rules (one release, then dropped).
- [ ] **Step 2: Run the whole suite in parallel in the app container** (per the run-full-suite memory): `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`, and the `mobile/` suite. Report failures; fix none silently.
- [ ] **Step 3: Commit** `Rules: per-product matching`.
