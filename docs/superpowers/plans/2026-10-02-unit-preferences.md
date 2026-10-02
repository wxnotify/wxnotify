# Subscriber Unit Preference (Imperial / Metric) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A subscriber chooses Imperial (default) or Metric on their Profile page, and every subscriber-facing display of a measurement — Daily Summary email and Brief text, threshold alert messages, rule names, the motion line, and the alert form — follows it.

**Architecture:** Imperial stays the canonical stored and computed unit; Metric is a conversion at the edges. One `App\Support\Units` class owns every factor and label; `App\Support\NwsTextUnits` rewrites NWS's standardized forecast phrases for Metric; the alert form converts values in on fill and out on save, with a guard so an untouched value is never rewritten.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5 (Livewire), Pest.

**Spec:** `docs/superpowers/specs/2026-10-02-unit-preferences-design.md` (binding authority; this plan argues from it).

## Global Constraints

- **Imperial output is unchanged byte-for-byte** everywhere: every existing Imperial test must keep passing unmodified. Metric is purely additive.
- **Storage stays imperial.** `alert_rules.threshold_value` (°F / mph / %) and `alert_rules.radius_miles` (miles) never change meaning; no stored rule is rewritten when a profile's units change. Internal computation (`EvaluateThresholdAlert`, `MatchLightningFlashes`, `GetGridpointForecastData`) remains imperial.
- **Metric units:** °C, km/h (not m/s), hPa, km (distance and visibility), m (elevation), cm (precipitation depth). **Imperial:** °F, mph, inHg, mi, ft, in.
- **One conversion service.** All factors and unit labels live in `App\Support\Units`; no other class hard-codes a conversion factor except `NwsTextUnits`, which rewrites free text with its own phrase patterns (it reuses only `Units::number()` for formatting).
- **Default Imperial:** `users.unit_system` defaults to `imperial`; code treats a missing/null value as Imperial (`Units::for(null)`, `Units::fromReport([])`).
- **No-drift guard:** saving an alert without touching a converted field never rewrites the stored value (a stored 35°F shows as 1.7°C and stays 35.0).
- **NWS prose:** Metric converts only recognized phrases, sentence by sentence; a sentence that still contains a unit token (`mph`, `inch(es)`, `degrees`, `°F`) after conversion reverts to the original NWS wording — never half-converted. Imperial never calls the converter. Clock times (`before 5pm`) and percents (`40%`) are never touched.
- **Non-goals:** mixing systems per measurement; 12/24-hour time; converting raw NWS product text inside alerts; rewriting stored historical text (delivery-log subjects, sent notifications); the admin panel's own settings (`lightning_min_radius_miles` stays in miles); the Test Product Generator; m/s wind.
- Project conventions: Pest feature tests; `vendor/bin/pint --dirty --format agent` before each commit; commit directly to `main` (no push); commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; fixed-string logs only.

## How to run tests (read this once)

The host cannot resolve the `pgsql` hostname and runs out of memory under `php artisan test`. Run Pest inside the app container:

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

Every "Run:" line below means that command with the stated test file(s). The full suite takes ~5 minutes: run it with `run_in_background` (or a long timeout). Pint runs on the host: `vendor/bin/pint --dirty --format agent`.

## File Structure

| Path | Responsibility |
| --- | --- |
| `app/Enums/UnitSystem.php` | `Imperial` / `Metric` + label |
| `database/migrations/2026_10_02_200001_add_unit_system_to_users_table.php` | `users.unit_system` default `imperial` |
| `app/Models/User.php` | cast + `#[Fillable]` |
| `app/Support/Units.php` | every conversion, formatting and unit label |
| `app/Enums/ThresholdVariable.php` | unit-aware `unit()`, `displayValue()`, `canonicalValue()`, `format()` |
| `app/Support/NwsTextUnits.php` | Metric rewrite of NWS forecast prose and hourly wind text |
| `tests/Fixtures/nws_detailed_forecasts.json` | **already committed with this plan** — 112 real NWS `detailedForecast` strings from 8 regions (2026-10-02) |
| `app/Filament/Dashboard/Pages/Auth/EditProfile.php` | "Units" toggle |
| `app/Support/DailySummaryPresenter.php`, `app/Actions/Weather/BuildDailySummaryReport.php`, `BuildDailySummaryMessage.php`, `app/Console/Commands/DispatchScheduledAlerts.php` | Daily Summary email/Brief and threshold messages |
| `app/Models/AlertRule.php`, `app/Actions/Weather/FormatMotionVector.php`, `ComposeTemplatedAlertBody.php`, `AlertRulesTable.php`, `RecentAlertsWidget.php` | rule names and the motion line |
| `app/Support/AlertRuleFormUnits.php`, `AlertRuleForm.php`, `CreateAlertRule.php`, `EditAlertRule.php` | alert form conversion in/out and the no-drift guard |

---

### Task 1: `UnitSystem`, the `users.unit_system` column, `Units`, and `ThresholdVariable` support

**Files:**
- Create: `app/Enums/UnitSystem.php`, `database/migrations/2026_10_02_200001_add_unit_system_to_users_table.php`, `app/Support/Units.php`
- Modify: `app/Models/User.php`, `app/Enums/ThresholdVariable.php`
- Test: `tests/Feature/UnitsTest.php`, `tests/Feature/UserUnitSystemTest.php`, `tests/Feature/ThresholdVariableUnitsTest.php`

**Interfaces:**
- Produces (consumed by every later task):
  - `UnitSystem::{Imperial,Metric}` (string-backed `imperial`/`metric`), `label(): string`.
  - `User::$unit_system` cast to `UnitSystem` (null when the attribute is not loaded).
  - `Units` (final): `new Units(UnitSystem $system = Imperial)`; `Units::for(?User): self`; `Units::forRule(AlertRule): self`; `Units::fromReport(array): self`; `isMetric(): bool`; `public readonly UnitSystem $system`.
    - values: `temperatureValue(float °F): float`, `speedValue(float mph)`, `distanceValue(float mi)`, `elevationValue(float ft)`, `depthValue(float in)`, `pressureValue(float inHg)`.
    - inverses: `temperatureToFahrenheit(float)`, `speedToMph(float)`, `kilometersToMiles(float)`.
    - labels: `temperatureUnit()` (`°F`/`°C`), `speedUnit()` (`mph`/`km/h`), `pressureUnit()` (`inHg`/`hPa`), `distanceUnit()` (`mi`/`km`), `elevationUnit()` (`ft`/`m`), `depthUnit()` (`in`/`cm`), `distanceUnitName()` (`miles`/`km`).
    - formatted strings: `temperature(float °F, int $decimals = 0)` → `54°F`/`12°C`; `speed(float mph, int $decimals = 0)` → `10 mph`/`16 km/h`; `pressure(float inHg)` → `30.27 inHg`/`1025 hPa`; `distance(float mi, int $decimals = 1)` → `10 mi`/`16.1 km`; `elevation(float ft)` → `5,085 ft`/`1,550 m`; `depth(float in, int $decimals = 1)` → `1 in`/`2.5 cm`.
    - `static number(float $value, int $decimals = 0): string` (trims trailing zeros, never `-0`).
    - `minDistanceDisplay(int $miles): int` (Imperial identity; Metric `ceil(miles × 1.609344)`), `maxDistanceDisplay(int $miles): int` (Metric `floor`).
  - `ThresholdVariable::unit(UnitSystem $system = Imperial): string`, `displayValue(float $canonical, UnitSystem $system): float`, `canonicalValue(float $display, UnitSystem $system): float`, `format(float $canonical, UnitSystem $system): string` (`number_format(display, 1)` + unit, e.g. `32.0°F` / `0.0°C`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/UnitsTest.php`:

```php
<?php

use App\Enums\UnitSystem;
use App\Models\User;
use App\Support\Units;

test('imperial is the identity, with the existing display shapes', function () {
    $units = new Units;

    expect($units->temperature(53.6))->toBe('54°F')
        ->and($units->temperature(35.0, 1))->toBe('35°F')
        ->and($units->speed(10.0))->toBe('10 mph')
        ->and($units->pressure(30.2712))->toBe('30.27 inHg')
        ->and($units->distance(10.0))->toBe('10 mi')
        ->and($units->distance(9.5))->toBe('9.5 mi')
        ->and($units->elevation(5085.0))->toBe('5,085 ft')
        ->and($units->depth(1.0))->toBe('1 in')
        ->and($units->temperatureValue(70.0))->toBe(70.0)
        ->and($units->minDistanceDisplay(10))->toBe(10)
        ->and($units->maxDistanceDisplay(50))->toBe(50)
        ->and($units->distanceUnitName())->toBe('miles');
});

test('metric converts each measurement with its own factor', function () {
    $units = new Units(UnitSystem::Metric);

    expect($units->temperature(53.6))->toBe('12°C')
        ->and($units->temperature(35.0, 1))->toBe('1.7°C')
        ->and($units->temperature(32.0, 1))->toBe('0°C')
        ->and($units->temperature(-40.0))->toBe('-40°C')
        ->and($units->speed(10.0))->toBe('16 km/h')
        ->and($units->pressure(30.2712))->toBe('1025 hPa')
        ->and($units->distance(10.0))->toBe('16.1 km')
        ->and($units->distance(15.0, 0))->toBe('24 km')
        ->and($units->elevation(5085.0))->toBe('1,550 m')
        ->and($units->depth(1.0))->toBe('2.5 cm')
        ->and($units->distanceUnitName())->toBe('km')
        ->and($units->minDistanceDisplay(10))->toBe(17)
        ->and($units->maxDistanceDisplay(50))->toBe(80);
});

test('metric never prints a negative zero', function () {
    expect((new Units(UnitSystem::Metric))->temperature(31.9))->toBe('0°C')
        ->and(Units::number(-0.04, 1))->toBe('0');
});

test('inverse conversions round-trip', function () {
    $units = new Units(UnitSystem::Metric);

    foreach ([-40.0, 0.0, 32.0, 35.0, 98.6, 105.0] as $fahrenheit) {
        expect($units->temperatureToFahrenheit($units->temperatureValue($fahrenheit)))->toEqualWithDelta($fahrenheit, 1e-9);
    }
    expect($units->speedToMph($units->speedValue(37.0)))->toEqualWithDelta(37.0, 1e-9)
        ->and($units->kilometersToMiles($units->distanceValue(15.0)))->toEqualWithDelta(15.0, 1e-9);
});

test('units resolve from a user, a rule owner and a queued report, defaulting to imperial', function () {
    $metric = User::factory()->create(['unit_system' => 'metric']);

    expect(Units::for($metric)->system)->toBe(UnitSystem::Metric)
        ->and(Units::for(null)->system)->toBe(UnitSystem::Imperial)
        ->and(Units::for(User::factory()->create())->system)->toBe(UnitSystem::Imperial)
        ->and(Units::fromReport(['units' => 'metric'])->system)->toBe(UnitSystem::Metric)
        ->and(Units::fromReport([])->system)->toBe(UnitSystem::Imperial)
        ->and(Units::fromReport(['units' => 'nonsense'])->system)->toBe(UnitSystem::Imperial);
});
```

`tests/Feature/UserUnitSystemTest.php`:

```php
<?php

use App\Enums\UnitSystem;
use App\Models\User;

test('a new user is Imperial by default', function () {
    $user = User::factory()->create();

    expect($user->fresh()->unit_system)->toBe(UnitSystem::Imperial);
});

test('the unit system is mass assignable and cast to the enum', function () {
    $user = User::factory()->create();

    $user->update(['unit_system' => 'metric']);

    expect($user->fresh()->unit_system)->toBe(UnitSystem::Metric);
});
```

`tests/Feature/ThresholdVariableUnitsTest.php`:

```php
<?php

use App\Enums\ThresholdVariable;
use App\Enums\UnitSystem;

test('the unit label follows the system and defaults to imperial', function () {
    expect(ThresholdVariable::Temperature->unit())->toBe('°F')
        ->and(ThresholdVariable::Temperature->unit(UnitSystem::Metric))->toBe('°C')
        ->and(ThresholdVariable::WindSpeed->unit())->toBe('mph')
        ->and(ThresholdVariable::Gusts->unit(UnitSystem::Metric))->toBe('km/h')
        ->and(ThresholdVariable::Humidity->unit(UnitSystem::Metric))->toBe('%')
        ->and(ThresholdVariable::PrecipChance->unit(UnitSystem::Metric))->toBe('%');
});

test('display and canonical values convert, percentages never do', function () {
    expect(ThresholdVariable::Temperature->displayValue(32.0, UnitSystem::Metric))->toEqualWithDelta(0.0, 1e-9)
        ->and(ThresholdVariable::FeelsLike->displayValue(35.0, UnitSystem::Metric))->toEqualWithDelta(1.6667, 1e-3)
        ->and(ThresholdVariable::WindSpeed->displayValue(20.0, UnitSystem::Metric))->toEqualWithDelta(32.1869, 1e-3)
        ->and(ThresholdVariable::Humidity->displayValue(60.0, UnitSystem::Metric))->toBe(60.0)
        ->and(ThresholdVariable::Temperature->displayValue(32.0, UnitSystem::Imperial))->toBe(32.0)
        ->and(ThresholdVariable::Dewpoint->canonicalValue(0.0, UnitSystem::Metric))->toEqualWithDelta(32.0, 1e-9)
        ->and(ThresholdVariable::Gusts->canonicalValue(32.1869, UnitSystem::Metric))->toEqualWithDelta(20.0, 1e-3)
        ->and(ThresholdVariable::PrecipChance->canonicalValue(40.0, UnitSystem::Metric))->toBe(40.0);
});

test('format keeps the one-decimal shape a rule name has always used', function () {
    expect(ThresholdVariable::Temperature->format(32.0, UnitSystem::Imperial))->toBe('32.0°F')
        ->and(ThresholdVariable::Temperature->format(32.0, UnitSystem::Metric))->toBe('0.0°C')
        ->and(ThresholdVariable::Temperature->format(35.0, UnitSystem::Metric))->toBe('1.7°C')
        ->and(ThresholdVariable::WindSpeed->format(20.0, UnitSystem::Metric))->toBe('32.2km/h')
        ->and(ThresholdVariable::Humidity->format(60.0, UnitSystem::Metric))->toBe('60.0%');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `tests/Feature/UnitsTest.php tests/Feature/UserUnitSystemTest.php tests/Feature/ThresholdVariableUnitsTest.php`
Expected: FAIL — `UnitSystem` / `Units` not found.

- [ ] **Step 3: Implement the enum and the migration**

`app/Enums/UnitSystem.php`:

```php
<?php

namespace App\Enums;

enum UnitSystem: string
{
    case Imperial = 'imperial';
    case Metric = 'metric';

    public function label(): string
    {
        return match ($this) {
            self::Imperial => 'Imperial (°F, mph, mi)',
            self::Metric => 'Metric (°C, km/h, km)',
        };
    }
}
```

`database/migrations/2026_10_02_200001_add_unit_system_to_users_table.php`:

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
            $table->string('unit_system')->default('imperial')->after('is_owner');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('unit_system');
        });
    }
};
```

- [ ] **Step 4: Update `User`**

In `app/Models/User.php`: add `use App\Enums\UnitSystem;`, change the attribute to `#[Fillable(['name', 'email', 'password', 'is_test_account', 'unit_system'])]`, and add to `casts()`:

```php
            'unit_system' => UnitSystem::class,
```

- [ ] **Step 5: Implement `app/Support/Units.php`**

```php
<?php

namespace App\Support;

use App\Enums\UnitSystem;
use App\Models\AlertRule;
use App\Models\User;

/**
 * The one place that knows how to convert, format and label a measurement.
 * Everything in the app is stored and computed in imperial units (degF, mph,
 * inHg, mi, ft, in); a subscriber's Metric preference is applied only at the
 * display edge (and inverted for form input), so switching systems never
 * changes what a stored alert means. Imperial methods are identity
 * conversions that format exactly as the app always has.
 */
final class Units
{
    private const KM_PER_MILE = 1.609344;

    private const HPA_PER_INHG = 33.8639;

    private const METERS_PER_FOOT = 0.3048;

    private const CM_PER_INCH = 2.54;

    public function __construct(public readonly UnitSystem $system = UnitSystem::Imperial) {}

    public static function for(?User $user): self
    {
        return new self($user?->unit_system ?? UnitSystem::Imperial);
    }

    public static function forRule(AlertRule $rule): self
    {
        return self::for($rule->user);
    }

    /**
     * @param  array<string, mixed>  $report  a BuildDailySummaryReport array; reports queued before this
     *                                        feature carry no `units` key and render Imperial
     */
    public static function fromReport(array $report): self
    {
        return new self(UnitSystem::tryFrom((string) ($report['units'] ?? '')) ?? UnitSystem::Imperial);
    }

    public function isMetric(): bool
    {
        return $this->system === UnitSystem::Metric;
    }

    public function temperatureValue(float $fahrenheit): float
    {
        return $this->isMetric() ? ($fahrenheit - 32) * 5 / 9 : $fahrenheit;
    }

    public function speedValue(float $mph): float
    {
        return $this->isMetric() ? $mph * self::KM_PER_MILE : $mph;
    }

    public function distanceValue(float $miles): float
    {
        return $this->isMetric() ? $miles * self::KM_PER_MILE : $miles;
    }

    public function elevationValue(float $feet): float
    {
        return $this->isMetric() ? $feet * self::METERS_PER_FOOT : $feet;
    }

    public function depthValue(float $inches): float
    {
        return $this->isMetric() ? $inches * self::CM_PER_INCH : $inches;
    }

    public function pressureValue(float $inHg): float
    {
        return $this->isMetric() ? $inHg * self::HPA_PER_INHG : $inHg;
    }

    public function temperatureToFahrenheit(float $value): float
    {
        return $this->isMetric() ? $value * 9 / 5 + 32 : $value;
    }

    public function speedToMph(float $value): float
    {
        return $this->isMetric() ? $value / self::KM_PER_MILE : $value;
    }

    public function kilometersToMiles(float $value): float
    {
        return $this->isMetric() ? $value / self::KM_PER_MILE : $value;
    }

    public function temperatureUnit(): string
    {
        return $this->isMetric() ? '°C' : '°F';
    }

    public function speedUnit(): string
    {
        return $this->isMetric() ? 'km/h' : 'mph';
    }

    public function pressureUnit(): string
    {
        return $this->isMetric() ? 'hPa' : 'inHg';
    }

    public function distanceUnit(): string
    {
        return $this->isMetric() ? 'km' : 'mi';
    }

    public function distanceUnitName(): string
    {
        return $this->isMetric() ? 'km' : 'miles';
    }

    public function elevationUnit(): string
    {
        return $this->isMetric() ? 'm' : 'ft';
    }

    public function depthUnit(): string
    {
        return $this->isMetric() ? 'cm' : 'in';
    }

    public function temperature(float $fahrenheit, int $decimals = 0): string
    {
        return self::number($this->temperatureValue($fahrenheit), $decimals).$this->temperatureUnit();
    }

    public function speed(float $mph, int $decimals = 0): string
    {
        return self::number($this->speedValue($mph), $decimals).' '.$this->speedUnit();
    }

    public function pressure(float $inHg): string
    {
        return $this->isMetric()
            ? number_format($this->pressureValue($inHg), 0, '.', '').' hPa'
            : number_format($inHg, 2).' inHg';
    }

    public function distance(float $miles, int $decimals = 1): string
    {
        return self::number($this->distanceValue($miles), $decimals).' '.$this->distanceUnit();
    }

    public function elevation(float $feet): string
    {
        return number_format($this->elevationValue($feet)).' '.$this->elevationUnit();
    }

    public function depth(float $inches, int $decimals = 1): string
    {
        return self::number($this->depthValue($inches), $decimals).' '.$this->depthUnit();
    }

    /**
     * The smallest and largest whole display value that still converts to a
     * stored mile count inside the allowed range: rounding up for the minimum
     * and down for the maximum, so anything the form accepts is valid.
     */
    public function minDistanceDisplay(int $miles): int
    {
        return $this->isMetric() ? (int) ceil($miles * self::KM_PER_MILE) : $miles;
    }

    public function maxDistanceDisplay(int $miles): int
    {
        return $this->isMetric() ? (int) floor($miles * self::KM_PER_MILE) : $miles;
    }

    /**
     * Rounds to $decimals, trims trailing zeros ("10.0" -> "10") and never
     * prints a negative zero.
     */
    public static function number(float $value, int $decimals = 0): string
    {
        $text = number_format(round($value, $decimals), $decimals, '.', '');

        if ($decimals > 0) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }
}
```

- [ ] **Step 6: Extend `ThresholdVariable`**

In `app/Enums/ThresholdVariable.php` add `use App\Support\Units;` and replace `unit()` with these four methods:

```php
    public function unit(UnitSystem $system = UnitSystem::Imperial): string
    {
        $metric = $system === UnitSystem::Metric;

        return match ($this) {
            self::Temperature, self::FeelsLike, self::Dewpoint => $metric ? '°C' : '°F',
            self::WindSpeed, self::Gusts => $metric ? 'km/h' : 'mph',
            self::Humidity, self::PrecipChance => '%',
        };
    }

    /**
     * The stored (imperial) value as the subscriber's system shows it.
     */
    public function displayValue(float $canonical, UnitSystem $system): float
    {
        $units = new Units($system);

        return match ($this) {
            self::Temperature, self::FeelsLike, self::Dewpoint => $units->temperatureValue($canonical),
            self::WindSpeed, self::Gusts => $units->speedValue($canonical),
            self::Humidity, self::PrecipChance => $canonical,
        };
    }

    /**
     * A value typed in the subscriber's system, as the stored imperial value.
     */
    public function canonicalValue(float $display, UnitSystem $system): float
    {
        $units = new Units($system);

        return match ($this) {
            self::Temperature, self::FeelsLike, self::Dewpoint => $units->temperatureToFahrenheit($display),
            self::WindSpeed, self::Gusts => $units->speedToMph($display),
            self::Humidity, self::PrecipChance => $display,
        };
    }

    /**
     * One decimal plus the unit, the shape a rule name has always used
     * ("32.0°F"; Metric "0.0°C").
     */
    public function format(float $canonical, UnitSystem $system): string
    {
        return number_format($this->displayValue($canonical, $system), 1, '.', '').$this->unit($system);
    }
```

Add `use App\Enums\UnitSystem;` is not needed (same namespace `App\Enums`).

- [ ] **Step 7: Run to verify they pass, plus the suites that read `ThresholdVariable::unit()`**

Run: `tests/Feature/UnitsTest.php tests/Feature/UserUnitSystemTest.php tests/Feature/ThresholdVariableUnitsTest.php tests/Feature/AlertRuleResourceTest.php tests/Feature/DispatchScheduledAlertsTest.php tests/Feature/UserModelTest.php`
Expected: all PASS (nothing else changes yet).

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/UnitSystem.php app/Enums/ThresholdVariable.php app/Models/User.php app/Support/Units.php database/migrations/2026_10_02_200001_add_unit_system_to_users_table.php tests/Feature/UnitsTest.php tests/Feature/UserUnitSystemTest.php tests/Feature/ThresholdVariableUnitsTest.php
git commit -m "Add the unit system preference, the Units conversion service, and unit-aware ThresholdVariable

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `NwsTextUnits` — Metric rewrite of NWS forecast prose

**Files:**
- Create: `app/Support/NwsTextUnits.php`
- Test: `tests/Feature/NwsTextUnitsTest.php` (uses the already-committed `tests/Fixtures/nws_detailed_forecasts.json`)

**Interfaces:**
- Produces: `NwsTextUnits::toMetric(string $text): string` — converts recognized imperial phrases to Metric sentence by sentence; unchanged text when nothing matches; idempotent.

- [ ] **Step 1: Write the failing test**

`tests/Feature/NwsTextUnitsTest.php`:

```php
<?php

use App\Support\NwsTextUnits;

test('winds and temperatures in a typical NWS forecast convert', function () {
    expect(NwsTextUnits::toMetric('Sunny, with a high near 79. East southeast wind 2 to 6 mph.'))
        ->toBe('Sunny, with a high near 26°C. East southeast wind 3 to 10 km/h.')
        ->and(NwsTextUnits::toMetric('Mostly clear, with a low around 50. South southwest wind around 7 mph.'))
        ->toBe('Mostly clear, with a low around 10°C. South southwest wind around 11 km/h.')
        ->and(NwsTextUnits::toMetric('Sunny. High near 84, with temperatures falling to around 81 in the afternoon. South southeast wind 2 to 9 mph, with gusts as high as 20 mph.'))
        ->toBe('Sunny. High near 29°C, with temperatures falling to around 27°C in the afternoon. South southeast wind 3 to 14 km/h, with gusts as high as 32 km/h.');
});

test('heat index, wind chill and negative temperatures convert', function () {
    expect(NwsTextUnits::toMetric('Heat index values as high as 105.'))->toBe('Heat index values as high as 41°C.')
        ->and(NwsTextUnits::toMetric('Wind chill values as low as -10.'))->toBe('Wind chill values as low as -23°C.')
        ->and(NwsTextUnits::toMetric('Cloudy, with a low around 5.'))->toBe('Cloudy, with a low around -15°C.');
});

test('snow depths convert to centimetres', function () {
    expect(NwsTextUnits::toMetric('New snow accumulation of 1 to 3 inches possible.'))->toBe('New snow accumulation of 2.5 to 7.6 cm possible.')
        ->and(NwsTextUnits::toMetric('New snow accumulation of around 1 inch possible.'))->toBe('New snow accumulation of around 2.5 cm possible.');
});

test('clock times and percentages are never touched', function () {
    $text = 'Showers and thunderstorms likely before 5pm. A chance of snow after 11am. Chance of precipitation is 40%.';

    expect(NwsTextUnits::toMetric($text))->toBe($text);
});

test('a sentence that cannot be fully converted stays exactly as NWS wrote it', function () {
    $mixed = 'Wind 10 mph, with rain amounts of a tenth of an inch.';

    expect(NwsTextUnits::toMetric($mixed))->toBe($mixed)
        ->and(NwsTextUnits::toMetric('Winds could gust as high as 25 mph. Rain amounts of a tenth of an inch possible.'))
        ->toBe('Winds could gust as high as 40 km/h. Rain amounts of a tenth of an inch possible.');
});

test('hourly wind strings convert', function () {
    expect(NwsTextUnits::toMetric('5 to 10 mph NW'))->toBe('8 to 16 km/h NW')
        ->and(NwsTextUnits::toMetric('5 mph NW'))->toBe('8 km/h NW');
});

test('text with nothing to convert, and empty text, come back unchanged', function () {
    expect(NwsTextUnits::toMetric('Cloudy.'))->toBe('Cloudy.')
        ->and(NwsTextUnits::toMetric(''))->toBe('');
});

test('conversion is idempotent', function () {
    foreach ([
        'Sunny, with a high near 79. East southeast wind 2 to 6 mph.',
        'Wind chill values as low as -10.',
        'New snow accumulation of 1 to 3 inches possible.',
        '5 to 10 mph NW',
    ] as $text) {
        $once = NwsTextUnits::toMetric($text);

        expect(NwsTextUnits::toMetric($once))->toBe($once);
    }
});

test('a corpus of real NWS forecasts converts cleanly', function () {
    $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/nws_detailed_forecasts.json')), true);
    $changed = 0;

    expect($corpus)->not->toBeEmpty();

    foreach ($corpus as $original) {
        $converted = NwsTextUnits::toMetric($original);

        if ($converted !== $original) {
            $changed++;
        }

        // Every wind phrase and unit symbol is gone...
        expect($converted)->not->toMatch('/\bmph\b|°F/');
        // ...no bare temperature is left after a known lead-in (a number not followed by °)...
        expect($converted)->not->toMatch('/\b(high|low) (near|around) -?\d+(?![\d°])/i')
            ->and($converted)->not->toMatch('/\b(falling|rising) to (near|around) -?\d+(?![\d°])/i')
            ->and($converted)->not->toMatch('/values as (high|low) as -?\d+(?![\d°])/i');
        // ...while clock times and percentages are exactly as published...
        preg_match_all('/\d+\s?[ap]m\b/i', $original, $timesBefore);
        preg_match_all('/\d+\s?[ap]m\b/i', $converted, $timesAfter);
        preg_match_all('/\d+%/', $original, $percentBefore);
        preg_match_all('/\d+%/', $converted, $percentAfter);
        expect($timesAfter)->toBe($timesBefore)->and($percentAfter)->toBe($percentBefore);
        // ...and converting twice changes nothing.
        expect(NwsTextUnits::toMetric($converted))->toBe($converted);
    }

    expect($changed)->toBeGreaterThan(50);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/NwsTextUnitsTest.php`
Expected: FAIL — class `App\Support\NwsTextUnits` not found.

- [ ] **Step 3: Implement `app/Support/NwsTextUnits.php`**

```php
<?php

namespace App\Support;

/**
 * Rewrites NWS's standardized forecast phrases from imperial to Metric for a
 * Metric subscriber. NWS writes its detailed forecasts and hourly wind text in
 * mph / degF (no unit symbol) / inches, so this converts only the phrase shapes
 * NWS actually uses, sentence by sentence. A temperature is converted only after
 * a known lead-in ("high near", "falling to around", "heat index values as high
 * as") — never a bare number — so clock times ("before 5pm") and percentages
 * ("40%") can't be touched. If any unit token (mph, inch, degrees, degF) is still
 * present after converting a sentence, that sentence reverts to NWS's original
 * wording: it is never half-converted.
 */
class NwsTextUnits
{
    private const KMH_PER_MPH = 1.609344;

    private const CM_PER_INCH = 2.54;

    /**
     * A temperature number that is not already converted ("26°C"), a decimal,
     * or a clock time ("5pm").
     */
    private const TEMP = '(-?\d+)(?!°|\.\d|\s?[ap]m\b)';

    public static function toMetric(string $text): string
    {
        $parts = preg_split('/((?<=[.!?])\s+(?=[A-Z]))/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $text;
        }

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = self::convertSentence($part);
            }
        }

        return implode('', $parts);
    }

    private static function convertSentence(string $sentence): string
    {
        $converted = $sentence;

        $converted = preg_replace_callback(
            '/\b(\d+) to (\d+) mph\b/i',
            fn (array $m): string => self::kmh($m[1]).' to '.self::kmh($m[2]).' km/h',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(\d+) mph\b/i',
            fn (array $m): string => self::kmh($m[1]).' km/h',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(\d+(?:\.\d+)?) to (\d+(?:\.\d+)?) inch(?:es)?\b/i',
            fn (array $m): string => self::cm($m[1]).' to '.self::cm($m[2]).' cm',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(\d+(?:\.\d+)?) inch(?:es)?\b/i',
            fn (array $m): string => self::cm($m[1]).' cm',
            $converted,
        );

        $converted = preg_replace_callback(
            '/\b(high|low) (near|around) '.self::TEMP.'/i',
            fn (array $m): string => "{$m[1]} {$m[2]} ".self::celsius($m[3]).'°C',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(falling|rising) to (near|around) '.self::TEMP.'/i',
            fn (array $m): string => "{$m[1]} to {$m[2]} ".self::celsius($m[3]).'°C',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(temperatures?) (near|around) '.self::TEMP.'/i',
            fn (array $m): string => "{$m[1]} {$m[2]} ".self::celsius($m[3]).'°C',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(heat index|wind chill) values as (high|low) as '.self::TEMP.'/i',
            fn (array $m): string => "{$m[1]} values as {$m[2]} as ".self::celsius($m[3]).'°C',
            $converted,
        );

        if (preg_match('/\b(?:mph|inch(?:es)?|degrees?)\b|°F/i', $converted) === 1) {
            return $sentence;
        }

        return $converted;
    }

    private static function kmh(string $mph): string
    {
        return (string) (int) round((float) $mph * self::KMH_PER_MPH);
    }

    private static function celsius(string $fahrenheit): string
    {
        return (string) (int) round(((float) $fahrenheit - 32) * 5 / 9);
    }

    private static function cm(string $inches): string
    {
        return Units::number((float) $inches * self::CM_PER_INCH, 1);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `tests/Feature/NwsTextUnitsTest.php`
Expected: all PASS. **If the corpus test fails on a real phrase** (a bare temperature left after a lead-in the patterns don't cover, e.g. `temperature steady near 40`), do not weaken the corpus assertions: add that lead-in to the converter (a pattern in the same style), keep every assertion, and note the added phrase in your report.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/NwsTextUnits.php tests/Feature/NwsTextUnitsTest.php tests/Fixtures/nws_detailed_forecasts.json
git commit -m "Add NwsTextUnits to convert NWS forecast phrases for Metric subscribers

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The Profile page "Units" field

**Files:**
- Modify: `app/Filament/Dashboard/Pages/Auth/EditProfile.php`
- Test: `tests/Feature/EditProfileUnitsTest.php`

**Interfaces:**
- Consumes: `UnitSystem` (Task 1), `User::$unit_system` fillable/cast.

- [ ] **Step 1: Write the failing test**

`tests/Feature/EditProfileUnitsTest.php`:

```php
<?php

use App\Enums\UnitSystem;
use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    // fresh(): the profile form fills from the loaded attributes, and a just-created
    // model does not carry the column's database default until it is re-read.
    $this->user = User::factory()->create()->fresh();
    $this->actingAs($this->user);
});

test('the profile form offers a Units choice that defaults to Imperial', function () {
    livewire(EditProfile::class)
        ->assertFormFieldExists('unit_system')
        ->assertFormSet(['unit_system' => 'imperial']);
});

test('a subscriber can switch to Metric and it persists', function () {
    livewire(EditProfile::class)
        ->fillForm(['unit_system' => 'metric'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->unit_system)->toBe(UnitSystem::Metric);
});

test('an unknown unit system is rejected', function () {
    livewire(EditProfile::class)
        ->fillForm(['unit_system' => 'furlongs'])
        ->call('save')
        ->assertHasFormErrors(['unit_system']);

    expect($this->user->fresh()->unit_system)->toBe(UnitSystem::Imperial);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/EditProfileUnitsTest.php`
Expected: FAIL — the form has no `unit_system` field.

- [ ] **Step 3: Add the field**

In `app/Filament/Dashboard/Pages/Auth/EditProfile.php` add imports `use App\Enums\UnitSystem;` and `use Filament\Forms\Components\ToggleButtons;`, and insert after `$this->getEmailFormComponent(),`:

```php
                ToggleButtons::make('unit_system')
                    ->label('Units')
                    ->options(collect(UnitSystem::cases())->mapWithKeys(fn (UnitSystem $system): array => [$system->value => $system->label()])->all())
                    ->inline()
                    ->required()
                    ->helperText('Temperatures, wind, distances and pressure in your emails, alerts and alert forms.'),
```

- [ ] **Step 4: Run to verify it passes**

Run: `tests/Feature/EditProfileUnitsTest.php`
Expected: all PASS. If saving fails for a reason unrelated to units (the base page's own password/email handling), report the exact error and adapt only the test's setup (e.g. providing whatever the base page requires), not the assertions.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Dashboard/Pages/Auth/EditProfile.php tests/Feature/EditProfileUnitsTest.php
git commit -m "Let a subscriber choose Imperial or Metric on the Profile page

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Daily Summary email, Brief text, and threshold messages follow the setting

**Files:**
- Modify: `app/Actions/Weather/BuildDailySummaryReport.php`, `app/Actions/Weather/BuildDailySummaryMessage.php`, `app/Support/DailySummaryPresenter.php`, `app/Console/Commands/DispatchScheduledAlerts.php`
- Test: `tests/Feature/DailySummaryPresenterTest.php`, `tests/Feature/DailySummaryMailTest.php`, `tests/Feature/BuildDailySummaryReportTest.php`, `tests/Feature/BuildDailySummaryMessageTest.php`, `tests/Feature/DispatchScheduledAlertsTest.php`

**Interfaces:**
- Consumes: `Units`, `UnitSystem`, `ThresholdVariable::{unit,displayValue}` (Task 1); `NwsTextUnits::toMetric` (Task 2); `presenterReport()` / `nwsFakeEverything()` fixtures in `tests/Pest.php`.
- Produces:
  - The report array gains `'units' => 'imperial'|'metric'` (right after `'timezone'`); `BuildDailySummaryReport::__invoke(Location $location, bool $includeTestAlerts = false, UnitSystem $units = UnitSystem::Imperial): ?array`.
  - `BuildDailySummaryMessage::__invoke(Location $location, UnitSystem $units = UnitSystem::Imperial): ?string`.
  - `DailySummaryPresenter::present()` renders in the report's system (missing `units` → Imperial).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DailySummaryPresenterTest.php`:

```php
test('a metric report renders every measurement in metric units', function () {
    $view = DailySummaryPresenter::present(presenterReport(['units' => 'metric']));

    $rows = collect($view['current']['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);

    expect($rows['Temperature'])->toBe('12°C')
        ->and($rows['Feels like'])->toBe('10°C')
        ->and($rows['Wind'])->toBe('NE 16 km/h, gusting 32 km/h')
        ->and($rows['Barometer'])->toBe('1025 hPa')
        ->and($rows['Dewpoint'])->toBe('8°C')
        ->and($rows['Visibility'])->toBe('16.1 km')
        ->and($view['station_line'])->toBe('Reporting station: Erie Municipal Airport (KEIK) — 40.0117, -105.0503 — elev. 1,550 m')
        ->and($view['hourly'][0]['temp'])->toBe('12°C')
        ->and($view['hourly'][0]['wind'])->toBe('8 km/h NW')
        ->and($view['periods'][0]['temp'])->toBe('High 26°C')
        ->and($view['periods'][0]['summary'])->toBe('Sunny, with a high near 26°C. East southeast wind 3 to 10 km/h.');
});

test('a report with no units key, or an imperial one, renders exactly as before', function () {
    // The radar caption stamps "now" to the minute; pin it so the two renders cannot straddle a minute.
    Illuminate\Support\Carbon::setTestNow('2026-10-02 15:00:00');

    $without = DailySummaryPresenter::present(presenterReport());
    $imperial = DailySummaryPresenter::present(presenterReport(['units' => 'imperial']));

    expect($imperial)->toBe($without)
        ->and($without['current']['rows'][0])->toBe(['Temperature', '54°F'])
        ->and($without['periods'][0]['summary'])->toBe('Sunny, with a high near 79. East southeast wind 2 to 6 mph.');

    Illuminate\Support\Carbon::setTestNow();
});

test('the plain text of a metric report has no imperial units left', function () {
    $text = DailySummaryPresenter::text(DailySummaryPresenter::present(presenterReport(['units' => 'metric'])), hasRadar: false);

    expect($text)->toContain('Temperature: 12°C')
        ->and($text)->toContain('High 26°C')
        ->and($text)->not->toMatch('/\bmph\b|°F|inHg/');
});
```

Append to `tests/Feature/DailySummaryMailTest.php`:

```php
test('a metric subscriber\'s email shows metric units and no imperial ones', function () {
    $html = (new DailySummaryMail('Title', presenterReport(['units' => 'metric']), 'brief'))->render();

    expect($html)->toContain('12°C')
        ->and($html)->toContain('1025 hPa')
        ->and($html)->toContain('16.1 km')
        ->and($html)->not->toContain('°F')
        ->and($html)->not->toContain('inHg')
        ->and($html)->not->toContain(' mph');
});
```

Append to `tests/Feature/BuildDailySummaryReportTest.php`:

```php
test('the report records the unit system it was built for, defaulting to imperial', function () {
    nwsFakeEverything();

    expect((new BuildDailySummaryReport)($this->location)['units'])->toBe('imperial')
        ->and((new BuildDailySummaryReport)($this->location, units: App\Enums\UnitSystem::Metric)['units'])->toBe('metric');
});
```

Append to `tests/Feature/BuildDailySummaryMessageTest.php`:

```php
test('a metric subscriber gets the Brief temperatures in celsius', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response(['properties' => ['forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast']]),
        'api.weather.gov/gridpoints/*' => Http::response(['properties' => ['periods' => [
            ['name' => 'Today', 'isDaytime' => true, 'temperature' => 88, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny'],
            ['name' => 'Tonight', 'isDaytime' => false, 'temperature' => 64, 'temperatureUnit' => 'F', 'shortForecast' => 'Clear'],
        ]]]),
    ]);
    $location = Location::factory()->for(User::factory())->create();

    expect((new BuildDailySummaryMessage)($location, App\Enums\UnitSystem::Metric))
        ->toBe("Today: Sunny, high 31\u{b0}C. Tonight: Clear, low 18\u{b0}C.");
});
```

Append to `tests/Feature/DispatchScheduledAlertsTest.php` (the file already defines `fakeGridDataForThreshold()` and `summaryRule()`; `User`, `Location`, `AlertRule`, `NotificationChannel`, `Bus`, `Http` are imported):

```php
test('a metric subscriber\'s threshold alert message is in celsius', function () {
    Bus::fake();
    fakeGridDataForThreshold(overnightLow: 27.0);

    $location = Location::factory()->for($user = User::factory()->create(['unit_system' => 'metric']))->create(['timezone' => 'America/Chicago']);
    $rule = AlertRule::factory()->for($user)->for($location)->create(['threshold_value' => 32, 'delivery_time' => '06:00']);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->create());

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => str_contains($job->body, 'below 0°C')
        && str_contains($job->body, 'peak -2.8°C')
        && ! str_contains($job->body, '°F'));
});

test('a metric subscriber\'s Brief daily summary and report are built in metric', function () {
    Bus::fake();
    nwsFakeEverything();
    $rule = summaryRule('full', [NotificationChannel::factory()->email('a@example.com')]);
    $rule->user->update(['unit_system' => 'metric']);

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report['units'] === 'metric'
        && str_contains($job->body, '°C')
        && ! str_contains($job->body, '°F'));
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `tests/Feature/DailySummaryPresenterTest.php tests/Feature/DailySummaryMailTest.php tests/Feature/BuildDailySummaryReportTest.php tests/Feature/BuildDailySummaryMessageTest.php tests/Feature/DispatchScheduledAlertsTest.php`
Expected: the new tests FAIL; every pre-existing test PASSES.

- [ ] **Step 3: `BuildDailySummaryReport` carries the system**

In `app/Actions/Weather/BuildDailySummaryReport.php` add `use App\Enums\UnitSystem;`, change the signature to
`public function __invoke(Location $location, bool $includeTestAlerts = false, UnitSystem $units = UnitSystem::Imperial): ?array`
and add `'units' => $units->value,` directly after `'timezone' => $timezone,` in the returned array. Update the class docblock's shape list if it enumerates keys.

- [ ] **Step 4: `BuildDailySummaryMessage` takes the system**

Replace the body of `app/Actions/Weather/BuildDailySummaryMessage.php` after the `use` block with (add `use App\Enums\UnitSystem; use App\Support\Units;`):

```php
class BuildDailySummaryMessage
{
    public function __invoke(Location $location, UnitSystem $units = UnitSystem::Imperial): ?string
    {
        $periods = app(GetSevenDayForecast::class)($location);
        $display = new Units($units);

        $day = collect($periods)->first(fn (array $p): bool => $p['isDaytime'] === true);
        $night = collect($periods)->first(fn (array $p): bool => $p['isDaytime'] === false);

        if ($day === null && $night === null) {
            return null;
        }

        $lines = [];

        if ($day !== null) {
            $lines[] = "{$day['name']}: {$day['shortForecast']}, high {$this->temperature($day, $display)}.";
        }

        if ($night !== null) {
            $lines[] = "{$night['name']}: {$night['shortForecast']}, low {$this->temperature($night, $display)}.";
        }

        return implode(' ', $lines);
    }

    /**
     * NWS publishes /forecast periods in degF; honour the period's own unit
     * flag anyway so a Celsius period is never treated as Fahrenheit.
     *
     * @param  array<string, mixed>  $period
     */
    private function temperature(array $period, Units $units): string
    {
        $value = (float) $period['temperature'];
        $fahrenheit = strtoupper((string) ($period['temperatureUnit'] ?? 'F')) === 'C' ? $value * 9 / 5 + 32 : $value;

        return $units->temperature($fahrenheit);
    }
}
```

- [ ] **Step 5: `DailySummaryPresenter` renders in the report's system**

Edit `app/Support/DailySummaryPresenter.php`:

1. In `present()`, add `$units = Units::fromReport($report);` after `$timezone = ...;`.
2. `'station_line' => $current !== null ? self::stationLine($current['station'], $units) : null,`
3. `'current' => $current !== null ? self::currentSection($current, $units) : null,`
4. Hourly mapping: `'temp' => self::temperature($hour['temperature'], $hour['unit'], $units),` and `'wind' => filled($hour['wind']) ? self::prose($hour['wind'], $units) : self::DASH,`
5. Periods mapping: `'summary' => self::prose((string) $period['summary'], $units),` and `'temp' => $period['label'].' '.self::temperature($period['temperature'], $period['unit'], $units),`
6. Replace the helper methods below with these (keeping `sunTimes`, `sunLine`, `text`, unchanged):

```php
    /**
     * @param  array<string, mixed>  $station
     */
    private static function stationLine(array $station, Units $units): string
    {
        $line = 'Reporting station: '.(filled($station['name']) ? "{$station['name']} ({$station['id']})" : $station['id']);

        if ($station['lat'] !== null && $station['lng'] !== null) {
            $line .= ' — '.number_format($station['lat'], 4, '.', '').', '.number_format($station['lng'], 4, '.', '');
        }

        if ($station['elevation_ft'] !== null) {
            $line .= ' — elev. '.$units->elevation($station['elevation_ft']);
        }

        return $line;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private static function currentSection(array $current, Units $units): array
    {
        return [
            'icon_url' => WeatherIcon::url($current['icon']['period'], $current['icon']['code']),
            'description' => filled($current['description'] ?? null) ? (string) $current['description'] : null,
            'rows' => [
                ['Temperature', self::degrees($current['temperature_f'], $units)],
                ['Feels like', self::degrees($current['feels_like_f'], $units)],
                ['Humidity', $current['humidity_pct'] !== null ? (int) round($current['humidity_pct']).'%' : self::DASH],
                ['Wind', self::wind($current['wind'], $units)],
                ['Barometer', $current['barometer_inhg'] !== null ? $units->pressure($current['barometer_inhg']) : self::DASH],
                ['Dewpoint', self::degrees($current['dewpoint_f'], $units)],
                ['Visibility', $current['visibility_mi'] !== null ? $units->distance($current['visibility_mi'], 1) : self::DASH],
            ],
            'updated' => 'Last updated '.Carbon::parse($current['observed_at'])->format('D, M j \a\t g:i A'),
        ];
    }

    /**
     * @param  array{direction: ?string, speed_mph: ?float, gust_mph: ?float}  $wind
     */
    private static function wind(array $wind, Units $units): string
    {
        if ($wind['speed_mph'] === null) {
            return self::DASH;
        }

        if ($wind['speed_mph'] < 0.5) {
            return 'Calm';
        }

        $text = trim(($wind['direction'] ?? '').' '.$units->speed($wind['speed_mph']));

        return $wind['gust_mph'] !== null ? $text.', gusting '.$units->speed($wind['gust_mph']) : $text;
    }

    private static function degrees(?float $value, Units $units): string
    {
        return $value === null ? self::DASH : $units->temperature($value);
    }

    /**
     * NWS forecast periods carry their own unit flag (always F in practice); a
     * Celsius value is normalised to Fahrenheit first so Units stays the only
     * place that converts.
     */
    private static function temperature(mixed $value, string $unit, Units $units): string
    {
        if (! is_numeric($value)) {
            return self::DASH;
        }

        $fahrenheit = strtoupper($unit) === 'C' ? (float) $value * 9 / 5 + 32 : (float) $value;

        return $units->temperature($fahrenheit);
    }

    /**
     * NWS writes prose and hourly wind in imperial units; Imperial subscribers
     * see it untouched, Metric subscribers get the recognized phrases converted.
     */
    private static function prose(string $text, Units $units): string
    {
        return $units->isMetric() ? NwsTextUnits::toMetric($text) : $text;
    }
```

(Existing Imperial strings are reproduced exactly: `temperature()` → `54°F`, `speed()` → `10 mph`, `pressure()` → `30.27 inHg`, `distance(…,1)` → `10 mi`, `elevation()` → `5,085 ft`.)

- [ ] **Step 6: `DispatchScheduledAlerts` passes and applies the system**

In `app/Console/Commands/DispatchScheduledAlerts.php`:

1. Add `use App\Support\Units;`.
2. `AlertCategory::DailySummary => $buildSummary($rule->location, Units::forRule($rule)->system),`
3. In `reportFor()`: `return $buildReport($rule->location, (bool) $rule->user->is_test_account, Units::forRule($rule)->system);`
4. Replace the end of `evaluateThreshold()` — from `$variable = $rule->variable->label();` through the final `return` statement — with the block below. Keep the `trimmedNumber()` method: it is still used.

```php
        $system = Units::forRule($rule)->system;
        $variable = $rule->variable->label();
        $direction = $rule->direction->label();
        $unit = $rule->variable->unit($system);
        $threshold = $this->trimmedNumber($rule->variable->displayValue((float) $rule->threshold_value, $system));
        $peakValue = $this->trimmedNumber($rule->variable->displayValue($result['value'], $system));
        $at = $result['at']->format('g:i A');

        return "{$variable} {$direction} {$threshold}{$unit} near {$rule->location->label} "
            ."(peak {$peakValue}{$unit} around {$at}).";
```

(Imperial output is unchanged: `displayValue()` is the identity for Imperial.)

- [ ] **Step 7: Run to verify they pass**

Run: `tests/Feature/DailySummaryPresenterTest.php tests/Feature/DailySummaryMailTest.php tests/Feature/BuildDailySummaryReportTest.php tests/Feature/BuildDailySummaryMessageTest.php tests/Feature/DispatchScheduledAlertsTest.php tests/Feature/DeliverScheduledAlertTest.php tests/Feature/MailTransportTest.php`
Expected: all PASS, every pre-existing Imperial test unmodified.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Weather/BuildDailySummaryReport.php app/Actions/Weather/BuildDailySummaryMessage.php app/Support/DailySummaryPresenter.php app/Console/Commands/DispatchScheduledAlerts.php tests/Feature
git commit -m "Render the Daily Summary, Brief text and threshold messages in the subscriber's units

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Rule names and the motion line follow the setting

**Files:**
- Modify: `app/Models/AlertRule.php`, `app/Actions/Weather/FormatMotionVector.php`, `app/Actions/Weather/ComposeTemplatedAlertBody.php`, `app/Filament/Dashboard/Resources/AlertRules/Tables/AlertRulesTable.php`, `app/Filament/Dashboard/Widgets/RecentAlertsWidget.php`
- Test: `tests/Feature/AlertRuleDisplayNameUnitsTest.php`, `tests/Feature/FormatMotionVectorTest.php`, `tests/Feature/ComposeTemplatedAlertBodyTest.php`

**Interfaces:**
- Consumes: `Units`, `UnitSystem`, `ThresholdVariable::format()` (Task 1).
- Produces: `AlertRule::displayName(?UnitSystem $units = null): string` (default: the rule owner's system); `FormatMotionVector::__invoke(?ActiveWwa $wwa, UnitSystem $units = UnitSystem::Imperial): ?string`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AlertRuleDisplayNameUnitsTest.php`:

```php
<?php

use App\Enums\UnitSystem;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;

test('a threshold rule is named in the owner\'s units, imperial exactly as before', function () {
    $imperial = AlertRule::factory()->create(['threshold_value' => 32]);
    $metric = AlertRule::factory()->for(User::factory()->create(['unit_system' => 'metric']))->create(['threshold_value' => 32]);
    $wind = AlertRule::factory()->for(User::factory()->create(['unit_system' => 'metric']))->create([
        'variable' => 'wind_speed', 'direction' => 'above', 'threshold_value' => 20,
    ]);

    expect($imperial->displayName())->toBe('Temperature Falls below 32.0°F')
        ->and($metric->displayName())->toBe('Temperature Falls below 0.0°C')
        ->and($wind->displayName())->toBe('Wind speed Rises above 32.2km/h');
});

test('a lightning rule is named in the owner\'s units', function () {
    $location = Location::factory()->create(['label' => 'Home']);
    $imperial = AlertRule::factory()->lightning()->for($location->user)->for($location)->create(['radius_miles' => 15]);
    $metric = AlertRule::factory()->lightning()->for(User::factory()->create(['unit_system' => 'metric']))->for($location)->create(['radius_miles' => 15]);

    expect($imperial->displayName())->toBe('Lightning within 15 mi of Home')
        ->and($metric->displayName())->toBe('Lightning within 24 km of Home');
});

test('an explicit unit system overrides the owner\'s, and a custom name is never converted', function () {
    $rule = AlertRule::factory()->create(['threshold_value' => 32]);

    expect($rule->displayName(UnitSystem::Metric))->toBe('Temperature Falls below 0.0°C')
        ->and(AlertRule::factory()->create(['name' => 'My 32°F alert'])->displayName(UnitSystem::Metric))->toBe('My 32°F alert');
});
```

(`AlertRule::factory()` defaults to a threshold rule — `direction` `below` labels as "Falls below"; `Rises above` for `above`. If the enum labels differ, take the exact strings from `ThresholdDirection::label()`.)

Append to `tests/Feature/FormatMotionVectorTest.php`:

```php
test('a metric subscriber sees the motion speed in km/h', function () {
    $wwa = ActiveWwa::factory()->make(['motion_direction_degrees' => 231, 'motion_speed_kt' => 34]);

    expect((new FormatMotionVector)($wwa, App\Enums\UnitSystem::Metric))->toBe('Moving SW at 63 km/h')
        ->and((new FormatMotionVector)($wwa))->toBe('Moving SW at 39 mph');
});
```

Append to `tests/Feature/ComposeTemplatedAlertBodyTest.php` a test that mirrors an existing Motion-section test in that file but with the rule owned by `User::factory()->create(['unit_system' => 'metric'])` and asserts the composed body contains `Motion: Moving SW at 63 km/h` (kt 34) and not `mph`. Reuse that file's own helpers/fixtures for building the template rule and ActiveWwa.

- [ ] **Step 2: Run to verify they fail**

Run: `tests/Feature/AlertRuleDisplayNameUnitsTest.php tests/Feature/FormatMotionVectorTest.php tests/Feature/ComposeTemplatedAlertBodyTest.php`
Expected: the new tests FAIL; existing tests PASS.

- [ ] **Step 3: `AlertRule::displayName`**

In `app/Models/AlertRule.php` add `use App\Enums\UnitSystem;` and `use App\Support\Units;`. Change `displayName()`:

```php
    public function displayName(?UnitSystem $units = null): string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        return match ($this->category) {
            AlertCategory::WeatherAlerts, AlertCategory::SpcWpc => $this->products->pluck('name')->join(', ') ?: $this->category->label(),
            AlertCategory::DailySummary => "Daily summary for {$this->location?->label}",
            AlertCategory::Threshold => "{$this->variable?->label()} {$this->direction?->label()} {$this->variable?->format((float) $this->threshold_value, $units ?? Units::forRule($this)->system)}",
            AlertCategory::Lightning => 'Lightning within '.(new Units($units ?? Units::forRule($this)->system))->distance((float) $this->radius_miles, 0)." of {$this->location?->label}",
        };
    }
```

(The owner's system is resolved lazily, only for Threshold/Lightning rules, so list pages of other categories add no query.)

- [ ] **Step 4: `FormatMotionVector` and `ComposeTemplatedAlertBody`**

`FormatMotionVector`: add `use App\Enums\UnitSystem; use App\Support\Units;`, change the signature to `__invoke(?ActiveWwa $activeWwa, UnitSystem $units = UnitSystem::Imperial): ?string`, and replace the last two lines of the body with:

```php
        $mph = $activeWwa->motion_speed_kt * 1.15078;

        return "Moving {$compass} at ".(new Units($units))->speed($mph);
```

Update its class docblock sentence about "nearest whole mph" to say the speed is shown in the subscriber's unit.

`ComposeTemplatedAlertBody`: add `use App\Enums\UnitSystem; use App\Support\Units;`; in `__invoke` compute `$units = Units::forRule($rule)->system;` and pass it to `renderSection($section, $product, $activeWwa, $units)`; change `renderSection`'s signature to add `UnitSystem $units` and its Motion arm to `app(FormatMotionVector::class)($activeWwa, $units)`.

- [ ] **Step 5: The subscriber's own list and widget use their setting**

`AlertRulesTable.php`: add `use App\Support\Units;` and change the column state to `fn (AlertRule $record): string => $record->displayName(Units::for(auth()->user())->system)`.
`RecentAlertsWidget.php`: add `use App\Support\Units;` and change the fallback to `$record->subject ?? $record->alertRule?->displayName(Units::for(auth()->user())->system) ?? 'Alert'`.

(Admin panel callers — `AlertRulesRelationManager`, `DeliveryLogsTable` — keep calling `displayName()` with no argument, so staff see the rule owner's units.)

- [ ] **Step 6: Run to verify they pass, plus every suite that asserts a rule name**

Run: `tests/Feature/AlertRuleDisplayNameUnitsTest.php tests/Feature/FormatMotionVectorTest.php tests/Feature/ComposeTemplatedAlertBodyTest.php tests/Feature/AlertRuleResourceTest.php tests/Feature/DeliverScheduledAlertTest.php tests/Feature/DeliverWeatherAlertTest.php tests/Feature/MatchLightningFlashesTest.php`
Expected: all PASS.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/AlertRule.php app/Actions/Weather/FormatMotionVector.php app/Actions/Weather/ComposeTemplatedAlertBody.php app/Filament/Dashboard/Resources/AlertRules/Tables/AlertRulesTable.php app/Filament/Dashboard/Widgets/RecentAlertsWidget.php tests/Feature
git commit -m "Name alert rules and format the motion line in the subscriber's units

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The alert form converts in and out, with a no-drift guard

**Files:**
- Create: `app/Support/AlertRuleFormUnits.php`
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/EditAlertRule.php`, `.ai/rules/support.md`
- Test: `tests/Feature/AlertRuleFormUnitsTest.php`

**Interfaces:**
- Consumes: `Units`, `ThresholdVariable::{displayValue,canonicalValue,unit}`, `AlertRule::displayName()` (Tasks 1, 5).
- Produces: `AlertRuleFormUnits::toDisplay(array $data, Units $units): array` and `AlertRuleFormUnits::toStorage(array $data, Units $units, ?AlertRule $stored = null): array` (Imperial returns `$data` untouched).

Design note (why pages, not field hooks): the Create/Edit pages already reshape the data on fill/save (`mutateFormDataBeforeFill`, `mutateFormDataBeforeSave`), the value fields live in a dynamically-built Wizard step where per-field state hooks are fragile (`.ai/rules/filament.md`), and a page-level hook lets the no-drift guard compare against the stored record.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AlertRuleFormUnitsTest.php` (mirror the setup of `tests/Feature/AlertRuleResourceTest.php`: `beforeEach` seeds `ProductCatalogSeeder`, creates `$this->user`, `$this->location`, `$this->channel`, and `actingAs`):

```php
<?php

use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create(['unit_system' => 'metric']);
    $this->actingAs($this->user);
    $this->location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
});

test('a metric subscriber creates a threshold in celsius and it is stored in fahrenheit', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold',
            'variable' => 'temperature',
            'direction' => 'below',
            'threshold_value' => 0,
            'timeframe' => 'overnight',
            'location_id' => $this->location->id,
            'delivery_time' => '06:00',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect((float) $rule->threshold_value)->toBe(32.0)
        ->and($rule->displayName())->toBe('Temperature Falls below 0.0°C');
});

test('the threshold field shows the metric unit and the value in celsius when editing', function () {
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create(['variable' => 'temperature', 'threshold_value' => 35]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['threshold_value' => 1.7]);
});

test('saving without touching a converted value never rewrites it (no drift)', function () {
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create(['variable' => 'temperature', 'threshold_value' => 35]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->fillForm(['delivery_time' => '07:30'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->threshold_value)->toBe('35.0');
});

test('changing the value converts it back to fahrenheit', function () {
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create(['variable' => 'temperature', 'threshold_value' => 35]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->fillForm(['threshold_value' => 2])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->threshold_value)->toBe('35.6');
});

test('a wind threshold converts km/h to mph and percentages are never converted', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold', 'variable' => 'wind_speed', 'direction' => 'above', 'threshold_value' => 32.2,
            'timeframe' => 'day', 'location_id' => $this->location->id, 'delivery_time' => '06:00', 'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold', 'variable' => 'humidity', 'direction' => 'above', 'threshold_value' => 70,
            'timeframe' => 'day', 'location_id' => $this->location->id, 'delivery_time' => '06:00', 'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $wind = AlertRule::where('variable', 'wind_speed')->sole();
    $humidity = AlertRule::where('variable', 'humidity')->sole();

    expect((float) $wind->threshold_value)->toEqualWithDelta(20.0, 0.1)
        ->and((float) $humidity->threshold_value)->toBe(70.0);
});

test('a metric subscriber sets the lightning radius in km, stored as whole miles', function () {
    $this->user->forceFill(['tier_override' => App\Enums\Tier::Plus])->save();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning', 'location_id' => $this->location->id, 'radius_miles' => 24,
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->radius_miles)->toBe(15)
        ->and($rule->displayName())->toBe('Lightning within 24 km of Home');

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['radius_miles' => 24])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->radius_miles)->toBe(15);
});

test('the lightning radius minimum and maximum are enforced in the subscriber\'s unit', function () {
    $this->user->forceFill(['tier_override' => App\Enums\Tier::Plus])->save();
    $minMiles = app(TierThresholdSettings::class)->lightning_min_radius_miles;
    $minKm = (int) ceil($minMiles * 1.609344);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning', 'location_id' => $this->location->id, 'radius_miles' => $minKm - 1, 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasFormErrors(['radius_miles' => 'min']);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning', 'location_id' => $this->location->id, 'radius_miles' => 81, 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasFormErrors(['radius_miles' => 'max']);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning', 'location_id' => $this->location->id, 'radius_miles' => $minKm, 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasNoFormErrors();
});

test('an imperial subscriber\'s form is untouched', function () {
    $imperial = User::factory()->create();
    $this->actingAs($imperial);
    $location = Location::factory()->for($imperial)->create();
    $channel = NotificationChannel::factory()->for($imperial)->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold', 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32,
            'timeframe' => 'overnight', 'location_id' => $location->id, 'delivery_time' => '06:00', 'channel_ids' => [$channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $imperial->id)->sole()->threshold_value)->toBe('32.0');
});
```

(Lightning rules need a paid tier in this app — mirror how `AlertRuleResourceTest`'s lightning tests set the tier; if no tier setup is needed there, drop the `forceFill`. The threshold `timeframe` enum value `day`/`overnight` and the lightning `Plus` tier gate must be copied from the existing tests in `AlertRuleResourceTest.php`, not guessed.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/AlertRuleFormUnitsTest.php`
Expected: FAIL (values stored unconverted; the Metric radius label/limits are imperial).

- [ ] **Step 3: Implement `app/Support/AlertRuleFormUnits.php`**

```php
<?php

namespace App\Support;

use App\Enums\ThresholdVariable;
use App\Models\AlertRule;

/**
 * Converts the alert form's two unit-bearing fields between what a Metric
 * subscriber sees and what is stored (imperial). Imperial subscribers pass
 * through untouched.
 *
 * toStorage() carries the no-drift guard: a value the subscriber did not change
 * (the submitted number equals the display of the stored one) is replaced by the
 * stored value itself, so a stored 35.0 degF -- shown as 1.7 degC -- never
 * becomes 35.06 -> 35.1 just because the form was saved.
 */
class AlertRuleFormUnits
{
    /**
     * @param  array<string, mixed>  $data  stored (imperial) values
     * @return array<string, mixed>
     */
    public static function toDisplay(array $data, Units $units): array
    {
        if (! $units->isMetric()) {
            return $data;
        }

        $variable = ThresholdVariable::tryFrom((string) ($data['variable'] ?? ''));

        if ($variable !== null && is_numeric($data['threshold_value'] ?? null)) {
            $data['threshold_value'] = round($variable->displayValue((float) $data['threshold_value'], $units->system), 1);
        }

        if (is_numeric($data['radius_miles'] ?? null)) {
            $data['radius_miles'] = (int) round($units->distanceValue((float) $data['radius_miles']));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data  form values in the subscriber's unit
     * @return array<string, mixed>
     */
    public static function toStorage(array $data, Units $units, ?AlertRule $stored = null): array
    {
        if (! $units->isMetric()) {
            return $data;
        }

        $variable = ThresholdVariable::tryFrom((string) ($data['variable'] ?? ''));

        if ($variable !== null && is_numeric($data['threshold_value'] ?? null)) {
            $submitted = (float) $data['threshold_value'];
            $unchanged = $stored !== null
                && $stored->variable === $variable
                && is_numeric($stored->threshold_value)
                && abs($submitted - round($variable->displayValue((float) $stored->threshold_value, $units->system), 1)) < 0.0001;

            $data['threshold_value'] = $unchanged
                ? (float) $stored->threshold_value
                : round($variable->canonicalValue($submitted, $units->system), 1);
        }

        if (is_numeric($data['radius_miles'] ?? null)) {
            $submitted = (int) round((float) $data['radius_miles']);
            $unchanged = $stored !== null
                && $stored->radius_miles !== null
                && $submitted === (int) round($units->distanceValue((float) $stored->radius_miles));

            $data['radius_miles'] = $unchanged ? (int) $stored->radius_miles : (int) round($units->kilometersToMiles($submitted));
        }

        return $data;
    }
}
```

- [ ] **Step 4: Wire the pages**

`CreateAlertRule.php`: add `use App\Support\AlertRuleFormUnits; use App\Support\Units;` and replace `mutateFormDataBeforeCreate` with:

```php
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return AlertRuleFormUnits::toStorage($data, Units::for(auth()->user()));
    }
```

`EditAlertRule.php`: add the same two imports; in `mutateFormDataBeforeFill()` change the final `return $data;` to `return AlertRuleFormUnits::toDisplay($data, Units::for(auth()->user()));`; in `mutateFormDataBeforeSave()` change the final `return $data;` to `return AlertRuleFormUnits::toStorage($data, Units::for(auth()->user()), $this->record);`.

- [ ] **Step 5: The form's labels, suffix and limits follow the setting**

In `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` add `use App\Support\Units;` and change:

- `threshold_value`'s suffix to `->suffix(fn (Get $get): ?string => ThresholdVariable::tryFrom($get('variable'))?->unit(Units::for(auth()->user())->system))`.
- the `radius_miles` field (keep the field NAME, it is the stored column):

```php
            TextInput::make('radius_miles')
                ->label(fn (): string => 'Alert radius ('.Units::for(auth()->user())->distanceUnitName().')')
                ->numeric()
                ->minValue(fn (): int => Units::for(auth()->user())->minDistanceDisplay(app(TierThresholdSettings::class)->lightning_min_radius_miles))
                ->maxValue(fn (): int => Units::for(auth()->user())->maxDistanceDisplay(50))
                ->default(15)
                ->required()
                ->helperText(fn (): string => 'Minimum '.Units::for(auth()->user())->minDistanceDisplay(app(TierThresholdSettings::class)->lightning_min_radius_miles).' '.Units::for(auth()->user())->distanceUnitName().' — tighter radii outrun what satellite lightning detection can reliably pinpoint.'),
```

(Imperial output is identical: `distanceUnitName()` is `miles`, min/max are the same integers.)

- [ ] **Step 6: Record the project rule**

Append to `.ai/rules/support.md`:

```markdown

## Units: imperial is stored, Metric is converted at the edges through App\Support\Units
Every stored and computed measurement is imperial (alert_rules.threshold_value in degF/mph/%, radius_miles in miles; NWS metric data is converted to imperial on ingest). A subscriber's `users.unit_system` only changes how a value is displayed and how form input is converted back, so switching units never changes what an existing alert means. All factors and labels live in `App\Support\Units` (and `ThresholdVariable::{unit,displayValue,canonicalValue,format}` for threshold values) — never add a conversion factor anywhere else, and Imperial output must stay byte-identical. NWS prose (detailedForecast, hourly wind text) goes through `NwsTextUnits::toMetric()` for Metric only, sentence by sentence, reverting a sentence it cannot fully convert. The alert form converts on the Create/Edit PAGES (`AlertRuleFormUnits::toDisplay/toStorage`), not in field hooks, because the value fields sit in a dynamically-built Wizard step; `toStorage` has a no-drift guard (an untouched converted value is never rewritten) — keep it when touching those fields. Stored text (delivery-log subjects, sent notifications) is not rewritten when units change. Raw NWS product text inside alerts stays as issued.
```

- [ ] **Step 7: Run to verify it passes, then the full suite**

Run: `tests/Feature/AlertRuleFormUnitsTest.php tests/Feature/AlertRuleResourceTest.php tests/Feature/AlertRuleDeliveryTimeResetTest.php`
Expected: all PASS (every existing Imperial form test unmodified).

Then the full suite once in the background (`docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact'`). Expected: all pass, 0 failures; the count is higher than the count before this plan (about 1578).

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/AlertRuleFormUnits.php app/Filament/Dashboard/Resources/AlertRules .ai/rules/support.md tests/Feature/AlertRuleFormUnitsTest.php
git commit -m "Convert alert form values to and from the subscriber's units, without drift

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-Review (run against the spec)

**Spec coverage:** Profile field + default Imperial → Tasks 1, 3. Storage stays imperial / no stored data rewritten → Global Constraints + Task 6 guard. `UnitSystem`, `Units` API, factors → Task 1. `ThresholdVariable` unit-aware → Task 1. Daily Summary (current temp/feels/dewpoint, wind+gust, barometer, visibility, elevation, hourly temps+wind text, 7-day temps+prose) → Task 4 presenter; `units` key on the report and the old-report-without-`units` → Imperial → Task 4; Brief text → Task 4; threshold messages → Task 4; motion line → Task 5; `displayName()` (owner's system, optional override, admin default) → Task 5; alert form suffix/value/radius label/min-max/helper → Task 6; no-drift guard → Task 6; `NwsTextUnits` with the conversion patterns, the sentence safety rule, idempotence, the real-corpus test → Task 2 (fixture committed with the plan); Imperial byte-identical regression → each task runs the existing suites; Non-goals recorded in Global Constraints. The spec's open items are resolved: conversion lives on the Create/Edit pages (Task 6 design note); `displayName()` takes an optional `UnitSystem` and otherwise resolves the owner's (Task 5); the corpus fixture is `tests/Fixtures/nws_detailed_forecasts.json`.

**Placeholder scan:** none. Three steps tell the implementer to copy exact enum labels / fixtures / tier setup from named existing tests rather than guessing (Task 5 `ThresholdDirection::label()`, Task 5 ComposeTemplatedAlertBody test fixtures, Task 6 tier gate) — each names the exact source.

**Type consistency:** `Units` method names used in Tasks 4–6 (`temperature`, `speed`, `pressure`, `distance`, `elevation`, `distanceValue`, `kilometersToMiles`, `minDistanceDisplay`, `maxDistanceDisplay`, `distanceUnitName`, `isMetric`, `system`) all match Task 1's interface list. `ThresholdVariable::format/displayValue/canonicalValue/unit(UnitSystem)` match their uses. `NwsTextUnits::toMetric` and `Units::number` are used as defined. The report key `units` and `Units::fromReport` agree. `AlertRule::displayName(?UnitSystem)` matches the table/widget callers.
