# Multi-Location Weather Alerts / SPC & WPC Alert Rules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a subscriber attach more than one saved `Location` to a Weather Alerts or SPC & WPC alert rule, with "any attached location matches → the rule fires" semantics.

**Architecture:** A new `alert_rule_location` pivot (mirroring the existing `alert_rule_product` pivot) sits alongside the untouched singular `location_id`/`location()` that every other category keeps using. `MatchWeatherAlertRules` loops the rule's attached locations for Location/Polygon/RiskArea modes and returns a small `GeographyMatch` result object (matched + which location) instead of a bare bool; that one piece of information threads through to both quiet-hours timezone resolution and `{location}` subject rendering, so no separate policy is needed for either. The multi-select form field is a plain `Select::make('location_ids')->multiple()`, following this codebase's own `county_zone_codes` field exactly (same dynamic Wizard step, already proven safe in a real browser).

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5, Pest, PostgreSQL + PostGIS.

**Spec:** `docs/superpowers/specs/2026-10-01-multi-location-alert-rules-design.md`

## Global Constraints

- Threshold, Daily Weather Summary, Lightning, and Forecast Discussion categories are **untouched** — `location_id`/`location()`/`wfo` keep working exactly as today. Nothing in this plan removes or renames those.
- `AlertRule::location_id`/`location()` are **not removed**. A new `locations()` relation is additive.
- Matching semantics: **any** attached location satisfying the mode's geography check fires the rule (not all, not a majority).
- Every migration, model change, and matching-engine change in this plan touches paths governed by `.ai/rules/models.md`, `.ai/rules/pipeline.md`, `.ai/rules/jobs.md`, and `.ai/rules/filament.md` — each task below names the specific rule it must follow.
- Run `vendor/bin/pint --dirty --format agent` after every task with PHP changes, and the narrowest relevant `vendor/bin/pest` filter after every step that adds/changes a test, before moving on.
- This project's `.env`/containers run via `serversideup/php` Docker Compose (service `wxnotify-nextgen-front-end-1` in this working tree) — run `artisan`/`pest`/`pint` via `docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && <command>"`, matching every prior session's convention in this repo.

---

### Task 1: Pivot table, backfill, model relation, factory helper

**Files:**
- Create: `database/migrations/2026_10_01_300001_create_alert_rule_location_table.php`
- Modify: `app/Models/AlertRule.php:76-78` (add `locations()` next to `location()`)
- Modify: `database/factories/AlertRuleFactory.php` (add a `locations()` state helper)
- Test: `tests/Feature/AlertRuleLocationPivotTest.php` (new)

**Interfaces:**
- Produces: `AlertRule::locations(): BelongsToMany` (pivot table `alert_rule_location`, composite PK `[alert_rule_id, location_id]`, both FKs `cascadeOnDelete`). `AlertRuleFactory::locations(Location ...$locations): static` — a factory state that attaches the given locations to the created rule via the pivot, in an `afterCreating()` hook (mirrors how `products()`/`notificationChannels()` are attached explicitly in existing tests, not via the factory's base `definition()`).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\AlertRule;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

test('AlertRule::locations() is a real many-to-many relation', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();

    $rule->locations()->attach([$locationA->id, $locationB->id]);

    expect($rule->fresh()->locations->pluck('id')->sort()->values()->all())
        ->toBe(collect([$locationA->id, $locationB->id])->sort()->values()->all());
});

test('deleting a location detaches it from every alert rule, not just one', function () {
    $location = Location::factory()->create();
    $ruleA = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $ruleB = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'risk_area', 'location_id' => null]);
    $ruleA->locations()->attach($location);
    $ruleB->locations()->attach($location);

    $location->delete();

    expect($ruleA->fresh()->locations)->toHaveCount(0)
        ->and($ruleB->fresh()->locations)->toHaveCount(0);
});

test('the backfill migration copies every existing Location/Polygon/RiskArea rule\'s location_id into the new pivot', function () {
    $location = Location::factory()->create();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id,
    ]);

    // The migration already ran as part of this test's own schema setup
    // (RefreshDatabase/migrate runs once for the whole suite) -- this rule
    // was created AFTER that, so it proves the backfill's WHERE clause, not
    // just that the pivot table exists. Simulate a pre-existing row by
    // deleting any pivot attachment the app code itself might have made
    // (none yet, since Task 5 hasn't wired the form) and re-running the
    // backfill SQL directly, matching exactly what the migration's up()
    // does.
    DB::table('alert_rule_location')->where('alert_rule_id', $rule->id)->delete();

    DB::statement("
        INSERT INTO alert_rule_location (alert_rule_id, location_id)
        SELECT id, location_id FROM alert_rules
        WHERE category IN ('weather_alerts', 'spc_wpc')
        AND matching_mode IN ('location', 'polygon', 'risk_area')
        AND location_id IS NOT NULL
        ON CONFLICT DO NOTHING
    ");

    expect($rule->fresh()->locations->pluck('id')->all())->toBe([$location->id]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleLocationPivotTest.php"
```
Expected: FAIL — `locations()` doesn't exist on `AlertRule`, and the `alert_rule_location` table doesn't exist yet.

- [ ] **Step 3: Create the migration**

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
        Schema::create('alert_rule_location', function (Blueprint $table) {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'location_id']);
        });

        // Backfill: every existing Location/Polygon/RiskArea-mode Weather
        // Alerts/SPC & WPC rule must keep matching after this ships, or its
        // subscriber silently stops receiving alerts the moment the
        // matching engine switches from reading location_id to reading
        // this pivot (see the design spec's "Decisions" section).
        DB::statement("
            INSERT INTO alert_rule_location (alert_rule_id, location_id)
            SELECT id, location_id FROM alert_rules
            WHERE category IN ('weather_alerts', 'spc_wpc')
            AND matching_mode IN ('location', 'polygon', 'risk_area')
            AND location_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rule_location');
    }
};
```

- [ ] **Step 4: Add the relation to `AlertRule`**

In `app/Models/AlertRule.php`, add the `BelongsToMany` import is already present (used by `products()`/`notificationChannels()`). Add, directly below the existing `location()` method:

```php
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class);
    }
```

- [ ] **Step 5: Add the factory helper**

In `database/factories/AlertRuleFactory.php`, add (after the existing `dailySummary()` state):

```php
    /**
     * Attaches the given locations via the alert_rule_location pivot after
     * the rule is created -- mirrors how tests already attach products()/
     * notificationChannels() explicitly, not via definition().
     */
    public function locations(Location ...$locations): static
    {
        return $this->afterCreating(function (AlertRule $rule) use ($locations): void {
            $rule->locations()->attach(collect($locations)->pluck('id'));
        });
    }
```

Add `use App\Models\Location;` to the top of the file if not already present (it is — used by the base `definition()`'s `location_id`).

- [ ] **Step 6: Run the migration and the test**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && php artisan migrate --force"
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleLocationPivotTest.php"
```
Expected: PASS (all 3 tests).

- [ ] **Step 7: Pint and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations/2026_10_01_300001_create_alert_rule_location_table.php app/Models/AlertRule.php database/factories/AlertRuleFactory.php tests/Feature/AlertRuleLocationPivotTest.php
git commit -m "Add the alert_rule_location pivot, with a backfill from location_id

Threshold/DailySummary/Lightning keep using location_id/location()
unchanged -- this pivot and the new locations() relation are additive,
laying the groundwork for Weather Alerts/SPC & WPC rules to attach
more than one location."
```

---
### Task 2: Thread the matched location into delivery and subject rendering

**Files:**
- Modify: `app/Actions/Weather/RenderAlertSubject.php`
- Modify: `app/Jobs/DeliverWeatherAlert.php`
- Test: `tests/Feature/RenderAlertSubjectTest.php` (append)
- Test: `tests/Feature/DeliverWeatherAlertTest.php` (append)

**Interfaces:**
- Consumes: `AlertRule::locations()` from Task 1 (used only by this task's own new `DeliverWeatherAlertTest.php` test — neither `RenderAlertSubject` nor `DeliverWeatherAlert` touch the matching engine). Deliberately done before Task 3's matching-engine refactor: both new parameters are optional and backward-compatible, so this task has no real dependency on `GeographyMatch` existing yet, while Task 3's `dispatchOrSuppress()` rewrite calls both of this task's new signatures directly and would fail with an `ArgumentCountError` if attempted first.
- Produces: `RenderAlertSubject::__invoke(AlertRule $rule, ?Product $product = null, ?Location $location = null): string` (used by Task 3's `dispatchOrSuppress()`). `DeliverWeatherAlert::__construct(int $ruleId, int $channelId, int $ingestedProductId, ?int $locationId = null)` (also used by Task 3's `dispatchOrSuppress()`).

This task's governing rule: `.ai/rules/jobs.md`'s `Str::limit(..., preserveWords: true)` note is unaffected (nothing here touches body truncation) — included for completeness since this file matches that rule's path glob.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/RenderAlertSubjectTest.php`:
```php
test('an explicit $location parameter wins over the rule\'s own location()', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $ruleLocation = Location::factory()->create(['label' => 'Rule default']);
    $passedLocation = Location::factory()->create(['label' => 'Matched this time']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'location',
        'location_id' => $ruleLocation->id,
        'custom_subject_template' => 'Near {location}',
    ]);

    $result = (new RenderAlertSubject)($rule, null, $passedLocation);

    expect($result)->toBe('Near Matched this time');
});

test('omitting $location falls back to the rule\'s own location(), unchanged from before this parameter existed', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $location = Location::factory()->create(['label' => 'Home']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'threshold',
        'location_id' => $location->id,
        'custom_subject_template' => 'Near {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Near Home');
});
```

Append to `tests/Feature/DeliverWeatherAlertTest.php`:
```php
test('the delivered subject reflects the location passed via the job, not an arbitrary location on the rule', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $matchedLocation = Location::factory()->create(['label' => 'Matched one']);
    $otherLocation = Location::factory()->create(['label' => 'Not this one']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null,
        'custom_subject_template' => 'Near {location}',
    ]);
    $rule->locations()->attach([$matchedLocation->id, $otherLocation->id]);
    $channel = NotificationChannel::factory()->for($plusUser)->create(['apprise_url' => 'discord://token/id']);
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id, $matchedLocation->id))
        ->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::sole()->subject)->toBe('Near Matched one');
});

test('omitting the job\'s locationId renders the subject exactly as it did before this parameter existed', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::sole()->subject)->toBe($rule->displayName());
});
```
Add `use App\Models\Location;` to `DeliverWeatherAlertTest.php`'s top-level `use` statements — confirmed not already present (its existing tests never reference `Location` directly).

- [ ] **Step 2: Run to verify they fail**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/RenderAlertSubjectTest.php tests/Feature/DeliverWeatherAlertTest.php"
```
Expected: FAIL — `RenderAlertSubject::__invoke()` doesn't accept a 3rd argument yet; `DeliverWeatherAlert`'s constructor doesn't accept a 4th.

- [ ] **Step 3: Update `RenderAlertSubject`**

```php
<?php

namespace App\Actions\Weather;

use App\Models\AlertRule;
use App\Models\Location;
use App\Models\Product;

/**
 * Renders a per-rule custom notification subject/title via plain
 * placeholder substitution -- logic-less strtr(), nothing executable,
 * since a subscriber-authored template runs on every dispatch of their
 * alerts. Priority: an attached AlertTemplate's subject_template first
 * (via effectiveAlertTemplate(), tier- and category-gated), then the
 * rule's own custom_subject_template (via
 * effectiveCustomSubjectTemplate()), then AlertRule::displayName() (the
 * existing admin/list-table label, unchanged and untouched by this class)
 * when neither is set.
 */
class RenderAlertSubject
{
    /**
     * $location is the specific location that matched THIS delivery for a
     * multi-location Weather Alerts/SPC & WPC rule (see
     * MatchWeatherAlertRules::matchesAnyLocation()) -- when omitted, falls
     * back to $rule->location, exactly this method's behavior before this
     * parameter existed, which is what every non-Weather-Alerts/SPC-WPC
     * caller (DeliverScheduledAlert) still relies on.
     */
    public function __invoke(AlertRule $rule, ?Product $product = null, ?Location $location = null): string
    {
        $templateSubject = $rule->effectiveAlertTemplate()?->subject_template;
        $subjectTemplate = filled($templateSubject) ? $templateSubject : $rule->effectiveCustomSubjectTemplate();

        if (blank($subjectTemplate)) {
            return $rule->displayName();
        }

        $location ??= $rule->location;

        return strtr($subjectTemplate, [
            '{product}' => $product?->name ?? $rule->category->label(),
            '{location}' => $location?->label ?? ($rule->wfo !== null ? "WFO {$rule->wfo}" : 'your area'),
            '{category}' => $rule->category->label(),
        ]);
    }
}
```

- [ ] **Step 4: Update `DeliverWeatherAlert`**

In `app/Jobs/DeliverWeatherAlert.php`, add `use App\Models\Location;` to the imports. Replace the constructor:
```php
    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly int $ingestedProductId,
        public readonly ?int $locationId = null,
    ) {}
```

Inside `handle()`, after `$product = IngestedProduct::find($this->ingestedProductId);` and its null-check (so `$location` is resolved once, after the earlier null-guards, before either `RenderAlertSubject` call site uses it), add:
```php
        $location = $this->locationId !== null ? Location::find($this->locationId) : null;
```

Then update both existing `RenderAlertSubject` calls to pass it as the 3rd argument:
```php
            $this->logSkipped($rule, $channel, 'The rule was deactivated after this delivery was dispatched.', $product, app(RenderAlertSubject::class)($rule, $product->product, $location));
```
and
```php
        $subject = app(RenderAlertSubject::class)($rule, $product->product, $location);
```
(Everything else in `handle()` — body composition, `$dispatcher->send(...)`, the `DeliveryLog::create(...)` call — is unchanged.)

- [ ] **Step 5: Run the tests**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/RenderAlertSubjectTest.php tests/Feature/DeliverWeatherAlertTest.php tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/DeliverScheduledAlertTest.php"
```
Expected: all PASS. `DeliverScheduledAlertTest.php` is included specifically to confirm `RenderAlertSubject`'s new optional 3rd parameter doesn't disturb `DeliverScheduledAlert`'s existing 2-argument call (`app(RenderAlertSubject::class)($rule)`), which must keep behaving exactly as before.

- [ ] **Step 6: Pint and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Weather/RenderAlertSubject.php app/Jobs/DeliverWeatherAlert.php tests/Feature/RenderAlertSubjectTest.php tests/Feature/DeliverWeatherAlertTest.php
git commit -m "Thread the matched location through delivery to subject rendering

DeliverWeatherAlert carries an optional locationId from
dispatchOrSuppress()'s GeographyMatch; RenderAlertSubject's {location}
placeholder now reflects whichever location actually matched this
specific delivery for a multi-location rule, falling back to the
rule's own location() exactly as before when none is passed."
```

---
### Task 3: Matching engine — GeographyMatch + multi-location loop

**Files:**
- Create: `app/Support/Geography/GeographyMatch.php`
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php` (`matchesGeography`, `matchesGeographySafely`, `matchesLocationZones`, `matchesPolygon`, `matchesRiskArea`, `isWithinQuietHours`, `dispatchOrSuppress`, `__invoke`)
- Modify: `tests/Feature/MatchWeatherAlertRulesTest.php` (16 existing tests, listed in Step 1 below)

**Interfaces:**
- Consumes: `AlertRule::locations()` from Task 1; `RenderAlertSubject`'s new optional 3rd parameter and `DeliverWeatherAlert`'s new optional 4th constructor parameter from Task 2 — this task's `dispatchOrSuppress()` rewrite (Step 4 below) calls both with the matched location, so Task 2 must already be done.
- Produces: `GeographyMatch::matched(?Location $location = null): self`, `GeographyMatch::notMatched(): self`, public readonly `bool $matched` / `?Location $location`. `MatchWeatherAlertRules::matchesGeography(AlertRule $rule, IngestedProduct $product): GeographyMatch` (was `bool`) — Task 4 reads this return type.

This task's governing rules: `.ai/rules/pipeline.md` ("A Forecast Discussion rule means the AFD product specifically" — the `ForecastDiscussion` branch's WFO-only check must stay exactly as-is, just wrapped in `GeographyMatch`) and `.ai/rules/models.md` (`ActiveWwa::forProduct()` usage inside `matchesActiveWwaPolygon()` must not be re-derived — leave that method untouched).

- [ ] **Step 1: Update the 16 existing tests to attach locations via the pivot instead of `location_id`**

Open `tests/Feature/MatchWeatherAlertRulesTest.php`. For each of the following tests, remove `'location_id' => $location->id` (or `$badLocation->id`) from the `AlertRule::factory()->create([...])` call, and add `$rule->locations()->attach($location->id);` (or the equivalent variable name used in that test) immediately after the rule is created — in the same spot these tests already attach products/channels. Do not change any other line; the assertions stay identical, since this is a fixture-shape change only, not a behavior change.

Exact list (test name, line number, location variable name to attach):
1. `'a matched rule within its quiet hours window is suppressed and logged, not dispatched'` (218) — `$location`
2. `'a Location-mode rule matches when the location\'s resolved zone overlaps the product\'s ugc_codes'` (284) — `$location`
3. `'a Location-mode rule with no zone overlap never matches'` (298) — `$location`
4. `'a Polygon-mode rule matches when the location falls inside the product\'s parsed polygon'` (344) — `$location`
5. `'a Polygon-mode rule does not match a location outside the product\'s parsed polygon'` (364) — `$location`
6. `'a Polygon-mode rule falls back to Location-style zone matching when the product has no polygon'` (384) — `$location`
7. `'a RiskArea-mode rule matches a location inside a HIGHER risk level than the one selected'` (398) — `$location`
8. `'a RiskArea-mode rule does not match a location inside a LOWER risk level than the one selected'` (423) — `$location`
9. `'a RiskArea-mode rule with no risk_levels selected matches inside any risk level\'s area'` (446) — `$location`
10. `'a RiskArea-mode rule falls back to plain polygon matching when the product has no risk_level_polygons'` (469) — `$location`
11. `'a Location-mode rule against a fixless mobile location never matches'` (493) — `$location`
12. `'a Polygon-mode rule against a fixless mobile location falls back to Location matching and still never matches'` (507) — `$location`
13. `'an exception evaluating one rule\'s geography does not prevent a second, valid rule from matching'` (524) — `$badLocation`, attached to `$throwingRule`
14. `'a Polygon-mode rule matches against active_wwas\'s persisted polygon when the triggering row has none of its own'` (555) — `$location`
15. `'a Polygon-mode rule does not match a location outside active_wwas\'s persisted polygon'` (586) — `$location`
16. `'when active_wwas has no persisted polygon for this VTEC key, matching falls back to the triggering row\'s own polygon field'` (614) — `$location`

Example for test #2 (284), before:
```php
test('a Location-mode rule matches when the location\'s resolved zone overlaps the product\'s ugc_codes', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
```
after:
```php
test('a Location-mode rule matches when the location\'s resolved zone overlaps the product\'s ugc_codes', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location']);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
```
Apply the same transformation (drop `'location_id' => $x->id` from the factory array, add `$rule->locations()->attach($x);` right after the `AlertRule::factory()->create(...)` line) to all 16 tests listed above.

- [ ] **Step 2: Run the suite to confirm these 16 now fail against the still-unmodified matching engine**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php"
```
Expected: the 16 updated tests FAIL (matching engine still reads `$rule->location`, which is now null for all of them) while the untouched tests (Nationwide, Wfo, CountyZone, ForecastDiscussion, risk-level-filter-only ones) still PASS. This confirms the fixture change alone — not a coincidence — is what's driving the failure, before any production code changes.

- [ ] **Step 3: Create `GeographyMatch`**

```php
<?php

namespace App\Support\Geography;

use App\Models\Location;

final class GeographyMatch
{
    private function __construct(
        public readonly bool $matched,
        public readonly ?Location $location = null,
    ) {}

    public static function matched(?Location $location = null): self
    {
        return new self(true, $location);
    }

    public static function notMatched(): self
    {
        return new self(false, null);
    }
}
```

- [ ] **Step 4: Refactor `MatchWeatherAlertRules`**

Add to the `use` block at the top of `app/Actions/Weather/MatchWeatherAlertRules.php`:
```php
use App\Support\Geography\GeographyMatch;
```

Replace the `__invoke()` method's rule-processing closure (currently):
```php
        $rules->each(function (AlertRule $rule) use ($product): void {
            if ($this->matchesGeographySafely($rule, $product) && $this->matchesRiskLevel($rule, $product)) {
                $this->dispatchOrSuppress($rule, $product);
            }
        });
```
with:
```php
        $rules->each(function (AlertRule $rule) use ($product): void {
            $match = $this->matchesGeographySafely($rule, $product);

            if ($match->matched && $this->matchesRiskLevel($rule, $product)) {
                $this->dispatchOrSuppress($rule, $product, $match->location);
            }
        });
```

Replace `matchesGeographySafely()`'s signature and body:
```php
    private function matchesGeographySafely(AlertRule $rule, IngestedProduct $product): GeographyMatch
    {
        try {
            return DB::transaction(fn (): GeographyMatch => $this->matchesGeography($rule, $product));
        } catch (Throwable $e) {
            logger()->error('Failed to evaluate geography match for AlertRule', [
                'rule_id' => $rule->id,
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);

            return GeographyMatch::notMatched();
        }
    }
```
(Only the return type and the two return statements change — the docblock above it and the try/DB::transaction/catch shape stay exactly as-is.)

Replace `dispatchOrSuppress()`'s signature and the two places it references a location:
```php
    private function dispatchOrSuppress(AlertRule $rule, IngestedProduct $product, ?Location $matchedLocation): void
    {
        $suppressed = $this->isWithinQuietHours($rule, $matchedLocation);

        $rule->notificationChannels->each(function (NotificationChannel $channel) use ($rule, $product, $suppressed, $matchedLocation): void {
            try {
                if (DeliveryLog::where('alert_rule_id', $rule->id)
                    ->where('notification_channel_id', $channel->id)
                    ->where('ingested_product_id', $product->id)
                    ->exists()) {
                    return;
                }

                if ($suppressed) {
                    DeliveryLog::create([
                        'alert_rule_id' => $rule->id,
                        'notification_channel_id' => $channel->id,
                        'user_id' => $rule->user_id,
                        'channel_type' => $channel->channel_type,
                        'status' => 'suppressed',
                        'subject' => app(RenderAlertSubject::class)($rule, $product->product, $matchedLocation),
                        'ingested_product_id' => $product->id,
                        'dispatched_at' => now(),
                    ]);

                    return;
                }

                DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id, $matchedLocation?->id);
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
```
(This keeps the existing dedup-guard comment above the `DeliveryLog::where(...)->exists()` check — only the method signature, the `RenderAlertSubject` call's 3rd arg, and the `DeliverWeatherAlert::dispatch` call's 4th arg are new.)

Replace `matchesGeography()`:
```php
    private function matchesGeography(AlertRule $rule, IngestedProduct $product): GeographyMatch
    {
        if ($rule->category === AlertCategory::ForecastDiscussion) {
            return $this->matchesWfo($rule->wfo, $product->cccc) ? GeographyMatch::matched() : GeographyMatch::notMatched();
        }

        return match ($rule->matching_mode) {
            MatchingMode::Nationwide => GeographyMatch::matched(),
            MatchingMode::Wfo => $this->matchesWfo($rule->wfo, $product->cccc) ? GeographyMatch::matched() : GeographyMatch::notMatched(),
            MatchingMode::CountyZone => $this->matchesCountyZone($rule, $product) ? GeographyMatch::matched() : GeographyMatch::notMatched(),
            MatchingMode::Location => $this->matchesAnyLocation($rule, fn (Location $location): bool => $this->matchesLocationZones($location, $product)),
            MatchingMode::Polygon => $this->matchesAnyLocation($rule, fn (Location $location): bool => $this->matchesPolygon($location, $product) ?? $this->matchesLocationZones($location, $product)),
            MatchingMode::RiskArea => $this->matchesAnyLocation($rule, fn (Location $location): bool => $this->matchesRiskArea($rule, $location, $product) ?? $this->matchesLocationZones($location, $product)),
            null => GeographyMatch::notMatched(),
        };
    }

    /**
     * "Any attached location matches -> the rule fires": the first location
     * (in attachment order) whose check succeeds wins and is the one
     * downstream code (quiet hours, {location} subject rendering) treats as
     * "the" match for this delivery. A rule with zero attached locations
     * never matches in these three modes -- there is nothing to check.
     */
    private function matchesAnyLocation(AlertRule $rule, Closure $matchesLocation): GeographyMatch
    {
        foreach ($rule->locations as $location) {
            if ($matchesLocation($location)) {
                return GeographyMatch::matched($location);
            }
        }

        return GeographyMatch::notMatched();
    }
```
Add `use Closure;` to the top of the file (not currently imported).

Replace `matchesPolygon()`'s signature (body unchanged except `$rule->location` → `$location`):
```php
    private function matchesPolygon(Location $location, IngestedProduct $product): ?bool
    {
        if (! $location->hasFix()) {
            return null;
        }

        $lng = (float) $location->lng;
        $lat = (float) $location->lat;

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
```
Update its docblock's opening line to read "Takes the specific `Location` being checked (one of the rule's possibly several attached locations), not the rule itself" in place of the current wording — keep the rest of the docblock (the "returns null, not false" explanation) unchanged.

Replace `matchesRiskArea()`'s signature (body unchanged except `$rule->location` → `$location` and its own `matchesPolygon` call updated):
```php
    private function matchesRiskArea(AlertRule $rule, Location $location, IngestedProduct $product): ?bool
    {
        if ($product->risk_level_polygons === null || $product->risk_level_polygons === []) {
            return $this->matchesPolygon($location, $product);
        }

        if (! $location->hasFix()) {
            return null;
        }

        $order = $product->product?->risk_level_options ?? [];
        $selectedRanks = collect($rule->risk_levels ?? [])
            ->map(fn (string $level): int|false => array_search($level, $order, true))
            ->filter(fn (int|false $rank): bool => $rank !== false);
        $thresholdRank = $selectedRanks->isEmpty() ? 0 : $selectedRanks->min();

        $lng = (float) $location->lng;
        $lat = (float) $location->lat;

        foreach ($product->risk_level_polygons as $level => $points) {
            $rank = array_search($level, $order, true);

            if ($rank === false || $rank < $thresholdRank || count($points) < 3) {
                continue;
            }

            $result = DB::selectOne(
                'SELECT ST_Contains(ST_SetSRID(ST_GeomFromText(?), 4269), ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains',
                [$this->polygonToWkt($points), $lng, $lat],
            );

            if ($this->resultIsTrue($result)) {
                return true;
            }
        }

        return false;
    }
```
Keep `$rule` as the first parameter (still needed for `$rule->risk_levels`); add `$location` as the second. Keep the existing docblock, adding one clause noting it now takes the specific location being checked rather than reading `$rule->location`.

Replace `matchesLocationZones()`'s signature (drops the `$rule` parameter entirely — it was only ever used to reach `$rule->location`):
```php
    private function matchesLocationZones(Location $location, IngestedProduct $product): bool
    {
        if (! $location->hasFix() || $product->ugc_codes === null) {
            return false;
        }

        $location = $this->ensureZonesResolved($location);
        $zones = array_filter([$location->forecast_zone_ugc, $location->county_ugc]);

        return array_intersect($zones, $product->ugc_codes) !== [];
    }
```

Replace `isWithinQuietHours()` entirely (drops the matching-mode allow-list, which both is now redundant and was silently excluding `RiskArea` — see the design spec's "Incidental bug fix" note):
```php
    /**
     * Nationwide/Wfo/CountyZone/ForecastDiscussion rules, and a
     * Location/Polygon/RiskArea rule with zero attached locations or none
     * that matched, pass $matchedLocation = null -- "quiet hours" implicitly
     * means "my nighttime", which has no well-defined answer without a
     * specific location. These always deliver regardless of any
     * quiet_hours_start/end value on the row.
     *
     * Uses the location that actually matched THIS delivery, not an
     * arbitrary or aggregated location across every location the rule has
     * attached -- see the design spec for why that's the only well-defined
     * choice once a rule can have more than one.
     */
    private function isWithinQuietHours(AlertRule $rule, ?Location $matchedLocation): bool
    {
        if ($rule->quiet_hours_start === null || $rule->quiet_hours_end === null) {
            return false;
        }

        $timezone = $matchedLocation?->timezone;
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
```
(The final `return` and everything below the `$timezone` null-check is unchanged from today — only the signature, the dropped `in_array` block, and the docblock are new.)

- [ ] **Step 5: Run the full matching test file**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php"
```
Expected: all tests PASS, including the 16 updated in Step 1.

- [ ] **Step 6: Pint and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Support/Geography/GeographyMatch.php app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/MatchWeatherAlertRulesTest.php
git commit -m "Match geography against every location a rule has attached

matchesGeography() now returns a GeographyMatch (matched + which
location) instead of a bare bool, and Location/Polygon/RiskArea modes
loop the rule's attached locations instead of reading a single one.
Incidental fix: quiet hours used to silently never apply to
RiskArea-mode rules (missing from an allow-list that's now gone
entirely, subsumed by whether a location actually matched)."
```

---
### Task 4: New multi-location matching coverage

**Files:**
- Test: `tests/Feature/MatchWeatherAlertRulesTest.php` (append new tests)

**Interfaces:**
- Consumes: `AlertRule::locations()`/`AlertRuleFactory::locations()` from Task 1, `GeographyMatch`-driven matching from Task 3.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/MatchWeatherAlertRulesTest.php`:

```php
test('a Location-mode rule with two attached locations fires when only the second one overlaps the product\'s zone', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $noOverlap = Location::factory()->create(['forecast_zone_ugc' => 'NYZ999']);
    $overlaps = Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location']);
    $rule->locations()->attach([$noOverlap->id, $overlaps->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Location-mode rule with zero attached locations never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location']);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('quiet hours are evaluated against the location that actually matched, not an unmatched sibling location in a different timezone', function () {
    // $awake is in a timezone where it's NOT currently quiet hours;
    // $asleep is in a timezone where it IS -- but $awake is the one
    // that overlaps the product's zone, so delivery must go through.
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $asleep = Location::factory()->create(['timezone' => 'America/New_York', 'forecast_zone_ugc' => 'NYZ999']);
    $awake = Location::factory()->create(['timezone' => 'Pacific/Kiritimati', 'forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location',
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $rule->locations()->attach([$asleep->id, $awake->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a RiskArea-mode rule\'s quiet hours now suppress correctly -- previously silently never applied', function () {
    // Regression for the incidental bug fixed in this change: RiskArea was
    // missing from isWithinQuietHours()'s old matching-mode allow-list, so
    // a RiskArea rule's quiet hours never suppressed anything, even with a
    // real location and a real timezone.
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $location = Location::factory()->create(['timezone' => 'America/New_York', 'lat' => 34.5, 'lng' => -95.5]);
    $rule = AlertRule::factory()->create([
        'category' => 'spc_wpc', 'matching_mode' => 'risk_area', 'risk_levels' => ['Slight'],
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $rule->locations()->attach($location);
    $rule->products()->attach($outlook);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $rule->notificationChannels()->attach($channel);
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $outlook->id,
        'risk_level_polygons' => [
            'Slight' => [['lat' => 30, 'lon' => -100], ['lat' => 30, 'lon' => -90], ['lat' => 40, 'lon' => -90], ['lat' => 40, 'lon' => -100]],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::sole()->status)->toBe('suppressed');
});
```

- [ ] **Step 2: Run to verify they pass against Task 3's implementation**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php"
```
Expected: PASS. (These exercise behavior Task 3 already implements — this task is pure coverage, not new production code. If the RiskArea quiet-hours test fails, Task 3's `isWithinQuietHours()` replacement was applied incorrectly — go back and re-check that step before continuing.)

- [ ] **Step 3: Pint and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add tests/Feature/MatchWeatherAlertRulesTest.php
git commit -m "Add coverage for multi-location matching and the RiskArea quiet-hours fix"
```

---
### Task 5: Multi-select form field, page wiring, browser verification

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` (`locationSelect()` → `locationsSelect()`, its 3 call sites in `matchingModeFields()`)
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php` (`afterCreate()`)
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Pages/EditAlertRule.php` (`mutateFormDataBeforeFill()`, `afterSave()`)
- Modify: `tests/Feature/AlertRuleResourceTest.php` (2 existing tests updated, new tests added)

**Interfaces:**
- Consumes: `AlertRule::locations()` from Task 1 (this task doesn't touch matching directly, only the form that feeds it).

This task's governing rule: `.ai/rules/filament.md`'s dynamic-Wizard-step entangle bug note and its `countyZoneField()` precedent (`->multiple()` is safe here specifically because `options()` is a plain closure, not `->relationship()`) — re-read that note before starting this task if picking it up fresh.

- [ ] **Step 1: Update the 2 existing form tests that use `location_id` for Weather Alerts/SPC & WPC**

In `tests/Feature/AlertRuleResourceTest.php`, find `'creating a weather alerts rule persists the chosen products and matching mode'` (around line 93). Change:
```php
            'matching_mode' => 'location',
            'location_id' => $this->location->id,
```
to:
```php
            'matching_mode' => 'location',
            'location_ids' => [$this->location->id],
```
and change the assertion block from:
```php
    expect($rule->matching_mode->value)->toBe('location')
        ->and($rule->products->pluck('id')->all())->toBe([$tornadoWarning->id])
        ->and($rule->displayName())->toBe('Tornado Warning');
```
to:
```php
    expect($rule->matching_mode->value)->toBe('location')
        ->and($rule->locations->pluck('id')->all())->toBe([$this->location->id])
        ->and($rule->products->pluck('id')->all())->toBe([$tornadoWarning->id])
        ->and($rule->displayName())->toBe('Tornado Warning');
```

Find `'an spc wpc rule for the Convective Outlook can use RiskArea matching mode against a saved location'` (around line 205). Change:
```php
            'matching_mode' => 'risk_area',
            'location_id' => $location->id,
```
to:
```php
            'matching_mode' => 'risk_area',
            'location_ids' => [$location->id],
```
and change:
```php
    expect($rule->matching_mode)->toBe(MatchingMode::RiskArea)
        ->and($rule->location_id)->toBe($location->id);
```
to:
```php
    expect($rule->matching_mode)->toBe(MatchingMode::RiskArea)
        ->and($rule->locations->pluck('id')->all())->toBe([$location->id]);
```

No other test in this file touches `location_id` for `weather_alerts`/`spc_wpc` — every other `location_id` usage in this file is `threshold`/`lightning`/`daily_summary`, which this plan does not change.

- [ ] **Step 2: Write the new multi-location form tests**

Append to `tests/Feature/AlertRuleResourceTest.php`:
```php
test('creating a weather alerts rule with two locations persists both via the pivot', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $secondLocation = Location::factory()->for($this->user)->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'location',
            'location_ids' => [$this->location->id, $secondLocation->id],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->locations->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->location->id, $secondLocation->id])->sort()->values()->all());
});

test('editing an existing multi-location rule pre-fills every attached location, and saving without changes keeps them all', function () {
    $secondLocation = Location::factory()->for($this->user)->create();
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $rule->locations()->attach([$this->location->id, $secondLocation->id]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['location_ids' => [$this->location->id, $secondLocation->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->locations->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->location->id, $secondLocation->id])->sort()->values()->all());
});

test('editing a multi-location rule down to one location detaches the rest', function () {
    $secondLocation = Location::factory()->for($this->user)->create();
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $rule->locations()->attach([$this->location->id, $secondLocation->id]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        // ->set('data.X', value), not ->fillForm([...]) -- this file's own
        // EditAlertRule tests consistently change one field this way (see
        // 'alert_template_id gets cleared' further down), not fillForm(),
        // which this file reserves for CreateAlertRule's initial fill.
        ->set('data.location_ids', [$this->location->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->locations->pluck('id')->all())->toBe([$this->location->id]);
});

test('a subscriber cannot attach another subscriber\'s location via location_ids', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $othersLocation = Location::factory()->for(User::factory())->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'location',
            'location_ids' => [$othersLocation->id],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['location_ids']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});
```

- [ ] **Step 3: Run to verify everything fails for the right reason**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php"
```
Expected: FAIL — `location_ids` isn't a field on the form yet (the 2 updated tests and the 4 new ones all fail; everything else in the file still passes).

- [ ] **Step 4: Rename and convert `locationSelect()` to a multi-select**

In `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`, replace:
```php
    protected static function locationSelect(): Select
    {
        return Select::make('location_id')
            ->label('Location')
            ->options(fn (): array => auth()->user()->locations()->pluck('label', 'id')->all())
            ->required()
            ->exists('locations', 'id', fn (Exists $rule): Exists => $rule->where('user_id', auth()->id()))
            ->createOptionForm(LocationForm::fields())
            ->createOptionAction(fn (Action $action): Action => $action
                ->visible(fn (): bool => auth()->user()->canCreateLocation())
                ->modalHeading('Add a location'))
            ->createOptionUsing(fn (array $data): int => auth()->user()->locations()->create($data)->getKey())
            ->helperText(fn (): ?string => match (true) {
                auth()->user()->locations()->exists() => null,
                auth()->user()->canCreateLocation() => 'No saved locations yet — use the + button to add one.',
                default => 'You\'ve reached your location limit — remove one or upgrade to add another.',
            });
    }
```
with:
```php
    /**
     * Weather Alerts/SPC & WPC only -- Threshold/DailySummary/Lightning
     * keep the separate singular location_id field (daily summary/
     * threshold/lightning fields aren't built through this method).
     * ->multiple() is safe in this dynamic Step::schema(fn (Get $get) =>
     * ...) context because options() below is a plain closure, not
     * ->relationship() -- see countyZoneField()'s docblock in this same
     * file, proven safe here in a real browser already for exactly this
     * reason. ->exists() applied directly to this multiple() field's array
     * value works with no extra wiring: Laravel's own validateExists()
     * (Illuminate\Validation\Concerns\ValidatesAttributes) counts unique
     * values in the submitted array and requires that many matches from
     * the scoped query, so a single id belonging to another subscriber
     * fails the whole field -- verified by reading that method directly,
     * not assumed, and covered by "a subscriber cannot attach another
     * subscriber's location via location_ids" below.
     */
    protected static function locationsSelect(): Select
    {
        return Select::make('location_ids')
            ->label('Locations')
            ->multiple()
            ->options(fn (): array => auth()->user()->locations()->pluck('label', 'id')->all())
            ->required()
            ->exists('locations', 'id', fn (Exists $rule): Exists => $rule->where('user_id', auth()->id()))
            ->createOptionForm(LocationForm::fields())
            ->createOptionAction(fn (Action $action): Action => $action
                ->visible(fn (): bool => auth()->user()->canCreateLocation())
                ->modalHeading('Add a location'))
            ->createOptionUsing(fn (array $data): int => auth()->user()->locations()->create($data)->getKey())
            ->helperText(fn (): ?string => match (true) {
                auth()->user()->locations()->exists() => null,
                auth()->user()->canCreateLocation() => 'No saved locations yet — use the + button to add one.',
                default => 'You\'ve reached your location limit — remove one or upgrade to add another.',
            });
    }
```

In `matchingModeFields()`, find:
```php
            self::locationSelect()
                ->visible(fn (Get $get): bool => in_array($get('matching_mode'), [MatchingMode::Location->value, MatchingMode::Polygon->value, MatchingMode::RiskArea->value], true)),
```
and change the method call to:
```php
            self::locationsSelect()
                ->visible(fn (Get $get): bool => in_array($get('matching_mode'), [MatchingMode::Location->value, MatchingMode::Polygon->value, MatchingMode::RiskArea->value], true)),
```
(the `->visible()` condition itself is unchanged — same three modes).

Do not touch `wfoField()`, `countyZoneStateField()`, or `countyZoneField()` — they're unrelated to this field.

- [ ] **Step 5: Wire `CreateAlertRule`/`EditAlertRule`**

In `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php`, in `afterCreate()`, add after the existing `notificationChannels()->sync(...)` line:
```php
        $this->record->locations()->sync($this->data['location_ids'] ?? []);
```

In `app/Filament/Dashboard/Resources/AlertRules/Pages/EditAlertRule.php`:
- In `mutateFormDataBeforeFill()`, add after the existing `$data['channel_ids'] = ...` line:
```php
        $data['location_ids'] = $this->record->locations->pluck('id')->all();
```
- In `afterSave()`, add after the existing `notificationChannels()->sync(...)` line:
```php
        $this->record->locations()->sync($this->data['location_ids'] ?? []);
```

- [ ] **Step 6: Run the test file**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php"
```
Expected: all PASS.

- [ ] **Step 7: Browser-verify the form change — required, not optional**

Pest's `fillForm()` sets Livewire state directly and cannot detect the entangle-desync failure mode `.ai/rules/filament.md` documents; `countyZoneField()`'s own precedent for `->multiple()` in this exact context was only trusted after a real browser check. Do the same here before considering this task done:
1. Start the dev stack's preview (or reuse the running `wxnotify-nextgen-front-end-1` container's exposed port) and log in as a subscriber.
2. Open Create Alert → Weather Alerts → pick a product → set Matching mode to "By my location" → confirm the Locations field renders as a searchable multi-select (not a single-select) and lets you pick 2+ saved locations.
3. Finish creating the rule; reload the Edit page for that same rule and confirm both locations show as selected (not just one, not entangled/desynced).
4. Remove one location on the Edit page and save; reload again and confirm only the remaining one is selected.
5. Repeat steps 2–3 once for SPC & WPC / RiskArea mode, since it shares `locationsSelect()` but is a separate category branch in `detailsStep()`'s dynamic schema.

Record what you saw (screenshot or explicit written confirmation) — do not mark this step done on the strength of the Pest run alone.

- [ ] **Step 8: Full suite, Pint, commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
docker exec wxnotify-nextgen-front-end-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"
```
Expected: Pint passes with no changes needed beyond what's already clean; full suite passes with 0 failures.

```bash
git add app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php app/Filament/Dashboard/Resources/AlertRules/Pages/EditAlertRule.php tests/Feature/AlertRuleResourceTest.php
git commit -m "Let a subscriber attach multiple locations to a Weather Alerts/SPC & WPC rule

locationSelect() becomes locationsSelect(), a multiple() Select over
location_ids -- following the same pattern this codebase's own
countyZoneField() already proved safe in this exact dynamic Wizard
step. CreateAlertRule/EditAlertRule sync/hydrate it the same way they
already do for product_ids/channel_ids. Browser-verified per
.ai/rules/filament.md's own caution that Pest's fillForm() can't
detect an entangle desync."
```

---

## Self-review notes (completed during planning, not a step to re-run)

- **Spec coverage**: every section of the design spec maps to a task — storage/backfill (Task 1), subject/delivery threading (Task 2), GeographyMatch + matching loop + quiet hours (Task 3), the incidental RiskArea fix (Task 3, covered by Task 4), the form (Task 5), and the full enumerated existing-test list (Task 3, Step 1).
- **Type consistency checked**: `GeographyMatch::matched()`/`notMatched()` (Task 3) used identically in Task 3's own `matchesGeography()`/`matchesAnyLocation()`; `?Location $location` parameter name and position consistent between `RenderAlertSubject` (Task 2) and its callers in `MatchWeatherAlertRules` (Task 3) and `DeliverWeatherAlert` (Task 2); `location_ids` field name consistent between the form (Task 5), `CreateAlertRule`/`EditAlertRule` (Task 5), and every test that fills or asserts it (Task 5).
- **Ordering dependency caught during subagent-driven-development's pre-flight scan** (not in the original self-review): Task 3's `dispatchOrSuppress()` calls `RenderAlertSubject`'s 3-arg form and `DeliverWeatherAlert::dispatch()`'s 4-arg form, which don't exist until the task that was originally numbered 4. Since both new parameters are optional/backward-compatible, that task has no real dependency on Task 3 and was moved ahead of it (now Task 2) rather than restructured — tasks were reordered and renumbered, no task content changed.
- **No placeholders**: every step with a code change shows the exact before/after or complete new file; the one place this plan intentionally defers a decision (Step 7 of Task 5, the browser check) is a verification step, not implementation, and says exactly what to look for.
