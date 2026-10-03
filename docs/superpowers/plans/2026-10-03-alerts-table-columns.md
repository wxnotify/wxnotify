# Alerts Table: Locations and Channels Columns Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Locations and Channels columns to the subscriber's "My Alerts" table so each alert shows where it applies and where it is delivered.

**Architecture:** Two new `TextColumn`s in `AlertRulesTable`; a small `MatchingMode::shortLabel()` for alerts not tied to a saved location; the table query eager-loads the relations so the columns (and the existing "Alert" column) add no per-row queries.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5 tables, Pest.

**Spec:** `docs/superpowers/specs/2026-10-03-alerts-table-columns-design.md` (binding; read it first).

## Global Constraints

- Columns sit **between Category and Active**, both `toggleable()` and visible by default; Created stays toggleable and hidden by default. No change to the form, filters, actions or other columns.
- **Locations column** content, by rule:
  - Daily Summary / Threshold / Lightning → one badge: `location->label` (single `location_id`).
  - Weather Alerts / SPC & WPC with `matching_mode` `Location`, `Polygon` or `RiskArea` (or no mode) → one badge per `locations` label (relationship order).
  - Weather Alerts / SPC & WPC with mode `Nationwide`, `Wfo` or `CountyZone` → one **gray** badge with the mode's `shortLabel()`: **Nationwide**, **Issuing office**, **County/zone**.
  - Anything that should have locations but has none → one **gray** badge **None**.
- **Channels column**: one badge per attached notification channel (`label`); none attached → gray **None** placeholder.
- Both columns: first 3 badges shown, expandable (`limitList(3)` + `expandableLimitedList()`). Location/channel names are colored badges; the three mode labels and "None" are gray (decided by a closure on the text, an accepted cosmetic edge case if a subscriber names a location "Nationwide").
- **Eager loading**: `location`, `locations`, `notificationChannels`, `products` on the table query; the resource's `getEloquentQuery()` scoping (`whereBelongsTo(auth()->user())`) is untouched.
- Not included: searching/sorting/filtering by these columns.
- Conventions: Pest feature tests; Pint (`vendor/bin/pint --dirty --format agent`) before the commit; commit directly to `main` (no push); commit message ends with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Read `.ai/rules/index.md` and the rule files covering touched paths (`filament.md` for `app/Filament/**`, `models.md` for enums under `app/Enums` if it applies, `feature.md` for tests) before editing.

## How to run tests (read this once)

Run Pest inside the app container (the host cannot reach the DB):

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

The full suite takes ~6 minutes and is run by the controller in the background at the end. Pint runs on the host.

## File Structure

| Path | Responsibility |
| --- | --- |
| `app/Enums/MatchingMode.php` | new `shortLabel()` |
| `app/Filament/Dashboard/Resources/AlertRules/Tables/AlertRulesTable.php` | the two columns + eager loading |
| `tests/Feature/AlertRulesTableColumnsTest.php` (new) | tests for the columns, isolation, query count |

---

### Task 1: The Locations and Channels columns

**Files:**
- Modify: `app/Enums/MatchingMode.php`, `app/Filament/Dashboard/Resources/AlertRules/Tables/AlertRulesTable.php`
- Test: `tests/Feature/AlertRulesTableColumnsTest.php`

**Interfaces:**
- Produces: `MatchingMode::shortLabel(): string` (total over all six cases); table columns named `location_labels` (state: `list<string>`) and `notificationChannels.label`.

Read `.ai/rules/filament.md` before editing the table.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AlertRulesTableColumnsTest.php`:

```php
<?php

use App\Enums\MatchingMode;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * A location-mode Weather Alerts rule listing the given locations and channels.
 *
 * @param  array<int, Location>  $locations
 * @param  array<int, NotificationChannel>  $channels
 */
function weatherRule(User $user, array $locations = [], array $channels = [], string $mode = 'location'): AlertRule
{
    $rule = AlertRule::factory()->for($user)->create([
        'category' => 'weather_alerts', 'matching_mode' => $mode, 'location_id' => null,
    ]);
    $rule->locations()->attach(collect($locations)->pluck('id'));
    $rule->notificationChannels()->attach(collect($channels)->pluck('id'));

    return $rule;
}

test('every matching mode has a short label', function () {
    foreach (MatchingMode::cases() as $mode) {
        expect($mode->shortLabel())->toBeString()->not->toBeEmpty();
    }

    expect(MatchingMode::Nationwide->shortLabel())->toBe('Nationwide')
        ->and(MatchingMode::Wfo->shortLabel())->toBe('Issuing office')
        ->and(MatchingMode::CountyZone->shortLabel())->toBe('County/zone');
});

test('a location-mode weather alert lists its locations in order', function () {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $cabin = Location::factory()->for($this->user)->create(['label' => 'Cabin']);
    $rule = weatherRule($this->user, [$home, $cabin]);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Home', 'Cabin'], $rule);
});

test('polygon and risk-area alerts list their saved locations too', function (string $mode) {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $rule = weatherRule($this->user, [$home], mode: $mode);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Home'], $rule);
})->with(['polygon', 'risk_area']);

test('scheduled alerts show their single location', function () {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $daily = AlertRule::factory()->dailySummary()->for($this->user)->for($home)->create();
    $lightning = AlertRule::factory()->lightning()->for($this->user)->for($home)->create();
    $threshold = AlertRule::factory()->for($this->user)->for($home)->create();

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['Home'], $daily)
        ->assertTableColumnStateSet('location_labels', ['Home'], $lightning)
        ->assertTableColumnStateSet('location_labels', ['Home'], $threshold);
});

test('alerts not tied to a saved location show their matching mode', function (string $mode, string $label) {
    $rule = weatherRule($this->user, mode: $mode);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', [$label], $rule);
})->with([
    'nationwide' => ['nationwide', 'Nationwide'],
    'wfo' => ['wfo', 'Issuing office'],
    'county/zone' => ['county_zone', 'County/zone'],
]);

test('an alert that should have locations but has none shows None', function () {
    $listless = weatherRule($this->user, []);
    $orphaned = AlertRule::factory()->dailySummary()->for($this->user)->create(['location_id' => null]);

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('location_labels', ['None'], $listless)
        ->assertTableColumnStateSet('location_labels', ['None'], $orphaned);
});

test('the channels column lists each attached channel, and shows None when there are none', function () {
    $discord = NotificationChannel::factory()->for($this->user)->create(['label' => 'My Discord']);
    $email = NotificationChannel::factory()->for($this->user)->create(['label' => 'Work email']);
    $with = weatherRule($this->user, [], [$discord, $email], mode: 'nationwide');
    $without = weatherRule($this->user, [], [], mode: 'nationwide');

    livewire(ListAlertRules::class)
        ->assertTableColumnStateSet('notificationChannels.label', ['My Discord', 'Work email'], $with)
        ->assertSee('None'); // the placeholder rendered for the alert with no channels
    expect($without->notificationChannels)->toBeEmpty();
});

test('the new columns can be toggled and sit between Category and Active', function () {
    $component = livewire(ListAlertRules::class)
        ->assertTableColumnExists('location_labels')
        ->assertTableColumnExists('notificationChannels.label');

    $names = collect($component->instance()->getTable()->getColumns())->keys()->all();

    expect(array_search('category', $names, true))->toBeLessThan(array_search('location_labels', $names, true))
        ->and(array_search('location_labels', $names, true))->toBeLessThan(array_search('notificationChannels.label', $names, true))
        ->and(array_search('notificationChannels.label', $names, true))->toBeLessThan(array_search('is_active', $names, true));
});

test('another subscriber\'s alerts, locations and channels never appear', function () {
    $stranger = User::factory()->create();
    $theirLocation = Location::factory()->for($stranger)->create(['label' => 'Their Place']);
    $theirChannel = NotificationChannel::factory()->for($stranger)->create(['label' => 'Their Channel']);
    $theirs = weatherRule($stranger, [$theirLocation], [$theirChannel]);
    $mine = weatherRule($this->user, [Location::factory()->for($this->user)->create(['label' => 'Home'])], [], mode: 'location');

    livewire(ListAlertRules::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertDontSee('Their Place')
        ->assertDontSee('Their Channel');
});

test('the number of queries does not grow with the number of alerts', function () {
    $countQueries = function (int $alerts): int {
        AlertRule::query()->whereBelongsTo($this->user)->delete();

        foreach (range(1, $alerts) as $i) {
            $locations = Location::factory()->for($this->user)->count(2)->create()->all();
            $channels = NotificationChannel::factory()->for($this->user)->count(2)->create()->all();
            weatherRule($this->user, $locations, $channels);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        livewire(ListAlertRules::class)->assertSuccessful();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $one = $countQueries(1);
    $six = $countQueries(6);

    expect($six)->toBe($one);
});
```

(Adapt the Filament testing calls to the installed v5 API if a name differs — `assertTableColumnStateSet`, `assertTableColumnExists`, `getTable()->getColumns()` — keeping every assertion's intent. If `assertTableColumnStateSet` returns a Collection for the relationship column, compare with `->all()` / render the HTML via `assertSeeHtml`/`assertSee` instead. The "None" placeholder for channels may only be observable in rendered HTML: assert on that if the state is empty. The query-count test must keep its equality unless a fixed, explainable constant overhead (e.g. one extra count query on the first page) forces `toBe($one)` to be loosened — if so say exactly why in the report. Other `AlertRule` tests create `NotificationChannel`/`Location` rows the same way, e.g. `tests/Feature/AlertRuleResourceTest.php`.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/AlertRulesTableColumnsTest.php`
Expected: FAIL — `shortLabel()` / the columns do not exist.

- [ ] **Step 3: `MatchingMode::shortLabel()`**

In `app/Enums/MatchingMode.php`, add after `label()`:

```php
    /**
     * A few words for a table cell; label() is a sentence for the form.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Nationwide => 'Nationwide',
            self::Location => 'By location',
            self::Wfo => 'Issuing office',
            self::CountyZone => 'County/zone',
            self::Polygon => 'By polygon',
            self::RiskArea => 'By risk area',
        };
    }
```

- [ ] **Step 4: The columns**

In `AlertRulesTable`, add imports (`use App\Enums\MatchingMode; use Illuminate\Database\Eloquent\Builder;`) and, between the Category column and `ToggleColumn::make('is_active')`:

```php
                TextColumn::make('location_labels')
                    ->label('Locations')
                    ->getStateUsing(fn (AlertRule $record): array => self::locationLabels($record))
                    ->badge()
                    ->color(fn (string $state): string => in_array($state, self::mutedLabels(), true) ? 'gray' : 'primary')
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->toggleable(),
                TextColumn::make('notificationChannels.label')
                    ->label('Channels')
                    ->badge()
                    ->placeholder('None')
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->toggleable(),
```

Add `->modifyQueryUsing(fn (Builder $query) => $query->with(['location', 'locations', 'notificationChannels', 'products']))` to the table chain (before `->columns(...)`), and these private helpers to the class:

```php
    private const NONE = 'None';

    /**
     * Where an alert applies: its saved locations, or -- for matching modes
     * that are not tied to a saved location -- the mode's short name.
     *
     * @return list<string>
     */
    private static function locationLabels(AlertRule $record): array
    {
        if (in_array($record->category, [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc], true)) {
            $mode = $record->matching_mode;

            if (in_array($mode, [MatchingMode::Nationwide, MatchingMode::Wfo, MatchingMode::CountyZone], true)) {
                return [$mode->shortLabel()];
            }

            $labels = $record->locations->pluck('label')->all();
        } else {
            $labels = array_filter([$record->location?->label]);
        }

        return $labels === [] ? [self::NONE] : array_values($labels);
    }

    /**
     * @return list<string>
     */
    private static function mutedLabels(): array
    {
        return [
            self::NONE,
            MatchingMode::Nationwide->shortLabel(),
            MatchingMode::Wfo->shortLabel(),
            MatchingMode::CountyZone->shortLabel(),
        ];
    }
```

- [ ] **Step 5: Run to verify it passes, plus the existing alert list tests**

Run: `tests/Feature/AlertRulesTableColumnsTest.php tests/Feature/AlertRuleResourceTest.php tests/Feature/AlertRuleDisplayNameUnitsTest.php`
Expected: all PASS.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/MatchingMode.php app/Filament/Dashboard/Resources/AlertRules/Tables/AlertRulesTable.php tests/Feature/AlertRulesTableColumnsTest.php
git commit -m "Add Locations and Channels columns to the Alerts table

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Post-implementation verification (controller, in a real browser)

1. Create a throwaway subscriber with: a location-mode weather alert on two locations and two channels; a nationwide weather alert; a WFO alert; a Daily Summary on one location; a location-mode alert with 4 locations (expands past 3); an alert with no channels; another subscriber's alert (must not appear). Log in (Livewire login component; retry once if the first attempt doesn't redirect), open `/dashboard/alerts` at 1280x900.
2. Confirm: the two columns between Category and Active; colored badges for names, gray for "Nationwide"/"Issuing office"/"None"; "+N more"/expand works on the 4-location alert; the column toggle (columns icon) hides/shows them; the Active toggle and Edit/Delete row actions still work; no other subscriber's data.
3. Delete the throwaway data afterwards and reset the viewport.

---

## Self-Review (against the spec)

**Coverage:** placement and toggleable (T1 step 4 + ordering test); Locations by category/mode incl. polygon/risk-area and scheduled single location (tests); gray mode labels and None (tests); Channels badges and None placeholder (test); first-3-expandable (step 4, browser check); eager-loading and no per-row queries (query-count test); isolation (test); `shortLabel()` total (test); non-goals untouched.

**Placeholder scan:** none. One note tells the implementer to adapt Filament test-API names to the installed version while keeping each assertion's intent; one note covers the "None" channels placeholder possibly only being observable in rendered HTML.

**Type consistency:** `location_labels` state is `list<string>` in the closure, the color closure and the tests; `MatchingMode::shortLabel()` is used identically by `locationLabels()`, `mutedLabels()` and the tests.
