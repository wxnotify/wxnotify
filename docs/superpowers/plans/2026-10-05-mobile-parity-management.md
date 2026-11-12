# Mobile Parity Management (alert rules and locations in the app) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A subscriber can create, edit, switch on or off and delete alert rules, and add, edit and delete locations, from the mobile app, with the same fields, limits and stored results as the dashboard.

**Architecture:** A new `SaveAlertRule` action holds the rule persistence logic the dashboard's Create/Edit pages carry today; the Filament pages and the new `/api/v1` rule endpoints both call it. The API adds rule, location, options, lookup and place-search endpoints in the existing app-token group. The app adds Rules and Locations tabs, list screens and draft-based editors; a pure `RuleFormPresenter` holds the category/matching-mode logic on the phone and the server re-validates everything.

**Tech Stack:** Laravel app (PHP 8.4), Pest, Filament (dashboard), Sanctum (app tokens), PostgreSQL; NativePHP Mobile (EDGE native components, `nativephp/mobile-ui`, `nativephp/mobile-geolocation`) in `mobile/`.

**Spec:** `docs/superpowers/specs/2026-10-05-mobile-parity-management-design.md` (read it first; the plan argues from it).

## Global Constraints

- Backend tests run in the container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest <files>'` (start containers with `docker compose up -d front-end pgsql redis` if stopped). Mobile tests run on the host: `cd mobile && vendor/bin/pest --compact`. `cd mobile && php artisan native:validate` must stay clean. Run `vendor/bin/pint --dirty --format agent` (in the container for backend files, on the host for `mobile/`) before every commit.
- Never run `native:run` or `native:jump`; the owner builds. Do not push; commits go on `main`.
- Every commit message ends with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Read `.ai/rules/index.md` and the rule files that match the paths you touch before editing (`api.md` for `routes/api.php` and `app/Http/Controllers/Api/**`, `actions.md` for `app/Actions/**`, `filament.md` for `app/Filament/**`, `models.md`, `feature.md` for `tests/Feature/**`).
- API: every route sits in the existing `auth:sanctum`, `ability:app`, `ExtendAppTokenExpiry`, `throttle:app-api` group in `routes/api.php`; every query is scoped to `$request->user()` and another user's id is a 404; responses use the `data` wrapper; no response carries a channel destination or URL, a Traccar credential or a token; activity writes pin the causer with `app(CauserResolver::class)->withCauser($user, fn () => ...)` (the API has no session user).
- Stored values are imperial; the API speaks the subscriber's own unit system (`App\Support\Units`, `App\Support\AlertRuleFormUnits`).
- A refused write changes nothing (one DB transaction per save). Maintenance mode answers 503 with the dashboard's message as a **plain-text** body (`MaintenanceModeException::render`); the app treats a 503 on a write as maintenance.
- Tests: use factories, `Http::fake()` for outbound HTTP, `$this->freezeTime()`/`travelTo(now()->startOfSecond())` where time matters; never name a test property `$this->app`; in an API test that makes several calls as different tokens call `app('auth')->forgetGuards()` between them (Sanctum caches the user within one test).
- App (`mobile/`): native components from EDGE elements only (no web view); Pest with the fakes in `mobile/tests/Fakes`; every native capability the app calls is listed in `NativePluginsTest`; signing out wipes everything cached.

## Plan decisions beyond the spec (rulings made while planning; the reviewer should hold the plan to them)

1. **`GET /places/reverse?lat=&lng=` is added.** The dashboard fills a stationary location's address from the browser map; the app has no map, so "Use my current position" needs the server to turn the fix into an address before saving (the spec says the editor shows the resolved address). It reuses `ReverseGeocodeCoordinates`. `POST /locations` takes the `address` the app got from search or reverse lookup (max 255 characters); the server resolves only the timezone when none is sent.
2. **The options payload lists all the user's channels with a `verified` flag** (the dashboard lists unverified ones too, labelled "(unverified)"). Showing verified-only would hide a channel an existing rule already uses and trap the edit. The app marks unverified ones.
3. **Retired or draft products an existing rule still uses** pass validation (family only, like the dashboard, which does not validate products at all) and the app keeps those ids in the draft even though it only lists active products.
4. **`SaveAlertRule` clears columns that do not belong to the saved category** (and category-irrelevant product/location pivots). The dashboard today leaves stale values behind after a category change on Edit; both front ends now behave the same. Pivots are otherwise synced as given, which keeps the dashboard's existing behaviour.
5. The API names the lightning radius field `radius` (a whole number in the user's distance unit, with a `unit` in the resource; the stored `radius_miles` column is an integer, so the request refuses a fraction and the app sends an integer); `SaveAlertRuleRequest::ruleData()` maps it to the `radius_miles` key the action and the form use.
6. `GET /locations` carries `meta: {can_create, limit}` so the app can explain the tier limit before the "+" fails.
7. The `native:select` and `native:radio-group` elements take **labels only** (no ids), so the app keeps an id-by-label map for id-valued pickers and disambiguates duplicate labels; see Task 10.

## File Structure

Backend (create unless noted):
- `app/Actions/Alerts/SaveAlertRule.php`, `SetAlertRuleActive.php`, `DeleteAlertRule.php`, `BuildAlertRuleOptions.php`
- `app/Actions/Locations/CreateStationaryLocation.php`, `UpdateSavedLocation.php`, `DeleteSavedLocation.php`, `SearchPlaces.php`
- `app/Actions/Weather/SearchUgcCodes.php`
- `app/Exceptions/LocationInUse.php`, `LocationIsAPhone.php`
- `app/Rules/KnownUgcCode.php`
- `app/Http/Controllers/Api/V1/{AlertRuleController,AlertRuleOptionsController,NwsOfficeController,ZoneController,SavedLocationController,PlaceController}.php`
- `app/Http/Requests/{SaveAlertRuleRequest,SetAlertRuleActiveRequest,SearchZonesRequest,StoreSavedLocationRequest,UpdateSavedLocationRequest,SearchPlacesRequest,ReversePlaceRequest}.php`
- `app/Http/Resources/Api/V1/{AlertRuleResource,SavedLocationResource}.php`
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Pages/{CreateAlertRule,EditAlertRule}.php`, `app/Actions/Locations/ReverseGeocodeCoordinates.php` (one constant made public), `app/Providers/AppServiceProvider.php` (limiter `app-places`), `routes/api.php`, `.ai/rules/api.md`, `.ai/rules/actions.md`
- Tests (`tests/Feature/`): `SaveAlertRuleTest`, `ApiAlertRuleOptionsTest`, `ApiLookupsTest`, `ApiAlertRulesTest`, `AlertRuleParityTest`, `ApiSavedLocationsTest`, `ApiPlacesTest`

App (`mobile/`), create unless noted:
- `app/Exceptions/Api/{ValidationFailed,Conflict,Maintenance}.php`
- Modify: `app/Services/WxNotifyApi.php`, `app/Support/LocalCache.php`, `routes/mobile.php`, the two existing tab screens (Alerts, Account) and their views (shared tab bar)
- `app/Support/RuleFormPresenter.php`
- `app/NativeComponents/{RulesScreen,RuleEditor,LocationsScreen,LocationEditor}.php`
- `resources/views/native/{rules-screen,rule-editor,locations-screen,location-editor}.blade.php`, `resources/views/native/partials/tabs.blade.php`
- Modify: `tests/Fakes/RecordingGeolocation.php` (one-shot read and permission recording)
- Tests (`mobile/tests/Feature/`): `WxNotifyApiManagementTest`, `LocalCacheManagementTest`, `RulesScreenTest`, `RuleFormPresenterTest`, `RuleEditorTest`, `LocationsScreenTest`, `LocationEditorTest`; modify `NativePluginsTest`, `ScreensRenderTest`, `AccountScreenTest`, `AlertsScreenTest`

---

## Probe (run first)

### Task 0: Form-element probe (runs first; commits nothing)

**Why first:** the spec requires confirming that the EDGE element vocabulary has the inputs the editors need (switch, text, number, time, multi-select, search field) before anything is built on it. The answer decides how Tasks 8, 10, 11 and 12 write their views.

**Files (all throwaway, deleted in Step 3):** `app/NativeComponents/FormProbe.php`, `resources/views/native/form-probe.blade.php`, `resources/views/native/form-probe-part.blade.php`, `tests/Feature/FormProbeTest.php` (under `mobile/`).

**Interfaces:** produces a short findings report, no code: the element type names, the handler-argument convention (**Convention C**, below) and any rulings.

- [ ] **Step 1: Write the probe**

The spec requires confirming the EDGE vocabulary has the inputs the editors need before building on it. Create the probe component, view and test below, run them, and record the findings in your report (they decide how Tasks 10-11 write their views).

`app/NativeComponents/FormProbe.php`:

```php
<?php

namespace App\NativeComponents;

use Native\Mobile\Edge\NativeComponent;

/**
 * Throwaway: proves which form elements and event shapes the editors can rely on.
 */
class FormProbe extends NativeComponent
{
    /** @var array<string, mixed> */
    public array $draft = ['name' => 'Home', 'value' => '32', 'ids' => [2], 'time' => '06:30', 'units' => 'Imperial'];

    public ?string $last = null;

    public function flip(...$args): void
    {
        $this->last = 'flip:'.json_encode($args);
    }

    public function mark(...$args): void
    {
        $this->last = 'mark:'.json_encode($args);
    }

    public function pick(...$args): void
    {
        $this->last = 'pick:'.json_encode($args);
    }

    public function clock(...$args): void
    {
        $this->last = 'clock:'.json_encode($args);
    }
}
```

`resources/views/native/form-probe.blade.php`:

```blade
<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-3">
        <native:text class="text-base">{{ $last }}</native:text>
        <native:outlined-text-input label="Name" native:model="draft.name" ref="probe-name" />
        <native:outlined-text-input label="Value" native:model="draft.value" keyboard="decimal" ref="probe-value" />
        <native:toggle :value="true" label="Active" @change="flip(5)" ref="probe-toggle" />
        <native:checkbox :value="in_array(2, $draft['ids'])" label="Tornado Warning" @change="mark(2)" ref="probe-check" />
        <native:select label="Units" :options="['Imperial', 'Metric']" :value="$draft['units']" @change="pick('units')" ref="probe-select" />
        <native:pressable @tap="pick('p')" ref="probe-press"><native:text>Press</native:text></native:pressable>
        <native:date-picker mode="time" label="Time" :value="$draft['time']" @change="clock" ref="probe-time" />
        <native:accordion :expanded="false" ref="probe-accordion">
            <native:accordion-header><native:text>Section</native:text></native:accordion-header>
            <native:accordion-content><native:text>Inside</native:text></native:accordion-content>
        </native:accordion>
        <native:radio-group :value="$draft['units']" @change="pick" ref="probe-radio"></native:radio-group>
        <native:chip label="Chip" ref="probe-chip" />
        @include('native.form-probe-part', ['label' => 'Included'])
        @switch($draft['units'])
            @case('Metric')
                <native:text>Switch: metric</native:text>
                @break
            @default
                <native:text>Switch: imperial</native:text>
        @endswitch
    </native:column>
</native:scroll-view>
```

`resources/views/native/form-probe-part.blade.php`:

```blade
<native:text>{{ $label }}</native:text>
```

`tests/Feature/FormProbeTest.php`:

```php
<?php

use App\NativeComponents\FormProbe;
use Native\Mobile\Testing\Native;

function probeTypes(array $node): array
{
    return [$node['type'] ?? '?', ...collect($node['children'] ?? [])->flatMap(fn (array $child) => probeTypes($child))->all()];
}

test('probe: which element types the form views compile to', function () {
    $screen = Native::test(FormProbe::class);

    fwrite(STDERR, "\nPROBE types: ".json_encode(array_values(array_unique(probeTypes($screen->tree()))))."\n");

    $screen->input('probe-name', 'Cabin')->assertSet('draft.name', 'Cabin');
    $screen->input('probe-value', '40')->assertSet('draft.value', '40');
    $screen->toggle('probe-toggle', false);
    fwrite(STDERR, 'PROBE toggle -> '.$screen->get('last')."\n");
    $screen->check('probe-check', true);
    fwrite(STDERR, 'PROBE check -> '.$screen->get('last')."\n");
    $screen->select('probe-select', 'Metric');
    fwrite(STDERR, 'PROBE select (static argument) -> '.$screen->get('last')."\n");
    $screen->tap('probe-press');
    fwrite(STDERR, 'PROBE pressable -> '.$screen->get('last')."\n");
    $screen->pickTime('probe-time', '07:15');
    fwrite(STDERR, 'PROBE time -> '.$screen->get('last')."\n");
    $screen->assertSee('Included')->assertSee('Switch: metric');

    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run the probe and record what it shows**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/FormProbeTest.php`, and `cd mobile && php artisan native:validate`.
Record in the report: (a) the printed type list (confirm `outlined_text_input`, `toggle`, `checkbox`, `select`, `date_picker`, `accordion`, `radio_group`, `chip`, `pressable` or their actual names); (b) whether `input()` updates a dotted `native:model` path; (c) the argument shape each handler received: expected **Convention C: `@change="method(a)"` calls `method(a, $value)` for toggle/checkbox, and `@change="method"` calls `method($value)` for select/date-picker**, where `$value` is the bool, the selected label string, or the `H:i` time string; also that a select with a static argument (`@change="pick('units')"`) calls `pick('units', $value)` and a pressable (`@tap="pick('p')"`) calls `pick('p')`; (d) whether `native:validate` accepts every tag used, including the `@include` partial and the `@switch` block (the render assertions `assertSee('Included')` and `assertSee('Switch: metric')` prove both compile). The date-picker line uses the harness's `pickTime()` macro from mobile-ui (`DatePickerMacros`), which sends the `H:i` value as a select-change event; if it does not reach `clock`, read `vendor/nativephp/mobile-ui/src/Testing/DatePickerMacros.php` and report what it sends.

Rulings for a missing or different element (decide, do not stop; later tasks assume the first option of each):
- `accordion` missing or invalid → collapsible sections built from a `native:pressable` header toggling a local `expanded` list in the component, content rendered with `@if`.
- `date-picker mode="time"` missing → an `outlined-text-input` holding `HH:MM` (`keyboard="number"`) validated by the presenter.
- `checkbox` missing → `native:toggle`.
- Handler arguments differ from Convention C → use the convention the probe showed in every later task and keep the tests' intent (the tests drive refs, not argument shapes).

- [ ] **Step 3: Delete the probe**

```bash
rm mobile/app/NativeComponents/FormProbe.php mobile/resources/views/native/form-probe.blade.php mobile/resources/views/native/form-probe-part.blade.php mobile/tests/Feature/FormProbeTest.php
```

Nothing from the probe is committed. Put its findings (types, argument convention, rulings) at the top of your task report; the controller carries them into the Task 8, 10, 11 and 12 dispatches.


---

## Backend

### Task 1: The shared `SaveAlertRule` action, and the dashboard pages delegate to it

**Files:**
- Create: `app/Actions/Alerts/SaveAlertRule.php`
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/EditAlertRule.php`
- Test: `tests/Feature/SaveAlertRuleTest.php`

**Interfaces:**
- Consumes: `App\Support\AlertRuleFormUnits::toStorage(array, Units, ?AlertRule)`, `App\Support\SpcWpcRiskLevels::flatten()`, `App\Support\Units::for(User)`.
- Produces (later tasks rely on these exact signatures):
  - `SaveAlertRule::__invoke(User $user, array $data, ?AlertRule $rule = null): AlertRule` creates (null rule) or replaces the rule's settings, syncs relations, logs with `$user` as causer, runs in one transaction, returns the refreshed rule.
  - `SaveAlertRule::attributes(User $user, array $data, ?AlertRule $stored = null): array` the column array to persist (adds `user_id` when `$stored` is null).
  - `SaveAlertRule::syncRelations(AlertRule $rule, array $data): void`.
  - Input `$data` uses the dashboard form's keys: `category`, `name`, `product_ids` (a flat id list or the form's `{productCategory: [ids]}` nesting), `matching_mode`, `location_ids`, `location_id`, `wfo`, `county_zone_codes` (array or comma string), `risk_levels` (flat list or the form's `{productCategory: [levels]}` nesting), `variable`, `direction`, `threshold_value` and `radius_miles` **in the user's units**, `timeframe`, `delivery_time`, `quiet_hours_start`, `quiet_hours_end`, `message_body_style`, `custom_subject_template`, `alert_template_id`, `channel_ids`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SaveAlertRuleTest.php`:

```php
<?php

use App\Actions\Alerts\SaveAlertRule;
use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create();
    $this->location = Location::factory()->for($this->user)->create();
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
    $this->tornado = Product::where('code', 'TO.W')->sole();
    $this->riskProduct = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();

    $this->save = fn (array $data, ?AlertRule $rule = null, ?User $user = null): AlertRule => app(SaveAlertRule::class)($user ?? $this->user, $data, $rule);

    $this->thresholdData = fn (array $overrides = []): array => [
        'category' => 'threshold',
        'variable' => 'temperature',
        'direction' => 'below',
        'threshold_value' => 32,
        'timeframe' => 'overnight',
        'location_id' => $this->location->id,
        'delivery_time' => '06:00',
        'channel_ids' => [$this->channel->id],
        ...$overrides,
    ];

    $this->weatherData = fn (array $overrides = []): array => [
        'category' => 'weather_alerts',
        'product_ids' => [$this->tornado->category->value => [$this->tornado->id]],
        'matching_mode' => 'nationwide',
        'channel_ids' => [$this->channel->id],
        ...$overrides,
    ];
});

test('it creates a threshold rule owned by the user with its channel and no products or locations', function () {
    $rule = ($this->save)(($this->thresholdData)());

    expect($rule->user_id)->toBe($this->user->id)
        ->and($rule->category)->toBe(AlertCategory::Threshold)
        ->and((float) $rule->threshold_value)->toBe(32.0)
        ->and($rule->location_id)->toBe($this->location->id)
        ->and($rule->is_active)->toBeTrue()
        ->and($rule->notificationChannels->pluck('id')->all())->toBe([$this->channel->id])
        ->and($rule->products)->toBeEmpty()
        ->and($rule->locations)->toBeEmpty();
});

test('a Metric subscriber\'s temperature and radius are converted to the stored imperial units', function () {
    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $location = Location::factory()->for($metric)->create();
    $channel = NotificationChannel::factory()->for($metric)->create();

    $threshold = ($this->save)(($this->thresholdData)(['threshold_value' => 0, 'location_id' => $location->id, 'channel_ids' => [$channel->id]]), null, $metric);
    $lightning = ($this->save)(['category' => 'lightning', 'radius_miles' => 24, 'location_id' => $location->id, 'channel_ids' => [$channel->id]], null, $metric);

    expect((float) $threshold->threshold_value)->toBe(32.0)
        ->and($lightning->radius_miles)->toBe(15);
});

test('saving an untouched Metric threshold keeps the stored number instead of drifting', function () {
    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $location = Location::factory()->for($metric)->create();
    $channel = NotificationChannel::factory()->for($metric)->create();
    $rule = AlertRule::factory()->for($metric)->create(['location_id' => $location->id, 'threshold_value' => 35]);

    // 35 degF is shown as 1.7 degC; saving that unchanged value must not turn it into 35.06 -> 35.1.
    $saved = ($this->save)(($this->thresholdData)(['threshold_value' => 1.7, 'location_id' => $location->id, 'channel_ids' => [$channel->id]]), $rule, $metric);

    expect((float) $saved->threshold_value)->toBe(35.0);
});

test('SPC and WPC risk levels are stored flat whether they arrive flat or per product category', function () {
    $levels = array_slice($this->riskProduct->risk_level_options, 0, 2);
    $base = [
        'category' => 'spc_wpc',
        'product_ids' => [$this->riskProduct->category->value => [$this->riskProduct->id]],
        'matching_mode' => 'risk_area',
        'location_ids' => [$this->location->id],
        'channel_ids' => [$this->channel->id],
    ];

    $nested = ($this->save)([...$base, 'risk_levels' => [$this->riskProduct->category->value => $levels]]);
    $flat = ($this->save)([...$base, 'risk_levels' => $levels]);

    expect($nested->risk_levels)->toBe($levels)
        ->and($flat->risk_levels)->toBe($levels)
        ->and($nested->products->pluck('id')->all())->toBe([$this->riskProduct->id])
        ->and($nested->locations->pluck('id')->all())->toBe([$this->location->id]);
});

test('the matching mode decides whether the office and the county/zone codes are kept', function () {
    $wfo = ($this->save)(($this->weatherData)(['matching_mode' => 'wfo', 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103']]));
    $codes = ($this->save)(($this->weatherData)(['matching_mode' => 'county_zone', 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103', 'NYZ072']]));
    $string = ($this->save)(($this->weatherData)(['matching_mode' => 'county_zone', 'county_zone_codes' => 'NYC103,NYZ072']));

    expect($wfo->wfo)->toBe('OKX')
        ->and($wfo->county_zone_codes)->toBeNull()
        ->and($codes->wfo)->toBeNull()
        ->and($codes->county_zone_codes)->toBe('NYC103,NYZ072')
        ->and($string->county_zone_codes)->toBe('NYC103,NYZ072')
        ->and($wfo->matching_mode)->toBe(MatchingMode::Wfo);
});

test('changing the category clears the columns and relations that no longer apply', function () {
    $weather = ($this->save)(($this->weatherData)(['matching_mode' => 'location', 'location_ids' => [$this->location->id]]));

    $daily = ($this->save)(['category' => 'daily_summary', 'location_id' => $this->location->id, 'delivery_time' => '07:00', 'channel_ids' => [$this->channel->id]], $weather);

    expect($daily->matching_mode)->toBeNull()
        ->and($daily->products)->toBeEmpty()
        ->and($daily->locations)->toBeEmpty()
        ->and($daily->location_id)->toBe($this->location->id);

    $threshold = ($this->save)(($this->thresholdData)(), $daily);
    $back = ($this->save)(($this->weatherData)(), $threshold);

    expect($back->variable)->toBeNull()
        ->and($back->direction)->toBeNull()
        ->and($back->threshold_value)->toBeNull()
        ->and($back->timeframe)->toBeNull()
        ->and($back->location_id)->toBeNull()
        ->and($back->delivery_time)->toBeNull()
        ->and($back->matching_mode)->toBe(MatchingMode::Nationwide);
});

test('a Free subscriber\'s message customisation is ignored', function () {
    $rule = ($this->save)(($this->weatherData)(['message_body_style' => 'brief', 'custom_subject_template' => '{product}']));

    expect($rule->message_body_style)->toBeNull()
        ->and($rule->custom_subject_template)->toBeNull();
});

test('a paid subscriber\'s message customisation is stored, defaulting the length to full, and a template only where the category supports one', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $template = AlertTemplate::factory()->for($this->user)->create(['category' => AlertCategory::WeatherAlerts]);

    $default = ($this->save)(($this->weatherData)());
    $custom = ($this->save)(($this->weatherData)(['message_body_style' => 'brief', 'custom_subject_template' => '{product} at {location}', 'alert_template_id' => $template->id]));
    $threshold = ($this->save)(($this->thresholdData)(['alert_template_id' => $template->id]));

    expect($default->message_body_style)->toBe(MessageBodyStyle::Full)
        ->and($custom->message_body_style)->toBe(MessageBodyStyle::Brief)
        ->and($custom->custom_subject_template)->toBe('{product} at {location}')
        ->and($custom->alert_template_id)->toBe($template->id)
        ->and($threshold->alert_template_id)->toBeNull();
});

test('a blank name and blank quiet hours are stored as null', function () {
    $rule = ($this->save)(($this->thresholdData)(['name' => '', 'quiet_hours_start' => '', 'quiet_hours_end' => '']));

    expect($rule->name)->toBeNull()
        ->and($rule->quiet_hours_start)->toBeNull()
        ->and($rule->quiet_hours_end)->toBeNull();
});

test('the write is logged with the subscriber as the causer although there is no session', function () {
    ($this->save)(($this->thresholdData)());

    $activity = Activity::query()->where('subject_type', AlertRule::class)->latest('id')->firstOrFail();

    expect($activity->causer_id)->toBe($this->user->id);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/SaveAlertRuleTest.php'`
Expected: FAIL (`Class "App\Actions\Alerts\SaveAlertRule" not found`).

- [ ] **Step 3: Write the action**

Create `app/Actions/Alerts/SaveAlertRule.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\Tier;
use App\Models\AlertRule;
use App\Models\User;
use App\Support\AlertRuleFormUnits;
use App\Support\SpcWpcRiskLevels;
use App\Support\Units;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Persists an alert rule the way the dashboard's Create and Edit pages always have, for both the
 * dashboard and the mobile API: the user's units become the stored imperial values (without
 * drifting an untouched number), SPC & WPC risk levels are stored flat, columns that belong to
 * another category are cleared, and the product, location and channel pivots are synced.
 * Validation stays with the caller (the form's field rules, or SaveAlertRuleRequest).
 *
 * Input uses the dashboard form's keys, in the user's units (see the keys read below).
 */
class SaveAlertRule
{
    /** The category-specific columns each category owns; every other one is cleared on save. */
    private const CATEGORY_COLUMNS = [
        'weather_alerts' => ['matching_mode', 'wfo', 'county_zone_codes'],
        'spc_wpc' => ['matching_mode', 'risk_levels'],
        'threshold' => ['variable', 'direction', 'threshold_value', 'timeframe', 'location_id', 'delivery_time'],
        'daily_summary' => ['location_id', 'delivery_time'],
        'lightning' => ['location_id', 'radius_miles'],
    ];

    private const ALL_CATEGORY_COLUMNS = [
        'matching_mode', 'wfo', 'county_zone_codes', 'risk_levels', 'variable', 'direction',
        'threshold_value', 'timeframe', 'location_id', 'delivery_time', 'radius_miles',
    ];

    private const CATEGORIES_WITH_PRODUCTS = ['weather_alerts', 'spc_wpc'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $user, array $data, ?AlertRule $rule = null): AlertRule
    {
        return app(CauserResolver::class)->withCauser($user, fn (): AlertRule => DB::transaction(function () use ($user, $data, $rule): AlertRule {
            $attributes = $this->attributes($user, $data, $rule);

            if ($rule === null) {
                $rule = AlertRule::create($attributes);
            } else {
                $rule->update($attributes);
            }

            $this->syncRelations($rule, $data);

            return $rule->refresh();
        }));
    }

    /**
     * The columns to persist. Only keys the caller supplied are written, except that every
     * category-specific column the saved category does not own is set to null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function attributes(User $user, array $data, ?AlertRule $stored = null): array
    {
        $category = AlertCategory::from((string) ($data['category'] ?? $stored?->category?->value));
        $owned = self::CATEGORY_COLUMNS[$category->value];

        $attributes = Arr::only($data, ['name', 'quiet_hours_start', 'quiet_hours_end', ...self::ALL_CATEGORY_COLUMNS]);
        $attributes['category'] = $category->value;

        foreach (['name', 'quiet_hours_start', 'quiet_hours_end'] as $optional) {
            if (array_key_exists($optional, $attributes) && ! filled($attributes[$optional])) {
                $attributes[$optional] = null;
            }
        }

        foreach (self::ALL_CATEGORY_COLUMNS as $column) {
            if (! in_array($column, $owned, true)) {
                $attributes[$column] = null;
            }
        }

        $mode = $attributes['matching_mode'] ?? null;

        if (in_array('matching_mode', $owned, true)) {
            if ($mode !== MatchingMode::Wfo->value) {
                $attributes['wfo'] = null;
            }

            $codes = $mode === MatchingMode::CountyZone->value ? ($data['county_zone_codes'] ?? null) : null;
            $attributes['county_zone_codes'] = is_array($codes) ? (filled($codes) ? implode(',', $codes) : null) : (filled($codes) ? $codes : null);
        }

        if ($category === AlertCategory::SpcWpc) {
            $attributes['risk_levels'] = SpcWpcRiskLevels::flatten($data['risk_levels'] ?? []);
        }

        $attributes = [...$attributes, ...$this->messageCustomisation($user, $category, $data, $stored)];

        if ($stored === null) {
            $attributes['user_id'] = $user->id;
        }

        return AlertRuleFormUnits::toStorage($attributes, Units::for($user), $stored);
    }

    /**
     * Pivot syncs. Products and locations only apply to the product-based categories; the
     * channel list always applies.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncRelations(AlertRule $rule, array $data): void
    {
        $usesProducts = in_array($rule->category->value, self::CATEGORIES_WITH_PRODUCTS, true);

        $rule->products()->sync($usesProducts
            ? collect($data['product_ids'] ?? [])->flatten()->filter(fn ($id): bool => is_numeric($id))->values()->all()
            : []);
        $rule->locations()->sync($usesProducts ? ($data['location_ids'] ?? []) : []);
        $rule->notificationChannels()->sync($data['channel_ids'] ?? []);
    }

    /**
     * Message length, custom subject and template: paid tiers only (the dashboard hides them
     * from Free), and a template only for a category that supports one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function messageCustomisation(User $user, AlertCategory $category, array $data, ?AlertRule $stored): array
    {
        $attributes = [];

        if ($user->tier() !== Tier::Free) {
            if (array_key_exists('custom_subject_template', $data)) {
                $attributes['custom_subject_template'] = filled($data['custom_subject_template']) ? $data['custom_subject_template'] : null;
            }

            if (filled($data['message_body_style'] ?? null)) {
                $attributes['message_body_style'] = $data['message_body_style'];
            } elseif ($stored === null) {
                $attributes['message_body_style'] = MessageBodyStyle::Full->value;
            }

            if (array_key_exists('alert_template_id', $data)) {
                $attributes['alert_template_id'] = filled($data['alert_template_id']) ? $data['alert_template_id'] : null;
            }
        }

        if (! in_array($category->value, self::CATEGORIES_WITH_PRODUCTS, true)) {
            $attributes['alert_template_id'] = null;
        }

        return $attributes;
    }
}
```

- [ ] **Step 4: Run the tests and fix until they pass**

Run the same command as Step 2. Expected: PASS (10 tests).

- [ ] **Step 5: Delegate the Filament pages to the action**

In `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php`, replace `mutateFormDataBeforeCreate()` and `afterCreate()` and drop the imports that become unused (`AlertCategory`, `AlertRuleFormUnits`, `SpcWpcRiskLevels`, `Units`):

```php
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(SaveAlertRule::class)->attributes(auth()->user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        app(SaveAlertRule::class)->syncRelations($this->record, $this->data);
    }
```

(add `use App\Actions\Alerts\SaveAlertRule;`). In `EditAlertRule.php` keep `mutateFormDataBeforeFill()` unchanged, replace `mutateFormDataBeforeSave()` and `afterSave()`, and shorten the docblock to say the clearing of `alert_template_id` (and the other category-specific columns) now lives in `SaveAlertRule::attributes()`:

```php
    /**
     * Persistence rules (units, risk-level flattening, clearing columns and the template that
     * the saved category does not own) live in SaveAlertRule, which the mobile API shares. A
     * hidden field is dropped from the dehydrated data, so the action, not the form state, is
     * what keeps a stale value from surviving a category change.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return app(SaveAlertRule::class)->attributes(auth()->user(), $data, $this->record);
    }

    protected function afterSave(): void
    {
        app(SaveAlertRule::class)->syncRelations($this->record, $this->data);
    }
```

(add the `use` for `SaveAlertRule`; keep `AlertCategory`, `AlertRuleFormUnits`, `SpcWpcRiskLevels`, `Units` and `Product` in `EditAlertRule`, because `mutateFormDataBeforeFill()` still uses all five; remove only the imports that became unused in `CreateAlertRule`.)

- [ ] **Step 6: Run the dashboard's rule tests**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/AlertRuleResourceTest.php tests/Feature/AlertRuleFormUnitsTest.php tests/Feature/AlertRuleLocationPivotTest.php tests/Feature/AlertRuleDeliveryTimeResetTest.php tests/Feature/AlertRuleDisplayNameUnitsTest.php tests/Feature/AlertRulesTableColumnsTest.php tests/Feature/SaveAlertRuleTest.php'`
Expected: all PASS. If a dashboard test fails because it asserted a stale column surviving a category change, that is plan decision 4: read the test, confirm it encoded the old stale behaviour (not a user-visible requirement), update it to assert the cleared value, and say so in the report. Any other failure is a regression: fix the action, not the test.

- [ ] **Step 7: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --dirty --format agent'
git add app/Actions/Alerts/SaveAlertRule.php app/Filament/Dashboard/Resources/AlertRules/Pages tests/Feature/SaveAlertRuleTest.php
git commit -m "Alert rules: share the dashboard's save logic in a SaveAlertRule action

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Rule options and the office and zone lookups

**Files:**
- Create: `app/Actions/Alerts/BuildAlertRuleOptions.php`, `app/Actions/Weather/SearchUgcCodes.php`, `app/Rules/KnownUgcCode.php`, `app/Http/Controllers/Api/V1/{AlertRuleOptionsController,NwsOfficeController,ZoneController}.php`, `app/Http/Requests/SearchZonesRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/ApiAlertRuleOptionsTest.php`, `tests/Feature/ApiLookupsTest.php`

**Interfaces:**
- Produces: `GET /api/v1/alert-rule-options`, `GET /api/v1/nws-offices`, `GET /api/v1/zones`; `App\Rules\KnownUgcCode` (a `ValidationRule`: a county `SSCnnn` or zone `SSZnnn` code that exists); `SearchUgcCodes::__invoke(?string $state, ?string $query, int $limit, ?string $after): array` returning `list<array{code: string, label: string}>`.
- The options payload shape (the app and Task 3's request rely on it):

```
data: {
  categories: [{value, label, description}],
  products: {weather_alerts: [Group], spc_wpc: [Group]},   // Group = {category, label, products: [{id, name, description, risk_levels: [string]}]}
  matching_modes: [{value, label, description}],
  thresholds: {variables: [{value, label, unit, decimals}], directions: [{value, label}], timeframes: [{value, label}]},
  lightning: {unit, min, max, default},
  units: {system: 'imperial'|'metric'},
  tier: {can_customize_messages: bool},
  message_body_styles: [{value, label}],
  channels: [{id, label, type, type_label, verified}],
  templates: {weather_alerts: [{id, name}], spc_wpc: [{id, name}]},
  locations: [{id, label}],
  can_create_location: bool
}
```

- [ ] **Step 1: Write the failing option tests**

Create `tests/Feature/ApiAlertRuleOptionsTest.php`:

```php
<?php

use App\Enums\AlertCategory;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Pixel 9', ['app'])->plainTextToken;
});

function optionsCall($test, ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->token)->getJson('/api/v1/alert-rule-options');
}

test('it needs an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/alert-rule-options')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    optionsCall($this, $beacon)->assertForbidden();
});

test('it lists the categories, the matching modes and the active products grouped by product category', function () {
    $retired = Product::factory()->retired()->create(['family' => 'warning', 'category' => Product::where('code', 'TO.W')->sole()->category]);

    $response = optionsCall($this)->assertOk();

    expect(collect($response->json('data.categories'))->pluck('value')->all())->toBe(array_map(fn (AlertCategory $c) => $c->value, AlertCategory::cases()))
        ->and(collect($response->json('data.matching_modes'))->pluck('value')->all())->toContain('nationwide', 'location', 'wfo', 'county_zone', 'polygon', 'risk_area');

    $tornado = Product::where('code', 'TO.W')->sole();
    $weatherIds = collect($response->json('data.products.weather_alerts'))->pluck('products')->flatten(1)->pluck('id');
    $group = collect($response->json('data.products.weather_alerts'))->firstWhere('category', $tornado->category->value);

    expect($weatherIds)->toContain($tornado->id)->not->toContain($retired->id)
        ->and($group['label'])->toBe($tornado->category->label());
});

test('SPC and WPC products carry their risk levels', function () {
    $risk = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();

    $response = optionsCall($this)->assertOk();
    $product = collect($response->json('data.products.spc_wpc'))->pluck('products')->flatten(1)->firstWhere('id', $risk->id);

    expect($product['risk_levels'])->toBe($risk->risk_level_options);
});

test('threshold units and the lightning radius bounds follow the subscriber\'s unit system', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->lightning_min_radius_miles = 10;
    $limits->save();

    $imperial = optionsCall($this)->assertOk();
    $metricUser = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $metric = optionsCall($this, $metricUser->createToken('p', ['app'])->plainTextToken)->assertOk();

    $wind = fn ($response) => collect($response->json('data.thresholds.variables'))->firstWhere('value', 'wind_speed');

    expect($imperial->json('data.units.system'))->toBe('imperial')
        ->and($wind($imperial)['unit'])->toBe('mph')
        ->and($imperial->json('data.lightning'))->toMatchArray(['unit' => 'mi', 'min' => 10, 'max' => 50, 'default' => 15])
        ->and($metric->json('data.units.system'))->toBe('metric')
        ->and($wind($metric))->toMatchArray(['unit' => 'km/h', 'decimals' => 0])
        ->and($metric->json('data.lightning'))->toMatchArray(['unit' => 'km', 'min' => 17, 'max' => 80, 'default' => 24]);
});

test('message customisation is offered to paid tiers only', function () {
    expect(optionsCall($this)->json('data.tier.can_customize_messages'))->toBeFalse();

    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    expect(optionsCall($this)->json('data.tier.can_customize_messages'))->toBeTrue();
});

test('channels, templates and locations are the subscriber\'s own and never expose a destination', function () {
    $mine = NotificationChannel::factory()->for($this->user)->create(['label' => 'Team Discord', 'apprise_url' => 'discord://secret-id/secret-token/']);
    $unverified = NotificationChannel::factory()->for($this->user)->create(['label' => 'New one', 'verified_at' => null]);
    NotificationChannel::factory()->create(['label' => 'Someone else']);
    $location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    Location::factory()->create(['label' => 'Not mine']);
    $template = AlertTemplate::factory()->for($this->user)->create(['category' => AlertCategory::WeatherAlerts, 'name' => 'My template']);
    AlertTemplate::factory()->create(['category' => AlertCategory::WeatherAlerts, 'name' => 'Their template']);

    $response = optionsCall($this)->assertOk();

    expect(collect($response->json('data.channels'))->pluck('label')->all())->toBe(['New one', 'Team Discord'])
        ->and(collect($response->json('data.channels'))->firstWhere('id', $mine->id))->toMatchArray(['type' => 'discord', 'type_label' => 'Discord', 'verified' => true])
        ->and(collect($response->json('data.channels'))->firstWhere('id', $unverified->id)['verified'])->toBeFalse()
        ->and($response->json('data.locations'))->toBe([['id' => $location->id, 'label' => 'Home']])
        ->and($response->json('data.templates.weather_alerts'))->toBe([['id' => $template->id, 'name' => 'My template']])
        ->and($response->getContent())->not->toContain('secret-token')->not->toContain('Someone else')->not->toContain('Their template');
});

test('it says whether another location can be created', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();

    expect(optionsCall($this)->json('data.can_create_location'))->toBeTrue();

    Location::factory()->for($this->user)->create();

    expect(optionsCall($this)->json('data.can_create_location'))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify they fail**

Run the container pest command on `tests/Feature/ApiAlertRuleOptionsTest.php`. Expected: FAIL (404 on the route).

- [ ] **Step 3: Write the options action, controller and route**

Create `app/Actions/Alerts/BuildAlertRuleOptions.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\ProductCategory;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Enums\Tier;
use App\Models\AlertTemplate;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Units;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything the alert rule form needs in one read-only payload: the choices the dashboard form
 * builds from its enums, product catalog, settings and the subscriber's own channels, templates
 * and locations. Channel entries never carry a destination or URL.
 */
class BuildAlertRuleOptions
{
    /** The product families a Weather Alerts rule can choose from (the dashboard form's own list). */
    public const WEATHER_FAMILIES = ['warning', 'watch', 'advisory', 'statement', 'informational', 'administrative', 'aviation', 'forecast_discussion'];

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        $units = Units::for($user);
        $limits = app(TierThresholdSettings::class);
        $min = $units->minDistanceDisplay($limits->lightning_min_radius_miles);
        $max = $units->maxDistanceDisplay(50);
        $registry = app(ChannelTypeRegistry::class);

        return [
            'categories' => collect(AlertCategory::cases())->map(fn (AlertCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
                'description' => $category->description(),
            ])->all(),
            'products' => [
                'weather_alerts' => $this->groups(Product::query()->whereIn('family', self::WEATHER_FAMILIES)->where('status', 'active')),
                'spc_wpc' => $this->groups(Product::query()->where('family', 'spc_wpc')->where('status', 'active')),
            ],
            'matching_modes' => collect(MatchingMode::cases())->map(fn (MatchingMode $mode): array => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'description' => $mode->helperText(),
            ])->all(),
            'thresholds' => [
                'variables' => collect(ThresholdVariable::cases())->map(fn (ThresholdVariable $variable): array => [
                    'value' => $variable->value,
                    'label' => $variable->label(),
                    'unit' => $variable->unit($units->system),
                    'decimals' => $variable->displayDecimals($units->system),
                ])->all(),
                'directions' => collect(ThresholdDirection::cases())->map(fn (ThresholdDirection $d): array => ['value' => $d->value, 'label' => $d->label()])->all(),
                'timeframes' => collect(ThresholdTimeframe::cases())->map(fn (ThresholdTimeframe $t): array => ['value' => $t->value, 'label' => $t->label()])->all(),
            ],
            'lightning' => [
                'unit' => $units->distanceUnit(),
                'min' => $min,
                'max' => $max,
                'default' => min($max, max($min, (int) round($units->distanceValue(15)))),
            ],
            'units' => ['system' => $units->system->value],
            'tier' => ['can_customize_messages' => $user->tier() !== Tier::Free],
            'message_body_styles' => collect(MessageBodyStyle::cases())->map(fn (MessageBodyStyle $s): array => ['value' => $s->value, 'label' => $s->label()])->all(),
            'channels' => $user->notificationChannels()->orderBy('label')->orderBy('id')->get()->map(fn (NotificationChannel $channel): array => [
                'id' => $channel->id,
                'label' => $channel->label,
                'type' => $channel->channel_type,
                'type_label' => $registry->find($channel->channel_type)?->label() ?? $channel->channel_type,
                'verified' => $channel->isVerified(),
            ])->all(),
            'templates' => [
                'weather_alerts' => $this->templates($user, AlertCategory::WeatherAlerts),
                'spc_wpc' => $this->templates($user, AlertCategory::SpcWpc),
            ],
            'locations' => $user->locations()->orderBy('label')->orderBy('id')->get(['id', 'label'])
                ->map(fn ($location): array => ['id' => $location->id, 'label' => $location->label])->all(),
            'can_create_location' => $user->canCreateLocation(),
        ];
    }

    /**
     * One group per product category present, in the dashboard's section order.
     *
     * @param  Builder<Product>  $query
     * @return list<array{category: string, label: string, products: list<array<string, mixed>>}>
     */
    private function groups(Builder $query): array
    {
        $byCategory = $query->orderBy('sort_order')->get(['id', 'name', 'description', 'category', 'risk_level_options'])
            ->groupBy(fn (Product $product): ?string => $product->category?->value);

        return collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $category): bool => $byCategory->has($category->value))
            ->map(fn (ProductCategory $category): array => [
                'category' => $category->value,
                'label' => $category->label(),
                'products' => $byCategory->get($category->value)->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'risk_levels' => $product->risk_level_options ?? [],
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function templates(User $user, AlertCategory $category): array
    {
        return AlertTemplate::query()
            ->where('user_id', $user->id)
            ->where('category', $category->value)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AlertTemplate $template): array => ['id' => $template->id, 'name' => $template->name])
            ->all();
    }
}
```

Create `app/Http/Controllers/Api/V1/AlertRuleOptionsController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\BuildAlertRuleOptions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertRuleOptionsController extends Controller
{
    public function __invoke(Request $request, BuildAlertRuleOptions $options): JsonResponse
    {
        return response()->json(['data' => $options($request->user())]);
    }
}
```

In `routes/api.php`, inside the existing `ability:app` group, add (with `use` lines for the new controllers):

```php
        Route::get('/alert-rule-options', AlertRuleOptionsController::class)->name('api.v1.alert-rule-options');
```

- [ ] **Step 4: Run the option tests and fix until they pass**

Expected: PASS. If the metric lightning numbers differ (17/80/24), recompute from `Units::minDistanceDisplay(10)` = ceil(16.09) = 17, `maxDistanceDisplay(50)` = floor(80.47) = 80 and default = round(24.14) = 24; the numbers are correct, so a mismatch means the code is wrong. If `ProductCategory::label()` does not exist, read the enum: the dashboard form calls `$category->label()` on it, so it does.

- [ ] **Step 5: Write the failing lookup tests**

Create `tests/Feature/ApiLookupsTest.php`:

```php
<?php

use App\Models\User;
use App\Rules\KnownUgcCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Pixel 9', ['app'])->plainTextToken;

    $boundary = DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)");

    DB::table('cwa_boundaries')->insert([
        ['wfo' => 'OKX', 'cwa' => 'OKX', 'citystate' => 'New York, NY', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()],
        ['wfo' => 'ABQ', 'cwa' => 'ABQ', 'citystate' => 'Albuquerque, NM', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()],
    ]);

    foreach ([['NY', '36103', 'Suffolk'], ['NY', '36059', 'Nassau'], ['NJ', '34003', 'Bergen']] as [$state, $fips, $name]) {
        DB::table('counties')->insert([
            'state' => $state, 'cwa' => 'OKX', 'countyname' => $name, 'fips' => $fips, 'lon' => -72.5, 'lat' => 40.9,
            'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ([['NY', '072', 'Suffolk'], ['NY', '078', 'Southern Nassau'], ['NJ', '104', 'Eastern Bergen']] as [$state, $zone, $name]) {
        DB::table('public_zones')->insert([
            'state' => $state, 'cwa' => 'OKX', 'zone' => $zone, 'name' => $name, 'state_zone' => $state.$zone,
            'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

function lookup($test, string $uri)
{
    app('auth')->forgetGuards();

    return $test->withToken($test->token)->getJson($uri);
}

test('the offices come back with their codes and labels', function () {
    $response = lookup($this, '/api/v1/nws-offices')->assertOk();

    expect($response->json('data'))->toBe([
        ['code' => 'ABQ', 'label' => 'ABQ — Albuquerque, NM'],
        ['code' => 'OKX', 'label' => 'OKX — New York, NY'],
    ]);
});

test('zones are searched by state and text and carry the UGC codes the rule form stores', function () {
    $byState = lookup($this, '/api/v1/zones?state=NY')->assertOk();
    $byText = lookup($this, '/api/v1/zones?q=bergen')->assertOk();

    expect(collect($byState->json('data'))->pluck('code')->all())->toBe(['NYC059', 'NYC103', 'NYZ072', 'NYZ078'])
        ->and($byState->json('data.1'))->toBe(['code' => 'NYC103', 'label' => 'NYC103 — Suffolk County, NY'])
        ->and($byState->json('data.2'))->toBe(['code' => 'NYZ072', 'label' => 'NYZ072 — Suffolk, NY'])
        ->and(collect($byText->json('data'))->pluck('code')->all())->toBe(['NJC003', 'NJZ104']);
});

test('zones page by the last code seen and never return the whole table', function () {
    $first = lookup($this, '/api/v1/zones?state=NY&limit=2')->assertOk();
    $second = lookup($this, '/api/v1/zones?state=NY&limit=2&after=NYC103')->assertOk();

    expect(collect($first->json('data'))->pluck('code')->all())->toBe(['NYC059', 'NYC103'])
        ->and($first->json('meta.next_after'))->toBe('NYC103')
        ->and(collect($second->json('data'))->pluck('code')->all())->toBe(['NYZ072', 'NYZ078'])
        ->and($second->json('meta.next_after'))->toBeNull();

    lookup($this, '/api/v1/zones')->assertStatus(422);
    lookup($this, '/api/v1/zones?q=a')->assertStatus(422);
    lookup($this, '/api/v1/zones?state=NY&limit=500')->assertStatus(422);
});

test('a search treats like wildcards as plain text', function () {
    expect(lookup($this, '/api/v1/zones?q=%25%25')->assertOk()->json('data'))->toBe([]);
});

test('the known UGC code rule accepts real counties and zones and refuses everything else', function (string $code, bool $valid) {
    $validator = Validator::make(['code' => $code], ['code' => [new KnownUgcCode]]);

    expect($validator->passes())->toBe($valid);
})->with([
    'a county' => ['NYC103', true],
    'a zone' => ['NYZ072', true],
    'lower case is not a code' => ['nyc103', false],
    'an unknown county' => ['NYC999', false],
    'an unknown zone' => ['NYZ999', false],
    'a bad shape' => ['NY103', false],
    'a number' => ['12345', false],
]);

test('the lookups need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/nws-offices')->assertUnauthorized();
    $this->getJson('/api/v1/zones?state=NY')->assertUnauthorized();
});
```

(The `cwa_boundaries` fixture rows must carry the NOT NULL columns `wfo`, `lon` and `lat` as well as `cwa`, `citystate`, `boundary` and the timestamps (migration `2026_09_07_100012_create_cwa_boundaries_table.php`); the insert shape is copied from `tests/Feature/TestProductScenarioWizardStep2Test.php`. Task 3's two test files seed the same rows the same way.)

- [ ] **Step 6: Write the lookups**

Create `app/Actions/Weather/SearchUgcCodes.php`:

```php
<?php

namespace App\Actions\Weather;

use Illuminate\Support\Facades\DB;

/**
 * Counties and forecast zones as the UGC codes the alert form stores (county SSCnnn, zone SSZnnn),
 * searched by state and text and paged by the last code seen. counties.fips is a raw 5-digit
 * FIPS code and public_zones.state_zone omits the type letter, so both codes are derived here,
 * the way the dashboard form derives them.
 */
class SearchUgcCodes
{
    private const SOURCE = <<<'SQL'
        SELECT upper(state) || 'C' || right(fips, 3) AS code,
               upper(state) || 'C' || right(fips, 3) || ' — ' || countyname || ' County, ' || state AS label,
               upper(state) AS st,
               countyname AS name
        FROM counties
        UNION ALL
        SELECT upper(state) || 'Z' || zone AS code,
               upper(state) || 'Z' || zone || ' — ' || name || ', ' || state AS label,
               upper(state) AS st,
               name
        FROM public_zones
    SQL;

    /**
     * @return list<array{code: string, label: string}>
     */
    public function __invoke(?string $state, ?string $query, int $limit, ?string $after = null): array
    {
        $state = strtoupper((string) $state);
        $text = trim((string) $query);
        $after = (string) $after;
        $needle = '%'.addcslashes($text, '%_\\').'%';

        return collect(DB::select(
            'SELECT code, label FROM ('.self::SOURCE.') AS ugc
             WHERE (? = \'\' OR st = ?)
               AND (? = \'\' OR name ILIKE ? OR code ILIKE ?)
               AND (? = \'\' OR code > ?)
             ORDER BY code
             LIMIT ?',
            [$state, $state, $text, $needle, $needle, $after, $after, $limit],
        ))->map(fn (object $row): array => ['code' => $row->code, 'label' => $row->label])->all();
    }

    public function exists(string $code): bool
    {
        if (preg_match('/^([A-Z]{2})([CZ])(\d{3})$/', $code, $m) !== 1) {
            return false;
        }

        [, $state, $type, $number] = $m;

        return $type === 'C'
            ? DB::table('counties')->whereRaw('upper(state) = ?', [$state])->whereRaw('right(fips, 3) = ?', [$number])->exists()
            : DB::table('public_zones')->whereRaw('upper(state) = ?', [$state])->where('zone', $number)->exists();
    }
}
```

Create `app/Rules/KnownUgcCode.php`:

```php
<?php

namespace App\Rules;

use App\Actions\Weather\SearchUgcCodes;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A county (SSCnnn) or forecast zone (SSZnnn) UGC code that exists in the weather tables.
 */
class KnownUgcCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(SearchUgcCodes::class)->exists($value)) {
            $fail('The :attribute must be a known county or zone code.');
        }
    }
}
```

Create `app/Http/Requests/SearchZonesRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchZonesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A state or at least two characters of text is required, so the whole table is never returned.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', 'size:2', 'alpha', 'required_without:q'],
            'q' => ['nullable', 'string', 'min:2', 'max:50', 'required_without:state'],
            'after' => ['nullable', 'string', 'max:6'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
```

Create `app/Http/Controllers/Api/V1/NwsOfficeController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class NwsOfficeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $offices = DB::table('cwa_boundaries')->orderBy('cwa')->get(['cwa', 'citystate'])
            ->map(fn (object $row): array => ['code' => $row->cwa, 'label' => "{$row->cwa} — {$row->citystate}"])
            ->all();

        return response()->json(['data' => $offices]);
    }
}
```

Create `app/Http/Controllers/Api/V1/ZoneController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Weather\SearchUgcCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchZonesRequest;
use Illuminate\Http\JsonResponse;

class ZoneController extends Controller
{
    public function __invoke(SearchZonesRequest $request, SearchUgcCodes $search): JsonResponse
    {
        $limit = (int) $request->validated('limit', 25);

        // One extra row tells whether another page exists, so a page that ends exactly on the last row has no next.
        $rows = $search($request->validated('state'), $request->validated('q'), $limit + 1, $request->validated('after'));
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return response()->json([
            'data' => $rows,
            'meta' => ['next_after' => $hasMore ? $rows[$limit - 1]['code'] : null],
        ]);
    }
}
```

Add the routes beside the options route:

```php
        Route::get('/nws-offices', NwsOfficeController::class)->name('api.v1.nws-offices');
        Route::get('/zones', ZoneController::class)->name('api.v1.zones');
```

- [ ] **Step 7: Run both test files and fix until they pass; then format and commit**

Run the container pest command on `tests/Feature/ApiAlertRuleOptionsTest.php tests/Feature/ApiLookupsTest.php`. Expected: PASS. Then:

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --dirty --format agent'
git add app routes tests
git commit -m "API: alert rule options, NWS offices and zone search for the app

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

### Task 3: Alert rule endpoints (list, show, create, replace, switch, delete) and the dashboard parity test

**Files:**
- Create: `app/Actions/Alerts/SetAlertRuleActive.php`, `app/Actions/Alerts/DeleteAlertRule.php`, `app/Http/Controllers/Api/V1/AlertRuleController.php`, `app/Http/Requests/SaveAlertRuleRequest.php`, `app/Http/Requests/SetAlertRuleActiveRequest.php`, `app/Http/Resources/Api/V1/AlertRuleResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/ApiAlertRulesTest.php`, `tests/Feature/AlertRuleParityTest.php`

**Interfaces:**
- Consumes: `SaveAlertRule` (Task 1), `BuildAlertRuleOptions::WEATHER_FAMILIES` and `KnownUgcCode` (Task 2).
- Produces: routes `api.v1.alert-rules.{index,show,store,update,set-active,destroy}`; `AlertRuleResource` JSON (below); `SaveAlertRuleRequest::ruleData(): array` returning the keys `SaveAlertRule` reads.
- Rule JSON (index returns a list, show/store/update one, all under `data`):

```
{id, name, display_name, category, category_label, is_active, matching_mode, product_ids, location_ids, location_id,
 wfo, county_zone_codes: [string], risk_levels: [string], variable, direction, threshold_value: float|null, threshold_unit,
 timeframe, radius: int|null, radius_unit, delivery_time: "HH:MM"|null, quiet_hours_start, quiet_hours_end,
 message_body_style, custom_subject_template, alert_template_id, channel_ids}
```
- Request body for `POST`/`PUT`: `category`, `name`, `channel_ids`, `quiet_hours_start`, `quiet_hours_end`, `message_body_style`, `custom_subject_template`, `alert_template_id` (paid tiers) and the category fields: Weather Alerts / SPC & WPC: `product_ids` (flat), `matching_mode`, `location_ids`, `wfo`, `county_zone_codes` (array), `risk_levels` (flat, SPC only); Threshold: `variable`, `direction`, `threshold_value`, `timeframe`, `location_id`, `delivery_time`; Daily Summary: `location_id`, `delivery_time`; Lightning: `location_id`, `radius` (a whole number in the subscriber's distance unit; the column is an integer, so a fraction is refused with 422). `PATCH` body: `is_active` (boolean).

- [ ] **Step 1: Write the failing endpoint tests**

Create `tests/Feature/ApiAlertRulesTest.php`:

```php
<?php

use App\Enums\AlertCategory;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $limits = app(TierThresholdSettings::class);
    $limits->lightning_min_radius_miles = 10;
    $limits->save();

    $boundary = DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)");
    DB::table('cwa_boundaries')->insert(['wfo' => 'OKX', 'cwa' => 'OKX', 'citystate' => 'New York, NY', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('counties')->insert(['state' => 'NY', 'cwa' => 'OKX', 'countyname' => 'Suffolk', 'fips' => '36103', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('public_zones')->insert(['state' => 'NY', 'cwa' => 'OKX', 'zone' => '072', 'name' => 'Suffolk', 'state_zone' => 'NY072', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Pixel 9', ['app'])->plainTextToken;
    $this->location = Location::factory()->for($this->user)->create();
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
    $this->tornado = Product::where('code', 'TO.W')->sole();
    $this->riskProduct = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();
});

function ruleCall($test, string $method, string $uri, array $body = [], ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->token)->json($method, $uri, $body);
}

/**
 * A valid request body for each kind of rule, built from the test's own records.
 */
function validRule($test, string $kind): array
{
    $common = ['channel_ids' => [$test->channel->id]];

    return match ($kind) {
        'threshold' => ['category' => 'threshold', 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32, 'timeframe' => 'overnight', 'location_id' => $test->location->id, 'delivery_time' => '06:00', ...$common],
        'daily summary' => ['category' => 'daily_summary', 'location_id' => $test->location->id, 'delivery_time' => '07:00', ...$common],
        'lightning' => ['category' => 'lightning', 'location_id' => $test->location->id, 'radius' => 15, ...$common],
        'weather nationwide' => ['category' => 'weather_alerts', 'product_ids' => [$test->tornado->id], 'matching_mode' => 'nationwide', ...$common],
        'weather by location' => ['category' => 'weather_alerts', 'product_ids' => [$test->tornado->id], 'matching_mode' => 'location', 'location_ids' => [$test->location->id], ...$common],
        'weather by office' => ['category' => 'weather_alerts', 'product_ids' => [$test->tornado->id], 'matching_mode' => 'wfo', 'wfo' => 'OKX', ...$common],
        'weather by county and zone' => ['category' => 'weather_alerts', 'product_ids' => [$test->tornado->id], 'matching_mode' => 'county_zone', 'county_zone_codes' => ['NYC103', 'NYZ072'], ...$common],
        'spc by risk area' => ['category' => 'spc_wpc', 'product_ids' => [$test->riskProduct->id], 'matching_mode' => 'risk_area', 'location_ids' => [$test->location->id], 'risk_levels' => array_slice($test->riskProduct->risk_level_options, 0, 2), ...$common],
    };
}

test('the rule endpoints need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/alert-rules')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    ruleCall($this, 'GET', '/api/v1/alert-rules', [], $beacon)->assertForbidden();
});

test('the list is the subscriber\'s own rules, newest first, with every field the editor needs', function () {
    $older = AlertRule::factory()->for($this->user)->create(['location_id' => $this->location->id, 'name' => 'Freeze', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00']);
    $newer = AlertRule::factory()->for($this->user)->lightning()->create(['location_id' => $this->location->id]);
    $older->notificationChannels()->attach($this->channel->id);
    AlertRule::factory()->create();

    $response = ruleCall($this, 'GET', '/api/v1/alert-rules')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id]);

    $freeze = collect($response->json('data'))->firstWhere('id', $older->id);

    expect($freeze)->toMatchArray([
        'name' => 'Freeze', 'display_name' => 'Freeze', 'category' => 'threshold', 'category_label' => 'Threshold Alerts',
        'is_active' => true, 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0, 'threshold_unit' => '°F',
        'timeframe' => 'overnight', 'delivery_time' => '06:00', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00',
        'location_id' => $this->location->id, 'channel_ids' => [$this->channel->id], 'product_ids' => [], 'county_zone_codes' => [],
    ]);
});

test('a Metric subscriber sees their own units', function () {
    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $location = Location::factory()->for($metric)->create();
    $threshold = AlertRule::factory()->for($metric)->create(['location_id' => $location->id, 'threshold_value' => 32]);
    $lightning = AlertRule::factory()->for($metric)->lightning()->create(['location_id' => $location->id, 'radius_miles' => 15]);

    $response = ruleCall($this, 'GET', '/api/v1/alert-rules', [], $metric->createToken('p', ['app'])->plainTextToken)->assertOk();

    $byId = collect($response->json('data'))->keyBy('id');

    expect($byId[$threshold->id])->toMatchArray(['threshold_value' => 0.0, 'threshold_unit' => '°C'])
        ->and($byId[$lightning->id])->toMatchArray(['radius' => 24, 'radius_unit' => 'km']);
});

test('showing a rule returns its products, locations and codes; another subscriber\'s rule is a 404', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['category' => AlertCategory::WeatherAlerts, 'matching_mode' => 'county_zone', 'county_zone_codes' => 'NYC103,NYZ072', 'location_id' => null]);
    $rule->products()->attach($this->tornado->id);
    $rule->locations()->attach($this->location->id);
    $theirs = AlertRule::factory()->create();

    ruleCall($this, 'GET', "/api/v1/alert-rules/{$rule->id}")->assertOk()
        ->assertJsonPath('data.product_ids', [$this->tornado->id])
        ->assertJsonPath('data.location_ids', [$this->location->id])
        ->assertJsonPath('data.county_zone_codes', ['NYC103', 'NYZ072']);

    ruleCall($this, 'GET', "/api/v1/alert-rules/{$theirs->id}")->assertNotFound();
});

test('a valid rule of every kind is created with its relations', function (string $kind) {
    $response = ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, $kind))->assertCreated();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($response->json('data.id'))->toBe($rule->id)
        ->and($rule->notificationChannels->pluck('id')->all())->toBe([$this->channel->id])
        ->and($rule->category->value)->toBe(validRule($this, $kind)['category'])
        ->and($rule->is_active)->toBeTrue();
})->with(['threshold', 'daily summary', 'lightning', 'weather nationwide', 'weather by location', 'weather by office', 'weather by county and zone', 'spc by risk area']);

test('the stored values match what was sent, in imperial units', function () {
    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $location = Location::factory()->for($metric)->create();
    $channel = NotificationChannel::factory()->for($metric)->create();
    $token = $metric->createToken('p', ['app'])->plainTextToken;

    ruleCall($this, 'POST', '/api/v1/alert-rules', ['category' => 'threshold', 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 0, 'timeframe' => 'overnight', 'location_id' => $location->id, 'delivery_time' => '06:00', 'channel_ids' => [$channel->id]], $token)->assertCreated();
    ruleCall($this, 'POST', '/api/v1/alert-rules', ['category' => 'lightning', 'location_id' => $location->id, 'radius' => 24, 'channel_ids' => [$channel->id]], $token)->assertCreated();

    expect((float) AlertRule::where('category', 'threshold')->where('user_id', $metric->id)->sole()->threshold_value)->toBe(32.0)
        ->and(AlertRule::where('category', 'lightning')->where('user_id', $metric->id)->sole()->radius_miles)->toBe(15);
});

test('a refused request names the field and writes nothing', function (string $case, string $field) {
    $other = User::factory()->create();
    $body = match ($case) {
        'no category' => [...validRule($this, 'threshold'), 'category' => null],
        'no channels' => [...validRule($this, 'threshold'), 'channel_ids' => []],
        'a channel of another subscriber' => [...validRule($this, 'threshold'), 'channel_ids' => [NotificationChannel::factory()->for($other)->create()->id]],
        'a location of another subscriber' => [...validRule($this, 'threshold'), 'location_id' => Location::factory()->for($other)->create()->id],
        'a threshold without a value' => [...validRule($this, 'threshold'), 'threshold_value' => null],
        'a threshold with an unknown variable' => [...validRule($this, 'threshold'), 'variable' => 'humidex'],
        'a delivery time that is not a clock time' => [...validRule($this, 'daily summary'), 'delivery_time' => '6am'],
        'a daily summary without a delivery time' => Arr::except(validRule($this, 'daily summary'), 'delivery_time'),
        'a lightning radius below the minimum' => [...validRule($this, 'lightning'), 'radius' => 9],
        'a lightning radius above 50 miles' => [...validRule($this, 'lightning'), 'radius' => 51],
        'a lightning radius that is not a whole number' => [...validRule($this, 'lightning'), 'radius' => 24.5],
        'weather alerts without products' => [...validRule($this, 'weather nationwide'), 'product_ids' => []],
        'a product from the wrong family' => [...validRule($this, 'weather nationwide'), 'product_ids' => [$this->riskProduct->id]],
        'risk area on a weather alert' => [...validRule($this, 'weather nationwide'), 'matching_mode' => 'risk_area'],
        'polygon on an SPC product that has risk levels' => [...validRule($this, 'spc by risk area'), 'matching_mode' => 'polygon'],
        'a location mode without locations' => [...validRule($this, 'weather by location'), 'location_ids' => []],
        'a location mode with another subscriber\'s location' => [...validRule($this, 'weather by location'), 'location_ids' => [Location::factory()->for($other)->create()->id]],
        'an unknown office' => [...validRule($this, 'weather by office'), 'wfo' => 'ZZZ'],
        'an unknown county code' => [...validRule($this, 'weather by county and zone'), 'county_zone_codes' => ['NYC999']],
        'a risk level the products do not offer' => [...validRule($this, 'spc by risk area'), 'risk_levels' => ['NOT-A-LEVEL']],
        'quiet hours that are not clock times' => [...validRule($this, 'threshold'), 'quiet_hours_start' => '10pm'],
    };

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertUnprocessable()->assertJsonValidationErrorFor($field);

    expect(AlertRule::where('user_id', $this->user->id)->count())->toBe(0);
})->with([
    'no category' => ['no category', 'category'],
    'no channels' => ['no channels', 'channel_ids'],
    'a channel of another subscriber' => ['a channel of another subscriber', 'channel_ids.0'],
    'a location of another subscriber' => ['a location of another subscriber', 'location_id'],
    'a threshold without a value' => ['a threshold without a value', 'threshold_value'],
    'a threshold with an unknown variable' => ['a threshold with an unknown variable', 'variable'],
    'a delivery time that is not a clock time' => ['a delivery time that is not a clock time', 'delivery_time'],
    'a daily summary without a delivery time' => ['a daily summary without a delivery time', 'delivery_time'],
    'a lightning radius below the minimum' => ['a lightning radius below the minimum', 'radius'],
    'a lightning radius above 50 miles' => ['a lightning radius above 50 miles', 'radius'],
    'a lightning radius that is not a whole number' => ['a lightning radius that is not a whole number', 'radius'],
    'weather alerts without products' => ['weather alerts without products', 'product_ids'],
    'a product from the wrong family' => ['a product from the wrong family', 'product_ids.0'],
    'risk area on a weather alert' => ['risk area on a weather alert', 'matching_mode'],
    'polygon on an SPC product that has risk levels' => ['polygon on an SPC product that has risk levels', 'matching_mode'],
    'a location mode without locations' => ['a location mode without locations', 'location_ids'],
    'a location mode with another subscriber\'s location' => ['a location mode with another subscriber\'s location', 'location_ids.0'],
    'an unknown office' => ['an unknown office', 'wfo'],
    'an unknown county code' => ['an unknown county code', 'county_zone_codes.0'],
    'a risk level the products do not offer' => ['a risk level the products do not offer', 'risk_levels.0'],
    'quiet hours that are not clock times' => ['quiet hours that are not clock times', 'quiet_hours_start'],
]);

test('fields that do not apply to the chosen matching mode are dropped, not stored', function () {
    $body = [...validRule($this, 'weather nationwide'), 'location_ids' => [$this->location->id], 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103']];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertCreated();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->locations)->toBeEmpty()
        ->and($rule->wfo)->toBeNull()
        ->and($rule->county_zone_codes)->toBeNull();
});

test('message customisation is stored for a paid subscriber and ignored for a Free one', function () {
    $body = [...validRule($this, 'weather nationwide'), 'message_body_style' => 'brief', 'custom_subject_template' => '{product}'];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertCreated();
    expect(AlertRule::where('user_id', $this->user->id)->sole())->message_body_style->toBeNull()->custom_subject_template->toBeNull();

    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $template = AlertTemplate::factory()->for($this->user)->create(['category' => AlertCategory::WeatherAlerts]);
    $response = ruleCall($this, 'POST', '/api/v1/alert-rules', [...$body, 'alert_template_id' => $template->id])->assertCreated();

    $stored = AlertRule::find($response->json('data.id'));

    expect($stored->message_body_style->value)->toBe('brief')
        ->and($stored->custom_subject_template)->toBe('{product}')
        ->and($stored->alert_template_id)->toBe($template->id);

    $theirs = AlertTemplate::factory()->create(['category' => AlertCategory::WeatherAlerts]);
    ruleCall($this, 'POST', '/api/v1/alert-rules', [...$body, 'alert_template_id' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrorFor('alert_template_id');
});

test('a rule can be replaced, including a change of category', function () {
    $rule = AlertRule::find(ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, 'weather by location'))->json('data.id'));

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$rule->id}", validRule($this, 'daily summary'))->assertOk()->assertJsonPath('data.category', 'daily_summary');

    $rule->refresh();

    expect($rule->category)->toBe(AlertCategory::DailySummary)
        ->and($rule->matching_mode)->toBeNull()
        ->and($rule->products)->toBeEmpty()
        ->and($rule->locations)->toBeEmpty()
        ->and($rule->location_id)->toBe($this->location->id);
});

test('another subscriber\'s rule cannot be replaced, switched or deleted', function () {
    $theirs = AlertRule::factory()->create(['name' => 'Theirs']);

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$theirs->id}", validRule($this, 'threshold'))->assertNotFound();
    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$theirs->id}", ['is_active' => false])->assertNotFound();
    ruleCall($this, 'DELETE', "/api/v1/alert-rules/{$theirs->id}")->assertNotFound();

    expect($theirs->fresh())->name->toBe('Theirs')->is_active->toBeTrue();
});

test('a rule is switched off and on with PATCH', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['location_id' => $this->location->id]);

    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$rule->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    expect($rule->fresh()->is_active)->toBeFalse();

    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$rule->id}", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$rule->id}", [])->assertUnprocessable()->assertJsonValidationErrorFor('is_active');
});

test('a rule is deleted with its relations', function () {
    $rule = AlertRule::find(ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, 'weather by location'))->json('data.id'));

    ruleCall($this, 'DELETE', "/api/v1/alert-rules/{$rule->id}")->assertNoContent();

    expect(AlertRule::find($rule->id))->toBeNull()
        ->and(DB::table('alert_rule_location')->where('alert_rule_id', $rule->id)->exists())->toBeFalse();
});

test('every write is logged with the subscriber as the causer', function () {
    $created = ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, 'threshold'))->json('data.id');
    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$created}", ['is_active' => false]);
    ruleCall($this, 'DELETE', "/api/v1/alert-rules/{$created}");

    $activities = Activity::query()->where('subject_type', AlertRule::class)->get();

    expect($activities)->not->toBeEmpty()
        ->and($activities->pluck('causer_id')->unique()->all())->toBe([$this->user->id]);
});

test('during maintenance a write answers 503 with the maintenance message as plain text and changes nothing', function () {
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = true;
    $settings->maintenance_message = 'Back soon.';
    $settings->save();

    $response = ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, 'threshold'));

    $response->assertStatus(503);
    expect($response->getContent())->toBe('Back soon.')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and(AlertRule::where('user_id', $this->user->id)->count())->toBe(0);
});
```

(If the maintenance test shows no 503, check that `auth:sanctum` made sanctum the default guard for the request, so that `MaintenanceModeGuard::isBlocking()` sees the user through `auth()->user()`; report the finding rather than weakening the test.)

- [ ] **Step 2: Run to verify they fail**

Run the container pest command on `tests/Feature/ApiAlertRulesTest.php`. Expected: FAIL (404 on the routes).

- [ ] **Step 3: Write the actions, request, resource, controller and routes**

Create `app/Actions/Alerts/SetAlertRuleActive.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Models\AlertRule;
use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

class SetAlertRuleActive
{
    public function __invoke(User $user, AlertRule $rule, bool $active): AlertRule
    {
        app(CauserResolver::class)->withCauser($user, fn () => $rule->update(['is_active' => $active]));

        return $rule;
    }
}
```

Create `app/Actions/Alerts/DeleteAlertRule.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Models\AlertRule;
use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

class DeleteAlertRule
{
    public function __invoke(User $user, AlertRule $rule): void
    {
        app(CauserResolver::class)->withCauser($user, fn () => $rule->delete());
    }
}
```

Create `app/Http/Requests/SetAlertRuleActiveRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetAlertRuleActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}
```

Create `app/Http/Requests/SaveAlertRuleRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Actions\Alerts\BuildAlertRuleOptions;
use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Enums\Tier;
use App\Models\Product;
use App\Rules\KnownUgcCode;
use App\Settings\TierThresholdSettings;
use App\Support\Units;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The dashboard form's rules for an alert rule, per category. The body is in the subscriber's
 * own units (threshold_value, radius). ruleData() returns what SaveAlertRule reads.
 */
class SaveAlertRuleRequest extends FormRequest
{
    private const LOCATION_MODES = ['location', 'polygon', 'risk_area'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $category = AlertCategory::tryFrom((string) $this->input('category'));

        $rules = [
            'category' => ['required', Rule::enum(AlertCategory::class)],
            'name' => ['nullable', 'string', 'max:255'],
            'channel_ids' => ['required', 'array', 'min:1'],
            'channel_ids.*' => ['integer', Rule::exists('notification_channels', 'id')->where('user_id', $user->id)],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ];

        if ($user->tier() !== Tier::Free) {
            $rules['message_body_style'] = ['nullable', Rule::enum(MessageBodyStyle::class)];
            $rules['custom_subject_template'] = ['nullable', 'string', 'max:255'];

            if (in_array($category, [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc], true)) {
                $rules['alert_template_id'] = ['nullable', Rule::exists('alert_templates', 'id')->where('user_id', $user->id)->where('category', $category->value)];
            }
        }

        return [...$rules, ...match ($category) {
            AlertCategory::WeatherAlerts, AlertCategory::SpcWpc => $this->productRules($category),
            AlertCategory::Threshold => $this->thresholdRules(),
            AlertCategory::DailySummary => $this->scheduledRules(),
            AlertCategory::Lightning => $this->lightningRules(),
            null => [],
        }];
    }

    /**
     * What SaveAlertRule reads: the validated fields, with optional ones present as null (a PUT
     * replaces the rule), fields that the matching mode does not use emptied, and `radius`
     * renamed to the form's `radius_miles` key.
     *
     * @return array<string, mixed>
     */
    public function ruleData(): array
    {
        $data = [
            'name' => null, 'quiet_hours_start' => null, 'quiet_hours_end' => null,
            'custom_subject_template' => null, 'alert_template_id' => null,
            ...$this->validated(),
        ];

        $mode = $data['matching_mode'] ?? null;

        if (! in_array($mode, self::LOCATION_MODES, true)) {
            $data['location_ids'] = [];
        }

        if ($mode !== MatchingMode::Wfo->value) {
            $data['wfo'] = null;
        }

        if ($mode !== MatchingMode::CountyZone->value) {
            $data['county_zone_codes'] = null;
        }

        if (array_key_exists('radius', $data)) {
            $data['radius_miles'] = $data['radius'];
            unset($data['radius']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function productRules(AlertCategory $category): array
    {
        $mode = $this->input('matching_mode');
        $needsLocations = in_array($mode, self::LOCATION_MODES, true);

        return [
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where(fn ($query) => $category === AlertCategory::SpcWpc
                ? $query->where('family', 'spc_wpc')
                : $query->whereIn('family', BuildAlertRuleOptions::WEATHER_FAMILIES))],
            'matching_mode' => ['required', Rule::in($this->allowedModes($category))],
            'location_ids' => $needsLocations ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('user_id', $this->user()->id)],
            'wfo' => $mode === MatchingMode::Wfo->value ? ['required', 'string', Rule::exists('cwa_boundaries', 'cwa')] : ['nullable'],
            'county_zone_codes' => $mode === MatchingMode::CountyZone->value ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'county_zone_codes.*' => ['string', new KnownUgcCode],
            ...($category === AlertCategory::SpcWpc ? [
                'risk_levels' => ['nullable', 'array'],
                'risk_levels.*' => ['string', Rule::in($this->riskLevelOptions())],
            ] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function thresholdRules(): array
    {
        return [
            'variable' => ['required', Rule::enum(ThresholdVariable::class)],
            'direction' => ['required', Rule::enum(ThresholdDirection::class)],
            'threshold_value' => ['required', 'numeric'],
            'timeframe' => ['required', Rule::enum(ThresholdTimeframe::class)],
            ...$this->scheduledRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledRules(): array
    {
        return [
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('user_id', $this->user()->id)],
            'delivery_time' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lightningRules(): array
    {
        $units = Units::for($this->user());

        return [
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('user_id', $this->user()->id)],
            'radius' => [
                'required', 'integer',
                'min:'.$units->minDistanceDisplay(app(TierThresholdSettings::class)->lightning_min_radius_miles),
                'max:'.$units->maxDistanceDisplay(50),
            ],
        ];
    }

    /**
     * Weather Alerts may use every mode but Risk area. SPC & WPC offers Nationwide plus Risk area
     * when a selected product carries risk levels, otherwise Nationwide plus Polygon (the
     * dashboard form's own rule).
     *
     * @return list<string>
     */
    private function allowedModes(AlertCategory $category): array
    {
        if ($category === AlertCategory::SpcWpc) {
            $hasRiskLevels = Product::query()->whereIn('id', $this->selectedProductIds())->whereNotNull('risk_level_options')->exists();

            return $hasRiskLevels
                ? [MatchingMode::Nationwide->value, MatchingMode::RiskArea->value]
                : [MatchingMode::Nationwide->value, MatchingMode::Polygon->value];
        }

        return collect(MatchingMode::cases())
            ->reject(fn (MatchingMode $mode): bool => $mode === MatchingMode::RiskArea)
            ->map(fn (MatchingMode $mode): string => $mode->value)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function selectedProductIds(): array
    {
        return collect($this->input('product_ids', []))->flatten()->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * Every level any SPC & WPC product offers (any status: a rule on a retired product keeps its levels).
     *
     * @return list<string>
     */
    private function riskLevelOptions(): array
    {
        return Product::query()->where('family', 'spc_wpc')->whereNotNull('risk_level_options')
            ->pluck('risk_level_options')->flatten()->unique()->values()->all();
    }
}
```

Create `app/Http/Resources/Api/V1/AlertRuleResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AlertRule;
use App\Support\AlertRuleFormUnits;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An alert rule as the app's editor needs it, in the subscriber's own units. Carries ids only for
 * relations, never a channel destination or URL.
 *
 * @property AlertRule $resource
 */
class AlertRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rule = $this->resource;
        $units = Units::for($request->user());
        $display = AlertRuleFormUnits::toDisplay([
            'variable' => $rule->variable?->value,
            'threshold_value' => $rule->threshold_value,
            'radius_miles' => $rule->radius_miles,
        ], $units);

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'display_name' => $rule->displayName($units->system),
            'category' => $rule->category->value,
            'category_label' => $rule->category->label(),
            'is_active' => (bool) $rule->is_active,
            'matching_mode' => $rule->matching_mode?->value,
            'product_ids' => $rule->products->pluck('id')->values()->all(),
            'location_ids' => $rule->locations->pluck('id')->values()->all(),
            'location_id' => $rule->location_id,
            'wfo' => $rule->wfo,
            'county_zone_codes' => filled($rule->county_zone_codes) ? explode(',', $rule->county_zone_codes) : [],
            'risk_levels' => $rule->risk_levels ?? [],
            'variable' => $rule->variable?->value,
            'direction' => $rule->direction?->value,
            'threshold_value' => is_numeric($display['threshold_value']) ? (float) $display['threshold_value'] : null,
            'threshold_unit' => $rule->variable?->unit($units->system),
            'timeframe' => $rule->timeframe?->value,
            'radius' => is_numeric($display['radius_miles']) ? (int) $display['radius_miles'] : null,
            'radius_unit' => $units->distanceUnit(),
            'delivery_time' => $this->clock($rule->delivery_time),
            'quiet_hours_start' => $this->clock($rule->quiet_hours_start),
            'quiet_hours_end' => $this->clock($rule->quiet_hours_end),
            'message_body_style' => $rule->message_body_style?->value,
            'custom_subject_template' => $rule->custom_subject_template,
            'alert_template_id' => $rule->alert_template_id,
            'channel_ids' => $rule->notificationChannels->pluck('id')->values()->all(),
        ];
    }

    private function clock(mixed $time): ?string
    {
        return blank($time) ? null : substr((string) $time, 0, 5);
    }
}
```

Create `app/Http/Controllers/Api/V1/AlertRuleController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\DeleteAlertRule;
use App\Actions\Alerts\SaveAlertRule;
use App\Actions\Alerts\SetAlertRuleActive;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAlertRuleRequest;
use App\Http\Requests\SetAlertRuleActiveRequest;
use App\Http\Resources\Api\V1\AlertRuleResource;
use App\Models\AlertRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AlertRuleController extends Controller
{
    private const WITH = ['products:id,name', 'location:id,label', 'locations:id', 'notificationChannels:id'];

    public function index(Request $request): AnonymousResourceCollection
    {
        return AlertRuleResource::collection(
            $request->user()->alertRules()->with(self::WITH)->orderByDesc('id')->get()
        );
    }

    public function show(Request $request, int $id): AlertRuleResource
    {
        return new AlertRuleResource($this->find($request, $id));
    }

    public function store(SaveAlertRuleRequest $request, SaveAlertRule $save): JsonResponse
    {
        $rule = $save($request->user(), $request->ruleData())->load(self::WITH);

        return (new AlertRuleResource($rule))->response()->setStatusCode(201);
    }

    public function update(SaveAlertRuleRequest $request, int $id, SaveAlertRule $save): AlertRuleResource
    {
        $rule = $save($request->user(), $request->ruleData(), $this->find($request, $id))->load(self::WITH);

        return new AlertRuleResource($rule);
    }

    public function setActive(SetAlertRuleActiveRequest $request, int $id, SetAlertRuleActive $set): AlertRuleResource
    {
        return new AlertRuleResource($set($request->user(), $this->find($request, $id), $request->boolean('is_active')));
    }

    public function destroy(Request $request, int $id, DeleteAlertRule $delete): Response
    {
        $delete($request->user(), $this->find($request, $id));

        return response()->noContent();
    }

    private function find(Request $request, int $id): AlertRule
    {
        return $request->user()->alertRules()->with(self::WITH)->find($id) ?? abort(404);
    }
}
```

In `routes/api.php`, inside the `ability:app` group:

```php
        Route::get('/alert-rules', [AlertRuleController::class, 'index'])->name('api.v1.alert-rules.index');
        Route::post('/alert-rules', [AlertRuleController::class, 'store'])->name('api.v1.alert-rules.store');
        Route::get('/alert-rules/{id}', [AlertRuleController::class, 'show'])->whereNumber('id')->name('api.v1.alert-rules.show');
        Route::put('/alert-rules/{id}', [AlertRuleController::class, 'update'])->whereNumber('id')->name('api.v1.alert-rules.update');
        Route::patch('/alert-rules/{id}', [AlertRuleController::class, 'setActive'])->whereNumber('id')->name('api.v1.alert-rules.set-active');
        Route::delete('/alert-rules/{id}', [AlertRuleController::class, 'destroy'])->whereNumber('id')->name('api.v1.alert-rules.destroy');
```

- [ ] **Step 4: Run the endpoint tests and fix until they pass**

Run the container pest command on `tests/Feature/ApiAlertRulesTest.php`. Expected: PASS. Likely friction to fix in the code (not the tests): the `Rule::exists(...)->where(fn ...)` closure form; `delivery_time` returning `06:00:00`; `displayName()` loading relations lazily (the controller eager loads them).

- [ ] **Step 5: Write the parity test**

Create `tests/Feature/AlertRuleParityTest.php`:

```php
<?php

use App\Enums\UnitSystem;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

/*
 * The dashboard's create page and the API write alert rules through the same SaveAlertRule action.
 * This runs the same scenario through both for equivalent subscribers and asserts the stored rows
 * (and relations) are identical, in both unit systems, so the two front ends cannot drift.
 */

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $limits = app(TierThresholdSettings::class);
    $limits->lightning_min_radius_miles = 10;
    $limits->save();

    $boundary = DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)");
    DB::table('cwa_boundaries')->insert(['wfo' => 'OKX', 'cwa' => 'OKX', 'citystate' => 'New York, NY', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('counties')->insert(['state' => 'NY', 'cwa' => 'OKX', 'countyname' => 'Suffolk', 'fips' => '36103', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('public_zones')->insert(['state' => 'NY', 'cwa' => 'OKX', 'zone' => '072', 'name' => 'Suffolk', 'state_zone' => 'NY072', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);

    $this->tornado = Product::where('code', 'TO.W')->sole();
    $this->risk = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();
});

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>} the dashboard form state and the API body
 */
function parityPayloads($test, string $scenario, int $location, int $channel): array
{
    $tornado = $test->tornado;
    $risk = $test->risk;
    $levels = array_slice($risk->risk_level_options, 0, 2);
    $common = ['channel_ids' => [$channel]];

    return match ($scenario) {
        'threshold' => [$p = ['category' => 'threshold', 'variable' => 'wind_speed', 'direction' => 'above', 'threshold_value' => 40, 'timeframe' => 'day', 'location_id' => $location, 'delivery_time' => '06:30', 'name' => 'Windy', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00', ...$common], $p],
        'daily summary' => [$p = ['category' => 'daily_summary', 'location_id' => $location, 'delivery_time' => '07:00', ...$common], $p],
        'lightning' => [
            ['category' => 'lightning', 'location_id' => $location, 'radius_miles' => 24, ...$common],
            ['category' => 'lightning', 'location_id' => $location, 'radius' => 24, ...$common],
        ],
        'weather nationwide' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'nationwide', ...$common],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'nationwide', ...$common],
        ],
        'weather by location' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'location', 'location_ids' => [$location], ...$common],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'location', 'location_ids' => [$location], ...$common],
        ],
        'weather by office' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'wfo', 'wfo' => 'OKX', ...$common],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'wfo', 'wfo' => 'OKX', ...$common],
        ],
        'weather by county and zone' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'county_zone', 'county_zone_state' => 'NY', 'county_zone_codes' => ['NYC103', 'NYZ072'], ...$common],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'county_zone', 'county_zone_codes' => ['NYC103', 'NYZ072'], ...$common],
        ],
        'spc by risk area' => [
            ['category' => 'spc_wpc', 'product_ids' => [$risk->category->value => [$risk->id]], 'matching_mode' => 'risk_area', 'location_ids' => [$location], 'risk_levels' => [$risk->category->value => $levels], ...$common],
            ['category' => 'spc_wpc', 'product_ids' => [$risk->id], 'matching_mode' => 'risk_area', 'location_ids' => [$location], 'risk_levels' => $levels, ...$common],
        ],
    };
}

/**
 * The stored rule without its identity, as comparable data.
 *
 * @return array<string, mixed>
 */
function storedShape(AlertRule $rule): array
{
    $rule->refresh();

    return [
        ...Arr::except($rule->getAttributes(), ['id', 'user_id', 'location_id', 'created_at', 'updated_at']),
        'has_location' => $rule->location_id !== null,
        'products' => $rule->products->pluck('code')->sort()->values()->all(),
        'locations' => $rule->locations->count(),
        'channels' => $rule->notificationChannels->count(),
    ];
}

test('the dashboard and the API store the same rule', function (string $scenario, string $units) {
    $system = UnitSystem::from($units);

    $subscriber = function () use ($system): array {
        $user = User::factory()->create(['unit_system' => $system]);

        return [$user, Location::factory()->for($user)->create()->id, NotificationChannel::factory()->for($user)->create()->id];
    };

    [$formUser, $formLocation, $formChannel] = $subscriber();
    [$apiUser, $apiLocation, $apiChannel] = $subscriber();

    [$formState] = parityPayloads($this, $scenario, $formLocation, $formChannel);
    [, $apiBody] = parityPayloads($this, $scenario, $apiLocation, $apiChannel);

    $this->actingAs($formUser);
    livewire(CreateAlertRule::class)->fillForm($formState)->call('create')->assertHasNoFormErrors();
    $fromDashboard = AlertRule::where('user_id', $formUser->id)->sole();

    app('auth')->forgetGuards();
    $token = $apiUser->createToken('Pixel 9', ['app'])->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/alert-rules', $apiBody)->assertCreated();
    $fromApi = AlertRule::where('user_id', $apiUser->id)->sole();

    expect(storedShape($fromApi))->toEqual(storedShape($fromDashboard));
})->with([
    'threshold', 'daily summary', 'lightning', 'weather nationwide', 'weather by location',
    'weather by office', 'weather by county and zone', 'spc by risk area',
])->with(['imperial', 'metric']);
```

- [ ] **Step 6: Run the parity test**

Run the container pest command on `tests/Feature/AlertRuleParityTest.php`. Expected: PASS (16 cases). If the dashboard rejects a scenario's form state, open `tests/Feature/AlertRuleResourceTest.php`, find how it fills the same scenario and copy that shape into the form half only. A real difference in the stored rows is a bug in `SaveAlertRule` or the request: fix that, never weaken the comparison. (`storedShape()` compares the raw attributes including `risk_levels` JSON and `delivery_time`; if one front end yields `06:30` and the other `06:30:00` for the same stored time, that is a database-level difference to normalise inside `storedShape()` only, and say so in the report.)

- [ ] **Step 7: Run the rule suites together, format and commit**

Run the container pest command on `tests/Feature/ApiAlertRulesTest.php tests/Feature/AlertRuleParityTest.php tests/Feature/SaveAlertRuleTest.php tests/Feature/AlertRuleResourceTest.php tests/Feature/ApiAlertRuleOptionsTest.php`. Expected: PASS. Then:

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --dirty --format agent'
git add app routes tests
git commit -m "API: manage alert rules (list, show, create, replace, switch, delete) with dashboard parity tests

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Saved locations endpoints

**Files:**
- Create: `app/Actions/Locations/{CreateStationaryLocation,UpdateSavedLocation,DeleteSavedLocation}.php`, `app/Exceptions/{LocationInUse,LocationIsAPhone}.php`, `app/Http/Controllers/Api/V1/SavedLocationController.php`, `app/Http/Requests/{StoreSavedLocationRequest,UpdateSavedLocationRequest}.php`, `app/Http/Resources/Api/V1/SavedLocationResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/ApiSavedLocationsTest.php`

**Interfaces:**
- Consumes: `App\Exceptions\LocationLimitReached` (exists), `App\Support\LocationDeletionGuard::alertsUsing(Location)`, `App\Actions\Weather\ResolveTimezoneFromCoordinates`, `App\Support\Timezones::forUser`.
- Produces: routes `api.v1.locations.{index,show,store,update,destroy}`; the location JSON:

```
{id, label, type: 'stationary'|'mobile', source: 'traccar'|'app'|null, source_label, address, lat, lng, timezone,
 update_interval_minutes, linked: bool|null (app source only), device_name (Traccar only), has_missing_device,
 has_fix, last_fix_at, position_updated_at, stale: bool|null (mobile only)}
```
  with `GET /locations` carrying `meta: {can_create: bool, limit: int|null}`. Errors: 422 `{error: 'location_limit', message}` (create); 409 `{error: 'location_in_use', message, alerts: [names]}`; 409 `{error: 'location_is_a_phone', message}`.
- `POST /locations` body: `label`, `lat`, `lng`, optional `address` (max 255), optional `timezone`. `PUT /locations/{id}` body: stationary: `label` plus optional `lat`+`lng` (together), `address`, `timezone`; mobile: `label`, `update_interval_minutes` (5, 10, 15, 20, 25 or 30).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ApiSavedLocationsTest.php`:

```php
<?php

use App\Actions\Weather\ResolveTimezoneFromCoordinates;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 5;
    $limits->save();

    $this->user = User::factory()->create(['timezone' => 'America/Chicago']);
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
});

function locationCall($test, string $method, string $uri, array $body = [], ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->phone->plainTextToken)->json($method, $uri, $body);
}

test('the location endpoints need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/locations')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    locationCall($this, 'GET', '/api/v1/locations', [], $beacon)->assertForbidden();
});

test('the list is the subscriber\'s own locations by name with the limit information, and carries nothing internal', function () {
    $home = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $work = Location::factory()->for($this->user)->create(['label' => 'Work']);
    Location::factory()->create(['label' => 'Theirs']);

    $response = locationCall($this, 'GET', '/api/v1/locations')->assertOk();

    expect(collect($response->json('data'))->pluck('label')->all())->toBe(['Home', 'Work'])
        ->and($response->json('meta'))->toBe(['can_create' => true, 'limit' => 5])
        ->and(collect($response->json('data'))->firstWhere('id', $home->id))->toMatchArray(['type' => 'stationary', 'source' => null, 'linked' => null, 'stale' => null])
        ->and($response->getContent())->not->toContain('app_token_id')->not->toContain('traccar_server_id');
});

test('mobile locations say whether they are linked to a phone, whose device they follow and whether their position is stale', function () {
    $linked = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9', 'mobile_position_updated_at' => now()->subMinutes(5), 'mobile_position_fixed_at' => now()->subMinutes(5), 'lat' => 35.2, 'lng' => -97.4]);
    $lost = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'Old phone', 'mobile_position_updated_at' => now()->subHours(3), 'lat' => 35.2, 'lng' => -97.4]);
    // mobile() resolves its own user, so the owner goes in as an attribute (->for() would create a second user).
    $traccar = Location::factory()->mobile()->create(['user_id' => $this->user->id, 'label' => 'Truck', 'traccar_device_name' => 'F-150']);

    $byId = collect(locationCall($this, 'GET', '/api/v1/locations')->assertOk()->json('data'))->keyBy('id');

    expect($byId[$linked->id])->toMatchArray(['type' => 'mobile', 'source' => 'app', 'source_label' => 'WxNotify app', 'linked' => true, 'has_missing_device' => false, 'has_fix' => true, 'stale' => false])
        ->and($byId[$lost->id])->toMatchArray(['linked' => false, 'has_missing_device' => true, 'stale' => true])
        ->and($byId[$traccar->id])->toMatchArray(['source' => 'traccar', 'linked' => null, 'device_name' => 'F-150', 'has_fix' => false, 'stale' => true]);
});

test('another subscriber\'s location is a 404', function () {
    $theirs = Location::factory()->create();

    locationCall($this, 'GET', "/api/v1/locations/{$theirs->id}")->assertNotFound();
    locationCall($this, 'PUT', "/api/v1/locations/{$theirs->id}", ['label' => 'x'])->assertNotFound();
    locationCall($this, 'DELETE', "/api/v1/locations/{$theirs->id}")->assertNotFound();
});

test('a stationary location is created with the address and timezone it is given', function () {
    $response = locationCall($this, 'POST', '/api/v1/locations', [
        'label' => 'Cabin', 'lat' => 35.2226, 'lng' => -97.4395, 'address' => '1 Lake Road, Norman, Oklahoma', 'timezone' => 'America/Denver',
    ])->assertCreated();

    $location = Location::where('user_id', $this->user->id)->sole();

    expect($response->json('data.id'))->toBe($location->id)
        ->and($location->type)->toBe(LocationType::Stationary)
        ->and((float) $location->lat)->toBe(35.2226)
        ->and($location->address)->toBe('1 Lake Road, Norman, Oklahoma')
        ->and($location->timezone)->toBe('America/Denver');
});

test('without a timezone the server resolves it from the coordinates, then falls back to the profile timezone', function () {
    $this->mock(ResolveTimezoneFromCoordinates::class, fn ($mock) => $mock->shouldReceive('__invoke')->andReturn('America/New_York', null));

    locationCall($this, 'POST', '/api/v1/locations', ['label' => 'East', 'lat' => 40.7, 'lng' => -74.0])->assertCreated();
    locationCall($this, 'POST', '/api/v1/locations', ['label' => 'Offshore', 'lat' => 30.0, 'lng' => -60.0])->assertCreated();

    expect(Location::where('label', 'East')->sole()->timezone)->toBe('America/New_York')
        ->and(Location::where('label', 'Offshore')->sole()->timezone)->toBe('America/Chicago');
});

test('at the plan limit a new location is refused with location_limit and nothing is created', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();
    Location::factory()->for($this->user)->create();

    locationCall($this, 'POST', '/api/v1/locations', ['label' => 'One more', 'lat' => 35.0, 'lng' => -97.0])
        ->assertUnprocessable()->assertJsonPath('error', 'location_limit');

    expect(Location::where('user_id', $this->user->id)->count())->toBe(1);
});

test('a create is refused when the details are not valid', function (array $body, string $field) {
    locationCall($this, 'POST', '/api/v1/locations', [...['label' => 'Cabin', 'lat' => 35.2, 'lng' => -97.4], ...$body])
        ->assertUnprocessable()->assertJsonValidationErrorFor($field);
})->with([
    'no name' => [['label' => ''], 'label'],
    'a name that is too long' => [['label' => str_repeat('a', 256)], 'label'],
    'a latitude out of range' => [['lat' => 91], 'lat'],
    'a longitude out of range' => [['lng' => -181], 'lng'],
    'no latitude' => [['lat' => null], 'lat'],
    'an address that is too long' => [['address' => str_repeat('a', 256)], 'address'],
    'an unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
]);

test('a stationary location can be renamed, moved and given a new timezone, and a rename alone keeps its position', function () {
    $location = Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 35.0, 'lng' => -97.0, 'address' => 'Old', 'timezone' => 'America/Chicago']);

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'Home base'])->assertOk()->assertJsonPath('data.label', 'Home base');
    expect($location->fresh())->label->toBe('Home base')->address->toBe('Old');

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'Home base', 'lat' => 36.1, 'lng' => -96.2, 'address' => 'New place', 'timezone' => 'America/Denver'])->assertOk();

    $location->refresh();
    expect((float) $location->lat)->toBe(36.1)->and($location->address)->toBe('New place')->and($location->timezone)->toBe('America/Denver');

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'Home base', 'lat' => 36.1])->assertUnprocessable()->assertJsonValidationErrorFor('lng');
});

test('a mobile location changes only its name and update interval, and an app location stays linked to its phone', function () {
    $location = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4]);

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'My phone', 'update_interval_minutes' => 10, 'lat' => 1.0, 'lng' => 1.0, 'type' => 'stationary'])->assertOk();

    $location->refresh();
    expect($location->label)->toBe('My phone')
        ->and($location->mobile_update_interval_minutes)->toBe(10)
        ->and($location->type)->toBe(LocationType::Mobile)
        ->and($location->mobile_source)->toBe(MobileSource::App)
        ->and($location->app_token_id)->toBe($this->phone->accessToken->id)
        ->and((float) $location->lat)->toBe(35.2);

    locationCall($this, 'PUT', "/api/v1/locations/{$location->id}", ['label' => 'My phone', 'update_interval_minutes' => 7])->assertUnprocessable()->assertJsonValidationErrorFor('update_interval_minutes');
});

test('a location no alert uses is deleted', function () {
    $location = Location::factory()->for($this->user)->create();

    locationCall($this, 'DELETE', "/api/v1/locations/{$location->id}")->assertNoContent();

    expect(Location::find($location->id))->toBeNull();
});

test('a location an alert still uses is refused with the alert names and survives', function () {
    $location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    AlertRule::factory()->for($this->user)->create(['location_id' => $location->id, 'name' => 'Freeze warning']);
    $weather = AlertRule::factory()->for($this->user)->create(['location_id' => null, 'name' => 'Storm watch']);
    $weather->locations()->attach($location->id);

    $response = locationCall($this, 'DELETE', "/api/v1/locations/{$location->id}")->assertStatus(409);

    expect($response->json('error'))->toBe('location_in_use')
        ->and($response->json('alerts'))->toBe(['Freeze warning', 'Storm watch'])
        ->and($response->json('message'))->toContain('Home')->toContain('Freeze warning')
        ->and(Location::find($location->id))->not->toBeNull();
});

test('a location linked to a phone is refused, an unlinked app location and a Traccar one are deleted', function () {
    $linked = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create();
    $unlinked = Location::factory()->for($this->user)->mobileApp()->create();
    $traccar = Location::factory()->mobile()->create(['user_id' => $this->user->id]);

    locationCall($this, 'DELETE', "/api/v1/locations/{$linked->id}")->assertStatus(409)->assertJsonPath('error', 'location_is_a_phone');
    locationCall($this, 'DELETE', "/api/v1/locations/{$unlinked->id}")->assertNoContent();
    locationCall($this, 'DELETE', "/api/v1/locations/{$traccar->id}")->assertNoContent();

    expect(Location::find($linked->id))->not->toBeNull()
        ->and(Location::find($unlinked->id))->toBeNull()
        ->and(Location::find($traccar->id))->toBeNull();
});

test('writes are logged with the subscriber as the causer', function () {
    $id = locationCall($this, 'POST', '/api/v1/locations', ['label' => 'Cabin', 'lat' => 35.2, 'lng' => -97.4])->json('data.id');
    locationCall($this, 'PUT', "/api/v1/locations/{$id}", ['label' => 'Cabin 2']);
    locationCall($this, 'DELETE', "/api/v1/locations/{$id}");

    $causers = Activity::query()->where('subject_type', Location::class)->pluck('causer_id')->unique()->all();

    expect($causers)->toBe([$this->user->id]);
});

test('during maintenance a write answers 503 with the maintenance message as plain text', function () {
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = true;
    $settings->maintenance_message = 'Back soon.';
    $settings->save();

    $response = locationCall($this, 'POST', '/api/v1/locations', ['label' => 'Cabin', 'lat' => 35.2, 'lng' => -97.4]);

    $response->assertStatus(503);
    expect($response->getContent())->toBe('Back soon.')->and(Location::where('user_id', $this->user->id)->count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify they fail**

Run the container pest command on `tests/Feature/ApiSavedLocationsTest.php`. Expected: FAIL (404 on the routes).

- [ ] **Step 3: Write the exceptions, actions, requests, resource, controller and routes**

Create `app/Exceptions/LocationInUse.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The location is still used by alerts; deleting it would leave them with no location.
 */
class LocationInUse extends RuntimeException
{
    /**
     * @param  list<string>  $alertNames
     */
    public function __construct(public readonly array $alertNames)
    {
        parent::__construct('The location is used by alerts.');
    }
}
```

Create `app/Exceptions/LocationIsAPhone.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The location is kept current by a phone; sharing is turned off first, never by deleting the location.
 */
class LocationIsAPhone extends RuntimeException {}
```

Create `app/Actions/Locations/CreateStationaryLocation.php`:

```php
<?php

namespace App\Actions\Locations;

use App\Actions\Weather\ResolveTimezoneFromCoordinates;
use App\Enums\LocationType;
use App\Exceptions\LocationLimitReached;
use App\Models\Location;
use App\Models\User;
use App\Support\Timezones;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Adds a stationary location for the app. The address is whatever the app resolved (search or
 * reverse lookup); the timezone is the one sent, else the coordinates' own, else the profile's.
 */
class CreateStationaryLocation
{
    /**
     * @param  array{label: string, lat: float|int|string, lng: float|int|string, address?: ?string, timezone?: ?string}  $data
     *
     * @throws LocationLimitReached
     */
    public function __invoke(User $user, array $data): Location
    {
        if (! $user->canCreateLocation()) {
            throw new LocationLimitReached;
        }

        $timezone = filled($data['timezone'] ?? null)
            ? $data['timezone']
            : (app(ResolveTimezoneFromCoordinates::class)((float) $data['lat'], (float) $data['lng'])
                ?? Timezones::forUser($user)
                ?? config('app.timezone'));

        return app(CauserResolver::class)->withCauser($user, fn (): Location => Location::create([
            'user_id' => $user->id,
            'label' => $data['label'],
            'type' => LocationType::Stationary->value,
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'address' => filled($data['address'] ?? null) ? $data['address'] : null,
            'timezone' => $timezone,
        ]));
    }
}
```

Create `app/Actions/Locations/UpdateSavedLocation.php`:

```php
<?php

namespace App\Actions\Locations;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * What the app may change on a location: a stationary one's name, position, address and timezone;
 * a mobile one's name and update interval. Never the type, the source or any device link.
 */
class UpdateSavedLocation
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $user, Location $location, array $data): Location
    {
        $changes = ['label' => $data['label']];

        if ($location->type === LocationType::Mobile) {
            $changes['mobile_update_interval_minutes'] = (int) $data['update_interval_minutes'];
        } else {
            if (filled($data['timezone'] ?? null)) {
                $changes['timezone'] = $data['timezone'];
            }

            if (isset($data['lat'], $data['lng'])) {
                $changes += [
                    'lat' => $data['lat'],
                    'lng' => $data['lng'],
                    'address' => filled($data['address'] ?? null) ? $data['address'] : null,
                ];
            }
        }

        app(CauserResolver::class)->withCauser($user, fn () => $location->update($changes));

        return $location->refresh();
    }
}
```

Create `app/Actions/Locations/DeleteSavedLocation.php`:

```php
<?php

namespace App\Actions\Locations;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Exceptions\LocationInUse;
use App\Exceptions\LocationIsAPhone;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationDeletionGuard;
use App\Support\Units;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Deletes a location unless a phone keeps it current or an alert still uses it (the dashboard's
 * LocationDeletionGuard: deleting it would silently stop those alerts).
 */
class DeleteSavedLocation
{
    /**
     * @throws LocationIsAPhone
     * @throws LocationInUse
     */
    public function __invoke(User $user, Location $location): void
    {
        if ($location->type === LocationType::Mobile && $location->mobile_source === MobileSource::App && $location->app_token_id !== null) {
            throw new LocationIsAPhone;
        }

        $alerts = LocationDeletionGuard::alertsUsing($location);

        if ($alerts->isNotEmpty()) {
            $units = Units::for($user)->system;

            throw new LocationInUse($alerts->map(fn (AlertRule $alert): string => $alert->displayName($units))->values()->all());
        }

        app(CauserResolver::class)->withCauser($user, fn () => $location->delete());
    }
}
```

Create `app/Http/Requests/StoreSavedLocationRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSavedLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }
}
```

Create `app/Http/Requests/UpdateSavedLocationRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\LocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSavedLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A mobile location may only change its name and update interval; a stationary one its name,
     * position (latitude and longitude together), address and timezone. Anything else sent is ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $location = $this->user()->locations()->find($this->route('id')) ?? abort(404);

        if ($location->type === LocationType::Mobile) {
            return [
                'label' => ['required', 'string', 'max:255'],
                'update_interval_minutes' => ['required', Rule::in([5, 10, 15, 20, 25, 30])],
            ];
        }

        return [
            'label' => ['required', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }
}
```

Create `app/Http/Resources/Api/V1/SavedLocationResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Traccar\GetTraccarDeviceMap;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A location as the app's list and editor need it. Never carries the phone's token id, a Traccar
 * server or device id, or any credential.
 *
 * @property Location $resource
 */
class SavedLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = $this->resource;
        $mobile = $location->type === LocationType::Mobile;
        $updatedAt = $location->mobile_position_updated_at;

        return [
            'id' => $location->id,
            'label' => $location->label,
            'type' => $location->type->value,
            'source' => $location->mobile_source?->value,
            'source_label' => $location->mobile_source?->label(),
            'address' => $location->address,
            'lat' => $location->lat === null ? null : (float) $location->lat,
            'lng' => $location->lng === null ? null : (float) $location->lng,
            'timezone' => $location->timezone,
            'update_interval_minutes' => $location->mobile_update_interval_minutes,
            'linked' => $location->mobile_source === MobileSource::App ? $location->app_token_id !== null : null,
            'device_name' => $location->mobile_source === MobileSource::Traccar ? $location->traccar_device_name : null,
            'has_missing_device' => $location->hasMissingDevice(),
            'has_fix' => $location->hasFix(),
            'last_fix_at' => $mobile ? $location->mobile_position_fixed_at?->toIso8601String() : null,
            'position_updated_at' => $mobile ? $updatedAt?->toIso8601String() : null,
            'stale' => $mobile ? ($updatedAt === null || $updatedAt->lt(now()->subMinutes(GetTraccarDeviceMap::STALE_AFTER_MINUTES))) : null,
        ];
    }
}
```

Create `app/Http/Controllers/Api/V1/SavedLocationController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Locations\CreateStationaryLocation;
use App\Actions\Locations\DeleteSavedLocation;
use App\Actions\Locations\UpdateSavedLocation;
use App\Exceptions\LocationInUse;
use App\Exceptions\LocationIsAPhone;
use App\Exceptions\LocationLimitReached;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavedLocationRequest;
use App\Http\Requests\UpdateSavedLocationRequest;
use App\Http\Resources\Api\V1\SavedLocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SavedLocationController extends Controller
{
    private const NAMED_ALERTS = 5;

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        return SavedLocationResource::collection($user->locations()->orderBy('label')->orderBy('id')->get())
            ->additional(['meta' => ['can_create' => $user->canCreateLocation(), 'limit' => $user->locationLimit()]]);
    }

    public function show(Request $request, int $id): SavedLocationResource
    {
        return new SavedLocationResource($this->find($request, $id));
    }

    public function store(StoreSavedLocationRequest $request, CreateStationaryLocation $create): JsonResponse
    {
        try {
            $location = $create($request->user(), $request->validated());
        } catch (LocationLimitReached) {
            return response()->json([
                'error' => 'location_limit',
                'message' => 'Your plan\'s location limit is reached. Remove a location, or upgrade, to add another.',
            ], 422);
        }

        return (new SavedLocationResource($location))->response()->setStatusCode(201);
    }

    public function update(UpdateSavedLocationRequest $request, int $id, UpdateSavedLocation $update): SavedLocationResource
    {
        return new SavedLocationResource($update($request->user(), $this->find($request, $id), $request->validated()));
    }

    public function destroy(Request $request, int $id, DeleteSavedLocation $delete): Response|JsonResponse
    {
        $location = $this->find($request, $id);

        try {
            $delete($request->user(), $location);
        } catch (LocationIsAPhone) {
            return response()->json([
                'error' => 'location_is_a_phone',
                'message' => "{$location->label} follows a phone. Stop sharing location on that phone first (Account, in the app).",
            ], 409);
        } catch (LocationInUse $e) {
            $names = collect($e->alertNames);
            $listed = $names->take(self::NAMED_ALERTS)->implode(', ');
            $rest = $names->count() - self::NAMED_ALERTS;

            return response()->json([
                'error' => 'location_in_use',
                'message' => "Can't delete {$location->label}. It's used by: ".($rest > 0 ? "{$listed} (and {$rest} more)" : $listed).'. Remove it from those alerts first.',
                'alerts' => $names->all(),
            ], 409);
        }

        return response()->noContent();
    }

    private function find(Request $request, int $id): Location
    {
        return $request->user()->locations()->find($id) ?? abort(404);
    }
}
```

In `routes/api.php`, inside the `ability:app` group (note the existing singular `POST /location` beacon route in the other group is unaffected):

```php
        Route::get('/locations', [SavedLocationController::class, 'index'])->name('api.v1.locations.index');
        Route::post('/locations', [SavedLocationController::class, 'store'])->name('api.v1.locations.store');
        Route::get('/locations/{id}', [SavedLocationController::class, 'show'])->whereNumber('id')->name('api.v1.locations.show');
        Route::put('/locations/{id}', [SavedLocationController::class, 'update'])->whereNumber('id')->name('api.v1.locations.update');
        Route::delete('/locations/{id}', [SavedLocationController::class, 'destroy'])->whereNumber('id')->name('api.v1.locations.destroy');
```

- [ ] **Step 4: Run the tests and fix until they pass**

Run the container pest command on `tests/Feature/ApiSavedLocationsTest.php`. Expected: PASS. Notes: `Location` factory `mobile()` creates a Traccar server for the same user; `MobileSource::label()` exists; if `Timezones::forUser` returns null for a profile without a valid timezone the final fallback is `config('app.timezone')`.

- [ ] **Step 5: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --dirty --format agent'
git add app routes tests
git commit -m "API: manage saved locations (stationary create, edit, guarded delete)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Place search and reverse lookup

**Files:**
- Create: `app/Actions/Locations/SearchPlaces.php`, `app/Http/Controllers/Api/V1/PlaceController.php`, `app/Http/Requests/{SearchPlacesRequest,ReversePlaceRequest}.php`
- Modify: `app/Actions/Locations/ReverseGeocodeCoordinates.php` (make `RATE_LIMIT_KEY` public), `app/Providers/AppServiceProvider.php`, `routes/api.php`
- Test: `tests/Feature/ApiPlacesTest.php`

**Interfaces:**
- Consumes: `ReverseGeocodeCoordinates::RATE_LIMIT_KEY` (becomes public), `Integrations::endpoint(Integration::ReverseGeocoding)`, `HeartbeatUrlGuard::requestOptions()`.
- Produces: `GET /api/v1/places/search?q=` → `{data: [{label, lat, lng}]}` (at most 5; `[]` when none); `GET /api/v1/places/reverse?lat=&lng=` → `{data: {address: string|null}}`; errors 429 `{error: 'busy', message}` with `Retry-After: 1`, 502 `{error: 'unavailable', message}`. `SearchPlaces::__invoke(string $query): array{status: 'found'|'none'|'failed'|'throttled', places: list<array{label: string, lat: float, lng: float}>}`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ApiPlacesTest.php`:

```php
<?php

use App\Actions\Locations\ReverseGeocodeCoordinates;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    RateLimiter::clear(ReverseGeocodeCoordinates::RATE_LIMIT_KEY);
    config(['filament-pinpoint.leaflet.nominatim_url' => 'https://nominatim.example.test']);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Pixel 9', ['app'])->plainTextToken;
});

function placeCall($test, string $uri)
{
    app('auth')->forgetGuards();

    return $test->withToken($test->token)->getJson($uri);
}

function nominatimItems(int $count): array
{
    return collect(range(1, $count))->map(fn (int $i): array => [
        'display_name' => "{$i} Main Street, Norman, Oklahoma, United States",
        'lat' => '35.22'.$i,
        'lon' => '-97.43'.$i,
    ])->all();
}

test('the place endpoints need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/places/search?q=norman')->assertUnauthorized();
    $this->getJson('/api/v1/places/reverse?lat=35&lng=-97')->assertUnauthorized();
});

test('a search asks the configured Nominatim once and returns at most five places with numeric coordinates', function () {
    config(['app.name' => 'WxNotify', 'app.url' => 'https://wx.example.test']);
    Http::fake(['nominatim.example.test/*' => Http::response(nominatimItems(7))]);

    $response = placeCall($this, '/api/v1/places/search?q=Main+Street+Norman')->assertOk();

    expect($response->json('data'))->toHaveCount(5)
        ->and($response->json('data.0'))->toBe(['label' => '1 Main Street, Norman, Oklahoma, United States', 'lat' => 35.221, 'lng' => -97.431]);

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://nominatim.example.test/search?')
            && $query['q'] === 'Main Street Norman' && $query['format'] === 'jsonv2' && $query['limit'] === '5'
            && $request->header('User-Agent')[0] === 'WxNotify (+https://wx.example.test)'
            && $request->hasHeader('Authorization') === false;
    });
});

test('items without a name or a usable coordinate are skipped and an over-long name is cut to 255 characters', function () {
    Http::fake(['nominatim.example.test/*' => Http::response([
        ['display_name' => str_repeat('a', 400), 'lat' => '35.1', 'lon' => '-97.1'],
        ['lat' => '35.2', 'lon' => '-97.2'],
        ['display_name' => 'No longitude', 'lat' => '35.3'],
        'not an object',
    ])]);

    $places = placeCall($this, '/api/v1/places/search?q=anything')->assertOk()->json('data');

    expect($places)->toHaveCount(1)->and(mb_strlen($places[0]['label']))->toBe(255);
});

test('no match is an empty list and the same search is answered from the cache', function () {
    Http::fake(['nominatim.example.test/*' => Http::response([])]);

    expect(placeCall($this, '/api/v1/places/search?q=nowhere')->assertOk()->json('data'))->toBe([]);
    expect(placeCall($this, '/api/v1/places/search?q=NOWHERE')->assertOk()->json('data'))->toBe([]);

    Http::assertSentCount(1);
});

test('a spent rate limit answers 429 with Retry-After and sends nothing', function () {
    RateLimiter::attempt(ReverseGeocodeCoordinates::RATE_LIMIT_KEY, 1, fn () => true, 60);
    Http::fake();

    placeCall($this, '/api/v1/places/search?q=norman')->assertStatus(429)->assertJsonPath('error', 'busy')->assertHeader('Retry-After', '1');

    Http::assertNothingSent();
});

test('a failing or refused geocoder answers 502', function () {
    Http::fake(['nominatim.example.test/*' => Http::response('boom', 500)]);

    placeCall($this, '/api/v1/places/search?q=norman')->assertStatus(502)->assertJsonPath('error', 'unavailable');

    // The cloud metadata address is refused by the URL guard before anything is sent (stray requests would throw).
    config(['filament-pinpoint.leaflet.nominatim_url' => 'http://169.254.169.254']);

    placeCall($this, '/api/v1/places/search?q=other')->assertStatus(502);
    Http::assertSentCount(1);
});

test('the search text is never logged or put in the activity log', function () {
    Log::spy();
    Http::fake(['nominatim.example.test/*' => Http::response('boom', 500)]);
    $activityBefore = Activity::count();

    placeCall($this, '/api/v1/places/search?q=Hidden+Query+Road')->assertStatus(502);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ! str_contains($message.json_encode($context), 'Hidden'));
    expect(Activity::count())->toBe($activityBefore);
});

test('a search needs at least three characters', function () {
    placeCall($this, '/api/v1/places/search')->assertUnprocessable()->assertJsonValidationErrorFor('q');
    placeCall($this, '/api/v1/places/search?q=ab')->assertUnprocessable()->assertJsonValidationErrorFor('q');
    placeCall($this, '/api/v1/places/search?q='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrorFor('q');
});

test('a reverse lookup returns the address, or null when there is none', function () {
    Http::fake(['nominatim.example.test/*' => Http::sequence()
        ->push(['display_name' => '1 Main Street, Norman, Oklahoma'])
        ->push(['error' => 'Unable to geocode'])]);

    expect(placeCall($this, '/api/v1/places/reverse?lat=35.2&lng=-97.4')->assertOk()->json('data'))->toBe(['address' => '1 Main Street, Norman, Oklahoma']);

    RateLimiter::clear(ReverseGeocodeCoordinates::RATE_LIMIT_KEY);

    expect(placeCall($this, '/api/v1/places/reverse?lat=1&lng=1')->assertOk()->json('data'))->toBe(['address' => null]);
});

test('a reverse lookup that is throttled or fails answers like a search, and bad coordinates are refused', function () {
    RateLimiter::attempt(ReverseGeocodeCoordinates::RATE_LIMIT_KEY, 1, fn () => true, 60);

    // Nothing is faked yet: a request sent while throttled would throw as a stray request.
    placeCall($this, '/api/v1/places/reverse?lat=35.2&lng=-97.4')->assertStatus(429)->assertJsonPath('error', 'busy');

    RateLimiter::clear(ReverseGeocodeCoordinates::RATE_LIMIT_KEY);
    Http::fake(['nominatim.example.test/*' => Http::response('boom', 500)]);

    placeCall($this, '/api/v1/places/reverse?lat=35.3&lng=-97.4')->assertStatus(502);
    placeCall($this, '/api/v1/places/reverse?lat=91&lng=0')->assertUnprocessable()->assertJsonValidationErrorFor('lat');
    placeCall($this, '/api/v1/places/reverse?lat=0&lng=181')->assertUnprocessable()->assertJsonValidationErrorFor('lng');
});

test('the place endpoints have their own per-token limit', function () {
    Http::fake(['nominatim.example.test/*' => Http::response(nominatimItems(1))]);

    foreach (range(1, 20) as $i) {
        placeCall($this, '/api/v1/places/search?q=norman')->assertOk();
    }

    placeCall($this, '/api/v1/places/search?q=norman')->assertStatus(429);
});
```

- [ ] **Step 2: Run to verify they fail**

Run the container pest command on `tests/Feature/ApiPlacesTest.php`. Expected: FAIL (`RATE_LIMIT_KEY` is private, then 404 on the routes).

- [ ] **Step 3: Write the search action, controller, requests, limiter and routes**

In `app/Actions/Locations/ReverseGeocodeCoordinates.php` change `private const RATE_LIMIT_KEY = 'reverse-geocode';` to `public const RATE_LIMIT_KEY = 'reverse-geocode';` (forward and reverse lookups share OSM's one-request-per-second budget).

Create `app/Actions/Locations/SearchPlaces.php`:

```php
<?php

namespace App\Actions\Locations;

use App\Enums\Integration;
use App\Support\Delivery\HeartbeatUrlGuard;
use App\Support\Integrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Forward geocoding for the app's stationary-location picker, against the same Nominatim as the
 * reverse lookups (the admin-set endpoint or the configured URL) and under the same rules: one
 * request per second app-wide (sharing ReverseGeocodeCoordinates's budget, as OSM's policy counts
 * every request), an identifying User-Agent, the endpoint vetted and pinned by HeartbeatUrlGuard,
 * no redirects, answers cached. Never logs the query text; the cache key hashes it.
 *
 * Statuses: `found`, `none` (nothing matches), `failed` (could not ask or got no usable answer)
 * and `throttled` (the rate limit is spent: retry in a moment).
 */
class SearchPlaces
{
    public const CACHE_DAYS = 7;

    public const MAX_RESULTS = 5;

    private const LABEL_MAX_LENGTH = 255;

    /**
     * @return array{status: 'found'|'none'|'failed'|'throttled', places: list<array{label: string, lat: float, lng: float}>}
     */
    public function __invoke(string $query): array
    {
        $base = rtrim((string) (Integrations::endpoint(Integration::ReverseGeocoding) ?? config('filament-pinpoint.leaflet.nominatim_url')), '/');
        $text = trim((string) preg_replace('/\s+/', ' ', $query));
        $cacheKey = 'forward-geocode:'.substr(sha1($base), 0, 12).':'.sha1(mb_strtolower($text));

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $pin = app(HeartbeatUrlGuard::class)->requestOptions($base);

        if ($pin === null) {
            Log::warning('Forward geocoding endpoint refused');

            return $this->result('failed');
        }

        if (! RateLimiter::attempt(ReverseGeocodeCoordinates::RATE_LIMIT_KEY, 1, fn (): bool => true, 1)) {
            return $this->result('throttled');
        }

        try {
            $response = Http::withHeaders(['User-Agent' => config('app.name').' (+'.config('app.url').')'])
                ->acceptJson()
                ->timeout(5)
                ->connectTimeout(3)
                ->withOptions(['allow_redirects' => false, ...$pin])
                ->get("{$base}/search", [
                    'format' => 'jsonv2',
                    'q' => $text,
                    'limit' => self::MAX_RESULTS,
                    'addressdetails' => 0,
                    'accept-language' => 'en',
                ]);
        } catch (Throwable) {
            Log::warning('Forward geocoding request failed');

            return $this->result('failed');
        }

        if (! $response->successful()) {
            Log::warning('Forward geocoding endpoint returned an error', ['status' => $response->status()]);

            return $this->result('failed');
        }

        $body = $response->json();

        if (! is_array($body) || ! array_is_list($body)) {
            Log::warning('Forward geocoding endpoint returned an unreadable response');

            return $this->result('failed');
        }

        $places = collect($body)
            ->map(fn (mixed $item): ?array => $this->place($item))
            ->filter()
            ->take(self::MAX_RESULTS)
            ->values()
            ->all();

        $result = $this->result($places === [] ? 'none' : 'found', $places);

        Cache::put($cacheKey, $result, now()->addDays(self::CACHE_DAYS));

        return $result;
    }

    /**
     * @return array{label: string, lat: float, lng: float}|null
     */
    private function place(mixed $item): ?array
    {
        if (! is_array($item) || ! is_string($item['display_name'] ?? null) || trim($item['display_name']) === '' || ! is_numeric($item['lat'] ?? null) || ! is_numeric($item['lon'] ?? null)) {
            return null;
        }

        return [
            'label' => mb_substr(trim($item['display_name']), 0, self::LABEL_MAX_LENGTH),
            'lat' => (float) $item['lat'],
            'lng' => (float) $item['lon'],
        ];
    }

    /**
     * @param  list<array{label: string, lat: float, lng: float}>  $places
     * @return array{status: 'found'|'none'|'failed'|'throttled', places: list<array{label: string, lat: float, lng: float}>}
     */
    private function result(string $status, array $places = []): array
    {
        return ['status' => $status, 'places' => $places];
    }
}
```

Create `app/Http/Requests/SearchPlacesRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchPlacesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['q' => ['required', 'string', 'min:3', 'max:100']];
    }
}
```

Create `app/Http/Requests/ReversePlaceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReversePlaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
```

Create `app/Http/Controllers/Api/V1/PlaceController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Locations\ReverseGeocodeCoordinates;
use App\Actions\Locations\SearchPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReversePlaceRequest;
use App\Http\Requests\SearchPlacesRequest;
use Illuminate\Http\JsonResponse;

/**
 * The picker's two lookups. Nothing is logged or stored: the query text and coordinates are the
 * subscriber's own place and stay out of the log and the activity log.
 */
class PlaceController extends Controller
{
    public function search(SearchPlacesRequest $request, SearchPlaces $search): JsonResponse
    {
        $result = $search($request->validated('q'));

        return $this->refusal($result['status']) ?? response()->json(['data' => $result['places']]);
    }

    public function reverse(ReversePlaceRequest $request, ReverseGeocodeCoordinates $reverse): JsonResponse
    {
        $result = $reverse((float) $request->validated('lat'), (float) $request->validated('lng'));

        return $this->refusal($result['status']) ?? response()->json(['data' => ['address' => $result['address']]]);
    }

    private function refusal(string $status): ?JsonResponse
    {
        return match ($status) {
            'throttled' => response()->json(['error' => 'busy', 'message' => 'Search is busy. Try again in a moment.'], 429)->header('Retry-After', '1'),
            'failed' => response()->json(['error' => 'unavailable', 'message' => 'Address lookup is unavailable right now.'], 502),
            default => null,
        };
    }
}
```

In `app/Providers/AppServiceProvider.php`, beside the other limiters:

```php
        // Place search and reverse lookup are for a person tapping a button; the geocoder itself is
        // limited to one request per second app-wide, so a runaway client must hit this first.
        RateLimiter::for('app-places', fn (Request $request) => Limit::perMinute(20)
            ->by('app-places:'.($request->user()?->currentAccessToken()?->id ?? $request->ip())));
```

In `routes/api.php`, inside the `ability:app` group:

```php
        Route::get('/places/search', [PlaceController::class, 'search'])->middleware('throttle:app-places')->name('api.v1.places.search');
        Route::get('/places/reverse', [PlaceController::class, 'reverse'])->middleware('throttle:app-places')->name('api.v1.places.reverse');
```

- [ ] **Step 4: Run the tests and fix until they pass**

Run the container pest command on `tests/Feature/ApiPlacesTest.php tests/Feature/ReverseGeocodeCoordinatesTest.php`. Expected: PASS (the second file proves making the constant public changed nothing). If the HeartbeatUrlGuard refuses `nominatim.example.test` in this environment, copy exactly how `ReverseGeocodeCoordinatesTest` makes the host acceptable and do the same.

- [ ] **Step 5: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --dirty --format agent'
git add app routes tests
git commit -m "API: place search and reverse lookup for the app's stationary location picker

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Backend rules and the full backend suite

**Files:**
- Modify: `.ai/rules/api.md`, `.ai/rules/actions.md`

- [ ] **Step 1: Record the rules**

Append to `.ai/rules/api.md`:

```markdown

## Alert rule and location writes share the dashboard's logic and speak the subscriber's units
`POST/PUT /api/v1/alert-rules` persist through `SaveAlertRule` (the same action the dashboard's Create and Edit pages call), so units, SPC risk-level flattening, clearing of columns another category owns, and the product/location/channel syncs cannot drift between the two front ends; `AlertRuleParityTest` runs the same scenarios through both. The API takes and returns `threshold_value` and `radius` in the subscriber's own unit system (`AlertRuleResource` converts with `AlertRuleFormUnits::toDisplay`, the request converts back through the action). Validation (`SaveAlertRuleRequest`) mirrors the form per category and re-checks every id against the user. `DELETE /locations/{id}` honours `LocationDeletionGuard` (409 `location_in_use`, with the alert names as plain strings, not the guard's HTML-escaped summary) and never removes a location a phone keeps current (409 `location_is_a_phone`; unlink by stopping sharing). Maintenance mode answers a write with 503 and a plain-text body (`MaintenanceModeException::render`), not JSON.

## Place search never logs or stores what was searched
`GET /places/search` and `/places/reverse` log nothing about the query or coordinates, write no activity row and cache by a hash; the geocoder shares one app-wide one-request-per-second budget (`ReverseGeocodeCoordinates::RATE_LIMIT_KEY`) between forward and reverse lookups, and the endpoint goes through `HeartbeatUrlGuard` like every other staff-set URL.
```

Append to `.ai/rules/actions.md`:

```markdown

## Alert rule persistence lives in SaveAlertRule
`CreateAlertRule` and `EditAlertRule` (dashboard) and `AlertRuleController` (API) all persist through `App\Actions\Alerts\SaveAlertRule` (`attributes()` for the columns, `syncRelations()` for the pivots). A change to what a rule save does belongs in that action, not in a Filament page hook or a controller, and needs a case in `AlertRuleParityTest`.
```

- [ ] **Step 2: Run the full backend suite in parallel**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'` (about 3-4 minutes). Expected: all PASS. Fix any failure the new code caused (a dashboard test that asserted a stale column after a category change is plan decision 4: update it to the cleared value and say so). Report the count.

- [ ] **Step 3: Format and commit**

```bash
docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --dirty --format agent'
git add .ai/rules
git commit -m "Docs: record the rule and location API rules

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## App (`mobile/`)

Conventions for every app task: run tests from `mobile/` with `vendor/bin/pest --compact` (add a file path to narrow); screens are `NativeComponent` classes in `app/NativeComponents` with a kebab-case Blade view in `resources/views/native` (class `RulesScreen` → `rules-screen.blade.php`); read a route parameter with `$this->param('id')`; screen tests use `Native::test(Screen::class, [...params])` from `Native\Mobile\Testing\Native` with `assertSee`, `tap('Label or ref')`, `input('ref', 'text')`, `toggle('ref', bool)`, `check('ref', bool)`, `select('ref', 'label')`, `set('property', value)`, `call('method', ...$args)`, `assertSet`, `assertElement('type', fn)`, `assertReplacedWith('/path')`, `assertNavigatedTo('/path')`, `pressBack()`, `tree()`/`dumpTree()`; a signed-in test starts with `(new TokenStore)->put('9|secret')`; HTTP is faked with `Http::fake(['wx.test/api/v1/...' => Http::response(...)])` (stray requests fail); `$this->network->connected = false` makes the app offline. Use `ref="..."` attributes on elements you drive from tests.

### Task 7: API client, error types and cache for rules and locations

**Files:**
- Create: `app/Exceptions/Api/{ValidationFailed,Conflict,Maintenance}.php`
- Modify: `app/Services/WxNotifyApi.php`, `app/Support/LocalCache.php`
- Test: `tests/Feature/WxNotifyApiManagementTest.php`, `tests/Feature/LocalCacheManagementTest.php`

**Interfaces:**
- Produces (Tasks 8-12 rely on these exact signatures):
  - `ValidationFailed` (`public readonly array $errors`: field => first message, nested keys reduced to their root, e.g. `channel_ids.0` → `channel_ids`; `getMessage()` is the server's summary), `Conflict` (`public readonly string $reason`, `public readonly array $alerts` (list of names); `getMessage()` is the server's text), `Maintenance` (`getMessage()` is the maintenance text; empty server text becomes a default). All extend `ApiException`.
  - `WxNotifyApi`: `alertRules(): array` (list), `alertRule(int $id): array`, `saveAlertRule(?int $id, array $payload): array`, `setAlertRuleActive(int $id, bool $active): array`, `deleteAlertRule(int $id): void`, `alertRuleOptions(): array`, `nwsOffices(): array` (list of `{code, label}`), `zones(?string $state, ?string $query, ?string $after = null): array{data: array, next_after: ?string}`, `locations(): array{data: array, meta: array}`, `saveLocation(?int $id, array $payload): array`, `deleteLocation(int $id): void`, `searchPlaces(string $query): array` (list of `{label, lat, lng}`), `reversePlace(float $lat, float $lng): ?string`.
  - `LocalCache`: `rememberRules(array $rules): void`, `rules(): ?array`, `rememberLocations(array $locations, array $meta): void`, `locations(): ?array`, `locationsMeta(): ?array`, `rememberOptions(array $options): void`, `options(): ?array`; `wipe()` forgets all of them.

- [ ] **Step 1: Write the failing client and cache tests**

Create `tests/Feature/WxNotifyApiManagementTest.php`:

```php
<?php

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Conflict;
use App\Exceptions\Api\LocationLimitReached;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

function wxApi(): WxNotifyApi
{
    return app(WxNotifyApi::class);
}

test('the rule calls hit the right routes with the bearer token', function () {
    Http::fake([
        'wx.test/api/v1/alert-rules' => Http::response(['data' => [['id' => 1]]]),
        'wx.test/api/v1/alert-rules/1' => Http::response(['data' => ['id' => 1, 'is_active' => false]]),
        'wx.test/api/v1/alert-rule-options' => Http::response(['data' => ['categories' => []]]),
    ]);

    expect(wxApi()->alertRules())->toBe([['id' => 1]])
        ->and(wxApi()->alertRule(1))->toBe(['id' => 1, 'is_active' => false])
        ->and(wxApi()->setAlertRuleActive(1, false))->toBe(['id' => 1, 'is_active' => false])
        ->and(wxApi()->alertRuleOptions())->toBe(['categories' => []]);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === 'https://wx.test/api/v1/alert-rules/1' && $r['is_active'] === false && $r->hasHeader('Authorization', 'Bearer 9|secret'));
});

test('saving a rule creates with POST and replaces with PUT', function () {
    Http::fake(['wx.test/api/v1/alert-rules*' => Http::response(['data' => ['id' => 4]])]);

    wxApi()->saveAlertRule(null, ['category' => 'threshold']);
    wxApi()->saveAlertRule(4, ['category' => 'lightning']);
    wxApi()->deleteAlertRule(4);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/alert-rules' && $r['category'] === 'threshold');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/alert-rules/4' && $r['category'] === 'lightning');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/alert-rules/4');
});

test('the location and lookup calls hit the right routes', function () {
    Http::fake([
        'wx.test/api/v1/locations' => Http::response(['data' => [['id' => 2]], 'meta' => ['can_create' => true, 'limit' => 5]]),
        'wx.test/api/v1/locations/2' => Http::response(['data' => ['id' => 2, 'label' => 'Cabin']]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => [['code' => 'OKX', 'label' => 'OKX — New York, NY']]]),
        'wx.test/api/v1/zones*' => Http::response(['data' => [['code' => 'NYC103', 'label' => 'x']], 'meta' => ['next_after' => 'NYC103']]),
        'wx.test/api/v1/places/search*' => Http::response(['data' => [['label' => 'A', 'lat' => 35.2, 'lng' => -97.4]]]),
        'wx.test/api/v1/places/reverse*' => Http::response(['data' => ['address' => '1 Main Street']]),
    ]);

    expect(wxApi()->locations())->toBe(['data' => [['id' => 2]], 'meta' => ['can_create' => true, 'limit' => 5]])
        ->and(wxApi()->saveLocation(2, ['label' => 'Cabin']))->toBe(['id' => 2, 'label' => 'Cabin'])
        ->and(wxApi()->nwsOffices())->toBe([['code' => 'OKX', 'label' => 'OKX — New York, NY']])
        ->and(wxApi()->zones('NY', 'suffolk', 'NYC059'))->toBe(['data' => [['code' => 'NYC103', 'label' => 'x']], 'next_after' => 'NYC103'])
        ->and(wxApi()->searchPlaces('main street'))->toBe([['label' => 'A', 'lat' => 35.2, 'lng' => -97.4]])
        ->and(wxApi()->reversePlace(35.2, -97.4))->toBe('1 Main Street');

    wxApi()->saveLocation(null, ['label' => 'New']);
    wxApi()->deleteLocation(2);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/locations');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/locations/2');
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://wx.test/api/v1/zones?') && str_contains($r->url(), 'state=NY') && str_contains($r->url(), 'q=suffolk') && str_contains($r->url(), 'after=NYC059'));
});

test('a validation answer becomes the first message per field, with nested keys reduced to their root', function () {
    Http::fake(['*' => Http::response([
        'message' => 'The given data was invalid.',
        'errors' => ['channel_ids.0' => ['The selected channel ids.0 is invalid.', 'second'], 'channel_ids' => ['Pick one.'], 'threshold_value' => ['Required.']],
    ], 422)]);

    try {
        wxApi()->saveAlertRule(null, []);
        $this->fail('Expected ValidationFailed');
    } catch (ValidationFailed $e) {
        expect($e->errors)->toBe(['channel_ids' => 'The selected channel ids.0 is invalid.', 'threshold_value' => 'Required.'])
            ->and($e->getMessage())->toBe('The given data was invalid.');
    }
});

test('a conflict carries its reason, message and the alert names', function () {
    Http::fake(['*' => Http::response(['error' => 'location_in_use', 'message' => "Can't delete Home.", 'alerts' => ['Freeze', 'Storm']], 409)]);

    try {
        wxApi()->deleteLocation(1);
        $this->fail('Expected Conflict');
    } catch (Conflict $e) {
        expect($e->reason)->toBe('location_in_use')->and($e->getMessage())->toBe("Can't delete Home.")->and($e->alerts)->toBe(['Freeze', 'Storm']);
    }
});

test('a plain-text 503 is maintenance with the server\'s words, not a retryable error', function () {
    Http::fake(['*' => Http::sequence()
        ->push('Back soon.', 503, ['Content-Type' => 'text/plain; charset=UTF-8'])
        ->push('', 503, ['Content-Type' => 'text/plain'])
        ->push(['message' => 'Server Error'], 503)]);

    expect(fn () => wxApi()->saveAlertRule(null, []))->toThrow(Maintenance::class, 'Back soon.')
        ->and(fn () => wxApi()->saveAlertRule(null, []))->toThrow(Maintenance::class, 'WxNotify is down for maintenance.')
        ->and(fn () => wxApi()->saveAlertRule(null, []))->toThrow(Retryable::class);
});

test('the existing failure mapping still holds', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::sequence()
        ->push([], 401)
        ->push([], 502)
        ->push(['error' => 'location_limit'], 422)]);

    expect(fn () => wxApi()->alertRules())->toThrow(Unauthenticated::class)
        ->and(fn () => wxApi()->alertRules())->toThrow(Retryable::class)
        ->and(fn () => wxApi()->alertRules())->toThrow(LocationLimitReached::class);

    $this->network->connected = false;

    expect(fn () => wxApi()->alertRules())->toThrow(Offline::class);
});

test('a response without the data wrapper is a transient failure', function () {
    Http::fake(['*' => Http::response(['unexpected' => true])]);

    expect(fn () => wxApi()->alertRules())->toThrow(Retryable::class)
        ->and(fn () => wxApi()->alertRules())->toThrow(ApiException::class);
});
```

Create `tests/Feature/LocalCacheManagementTest.php`:

```php
<?php

use App\Support\LocalCache;

test('rules, locations and the form options are remembered and read back', function () {
    $cache = new LocalCache;

    expect($cache->rules())->toBeNull()->and($cache->locations())->toBeNull()->and($cache->locationsMeta())->toBeNull()->and($cache->options())->toBeNull();

    $cache->rememberRules([['id' => 1, 'display_name' => 'Freeze']]);
    $cache->rememberLocations([['id' => 2, 'label' => 'Home']], ['can_create' => false, 'limit' => 1]);
    $cache->rememberOptions(['categories' => [['value' => 'threshold']]]);

    expect($cache->rules())->toBe([['id' => 1, 'display_name' => 'Freeze']])
        ->and($cache->locations())->toBe([['id' => 2, 'label' => 'Home']])
        ->and($cache->locationsMeta())->toBe(['can_create' => false, 'limit' => 1])
        ->and($cache->options())->toBe(['categories' => [['value' => 'threshold']]]);
});

test('an empty list is remembered as empty, not as never fetched', function () {
    $cache = new LocalCache;
    $cache->rememberRules([]);

    expect($cache->rules())->toBe([]);
});

test('wiping forgets them all', function () {
    $cache = new LocalCache;
    $cache->rememberRules([['id' => 1]]);
    $cache->rememberLocations([['id' => 2]], ['can_create' => true, 'limit' => null]);
    $cache->rememberOptions(['categories' => []]);

    $cache->wipe();

    expect($cache->rules())->toBeNull()->and($cache->locations())->toBeNull()->and($cache->locationsMeta())->toBeNull()->and($cache->options())->toBeNull();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/WxNotifyApiManagementTest.php tests/Feature/LocalCacheManagementTest.php`. Expected: FAIL (classes and methods do not exist).

- [ ] **Step 3: Write the exceptions, client methods and cache methods**

Create `app/Exceptions/Api/ValidationFailed.php`:

```php
<?php

namespace App\Exceptions\Api;

/**
 * The server refused the details (422). `errors` maps each field to its first message.
 */
final class ValidationFailed extends ApiException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors, string $message = '')
    {
        parent::__construct($message);
    }
}
```

Create `app/Exceptions/Api/Conflict.php`:

```php
<?php

namespace App\Exceptions\Api;

/**
 * The server refused the change because of the current state (409): a location still used by
 * alerts, or one that follows a phone. `reason` is the server's error code, `getMessage()` its text.
 */
final class Conflict extends ApiException
{
    /**
     * @param  list<string>  $alerts
     */
    public function __construct(public readonly string $reason, string $message = '', public readonly array $alerts = [])
    {
        parent::__construct($message);
    }
}
```

Create `app/Exceptions/Api/Maintenance.php`:

```php
<?php

namespace App\Exceptions\Api;

/**
 * WxNotify is read-only for maintenance (a plain-text 503 on a write); the message is the server's.
 */
final class Maintenance extends ApiException {}
```

In `app/Services/WxNotifyApi.php` add the `use` lines for the new exceptions and `Illuminate\Support\Str`, add these methods before `send()`:

```php
    /**
     * @return array<int, array<string, mixed>>
     */
    public function alertRules(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/alert-rules')));
    }

    /**
     * @return array<string, mixed>
     */
    public function alertRule(int $id): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get("/api/v1/alert-rules/{$id}")));
    }

    /**
     * Creates a rule (null id) or replaces one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationFailed
     * @throws Maintenance
     */
    public function saveAlertRule(?int $id, array $payload): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $id === null
            ? $request->post('/api/v1/alert-rules', $payload)
            : $request->put("/api/v1/alert-rules/{$id}", $payload)));
    }

    /**
     * @return array<string, mixed>
     */
    public function setAlertRuleActive(int $id, bool $active): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->patch("/api/v1/alert-rules/{$id}", ['is_active' => $active])));
    }

    public function deleteAlertRule(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete("/api/v1/alert-rules/{$id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function alertRuleOptions(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/alert-rule-options')));
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    public function nwsOffices(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/nws-offices')));
    }

    /**
     * @return array{data: array<int, array{code: string, label: string}>, next_after: ?string}
     */
    public function zones(?string $state, ?string $query, ?string $after = null): array
    {
        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/zones', array_filter(
            ['state' => $state, 'q' => $query, 'after' => $after],
            fn ($value) => $value !== null && $value !== '',
        )));

        return ['data' => $this->data($body), 'next_after' => $body['meta']['next_after'] ?? null];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function locations(): array
    {
        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/locations'));

        return ['data' => $this->data($body), 'meta' => is_array($body['meta'] ?? null) ? $body['meta'] : []];
    }

    /**
     * Creates a stationary location (null id) or edits one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws LocationLimitReached
     * @throws ValidationFailed
     */
    public function saveLocation(?int $id, array $payload): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $id === null
            ? $request->post('/api/v1/locations', $payload)
            : $request->put("/api/v1/locations/{$id}", $payload)));
    }

    /**
     * @throws Conflict when alerts still use the location or it follows a phone
     */
    public function deleteLocation(int $id): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete("/api/v1/locations/{$id}"));
    }

    /**
     * @return array<int, array{label: string, lat: float, lng: float}>
     */
    public function searchPlaces(string $query): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/places/search', ['q' => $query])));
    }

    public function reversePlace(float $lat, float $lng): ?string
    {
        $data = $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/places/reverse', ['lat' => $lat, 'lng' => $lng])));

        return is_string($data['address'] ?? null) ? $data['address'] : null;
    }
```

Replace the `match` in `send()` with (the 503 and the new 422/409 arms are the change; keep the existing arms' meaning):

```php
        return match (true) {
            $response->successful() => $this->decode($response),
            $response->status() === 401 => throw new Unauthenticated,
            $response->status() === 503 && str_starts_with((string) $response->header('Content-Type'), 'text/plain') => throw new Maintenance(trim($response->body()) ?: 'WxNotify is down for maintenance.'),
            $response->status() === 429, $response->serverError() => throw new Retryable,
            $response->status() === 422 && $response->json('error') === 'location_limit' => throw new LocationLimitReached,
            $response->status() === 422 && is_array($response->json('errors')) => throw new ValidationFailed($this->firstMessages($response->json('errors')), (string) $response->json('message', '')),
            $response->status() === 409 => throw new Conflict((string) $response->json('error', ''), (string) $response->json('message', ''), array_values((array) $response->json('alerts', []))),
            default => throw new Rejected,
        };
```

and add the helper:

```php
    /**
     * The first message of each field; a nested key such as `channel_ids.0` is reported against
     * its root field, and the first message seen for a field wins.
     *
     * @param  array<string, mixed>  $errors
     * @return array<string, string>
     */
    private function firstMessages(array $errors): array
    {
        $messages = [];

        foreach ($errors as $key => $value) {
            $field = Str::before((string) $key, '.');
            $messages[$field] ??= (string) (is_array($value) ? ($value[0] ?? '') : $value);
        }

        return $messages;
    }
```

In `app/Support/LocalCache.php` add constants and methods (and extend `wipe()`):

```php
    private const RULES = 'wxnotify.rules';

    private const LOCATIONS = 'wxnotify.locations';

    private const LOCATIONS_META = 'wxnotify.locations.meta';

    private const OPTIONS = 'wxnotify.rule_options';

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function rememberRules(array $rules): void
    {
        Cache::forever(self::RULES, $rules);
    }

    /**
     * @return array<int, array<string, mixed>>|null null until the list was fetched once
     */
    public function rules(): ?array
    {
        return Cache::get(self::RULES);
    }

    /**
     * @param  array<int, array<string, mixed>>  $locations
     * @param  array<string, mixed>  $meta
     */
    public function rememberLocations(array $locations, array $meta): void
    {
        Cache::forever(self::LOCATIONS, $locations);
        Cache::forever(self::LOCATIONS_META, $meta);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function locations(): ?array
    {
        return Cache::get(self::LOCATIONS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function locationsMeta(): ?array
    {
        return Cache::get(self::LOCATIONS_META);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function rememberOptions(array $options): void
    {
        Cache::forever(self::OPTIONS, $options);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function options(): ?array
    {
        return Cache::get(self::OPTIONS);
    }
```

and in `wipe()` add:

```php
        Cache::forget(self::RULES);
        Cache::forget(self::LOCATIONS);
        Cache::forget(self::LOCATIONS_META);
        Cache::forget(self::OPTIONS);
```

- [ ] **Step 4: Run the tests and fix until they pass, format, commit**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/WxNotifyApiManagementTest.php tests/Feature/LocalCacheManagementTest.php tests/Feature/WxNotifyApiTest.php tests/Feature/LocalCacheTest.php`. Expected: PASS (the last two prove the existing client and cache behaviour is unchanged). Then:

```bash
cd mobile && vendor/bin/pint --dirty --format agent && php artisan native:validate
cd .. && git add mobile/app mobile/tests
git commit -m "App: API client, error types and cache for managing rules and locations

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Four-tab bar and the Rules list screen

**Files:**
- Create: `resources/views/native/partials/tabs.blade.php`, `app/NativeComponents/RulesScreen.php`, `resources/views/native/rules-screen.blade.php`
- Modify: `resources/views/native/alerts-screen.blade.php`, `resources/views/native/account-screen.blade.php`, `routes/mobile.php`
- Test: `tests/Feature/RulesScreenTest.php`; modify `tests/Feature/AlertsScreenTest.php`, `tests/Feature/AccountScreenTest.php` (tab assertions)

**Interfaces:**
- Consumes: `WxNotifyApi::alertRules()`, `setAlertRuleActive()`, `LocalCache::rules()/rememberRules()`, exceptions from Task 7, the rule JSON from Task 3, and Task 0's findings.
- Produces: the route `/rules` (list; the editor routes come with Task 10 and the Locations routes with Task 11); the `native.partials.tabs` partial taking `$active` (`alerts`, `rules`, `locations` or `account`); `RulesScreen` with public `array $rules`, `bool $offline`, `bool $loaded`, `?string $error`, `?string $message` and methods `refresh()`, `setActive(int $id, bool $value)`, `create()`, `open(int $id)`.
- Row refs used by tests: `rule-{id}` (the row's tap target), `active-{id}` (its toggle), `add-rule` (the add button).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/RulesScreenTest.php`:

```php
<?php

use App\NativeComponents\RulesScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function listedRule(array $overrides = []): array
{
    return [
        'id' => 1, 'name' => 'Freeze', 'display_name' => 'Freeze', 'category' => 'threshold', 'category_label' => 'Threshold Alerts',
        'is_active' => true, ...$overrides,
    ];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the rules list shows each rule\'s name, its category and an on/off switch', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [
        listedRule(),
        listedRule(['id' => 2, 'name' => null, 'display_name' => 'Tornado Warning', 'category' => 'weather_alerts', 'category_label' => 'Weather Alerts', 'is_active' => false]),
    ]])]);

    Native::test(RulesScreen::class)
        ->assertSee('Freeze')
        ->assertSee('Threshold Alerts')
        ->assertSee('Tornado Warning')
        ->assertSee('Weather Alerts')
        ->assertSee('Off')
        ->assertElement('toggle', fn (array $node) => ($node['props']['value'] ?? null) === false)
        ->assertHasTab('Rules')
        ->assertTabActive('Rules');
});

test('the list is remembered for offline use', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]])]);

    Native::test(RulesScreen::class);

    expect((new LocalCache)->rules())->toBe([listedRule()]);
});

test('offline, the remembered list is shown with a note', function () {
    (new LocalCache)->rememberRules([listedRule()]);
    $this->network->connected = false;
    Http::fake();

    Native::test(RulesScreen::class)->assertSee('Freeze')->assertSee('offline');
});

test('offline with nothing remembered says so', function () {
    $this->network->connected = false;
    Http::fake();

    Native::test(RulesScreen::class)->assertSee('offline')->assertSee('No alert rules yet');
});

test('with no rules the screen explains how to add one', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => []])]);

    Native::test(RulesScreen::class)->assertSee('No alert rules yet')->assertSee('Add rule');
});

test('switching a rule off updates the server and keeps the new state', function () {
    Http::fake([
        'wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]]),
        'wx.test/api/v1/alert-rules/1' => Http::response(['data' => listedRule(['is_active' => false])]),
    ]);

    Native::test(RulesScreen::class)->toggle('active-1', false)->assertSet('rules.0.is_active', false);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['is_active'] === false);
    expect((new LocalCache)->rules()[0]['is_active'])->toBeFalse();
});

test('a refused switch reverts and says why', function (int $status, string $text) {
    Http::fake([
        'wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]]),
        'wx.test/api/v1/alert-rules/1' => Http::response('Back soon.', $status, ['Content-Type' => 'text/plain']),
    ]);

    Native::test(RulesScreen::class)->toggle('active-1', false)->assertSet('rules.0.is_active', true)->assertSee($text);
})->with([
    'maintenance' => [503, 'Back soon.'],
    'an unexpected refusal' => [404, 'Couldn\'t update that alert'],
]);

test('switching a rule while offline reverts it and says so', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]])]);

    $screen = Native::test(RulesScreen::class);
    $this->network->connected = false;

    $screen->toggle('active-1', false)->assertSet('rules.0.is_active', true)->assertSee('offline');
});

test('tapping a rule opens its editor and the add button opens a new one', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]])]);

    Native::test(RulesScreen::class)->tap('rule-1')->assertNavigatedTo('/rules/1');
    Native::test(RulesScreen::class)->tap('Add rule')->assertNavigatedTo('/rules/new');
});

test('a refused token returns to sign in and a signed-out visitor is sent there', function () {
    Http::fake(['*' => Http::response([], 401)]);
    Native::test(RulesScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->forget();
    Native::test(RulesScreen::class)->assertReplacedWith('/sign-in');
});
```

In `tests/Feature/AlertsScreenTest.php` and `tests/Feature/AccountScreenTest.php`, add one test each asserting all four tabs exist (the existing tests already call `assertHasTab('Alerts')` / `assertHasTab('Account')`):

```php
test('the tab bar offers alerts, rules, locations and account', function () {
    Http::fake(['*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null]])]);

    $screen = Native::test(AlertsScreen::class);

    foreach (['Alerts', 'Rules', 'Locations', 'Account'] as $tab) {
        $screen->assertHasTab($tab);
    }
});
```

(in the account file use `AccountScreen` and the profile fake already used in that file's other tests).

- [ ] **Step 2: Run to verify they fail**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/RulesScreenTest.php`. Expected: FAIL (`RulesScreen` does not exist).

- [ ] **Step 3: Write the tab partial, the screen, its view and the routes**

Create `resources/views/native/partials/tabs.blade.php`:

```blade
@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" :ios-icon="Ios::Bell" :android-icon="Android::Notifications" :active="$active === 'alerts'"></native:bottom-nav-item>
    <native:bottom-nav-item id="rules" label="Rules" url="/rules" icon="list" :ios-icon="Ios::ListBullet" :android-icon="Android::List" :active="$active === 'rules'"></native:bottom-nav-item>
    <native:bottom-nav-item id="locations" label="Locations" url="/locations" icon="place" :ios-icon="Ios::Mappin" :android-icon="Android::Place" :active="$active === 'locations'"></native:bottom-nav-item>
    <native:bottom-nav-item id="account" label="Account" url="/account" icon="person" :ios-icon="Ios::Person" :android-icon="Android::Person" :active="$active === 'account'"></native:bottom-nav-item>
</native:bottom-nav>
```

In `alerts-screen.blade.php` and `account-screen.blade.php` replace each file's `@use` lines and `<native:bottom-nav>...</native:bottom-nav>` block with `@include('native.partials.tabs', ['active' => 'alerts'])` (respectively `'account'`), keeping `@use('App\Icons\Ios')`/`Android` only where the rest of that view still uses them (alerts-screen's badges use them in the component, not the view; keep the `@use` lines if the view still references `Ios::`/`Android::`, otherwise remove them).

If `assertHasTab('Rules')` or the active-tab assertion fails with the partial (the element collector may not hoist a `native:bottom-nav` that comes from an include), inline the four `native:bottom-nav-item` lines in each of the four screens instead (the original pattern) and delete the partial; say so in the report.

Create `app/NativeComponents/RulesScreen.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\HandlesPushToken;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;

/**
 * The subscriber's alert rules: a list with an on/off switch per row, an add button and a tap to
 * edit. Paints from the cache first and refreshes; offline it keeps the last copy with a note.
 */
class RulesScreen extends NativeComponent
{
    use HandlesPushToken;
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $rules = [];

    public bool $offline = false;

    public bool $loaded = false;

    public ?string $error = null;

    public ?string $message = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cached = app(LocalCache::class)->rules();
        $this->rules = $cached ?? [];
        $this->loaded = $cached !== null;

        $this->refresh();
    }

    /**
     * Back from the editor (saved, deleted or abandoned): the list may have changed.
     */
    public function onResume(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        try {
            $this->rules = app(WxNotifyApi::class)->alertRules();
            app(LocalCache::class)->rememberRules($this->rules);
            $this->offline = false;
            $this->error = null;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
        } catch (ApiException) {
            $this->error = 'WxNotify could not load your alert rules. Try again in a moment.';
        } finally {
            $this->loaded = true;
        }
    }

    /**
     * The switch flips at once; a refusal puts it back and says why.
     */
    public function setActive(int $id, bool $value): void
    {
        $index = $this->indexOf($id);

        if ($index === null) {
            return;
        }

        $this->message = null;
        $this->rules[$index]['is_active'] = $value;

        try {
            $this->rules[$index] = app(WxNotifyApi::class)->setAlertRuleActive($id, $value);
            app(LocalCache::class)->rememberRules($this->rules);
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Maintenance $e) {
            $this->rules[$index]['is_active'] = ! $value;
            $this->message = $e->getMessage();
        } catch (Offline) {
            $this->rules[$index]['is_active'] = ! $value;
            $this->message = 'You\'re offline. Reconnect to change this alert.';
        } catch (ApiException) {
            $this->rules[$index]['is_active'] = ! $value;
            $this->message = 'Couldn\'t update that alert. Please try again.';
        }
    }

    public function create(): void
    {
        $this->navigate('/rules/new');
    }

    public function open(int $id): void
    {
        $this->navigate("/rules/{$id}");
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public function subtitle(array $rule): string
    {
        return $rule['is_active'] ? (string) $rule['category_label'] : $rule['category_label'].' · Off';
    }

    private function indexOf(int $id): ?int
    {
        foreach ($this->rules as $index => $rule) {
            if ($rule['id'] === $id) {
                return $index;
            }
        }

        return null;
    }
}
```

Create `resources/views/native/rules-screen.blade.php` (rows are pressables so the toggle and the tap target are separate; the list uses a scroll view because `native:list` rows cannot hold a switch):

```blade
<native:top-bar title="Alert rules" display-mode="large"></native:top-bar>

@include('native.partials.tabs', ['active' => 'rules'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-on-surface-variant">{{ $loaded && count($rules) > 0 ? "You're offline. Showing your rules as last loaded." : "You're offline." }}</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
        </native:row>
    @endif

    @if ($message)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $message }}</native:text>
        </native:row>
    @endif

    <native:scroll-view class="w-full flex-1 bg-theme-background">
        <native:column class="w-full p-4 gap-2">
            <native:button label="Add rule" @tap="create" ref="add-rule" class="w-full" />

            @forelse ($rules as $rule)
                <native:row class="w-full items-center justify-between gap-3">
                    <native:pressable @tap="open({{ $rule['id'] }})" ref="rule-{{ $rule['id'] }}" class="flex-1">
                        <native:column class="gap-1">
                            <native:text class="text-base font-semibold text-theme-on-background">{{ $rule['display_name'] }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($rule) }}</native:text>
                        </native:column>
                    </native:pressable>
                    <native:toggle :value="$rule['is_active']" @change="setActive({{ $rule['id'] }})" ref="active-{{ $rule['id'] }}" />
                </native:row>
                <native:divider></native:divider>
            @empty
                @if ($loaded)
                    <native:column class="w-full p-6 items-center gap-2">
                        <native:text class="text-lg font-semibold text-theme-on-background">No alert rules yet</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant text-center">A rule decides which alerts reach you, where, and on which channels. Tap Add rule to create one.</native:text>
                    </native:column>
                @endif
            @endforelse
        </native:column>
    </native:scroll-view>
</native:column>
```

(The "Convention C" argument shape from Task 0's probe decides the `@change` and `@tap` forms: with `setActive(int $id, bool $value)` the toggle handler receives the static id first and the switch value last. Use whatever shape the probe proved; the tests drive the `active-1` ref and assert behaviour, not the argument shape.)

In `routes/mobile.php` register only the Rules list now (`LocationsScreen` and the editors do not exist yet):

```php
Route::native('/rules', RulesScreen::class);
```

Task 10 adds the rule editor routes (`/rules/new` before `/rules/{id}`); Task 11 adds `/locations`, `/locations/new` before `/locations/{id}`.

- [ ] **Step 4: Run the tests and fix until they pass, format, commit**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/RulesScreenTest.php tests/Feature/AlertsScreenTest.php tests/Feature/AccountScreenTest.php tests/Feature/ScreensRenderTest.php`. Expected: PASS. (`assertHasTab('Locations')` in the other screens passes even before the Locations screen exists: the tab item only carries a URL.) Then:

```bash
cd mobile && vendor/bin/pint --dirty --format agent && php artisan native:validate
cd .. && git add mobile
git commit -m "App: Rules tab with an on/off list, and a four-tab bar

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: `RuleFormPresenter` (the rule form's logic, pure PHP)

**Files:**
- Create: `app/Support/RuleFormPresenter.php`
- Test: `tests/Feature/RuleFormPresenterTest.php`

**Interfaces:**
- Consumes: the options payload shape from Task 2 and the rule JSON from Task 3.
- Produces (Task 10 relies on these exact signatures): `new RuleFormPresenter(array $options)`, and
  - `categories(): array` list of `{value, label, description}`
  - `blankDraft(string $category = ''): array`, `draftFromRule(array $rule): array`, `switchCategory(array $draft, string $category): array`
  - `fields(array $draft): array` the keys of the "Set it up" fields in order: `products`, `risk_levels`, `matching_mode`, `locations`, `wfo`, `county_zone_codes`, `variable`, `direction`, `threshold_value`, `timeframe`, `location`, `delivery_time`, `radius`
  - `productGroups(array $draft): array` the active product groups for the draft's category (`weather_alerts` or `spc_wpc`; `[]` otherwise)
  - `allowedModes(array $draft): array` list of `{value, label, description}`
  - `riskLevelChoices(array $draft): array` list of strings
  - `reconcile(array $draft): array` drops a matching mode, risk levels, locations, office or codes the current products or mode no longer allow
  - `payload(array $draft): array` the API body
  - `validate(array $draft): array` field => message (client-side checks that mirror the server's required rules)
  - `canCustomizeMessages(): bool`, `unitLabel(array $draft): ?string` (the threshold variable's unit), `radiusUnit(): string`, `radiusBounds(): array{min: int, max: int}`
- Draft shape (flat, the API's keys): `category`, `name`, `product_ids: int[]`, `matching_mode`, `location_ids: int[]`, `location_id: ?int`, `wfo`, `county_zone_codes: string[]`, `risk_levels: string[]`, `variable`, `direction`, `threshold_value` (string), `timeframe`, `delivery_time` (`H:i`), `radius` (string), `quiet_hours_start`, `quiet_hours_end`, `message_body_style`, `custom_subject_template`, `alert_template_id: ?int`, `channel_ids: int[]`; empty values are `''`, `[]` or `null`.

- [ ] **Step 1: Write the failing tests**

First add the shared options fixture to the `Functions` section of `tests/Pest.php` (the editor tests in Task 10 use it too):

```php
function ruleFormOptions(array $overrides = []): array
{
    return [
        'categories' => [
            ['value' => 'weather_alerts', 'label' => 'Weather Alerts', 'description' => 'Warnings.'],
            ['value' => 'daily_summary', 'label' => 'Daily Weather Summary', 'description' => 'Daily.'],
            ['value' => 'threshold', 'label' => 'Threshold Alerts', 'description' => 'Values.'],
            ['value' => 'spc_wpc', 'label' => 'SPC & WPC Alerts', 'description' => 'Outlooks.'],
            ['value' => 'lightning', 'label' => 'Lightning Alerts', 'description' => 'Strikes.'],
        ],
        'products' => [
            'weather_alerts' => [['category' => 'tornado', 'label' => 'Tornado', 'products' => [
                ['id' => 10, 'name' => 'Tornado Warning', 'description' => null, 'risk_levels' => []],
                ['id' => 11, 'name' => 'Tornado Watch', 'description' => null, 'risk_levels' => []],
            ]]],
            'spc_wpc' => [
                ['category' => 'convective', 'label' => 'Convective Outlooks', 'products' => [
                    ['id' => 20, 'name' => 'Day 1 Outlook', 'description' => null, 'risk_levels' => ['MRGL', 'SLGT', 'ENH']],
                    ['id' => 21, 'name' => 'Mesoscale Discussion', 'description' => null, 'risk_levels' => []],
                ]],
            ],
        ],
        'matching_modes' => [
            ['value' => 'nationwide', 'label' => 'All products, any location', 'description' => 'n'],
            ['value' => 'location', 'label' => 'By my location', 'description' => 'l'],
            ['value' => 'wfo', 'label' => 'By issuing office (WFO)', 'description' => 'w'],
            ['value' => 'county_zone', 'label' => 'By county/zone (UGC)', 'description' => 'c'],
            ['value' => 'polygon', 'label' => 'By product polygon', 'description' => 'p'],
            ['value' => 'risk_area', 'label' => 'By risk area at my location', 'description' => 'r'],
        ],
        'thresholds' => [
            'variables' => [['value' => 'temperature', 'label' => 'Temperature', 'unit' => '°F', 'decimals' => 1], ['value' => 'wind_speed', 'label' => 'Wind speed', 'unit' => 'mph', 'decimals' => 1]],
            'directions' => [['value' => 'above', 'label' => 'Rises above'], ['value' => 'below', 'label' => 'Falls below']],
            'timeframes' => [['value' => 'day', 'label' => 'During the day'], ['value' => 'overnight', 'label' => 'Overnight']],
        ],
        'lightning' => ['unit' => 'mi', 'min' => 10, 'max' => 50, 'default' => 15],
        'tier' => ['can_customize_messages' => false],
        'message_body_styles' => [['value' => 'full', 'label' => 'Full'], ['value' => 'brief', 'label' => 'Brief']],
        'channels' => [['id' => 5, 'label' => 'Discord', 'type' => 'discord', 'type_label' => 'Discord', 'verified' => true]],
        'templates' => ['weather_alerts' => [], 'spc_wpc' => []],
        'locations' => [['id' => 7, 'label' => 'Home']],
        'can_create_location' => true,
        ...$overrides,
    ];
}
```

(`tests/Pest.php` needs no new `use` lines for it.)

Create `tests/Feature/RuleFormPresenterTest.php`:

```php
<?php

use App\Support\RuleFormPresenter;

function rulePresenter(array $overrides = []): RuleFormPresenter
{
    return new RuleFormPresenter(ruleFormOptions($overrides));
}

function draftWith(RuleFormPresenter $presenter, string $category, array $values = []): array
{
    return [...$presenter->blankDraft($category), ...$values];
}

test('a blank draft starts empty, with the lightning radius defaulted', function () {
    $draft = rulePresenter()->blankDraft('lightning');

    expect($draft['category'])->toBe('lightning')
        ->and($draft['radius'])->toBe('15')
        ->and($draft['channel_ids'])->toBe([])
        ->and($draft['product_ids'])->toBe([])
        ->and($draft['location_id'])->toBeNull()
        ->and(rulePresenter()->blankDraft('')['category'])->toBe('');
});

test('which fields show depends on the category and the matching mode', function (string $category, array $values, array $expected) {
    $presenter = rulePresenter();

    expect($presenter->fields(draftWith($presenter, $category, $values)))->toBe($expected);
})->with([
    'weather alerts before a mode is chosen' => ['weather_alerts', [], ['products', 'matching_mode']],
    'weather alerts by location' => ['weather_alerts', ['matching_mode' => 'location'], ['products', 'matching_mode', 'locations']],
    'weather alerts by polygon' => ['weather_alerts', ['matching_mode' => 'polygon'], ['products', 'matching_mode', 'locations']],
    'weather alerts by office' => ['weather_alerts', ['matching_mode' => 'wfo'], ['products', 'matching_mode', 'wfo']],
    'weather alerts by county' => ['weather_alerts', ['matching_mode' => 'county_zone'], ['products', 'matching_mode', 'county_zone_codes']],
    'weather alerts nationwide' => ['weather_alerts', ['matching_mode' => 'nationwide'], ['products', 'matching_mode']],
    'SPC with a risk-level product selected' => ['spc_wpc', ['product_ids' => [20], 'matching_mode' => 'risk_area'], ['products', 'risk_levels', 'matching_mode', 'locations']],
    'SPC without a risk-level product' => ['spc_wpc', ['product_ids' => [21], 'matching_mode' => 'polygon'], ['products', 'matching_mode', 'locations']],
    'threshold' => ['threshold', [], ['variable', 'direction', 'threshold_value', 'timeframe', 'location', 'delivery_time']],
    'daily summary' => ['daily_summary', [], ['location', 'delivery_time']],
    'lightning' => ['lightning', [], ['location', 'radius']],
    'no category yet' => ['', [], []],
]);

test('the allowed matching modes follow the dashboard form', function () {
    $presenter = rulePresenter();
    $values = fn (array $modes) => array_column($modes, 'value');

    expect($values($presenter->allowedModes(draftWith($presenter, 'weather_alerts'))))->toBe(['nationwide', 'location', 'wfo', 'county_zone', 'polygon'])
        ->and($values($presenter->allowedModes(draftWith($presenter, 'spc_wpc', ['product_ids' => [20]]))))->toBe(['nationwide', 'risk_area'])
        ->and($values($presenter->allowedModes(draftWith($presenter, 'spc_wpc', ['product_ids' => [21]]))))->toBe(['nationwide', 'polygon'])
        ->and($values($presenter->allowedModes(draftWith($presenter, 'spc_wpc', ['product_ids' => [20, 21]]))))->toBe(['nationwide', 'risk_area'])
        ->and($presenter->allowedModes(draftWith($presenter, 'threshold')))->toBe([]);
});

test('risk level choices are the union of the selected products\' levels, in order', function () {
    $presenter = rulePresenter();

    expect($presenter->riskLevelChoices(draftWith($presenter, 'spc_wpc', ['product_ids' => [20, 21]])))->toBe(['MRGL', 'SLGT', 'ENH'])
        ->and($presenter->riskLevelChoices(draftWith($presenter, 'spc_wpc', ['product_ids' => [21]])))->toBe([])
        ->and($presenter->riskLevelChoices(draftWith($presenter, 'weather_alerts', ['product_ids' => [20]])))->toBe([]);
});

test('reconcile drops what a changed product selection or mode no longer allows', function () {
    $presenter = rulePresenter();

    $draft = draftWith($presenter, 'spc_wpc', ['product_ids' => [21], 'matching_mode' => 'risk_area', 'risk_levels' => ['SLGT'], 'location_ids' => [7]]);
    $reconciled = $presenter->reconcile($draft);

    expect($reconciled['matching_mode'])->toBe('')
        ->and($reconciled['risk_levels'])->toBe([])
        ->and($reconciled['location_ids'])->toBe([]);

    $kept = $presenter->reconcile(draftWith($presenter, 'spc_wpc', ['product_ids' => [20], 'matching_mode' => 'risk_area', 'risk_levels' => ['SLGT', 'GONE'], 'location_ids' => [7]]));

    expect($kept['matching_mode'])->toBe('risk_area')->and($kept['risk_levels'])->toBe(['SLGT'])->and($kept['location_ids'])->toBe([7]);

    $byOffice = $presenter->reconcile(draftWith($presenter, 'weather_alerts', ['matching_mode' => 'wfo', 'wfo' => 'OKX', 'location_ids' => [7], 'county_zone_codes' => ['NYC103']]));

    expect($byOffice['wfo'])->toBe('OKX')->and($byOffice['location_ids'])->toBe([])->and($byOffice['county_zone_codes'])->toBe([]);
});

test('switching category keeps the shared fields and clears the rest', function () {
    $presenter = rulePresenter(['tier' => ['can_customize_messages' => true]]);
    $draft = draftWith($presenter, 'threshold', [
        'name' => 'Freeze', 'channel_ids' => [5], 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00',
        'message_body_style' => 'brief', 'custom_subject_template' => '{product}', 'alert_template_id' => 3,
        'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => '32', 'location_id' => 7, 'delivery_time' => '06:00',
    ]);

    $switched = $presenter->switchCategory($draft, 'weather_alerts');

    expect($switched['category'])->toBe('weather_alerts')
        ->and($switched['name'])->toBe('Freeze')
        ->and($switched['channel_ids'])->toBe([5])
        ->and($switched['quiet_hours_start'])->toBe('22:00')
        ->and($switched['message_body_style'])->toBe('brief')
        ->and($switched['custom_subject_template'])->toBe('{product}')
        ->and($switched['alert_template_id'])->toBeNull()
        ->and($switched['variable'])->toBe('')
        ->and($switched['threshold_value'])->toBe('')
        ->and($switched['location_id'])->toBeNull()
        ->and($switched['delivery_time'])->toBe('')
        ->and($presenter->switchCategory($draft, 'lightning')['radius'])->toBe('15');
});

test('a stored rule becomes a draft the editor can show and send back unchanged', function () {
    $presenter = rulePresenter();
    $rule = [
        'id' => 4, 'name' => 'Freeze', 'category' => 'threshold', 'is_active' => true, 'matching_mode' => null, 'product_ids' => [], 'location_ids' => [], 'location_id' => 7,
        'wfo' => null, 'county_zone_codes' => [], 'risk_levels' => [], 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0,
        'timeframe' => 'overnight', 'radius' => null, 'delivery_time' => '06:00', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => null,
        'message_body_style' => null, 'custom_subject_template' => null, 'alert_template_id' => null, 'channel_ids' => [5],
    ];

    $draft = $presenter->draftFromRule($rule);

    expect($draft['threshold_value'])->toBe('32')
        ->and($draft['wfo'])->toBe('')
        ->and($draft['matching_mode'])->toBe('')
        ->and($draft['quiet_hours_end'])->toBe('')
        ->and($presenter->payload($draft))->toBe([
            'category' => 'threshold', 'name' => 'Freeze', 'channel_ids' => [5], 'quiet_hours_start' => '22:00', 'quiet_hours_end' => null,
            'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0, 'timeframe' => 'overnight', 'location_id' => 7, 'delivery_time' => '06:00',
        ]);
});

test('the payload carries only what the category and mode use', function () {
    $presenter = rulePresenter(['tier' => ['can_customize_messages' => true]]);

    $weather = $presenter->payload(draftWith($presenter, 'weather_alerts', [
        'product_ids' => [10, 99], 'matching_mode' => 'wfo', 'wfo' => 'OKX', 'location_ids' => [7], 'county_zone_codes' => ['NYC103'],
        'channel_ids' => [5], 'message_body_style' => 'brief', 'alert_template_id' => 3, 'variable' => 'temperature', 'delivery_time' => '06:00',
    ]));

    expect($weather)->toBe([
        'category' => 'weather_alerts', 'name' => null, 'channel_ids' => [5], 'quiet_hours_start' => null, 'quiet_hours_end' => null,
        'message_body_style' => 'brief', 'custom_subject_template' => null, 'alert_template_id' => 3,
        'product_ids' => [10, 99], 'matching_mode' => 'wfo', 'wfo' => 'OKX',
    ]);

    $lightning = $presenter->payload(draftWith($presenter, 'lightning', ['location_id' => 7, 'radius' => '24', 'channel_ids' => [5]]));

    expect($lightning)->toMatchArray(['category' => 'lightning', 'location_id' => 7, 'radius' => 24])
        ->and($lightning)->not->toHaveKey('alert_template_id')
        ->and($lightning)->not->toHaveKey('product_ids');

    $spc = $presenter->payload(draftWith($presenter, 'spc_wpc', ['product_ids' => [20], 'matching_mode' => 'risk_area', 'risk_levels' => ['SLGT'], 'location_ids' => [7], 'channel_ids' => [5]]));

    expect($spc)->toMatchArray(['risk_levels' => ['SLGT'], 'location_ids' => [7]]);
});

test('a Free subscriber\'s payload leaves out message customisation', function () {
    $presenter = rulePresenter();

    $payload = $presenter->payload(draftWith($presenter, 'weather_alerts', ['product_ids' => [10], 'matching_mode' => 'nationwide', 'channel_ids' => [5], 'message_body_style' => 'brief', 'custom_subject_template' => 'x']));

    expect($payload)->not->toHaveKey('message_body_style')->not->toHaveKey('custom_subject_template')->not->toHaveKey('alert_template_id');
});

test('validation mirrors the server\'s required fields', function (string $category, array $values, array $expectedFields) {
    $presenter = rulePresenter();

    expect(array_keys($presenter->validate(draftWith($presenter, $category, $values))))->toBe($expectedFields);
})->with([
    'nothing chosen' => ['', [], ['category', 'channel_ids']],
    'a weather rule with nothing' => ['weather_alerts', [], ['product_ids', 'matching_mode', 'channel_ids']],
    'a weather rule by location without locations' => ['weather_alerts', ['product_ids' => [10], 'matching_mode' => 'location', 'channel_ids' => [5]], ['location_ids']],
    'a weather rule by office without one' => ['weather_alerts', ['product_ids' => [10], 'matching_mode' => 'wfo', 'channel_ids' => [5]], ['wfo']],
    'a weather rule by county without codes' => ['weather_alerts', ['product_ids' => [10], 'matching_mode' => 'county_zone', 'channel_ids' => [5]], ['county_zone_codes']],
    'a complete nationwide rule' => ['weather_alerts', ['product_ids' => [10], 'matching_mode' => 'nationwide', 'channel_ids' => [5]], []],
    'a threshold with nothing' => ['threshold', ['channel_ids' => [5]], ['variable', 'direction', 'threshold_value', 'timeframe', 'location_id', 'delivery_time']],
    'a threshold value that is not a number' => ['threshold', ['variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 'cold', 'timeframe' => 'day', 'location_id' => 7, 'delivery_time' => '06:00', 'channel_ids' => [5]], ['threshold_value']],
    'a daily summary with nothing' => ['daily_summary', ['channel_ids' => [5]], ['location_id', 'delivery_time']],
    'a lightning radius below the minimum' => ['lightning', ['location_id' => 7, 'radius' => '9', 'channel_ids' => [5]], ['radius']],
    'a lightning radius above the maximum' => ['lightning', ['location_id' => 7, 'radius' => '51', 'channel_ids' => [5]], ['radius']],
    'a lightning radius that is not a whole number' => ['lightning', ['location_id' => 7, 'radius' => '24.5', 'channel_ids' => [5]], ['radius']],
    'a complete lightning rule' => ['lightning', ['location_id' => 7, 'radius' => '15', 'channel_ids' => [5]], []],
]);

test('small helpers read from the options', function () {
    $presenter = rulePresenter(['tier' => ['can_customize_messages' => true]]);

    expect($presenter->canCustomizeMessages())->toBeTrue()
        ->and($presenter->unitLabel(draftWith($presenter, 'threshold', ['variable' => 'wind_speed'])))->toBe('mph')
        ->and($presenter->unitLabel(draftWith($presenter, 'threshold')))->toBeNull()
        ->and($presenter->radiusUnit())->toBe('mi')
        ->and($presenter->radiusBounds())->toBe(['min' => 10, 'max' => 50]);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/RuleFormPresenterTest.php`. Expected: FAIL (`RuleFormPresenter` does not exist).

- [ ] **Step 3: Write the presenter**

Create `app/Support/RuleFormPresenter.php`:

```php
<?php

namespace App\Support;

/**
 * The alert rule form's logic on the phone, as plain data in and out: which fields a category and
 * matching mode show, which matching modes the selected products allow, how a stored rule becomes
 * a draft, how a draft becomes the API body, and the required-field checks. It mirrors the
 * dashboard form and the server's request rules so the editor can guide the subscriber, but the
 * server stays the authority and re-validates everything.
 *
 * A draft uses the API's own keys; empty values are '', [] or null.
 */
final class RuleFormPresenter
{
    private const PRODUCT_CATEGORIES = ['weather_alerts', 'spc_wpc'];

    private const LOCATION_MODES = ['location', 'polygon', 'risk_area'];

    /**
     * @param  array<string, mixed>  $options  the /alert-rule-options payload
     */
    public function __construct(private readonly array $options) {}

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public function categories(): array
    {
        return $this->options['categories'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function blankDraft(string $category = ''): array
    {
        return [
            'category' => $category,
            'name' => '',
            'product_ids' => [],
            'matching_mode' => '',
            'location_ids' => [],
            'location_id' => null,
            'wfo' => '',
            'county_zone_codes' => [],
            'risk_levels' => [],
            'variable' => '',
            'direction' => '',
            'threshold_value' => '',
            'timeframe' => '',
            'delivery_time' => '',
            'radius' => $category === 'lightning' ? (string) ($this->options['lightning']['default'] ?? '') : '',
            'quiet_hours_start' => '',
            'quiet_hours_end' => '',
            'message_body_style' => '',
            'custom_subject_template' => '',
            'alert_template_id' => null,
            'channel_ids' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $rule  a rule as the API returns it
     * @return array<string, mixed>
     */
    public function draftFromRule(array $rule): array
    {
        $text = fn (string $key): string => (string) ($rule[$key] ?? '');
        $number = fn (string $key): string => isset($rule[$key]) ? rtrim(rtrim(number_format((float) $rule[$key], 4, '.', ''), '0'), '.') : '';

        return [
            ...$this->blankDraft($text('category')),
            'name' => $text('name'),
            'product_ids' => array_values($rule['product_ids'] ?? []),
            'matching_mode' => $text('matching_mode'),
            'location_ids' => array_values($rule['location_ids'] ?? []),
            'location_id' => $rule['location_id'] ?? null,
            'wfo' => $text('wfo'),
            'county_zone_codes' => array_values($rule['county_zone_codes'] ?? []),
            'risk_levels' => array_values($rule['risk_levels'] ?? []),
            'variable' => $text('variable'),
            'direction' => $text('direction'),
            'threshold_value' => $number('threshold_value'),
            'timeframe' => $text('timeframe'),
            'delivery_time' => $text('delivery_time'),
            'radius' => $number('radius'),
            'quiet_hours_start' => $text('quiet_hours_start'),
            'quiet_hours_end' => $text('quiet_hours_end'),
            'message_body_style' => $text('message_body_style'),
            'custom_subject_template' => $text('custom_subject_template'),
            'alert_template_id' => $rule['alert_template_id'] ?? null,
            'channel_ids' => array_values($rule['channel_ids'] ?? []),
        ];
    }

    /**
     * Keeps what every category shares (name, quiet hours, channels, message style and subject);
     * the category's own fields start blank, and the template is cleared because it belongs to a category.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function switchCategory(array $draft, string $category): array
    {
        $shared = array_intersect_key($draft, array_flip([
            'name', 'quiet_hours_start', 'quiet_hours_end', 'message_body_style', 'custom_subject_template', 'channel_ids',
        ]));

        return [...$this->blankDraft($category), ...$shared];
    }

    /**
     * The "Set it up" fields to show, in order.
     *
     * @param  array<string, mixed>  $draft
     * @return array<int, string>
     */
    public function fields(array $draft): array
    {
        $mode = (string) $draft['matching_mode'];

        return match ($draft['category']) {
            'weather_alerts', 'spc_wpc' => [
                'products',
                ...($this->riskLevelChoices($draft) !== [] ? ['risk_levels'] : []),
                'matching_mode',
                ...(in_array($mode, self::LOCATION_MODES, true) ? ['locations'] : []),
                ...($mode === 'wfo' ? ['wfo'] : []),
                ...($mode === 'county_zone' ? ['county_zone_codes'] : []),
            ],
            'threshold' => ['variable', 'direction', 'threshold_value', 'timeframe', 'location', 'delivery_time'],
            'daily_summary' => ['location', 'delivery_time'],
            'lightning' => ['location', 'radius'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, array{category: string, label: string, products: array<int, array<string, mixed>>}>
     */
    public function productGroups(array $draft): array
    {
        return in_array($draft['category'], self::PRODUCT_CATEGORIES, true)
            ? ($this->options['products'][$draft['category']] ?? [])
            : [];
    }

    /**
     * Weather Alerts offer every mode but Risk area. SPC & WPC offer Nationwide plus Risk area when
     * a selected product carries risk levels, otherwise Nationwide plus Polygon (the dashboard's rule).
     *
     * @param  array<string, mixed>  $draft
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public function allowedModes(array $draft): array
    {
        $allowed = match ($draft['category']) {
            'weather_alerts' => ['nationwide', 'location', 'wfo', 'county_zone', 'polygon'],
            'spc_wpc' => $this->riskLevelChoices($draft) !== [] ? ['nationwide', 'risk_area'] : ['nationwide', 'polygon'],
            default => [],
        };

        return array_values(array_filter(
            $this->options['matching_modes'] ?? [],
            fn (array $mode): bool => in_array($mode['value'], $allowed, true),
        ));
    }

    /**
     * Every risk level the selected SPC & WPC products offer, in the products' own order.
     *
     * @param  array<string, mixed>  $draft
     * @return array<int, string>
     */
    public function riskLevelChoices(array $draft): array
    {
        if ($draft['category'] !== 'spc_wpc') {
            return [];
        }

        $levels = [];

        foreach ($this->productGroups($draft) as $group) {
            foreach ($group['products'] as $product) {
                if (in_array($product['id'], $draft['product_ids'], true)) {
                    foreach ($product['risk_levels'] as $level) {
                        $levels[$level] = $level;
                    }
                }
            }
        }

        return array_values($levels);
    }

    /**
     * After a product or mode change: drop a mode the products no longer allow, risk levels they no
     * longer offer, and the picks (locations, office, codes) the current mode does not use.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function reconcile(array $draft): array
    {
        if (! in_array($draft['category'], self::PRODUCT_CATEGORIES, true)) {
            return $draft;
        }

        $allowed = array_column($this->allowedModes($draft), 'value');

        if ($draft['matching_mode'] !== '' && ! in_array($draft['matching_mode'], $allowed, true)) {
            $draft['matching_mode'] = '';
        }

        $draft['risk_levels'] = array_values(array_intersect($draft['risk_levels'], $this->riskLevelChoices($draft)));

        $mode = $draft['matching_mode'];

        if (! in_array($mode, self::LOCATION_MODES, true)) {
            $draft['location_ids'] = [];
        }

        if ($mode !== 'wfo') {
            $draft['wfo'] = '';
        }

        if ($mode !== 'county_zone') {
            $draft['county_zone_codes'] = [];
        }

        return $draft;
    }

    /**
     * The API request body: only the fields the category and mode use, numbers as numbers, blanks as null.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function payload(array $draft): array
    {
        $blank = fn (string $key): mixed => filled($draft[$key]) ? $draft[$key] : null;
        $category = (string) $draft['category'];

        $body = [
            'category' => $category,
            'name' => $blank('name'),
            'channel_ids' => array_values($draft['channel_ids']),
            'quiet_hours_start' => $blank('quiet_hours_start'),
            'quiet_hours_end' => $blank('quiet_hours_end'),
        ];

        if ($this->canCustomizeMessages()) {
            $body['message_body_style'] = $blank('message_body_style');
            $body['custom_subject_template'] = $blank('custom_subject_template');

            if (in_array($category, self::PRODUCT_CATEGORIES, true)) {
                $body['alert_template_id'] = $draft['alert_template_id'];
            }
        }

        return [...$body, ...match ($category) {
            'weather_alerts', 'spc_wpc' => $this->productPayload($draft),
            'threshold' => [
                'variable' => $draft['variable'],
                'direction' => $draft['direction'],
                'threshold_value' => (float) $draft['threshold_value'],
                'timeframe' => $draft['timeframe'],
                'location_id' => $draft['location_id'],
                'delivery_time' => $draft['delivery_time'],
            ],
            'daily_summary' => ['location_id' => $draft['location_id'], 'delivery_time' => $draft['delivery_time']],
            'lightning' => ['location_id' => $draft['location_id'], 'radius' => (int) $draft['radius']],
            default => [],
        }];
    }

    /**
     * Required-field checks that mirror the server's, one short message per field.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, string>
     */
    public function validate(array $draft): array
    {
        $errors = [];
        $category = (string) $draft['category'];

        if ($category === '') {
            $errors['category'] = 'Choose a kind of alert.';
        }

        if (in_array($category, self::PRODUCT_CATEGORIES, true)) {
            if ($draft['product_ids'] === []) {
                $errors['product_ids'] = 'Select at least one product.';
            }

            if ($draft['matching_mode'] === '') {
                $errors['matching_mode'] = 'Choose how alerts should match.';
            } elseif (in_array($draft['matching_mode'], self::LOCATION_MODES, true) && $draft['location_ids'] === []) {
                $errors['location_ids'] = 'Choose at least one location.';
            } elseif ($draft['matching_mode'] === 'wfo' && $draft['wfo'] === '') {
                $errors['wfo'] = 'Choose an NWS office.';
            } elseif ($draft['matching_mode'] === 'county_zone' && $draft['county_zone_codes'] === []) {
                $errors['county_zone_codes'] = 'Choose at least one county or zone.';
            }
        }

        if ($category === 'threshold') {
            if ($draft['variable'] === '') {
                $errors['variable'] = 'Choose a variable.';
            }

            if ($draft['direction'] === '') {
                $errors['direction'] = 'Choose a direction.';
            }

            if (! is_numeric($draft['threshold_value'])) {
                $errors['threshold_value'] = 'Enter a number.';
            }

            if ($draft['timeframe'] === '') {
                $errors['timeframe'] = 'Choose a timeframe.';
            }
        }

        if (in_array($category, ['threshold', 'daily_summary'], true)) {
            $this->requireLocationAndTime($draft, $errors);
        }

        if ($category === 'lightning') {
            $bounds = $this->radiusBounds();

            if ($draft['location_id'] === null) {
                $errors['location_id'] = 'Choose a location.';
            }

            $radius = filter_var(trim((string) $draft['radius']), FILTER_VALIDATE_INT);

            if ($radius === false || $radius < $bounds['min'] || $radius > $bounds['max']) {
                $errors['radius'] = "Enter a whole-number radius from {$bounds['min']} to {$bounds['max']} {$this->radiusUnit()}.";
            }
        }

        if ($draft['channel_ids'] === []) {
            $errors['channel_ids'] = 'Choose at least one channel.';
        }

        return $errors;
    }

    public function canCustomizeMessages(): bool
    {
        return (bool) ($this->options['tier']['can_customize_messages'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function unitLabel(array $draft): ?string
    {
        foreach ($this->options['thresholds']['variables'] ?? [] as $variable) {
            if ($variable['value'] === $draft['variable']) {
                return $variable['unit'];
            }
        }

        return null;
    }

    public function radiusUnit(): string
    {
        return (string) ($this->options['lightning']['unit'] ?? 'mi');
    }

    /**
     * @return array{min: int, max: int}
     */
    public function radiusBounds(): array
    {
        return ['min' => (int) ($this->options['lightning']['min'] ?? 1), 'max' => (int) ($this->options['lightning']['max'] ?? 50)];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function productPayload(array $draft): array
    {
        $mode = (string) $draft['matching_mode'];

        return [
            'product_ids' => array_values($draft['product_ids']),
            'matching_mode' => $mode,
            ...(in_array($mode, self::LOCATION_MODES, true) ? ['location_ids' => array_values($draft['location_ids'])] : []),
            ...($mode === 'wfo' ? ['wfo' => $draft['wfo']] : []),
            ...($mode === 'county_zone' ? ['county_zone_codes' => array_values($draft['county_zone_codes'])] : []),
            ...($draft['category'] === 'spc_wpc' ? ['risk_levels' => array_values($draft['risk_levels'])] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, string>  $errors
     */
    private function requireLocationAndTime(array $draft, array &$errors): void
    {
        if ($draft['location_id'] === null) {
            $errors['location_id'] = 'Choose a location.';
        }

        if ($draft['delivery_time'] === '') {
            $errors['delivery_time'] = 'Choose a delivery time.';
        }
    }
}
```

- [ ] **Step 4: Run the tests and fix until they pass, format, commit**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/RuleFormPresenterTest.php`. Expected: PASS. (The error-ordering in the `validate` dataset is part of the contract: category, product_ids, matching_mode... then channel_ids last. If a dataset's expected order differs from the code's natural order, fix the code, not the dataset: the editor shows the first error first.) Then:

```bash
cd mobile && vendor/bin/pint --dirty --format agent
cd .. && git add mobile
git commit -m "App: RuleFormPresenter holds the rule form's category and matching-mode logic

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 10: The rule editor screen

**Files:**
- Create: `app/NativeComponents/RuleEditor.php`, `resources/views/native/rule-editor.blade.php`
- Modify: `routes/mobile.php`
- Test: `tests/Feature/RuleEditorTest.php`

**Interfaces:**
- Consumes: `RuleFormPresenter` (Task 9; every method), `WxNotifyApi::{alertRule, alertRuleOptions, saveAlertRule, deleteAlertRule, nwsOffices, zones}` and `LocalCache::{options, rememberOptions, rules, rememberRules}` (Task 7), the exceptions `ValidationFailed`, `Maintenance`, `Offline`, `Rejected`, `Unauthenticated`, the Task 0 probe's **conventions and rulings** (carry them in from the controller's dispatch: element type names, whether `native:accordion` and `native:date-picker mode="time"` exist, and the handler-argument convention; the code below assumes Convention C and both elements existing).
- Produces: routes `/rules/new` and `/rules/{id}` (register `/rules/new` first); `RuleEditor` with public `?int $ruleId`, `array $draft`, `array $original`, `array $options`, `array $errors`, `?string $banner`, `bool $ready`, `bool $confirmingDelete`, `bool $confirmingDiscard` and the methods listed in the test refs below.
- Refs the tests drive: `category-{value}` (a pressable), `product-{id}`, `risk-{level}`, `location-{id}`, `channel-{id}`, `zone-{code}` (checkboxes), `mode-{value}` (a pressable), `office-{code}` (a pressable), `field-variable`, `field-direction`, `field-timeframe`, `field-location`, `field-message_body_style`, `field-alert_template_id` (selects), `field-threshold_value`, `field-radius`, `field-name`, `field-custom_subject_template` (text inputs); buttons are driven by their label: `Save rule`, `Delete rule`, `Yes, delete`, `Keep rule`, `Discard changes`, `Keep editing`, `Search offices`, `Search zones`, `More results`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/RuleEditorTest.php`:

```php
<?php

use App\NativeComponents\RuleEditor;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function editorSavedRule(array $overrides = []): array
{
    return [
        'id' => 4, 'name' => 'Freeze', 'display_name' => 'Freeze', 'category' => 'threshold', 'category_label' => 'Threshold Alerts', 'is_active' => true,
        'matching_mode' => null, 'product_ids' => [], 'location_ids' => [], 'location_id' => 7, 'wfo' => null, 'county_zone_codes' => [], 'risk_levels' => [],
        'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0, 'threshold_unit' => '°F', 'timeframe' => 'overnight', 'radius' => null,
        'radius_unit' => 'mi', 'delivery_time' => '06:00', 'quiet_hours_start' => null, 'quiet_hours_end' => null, 'message_body_style' => null,
        'custom_subject_template' => null, 'alert_template_id' => null, 'channel_ids' => [5],
        ...$overrides,
    ];
}

/**
 * Fakes the options endpoint (plus any extra routes). Call once per test: Http::fake calls merge and the first match wins.
 */
function editorFakes(array $extra = [], array $options = []): void
{
    Http::fake(['wx.test/api/v1/alert-rule-options' => Http::response(['data' => ruleFormOptions($options)]), ...$extra]);
}

function bodyOf(Request $request): array
{
    return $request->data();
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('a new rule starts with the category choices and no category fields', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->assertNavTitle('New alert rule')
        ->assertSee('What kind of alert?')
        ->assertSee('Weather Alerts')
        ->assertSee('Notify me on')
        ->assertDontSee('Set it up');

    expect((new LocalCache)->options())->not->toBeNull();
});

test('choosing a category shows its fields, and switching clears the previous category\'s', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->tap('category-threshold')
        ->assertSee('Set it up')
        ->select('field-variable', 'Temperature')
        ->assertSet('draft.variable', 'temperature')
        ->tap('category-lightning')
        ->assertSet('draft.category', 'lightning')
        ->assertSet('draft.variable', '')
        ->assertSet('draft.radius', '15');
});

test('a threshold rule is built from the form and saved', function () {
    editorFakes(['wx.test/api/v1/alert-rules' => Http::response(['data' => editorSavedRule()], 201)]);

    Native::test(RuleEditor::class)
        ->tap('category-threshold')
        ->select('field-variable', 'Temperature')
        ->select('field-direction', 'Falls below')
        ->input('field-threshold_value', '32')
        ->select('field-timeframe', 'Overnight')
        ->select('field-location', 'Home')
        ->pickTime('field-delivery_time', '06:30')
        ->check('channel-5', true)
        ->tap('Save rule')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/alert-rules' && bodyOf($r) == [
        'category' => 'threshold', 'name' => null, 'channel_ids' => [5], 'quiet_hours_start' => null, 'quiet_hours_end' => null,
        'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0, 'timeframe' => 'overnight', 'location_id' => 7, 'delivery_time' => '06:30',
    ]);
    expect((new LocalCache)->rules()[0]['id'])->toBe(4);
});

test('a weather alert by location is built from products, a mode and locations', function () {
    editorFakes(['wx.test/api/v1/alert-rules' => Http::response(['data' => editorSavedRule(['id' => 9, 'category' => 'weather_alerts'])], 201)]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->check('product-10', true)
        ->tap('mode-location')
        ->check('location-7', true)
        ->check('channel-5', true)
        ->tap('Save rule')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && bodyOf($r) == [
        'category' => 'weather_alerts', 'name' => null, 'channel_ids' => [5], 'quiet_hours_start' => null, 'quiet_hours_end' => null,
        'product_ids' => [10], 'matching_mode' => 'location', 'location_ids' => [7],
    ]);
});

test('SPC and WPC offer the risk levels and the modes their products allow, and drop what a changed selection no longer allows', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->tap('category-spc_wpc')
        ->check('product-21', true)
        ->assertSee('By product polygon')
        ->assertDontSee('By risk area at my location')
        ->check('product-20', true)
        ->assertSee('By risk area at my location')
        ->assertSee('SLGT')
        ->tap('mode-risk_area')
        ->check('risk-SLGT', true)
        ->assertSet('draft.risk_levels', ['SLGT'])
        ->check('product-20', false)
        ->assertSet('draft.matching_mode', '')
        ->assertSet('draft.risk_levels', []);
});

test('saving with required fields missing shows what to fix and sends nothing', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->tap('Save rule')
        ->assertSee('Some details need fixing.')
        ->assertSee('Select at least one product.')
        ->assertSee('Choose how alerts should match.')
        ->assertSee('Choose at least one channel.')
        ->assertNoNavigation();

    Http::assertSentCount(1);
});

test('the server refusing a field puts its message on that field and keeps the draft', function () {
    editorFakes(['wx.test/api/v1/alert-rules' => Http::response([
        'message' => 'The given data was invalid.',
        'errors' => ['radius' => ['The radius field must be at least 10.']],
    ], 422)]);

    Native::test(RuleEditor::class)
        ->tap('category-lightning')
        ->select('field-location', 'Home')
        ->check('channel-5', true)
        ->tap('Save rule')
        ->assertSee('The radius field must be at least 10.')
        ->assertSee('Some details need fixing.')
        ->assertSet('draft.radius', '15')
        ->assertNoNavigation();
});

test('a failed save says why and keeps the draft', function (int $status, string $contentType, string $body, string $text) {
    editorFakes(['wx.test/api/v1/alert-rules' => Http::response($body, $status, ['Content-Type' => $contentType])]);

    Native::test(RuleEditor::class)
        ->tap('category-lightning')
        ->select('field-location', 'Home')
        ->check('channel-5', true)
        ->tap('Save rule')
        ->assertSee($text)
        ->assertSet('draft.location_id', 7)
        ->assertNoNavigation();
})->with([
    'maintenance' => [503, 'text/plain', 'Back soon.', 'Back soon.'],
    'a server error' => [500, 'application/json', '{}', 'Couldn\'t save the rule. Please try again.'],
]);

test('saving while offline says so and keeps the draft', function () {
    editorFakes();

    $screen = Native::test(RuleEditor::class)->tap('category-lightning')->select('field-location', 'Home')->check('channel-5', true);
    $this->network->connected = false;

    $screen->tap('Save rule')->assertSee('You\'re offline. Reconnect to save.')->assertNoNavigation();
});

test('an existing rule opens filled in and is replaced with PUT', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule()])]);

    Native::test(RuleEditor::class, ['id' => 4])
        ->assertNavTitle('Edit alert rule')
        ->assertSet('draft.threshold_value', '32')
        ->assertSet('draft.variable', 'temperature')
        ->input('field-threshold_value', '30')
        ->tap('Save rule')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/alert-rules/4' && bodyOf($r)['threshold_value'] === 30.0 && bodyOf($r)['name'] === 'Freeze');
});

test('an existing rule opens from the remembered list when offline, and a rule that is gone says so', function () {
    (new LocalCache)->rememberOptions(ruleFormOptions());
    (new LocalCache)->rememberRules([editorSavedRule()]);
    $this->network->connected = false;

    Native::test(RuleEditor::class, ['id' => 4])->assertSet('draft.variable', 'temperature');

    $this->network->connected = true;
    Http::fake(['wx.test/api/v1/alert-rule-options' => Http::response(['data' => ruleFormOptions()]), 'wx.test/api/v1/alert-rules/9' => Http::response([], 404)]);

    Native::test(RuleEditor::class, ['id' => 9])->assertSee('This rule no longer exists.')->assertDontSee('What kind of alert?');
});

test('offline with nothing remembered, the form cannot open', function () {
    $this->network->connected = false;
    Http::fake();

    Native::test(RuleEditor::class)->assertSee('Connect to the internet to set up an alert rule.')->assertDontSee('What kind of alert?');
});

test('backing out with nothing changed just goes back, and with edits asks first', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => Http::response(['data' => editorSavedRule()])]);

    Native::test(RuleEditor::class, ['id' => 4])->pressBack()->assertWentBack();

    Native::test(RuleEditor::class, ['id' => 4])
        ->input('field-threshold_value', '30')
        ->pressBack()
        ->assertNoNavigation()
        ->assertSee('Discard your changes?')
        ->tap('Keep editing')
        ->assertSet('confirmingDiscard', false)
        ->pressBack()
        ->tap('Discard changes')
        ->assertWentBack();
});

test('a rule is deleted after a confirmation, and keeping it changes nothing', function () {
    editorFakes(['wx.test/api/v1/alert-rules/4' => fn (Request $request) => $request->method() === 'DELETE'
        ? Http::response(null, 204)
        : Http::response(['data' => editorSavedRule()])]);
    (new LocalCache)->rememberRules([editorSavedRule(), editorSavedRule(['id' => 5])]);

    $screen = Native::test(RuleEditor::class, ['id' => 4]);

    $screen->tap('Delete rule')->assertSee('can\'t be undone')->tap('Keep rule')->assertSet('confirmingDelete', false);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');

    $screen->tap('Delete rule')->tap('Yes, delete')->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/alert-rules/4');
    expect(collect((new LocalCache)->rules())->pluck('id')->all())->toBe([5]);
});

test('with no channels the form says where to get one', function () {
    editorFakes([], ['channels' => []]);

    Native::test(RuleEditor::class)->assertSee('You have no channels yet.')->assertSee('add a channel on wxnotify.com');
});

test('an unverified channel is marked as such', function () {
    editorFakes([], ['channels' => [['id' => 5, 'label' => 'Team Discord', 'type' => 'discord', 'type_label' => 'Discord', 'verified' => false]]]);

    Native::test(RuleEditor::class)->assertSee('Team Discord')->assertSee('(unverified)');
});

test('message length, subject and template are hidden from a Free subscriber', function () {
    editorFakes([], ['tier' => ['can_customize_messages' => false]]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->assertDontSee('Message length')
        ->assertDontSee('Message template');
});

test('message length, subject and template appear for a paid subscriber', function () {
    editorFakes([], ['tier' => ['can_customize_messages' => true], 'templates' => ['weather_alerts' => [['id' => 3, 'name' => 'Brief']], 'spc_wpc' => []]]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->assertSee('Message length')
        ->assertSee('Message template')
        ->select('field-alert_template_id', 'Brief')
        ->assertSet('draft.alert_template_id', 3)
        ->tap('category-threshold')
        ->assertDontSee('Message template');
});

test('two locations with the same name can both be chosen', function () {
    editorFakes([], ['locations' => [['id' => 7, 'label' => 'Home'], ['id' => 8, 'label' => 'Home']]]);

    Native::test(RuleEditor::class)
        ->tap('category-daily_summary')
        ->select('field-location', 'Home (2)')
        ->assertSet('draft.location_id', 8)
        ->select('field-location', 'Home')
        ->assertSet('draft.location_id', 7);
});

test('returning from adding a location refreshes the choices and keeps the draft', function () {
    Http::fake(['wx.test/api/v1/alert-rule-options' => Http::sequence()
        ->push(['data' => ruleFormOptions()])
        ->push(['data' => ruleFormOptions(['locations' => [['id' => 7, 'label' => 'Home'], ['id' => 8, 'label' => 'Cabin']]])])]);

    Native::test(RuleEditor::class)
        ->tap('category-daily_summary')
        ->set('draft.name', 'Morning')
        ->call('onResume')
        ->select('field-location', 'Cabin')
        ->assertSet('draft.location_id', 8)
        ->assertSet('draft.name', 'Morning');
});

test('an NWS office is found by searching and chosen', function () {
    editorFakes(['wx.test/api/v1/nws-offices' => Http::response(['data' => [
        ['code' => 'ABQ', 'label' => 'ABQ — Albuquerque, NM'], ['code' => 'OKX', 'label' => 'OKX — New York, NY'],
    ]])]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->tap('mode-wfo')
        ->set('officeQuery', 'new york')
        ->tap('Search offices')
        ->assertSee('OKX — New York, NY')
        ->assertDontSee('Albuquerque')
        ->tap('office-OKX')
        ->assertSet('draft.wfo', 'OKX');
});

test('counties and zones are searched by state and chosen with checkboxes', function () {
    editorFakes(['wx.test/api/v1/zones*' => Http::response(['data' => [
        ['code' => 'NYC103', 'label' => 'NYC103 — Suffolk County, NY'], ['code' => 'NYZ072', 'label' => 'NYZ072 — Suffolk, NY'],
    ], 'meta' => ['next_after' => null]])]);

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->tap('mode-county_zone')
        ->set('zoneState', 'ny')
        ->tap('Search zones')
        ->assertSee('NYC103 — Suffolk County, NY')
        ->check('zone-NYC103', true)
        ->assertSet('draft.county_zone_codes', ['NYC103']);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://wx.test/api/v1/zones?') && str_contains($r->url(), 'state=NY'));
});

test('a zone search needs a state or a few letters', function () {
    editorFakes();

    Native::test(RuleEditor::class)
        ->tap('category-weather_alerts')
        ->tap('mode-county_zone')
        ->tap('Search zones')
        ->assertSee('Enter a two-letter state or at least two letters');

    Http::assertSentCount(1);
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();
    Http::fake();

    Native::test(RuleEditor::class)->assertReplacedWith('/sign-in');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/RuleEditorTest.php`. Expected: FAIL (`RuleEditor` does not exist).

- [ ] **Step 3: Write the component**

Create `app/NativeComponents/RuleEditor.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\RuleFormPresenter;
use Native\Mobile\Edge\NativeComponent;

/**
 * Creates or edits one alert rule: a draft held here, nothing sent until Save. The category-driven
 * logic is RuleFormPresenter's; this screen only wires taps to the draft and the draft to the API.
 * The server re-validates everything and its field messages are shown on the field.
 */
class RuleEditor extends NativeComponent
{
    use RequiresSignIn;

    private const MAX_OFFICE_RESULTS = 20;

    public ?int $ruleId = null;

    /** @var array<string, mixed> */
    public array $draft = [];

    /** @var array<string, mixed> */
    public array $original = [];

    /** @var array<string, mixed> */
    public array $options = [];

    /** @var array<string, string> */
    public array $errors = [];

    public ?string $banner = null;

    public bool $ready = false;

    public bool $confirmingDelete = false;

    public bool $confirmingDiscard = false;

    public string $officeQuery = '';

    /** @var array<int, array{code: string, label: string}> */
    public array $offices = [];

    /** @var array<int, array{code: string, label: string}> */
    public array $officeResults = [];

    public string $zoneState = '';

    public string $zoneQuery = '';

    /** @var array<int, array{code: string, label: string}> */
    public array $zoneResults = [];

    public ?string $zoneNextAfter = null;

    public ?string $pickerMessage = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $id = $this->param('id');
        $this->ruleId = filled($id) ? (int) $id : null;

        $cache = app(LocalCache::class);
        $this->options = $cache->options() ?? [];

        try {
            $this->options = app(WxNotifyApi::class)->alertRuleOptions();
            $cache->rememberOptions($this->options);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ApiException) {
            if ($this->options === []) {
                $this->banner = 'Connect to the internet to set up an alert rule.';

                return;
            }
        }

        $rule = null;

        if ($this->ruleId !== null) {
            $rule = $this->loadRule($this->ruleId);

            if ($rule === null) {
                return;
            }
        }

        $presenter = $this->presenter();
        $this->draft = $rule === null ? $presenter->blankDraft('') : $presenter->draftFromRule($rule);
        $this->original = $this->draft;
        $this->ready = true;
    }

    /**
     * Back from adding a location (or from Account): refresh the choices so a new location or
     * channel can be picked; the draft is untouched, and a failed refresh keeps the old choices.
     */
    public function onResume(): void
    {
        if (! $this->ready) {
            return;
        }

        try {
            $this->options = app(WxNotifyApi::class)->alertRuleOptions();
            app(LocalCache::class)->rememberOptions($this->options);
        } catch (Unauthenticated) {
            $this->signOutLocally();
        } catch (ApiException) {
            // Offline or a server hiccup: the choices already on screen stay.
        }
    }

    public function presenter(): RuleFormPresenter
    {
        return new RuleFormPresenter($this->options);
    }

    // ---- draft changes -------------------------------------------------------------------------

    public function chooseCategory(string $category): void
    {
        if ($category === $this->draft['category']) {
            return;
        }

        $this->draft = $this->presenter()->switchCategory($this->draft, $category);
        $this->errors = [];
        $this->banner = null;
        $this->officeResults = [];
        $this->zoneResults = [];
        $this->pickerMessage = null;
    }

    public function setProduct(int $id, bool $on): void
    {
        $this->toggleIn('product_ids', $id, $on);
        $this->draft = $this->presenter()->reconcile($this->draft);
    }

    public function setRiskLevel(string $level, bool $on): void
    {
        $this->toggleIn('risk_levels', $level, $on);
    }

    public function chooseMode(string $mode): void
    {
        $this->draft['matching_mode'] = $mode;
        $this->draft = $this->presenter()->reconcile($this->draft);
        unset($this->errors['matching_mode']);
    }

    public function setLocation(int $id, bool $on): void
    {
        $this->toggleIn('location_ids', $id, $on);
    }

    public function setChannel(int $id, bool $on): void
    {
        $this->toggleIn('channel_ids', $id, $on);
    }

    public function setZone(string $code, bool $on): void
    {
        $this->toggleIn('county_zone_codes', $code, $on);
    }

    public function setTime(string $field, string $value): void
    {
        $this->draft[$field] = $value;
        unset($this->errors[$field]);
    }

    public function clearTime(string $field): void
    {
        $this->setTime($field, '');
    }

    /**
     * A select element reports the chosen label, never an id: map it back through this field's own
     * label list (duplicate names are numbered, so every choice has a unique label).
     */
    public function pick(string $field, string $label): void
    {
        $map = $this->labelMap($field);

        if (! array_key_exists($label, $map)) {
            return;
        }

        $value = $map[$label];

        $this->draft[$field] = in_array($field, ['location_id', 'alert_template_id'], true)
            ? ($value === '' ? null : (int) $value)
            : (string) $value;

        unset($this->errors[$field]);
    }

    /**
     * @return array<int, string> the labels the select for a field offers, in order
     */
    public function selectLabels(string $field): array
    {
        return array_map('strval', array_keys($this->labelMap($field)));
    }

    public function selectedLabel(string $field): string
    {
        foreach ($this->labelMap($field) as $label => $value) {
            if ((string) $value === (string) ($this->draft[$field] ?? '')) {
                return (string) $label;
            }
        }

        return '';
    }

    // ---- pickers -------------------------------------------------------------------------------

    public function searchOffices(): void
    {
        $this->pickerMessage = null;

        try {
            if ($this->offices === []) {
                $this->offices = app(WxNotifyApi::class)->nwsOffices();
            }
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ApiException) {
            $this->pickerMessage = 'Couldn\'t load the offices. Check your connection and try again.';

            return;
        }

        $needle = mb_strtolower(trim($this->officeQuery));

        $this->officeResults = array_slice(array_values(array_filter(
            $this->offices,
            fn (array $office): bool => $needle === '' || str_contains(mb_strtolower($office['label']), $needle),
        )), 0, self::MAX_OFFICE_RESULTS);
    }

    public function chooseOffice(string $code): void
    {
        $this->draft['wfo'] = $code;
        $this->officeResults = [];
        unset($this->errors['wfo']);
    }

    public function searchZones(): void
    {
        $this->pickerMessage = null;

        $state = strtoupper(trim($this->zoneState));
        $state = preg_match('/^[A-Z]{2}$/', $state) === 1 ? $state : null;
        $query = trim($this->zoneQuery);

        if ($state === null && mb_strlen($query) < 2) {
            $this->pickerMessage = 'Enter a two-letter state or at least two letters of a county or zone name.';

            return;
        }

        $this->fetchZones($state, $query === '' ? null : $query, null, false);
    }

    public function moreZones(): void
    {
        if ($this->zoneNextAfter === null) {
            return;
        }

        $state = strtoupper(trim($this->zoneState));
        $state = preg_match('/^[A-Z]{2}$/', $state) === 1 ? $state : null;
        $query = trim($this->zoneQuery);

        $this->fetchZones($state, $query === '' ? null : $query, $this->zoneNextAfter, true);
    }

    // ---- saving and leaving ----------------------------------------------------------------------

    public function save(): void
    {
        $this->banner = null;
        $this->errors = $this->presenter()->validate($this->draft);

        if ($this->errors !== []) {
            $this->banner = 'Some details need fixing.';

            return;
        }

        try {
            $rule = app(WxNotifyApi::class)->saveAlertRule($this->ruleId, $this->presenter()->payload($this->draft));
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ValidationFailed $e) {
            $this->errors = $e->errors;
            $this->banner = 'Some details need fixing.';

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to save.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t save the rule. Please try again.';

            return;
        }

        $this->rememberSaved($rule);
        $this->original = $this->draft;
        $this->back();
    }

    public function askDelete(): void
    {
        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    public function delete(): void
    {
        if ($this->ruleId === null) {
            return;
        }

        $this->banner = null;

        try {
            app(WxNotifyApi::class)->deleteAlertRule($this->ruleId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (Rejected) {
            // Already gone (deleted elsewhere): the list should drop it too.
        } catch (Maintenance $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->confirmingDelete = false;
            $this->banner = 'You\'re offline. Reconnect to delete this rule.';

            return;
        } catch (ApiException) {
            $this->confirmingDelete = false;
            $this->banner = 'Couldn\'t delete the rule. Please try again.';

            return;
        }

        $cache = app(LocalCache::class);
        $cache->rememberRules(array_values(array_filter($cache->rules() ?? [], fn (array $rule): bool => $rule['id'] !== $this->ruleId)));

        $this->original = $this->draft;
        $this->back();
    }

    public function isDirty(): bool
    {
        return $this->draft !== $this->original;
    }

    /**
     * The system back gesture: leave straight away unless there are unsaved edits.
     */
    public function onBackPressed(): void
    {
        if ($this->ready && $this->isDirty()) {
            $this->confirmingDiscard = true;

            return;
        }

        $this->back();
    }

    public function discard(): void
    {
        $this->original = $this->draft;
        $this->back();
    }

    public function keepEditing(): void
    {
        $this->confirmingDiscard = false;
    }

    public function openAccount(): void
    {
        $this->navigate('/account');
    }

    public function addLocation(): void
    {
        $this->navigate('/locations/new');
    }

    // ---- helpers -------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null the rule, or null with a banner (or a sign-out) when it cannot be opened
     */
    private function loadRule(int $id): ?array
    {
        $cached = collect(app(LocalCache::class)->rules() ?? [])->firstWhere('id', $id);

        try {
            return app(WxNotifyApi::class)->alertRule($id);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return null;
        } catch (Rejected) {
            $this->banner = 'This rule no longer exists.';

            return null;
        } catch (ApiException) {
            if ($cached === null) {
                $this->banner = 'Connect to the internet to open this rule.';
            }

            return $cached;
        }
    }

    private function toggleIn(string $field, int|string $value, bool $on): void
    {
        $list = array_values(array_filter($this->draft[$field], fn (mixed $item): bool => $item !== $value));

        if ($on) {
            $list[] = $value;
        }

        $this->draft[$field] = $list;
        unset($this->errors[$field]);
    }

    /**
     * @return array<int|string, string> value => label for a select field
     */
    private function choices(string $field): array
    {
        $options = $this->options;

        return match ($field) {
            'variable' => collect($options['thresholds']['variables'] ?? [])->pluck('label', 'value')->all(),
            'direction' => collect($options['thresholds']['directions'] ?? [])->pluck('label', 'value')->all(),
            'timeframe' => collect($options['thresholds']['timeframes'] ?? [])->pluck('label', 'value')->all(),
            'message_body_style' => ['' => 'Default'] + collect($options['message_body_styles'] ?? [])->pluck('label', 'value')->all(),
            'location_id' => collect($options['locations'] ?? [])->pluck('label', 'id')->all(),
            'alert_template_id' => ['' => 'None'] + collect($options['templates'][$this->draft['category']] ?? [])->pluck('name', 'id')->all(),
            default => [],
        };
    }

    /**
     * @return array<string, int|string> label => value, a repeated label numbered "Home (2)"
     */
    private function labelMap(string $field): array
    {
        $map = [];
        $seen = [];

        foreach ($this->choices($field) as $value => $label) {
            $label = (string) $label;
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            $map[$seen[$label] === 1 ? $label : "{$label} ({$seen[$label]})"] = $value;
        }

        return $map;
    }

    private function fetchZones(?string $state, ?string $query, ?string $after, bool $append): void
    {
        try {
            $page = app(WxNotifyApi::class)->zones($state, $query, $after);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ApiException) {
            $this->pickerMessage = 'Couldn\'t search right now. Try again.';

            return;
        }

        $this->zoneResults = $append ? [...$this->zoneResults, ...$page['data']] : $page['data'];
        $this->zoneNextAfter = $page['next_after'];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function rememberSaved(array $rule): void
    {
        $cache = app(LocalCache::class);
        $rules = $cache->rules() ?? [];
        $replaced = false;

        foreach ($rules as $index => $existing) {
            if ($existing['id'] === $rule['id']) {
                $rules[$index] = $rule;
                $replaced = true;
            }
        }

        if (! $replaced) {
            array_unshift($rules, $rule);
        }

        $cache->rememberRules($rules);
    }

    private function signOutLocally(): void
    {
        app(SignOut::class)->clearLocal();
        $this->replace('/sign-in');
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/native/rule-editor.blade.php`. It follows the dashboard wizard's four sections in one scrolling form. (If the probe ruled `native:accordion` unusable, replace each product group's accordion with a `native:pressable` header and an `expanded` list as the Task 0 ruling says; if `native:date-picker` is unusable, use the `outlined-text-input` ruling for the three time fields. Keep every `ref` and button label below.)

```blade
@php
    $presenter = $ready ? $this->presenter() : null;
    $category = $ready ? $draft['category'] : '';
    $errorKeys = ['products' => 'product_ids', 'locations' => 'location_ids', 'location' => 'location_id'];
    $isProductCategory = in_array($category, ['weather_alerts', 'spc_wpc'], true);
@endphp

<native:top-bar title="{{ $ruleId === null ? 'New alert rule' : 'Edit alert rule' }}" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($banner)
            <native:text class="text-sm text-theme-destructive">{{ $banner }}</native:text>
        @endif

        @if ($confirmingDiscard)
            <native:column class="w-full p-3 gap-2 bg-theme-surface">
                <native:text class="text-base text-theme-on-background">Discard your changes?</native:text>
                <native:button label="Discard changes" @tap="discard" class="w-full" />
                <native:button label="Keep editing" variant="secondary" @tap="keepEditing" class="w-full" />
            </native:column>
        @endif

        @if ($ready)
            <native:text class="text-lg font-bold text-theme-on-background">What kind of alert?</native:text>
            @foreach ($presenter->categories() as $choice)
                <native:pressable @tap="chooseCategory('{{ $choice['value'] }}')" ref="category-{{ $choice['value'] }}" class="w-full">
                    <native:column class="w-full p-3 gap-1 bg-theme-surface">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $choice['label'] }}{{ $category === $choice['value'] ? '  ✓' : '' }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $choice['description'] }}</native:text>
                    </native:column>
                </native:pressable>
            @endforeach
            @isset($errors['category'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['category'] }}</native:text>
            @endisset

            @if ($category !== '')
                <native:text class="text-lg font-bold text-theme-on-background">Set it up</native:text>

                @foreach ($presenter->fields($draft) as $field)
                    @switch($field)
                        @case('products')
                            @foreach ($presenter->productGroups($draft) as $group)
                                @php($open = $loop->first || count(array_intersect(array_column($group['products'], 'id'), $draft['product_ids'])) > 0)
                                <native:accordion :expanded="$open">
                                    <native:accordion-header>
                                        <native:text class="text-base font-semibold text-theme-on-background">{{ $group['label'] }}</native:text>
                                    </native:accordion-header>
                                    <native:accordion-content>
                                        <native:column class="w-full gap-1">
                                            @foreach ($group['products'] as $product)
                                                <native:checkbox :value="in_array($product['id'], $draft['product_ids'], true)" label="{{ $product['name'] }}" @change="setProduct({{ $product['id'] }})" ref="product-{{ $product['id'] }}" />
                                            @endforeach
                                        </native:column>
                                    </native:accordion-content>
                                </native:accordion>
                            @endforeach
                            @break

                        @case('risk_levels')
                            <native:text class="text-base font-semibold text-theme-on-background">Risk levels</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">Leave all unchecked to receive every risk level for the products above.</native:text>
                            @foreach ($presenter->riskLevelChoices($draft) as $level)
                                <native:checkbox :value="in_array($level, $draft['risk_levels'], true)" label="{{ $level }}" @change="setRiskLevel('{{ $level }}')" ref="risk-{{ $level }}" />
                            @endforeach
                            @break

                        @case('matching_mode')
                            <native:text class="text-base font-semibold text-theme-on-background">Matching mode</native:text>
                            @foreach ($presenter->allowedModes($draft) as $mode)
                                <native:pressable @tap="chooseMode('{{ $mode['value'] }}')" ref="mode-{{ $mode['value'] }}" class="w-full">
                                    <native:column class="w-full p-3 gap-1 bg-theme-surface">
                                        <native:text class="text-base text-theme-on-background">{{ $mode['label'] }}{{ $draft['matching_mode'] === $mode['value'] ? '  ✓' : '' }}</native:text>
                                        <native:text class="text-sm text-theme-on-surface-variant">{{ $mode['description'] }}</native:text>
                                    </native:column>
                                </native:pressable>
                            @endforeach
                            @break

                        @case('locations')
                            <native:text class="text-base font-semibold text-theme-on-background">Locations</native:text>
                            @forelse ($options['locations'] as $location)
                                <native:checkbox :value="in_array($location['id'], $draft['location_ids'], true)" label="{{ $location['label'] }}" @change="setLocation({{ $location['id'] }})" ref="location-{{ $location['id'] }}" />
                            @empty
                                <native:text class="text-sm text-theme-on-surface-variant">You have no saved locations yet.</native:text>
                            @endforelse
                            @if ($options['can_create_location'] ?? false)
                                <native:button label="Add a location" variant="secondary" @tap="addLocation" class="w-full" />
                            @endif
                            @break

                        @case('wfo')
                            <native:text class="text-base font-semibold text-theme-on-background">NWS office</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $draft['wfo'] === '' ? 'No office chosen yet.' : 'Chosen: '.$draft['wfo'] }}</native:text>
                            <native:outlined-text-input label="Search offices by city or code" native:model="officeQuery" ref="office-query" />
                            <native:button label="Search offices" variant="secondary" @tap="searchOffices" class="w-full" />
                            @foreach ($officeResults as $office)
                                <native:pressable @tap="chooseOffice('{{ $office['code'] }}')" ref="office-{{ $office['code'] }}" class="w-full">
                                    <native:text class="text-base text-theme-on-background p-2">{{ $office['label'] }}</native:text>
                                </native:pressable>
                            @endforeach
                            @break

                        @case('county_zone_codes')
                            <native:text class="text-base font-semibold text-theme-on-background">Counties and zones</native:text>
                            @foreach ($draft['county_zone_codes'] as $code)
                                <native:checkbox :value="true" label="{{ $code }}" @change="setZone('{{ $code }}')" ref="zone-{{ $code }}" />
                            @endforeach
                            <native:outlined-text-input label="State (two letters)" native:model="zoneState" ref="zone-state" />
                            <native:outlined-text-input label="County or zone name" native:model="zoneQuery" ref="zone-query" />
                            <native:button label="Search zones" variant="secondary" @tap="searchZones" class="w-full" />
                            @foreach ($zoneResults as $zone)
                                @unless (in_array($zone['code'], $draft['county_zone_codes'], true))
                                    <native:checkbox :value="false" label="{{ $zone['label'] }}" @change="setZone('{{ $zone['code'] }}')" ref="zone-{{ $zone['code'] }}" />
                                @endunless
                            @endforeach
                            @if ($zoneNextAfter)
                                <native:button label="More results" variant="secondary" @tap="moreZones" class="w-full" />
                            @endif
                            @break

                        @case('variable')
                        @case('direction')
                        @case('timeframe')
                            <native:select label="{{ ['variable' => 'Variable', 'direction' => 'Direction', 'timeframe' => 'Timeframe'][$field] }}" :options="$this->selectLabels($field)" :value="$this->selectedLabel($field)" @change="pick('{{ $field }}')" ref="field-{{ $field }}" />
                            @break

                        @case('threshold_value')
                            <native:outlined-text-input label="Value{{ $presenter->unitLabel($draft) ? ' ('.$presenter->unitLabel($draft).')' : '' }}" native:model="draft.threshold_value" keyboard="decimal" ref="field-threshold_value" />
                            @break

                        @case('location')
                            <native:select label="Location" :options="$this->selectLabels('location_id')" :value="$this->selectedLabel('location_id')" @change="pick('location_id')" ref="field-location" />
                            @if (($options['locations'] ?? []) === [])
                                <native:text class="text-sm text-theme-on-surface-variant">You have no saved locations yet.</native:text>
                            @endif
                            @if ($options['can_create_location'] ?? false)
                                <native:button label="Add a location" variant="secondary" @tap="addLocation" class="w-full" />
                            @endif
                            @break

                        @case('delivery_time')
                            <native:date-picker mode="time" label="Delivery time (when we check and deliver this alert each day)" :value="$draft['delivery_time']" @change="setTime('delivery_time')" ref="field-delivery_time" />
                            @break

                        @case('radius')
                            <native:outlined-text-input label="Alert radius ({{ $presenter->radiusUnit() }})" native:model="draft.radius" keyboard="number" ref="field-radius" />
                            <native:text class="text-sm text-theme-on-surface-variant">From {{ $presenter->radiusBounds()['min'] }} to {{ $presenter->radiusBounds()['max'] }} {{ $presenter->radiusUnit() }}.</native:text>
                            @break
                    @endswitch

                    @php($errorKey = $errorKeys[$field] ?? $field)
                    @isset($errors[$errorKey])
                        <native:text class="text-sm text-theme-destructive">{{ $errors[$errorKey] }}</native:text>
                    @endisset
                @endforeach
                @if (($errors['location_id'] ?? null) && ! in_array('location', $presenter->fields($draft), true))
                    <native:text class="text-sm text-theme-destructive">{{ $errors['location_id'] }}</native:text>
                @endif
            @endif

            <native:text class="text-lg font-bold text-theme-on-background">Notify me on</native:text>
            @forelse ($options['channels'] as $channel)
                <native:checkbox :value="in_array($channel['id'], $draft['channel_ids'], true)" label="{{ $channel['label'] }} ({{ $channel['type_label'] }}){{ $channel['verified'] ? '' : ' (unverified)' }}" @change="setChannel({{ $channel['id'] }})" ref="channel-{{ $channel['id'] }}" />
            @empty
                <native:text class="text-sm text-theme-on-surface-variant">You have no channels yet. Turn on push notifications in Account, or add a channel on wxnotify.com.</native:text>
                <native:button label="Open Account" variant="secondary" @tap="openAccount" class="w-full" />
            @endforelse
            @isset($errors['channel_ids'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['channel_ids'] }}</native:text>
            @endisset

            <native:text class="text-lg font-bold text-theme-on-background">Finishing touches</native:text>
            <native:outlined-text-input label="Name this alert (optional)" native:model="draft.name" ref="field-name" />
            <native:text class="text-sm text-theme-on-surface-variant">Leave blank and we'll generate one from your settings.</native:text>

            <native:date-picker mode="time" label="Quiet hours start (optional)" :value="$draft['quiet_hours_start']" @change="setTime('quiet_hours_start')" ref="field-quiet_hours_start" />
            <native:date-picker mode="time" label="Quiet hours end (optional)" :value="$draft['quiet_hours_end']" @change="setTime('quiet_hours_end')" ref="field-quiet_hours_end" />
            @if ($draft['quiet_hours_start'] !== '' || $draft['quiet_hours_end'] !== '')
                <native:button label="Clear quiet hours" variant="secondary" @tap="clearQuietHours" class="w-full" />
            @endif
            @isset($errors['quiet_hours_start'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['quiet_hours_start'] }}</native:text>
            @endisset
            @isset($errors['quiet_hours_end'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['quiet_hours_end'] }}</native:text>
            @endisset

            @if ($presenter->canCustomizeMessages())
                <native:select label="Message length" :options="$this->selectLabels('message_body_style')" :value="$this->selectedLabel('message_body_style')" @change="pick('message_body_style')" ref="field-message_body_style" />
                <native:outlined-text-input label="Custom subject line (optional)" native:model="draft.custom_subject_template" ref="field-custom_subject_template" />
                <native:text class="text-sm text-theme-on-surface-variant">Placeholders: {product}, {location}, {category}.</native:text>
                @if ($isProductCategory)
                    <native:select label="Message template (optional)" :options="$this->selectLabels('alert_template_id')" :value="$this->selectedLabel('alert_template_id')" @change="pick('alert_template_id')" ref="field-alert_template_id" />
                @endif
            @endif

            <native:button label="Save rule" @tap="save" class="w-full" />

            @if ($ruleId !== null)
                @if ($confirmingDelete)
                    <native:column class="w-full p-3 gap-2 bg-theme-surface">
                        <native:text class="text-base text-theme-on-background">Delete this alert rule? This can't be undone.</native:text>
                        <native:button label="Yes, delete" @tap="delete" class="w-full" />
                        <native:button label="Keep rule" variant="secondary" @tap="cancelDelete" class="w-full" />
                    </native:column>
                @else
                    <native:button label="Delete rule" variant="secondary" @tap="askDelete" class="w-full" />
                @endif
            @endif
        @endif
    </native:column>
</native:scroll-view>
```

The view calls `clearQuietHours`: add to the component:

```php
    public function clearQuietHours(): void
    {
        $this->draft['quiet_hours_start'] = '';
        $this->draft['quiet_hours_end'] = '';
        unset($this->errors['quiet_hours_start'], $this->errors['quiet_hours_end']);
    }
```

Register the routes in `routes/mobile.php` (the new route first so `new` is not read as an id), with the `use App\NativeComponents\RuleEditor;` line:

```php
Route::native('/rules/new', RuleEditor::class);
Route::native('/rules/{id}', RuleEditor::class);
```

- [ ] **Step 5: Run the tests and fix until they pass**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/RuleEditorTest.php tests/Feature/RuleFormPresenterTest.php tests/Feature/RulesScreenTest.php` and `cd mobile && php artisan native:validate`. Expected: PASS and validate clean. Typical friction to fix in the view, not the tests' intent: an element attribute name `native:validate` rejects; a Blade `@switch` inside the native compiler (if it does not compile, replace with an `@if/@elseif` chain); `ref` on an element type that ignores it (then drive that element by its label); the handler-argument convention from the probe. Do not weaken an assertion to get green; if a behaviour cannot be driven in the harness, record it as a device-check item and say so.

- [ ] **Step 6: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && php artisan native:validate
cd .. && git add mobile
git commit -m "App: the alert rule editor (create, edit, delete) with a draft and server field errors

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 11: The Locations list and the location editor

**Files:**
- Create: `app/NativeComponents/LocationsScreen.php`, `app/NativeComponents/LocationEditor.php`, `resources/views/native/locations-screen.blade.php`, `resources/views/native/location-editor.blade.php`
- Modify: `routes/mobile.php`, `tests/Fakes/RecordingGeolocation.php`
- Test: `tests/Feature/LocationsScreenTest.php`, `tests/Feature/LocationEditorTest.php`

**Interfaces:**
- Consumes: `WxNotifyApi::{locations, saveLocation, deleteLocation, searchPlaces, reversePlace}`, `LocalCache::{locations, locationsMeta, rememberLocations}`, `ValidationFailed`, `Conflict`, `LocationLimitReached`, `Maintenance`, the `native.partials.tabs` partial, the location JSON from Task 4; the core `Native\Mobile\Geolocation` one-shot `getCurrentPosition()` and `requestPermissions()` with the `LocationReceived` and `PermissionRequestResult` events (`Native\Mobile\Events\Geolocation\*`); `Native\Mobile\Facades\System::appSettings()`.
- Produces: routes `/locations`, `/locations/new`, `/locations/{id}`; `LocationsScreen` (public `array $locations`, `array $meta`, `bool $offline`, `bool $loaded`, `?string $error`, `?string $message`; `refresh()`, `create()`, `open(int $id)`); `LocationEditor` (public `?int $locationId`, `array $location`, `array $draft`, `array $original`, `array $errors`, `?string $banner`, `bool $ready`, `string $placeQuery`, `array $placeResults`, `?string $positionMessage`, `bool $locating`, `bool $permissionDenied`; handlers `useCurrentPosition()`, `searchPlaces()`, `choosePlace(int $index)`, `pickTimezone(string $label)`, `pickInterval(string $label)`, `save()`, `askDelete()`, `cancelDelete()`, `delete()`, `discard()`, `keepEditing()`, `openSettings()`).
- Draft keys: `label`, `address`, `lat` (string), `lng` (string), `timezone` (`''` means automatic), `update_interval_minutes` (string).
- Test refs: `location-{id}` (a list row pressable), `place-{index}` (a result pressable); buttons by label: `Add location`, `Use my current position`, `Search`, `Save location`, `Delete location`, `Yes, delete`, `Keep location`, `Discard changes`, `Keep editing`, `Open settings`.

- [ ] **Step 1: Extend the geolocation fake**

In `tests/Fakes/RecordingGeolocation.php` add `use Native\Mobile\PendingGeolocation;`, two public properties and two overrides (the builders they return are the real, inert-off-device ones):

```php
    /** @var list<array{fine: bool}> */
    public array $positionRequests = [];

    public int $permissionRequests = 0;

    public function getCurrentPosition(bool $fineAccuracy = false): PendingGeolocation
    {
        $this->positionRequests[] = ['fine' => $fineAccuracy];

        return parent::getCurrentPosition($fineAccuracy);
    }

    public function requestPermissions(): PendingGeolocation
    {
        $this->permissionRequests++;

        return parent::requestPermissions();
    }
```

- [ ] **Step 2: Write the failing list-screen tests**

Create `tests/Feature/LocationsScreenTest.php`:

```php
<?php

use App\NativeComponents\LocationsScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function listedLocation(array $overrides = []): array
{
    return [
        'id' => 1, 'label' => 'Home', 'type' => 'stationary', 'source' => null, 'source_label' => null, 'address' => '1 Main Street, Norman',
        'lat' => 35.2, 'lng' => -97.4, 'timezone' => 'America/Chicago', 'update_interval_minutes' => null, 'linked' => null, 'device_name' => null,
        'has_missing_device' => false, 'has_fix' => true, 'last_fix_at' => null, 'position_updated_at' => null, 'stale' => null,
        ...$overrides,
    ];
}

function locationsResponse(array $locations, array $meta = ['can_create' => true, 'limit' => 5]): array
{
    return ['data' => $locations, 'meta' => $meta];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the list shows each location with what kind it is and how current it is', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([
        listedLocation(),
        listedLocation(['id' => 2, 'label' => 'Pixel 9', 'type' => 'mobile', 'source' => 'app', 'source_label' => 'WxNotify app', 'address' => null, 'linked' => true, 'stale' => false]),
        listedLocation(['id' => 3, 'label' => 'Old phone', 'type' => 'mobile', 'source' => 'app', 'source_label' => 'WxNotify app', 'address' => null, 'linked' => false, 'has_missing_device' => true, 'stale' => true]),
        listedLocation(['id' => 4, 'label' => 'Truck', 'type' => 'mobile', 'source' => 'traccar', 'source_label' => 'Traccar', 'address' => null, 'device_name' => 'F-150', 'stale' => true]),
    ]))]);

    Native::test(LocationsScreen::class)
        ->assertSee('Home')
        ->assertSee('1 Main Street, Norman')
        ->assertSee('Pixel 9')
        ->assertSee('WxNotify app · Current')
        ->assertSee('Old phone')
        ->assertSee('WxNotify app · Not linked to a phone')
        ->assertSee('Truck')
        ->assertSee('Traccar · No recent update')
        ->assertHasTab('Locations')
        ->assertTabActive('Locations');
});

test('the list is remembered for offline use, and offline shows it with a note', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([listedLocation()]))]);

    Native::test(LocationsScreen::class);

    expect((new LocalCache)->locations())->toBe([listedLocation()])->and((new LocalCache)->locationsMeta())->toBe(['can_create' => true, 'limit' => 5]);

    $this->network->connected = false;

    Native::test(LocationsScreen::class)->assertSee('Home')->assertSee('offline');
});

test('with no locations the screen explains how to add one', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([]))]);

    Native::test(LocationsScreen::class)->assertSee('No locations yet')->assertSee('Add location');
});

test('tapping a location opens its editor and the add button opens a new one', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([listedLocation()]))]);

    Native::test(LocationsScreen::class)->tap('location-1')->assertNavigatedTo('/locations/1');
    Native::test(LocationsScreen::class)->tap('Add location')->assertNavigatedTo('/locations/new');
});

test('at the plan limit the add button explains the limit instead of opening the editor', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([listedLocation()], ['can_create' => false, 'limit' => 1]))]);

    Native::test(LocationsScreen::class)
        ->tap('Add location')
        ->assertSee('Your plan allows 1 location')
        ->assertNoNavigation();
});

test('a refused token returns to sign in and a signed-out visitor is sent there', function () {
    Http::fake(['*' => Http::response([], 401)]);
    Native::test(LocationsScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->forget();
    Native::test(LocationsScreen::class)->assertReplacedWith('/sign-in');
});
```

- [ ] **Step 3: Write the failing editor tests**

Create `tests/Feature/LocationEditorTest.php`:

```php
<?php

use App\NativeComponents\LocationEditor;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Events\Geolocation\PermissionRequestResult;
use Native\Mobile\Facades\System;
use Native\Mobile\Testing\Native;

function editorLocation(array $overrides = []): array
{
    return [
        'id' => 1, 'label' => 'Home', 'type' => 'stationary', 'source' => null, 'source_label' => null, 'address' => '1 Main Street, Norman',
        'lat' => 35.2226, 'lng' => -97.4395, 'timezone' => 'America/Chicago', 'update_interval_minutes' => null, 'linked' => null, 'device_name' => null,
        'has_missing_device' => false, 'has_fix' => true, 'last_fix_at' => null, 'position_updated_at' => null, 'stale' => null,
        ...$overrides,
    ];
}

function phoneLocation(array $overrides = []): array
{
    return editorLocation(['id' => 2, 'label' => 'Pixel 9', 'type' => 'mobile', 'source' => 'app', 'source_label' => 'WxNotify app', 'timezone' => null, 'update_interval_minutes' => 5, 'linked' => true, 'stale' => false, ...$overrides]);
}

/**
 * Fakes the locations endpoint (GET answers the list, POST a created location) plus any extra
 * routes; an extra route with the same key replaces the default. Call once per test: Http::fake
 * calls merge and the first match wins.
 */
function locationFakes(array $extra = [], array $listed = []): void
{
    Http::fake([
        'wx.test/api/v1/locations' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['data' => ['id' => 9, 'label' => 'Cabin']], 201)
            : Http::response(['data' => $listed, 'meta' => ['can_create' => true, 'limit' => 5]]),
        ...$extra,
    ]);
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('a new location offers the current position and an address search, and needs both a name and a position', function () {
    locationFakes();

    Native::test(LocationEditor::class)
        ->assertNavTitle('New location')
        ->assertSee('Use my current position')
        ->assertSee('Search for an address')
        ->tap('Save location')
        ->assertSee('Give this location a name.')
        ->assertSee('Choose a position')
        ->assertNoNavigation();

    Http::assertNothingSent();
});

test('an address search offers places, choosing one fills the position and a location is saved', function () {
    locationFakes(['wx.test/api/v1/places/search*' => Http::response(['data' => [
        ['label' => '1 Lake Road, Norman, Oklahoma, United States', 'lat' => 35.2226, 'lng' => -97.4395],
        ['label' => '2 Lake Road, Norman, Oklahoma, United States', 'lat' => 35.23, 'lng' => -97.44],
    ]])]);

    Native::test(LocationEditor::class)
        ->set('placeQuery', 'Lake Road Norman')
        ->tap('Search')
        ->assertSee('1 Lake Road, Norman, Oklahoma, United States')
        ->tap('place-0')
        ->assertSet('draft.lat', '35.2226')
        ->assertSet('draft.address', '1 Lake Road, Norman, Oklahoma, United States')
        ->assertSee('1 Lake Road, Norman, Oklahoma, United States')
        ->input('field-label', 'Cabin')
        ->tap('Save location')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/locations' && $r->data() == [
        'label' => 'Cabin', 'lat' => 35.2226, 'lng' => -97.4395, 'address' => '1 Lake Road, Norman, Oklahoma, United States',
    ]);
});

test('an address search needs three characters and reports a busy or offline service', function () {
    locationFakes(['wx.test/api/v1/places/search*' => Http::response(['error' => 'busy'], 429)]);

    $screen = Native::test(LocationEditor::class)
        ->set('placeQuery', 'ab')
        ->tap('Search')
        ->assertSee('Type at least three characters');

    $screen->set('placeQuery', 'Norman')->tap('Search')->assertSee('busy or unavailable');

    $this->network->connected = false;

    $screen->tap('Search')->assertSee('offline');
});

test('the current position is requested, then its address is looked up, and the position fills the draft', function () {
    locationFakes(['wx.test/api/v1/places/reverse*' => Http::response(['data' => ['address' => '1 Main Street, Norman']])]);

    Native::test(LocationEditor::class)
        ->tap('Use my current position')
        ->assertSet('locating', true)
        ->emitNative(LocationReceived::class, ['success' => true, 'latitude' => 35.2226, 'longitude' => -97.4395, 'accuracy' => 20.0, 'id' => 'wxnotify-place'])
        ->assertSet('locating', false)
        ->assertSet('draft.lat', '35.222600')
        ->assertSet('draft.address', '1 Main Street, Norman')
        ->assertSee('1 Main Street, Norman');

    expect($this->geolocation->positionRequests)->toHaveCount(1);
});

test('a position whose address cannot be found is still kept', function () {
    locationFakes(['wx.test/api/v1/places/reverse*' => Http::response(['error' => 'unavailable'], 502)]);

    Native::test(LocationEditor::class)
        ->tap('Use my current position')
        ->emitNative(LocationReceived::class, ['success' => true, 'latitude' => 35.2, 'longitude' => -97.4, 'id' => 'wxnotify-place'])
        ->assertSet('draft.lat', '35.200000')
        ->assertSet('draft.address', '')
        ->assertSee('couldn\'t look up the address');
});

test('without location permission it asks once, retries when granted and otherwise points to the settings', function () {
    locationFakes();
    System::shouldReceive('appSettings')->once();

    $screen = Native::test(LocationEditor::class)
        ->tap('Use my current position')
        ->emitNative(LocationReceived::class, ['success' => false, 'error' => 'Location permission denied', 'id' => 'wxnotify-place'])
        ->assertSet('locating', true);

    expect($this->geolocation->permissionRequests)->toBe(1);

    $screen->emitNative(PermissionRequestResult::class, ['location' => 'granted', 'coarseLocation' => 'granted', 'fineLocation' => 'granted', 'id' => 'wxnotify-place']);

    expect($this->geolocation->positionRequests)->toHaveCount(2);

    $screen
        ->emitNative(LocationReceived::class, ['success' => false, 'error' => 'Location permission denied', 'id' => 'wxnotify-place'])
        ->assertSet('locating', false)
        ->assertSet('permissionDenied', true)
        ->assertSee('Location permission is off')
        ->tap('Open settings');

    expect($this->geolocation->permissionRequests)->toBe(1);
});

test('a position event for another request is ignored', function () {
    locationFakes();

    Native::test(LocationEditor::class)
        ->emitNative(LocationReceived::class, ['success' => true, 'latitude' => 1.0, 'longitude' => 2.0, 'id' => 'something-else'])
        ->assertSet('draft.lat', '');
});

test('an existing stationary location opens filled in, and a rename alone sends only the name', function () {
    (new LocalCache)->rememberLocations([editorLocation()], ['can_create' => true, 'limit' => 5]);
    locationFakes(['wx.test/api/v1/locations/1' => Http::response(['data' => editorLocation(['label' => 'Home base'])])], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->assertNavTitle('Edit location')
        ->assertSet('draft.label', 'Home')
        ->assertSet('draft.lat', '35.2226')
        ->input('field-label', 'Home base')
        ->tap('Save location')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/locations/1' && $r->data() == ['label' => 'Home base']);
    expect(collect((new LocalCache)->locations())->firstWhere('id', 1)['label'])->toBe('Home base');
});

test('moving a location sends the new position, its address and a chosen timezone', function () {
    locationFakes([
        'wx.test/api/v1/places/search*' => Http::response(['data' => [['label' => 'Denver, Colorado', 'lat' => 39.74, 'lng' => -104.99]]]),
        'wx.test/api/v1/locations/1' => Http::response(['data' => editorLocation()]),
    ], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->set('placeQuery', 'Denver')
        ->tap('Search')
        ->tap('place-0')
        ->call('pickTimezone', 'Mountain (Denver)')
        ->tap('Save location')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() == [
        'label' => 'Home', 'lat' => 39.74, 'lng' => -104.99, 'address' => 'Denver, Colorado', 'timezone' => 'America/Denver',
    ]);
});

test('a mobile location edits only its name and update interval and says what keeps it current', function () {
    locationFakes(['wx.test/api/v1/locations/2' => Http::response(['data' => phoneLocation(['update_interval_minutes' => 10])])], [phoneLocation()]);

    Native::test(LocationEditor::class, ['id' => 2])
        ->assertSee('Kept current by a phone running WxNotify')
        ->assertDontSee('Use my current position')
        ->call('pickInterval', '10 minutes')
        ->tap('Save location')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() == ['label' => 'Pixel 9', 'update_interval_minutes' => 10]);
});

test('a mobile location that lost its phone says so', function () {
    locationFakes([], [phoneLocation(['linked' => false, 'has_missing_device' => true])]);

    Native::test(LocationEditor::class, ['id' => 2])->assertSee('Not linked to a phone');
});

test('a Traccar location says which device follows it', function () {
    locationFakes([], [editorLocation(['id' => 3, 'type' => 'mobile', 'source' => 'traccar', 'source_label' => 'Traccar', 'device_name' => 'F-150', 'update_interval_minutes' => 15])]);

    Native::test(LocationEditor::class, ['id' => 3])->assertSee('F-150');
});

test('the server refusing a field puts its message on the page and keeps the draft', function () {
    locationFakes(['wx.test/api/v1/locations/1' => Http::response(['message' => 'Invalid.', 'errors' => ['label' => ['The label field must not be greater than 255 characters.']]], 422)], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->input('field-label', 'Cabin')
        ->tap('Save location')
        ->assertSee('The label field must not be greater than 255 characters.')
        ->assertSet('draft.label', 'Cabin')
        ->assertNoNavigation();
});

test('the plan limit, maintenance and an offline phone each say so on save', function (int $status, string $contentType, string $body, string $text) {
    locationFakes([
        'wx.test/api/v1/places/search*' => Http::response(['data' => [['label' => 'Norman', 'lat' => 35.2, 'lng' => -97.4]]]),
        'wx.test/api/v1/locations' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response($body, $status, ['Content-Type' => $contentType])
            : Http::response(['data' => [], 'meta' => []]),
    ]);

    Native::test(LocationEditor::class)
        ->input('field-label', 'Cabin')
        ->set('placeQuery', 'Norman')
        ->tap('Search')
        ->tap('place-0')
        ->tap('Save location')
        ->assertSee($text)
        ->assertNoNavigation();
})->with([
    'the plan limit' => [422, 'application/json', '{"error":"location_limit"}', 'Your plan\'s location limit is reached.'],
    'maintenance' => [503, 'text/plain', 'Back soon.', 'Back soon.'],
]);

test('backing out with edits asks first', function () {
    locationFakes([], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])->pressBack()->assertWentBack();

    Native::test(LocationEditor::class, ['id' => 1])
        ->input('field-label', 'Cabin')
        ->pressBack()
        ->assertNoNavigation()
        ->assertSee('Discard your changes?')
        ->tap('Discard changes')
        ->assertWentBack();
});

test('a location is deleted after a confirmation', function () {
    locationFakes(['wx.test/api/v1/locations/1' => Http::response(null, 204)], [editorLocation(), editorLocation(['id' => 5])]);

    $screen = Native::test(LocationEditor::class, ['id' => 1]);

    $screen->tap('Delete location')->assertSee('can\'t be undone')->tap('Keep location')->assertSet('confirmingDelete', false);

    $screen->tap('Delete location')->tap('Yes, delete')->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/locations/1');
    expect(collect((new LocalCache)->locations())->pluck('id')->all())->toBe([5]);
});

test('a location that alerts still use, or that follows a phone, is not deleted and the reason is shown', function (array $response, string $text) {
    locationFakes(['wx.test/api/v1/locations/1' => Http::response($response, 409)], [editorLocation()]);

    Native::test(LocationEditor::class, ['id' => 1])
        ->tap('Delete location')
        ->tap('Yes, delete')
        ->assertSee($text)
        ->assertNoNavigation();
})->with([
    'used by alerts' => [['error' => 'location_in_use', 'message' => 'Can\'t delete Home. It\'s used by: Freeze, Storm. Remove it from those alerts first.', 'alerts' => ['Freeze', 'Storm']], 'It\'s used by: Freeze, Storm.'],
    'a phone' => [['error' => 'location_is_a_phone', 'message' => 'Home follows a phone. Stop sharing location on that phone first (Account, in the app).'], 'follows a phone'],
]);

test('a location that no longer exists says so', function () {
    locationFakes([], []);

    Native::test(LocationEditor::class, ['id' => 99])->assertSee('This location no longer exists.');
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();
    Http::fake();

    Native::test(LocationEditor::class)->assertReplacedWith('/sign-in');
});
```

- [ ] **Step 4: Run to verify they fail**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/LocationsScreenTest.php tests/Feature/LocationEditorTest.php`. Expected: FAIL (the screens do not exist).

- [ ] **Step 5: Write the Locations list screen**

Create `app/NativeComponents/LocationsScreen.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\HandlesPushToken;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;

/**
 * The subscriber's locations: stationary places and the mobile ones that follow a phone or a
 * Traccar device, each with how current it is. Paints from the cache first, refreshes, and keeps
 * the last copy with a note when offline.
 */
class LocationsScreen extends NativeComponent
{
    use HandlesPushToken;
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $locations = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    public bool $offline = false;

    public bool $loaded = false;

    public ?string $error = null;

    public ?string $message = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cache = app(LocalCache::class);
        $cached = $cache->locations();
        $this->locations = $cached ?? [];
        $this->meta = $cache->locationsMeta() ?? [];
        $this->loaded = $cached !== null;

        $this->refresh();
    }

    /**
     * Back from the editor (saved, deleted or abandoned): the list may have changed.
     */
    public function onResume(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        try {
            $page = app(WxNotifyApi::class)->locations();
            $this->locations = $page['data'];
            $this->meta = $page['meta'];
            app(LocalCache::class)->rememberLocations($this->locations, $this->meta);
            $this->offline = false;
            $this->error = null;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
        } catch (ApiException) {
            $this->error = 'WxNotify could not load your locations. Try again in a moment.';
        } finally {
            $this->loaded = true;
        }
    }

    /**
     * At the plan limit the add button explains the limit instead of opening an editor that would fail.
     */
    public function create(): void
    {
        $this->message = null;

        if (($this->meta['can_create'] ?? true) === false) {
            $limit = $this->meta['limit'] ?? null;
            $this->message = 'Your plan allows '.$limit.' location'.($limit === 1 ? '' : 's').'. Remove one, or upgrade, to add another.';

            return;
        }

        $this->navigate('/locations/new');
    }

    public function open(int $id): void
    {
        $this->navigate("/locations/{$id}");
    }

    /**
     * What kind of location this is and how current it is, in a few words.
     *
     * @param  array<string, mixed>  $location
     */
    public function subtitle(array $location): string
    {
        if ($location['type'] === 'stationary') {
            return filled($location['address'] ?? null) ? (string) $location['address'] : 'Stationary';
        }

        $source = (string) ($location['source_label'] ?? 'Mobile');

        return match (true) {
            $location['source'] === 'app' && $location['linked'] === false => "{$source} · Not linked to a phone",
            $location['source'] === 'traccar' && $location['has_missing_device'] => "{$source} · Device missing",
            ! $location['has_fix'] => "{$source} · Waiting for a first position",
            $location['stale'] === true => "{$source} · No recent update",
            default => "{$source} · Current",
        };
    }
}
```

Create `resources/views/native/locations-screen.blade.php`:

```blade
<native:top-bar title="Locations" display-mode="large"></native:top-bar>

@include('native.partials.tabs', ['active' => 'locations'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-on-surface-variant">{{ $loaded && count($locations) > 0 ? "You're offline. Showing your locations as last loaded." : "You're offline." }}</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
        </native:row>
    @endif

    @if ($message)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $message }}</native:text>
        </native:row>
    @endif

    <native:scroll-view class="w-full flex-1 bg-theme-background">
        <native:column class="w-full p-4 gap-2">
            <native:button label="Add location" @tap="create" class="w-full" />

            @forelse ($locations as $location)
                <native:pressable @tap="open({{ $location['id'] }})" ref="location-{{ $location['id'] }}" class="w-full">
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $location['label'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($location) }}</native:text>
                    </native:column>
                </native:pressable>
                <native:divider></native:divider>
            @empty
                @if ($loaded)
                    <native:column class="w-full p-6 items-center gap-2">
                        <native:text class="text-lg font-semibold text-theme-on-background">No locations yet</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant text-center">Locations are the places your alerts watch. Tap Add location to save one.</native:text>
                    </native:column>
                @endif
            @endforelse
        </native:column>
    </native:scroll-view>
</native:column>
```

- [ ] **Step 6: Write the location editor**

Create `app/NativeComponents/LocationEditor.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Conflict;
use App\Exceptions\Api\LocationLimitReached;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Events\Geolocation\PermissionRequestResult;
use Native\Mobile\Facades\Geolocation;
use Native\Mobile\Facades\System;

/**
 * Adds or edits one location. A stationary location gets its position from the phone's current
 * position or an address search (no map yet); a mobile location edits only its name and update
 * interval. A draft is held here and nothing is sent until Save.
 */
class LocationEditor extends NativeComponent
{
    use RequiresSignIn;

    private const POSITION_REQUEST = 'wxnotify-place';

    /** @var array<string, string> label => IANA zone: the zones WxNotify's US alerts use; '' is automatic */
    private const TIMEZONES = [
        'Eastern (New York)' => 'America/New_York',
        'Central (Chicago)' => 'America/Chicago',
        'Mountain (Denver)' => 'America/Denver',
        'Arizona (Phoenix)' => 'America/Phoenix',
        'Pacific (Los Angeles)' => 'America/Los_Angeles',
        'Alaska (Anchorage)' => 'America/Anchorage',
        'Hawaii (Honolulu)' => 'Pacific/Honolulu',
        'Puerto Rico' => 'America/Puerto_Rico',
        'Guam' => 'Pacific/Guam',
    ];

    private const AUTOMATIC = 'Automatic (from the position)';

    private const INTERVALS = [5, 10, 15, 20, 25, 30];

    public ?int $locationId = null;

    /** @var array<string, mixed> the location as loaded (empty for a new one) */
    public array $location = [];

    /** @var array<string, string> */
    public array $draft = ['label' => '', 'address' => '', 'lat' => '', 'lng' => '', 'timezone' => '', 'update_interval_minutes' => '15'];

    /** @var array<string, string> */
    public array $original = [];

    /** @var array<string, string> */
    public array $errors = [];

    public ?string $banner = null;

    public bool $ready = false;

    public bool $confirmingDelete = false;

    public bool $confirmingDiscard = false;

    public string $placeQuery = '';

    /** @var array<int, array{label: string, lat: float, lng: float}> */
    public array $placeResults = [];

    public ?string $placeMessage = null;

    public ?string $positionMessage = null;

    public bool $locating = false;

    public bool $permissionAsked = false;

    public bool $permissionDenied = false;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $id = $this->param('id');
        $this->locationId = filled($id) ? (int) $id : null;

        if ($this->locationId !== null) {
            $location = $this->loadLocation($this->locationId);

            if ($location === null) {
                return;
            }

            $this->location = $location;
            $this->draft = [
                'label' => (string) $location['label'],
                'address' => (string) ($location['address'] ?? ''),
                'lat' => $location['lat'] === null ? '' : (string) $location['lat'],
                'lng' => $location['lng'] === null ? '' : (string) $location['lng'],
                'timezone' => (string) ($location['timezone'] ?? ''),
                'update_interval_minutes' => (string) ($location['update_interval_minutes'] ?? 15),
            ];
        }

        $this->original = $this->draft;
        $this->ready = true;
    }

    public function isMobile(): bool
    {
        return ($this->location['type'] ?? 'stationary') === 'mobile';
    }

    // ---- position ------------------------------------------------------------------------------

    public function useCurrentPosition(): void
    {
        $this->positionMessage = 'Finding your position…';
        $this->permissionDenied = false;
        $this->locating = true;

        Geolocation::getCurrentPosition()->id(self::POSITION_REQUEST)->get();
    }

    #[On(LocationReceived::class)]
    public function receivePosition(bool $success, ?float $latitude = null, ?float $longitude = null, ?float $accuracy = null, ?int $timestamp = null, ?string $provider = null, ?string $error = null, ?string $id = null): void
    {
        if (! $this->locating || ($id !== null && $id !== self::POSITION_REQUEST)) {
            return;
        }

        if ($success && $latitude !== null && $longitude !== null) {
            $this->locating = false;
            $this->setPosition(sprintf('%.6f', $latitude), sprintf('%.6f', $longitude), $this->lookUpAddress($latitude, $longitude));

            return;
        }

        if (! $this->permissionAsked && stripos((string) $error, 'permission') !== false) {
            $this->permissionAsked = true;
            Geolocation::requestPermissions()->id(self::POSITION_REQUEST)->get();

            return;
        }

        $this->locating = false;
        $this->permissionDenied = stripos((string) $error, 'permission') !== false;
        $this->positionMessage = $this->permissionDenied
            ? 'Location permission is off. Allow it in your phone\'s settings, or search for an address instead.'
            : 'Couldn\'t get your position. Try again, or search for an address.';
    }

    #[On(PermissionRequestResult::class)]
    public function receivePermission(string $location = '', string $coarseLocation = '', string $fineLocation = '', ?string $error = null, ?string $id = null): void
    {
        if (! $this->locating || ($id !== null && $id !== self::POSITION_REQUEST)) {
            return;
        }

        if (in_array('granted', [$location, $coarseLocation, $fineLocation], true)) {
            Geolocation::getCurrentPosition()->id(self::POSITION_REQUEST)->get();

            return;
        }

        $this->locating = false;
        $this->permissionDenied = true;
        $this->positionMessage = 'Location permission is off. Allow it in your phone\'s settings, or search for an address instead.';
    }

    public function openSettings(): void
    {
        System::appSettings();
    }

    public function searchPlaces(): void
    {
        $this->placeMessage = null;
        $this->placeResults = [];

        if (mb_strlen(trim($this->placeQuery)) < 3) {
            $this->placeMessage = 'Type at least three characters to search.';

            return;
        }

        try {
            $this->placeResults = app(WxNotifyApi::class)->searchPlaces(trim($this->placeQuery));
            $this->placeMessage = $this->placeResults === [] ? 'No places found. Try a fuller address.' : null;
        } catch (Unauthenticated) {
            $this->signOutLocally();
        } catch (Offline) {
            $this->placeMessage = 'You\'re offline. Reconnect to search.';
        } catch (ApiException) {
            $this->placeMessage = 'Search is busy or unavailable. Try again in a moment.';
        }
    }

    public function choosePlace(int $index): void
    {
        $place = $this->placeResults[$index] ?? null;

        if ($place === null) {
            return;
        }

        $this->placeResults = [];
        $this->setPosition((string) $place['lat'], (string) $place['lng'], $place['label']);

        if (trim($this->draft['label']) === '') {
            $this->draft['label'] = trim(explode(',', $place['label'])[0]);
        }
    }

    // ---- other draft changes -------------------------------------------------------------------

    /**
     * @return array<int, string> the labels the timezone select offers
     */
    public function timezoneLabels(): array
    {
        return array_keys($this->timezoneMap());
    }

    public function selectedTimezoneLabel(): string
    {
        return (string) (array_search($this->draft['timezone'], $this->timezoneMap(), true) ?: self::AUTOMATIC);
    }

    public function pickTimezone(string $label): void
    {
        $map = $this->timezoneMap();

        if (array_key_exists($label, $map)) {
            $this->draft['timezone'] = $map[$label];
        }
    }

    /**
     * @return array<int, string>
     */
    public function intervalLabels(): array
    {
        return array_map(fn (int $minutes): string => "{$minutes} minutes", self::INTERVALS);
    }

    public function selectedIntervalLabel(): string
    {
        return $this->draft['update_interval_minutes'].' minutes';
    }

    public function pickInterval(string $label): void
    {
        $minutes = (int) $label;

        if (in_array($minutes, self::INTERVALS, true)) {
            $this->draft['update_interval_minutes'] = (string) $minutes;
        }
    }

    // ---- saving and leaving ----------------------------------------------------------------------

    public function save(): void
    {
        $this->banner = null;
        $this->errors = $this->validateDraft();

        if ($this->errors !== []) {
            return;
        }

        try {
            $location = app(WxNotifyApi::class)->saveLocation($this->locationId, $this->payload());
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (LocationLimitReached) {
            $this->banner = 'Your plan\'s location limit is reached. Remove a location, or upgrade, to add another.';

            return;
        } catch (ValidationFailed $e) {
            $this->errors = $this->mapServerErrors($e->errors);

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to save.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t save the location. Please try again.';

            return;
        }

        $this->rememberSaved($location);
        $this->original = $this->draft;
        $this->back();
    }

    public function askDelete(): void
    {
        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    public function delete(): void
    {
        if ($this->locationId === null) {
            return;
        }

        $this->banner = null;

        try {
            app(WxNotifyApi::class)->deleteLocation($this->locationId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (Conflict $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'This location can\'t be deleted right now.';

            return;
        } catch (Rejected) {
            // Already gone elsewhere: the list should drop it too.
        } catch (Maintenance $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->confirmingDelete = false;
            $this->banner = 'You\'re offline. Reconnect to delete this location.';

            return;
        } catch (ApiException) {
            $this->confirmingDelete = false;
            $this->banner = 'Couldn\'t delete the location. Please try again.';

            return;
        }

        $cache = app(LocalCache::class);
        $cache->rememberLocations(
            array_values(array_filter($cache->locations() ?? [], fn (array $location): bool => $location['id'] !== $this->locationId)),
            $cache->locationsMeta() ?? [],
        );

        $this->original = $this->draft;
        $this->back();
    }

    public function isDirty(): bool
    {
        return $this->draft !== $this->original;
    }

    public function onBackPressed(): void
    {
        if ($this->ready && $this->isDirty()) {
            $this->confirmingDiscard = true;

            return;
        }

        $this->back();
    }

    public function discard(): void
    {
        $this->original = $this->draft;
        $this->back();
    }

    public function keepEditing(): void
    {
        $this->confirmingDiscard = false;
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function setPosition(string $lat, string $lng, string $address): void
    {
        $this->draft['lat'] = $lat;
        $this->draft['lng'] = $lng;
        $this->draft['address'] = $address;
        unset($this->errors['position']);
    }

    /**
     * The address for a fix, or '' (with a message) when none can be found: the position is kept either way.
     */
    private function lookUpAddress(float $lat, float $lng): string
    {
        try {
            $address = app(WxNotifyApi::class)->reversePlace($lat, $lng);
            $this->positionMessage = $address === null ? 'Position found. We couldn\'t look up the address, but you can still save it.' : null;

            return $address ?? '';
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return '';
        } catch (ApiException) {
            $this->positionMessage = 'Position found. We couldn\'t look up the address, but you can still save it.';

            return '';
        }
    }

    /**
     * @return array<string, mixed>|null the location, or null with a banner (or a sign-out) when it cannot be opened
     */
    private function loadLocation(int $id): ?array
    {
        $cache = app(LocalCache::class);
        $location = collect($cache->locations() ?? [])->firstWhere('id', $id);

        try {
            $page = app(WxNotifyApi::class)->locations();
            $cache->rememberLocations($page['data'], $page['meta']);
            $location = collect($page['data'])->firstWhere('id', $id);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return null;
        } catch (ApiException) {
            // Offline or a server hiccup: the remembered copy, if any, is what there is.
        }

        if ($location === null) {
            $this->banner = 'This location no longer exists.';
        }

        return $location;
    }

    /**
     * @return array<string, string>
     */
    private function validateDraft(): array
    {
        $errors = [];

        if (trim($this->draft['label']) === '') {
            $errors['label'] = 'Give this location a name.';
        }

        if (! $this->isMobile() && (! is_numeric($this->draft['lat']) || ! is_numeric($this->draft['lng']))) {
            $errors['position'] = 'Choose a position: use your current position or search for an address.';
        }

        return $errors;
    }

    /**
     * A mobile location sends its name and interval; a stationary one its name plus, only when it
     * changed (or is new), its position with the address, and the timezone when one was chosen.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $body = ['label' => trim($this->draft['label'])];

        if ($this->isMobile()) {
            return [...$body, 'update_interval_minutes' => (int) $this->draft['update_interval_minutes']];
        }

        $moved = $this->locationId === null
            || $this->draft['lat'] !== $this->original['lat']
            || $this->draft['lng'] !== $this->original['lng'];

        if ($moved) {
            $body += [
                'lat' => (float) $this->draft['lat'],
                'lng' => (float) $this->draft['lng'],
                'address' => filled($this->draft['address']) ? $this->draft['address'] : null,
            ];
        }

        if ($this->draft['timezone'] !== '' && ($this->locationId === null || $this->draft['timezone'] !== $this->original['timezone'])) {
            $body['timezone'] = $this->draft['timezone'];
        }

        return $body;
    }

    /**
     * @param  array<string, string>  $serverErrors
     * @return array<string, string>
     */
    private function mapServerErrors(array $serverErrors): array
    {
        $errors = [];

        foreach ($serverErrors as $field => $message) {
            $errors[in_array($field, ['lat', 'lng'], true) ? 'position' : $field] ??= $message;
        }

        return $errors;
    }

    /**
     * @return array<string, string> label => zone, with the automatic choice first and a zone outside the list shown as itself
     */
    private function timezoneMap(): array
    {
        $map = [self::AUTOMATIC => ''] + self::TIMEZONES;

        if ($this->draft['timezone'] !== '' && ! in_array($this->draft['timezone'], $map, true)) {
            $map[$this->draft['timezone']] = $this->draft['timezone'];
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function rememberSaved(array $location): void
    {
        $cache = app(LocalCache::class);
        $locations = $cache->locations() ?? [];
        $replaced = false;

        foreach ($locations as $index => $existing) {
            if ($existing['id'] === ($location['id'] ?? null)) {
                $locations[$index] = $location;
                $replaced = true;
            }
        }

        if (! $replaced && isset($location['id'])) {
            $locations[] = $location;
        }

        $cache->rememberLocations($locations, $cache->locationsMeta() ?? []);
    }

    private function signOutLocally(): void
    {
        app(SignOut::class)->clearLocal();
        $this->replace('/sign-in');
    }
}
```

Create `resources/views/native/location-editor.blade.php`:

```blade
<native:top-bar title="{{ $locationId === null ? 'New location' : 'Edit location' }}" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($banner)
            <native:text class="text-sm text-theme-destructive">{{ $banner }}</native:text>
        @endif

        @if ($confirmingDiscard)
            <native:column class="w-full p-3 gap-2 bg-theme-surface">
                <native:text class="text-base text-theme-on-background">Discard your changes?</native:text>
                <native:button label="Discard changes" @tap="discard" class="w-full" />
                <native:button label="Keep editing" variant="secondary" @tap="keepEditing" class="w-full" />
            </native:column>
        @endif

        @if ($ready)
            <native:outlined-text-input label="Name this location" native:model="draft.label" ref="field-label" />
            @isset($errors['label'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['label'] }}</native:text>
            @endisset

            @if ($this->isMobile())
                @if (($location['source'] ?? null) === 'app')
                    <native:text class="text-sm text-theme-on-surface-variant">
                        {{ ($location['linked'] ?? false) ? 'Kept current by a phone running WxNotify. Turn location sharing on or off in Account.' : 'Not linked to a phone. Turn on location sharing in the app on a phone to link it again.' }}
                    </native:text>
                @else
                    <native:text class="text-sm text-theme-on-surface-variant">Tracked by the Traccar device {{ $location['device_name'] ?? '' }}. Change the device on wxnotify.com.</native:text>
                @endif

                @if (filled($location['address'] ?? null))
                    <native:text class="text-sm text-theme-on-surface-variant">Last known address: {{ $location['address'] }}</native:text>
                @endif

                <native:select label="Update every" :options="$this->intervalLabels()" :value="$this->selectedIntervalLabel()" @change="pickInterval" ref="field-interval" />
            @else
                <native:text class="text-lg font-bold text-theme-on-background">Where is it?</native:text>

                @if (filled($draft['lat']))
                    <native:column class="w-full p-3 gap-1 bg-theme-surface">
                        <native:text class="text-base text-theme-on-background">{{ filled($draft['address']) ? $draft['address'] : 'Position chosen' }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $draft['lat'] }}, {{ $draft['lng'] }}</native:text>
                    </native:column>
                @endif

                <native:button label="Use my current position" variant="secondary" @tap="useCurrentPosition" class="w-full" />
                @if ($positionMessage)
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $positionMessage }}</native:text>
                @endif
                @if ($permissionDenied)
                    <native:button label="Open settings" variant="secondary" @tap="openSettings" class="w-full" />
                @endif

                <native:text class="text-base font-semibold text-theme-on-background">Search for an address</native:text>
                <native:outlined-text-input label="Address, city or place" native:model="placeQuery" ref="field-place-query" />
                <native:button label="Search" variant="secondary" @tap="searchPlaces" class="w-full" />
                @if ($placeMessage)
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $placeMessage }}</native:text>
                @endif
                @foreach ($placeResults as $index => $place)
                    <native:pressable @tap="choosePlace({{ $index }})" ref="place-{{ $index }}" class="w-full">
                        <native:text class="text-base text-theme-on-background p-2">{{ $place['label'] }}</native:text>
                    </native:pressable>
                @endforeach

                @isset($errors['position'])
                    <native:text class="text-sm text-theme-destructive">{{ $errors['position'] }}</native:text>
                @endisset

                <native:select label="Timezone" :options="$this->timezoneLabels()" :value="$this->selectedTimezoneLabel()" @change="pickTimezone" ref="field-timezone" />
                @isset($errors['timezone'])
                    <native:text class="text-sm text-theme-destructive">{{ $errors['timezone'] }}</native:text>
                @endisset
            @endif

            <native:button label="Save location" @tap="save" class="w-full" />

            @if ($locationId !== null)
                @if ($confirmingDelete)
                    <native:column class="w-full p-3 gap-2 bg-theme-surface">
                        <native:text class="text-base text-theme-on-background">Delete this location? This can't be undone.</native:text>
                        <native:button label="Yes, delete" @tap="delete" class="w-full" />
                        <native:button label="Keep location" variant="secondary" @tap="cancelDelete" class="w-full" />
                    </native:column>
                @else
                    <native:button label="Delete location" variant="secondary" @tap="askDelete" class="w-full" />
                @endif
            @endif
        @endif
    </native:column>
</native:scroll-view>
```

Register the routes in `routes/mobile.php` (`/locations/new` before `/locations/{id}`), with the matching `use` lines:

```php
Route::native('/locations', LocationsScreen::class);
Route::native('/locations/new', LocationEditor::class);
Route::native('/locations/{id}', LocationEditor::class);
```

- [ ] **Step 7: Run the tests and fix until they pass**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/LocationsScreenTest.php tests/Feature/LocationEditorTest.php` and `cd mobile && php artisan native:validate`. Expected: PASS and validate clean. Friction to expect: the harness may not bind `emitNative` payload keys by name to the `#[On]` handler's parameters (read `NativeComponent::dispatchNativeEvent` and adapt the handler signatures, not the tests' payloads); `Geolocation::getCurrentPosition()->id()->get()` chaining against the recording fake (the fake's parent builder is inert; if `->get()` throws without a bridge, drop the `->get()` and let the builder's destructor start it, as the plugin documents); a handler argument convention that differs from the probe's Convention C.

- [ ] **Step 8: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && php artisan native:validate
cd .. && git add mobile
git commit -m "App: Locations tab and the location editor (current position, address search, guarded delete)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Wrap-up: guard rows, render tests, sign-out wipe and the full suites

**Files:**
- Modify: `tests/Feature/NativePluginsTest.php`, `tests/Feature/ScreensRenderTest.php`, `tests/Feature/AccountScreenTest.php`

- [ ] **Step 1: List the one-shot position calls in the plugin guard**

In `tests/Feature/NativePluginsTest.php` change the Geolocation row's label so it also names the new calls (the provider is already guarded):

```php
    'Geolocation::sharePosition() and backgroundWatchStatus() (the location beacon), getCurrentPosition() and requestPermissions() (the location picker)' => GeolocationServiceProvider::class,
```

- [ ] **Step 2: Add render tests for the four new screens**

Append to `tests/Feature/ScreensRenderTest.php` (add the `use` lines for `RulesScreen`, `RuleEditor`, `LocationsScreen`, `LocationEditor`); these prove each screen's elements compile to types the native side knows, like the existing ones:

```php
test('the rules screen renders its title, four tabs, an add button and a row with a switch', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [['id' => 1, 'name' => 'Freeze', 'display_name' => 'Freeze', 'category' => 'threshold', 'category_label' => 'Threshold Alerts', 'is_active' => true]]])]);

    Native::test(RulesScreen::class)
        ->assertNavTitle('Alert rules')
        ->assertHasTabBar()
        ->assertTabActive('Rules')
        ->assertHasTab('Alerts')->assertHasTab('Locations')->assertHasTab('Account')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Add rule')
        ->assertElement('toggle')
        ->assertElement('pressable');
});

test('the rule editor renders its sections, inputs and save button', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/alert-rule-options' => Http::response(['data' => ruleFormOptions()])]);

    Native::test(RuleEditor::class)
        ->assertNavTitle('New alert rule')
        ->assertElement('scroll_view')
        ->assertElement('pressable')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Save rule')
        ->tap('category-threshold')
        ->assertElement('select')
        ->assertElement('outlined_text_input')
        ->assertElement('date_picker')
        ->tap('category-weather_alerts')
        ->assertElement('checkbox');
});

test('the locations screen renders its title, tabs and add button', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/locations' => Http::response(['data' => [], 'meta' => ['can_create' => true, 'limit' => 5]])]);

    Native::test(LocationsScreen::class)
        ->assertNavTitle('Locations')
        ->assertTabActive('Locations')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Add location');
});

test('the location editor renders the stationary form', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/locations' => Http::response(['data' => [], 'meta' => []])]);

    Native::test(LocationEditor::class)
        ->assertNavTitle('New location')
        ->assertElement('outlined_text_input')
        ->assertElement('select')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Use my current position')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Save location');
});
```

If an element type name differs from the ones above (the Task 0 probe recorded the real ones), use the recorded name.

- [ ] **Step 3: Prove that signing out wipes the new cache**

In `tests/Feature/AccountScreenTest.php` add:

```php
test('signing out also forgets the cached rules, locations and form options', function () use ($profile) {
    $cache = new LocalCache;
    $cache->rememberRules([['id' => 1]]);
    $cache->rememberLocations([['id' => 2]], ['can_create' => true, 'limit' => 5]);
    $cache->rememberOptions(['categories' => []]);
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/auth/token' => Http::response(null, 204),
    ]);

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    expect($cache->rules())->toBeNull()->and($cache->locations())->toBeNull()->and($cache->locationsMeta())->toBeNull()->and($cache->options())->toBeNull();
});
```

- [ ] **Step 4: Verify the spec amendment (already applied before Task 1)**

The "Clarifications settled while planning" section was appended to `docs/superpowers/specs/2026-10-05-mobile-parity-management-design.md` (before "## Rules to record") in the commit that applied the pre-flight scan rulings, before Task 1, so every task's reviewer held its work to a spec that already carried decisions 1-4 and 7, the US-only timezone list, the rule that fields not applying to the category, matching mode or tier are ignored rather than refused, and the whole-number lightning radius. Verify it only: `grep -n "Clarifications settled while planning" docs/superpowers/specs/2026-10-05-mobile-parity-management-design.md` finds one heading, and each of those clarifications still matches what was built. If a later ruling changed one, correct that bullet in the spec; otherwise change nothing.

- [ ] **Step 5: Run both full suites and `native:validate`**

Run: `cd mobile && vendor/bin/pest --compact` and `cd mobile && php artisan native:validate`. Expected: all PASS, validate clean. The backend suite already ran in Task 6; if any backend file changed since, rerun it: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`.

- [ ] **Step 6: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent
cd .. && git add mobile
git commit -m "App: guard row, render and sign-out tests for rules and locations

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Device check (controller task, needs the owner)

Not a subagent task. The owner rebuilds and reinstalls on an Android emulator (Google Play image, LAN access to the dev server) with `cd <project root>/mobile && php artisan native:run android`; the dev database needs no new migration for this slice. The controller never runs `native:run`. After the owner says the app is installed, check on the emulator (screenshots and `adb` where useful; app logs via `adb shell run-as com.wxnotify.dev.app tail app_storage/persisted_data/storage/logs/edge-nav.log`), and report each line as verified, failed or not verifiable:

- [ ] The four tabs show and switch; the Rules and Locations lists load and show the dashboard's data.
- [ ] Create, edit, switch off and delete a rule of each category: threshold, daily summary, lightning, weather alerts (by location, by office, by county/zone, nationwide) and SPC & WPC (risk area). Each saved rule opens on the dashboard with the same fields; a rule made on the dashboard opens in the app and saves unchanged.
- [ ] The category-driven form: switching category clears fields, SPC risk levels and matching modes follow the selected products, the office and zone searches work, quiet hours and the time pickers behave, Free versus paid message fields.
- [ ] The system back and the top-bar back icon both ask "Discard your changes?" with edits, and leave straight away without.
- [ ] Create a stationary location with "Use my current position" (grant the permission dialog; also try denying it and the Open settings button) and with an address search; edit, move and delete one; the guarded delete messages for a location a rule uses and for this phone's own location; the plan-limit message.
- [ ] Offline (airplane mode): the lists and the editors open from the cache with the note; saving, deleting and switching fail with a clear message and keep the draft.
- [ ] Sign out and in: nothing from the previous account is left in the lists.
- [ ] Not verifiable here: iOS (needs a physical device), the exact look on small screens, and anything the harness could not drive (record each in the report).

When the owner confirms the device check, the controller updates the memory file with the slice's state and asks before pushing.
