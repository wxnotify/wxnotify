# SPC & WPC Categories and Per-Category Risk Levels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Regroup the SPC & WPC products into four categories, show each category's own risk-level choices inside its section of the alert form, and make rule matching apply a category's chosen levels only to that category's products.

**Architecture:** Two new `ProductCategory` cases plus a data migration, seeder and snapshot update move seven products. The form's single "Risk levels" checklist becomes one checklist per category section (nested state `risk_levels.{category}`), flattened into the existing `alert_rules.risk_levels` column on save and split back on edit by a small `SpcWpcRiskLevels` helper. `MatchWeatherAlertRules::matchesRiskLevel()` intersects a rule's levels with the product's own options before filtering.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5 forms, Pest.

**Spec:** `docs/superpowers/specs/2026-10-04-spc-wpc-categories-and-risk-levels-design.md` (binding; read it first).

## Global Constraints

- Category names and labels, exactly: `Convective Outlooks` (value `convective_outlooks`) and `Other Discussions, Reports, and Outlooks` (value `other_discussions_reports_and_outlooks`). The old catch-all `Other Forecasts, Outlooks, Discussions, and Reports` (`other_forecasts_outlooks_discussions_and_reports`) is **kept** for the Weather Alerts products and is not renamed.
- Product → category after this change:
  - `convective_outlooks`: `SPC.OUTLOOK` (Day 1), `DY2`, `DY3`, `D48`.
  - `other_discussions_reports_and_outlooks`: `SPC.MD`, `STA`, `PWO`.
  - Unchanged: Fire Weather (`SPC.FIRE`, `FD2`, `F38`) and Flooding and Hydrological (`WPC.MPD`).
- Risk choices come from each product's `risk_level_options`; they are NOT hardcoded in the form. Fire Weather Outlook keeps all five (Elevated, Critical, Extremely Critical, Isolated Dry T-Storms, Scattered Dry T-Storms); Day 1 keeps Marginal, Slight, Enhanced, Moderate, High.
- Storage stays the flat `alert_rules.risk_levels` JSON array: no `alert_rules` migration, no data migration for risk levels.
- Fire risk levels still do not filter alerts (extraction is out of scope); the form says so.
- The Matching mode section, the Weather Alerts form, Alert Templates and `matchesRiskArea()` are not changed.
- Conventions: Pest feature tests; Pint (`vendor/bin/pint --dirty --format agent`) before each commit; commit directly to `main`, never push; every commit message ends with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Before editing, read `.ai/rules/index.md` and the rule files covering touched paths: `filament.md` (`app/Filament/**`), `models.md` (`app/Models/**`, `app/Enums` data), `pipeline.md` (`MatchWeatherAlertRules`), `support.md` (`app/Support/**`), `feature.md` (`tests/Feature/**`).
- Tests for catalog-dependent behavior must seed the catalog (`$this->seed(ProductCatalogSeeder::class)`): `DY2`, `DY3`, `D48`, `PWO`, `STA` and every product's `category` exist only after the seeder runs.

## How to run tests (read this once)

Run Pest inside the app container (the host cannot reach the DB):

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

Feature tests fail fast on any unfaked HTTP request (`Http::preventStrayRequests()` in `tests/Pest.php`). The controller runs the full suite once at the end, in parallel: `vendor/bin/pest --parallel --processes=6` in the container. Pint runs on the host.

## File Structure

| Path | Responsibility |
| --- | --- |
| `app/Enums/ProductCategory.php` | two new cases (value, label, icon) |
| `database/migrations/2026_10_04_150000_split_spc_wpc_product_categories.php` (new) | move the seven products |
| `database/seeders/ProductCatalogSeeder.php` | new categories for the seven codes |
| `database/data/shipped_product_catalog.php` | snapshot rows for the seven codes |
| `app/Actions/Weather/MatchWeatherAlertRules.php` | `matchesRiskLevel()` uses the product's own options |
| `app/Support/SpcWpcRiskLevels.php` (new) | `split()` / `flatten()` between the flat column and per-category form state |
| `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` | per-category risk checklists, seeded defaults, old checklist removed |
| `app/Filament/Dashboard/Resources/AlertRules/Pages/{CreateAlertRule,EditAlertRule}.php` | flatten on save, split on fill |
| `tests/Feature/SpcWpcCategoriesTest.php` (new) | enum, seeder and migration tests |
| `tests/Feature/SpcWpcRiskLevelsTest.php` (new) | helper tests |
| `tests/Feature/MatchWeatherAlertRulesTest.php`, `tests/Feature/AlertRuleResourceTest.php` | new matching and form tests; two existing form tests move to the nested shape |
| `.ai/rules/filament.md`, `.ai/rules/models.md` | rule notes |

---

### Task 1: The new categories and the data move

**Files:**
- Modify: `app/Enums/ProductCategory.php`, `database/seeders/ProductCatalogSeeder.php`, `database/data/shipped_product_catalog.php`
- Create: `database/migrations/2026_10_04_150000_split_spc_wpc_product_categories.php`
- Test: `tests/Feature/SpcWpcCategoriesTest.php`

**Interfaces:**
- Produces: `ProductCategory::ConvectiveOutlooks` (`'convective_outlooks'`), `ProductCategory::OtherDiscussionsReportsAndOutlooks` (`'other_discussions_reports_and_outlooks'`). Later tasks refer to these only by value.

Read `.ai/rules/models.md` and `.ai/rules/pages.md` (the catalog snapshot rule) first.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/SpcWpcCategoriesTest.php`:

```php
<?php

use App\Enums\ProductCategory;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;

const SPC_WPC_MOVES = [
    'convective_outlooks' => ['SPC.OUTLOOK', 'DY2', 'DY3', 'D48'],
    'other_discussions_reports_and_outlooks' => ['SPC.MD', 'STA', 'PWO'],
];

function splitCategoriesMigration(): object
{
    return require collect(glob(database_path('migrations/*_split_spc_wpc_product_categories.php')))->sole();
}

test('the new categories have a unique value, a label and an icon', function () {
    expect(ProductCategory::ConvectiveOutlooks->value)->toBe('convective_outlooks')
        ->and(ProductCategory::ConvectiveOutlooks->label())->toBe('Convective Outlooks')
        ->and(ProductCategory::OtherDiscussionsReportsAndOutlooks->value)->toBe('other_discussions_reports_and_outlooks')
        ->and(ProductCategory::OtherDiscussionsReportsAndOutlooks->label())->toBe('Other Discussions, Reports, and Outlooks');

    $values = array_map(fn (ProductCategory $c): string => $c->value, ProductCategory::cases());

    expect($values)->toHaveCount(count(array_unique($values)));

    foreach (ProductCategory::cases() as $category) {
        expect($category->label())->not->toBe('')->and($category->icon())->toStartWith('fas-');
    }
});

test('the new categories sit after the old catch-all so the SPC & WPC form lists them in order', function () {
    $order = array_map(fn (ProductCategory $c): string => $c->value, ProductCategory::cases());

    expect(array_search('flooding_and_hydrological', $order, true))->toBeLessThan(array_search('fire_weather', $order, true))
        ->and(array_search('fire_weather', $order, true))->toBeLessThan(array_search('convective_outlooks', $order, true))
        ->and(array_search('convective_outlooks', $order, true))->toBeLessThan(array_search('other_discussions_reports_and_outlooks', $order, true));
});

test('the seeded catalog puts every SPC & WPC product in its category', function () {
    $this->seed(ProductCatalogSeeder::class);

    foreach (SPC_WPC_MOVES as $category => $codes) {
        expect(DB::table('products')->whereIn('code', $codes)->pluck('category', 'code')->all())
            ->toEqual(array_fill_keys($codes, $category));
    }

    expect(DB::table('products')->where('family', 'spc_wpc')->orderBy('code')->pluck('category', 'code')->all())
        ->toMatchArray([
            'SPC.FIRE' => 'fire_weather', 'FD2' => 'fire_weather', 'F38' => 'fire_weather',
            'WPC.MPD' => 'flooding_and_hydrological',
        ]);
});

test('the Weather Alerts products keep the old catch-all category', function () {
    $this->seed(ProductCatalogSeeder::class);

    expect(DB::table('products')->where('code', 'AFD')->value('category'))->toBe('other_forecasts_outlooks_discussions_and_reports');
});

test('the migration moves the seven products out of the old category, and only from it', function () {
    $this->seed(ProductCatalogSeeder::class);
    $old = 'other_forecasts_outlooks_discussions_and_reports';
    DB::table('products')->whereIn('code', [...SPC_WPC_MOVES['convective_outlooks'], ...SPC_WPC_MOVES['other_discussions_reports_and_outlooks']])->update(['category' => $old]);
    DB::table('products')->where('code', 'SPC.MD')->update(['category' => 'fire_weather']); // staff chose another category

    splitCategoriesMigration()->up();

    expect(DB::table('products')->whereIn('code', SPC_WPC_MOVES['convective_outlooks'])->pluck('category')->unique()->all())->toBe(['convective_outlooks'])
        ->and(DB::table('products')->whereIn('code', ['STA', 'PWO'])->pluck('category')->unique()->all())->toBe(['other_discussions_reports_and_outlooks'])
        ->and(DB::table('products')->where('code', 'SPC.MD')->value('category'))->toBe('fire_weather');
});

test('the migration reverses cleanly', function () {
    $this->seed(ProductCatalogSeeder::class);

    splitCategoriesMigration()->down();

    expect(DB::table('products')->whereIn('code', [...SPC_WPC_MOVES['convective_outlooks'], ...SPC_WPC_MOVES['other_discussions_reports_and_outlooks']])->pluck('category')->unique()->all())
        ->toBe(['other_forecasts_outlooks_discussions_and_reports']);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `... vendor/bin/pest --compact tests/Feature/SpcWpcCategoriesTest.php`
Expected: FAIL (undefined enum cases / missing migration file).

- [ ] **Step 3: Add the enum cases**

In `app/Enums/ProductCategory.php`, add the cases directly after `OtherForecastsOutlooksDiscussionsAndReports` (before `NonMeteorologicalHazardsAndAdministrativeMessages`):

```php
    case ConvectiveOutlooks = 'convective_outlooks';
    case OtherDiscussionsReportsAndOutlooks = 'other_discussions_reports_and_outlooks';
```

In `label()` add, after the `OtherForecastsOutlooksDiscussionsAndReports` arm:

```php
            self::ConvectiveOutlooks => 'Convective Outlooks',
            self::OtherDiscussionsReportsAndOutlooks => 'Other Discussions, Reports, and Outlooks',
```

In `icon()` add, after the `OtherForecastsOutlooksDiscussionsAndReports` arm:

```php
            self::ConvectiveOutlooks => 'fas-cloud-bolt',
            self::OtherDiscussionsReportsAndOutlooks => 'fas-file-lines',
```

If `fas-cloud-bolt` or `fas-file-lines` is not an installed blade-fontawesome icon (the form would throw `Svg by name … not found` when rendering the section), substitute the nearest installed solid icon and say so in your report.

- [ ] **Step 4: Create the data migration**

`database/migrations/2026_10_04_150000_split_spc_wpc_product_categories.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = 'other_forecasts_outlooks_discussions_and_reports';

    /** @var array<string, list<string>> new category => product codes */
    private const MOVES = [
        'convective_outlooks' => ['SPC.OUTLOOK', 'DY2', 'DY3', 'D48'],
        'other_discussions_reports_and_outlooks' => ['SPC.MD', 'STA', 'PWO'],
    ];

    /**
     * The SPC & WPC products shared one catch-all category with the Weather Alerts products;
     * the SPC & WPC alert form now groups them as Convective Outlooks and Other Discussions,
     * Reports, and Outlooks. A product moves only while it is still in the old category, so a
     * category staff chose in the admin catalog is left alone. Products the seeder has not
     * created yet (DY2 and friends on a fresh install) are skipped: the seeder assigns the new
     * categories itself.
     */
    public function up(): void
    {
        foreach (self::MOVES as $category => $codes) {
            DB::table('products')->whereIn('code', $codes)->where('category', self::OLD)->update(['category' => $category]);
        }
    }

    public function down(): void
    {
        foreach (self::MOVES as $category => $codes) {
            DB::table('products')->whereIn('code', $codes)->where('category', $category)->update(['category' => self::OLD]);
        }
    }
};
```

- [ ] **Step 5: Update the seeder**

The matched-product pass of `ProductCatalogSeeder` re-applies each product's category on every run, so it must carry the new categories. In `database/seeders/ProductCatalogSeeder.php` change `ProductCategory::OtherForecastsOutlooksDiscussionsAndReports` to the new case on exactly these seven lines (leave every other line, including `AFD`, on the old case):

- `'DY1'` (`SPC.OUTLOOK`), `'DY2'`, `'DY3'`, `'D48'` → `ProductCategory::ConvectiveOutlooks`
- `'SWO'` (`SPC.MD`), `'PWO'`, `'STA'` → `ProductCategory::OtherDiscussionsReportsAndOutlooks`

Check with: `grep -n "ConvectiveOutlooks\|OtherDiscussionsReportsAndOutlooks" database/seeders/ProductCatalogSeeder.php` (expect 7 lines) and `grep -c "OtherForecastsOutlooksDiscussionsAndReports" database/seeders/ProductCatalogSeeder.php` (the Weather Alerts products keep it).

- [ ] **Step 6: Update the shipped snapshot**

`database/data/shipped_product_catalog.php` is compared with a freshly seeded catalog by `ShippedProductCatalogTest` and `FactoryResetActionsTest`. Update the `category` of the same seven rows (each row is an array with `'code' => '…'`; other fields unchanged). Run from the repo root:

```bash
python3 - <<'EOF'
import re
p = 'database/data/shipped_product_catalog.php'
s = open(p).read()
moves = {
    'convective_outlooks': ['SPC.OUTLOOK', 'DY2', 'DY3', 'D48'],
    'other_discussions_reports_and_outlooks': ['SPC.MD', 'STA', 'PWO'],
}
for category, codes in moves.items():
    for code in codes:
        pattern = re.compile(r"('code' => '" + re.escape(code) + r"',(?:(?!\n    \d+ => \[).)*?'category' => )'other_forecasts_outlooks_discussions_and_reports'", re.S)
        s, n = pattern.subn(lambda m: m.group(1) + "'" + category + "'", s, count=1)
        assert n == 1, code
open(p, 'w').write(s)
EOF
```

- [ ] **Step 7: Run the tests**

Run: `... vendor/bin/pest --compact tests/Feature/SpcWpcCategoriesTest.php tests/Feature/ShippedProductCatalogTest.php tests/Feature/FactoryResetActionsTest.php tests/Feature/ProductCatalogSeederTest.php tests/Feature/ProductResourceTest.php`
Expected: PASS. (If one of the last two files does not exist, drop it from the command.)

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/ProductCategory.php database/migrations/2026_10_04_150000_split_spc_wpc_product_categories.php database/seeders/ProductCatalogSeeder.php database/data/shipped_product_catalog.php tests/Feature/SpcWpcCategoriesTest.php
git commit -m "Split the SPC & WPC products into Convective Outlooks and Other Discussions, Reports, and Outlooks

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Match a category's risk levels only against that category's products

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php` (`matchesRiskLevel()`, docblock)
- Test: `tests/Feature/MatchWeatherAlertRulesTest.php` (append)

**Interfaces:**
- Consumes: nothing from Task 1 (uses existing `Product::risk_level_options`, `IngestedProduct::risk_levels`).
- Produces: unchanged signature `matchesRiskLevel(AlertRule $rule, IngestedProduct $product): bool` (private).

Read `.ai/rules/pipeline.md` first.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/MatchWeatherAlertRulesTest.php` (it already imports `Queue`, `Product`, `AlertRule`, `NotificationChannel`, `DeliverWeatherAlert`, `Location`, and defines `createIngestedProductWithoutMatching()`):

```php
function riskRule(array $productCodes, array $riskLevels): AlertRule
{
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null, 'risk_levels' => $riskLevels]);
    $rule->products()->attach(Product::whereIn('code', $productCodes)->pluck('id'));
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    return $rule;
}

test('a rule with only a Fire level ticked still matches a Day 1 outlook that states other levels', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    riskRule(['SPC.OUTLOOK', 'SPC.FIRE'], ['Elevated']);
    $ingested = createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => ['Marginal', 'Slight']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Convective level still filters the Day 1 outlook when a Fire level is ticked as well', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    riskRule(['SPC.OUTLOOK', 'SPC.FIRE'], ['Elevated', 'High']);

    (new MatchWeatherAlertRules)(createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => ['Marginal', 'Slight']]));
    Queue::assertNotPushed(DeliverWeatherAlert::class);

    (new MatchWeatherAlertRules)(createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => ['Slight', 'High']]));
    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('Convective levels never block the Fire Weather Outlook', function () {
    Queue::fake();
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    riskRule(['SPC.OUTLOOK', 'SPC.FIRE'], ['High']);
    $ingested = createIngestedProductWithoutMatching(['product_id' => $fire->id, 'risk_levels' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('levels that belong to none of the rule\'s product options apply no filter', function () {
    Queue::fake();
    $day2 = Product::where('code', 'DY2')->sole(); // has no risk_level_options
    riskRule(['DY2'], ['High']);
    $ingested = createIngestedProductWithoutMatching(['product_id' => $day2->id, 'risk_levels' => ['Marginal']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('the risk-area threshold ignores a ticked level from another category', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $location = Location::factory()->create(['lat' => 31, 'lng' => -99]); // inside Slight only
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'risk_area', 'risk_levels' => ['Elevated']]);
    $rule->locations()->attach($location);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $outlook->id,
        'risk_level_polygons' => [
            'Slight' => [['lat' => 30, 'lon' => -100], ['lat' => 30, 'lon' => -90], ['lat' => 40, 'lon' => -90], ['lat' => 40, 'lon' => -100]],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});
```

These tests need the full catalog (`DY2`, categories): if this file's other tests do not already seed it, add `$this->seed(Database\Seeders\ProductCatalogSeeder::class);` at the start of the `DY2` test only.

- [ ] **Step 2: Run them to verify the right ones fail**

Run: `... vendor/bin/pest --compact tests/Feature/MatchWeatherAlertRulesTest.php --filter="Fire level|Convective level|levels that belong|ignores a ticked level"`
Expected: `a rule with only a Fire level ticked…` FAILS (currently no match); the other four pass already (they pin behavior that must not regress).

- [ ] **Step 3: Implement**

In `app/Actions/Weather/MatchWeatherAlertRules.php`, replace the body of `matchesRiskLevel()` and extend its docblock:

```php
    private function matchesRiskLevel(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->category !== AlertCategory::SpcWpc || $rule->risk_levels === null || $rule->risk_levels === []) {
            return true;
        }

        // The rule's levels are one flat list across every category it watches (the form
        // keeps a checklist per category), so only the levels this product itself offers
        // count for it. None of them belonging to it means the subscriber ticked nothing
        // for this product's category: no filter, i.e. every level.
        $relevant = array_values(array_intersect($rule->risk_levels, $product->product?->risk_level_options ?? []));

        if ($relevant === []) {
            return true;
        }

        if ($product->risk_levels === null) {
            return true;
        }

        return array_intersect($relevant, $product->risk_levels) !== [];
    }
```

Add this paragraph to the method's docblock, after the existing text: "Only the levels in the product's own `risk_level_options` count: a rule watching both the Convective Outlook and the Fire Weather Outlook holds both categories' ticks in one list, and a level ticked for one category must not filter the other's products. `matchesRiskArea()` already ranks only levels found in the product's options, so it needs no equivalent change."

- [ ] **Step 4: Run the tests**

Run: `... vendor/bin/pest --compact tests/Feature/MatchWeatherAlertRulesTest.php`
Expected: PASS (all, including the existing risk-level tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/MatchWeatherAlertRulesTest.php
git commit -m "Match a rule's risk levels only against products that offer them

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Per-category risk checklists in the alert form

**Files:**
- Create: `app/Support/SpcWpcRiskLevels.php`
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/EditAlertRule.php`
- Test: `tests/Feature/SpcWpcRiskLevelsTest.php` (new), `tests/Feature/AlertRuleResourceTest.php` (two existing tests move to the nested shape, new tests appended)

**Interfaces:**
- Consumes: Task 1's categories (as values), seeded catalog.
- Produces: `SpcWpcRiskLevels::optionsByCategory(): array<string, list<string>>`, `::split(?array $flat): array<string, list<string>>`, `::flatten(mixed $byCategory): list<string>`. Form state `risk_levels.{category}`.

Read `.ai/rules/filament.md` and `.ai/rules/support.md` first. The form's existing comments explain why product fields are nested per category and seeded to `[]` (`seedProductIdsDefaults()`); the risk checklists must follow the same pattern.

- [ ] **Step 1: Write the failing helper tests**

`tests/Feature/SpcWpcRiskLevelsTest.php`:

```php
<?php

use App\Support\SpcWpcRiskLevels;
use Database\Seeders\ProductCatalogSeeder;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);
});

test('only the Fire Weather and Convective Outlooks categories offer risk levels, from their products\' own options', function () {
    expect(SpcWpcRiskLevels::optionsByCategory())->toBe([
        'fire_weather' => ['Elevated', 'Critical', 'Extremely Critical', 'Isolated Dry T-Storms', 'Scattered Dry T-Storms'],
        'convective_outlooks' => ['Marginal', 'Slight', 'Enhanced', 'Moderate', 'High'],
    ]);
});

test('split gives each category the stored levels it offers, in option order', function () {
    expect(SpcWpcRiskLevels::split(['High', 'Elevated', 'Marginal', 'Scattered Dry T-Storms']))->toBe([
        'fire_weather' => ['Elevated', 'Scattered Dry T-Storms'],
        'convective_outlooks' => ['Marginal', 'High'],
    ]);
});

test('split of nothing gives empty lists for every category', function () {
    expect(SpcWpcRiskLevels::split(null))->toBe(['fire_weather' => [], 'convective_outlooks' => []])
        ->and(SpcWpcRiskLevels::split([]))->toBe(['fire_weather' => [], 'convective_outlooks' => []]);
});

test('split drops a stored level no category offers', function () {
    expect(SpcWpcRiskLevels::split(['Nonsense', 'Slight']))->toBe(['fire_weather' => [], 'convective_outlooks' => ['Slight']]);
});

test('flatten merges the per-category lists into one unique list', function () {
    expect(SpcWpcRiskLevels::flatten(['fire_weather' => ['Elevated'], 'convective_outlooks' => ['Slight', 'High']]))->toBe(['Elevated', 'Slight', 'High'])
        ->and(SpcWpcRiskLevels::flatten(['fire_weather' => ['Elevated'], 'convective_outlooks' => ['Elevated']]))->toBe(['Elevated']);
});

test('flatten tolerates missing, null and junk state', function () {
    expect(SpcWpcRiskLevels::flatten(null))->toBe([])
        ->and(SpcWpcRiskLevels::flatten([]))->toBe([])
        ->and(SpcWpcRiskLevels::flatten(['fire_weather' => null, 'convective_outlooks' => [true, 5, 'Slight']]))->toBe(['Slight']);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `... vendor/bin/pest --compact tests/Feature/SpcWpcRiskLevelsTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Create the helper**

`app/Support/SpcWpcRiskLevels.php`:

```php
<?php

namespace App\Support;

use App\Models\Product;

/**
 * Converts between the flat `alert_rules.risk_levels` column and the per-category
 * state the SPC & WPC alert form keeps (`risk_levels.{category}`, one checklist per
 * category section whose products offer risk levels). Which levels belong to which
 * category is read from the products' own `risk_level_options`, never hardcoded.
 */
final class SpcWpcRiskLevels
{
    /**
     * Each category that has a product with risk levels, mapped to the union of those
     * products' options (in option order).
     *
     * @return array<string, list<string>>
     */
    public static function optionsByCategory(): array
    {
        return Product::query()
            ->where('family', 'spc_wpc')
            ->whereNotNull('risk_level_options')
            ->orderBy('sort_order')
            ->get(['category', 'risk_level_options'])
            ->groupBy(fn (Product $product): ?string => $product->category?->value)
            ->reject(fn ($products, $category): bool => $category === '')
            ->map(fn ($products): array => $products->pluck('risk_level_options')->flatten()->unique()->values()->all())
            ->all();
    }

    /**
     * The form state for a stored flat list: every category that offers risk levels,
     * with the stored levels it offers (in option order). Levels no category offers drop.
     *
     * @param  list<string>|null  $flat
     * @return array<string, list<string>>
     */
    public static function split(?array $flat): array
    {
        $flat ??= [];

        return collect(self::optionsByCategory())
            ->map(fn (array $options): array => array_values(array_filter($options, fn (string $level): bool => in_array($level, $flat, true))))
            ->all();
    }

    /**
     * The flat list to store for per-category form state; anything that is not a level
     * name (null, booleans from a broken binding) is ignored.
     *
     * @return list<string>
     */
    public static function flatten(mixed $byCategory): array
    {
        return collect($byCategory)->flatten()->filter(fn (mixed $level): bool => is_string($level) && $level !== '')->unique()->values()->all();
    }
}
```

Run the helper tests: expected PASS. If `optionsByCategory()`'s result key order differs from the test's (`fire_weather` then `convective_outlooks`), order the query/grouping so Fire Weather comes first (the `ProductCategory` enum order), e.g. sort the final map by `array_search($category, array_map(fn ($c) => $c->value, ProductCategory::cases()))`, and keep the test as written.

- [ ] **Step 4: Update the two existing form tests to the nested shape, and write the new form tests (failing)**

In `tests/Feature/AlertRuleResourceTest.php`:

1. In `creating an spc wpc rule persists the chosen risk levels`, change `'risk_levels' => ['Enhanced', 'Moderate', 'High'],` to `'risk_levels' => ['convective_outlooks' => ['Enhanced', 'Moderate', 'High']],` (the final assertion `$rule->risk_levels` stays the flat `['Enhanced', 'Moderate', 'High']`).
2. In `an spc wpc rule for the Convective Outlook can use RiskArea matching mode…`, change `'risk_levels' => ['Moderate'],` to `'risk_levels' => ['convective_outlooks' => ['Moderate']],`.

Append these new tests (the file's `beforeEach` seeds the catalog and defines `$this->user`, `$this->channel`; add `use Filament\Forms\Components\CheckboxList;` to the imports):

```php
test('the SPC & WPC form lists the category sections in order, each with its own risk levels where its products have them', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => ['fire_weather' => [$fire->id], 'convective_outlooks' => [$outlook->id]],
        ])
        ->assertFormFieldExists('risk_levels.fire_weather', fn (CheckboxList $field): bool => array_keys($field->getOptions()) === ['Elevated', 'Critical', 'Extremely Critical', 'Isolated Dry T-Storms', 'Scattered Dry T-Storms'])
        ->assertFormFieldExists('risk_levels.convective_outlooks', fn (CheckboxList $field): bool => array_keys($field->getOptions()) === ['Marginal', 'Slight', 'Enhanced', 'Moderate', 'High'])
        ->assertFormFieldVisible('risk_levels.fire_weather')
        ->assertFormFieldVisible('risk_levels.convective_outlooks');
});

test('a category\'s risk levels appear only while a product with risk levels is selected in it', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $day2 = Product::where('code', 'DY2')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'spc_wpc', 'product_ids' => ['convective_outlooks' => [$day2->id]]])
        ->assertFormFieldHidden('risk_levels.convective_outlooks')
        ->assertFormFieldHidden('risk_levels.fire_weather')
        ->fillForm(['product_ids' => ['convective_outlooks' => [$outlook->id]]])
        ->assertFormFieldVisible('risk_levels.convective_outlooks')
        ->assertFormFieldHidden('risk_levels.fire_weather')
        ->fillForm(['product_ids' => ['convective_outlooks' => [], 'fire_weather' => [$fire->id]]])
        ->assertFormFieldHidden('risk_levels.convective_outlooks')
        ->assertFormFieldVisible('risk_levels.fire_weather');
});

test('the Fire Weather list says fire levels do not filter yet, the Convective list says it applies to Day 1', function () {
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'spc_wpc', 'product_ids' => ['fire_weather' => [$fire->id], 'convective_outlooks' => [$outlook->id]]])
        ->assertSee("Fire risk levels are saved, but they don't filter alerts yet.")
        ->assertSee('Risk levels apply to the Day 1 Convective Outlook.');
});

test('categories without risk levels never get a risk list', function () {
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'spc_wpc'])
        ->assertFormFieldDoesNotExist('risk_levels.flooding_and_hydrological')
        ->assertFormFieldDoesNotExist('risk_levels.other_discussions_reports_and_outlooks');
});

test('creating an SPC & WPC rule with ticks in two categories stores one flat list', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => ['fire_weather' => [$fire->id], 'convective_outlooks' => [$outlook->id]],
            'risk_levels' => ['fire_weather' => ['Critical'], 'convective_outlooks' => ['Moderate', 'High']],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->sole()->risk_levels)->toBe(['Critical', 'Moderate', 'High']);
});

test('ticks in a category whose products were deselected are not stored', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => ['fire_weather' => [$fire->id], 'convective_outlooks' => [$outlook->id]],
            'risk_levels' => ['fire_weather' => ['Critical'], 'convective_outlooks' => ['High']],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->fillForm(['product_ids' => ['fire_weather' => [], 'convective_outlooks' => [$outlook->id]]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->sole()->risk_levels)->toBe(['High']);
});

test('editing an SPC & WPC rule restores each category\'s ticks from the flat list, and saves them back flat', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    $rule = AlertRule::factory()->for($this->user)->create([
        'category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null,
        'risk_levels' => ['Elevated', 'Moderate'],
    ]);
    $rule->products()->attach([$outlook->id, $fire->id]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['risk_levels' => ['fire_weather' => ['Elevated'], 'convective_outlooks' => ['Moderate']]])
        ->fillForm(['risk_levels' => ['fire_weather' => ['Elevated'], 'convective_outlooks' => ['Moderate', 'High']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->refresh()->risk_levels)->toBe(['Elevated', 'Moderate', 'High']);
});

test('a rule saved before this change with only Convective levels edits and saves unchanged', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->for($this->user)->create([
        'category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null,
        'risk_levels' => ['Enhanced', 'High'],
    ]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['risk_levels' => ['fire_weather' => [], 'convective_outlooks' => ['Enhanced', 'High']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->refresh()->risk_levels)->toBe(['Enhanced', 'High']);
});

test('an SPC & WPC rule with no ticks stores an empty list, meaning every level', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => ['convective_outlooks' => [$outlook->id]],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->sole()->risk_levels)->toBe([]);
});
```

If a Filament assertion name used above does not exist in the installed version (`assertFormFieldVisible`, `assertFormFieldHidden`, `assertFormFieldDoesNotExist`), use the nearest equivalents already used elsewhere in the test suite (for example `assertFormFieldIsVisible`/`assertFormFieldIsHidden`) and keep the assertion's meaning. Address nested fields the way the existing `product_ids.*` tests do.

- [ ] **Step 5: Run the form tests to verify they fail**

Run: `... vendor/bin/pest --compact tests/Feature/AlertRuleResourceTest.php`
Expected: the new tests and the two updated ones FAIL (no per-category fields yet); the rest pass.

- [ ] **Step 6: Implement the form**

In `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`:

(a) In `spcWpcFields()` delete the old combined `CheckboxList::make('risk_levels')` block entirely. The method becomes:

```php
    protected static function spcWpcFields(): array
    {
        return [
            ...self::productSections(Product::query()
                ->where('family', 'spc_wpc')
                ->where('status', 'active')),
            ...self::matchingModeFields(AlertCategory::SpcWpc),
        ];
    }
```

(b) In `productSections()`: add `'risk_level_options'` to the `get([...])` column list, and build each section's schema as the toggle list followed by that category's risk checklist when any of its products offers risk levels. Replace the final `->schema([$toggleList])` with `->schema([$toggleList, ...self::riskLevelFields($category, $products)])`.

(c) Add these methods next to `hasRiskLevelProducts()`:

```php
    /**
     * A category's own "Risk levels" checklist, shown inside that category's section when
     * any product in it offers risk levels (today: Fire Weather and Convective Outlooks).
     * State is nested per category, `risk_levels.{category}`, like `product_ids.{category}`,
     * so each checklist keeps its own state; the Create/Edit pages flatten it into the
     * stored `risk_levels` column and split it back on edit (see SpcWpcRiskLevels).
     * Options are the selected products' own `risk_level_options`; the list is hidden unless
     * a selected product in this category offers some.
     *
     * @param  Collection<int, Product>  $products  the category's products
     * @return array<CheckboxList>
     */
    protected static function riskLevelFields(ProductCategory $category, Collection $products): array
    {
        $riskProducts = $products->filter(fn (Product $product): bool => filled($product->risk_level_options));

        if ($riskProducts->isEmpty()) {
            return [];
        }

        $selectedRiskProducts = fn (Get $get): Collection => $riskProducts
            ->whereIn('id', self::selectedProductIds($get, "product_ids.{$category->value}"));

        return [
            CheckboxList::make("risk_levels.{$category->value}")
                ->label('Risk levels')
                ->columns(2)
                ->options(fn (Get $get): array => $selectedRiskProducts($get)
                    ->pluck('risk_level_options')
                    ->flatten()
                    ->unique()
                    ->mapWithKeys(fn (string $level): array => [$level => $level])
                    ->all())
                ->helperText(self::riskLevelsHelperText($category))
                ->visible(fn (Get $get): bool => $selectedRiskProducts($get)->isNotEmpty()),
        ];
    }

    protected static function riskLevelsHelperText(ProductCategory $category): string
    {
        $base = 'Leave all unchecked to receive every risk level for the products above.';

        return match ($category) {
            ProductCategory::FireWeather => "{$base} Fire risk levels are saved, but they don't filter alerts yet.",
            ProductCategory::ConvectiveOutlooks => "{$base} Risk levels apply to the Day 1 Convective Outlook.",
            default => $base,
        };
    }
```

Add `use Illuminate\Support\Collection;` to the imports if absent.

(d) Extend `seedProductIdsDefaults()` so the risk checklists start as real arrays (same checkbox-binding bug the method's docblock describes). Inside its `foreach`, after the product line, add:

```php
            if ($get("risk_levels.{$category->value}") === null) {
                $set("risk_levels.{$category->value}", []);
            }
```

Update that method's docblock with one sentence: "The per-category `risk_levels.{category}` checklists have the same null-binding problem and are seeded here too."

In `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php`, make `mutateFormDataBeforeCreate()` flatten when the key is present (a hidden/non-SPC&WPC form never dehydrates it):

```php
        if (array_key_exists('risk_levels', $data)) {
            $data['risk_levels'] = SpcWpcRiskLevels::flatten($data['risk_levels']);
        }
```

(placed before the `return AlertRuleFormUnits::toStorage(...)`), plus `use App\Support\SpcWpcRiskLevels;`.

In `EditAlertRule.php`:
- in `mutateFormDataBeforeFill()`, after `$data['location_ids'] = ...`, add: `if (($data['category'] ?? null) === AlertCategory::SpcWpc->value) { $data['risk_levels'] = SpcWpcRiskLevels::split($data['risk_levels'] ?? []); }`
- in `mutateFormDataBeforeSave()`, before the final `return`, add the same `array_key_exists('risk_levels', $data)` flatten as in Create.
- add `use App\Support\SpcWpcRiskLevels;`.

- [ ] **Step 7: Run the tests**

Run: `... vendor/bin/pest --compact tests/Feature/SpcWpcRiskLevelsTest.php tests/Feature/AlertRuleResourceTest.php tests/Feature/AlertRuleFormUnitsTest.php tests/Feature/MatchWeatherAlertRulesTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/SpcWpcRiskLevels.php app/Filament/Dashboard/Resources/AlertRules tests/Feature/SpcWpcRiskLevelsTest.php tests/Feature/AlertRuleResourceTest.php
git commit -m "Give each SPC & WPC category its own risk-level choices in the alert form

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Rule notes

**Files:**
- Modify: `.ai/rules/filament.md`, `.ai/rules/models.md`

- [ ] **Step 1: Add the notes**

Append to `.ai/rules/filament.md`:

```markdown

## SPC & WPC risk levels are nested per category in the form and flattened for storage
The SPC & WPC alert form shows a "Risk levels" checklist inside each category section whose products have `risk_level_options` (today Fire Weather and Convective Outlooks), named `risk_levels.{category}` like `product_ids.{category}`; options are the selected products' own options and the list is hidden unless such a product is selected. `alert_rules.risk_levels` stays ONE flat JSON array: `CreateAlertRule`/`EditAlertRule` flatten the nested state with `App\Support\SpcWpcRiskLevels::flatten()` before saving and `split()` it by category on fill (a category's levels are those found in the options of that category's products). The checklists are seeded to `[]` by `seedProductIdsDefaults()` for the same null-checkbox-binding reason as the product lists. Fire risk levels are stored but do not filter yet (no extraction), and the form says so. Never read a category's levels from the form without going through the helper.
```

Append to `.ai/rules/models.md`:

```markdown

## SPC & WPC products use their own categories; risk levels are matched per product
The SPC & WPC products are grouped Flooding and Hydrological (`WPC.MPD`), Fire Weather (`SPC.FIRE`, `FD2`, `F38`), Convective Outlooks (`SPC.OUTLOOK` = Day 1, `DY2`, `DY3`, `D48`) and Other Discussions, Reports, and Outlooks (`SPC.MD`, `STA`, `PWO`). The old `other_forecasts_outlooks_discussions_and_reports` category now holds only Weather Alerts products. The category lives in four places that must change together: the `ProductCategory` enum, a data migration (moves a product only while it is still in the old category), `ProductCatalogSeeder` (its matched-product pass re-applies categories on every run, so a stale seeder silently undoes a migration) and the hand-edited `database/data/shipped_product_catalog.php` snapshot. `MatchWeatherAlertRules::matchesRiskLevel()` counts only the rule's levels that appear in the product's own `risk_level_options` (none means no filter), because the rule's `risk_levels` is one flat list across every category it watches.
```

- [ ] **Step 2: Commit**

```bash
git add .ai/rules/filament.md .ai/rules/models.md
git commit -m "Record the SPC & WPC category and risk-level rules

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Controller verification (not a task for an implementer)

After Task 4 and the final review: run the migration on the dev database (`php artisan migrate` in the container), then check in the browser as a throwaway subscriber: create and edit an SPC & WPC alert with the Day 1 outlook and the Fire Weather Outlook; confirm the section order (Flooding, Fire Weather, Convective Outlooks, Other Discussions, Reports, and Outlooks), each category's own risk list and note, deselecting a category hides its list, the round trip on edit, and that Matching mode is unchanged at the bottom. Delete the throwaway data. Run the full suite in parallel with 6 processes. Do not push.
