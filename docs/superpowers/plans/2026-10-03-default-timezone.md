# Subscriber Default Timezone Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An optional per-subscriber timezone, set on the Profile page, used as the fallback wherever a Location has no timezone of its own and as the preferred day boundary for the dashboard alert charts.

**Architecture:** A nullable `users.timezone` plus one small resolver class, `App\Support\Timezones`, that is the only place deciding what a stored timezone means. Existing code that does `$location->timezone ?? config('app.timezone')` is switched to the resolver; unset subscribers resolve exactly as before.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5 (Tapp `TimezoneSelect`), PostgreSQL, Pest.

**Spec:** `docs/superpowers/specs/2026-10-03-default-timezone-design.md` (binding; read it first).

## Global Constraints

- A Location's own timezone **always wins**. The profile timezone is only ever a fallback (or, for the charts, preferred over the newest-Location guess).
- A subscriber with **no** profile timezone must see **no behavior change anywhere**: scheduled times use Location timezone else `config('app.timezone')`; quiet hours are never suppressed without a resolved timezone; charts use the newest Location **with a timezone** else UTC; new-Location timezone is browser-detected.
- A stored profile value counts only if it is in `DateTimeZone::listIdentifiers()` (so legacy aliases like `US/Mountain` are invalid); invalid = unset. Do not add new validation to existing Location timezone values.
- Quiet hours are only extended for a Location that **matched** but has no timezone. Rules matching no specific Location (Nationwide, WFO, County/Zone, or Location/Polygon/RiskArea with no matching location) still always deliver, with or without a profile timezone.
- No timestamp rendering changes anywhere else; no per-rule timezone; no backfill; no admin editing.
- Imperial/units code is untouched. Conventions: Pest feature tests; Pint (`vendor/bin/pint --dirty --format agent`) before each commit; commit directly to `main` (no push); commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Read `.ai/rules/index.md` and the rule files covering touched paths (`filament.md` for `app/Filament/**`, `support.md` for `app/Support/**`, `jobs.md`/`pipeline.md`, `feature.md` for tests, `models.md`) before editing.

## How to run tests (read this once)

Run Pest inside the app container (the host cannot reach the DB):

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

Every "Run:" below means that command with the stated files. The full suite takes ~6 minutes and is run by the controller in the background at the end. Pint runs on the host.

## File Structure

| Path | Responsibility |
| --- | --- |
| `database/migrations/2026_10_03_200001_add_timezone_to_users_table.php` | nullable `users.timezone` |
| `app/Models/User.php` | `'timezone'` in `#[Fillable]` |
| `app/Support/Timezones.php` | `isValid`, `forUser`, `forLocation`, `forRule` |
| `app/Filament/Dashboard/Pages/Auth/EditProfile.php` | Timezone field under Units |
| `app/Console/Commands/DispatchScheduledAlerts.php`, `app/Models/AlertRule.php` | use `Timezones::forRule` |
| `app/Actions/Weather/MatchWeatherAlertRules.php` | quiet hours use `Timezones::forLocation` |
| `app/Actions/Dashboard/GetAlertDeliveryStats.php` | profile tz, then newest Location with a tz, then UTC |
| `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php` | default from profile tz; reworded mobile notice |
| `.ai/rules/support.md` | "Timezones" rule |
| `tests/Feature/TimezonesTest.php`, `EditProfileTimezoneTest.php` (new); `AlertRuleDeliveryTimeResetTest.php`, `DispatchScheduledAlertsTest.php`, `MatchWeatherAlertRulesTest.php`, `GetAlertDeliveryStatsTest.php`, `LocationResourceTest.php` (extended) | tests |

---

### Task 1: Storage, resolver and the Profile field

**Files:**
- Create: `database/migrations/2026_10_03_200001_add_timezone_to_users_table.php`, `app/Support/Timezones.php`
- Modify: `app/Models/User.php`, `app/Filament/Dashboard/Pages/Auth/EditProfile.php`
- Test: `tests/Feature/TimezonesTest.php`, `tests/Feature/EditProfileTimezoneTest.php`

**Interfaces:**
- Produces (used by Tasks 2 and 3):
  - `users.timezone` (nullable string); `User::$timezone`, mass-assignable.
  - `Timezones::isValid(?string $timezone): bool`
  - `Timezones::forUser(?User $user): ?string` — the validated profile timezone or null.
  - `Timezones::forLocation(Location $location): ?string` — the Location's timezone if present, else `forUser($location->user)`, else null.
  - `Timezones::forRule(AlertRule $rule): string` — Location timezone if present, else `forUser($rule->user)`, else `config('app.timezone')`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TimezonesTest.php`:

```php
<?php

use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use App\Support\Timezones;

test('only canonical IANA identifiers are valid', function (?string $timezone, bool $valid) {
    expect(Timezones::isValid($timezone))->toBe($valid);
})->with([
    'a canonical zone' => ['America/Denver', true],
    'UTC' => ['UTC', true],
    'null' => [null, false],
    'an empty string' => ['', false],
    'garbage' => ['Mars/Olympus_Mons', false],
    'a legacy alias the timezone select never offers' => ['US/Mountain', false],
]);

test('forUser returns the validated profile timezone, else null', function () {
    expect(Timezones::forUser(null))->toBeNull()
        ->and(Timezones::forUser(User::factory()->make(['timezone' => null])))->toBeNull()
        ->and(Timezones::forUser(User::factory()->make(['timezone' => 'Mars/Olympus_Mons'])))->toBeNull()
        ->and(Timezones::forUser(User::factory()->make(['timezone' => 'America/Denver'])))->toBe('America/Denver');
});

test('forLocation prefers the location timezone, then the owner\'s profile timezone, then null', function () {
    $user = User::factory()->create(['timezone' => 'America/Chicago']);

    $own = Location::factory()->for($user)->create(['timezone' => 'Pacific/Honolulu']);
    $none = Location::factory()->for($user)->create(['timezone' => null]);

    expect(Timezones::forLocation($own))->toBe('Pacific/Honolulu')
        ->and(Timezones::forLocation($none))->toBe('America/Chicago');

    $user->update(['timezone' => null]);

    expect(Timezones::forLocation($none->fresh()))->toBeNull();
});

test('forRule prefers the location timezone, then the profile timezone, then the app timezone', function () {
    $user = User::factory()->create(['timezone' => 'America/Chicago']);
    $withZone = Location::factory()->for($user)->create(['timezone' => 'Pacific/Honolulu']);
    $withoutZone = Location::factory()->for($user)->create(['timezone' => null]);

    $ruleWithZone = AlertRule::factory()->dailySummary()->for($user)->for($withZone)->create();
    $ruleWithoutZone = AlertRule::factory()->dailySummary()->for($user)->for($withoutZone)->create();

    expect(Timezones::forRule($ruleWithZone))->toBe('Pacific/Honolulu')
        ->and(Timezones::forRule($ruleWithoutZone))->toBe('America/Chicago');

    $user->update(['timezone' => null]);

    expect(Timezones::forRule($ruleWithoutZone->fresh()))->toBe(config('app.timezone'));
});
```

`tests/Feature/EditProfileTimezoneTest.php`:

```php
<?php

use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->user = User::factory()->create()->fresh();
    $this->actingAs($this->user);
});

test('the profile form offers an optional Timezone field that starts empty', function () {
    livewire(EditProfile::class)
        ->assertFormFieldExists('timezone')
        ->assertFormSet(['timezone' => null]);
});

test('a subscriber can save a timezone and clear it again', function () {
    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'America/Denver'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->timezone)->toBe('America/Denver');

    livewire(EditProfile::class)
        ->fillForm(['timezone' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->timezone)->toBeNull();
});

test('an unknown timezone is rejected', function () {
    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'Mars/Olympus_Mons'])
        ->call('save')
        ->assertHasFormErrors(['timezone']);

    expect($this->user->fresh()->timezone)->toBeNull();
});

test('saving the profile without touching the timezone leaves it unchanged', function () {
    $this->user->update(['timezone' => 'America/Chicago']);

    livewire(EditProfile::class)
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh())->name->toBe('New Name')->timezone->toBe('America/Chicago');
});
```

(`AlertRule::factory()->dailySummary()` and `Location::factory()` exist — see `AlertRuleDeliveryTimeResetTest.php`. If `fillForm(['timezone' => null])` does not round-trip a clear for this select, clear it the way a user would in the form test API and keep the assertion: the stored value must become null.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/TimezonesTest.php tests/Feature/EditProfileTimezoneTest.php`
Expected: FAIL — `App\Support\Timezones` not found / no `timezone` field.

- [ ] **Step 3: Migration and model**

`database/migrations/2026_10_03_200001_add_timezone_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('unit_system');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
```

In `app/Models/User.php` add `'timezone'` to the `#[Fillable([...])]` list (after `'unit_system'`). No cast.

- [ ] **Step 4: The resolver**

`app/Support/Timezones.php`:

```php
<?php

namespace App\Support;

use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use DateTimeZone;

/**
 * The one place that decides what a stored timezone means. A Location's own
 * timezone always wins; a subscriber's profile timezone (users.timezone) is
 * only a fallback for a Location that has none (mobile locations) and for the
 * dashboard charts. A stored profile value counts only if it is a canonical
 * IANA identifier (the same list the timezone select offers); anything else is
 * treated as unset, so a subscriber with no profile timezone resolves exactly
 * as the app did before the setting existed.
 */
final class Timezones
{
    public static function isValid(?string $timezone): bool
    {
        return filled($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    public static function forUser(?User $user): ?string
    {
        $timezone = $user?->timezone;

        return self::isValid($timezone) ? $timezone : null;
    }

    public static function forLocation(Location $location): ?string
    {
        return filled($location->timezone) ? $location->timezone : self::forUser($location->user);
    }

    public static function forRule(AlertRule $rule): string
    {
        $locationTimezone = $rule->location?->timezone;

        return filled($locationTimezone)
            ? $locationTimezone
            : (self::forUser($rule->user) ?? config('app.timezone'));
    }
}
```

- [ ] **Step 5: The Profile field**

In `app/Filament/Dashboard/Pages/Auth/EditProfile.php`, add `use Tapp\FilamentTimezoneField\Forms\Components\TimezoneSelect;` and insert directly after the `unit_system` ToggleButtons:

```php
                TimezoneSelect::make('timezone')
                    ->label('Timezone')
                    ->searchable()
                    ->getTimezoneFromBrowser()
                    ->helperText('Used when a location has no timezone of its own (such as a mobile location), for the day boundaries on your dashboard charts, and as the starting value for new locations. A location\'s own timezone always takes priority. Clear it to stop using one.'),
```

Not required. (The package pre-fills it from the browser only when empty and client-side; the value is stored only when the profile form is saved — an accepted consequence.)

- [ ] **Step 6: Run to verify it passes**

Run: `tests/Feature/TimezonesTest.php tests/Feature/EditProfileTimezoneTest.php tests/Feature/EditProfileUnitsTest.php`
Expected: all PASS.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_10_03_200001_add_timezone_to_users_table.php app/Models/User.php app/Support/Timezones.php app/Filament/Dashboard/Pages/Auth/EditProfile.php tests/Feature/TimezonesTest.php tests/Feature/EditProfileTimezoneTest.php
git commit -m "Add an optional subscriber timezone and the Timezones resolver

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Scheduled alerts and quiet hours use the fallback

**Files:**
- Modify: `app/Console/Commands/DispatchScheduledAlerts.php` (line ~62), `app/Models/AlertRule.php` (line ~73), `app/Actions/Weather/MatchWeatherAlertRules.php` (`isWithinQuietHours`, ~line 430)
- Test: extend `tests/Feature/AlertRuleDeliveryTimeResetTest.php`, `tests/Feature/DispatchScheduledAlertsTest.php`, `tests/Feature/MatchWeatherAlertRulesTest.php`

**Interfaces:**
- Consumes: `Timezones::forRule(AlertRule): string`, `Timezones::forLocation(Location): ?string` (Task 1).
- Produces: scheduled dispatch, the rescheduled-run-stamp reset, and quiet hours resolve a timezone-less Location through the owner's profile timezone.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AlertRuleDeliveryTimeResetTest.php` (its `beforeEach` pins `2026-10-02 16:53:00` UTC and builds `$this->location`/`$this->rule`; the new tests build their own rule):

```php
test('a location with no timezone uses the owner\'s profile timezone for what later today means', function () {
    // 16:53 UTC is 06:53 in Honolulu, so 08:00 there is still ahead (it has already passed in UTC).
    $user = User::factory()->create(['timezone' => 'Pacific/Honolulu']);
    $location = Location::factory()->for($user)->create(['timezone' => null, 'lat' => 21.3, 'lng' => -157.8]);
    $rule = AlertRule::factory()->dailySummary()->for($user)->for($location)->create([
        'delivery_time' => '05:00', 'last_scheduled_run_on' => '2026-10-02',
    ]);

    $rule->update(['delivery_time' => '08:00']);

    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('a location with no timezone and no profile timezone still means UTC', function () {
    $user = User::factory()->create(['timezone' => null]);
    $location = Location::factory()->for($user)->create(['timezone' => null, 'lat' => 21.3, 'lng' => -157.8]);
    $rule = AlertRule::factory()->dailySummary()->for($user)->for($location)->create([
        'delivery_time' => '05:00', 'last_scheduled_run_on' => '2026-10-02',
    ]);

    $rule->update(['delivery_time' => '08:00']); // 08:00 UTC has already passed at 16:53 UTC

    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-02');
});
```

(add `use App\Models\User;` to the file's imports.)

Append to `tests/Feature/DispatchScheduledAlertsTest.php`, modelled on its first test (a threshold rule due now; `Carbon::setTestNow('2026-09-14 12:00:00')` is already set in `beforeEach` — 07:00 Chicago, 02:00 Honolulu, 12:00 UTC — and `fakeGridDataForThreshold()` is the file's helper):

```php
test('a location with no timezone is scheduled in the owner\'s profile timezone', function () {
    Bus::fake();
    fakeGridDataForThreshold(overnightLow: 27.0);

    // 12:00 UTC is 02:00 in Honolulu: a 06:00 delivery time is not due there, though it is due in UTC.
    $user = User::factory()->create(['timezone' => 'Pacific/Honolulu']);
    $location = Location::factory()->for($user)->create(['timezone' => null]);
    AlertRule::factory()->for($user)->for($location)->create(['threshold_value' => 32, 'delivery_time' => '06:00']);
    // ...attach a notification channel exactly as the first test in this file does, so a due rule would dispatch

    $this->artisan('app:dispatch-scheduled-alerts'); // use the command name/invocation the existing tests use

    Bus::assertNotDispatched(DeliverScheduledAlert::class);
});

test('a location with no timezone and no profile timezone is scheduled in UTC, as before', function () {
    Bus::fake();
    fakeGridDataForThreshold(overnightLow: 27.0);

    $user = User::factory()->create(['timezone' => null]);
    $location = Location::factory()->for($user)->create(['timezone' => null]);
    AlertRule::factory()->for($user)->for($location)->create(['threshold_value' => 32, 'delivery_time' => '06:00']);
    // ...same channel attachment

    $this->artisan('app:dispatch-scheduled-alerts');

    Bus::assertDispatched(DeliverScheduledAlert::class);
});
```

Copy the exact command invocation, channel attachment and any other setup from the file's existing "threshold rule due now dispatches" test; the two cases differ only in the owner's `timezone`.

Append to `tests/Feature/MatchWeatherAlertRulesTest.php` (uses its existing imports and `createIngestedProductWithoutMatching()`; note the new tests must create the Location **for the rule's user**, unlike the older tests that create unrelated users):

```php
function quietHoursRuleForOwnedLocation(?string $profileTimezone, ?string $locationTimezone, string $quietAroundTimezone = 'America/New_York'): array
{
    $user = User::factory()->create(['timezone' => $profileTimezone]);
    $location = Location::factory()->for($user)->create(['timezone' => $locationTimezone, 'forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->for($user)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location',
        'quiet_hours_start' => now($quietAroundTimezone)->subHour()->format('H:i:s'),
        'quiet_hours_end' => now($quietAroundTimezone)->addHour()->format('H:i:s'),
    ]);
    $product = Product::where('code', 'TO.W')->sole();
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->create());

    return [$rule, createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']])];
}

test('a matched location with no timezone is quiet-hours suppressed in the owner\'s profile timezone', function () {
    Queue::fake();
    [$rule, $ingested] = quietHoursRuleForOwnedLocation(profileTimezone: 'America/New_York', locationTimezone: null);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::sole()->status)->toBe('suppressed');
});

test('a matched location with no timezone is not suppressed when the owner has no profile timezone', function () {
    Queue::fake();
    [$rule, $ingested] = quietHoursRuleForOwnedLocation(profileTimezone: null, locationTimezone: null);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::count())->toBe(0);
});

test('a location\'s own timezone beats the profile timezone for quiet hours', function () {
    Queue::fake();
    // Quiet hours surround "now" in New York, so a profile timezone of New York would suppress;
    // the location's own Pacific/Kiritimati (a different hour of the day) must decide instead.
    [$rule, $ingested] = quietHoursRuleForOwnedLocation(profileTimezone: 'America/New_York', locationTimezone: 'Pacific/Kiritimati');

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a nationwide rule is never quiet-hours suppressed, even when the owner has a profile timezone', function () {
    Queue::fake();
    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->for($user)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59',
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id]);

    (new MatchWeatherAlertRules)($ingested);

    expect(DeliveryLog::where('status', 'suppressed')->count())->toBe(0);
});
```

(If the "Kiritimati" case happens to land inside quiet hours at the moment the test runs, pin the clock with `Carbon::setTestNow('2026-10-02 16:00:00')` for the whole group — 12:00 New York and 06:00 Kiritimati's next day… pick instants/windows so New York is inside quiet hours and the other zone is outside, as the existing "evaluated against the location that actually matched" test does with `now('America/New_York')±1h`; that test already relies on Kiritimati being +14 h from New York ±1 h — reuse the same construction. Note for the report: the existing test "a nationwide rule with quiet hours set always delivers…" asserts `assertNotPushed`, which looks inconsistent with its name; do not change it, but say in the report whether a nationwide rule in these fixtures actually dispatches.)

- [ ] **Step 2: Run to verify they fail**

Run: `tests/Feature/AlertRuleDeliveryTimeResetTest.php tests/Feature/DispatchScheduledAlertsTest.php tests/Feature/MatchWeatherAlertRulesTest.php`
Expected: the new "profile timezone" tests FAIL (the unset-user and nationwide tests already pass); all existing tests pass.

- [ ] **Step 3: Switch the three consumers to the resolver**

- `app/Console/Commands/DispatchScheduledAlerts.php`: replace `$timezone = $rule->location->timezone ?? config('app.timezone');` with `$timezone = Timezones::forRule($rule);` (+ `use App\Support\Timezones;`; the rule already eager-loads `user` and `location`). Update the class docblock sentence "in its own location's timezone" to mention the owner's profile timezone as the fallback.
- `app/Models/AlertRule.php` (`booted()` updating closure): replace `$timezone = $rule->location?->timezone ?? config('app.timezone');` with `$timezone = Timezones::forRule($rule);` (+ import). Update the docblock's "the rule's own timezone" wording only if it becomes inaccurate.
- `app/Actions/Weather/MatchWeatherAlertRules.php` `isWithinQuietHours()`: replace `$timezone = $matchedLocation?->timezone;` with `$timezone = $matchedLocation === null ? null : Timezones::forLocation($matchedLocation);` (+ import). Keep the `null → return false` branch and extend the method's docblock: a matched Location with no timezone uses the owner's profile timezone; no resolved timezone still means not suppressed.

- [ ] **Step 4: Run to verify they pass**

Run: `tests/Feature/AlertRuleDeliveryTimeResetTest.php tests/Feature/DispatchScheduledAlertsTest.php tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/DeliveryLogCategoryTest.php`
Expected: all PASS.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/DispatchScheduledAlerts.php app/Models/AlertRule.php app/Actions/Weather/MatchWeatherAlertRules.php tests/Feature/AlertRuleDeliveryTimeResetTest.php tests/Feature/DispatchScheduledAlertsTest.php tests/Feature/MatchWeatherAlertRulesTest.php
git commit -m "Use the subscriber's profile timezone for locations without one

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Charts, the Location form, and the rule

**Files:**
- Modify: `app/Actions/Dashboard/GetAlertDeliveryStats.php`, `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php`, `.ai/rules/support.md`
- Test: extend `tests/Feature/GetAlertDeliveryStatsTest.php`, `tests/Feature/LocationResourceTest.php`

**Interfaces:**
- Consumes: `Timezones::forUser(?User): ?string`, `Timezones::isValid()` (Task 1).
- Produces: `GetAlertDeliveryStats::timezoneFor()` = profile timezone, else newest Location **with a timezone** (validated as today), else `UTC`; the Location form's timezone defaults to the profile timezone; the mobile notice mentions the profile timezone.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/GetAlertDeliveryStatsTest.php` (it has `$this->user`, `$this->stats`, and a pinned clock):

```php
test('the profile timezone beats the newest location', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/New_York']);
    $this->user->update(['timezone' => 'Pacific/Honolulu']);

    expect($this->stats->timezoneFor($this->user->fresh()))->toBe('Pacific/Honolulu');
});

test('without a profile timezone a newer location that has no timezone does not flip the charts to UTC', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver', 'created_at' => now()->subDay()]);
    Location::factory()->for($this->user)->create(['timezone' => null, 'created_at' => now()]); // e.g. a mobile location

    expect($this->stats->timezoneFor($this->user))->toBe('America/Denver');
});

test('an invalid profile timezone is ignored', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver']);
    $this->user->forceFill(['timezone' => 'Mars/Olympus_Mons'])->save();

    expect($this->stats->timezoneFor($this->user->fresh()))->toBe('America/Denver');
});

test('with no profile timezone and no location timezone the charts use UTC', function () {
    Location::factory()->for($this->user)->create(['timezone' => null]);

    expect($this->stats->timezoneFor($this->user))->toBe('UTC');
});

test('a profile timezone moves the day boundary of the per-day counts', function () {
    $this->user->update(['timezone' => 'America/Denver']);
    logAt($this->user, '2026-10-02 02:00:00'); // 2026-10-01 20:00 Denver

    $result = $this->stats->perDay($this->user->fresh(), 7);

    expect($result['counts'][5])->toBe(1)->and($result['counts'][6])->toBe(0);
});
```

Append to `tests/Feature/LocationResourceTest.php` (existing create-page tests show how `CreateLocation` is filled and which fields are required; follow the setup of "creating a location persists an explicitly chosen timezone"):

```php
test('a new location\'s timezone starts as the subscriber\'s profile timezone', function () {
    $this->user->update(['timezone' => 'America/Chicago']);

    livewire(CreateLocation::class)->assertFormSet(['timezone' => 'America/Chicago']);
});

test('a new location\'s timezone is left blank for browser detection when the subscriber has no profile timezone', function () {
    livewire(CreateLocation::class)->assertFormSet(['timezone' => null]);
});

test('a timezone chosen on the location form is saved over the profile default', function () {
    $this->user->update(['timezone' => 'America/Chicago']);

    // Create a stationary location exactly as "creating a location persists an explicitly chosen timezone" does,
    // but with 'timezone' => 'America/New_York' in the filled data.

    expect(Location::latest('id')->first()->timezone)->toBe('America/New_York');
});

test('the mobile-location notice points at the profile timezone', function () {
    livewire(CreateLocation::class)
        ->fillForm(['type' => 'mobile'])
        ->assertSee('timezone on your Profile page');
});
```

(`$this->user` is whatever the file's `beforeEach` names the acting subscriber — match it. Fill in the third test's body from the existing explicit-timezone test; the assertion must verify the explicitly chosen zone wins.)

- [ ] **Step 2: Run to verify they fail**

Run: `tests/Feature/GetAlertDeliveryStatsTest.php tests/Feature/LocationResourceTest.php`
Expected: new tests FAIL (profile beats location; null-timezone newer location flips to UTC; no form default; old notice text); the existing tests in both files still pass.

- [ ] **Step 3: Charts**

In `app/Actions/Dashboard/GetAlertDeliveryStats.php` replace `timezoneFor()` with:

```php
    public function timezoneFor(User $user): string
    {
        $profile = Timezones::forUser($user);

        if ($profile !== null) {
            return $profile;
        }

        $timezone = $user->locations()->whereNotNull('timezone')->latest()->latest('id')->value('timezone');

        return Timezones::isValid($timezone) ? $timezone : 'UTC';
    }
```

(+ `use App\Support\Timezones;`) and update the class docblock sentence that says users have no timezone of their own: days are bucketed in the subscriber's profile timezone, else the timezone of their most recently added Location that has one, else UTC. (Note: the existing behavior that an invalid newest-Location value falls to UTC, not to an older Location, is deliberate and stays — the existing test "a missing or invalid stored timezone falls back to UTC" must keep passing.)

- [ ] **Step 4: Location form**

In `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php`, add `use App\Support\Timezones;` and add to the `TimezoneSelect::make('timezone')` chain, before `->getTimezoneFromBrowser()`:

```php
                ->default(fn (): ?string => Timezones::forUser(auth()->user()))
```

and update its helper text so the first sentence reads "Starts as your profile timezone when you've set one, otherwise your browser's detected timezone — change it if this location is somewhere else …" (keep the rest of the sentence about quiet hours). Replace the `mobile_quiet_hours_note` placeholder content with:

```php
'Mobile locations don\'t have a fixed timezone, so quiet hours on alert rules tied to this location use the timezone on your Profile page. Until you set one there, they won\'t take effect.'
```

Read `.ai/rules/filament.md` first; keep the existing `seedTimezoneFallback` behavior unchanged (a profile default counts as "not blank", the same way the browser-detected value already does).

- [ ] **Step 5: Record the rule**

Append to `.ai/rules/support.md` a section in the file's existing style:

```markdown
## Timezones: a Location's own timezone wins; the profile timezone is only a fallback
`users.timezone` is optional and never backfilled. `App\Support\Timezones` is the one place that decides what a stored timezone means: `forUser` (validated against `DateTimeZone::listIdentifiers()`, so legacy aliases and garbage count as unset), `forLocation` (Location tz, else the owner's), `forRule` (Location tz, else owner's, else `config('app.timezone')`) — never write `$location->timezone ?? config('app.timezone')` inline again. Unset must keep yesterday's behavior: scheduled alerts fall back to app timezone, quiet hours are NOT suppressed without a resolved timezone (and rules matching no specific Location always deliver), the Location form falls back to browser detection, and the dashboard charts use the newest Location that has a timezone, then UTC. A profile timezone on the Location form counts as a non-blank default, so the coordinate-derived fallback (`seedTimezoneFallback`) does not overwrite it, exactly as it does not overwrite a browser-detected value.
```

- [ ] **Step 6: Run to verify they pass**

Run: `tests/Feature/GetAlertDeliveryStatsTest.php tests/Feature/LocationResourceTest.php tests/Feature/AlertChartWidgetsTest.php tests/Feature/TimezonesTest.php`
Expected: all PASS.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Dashboard/GetAlertDeliveryStats.php app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php .ai/rules/support.md tests/Feature/GetAlertDeliveryStatsTest.php tests/Feature/LocationResourceTest.php
git commit -m "Use the profile timezone for chart days and as the new-location default

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Post-implementation verification (controller, in a real browser)

1. Migrate the dev database (`php artisan migrate --force` in the container). Create a throwaway subscriber (random password, test-only) plus a few seeded `delivery_logs` and a stationary Location, log in through the local app.
2. Profile page: the Timezone field is present under Units, pre-fills from the browser when empty, saves, reloads with the saved value, and can be cleared. A bogus value cannot be chosen.
3. Location form: with a profile timezone set, "Add location" starts with that timezone; with it cleared, browser detection fills it as before.
4. Mobile notice: choosing the Mobile type shows the new copy.
5. Charts: with the profile timezone set to a zone that differs from the newest Location's, the dashboard day labels/boundaries follow the profile zone (check one boundary delivery).
6. Delete the throwaway user and data afterwards; reset the viewport.

---

## Self-Review (against the spec)

**Coverage:** nullable column + fillable (T1); `Timezones` with `isValid/forUser/forLocation/forRule` and legacy-alias rejection (T1); Profile field under Units, optional, clearable, validated by the select (T1); scheduled dispatch + reschedule reset fallback (T2); quiet hours only for a matched timezone-less Location, nationwide/no-match still always deliver, unset unchanged (T2); charts profile → newest-with-timezone → UTC, existing invalid-newest→UTC behavior preserved (T3); Location form default + helper text + mobile notice (T3); rules recorded (T3); all test cases from the spec's Testing section are present; browser check listed; non-goals untouched.

**Placeholder scan:** two test bodies (DispatchScheduledAlerts channel attachment/command invocation, the LocationResourceTest explicit-timezone create) are specified as "copy the exact setup from the named existing test" with the differing data given — deliberate, because that setup is long and file-specific; no TBD/TODO.

**Type consistency:** `Timezones::forUser(?User): ?string`, `forLocation(Location): ?string`, `forRule(AlertRule): string`, `isValid(?string): bool` are used identically in T2/T3; the migration name sorts after `2026_10_03_100002_*`; `users.timezone` is the only new column.

**Known consequence (record in review, not a defect):** a profile timezone makes the Location form's timezone non-blank, so placing a pin in a different state does not auto-correct it (the same as browser detection today). The subscriber can change the field.
