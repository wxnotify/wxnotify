# Custom Alert Templates (Section-Picker Builder) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a new, reusable `AlertTemplate` resource that lets a Plus/Pro subscriber pick which sections of a Weather Alert to include (hazard, source, impact, locations impacted, expiration, motion, call to action) in a fixed canonical order, with a simpler subject-only template type for SPC/WPC Alerts — purely additive alongside today's existing `custom_subject_template`/`message_body_style` mechanism, which stays completely untouched.

**Architecture:** A new `alert_templates` table owned by the subscriber, attached to an `AlertRule` via a nullable `alert_template_id` FK (null-on-delete — matching this schema's real precedent for optional relations). Two new small Actions (`FormatMotionVector`, `ComposeTemplatedAlertBody`) compose the labeled-lines body from `IngestedProduct`'s narrative columns and `ActiveWwa`'s expiration/motion columns; `DeliverWeatherAlert` uses the composed body when present, falling back to exactly today's existing raw-text/`message_body_style` behavior otherwise. `RenderAlertSubject` gains a new highest-priority tier (the attached template's subject) ahead of its existing `custom_subject_template` fallback. No columns are dropped and no data migration is needed — `DeliverScheduledAlert` (Daily Summary/Threshold Alerts) is untouched by this plan.

**Tech Stack:** Laravel 13, PHP 8.4, Filament v5, Pest.

**Design doc:** `docs/superpowers/specs/2026-09-15-custom-alert-templates-design.md` — read it first for full rationale, especially the Context correction note (this plan does NOT drop `custom_subject_template`/`message_body_style` — an earlier draft of the design did, and that was corrected after discovering `DeliverScheduledAlert` also depends on them) and the Out-of-Scope section (this plan covers `WeatherAlerts` and `SpcWpc` categories only).

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_15_020000_create_alert_templates_table.php` | New `alert_templates` table |
| `app/Enums/AlertTemplateSection.php` | The 7 selectable sections, in fixed canonical order |
| `app/Models/AlertTemplate.php` | New model |
| `database/factories/AlertTemplateFactory.php` | New factory |
| `database/migrations/2026_09_15_020001_add_alert_template_id_to_alert_rules_table.php` | New nullable FK column on `alert_rules` |
| `app/Models/AlertRule.php` | Modify: `alertTemplate()` relation, `alert_template_id` fillable — nothing else changes |
| `app/Models/User.php` | Modify: `alertTemplates()` relation |
| `app/Actions/Weather/FormatMotionVector.php` | New: degrees/knots → readable "Moving ESE at 20 mph" |
| `app/Actions/Weather/ComposeTemplatedAlertBody.php` | New: composes the labeled-lines body from a rule's attached template |
| `app/Jobs/DeliverWeatherAlert.php` | Modify: try `ComposeTemplatedAlertBody` first, fall back to exactly today's existing code |
| `app/Actions/Weather/RenderAlertSubject.php` | Modify: try the attached template's subject first, fall back to exactly today's existing `effectiveCustomSubjectTemplate()` logic |
| `app/Filament/Dashboard/Resources/AlertTemplates/AlertTemplateResource.php` | New Filament resource |
| `app/Filament/Dashboard/Resources/AlertTemplates/Schemas/AlertTemplateForm.php` | New: name/category/subject/sections form |
| `app/Filament/Dashboard/Resources/AlertTemplates/Tables/AlertTemplatesTable.php` | New: list table |
| `app/Filament/Dashboard/Resources/AlertTemplates/Pages/{ListAlertTemplates,CreateAlertTemplate,EditAlertTemplate}.php` | New: standard CRUD pages |
| `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` | Modify: `quietHoursStep()` gains an `alert_template_id` Select alongside (not replacing) the existing two fields |

---

## Task 1: `alert_templates` table, `AlertTemplateSection` enum, `AlertTemplate` model

**Files:**
- Create: `database/migrations/2026_09_15_020000_create_alert_templates_table.php`
- Create: `app/Enums/AlertTemplateSection.php`
- Create: `app/Models/AlertTemplate.php`
- Create: `database/factories/AlertTemplateFactory.php`

No failing-test step — schema and model plumbing, verified via a real migration run and a Tinker round-trip, matching this codebase's established convention for schema-only tasks (see e.g. `database/migrations/2026_09_15_010000_add_narrative_sections_to_ingested_products_table.php`'s own task in the narrative-parser plan).

- [ ] **Step 1: Create the migration**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_alert_templates_table --no-interaction"
```

Rename the generated file to exactly `database/migrations/2026_09_15_020000_create_alert_templates_table.php`, then set its content to:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category');
            $table->text('subject_template')->nullable();
            // Only meaningful for category = weather_alerts -- an array of
            // App\Enums\AlertTemplateSection values. Always null/ignored
            // for spc_wpc templates, which only ever use subject_template.
            $table->json('sections')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_templates');
    }
};
```

- [ ] **Step 2: Create the `AlertTemplateSection` enum**

Create `app/Enums/AlertTemplateSection.php`:

```php
<?php

namespace App\Enums;

/**
 * The 7 sections a weather_alerts-category AlertTemplate can include,
 * declared in the fixed canonical order they always render in -- a
 * subscriber's template never controls order, only which of these are
 * present (see the design doc's rejected "reorderable" approach).
 */
enum AlertTemplateSection: string
{
    case Hazard = 'hazard';
    case Source = 'source';
    case Impact = 'impact';
    case LocationsImpacted = 'locations_impacted';
    case Expiration = 'expiration';
    case Motion = 'motion';
    case CallToAction = 'call_to_action';

    public function label(): string
    {
        return match ($this) {
            self::Hazard => 'Hazard',
            self::Source => 'Source',
            self::Impact => 'Impact',
            self::LocationsImpacted => 'Locations impacted',
            self::Expiration => 'Expiration',
            self::Motion => 'Motion',
            self::CallToAction => 'Call to action',
        };
    }
}
```

- [ ] **Step 3: Create the `AlertTemplate` model**

Create `app/Models/AlertTemplate.php`:

```php
<?php

namespace App\Models;

use App\Enums\AlertCategory;
use Database\Factories\AlertTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'category', 'subject_template', 'sections'])]
class AlertTemplate extends Model
{
    /** @use HasFactory<AlertTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => AlertCategory::class,
            'sections' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }
}
```

- [ ] **Step 4: Create the factory**

Create `database/factories/AlertTemplateFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\AlertCategory;
use App\Enums\AlertTemplateSection;
use App\Models\AlertTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertTemplate>
 */
class AlertTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'category' => AlertCategory::WeatherAlerts,
            'subject_template' => null,
            'sections' => [AlertTemplateSection::Hazard->value, AlertTemplateSection::Source->value, AlertTemplateSection::Impact->value],
        ];
    }

    public function spcWpc(): static
    {
        return $this->state(fn (): array => [
            'category' => AlertCategory::SpcWpc,
            'sections' => null,
        ]);
    }
}
```

- [ ] **Step 5: Run the migration and verify with Tinker**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

Expected: runs with no errors.

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute='
\$t = App\Models\AlertTemplate::factory()->create();
echo \$t->category->value . \" | \" . json_encode(\$t->sections) . PHP_EOL;
echo App\Enums\AlertTemplateSection::Hazard->label() . PHP_EOL;
'"
```

Expected: prints `weather_alerts | ["hazard","source","impact"]` then `Hazard`.

- [ ] **Step 6: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

```bash
git add database/migrations/2026_09_15_020000_create_alert_templates_table.php app/Enums/AlertTemplateSection.php app/Models/AlertTemplate.php database/factories/AlertTemplateFactory.php
git commit -m "$(cat <<'EOF'
Add AlertTemplate model, alert_templates table, and AlertTemplateSection enum

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `alert_template_id` on `alert_rules`

**Files:**
- Create: `database/migrations/2026_09_15_020001_add_alert_template_id_to_alert_rules_table.php`
- Modify: `app/Models/AlertRule.php`

- [ ] **Step 1: Create the migration**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration add_alert_template_id_to_alert_rules_table --no-interaction"
```

Rename to `database/migrations/2026_09_15_020001_add_alert_template_id_to_alert_rules_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            // null-on-delete, not restrict: unlike location_id (which a
            // rule structurally needs), a template is a pure rendering
            // nicety -- a rule whose template gets deleted just falls
            // back to the next tier down (custom_subject_template /
            // message_body_style, then displayName()/raw text), a fully
            // working state. Matches this schema's real precedent for
            // optional relations (location_id is also nullOnDelete()) --
            // see the design doc's corrected note on this.
            $table->foreignId('alert_template_id')->nullable()->after('message_body_style')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('alert_template_id');
        });
    }
};
```

- [ ] **Step 2: Add the relation and fillable entry to `AlertRule`**

Read `app/Models/AlertRule.php` first. Add `'alert_template_id'` to the end of the `#[Fillable]` list (after `'message_body_style'`, before the closing `])`). Add this relation method, placed after the existing `notificationChannels()` method and before `effectiveCustomSubjectTemplate()`:

```php
    public function alertTemplate(): BelongsTo
    {
        return $this->belongsTo(AlertTemplate::class);
    }
```

No new import needed — `AlertTemplate` is in the same `App\Models` namespace as `AlertRule`. Do NOT touch `effectiveCustomSubjectTemplate()`/`effectiveMessageBodyStyle()`, the `#[Fillable]` entries for `custom_subject_template`/`message_body_style`, or any casts — those stay exactly as they are, since `DeliverScheduledAlert` still depends on them unchanged.

- [ ] **Step 3: Run the migration and verify with Tinker**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute='
\$template = App\Models\AlertTemplate::factory()->create();
\$rule = App\Models\AlertRule::factory()->create([\"alert_template_id\" => \$template->id]);
echo \$rule->alertTemplate->name . PHP_EOL;
\$template->delete();
echo var_export(\$rule->fresh()->alert_template_id, true) . PHP_EOL;
'"
```

Expected: prints the template's name, then `NULL` — confirms the relation works and deleting the template nulls the rule's FK rather than throwing or cascading.

- [ ] **Step 4: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

```bash
git add database/migrations/2026_09_15_020001_add_alert_template_id_to_alert_rules_table.php app/Models/AlertRule.php
git commit -m "$(cat <<'EOF'
Add alert_template_id to alert_rules

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `FormatMotionVector` action

**Files:**
- Create: `app/Actions/Weather/FormatMotionVector.php`
- Test: `tests/Feature/FormatMotionVectorTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/FormatMotionVectorTest.php`:

```php
<?php

use App\Actions\Weather\FormatMotionVector;
use App\Models\ActiveWwa;

test('formats a real motion vector into a compass direction and mph', function () {
    $wwa = ActiveWwa::factory()->make(['motion_direction_degrees' => 231, 'motion_speed_kt' => 34]);

    $result = (new FormatMotionVector)($wwa);

    expect($result)->toBe('Moving SW at 39 mph');
});

test('rounds 0 degrees to North and 360 degrees also to North', function () {
    $north = ActiveWwa::factory()->make(['motion_direction_degrees' => 0, 'motion_speed_kt' => 10]);
    $alsoNorth = ActiveWwa::factory()->make(['motion_direction_degrees' => 360, 'motion_speed_kt' => 10]);

    expect((new FormatMotionVector)($north))->toBe('Moving N at 12 mph')
        ->and((new FormatMotionVector)($alsoNorth))->toBe('Moving N at 12 mph');
});

test('returns null when the ActiveWwa is null', function () {
    expect((new FormatMotionVector)(null))->toBeNull();
});

test('returns null when motion_direction_degrees is missing', function () {
    $wwa = ActiveWwa::factory()->make(['motion_direction_degrees' => null, 'motion_speed_kt' => 34]);

    expect((new FormatMotionVector)($wwa))->toBeNull();
});

test('returns null when motion_speed_kt is missing', function () {
    $wwa = ActiveWwa::factory()->make(['motion_direction_degrees' => 231, 'motion_speed_kt' => null]);

    expect((new FormatMotionVector)($wwa))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/FormatMotionVectorTest.php --compact"
```

Expected: FAIL — the class doesn't exist.

- [ ] **Step 3: Implement the action**

Create `app/Actions/Weather/FormatMotionVector.php`:

```php
<?php

namespace App\Actions\Weather;

use App\Models\ActiveWwa;

/**
 * Formats an ActiveWwa's motion_direction_degrees/motion_speed_kt into a
 * readable line like "Moving ESE at 20 mph", for the Motion section of a
 * composed alert template body. No existing compass-direction utility
 * exists elsewhere in this codebase to reuse -- this is new, self-contained
 * logic. Knots are converted to the nearest whole mph (x1.15078) since mph
 * is the more familiar unit for a US subscriber-facing message, even
 * though NWS's own eventMotionDescription parameter (see
 * SyncActiveWwa::parseMotion()) reports in knots.
 */
class FormatMotionVector
{
    private const COMPASS_POINTS = [
        'N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE',
        'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW',
    ];

    public function __invoke(?ActiveWwa $activeWwa): ?string
    {
        if ($activeWwa === null || $activeWwa->motion_direction_degrees === null || $activeWwa->motion_speed_kt === null) {
            return null;
        }

        $compass = self::COMPASS_POINTS[(int) round($activeWwa->motion_direction_degrees / 22.5) % 16];
        $mph = (int) round($activeWwa->motion_speed_kt * 1.15078);

        return "Moving {$compass} at {$mph} mph";
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/FormatMotionVectorTest.php --compact"
```

Expected: PASS (5 tests). Verify the math: 231° / 22.5 = 10.27 → rounds to 10 → `COMPASS_POINTS[10]` = `'SW'`. 34kt × 1.15078 = 39.13 → rounds to 39.

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

```bash
git add app/Actions/Weather/FormatMotionVector.php tests/Feature/FormatMotionVectorTest.php
git commit -m "$(cat <<'EOF'
Add FormatMotionVector action

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `ComposeTemplatedAlertBody` action

**Files:**
- Create: `app/Actions/Weather/ComposeTemplatedAlertBody.php`
- Test: `tests/Feature/ComposeTemplatedAlertBodyTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ComposeTemplatedAlertBodyTest.php`:

```php
<?php

use App\Actions\Weather\ComposeTemplatedAlertBody;
use App\Enums\AlertTemplateSection;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\IngestedProduct;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function vtecKeyFor(): array
{
    return [
        'vtec_office' => 'KTWC',
        'vtec_phenomenon' => 'FF',
        'vtec_significance' => 'W',
        'vtec_event_number' => '0209',
        'vtec_year' => 2026,
    ];
}

test('composes a labeled-lines body from the checked sections in fixed canonical order', function () {
    $template = AlertTemplate::factory()->create([
        'sections' => [AlertTemplateSection::Impact->value, AlertTemplateSection::Hazard->value, AlertTemplateSection::Source->value],
    ]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create([
        ...vtecKeyFor(),
        'hazard' => 'Flash flooding caused by thunderstorms.',
        'hazard_source' => 'Radar indicated.',
        'impact' => 'Flash flooding of small creeks and streams.',
    ]);

    $result = (new ComposeTemplatedAlertBody)($rule, $product);

    expect($result)->toBe("Hazard: Flash flooding caused by thunderstorms.\nSource: Radar indicated.\nImpact: Flash flooding of small creeks and streams.");
});

test('skips a checked section that is null on this specific event, rather than showing a placeholder', function () {
    $template = AlertTemplate::factory()->create([
        'sections' => [AlertTemplateSection::Hazard->value, AlertTemplateSection::LocationsImpacted->value],
    ]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create([...vtecKeyFor(), 'hazard' => 'Flash flooding.', 'locations_impacted' => null]);

    $result = (new ComposeTemplatedAlertBody)($rule, $product);

    expect($result)->toBe('Hazard: Flash flooding.');
});

test('pulls expiration and motion from the matching ActiveWwa row via the VTEC key', function () {
    // Time is frozen so expires->diffForHumans() renders a deterministic
    // string -- unfrozen, "now()->addHour()" and the render-time "now" a
    // moment later drift apart (e.g. "59 minutes from now" instead of
    // "1 hour from now"), making an exact-string assertion flaky.
    Carbon::setTestNow('2026-09-15 12:00:00');

    $template = AlertTemplate::factory()->create(['sections' => [AlertTemplateSection::Expiration->value, AlertTemplateSection::Motion->value]]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create(vtecKeyFor());
    ActiveWwa::factory()->create([...vtecKeyFor(), 'expires' => now()->addHour(), 'motion_direction_degrees' => 231, 'motion_speed_kt' => 34]);

    $result = (new ComposeTemplatedAlertBody)($rule, $product);

    expect($result)->toBe("Expiration: 1 hour from now\nMotion: Moving SW at 39 mph");
});

test('returns null when the rule has no attached template', function () {
    $rule = AlertRule::factory()->create(['alert_template_id' => null]);
    $product = IngestedProduct::factory()->create();

    expect((new ComposeTemplatedAlertBody)($rule, $product))->toBeNull();
});

test('returns null for an spc_wpc-category template, which has no sections of its own', function () {
    $template = AlertTemplate::factory()->spcWpc()->create();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create();

    expect((new ComposeTemplatedAlertBody)($rule, $product))->toBeNull();
});

test('returns null for a weather_alerts template with zero sections checked', function () {
    $template = AlertTemplate::factory()->create(['sections' => []]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create();

    expect((new ComposeTemplatedAlertBody)($rule, $product))->toBeNull();
});

test('returns null when every checked section is null for this event', function () {
    $template = AlertTemplate::factory()->create(['sections' => [AlertTemplateSection::LocationsImpacted->value, AlertTemplateSection::CallToAction->value]]);
    $rule = AlertRule::factory()->create(['alert_template_id' => $template->id]);
    $product = IngestedProduct::factory()->create(['locations_impacted' => null, 'call_to_action' => null]);

    expect((new ComposeTemplatedAlertBody)($rule, $product))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ComposeTemplatedAlertBodyTest.php --compact"
```

Expected: FAIL — the class doesn't exist.

- [ ] **Step 3: Implement the action**

Create `app/Actions/Weather/ComposeTemplatedAlertBody.php`:

```php
<?php

namespace App\Actions\Weather;

use App\Enums\AlertCategory;
use App\Enums\AlertTemplateSection;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\IngestedProduct;

/**
 * Composes the labeled-lines message body for a rule with an attached
 * weather_alerts-category AlertTemplate -- "Hazard: ...\nSource: ...",
 * one line per checked section that actually has data for this specific
 * event, always in AlertTemplateSection's fixed declaration order
 * regardless of how `sections` happens to be stored. Returns null in
 * every case where there's nothing to compose (no template, an
 * spc_wpc-category template, a weather_alerts template with no sections
 * checked, or every checked section coming back null for this event) --
 * DeliverWeatherAlert treats null as "fall back to today's existing
 * message_body_style/raw-text behavior", never as an error.
 */
class ComposeTemplatedAlertBody
{
    public function __invoke(AlertRule $rule, IngestedProduct $product): ?string
    {
        $template = $rule->alertTemplate;

        if ($template === null || $template->category !== AlertCategory::WeatherAlerts) {
            return null;
        }

        $checked = collect($template->sections ?? [])
            ->map(fn (string $value): ?AlertTemplateSection => AlertTemplateSection::tryFrom($value))
            ->filter()
            ->all();

        if ($checked === []) {
            return null;
        }

        $activeWwa = $this->findActiveWwa($product);

        $lines = collect(AlertTemplateSection::cases())
            ->filter(fn (AlertTemplateSection $section): bool => in_array($section, $checked, true))
            ->map(fn (AlertTemplateSection $section): ?string => $this->renderSection($section, $product, $activeWwa))
            ->filter()
            ->values();

        return $lines->isEmpty() ? null : $lines->implode("\n");
    }

    private function renderSection(AlertTemplateSection $section, IngestedProduct $product, ?ActiveWwa $activeWwa): ?string
    {
        $value = match ($section) {
            AlertTemplateSection::Hazard => $product->hazard,
            AlertTemplateSection::Source => $product->hazard_source,
            AlertTemplateSection::Impact => $product->impact,
            AlertTemplateSection::LocationsImpacted => $product->locations_impacted,
            AlertTemplateSection::Expiration => $activeWwa?->expires?->diffForHumans(),
            AlertTemplateSection::Motion => app(FormatMotionVector::class)($activeWwa),
            AlertTemplateSection::CallToAction => $product->call_to_action,
        };

        return $value !== null ? "{$section->label()}: {$value}" : null;
    }

    /**
     * Same VTEC-composite-key lookup MatchWeatherAlertRules::matchesActiveWwaPolygon()
     * already uses to correlate an IngestedProduct to its current-state
     * ActiveWwa row.
     */
    private function findActiveWwa(IngestedProduct $product): ?ActiveWwa
    {
        if ($product->vtec_office === null) {
            return null;
        }

        return ActiveWwa::query()
            ->where('vtec_office', $product->vtec_office)
            ->where('vtec_phenomenon', $product->vtec_phenomenon)
            ->where('vtec_significance', $product->vtec_significance)
            ->where('vtec_event_number', $product->vtec_event_number)
            ->where('vtec_year', $product->vtec_year)
            ->first();
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ComposeTemplatedAlertBodyTest.php --compact"
```

Expected: PASS (7 tests).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

```bash
git add app/Actions/Weather/ComposeTemplatedAlertBody.php tests/Feature/ComposeTemplatedAlertBodyTest.php
git commit -m "$(cat <<'EOF'
Add ComposeTemplatedAlertBody action

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Wire `ComposeTemplatedAlertBody` into `DeliverWeatherAlert`

**Files:**
- Modify: `app/Jobs/DeliverWeatherAlert.php`
- Test: `tests/Feature/DeliverWeatherAlertTest.php`

This is a purely additive change — every existing test in this file must keep passing unmodified, since `custom_subject_template`/`message_body_style` behavior is completely unchanged.

- [ ] **Step 1: Write the new failing tests**

Read the full current `tests/Feature/DeliverWeatherAlertTest.php` first to confirm its exact current imports and structure (nothing in it should need changing — this step is read-only verification that Tasks 1-4 haven't broken anything here, since none of them touched this file or dropped any columns). Add these 3 new tests, placed after the existing `'a CAP-sourced product with no description falls back to its raw text rather than crashing'` test:

```php
test('a rule with an attached weather_alerts template sends the composed labeled-lines body instead of raw text', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $template = AlertTemplate::factory()->for($plusUser)->create(['sections' => ['hazard', 'source']]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'alert_template_id' => $template->id,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create([
        'text' => 'This raw text must NOT be what gets sent.',
        'hazard' => 'Flash flooding caused by thunderstorms.',
        'hazard_source' => 'Radar indicated.',
    ]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['body'] === "Hazard: Flash flooding caused by thunderstorms.\nSource: Radar indicated.");
});

test('a rule with a template that composes to null (e.g. spc_wpc) falls back to raw text exactly as today', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $template = AlertTemplate::factory()->for($plusUser)->spcWpc()->create();
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null,
        'alert_template_id' => $template->id,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create(['text' => 'Convective outlook raw text.']);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Convective outlook raw text.');
});

test('a composed template body still respects a fixed 1500-character safety-net cap', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $template = AlertTemplate::factory()->for($plusUser)->create(['sections' => ['hazard', 'source', 'impact', 'locations_impacted', 'call_to_action']]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'alert_template_id' => $template->id,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $longSentence = str_repeat('Extremely long hazard text that keeps going and going. ', 20);
    $product = IngestedProduct::factory()->create([
        'hazard' => $longSentence, 'hazard_source' => $longSentence, 'impact' => $longSentence,
        'locations_impacted' => $longSentence, 'call_to_action' => $longSentence,
    ]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => strlen($request['body']) <= 1500);
});
```

Add `use App\Models\AlertTemplate;` to the top imports, alongside the existing `use App\Models\AlertRule;` line.

- [ ] **Step 2: Run the tests to verify the new ones fail and nothing else breaks**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverWeatherAlertTest.php --compact"
```

Expected: the 3 new tests FAIL (since `DeliverWeatherAlert` doesn't call `ComposeTemplatedAlertBody` yet); every pre-existing test in the file still PASSES unmodified — this confirms Tasks 1-4 introduced no regressions here.

- [ ] **Step 3: Wire `ComposeTemplatedAlertBody` into `DeliverWeatherAlert`**

Read `app/Jobs/DeliverWeatherAlert.php`'s current full content. Change this line:

```php
        $bodySource = $product->format === 'cap' ? ($product->description ?? $product->text) : $product->text;
```

to:

```php
        $templatedBody = app(ComposeTemplatedAlertBody::class)($rule, $product);
        $bodySource = $templatedBody ?? ($product->format === 'cap' ? ($product->description ?? $product->text) : $product->text);
        $maxLength = $templatedBody !== null ? 1500 : ($rule->effectiveMessageBodyStyle() === MessageBodyStyle::Brief ? 280 : 1500);
```

Then change the `Str::limit(...)` call inside the `$client->notify(...)` block from:

```php
            body: Str::limit($bodySource, $rule->effectiveMessageBodyStyle() === MessageBodyStyle::Brief ? 280 : 1500, preserveWords: true),
```

to:

```php
            body: Str::limit($bodySource, $maxLength, preserveWords: true),
```

Add `use App\Actions\Weather\ComposeTemplatedAlertBody;` to the top imports, alongside the existing `use App\Actions\Weather\RenderAlertSubject;` line. `MessageBodyStyle` should already be imported in this file (it's used in the pre-existing `effectiveMessageBodyStyle() === MessageBodyStyle::Brief` comparison) — confirm it still is; don't remove it.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverWeatherAlertTest.php --compact"
```

Expected: PASS, every test in the file — the 3 new ones and every pre-existing `message_body_style`/`custom_subject_template` test, all green.

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

```bash
git add app/Jobs/DeliverWeatherAlert.php tests/Feature/DeliverWeatherAlertTest.php
git commit -m "$(cat <<'EOF'
Wire ComposeTemplatedAlertBody into DeliverWeatherAlert, falling back to today's existing behavior

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Update `RenderAlertSubject` to try the attached template first

**Files:**
- Modify: `app/Actions/Weather/RenderAlertSubject.php`
- Test: `tests/Feature/RenderAlertSubjectTest.php`

Also purely additive — every existing test in this file must keep passing unmodified.

- [ ] **Step 1: Write the new failing test**

Read the full current `tests/Feature/RenderAlertSubjectTest.php` first (confirm nothing here has broken — Tasks 1-5 didn't touch `custom_subject_template`/`effectiveCustomSubjectTemplate()`). Add this new test, placed after whichever existing test covers the plain `custom_subject_template` happy path:

```php
test('an attached AlertTemplate\'s subject takes priority over an old custom_subject_template on the same rule', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $template = AlertTemplate::factory()->for($plusUser)->create(['subject_template' => 'From template: {product}']);
    $product = Product::factory()->create(['name' => 'Tornado Warning']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'custom_subject_template' => 'From old field: {product}',
        'alert_template_id' => $template->id,
    ]);

    $result = (new RenderAlertSubject)($rule, $product);

    expect($result)->toBe('From template: Tornado Warning');
});

test('falls through to the old custom_subject_template when no AlertTemplate is attached', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $product = Product::factory()->create(['name' => 'Tornado Warning']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'custom_subject_template' => 'From old field: {product}',
        'alert_template_id' => null,
    ]);

    $result = (new RenderAlertSubject)($rule, $product);

    expect($result)->toBe('From old field: Tornado Warning');
});

test('falls through to the old custom_subject_template when the attached AlertTemplate has a blank subject', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $template = AlertTemplate::factory()->for($plusUser)->create(['subject_template' => null]);
    $product = Product::factory()->create(['name' => 'Tornado Warning']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'custom_subject_template' => 'From old field: {product}',
        'alert_template_id' => $template->id,
    ]);

    $result = (new RenderAlertSubject)($rule, $product);

    expect($result)->toBe('From old field: Tornado Warning');
});
```

Add `use App\Models\AlertTemplate;` to the top imports if not already present.

- [ ] **Step 2: Run the tests to verify the new ones fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/RenderAlertSubjectTest.php --compact"
```

Expected: the 3 new tests FAIL (the first and third for the wrong reason — no priority/fallback logic yet — the second should already pass by coincidence since it doesn't touch new behavior; confirm all 3 fail or pass for the right reasons before proceeding). Every pre-existing test should still pass.

- [ ] **Step 3: Update the action**

Read `app/Actions/Weather/RenderAlertSubject.php`'s current full content. Replace:

```php
        if (blank($rule->effectiveCustomSubjectTemplate())) {
            return $rule->displayName();
        }

        return strtr($rule->effectiveCustomSubjectTemplate(), [
```

with:

```php
        $subjectTemplate = $rule->alertTemplate?->subject_template ?? $rule->effectiveCustomSubjectTemplate();

        if (blank($subjectTemplate)) {
            return $rule->displayName();
        }

        return strtr($subjectTemplate, [
```

Update the class docblock's final sentence (currently ending on something like "...since CAP does not pre-split this content" or similar — read the actual current wording) to add one clause noting the new three-tier priority: an attached `AlertTemplate`'s `subject_template` first, then the rule's own `custom_subject_template`, then `displayName()`.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/RenderAlertSubjectTest.php --compact"
```

Expected: PASS, every test in the file.

- [ ] **Step 5: Run the full suite**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact" 2>&1 | tail -40
```

Expected: 100% pass. Since nothing in Tasks 1-6 has dropped a column or removed a method, there should be no remaining breakage anywhere — including `tests/Feature/DeliverScheduledAlertTest.php`, which is completely untouched by this plan and should never have broken in the first place.

- [ ] **Step 6: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

```bash
git add app/Actions/Weather/RenderAlertSubject.php tests/Feature/RenderAlertSubjectTest.php
git commit -m "$(cat <<'EOF'
Give an attached AlertTemplate's subject priority in RenderAlertSubject

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: `AlertTemplateResource` (Filament CRUD)

**Files:**
- Create: `app/Filament/Dashboard/Resources/AlertTemplates/AlertTemplateResource.php`
- Create: `app/Filament/Dashboard/Resources/AlertTemplates/Schemas/AlertTemplateForm.php`
- Create: `app/Filament/Dashboard/Resources/AlertTemplates/Tables/AlertTemplatesTable.php`
- Create: `app/Filament/Dashboard/Resources/AlertTemplates/Pages/ListAlertTemplates.php`
- Create: `app/Filament/Dashboard/Resources/AlertTemplates/Pages/CreateAlertTemplate.php`
- Create: `app/Filament/Dashboard/Resources/AlertTemplates/Pages/EditAlertTemplate.php`
- Test: `tests/Feature/AlertTemplateResourceTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AlertTemplateResourceTest.php`:

```php
<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\CreateAlertTemplate;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\EditAlertTemplate;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\ListAlertTemplates;
use App\Models\AlertTemplate;
use App\Models\User;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($this->user);
});

test('a Plus/Pro subscriber can create a weather_alerts template with sections', function () {
    livewire(CreateAlertTemplate::class)
        ->fillForm([
            'name' => 'Detailed Severe Weather',
            'category' => 'weather_alerts',
            'subject_template' => '{product} for {location}',
            'sections' => ['hazard', 'impact'],
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(AlertTemplate::class, [
        'user_id' => $this->user->id,
        'name' => 'Detailed Severe Weather',
        'category' => 'weather_alerts',
    ]);

    expect(AlertTemplate::sole()->sections)->toBe(['hazard', 'impact']);
});

test('a Plus/Pro subscriber can create an spc_wpc template with no sections field involved', function () {
    livewire(CreateAlertTemplate::class)
        ->fillForm([
            'name' => 'Outlook Subject Only',
            'category' => 'spc_wpc',
            'subject_template' => 'SPC: {product}',
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(AlertTemplate::class, [
        'name' => 'Outlook Subject Only',
        'category' => 'spc_wpc',
    ]);
});

test('editing an existing template persists changes', function () {
    $template = AlertTemplate::factory()->for($this->user)->create(['name' => 'Old name']);

    livewire(EditAlertTemplate::class, ['record' => $template->id])
        ->fillForm(['name' => 'New name'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(AlertTemplate::class, ['id' => $template->id, 'name' => 'New name']);
});

test('the list only shows the current subscriber\'s own templates', function () {
    $mine = AlertTemplate::factory()->for($this->user)->create();
    $someoneElses = AlertTemplate::factory()->create();

    livewire(ListAlertTemplates::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

test('a Free-tier subscriber cannot access the resource at all', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));

    expect(AlertTemplateResource::canAccess())->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertTemplateResourceTest.php --compact"
```

Expected: FAIL — none of the resource classes exist yet.

- [ ] **Step 3: Create the form schema**

Create `app/Filament/Dashboard/Resources/AlertTemplates/Schemas/AlertTemplateForm.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Schemas;

use App\Enums\AlertCategory;
use App\Enums\AlertTemplateSection;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AlertTemplateForm
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
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->helperText('Your own label for this template, e.g. "Detailed Severe Weather".'),
            Select::make('category')
                ->label('Applies to')
                ->options([
                    AlertCategory::WeatherAlerts->value => AlertCategory::WeatherAlerts->label(),
                    AlertCategory::SpcWpc->value => AlertCategory::SpcWpc->label(),
                ])
                ->required()
                ->live()
                ->helperText('Only alert rules in this category can use this template.'),
            TextInput::make('subject_template')
                ->label('Custom subject line (optional)')
                ->maxLength(255)
                ->helperText('Leave blank to use the default. Placeholders: {product}, {location}, {category}.'),
            CheckboxList::make('sections')
                ->label('Include in message')
                ->columns(2)
                ->options(collect(AlertTemplateSection::cases())->mapWithKeys(fn (AlertTemplateSection $s): array => [$s->value => $s->label()]))
                ->helperText('Appears in this order in the message, regardless of check order. Leave all unchecked to send the raw product text instead.')
                ->visible(fn (Get $get): bool => $get('category') === AlertCategory::WeatherAlerts->value),
        ];
    }
}
```

- [ ] **Step 4: Create the table**

Create `app/Filament/Dashboard/Resources/AlertTemplates/Tables/AlertTemplatesTable.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Tables;

use App\Models\AlertTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AlertTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state->label()),
                TextColumn::make('sections')
                    ->label('Sections')
                    ->formatStateUsing(fn (AlertTemplate $record): string => $record->sections === null || $record->sections === []
                        ? '—'
                        : count($record->sections).' selected'),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
```

- [ ] **Step 5: Create the resource and pages**

Create `app/Filament/Dashboard/Resources/AlertTemplates/AlertTemplateResource.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates;

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\CreateAlertTemplate;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\EditAlertTemplate;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\ListAlertTemplates;
use App\Filament\Dashboard\Resources\AlertTemplates\Schemas\AlertTemplateForm;
use App\Filament\Dashboard\Resources\AlertTemplates\Tables\AlertTemplatesTable;
use App\Models\AlertTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AlertTemplateResource extends Resource
{
    protected static ?string $model = AlertTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Alerting';

    protected static ?string $navigationLabel = 'Alert Templates';

    protected static ?string $modelLabel = 'alert template';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()->tier() !== Tier::Free;
    }

    public static function canAccess(): bool
    {
        return auth()->user()->tier() !== Tier::Free;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return AlertTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AlertTemplatesTable::configure($table);
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
            'index' => ListAlertTemplates::route('/'),
            'create' => CreateAlertTemplate::route('/create'),
            'edit' => EditAlertTemplate::route('/{record}/edit'),
        ];
    }
}
```

Create `app/Filament/Dashboard/Resources/AlertTemplates/Pages/ListAlertTemplates.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Pages;

use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlertTemplates extends ListRecords
{
    protected static string $resource = AlertTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

Create `app/Filament/Dashboard/Resources/AlertTemplates/Pages/CreateAlertTemplate.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Pages;

use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlertTemplate extends CreateRecord
{
    protected static string $resource = AlertTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
```

Create `app/Filament/Dashboard/Resources/AlertTemplates/Pages/EditAlertTemplate.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Pages;

use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAlertTemplate extends EditRecord
{
    protected static string $resource = AlertTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertTemplateResourceTest.php --compact"
```

Expected: PASS (5 tests).

- [ ] **Step 7: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

```bash
git add app/Filament/Dashboard/Resources/AlertTemplates tests/Feature/AlertTemplateResourceTest.php
git commit -m "$(cat <<'EOF'
Add AlertTemplateResource (Filament CRUD)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Add the `alert_template_id` Select to `AlertRuleForm`, alongside the existing fields

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/AlertRuleResourceTest.php`

Purely additive — the existing `message_body_style`/`custom_subject_template` fields in `quietHoursStep()` stay exactly as they are; a new field is added alongside them.

- [ ] **Step 1: Add the `alertTemplates()` relation to `User`**

Read `app/Models/User.php`. Find the existing `notificationChannels()` or `locations()` relation method for the exact style to match, then add, placed nearby:

```php
    public function alertTemplates(): HasMany
    {
        return $this->hasMany(AlertTemplate::class);
    }
```

No new import needed for `AlertTemplate` (same `App\Models` namespace). Confirm `HasMany` is already imported at the top of the file (it should be, given `locations()`/`notificationChannels()` already exist) — add `use Illuminate\Database\Eloquent\Relations\HasMany;` only if it's genuinely missing.

- [ ] **Step 2: Write the new failing test**

Read `tests/Feature/AlertRuleResourceTest.php`'s current full content first, to confirm the exact page class name(s) used for the wizard's Create/Edit Livewire tests (e.g. `CreateAlertRule`) and this file's existing `livewire(...)` import/usage pattern. Add this new test, matching that same pattern:

```php
test('the alert_template_id select only offers templates matching the rule\'s own category', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($plusUser);

    AlertTemplate::factory()->for($plusUser)->create(['category' => 'weather_alerts', 'name' => 'Matching']);
    AlertTemplate::factory()->for($plusUser)->spcWpc()->create(['name' => 'Mismatched']);

    $product = Product::factory()->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => ['warning' => [$product->id]],
            'matching_mode' => 'nationwide',
        ])
        ->assertFormFieldExists('alert_template_id');
});
```

(Adjust `CreateAlertRule`, `product_ids`'s exact nested shape, and any other required fields on this fill to match whatever the file's OTHER existing category-switching tests already do for a `weather_alerts` rule — copy their exact `fillForm()` shape rather than guessing new field names, since `AlertRuleForm`'s dynamic per-category schema has several required fields depending on category and matching mode that this plan doesn't have full visibility into without reading that file directly.)

Add `use App\Models\AlertTemplate;` and `use App\Models\Product;` to the top imports if not already present.

- [ ] **Step 3: Run the tests to verify the new one fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php --compact"
```

Expected: the new test FAILS (`alert_template_id` doesn't exist in the schema yet); every pre-existing test in the file still passes.

- [ ] **Step 4: Add the Select**

Read `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`'s current full content. Inside `quietHoursStep()`'s `->schema([...])` array, add a new entry after the existing `custom_subject_template` `TextInput` (i.e. as the last item in that array):

```php
                self::alertTemplateSelect()
                    ->visible(fn (Get $get): bool => auth()->user()->tier() !== Tier::Free
                        && in_array($get('category'), [AlertCategory::WeatherAlerts->value, AlertCategory::SpcWpc->value], true)),
```

Do NOT remove or modify the existing `message_body_style` Select or `custom_subject_template` TextInput — they stay exactly as they are, for every category.

Add this new method, placed after `quietHoursStep()` and before `weatherAlertsFields()`:

```php
    /**
     * Deliberately native, not ->searchable()/->relationship() -- same
     * dynamic-Wizard-step entangle bug documented on locationSelect() and
     * wfoField() above applies here too, since this field's ->visible()
     * closure depends on `category`, which is set in an earlier step.
     * Additive alongside message_body_style/custom_subject_template above,
     * not a replacement -- see this field's helperText.
     */
    protected static function alertTemplateSelect(): Select
    {
        return Select::make('alert_template_id')
            ->label('Message template (optional)')
            ->options(fn (Get $get): array => auth()->user()->alertTemplates()
                ->where('category', $get('category'))
                ->pluck('name', 'id')
                ->all())
            ->createOptionForm(AlertTemplateForm::fields())
            ->createOptionAction(fn (Action $action): Action => $action->modalHeading('Add a template'))
            ->createOptionUsing(fn (array $data): int => auth()->user()->alertTemplates()->create($data)->getKey())
            ->helperText('Takes priority over the subject line and message length above when set. Leave blank to keep using those.');
    }
```

Add this import at the top of the file: `use App\Filament\Dashboard\Resources\AlertTemplates\Schemas\AlertTemplateForm;`. `Select`, `Action`, `AlertCategory`, `Tier`, and `Get` should all already be imported in this file (they're used by the existing fields) — do not remove any of them.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php --compact"
```

Expected: PASS, every test in the file — the new one and every pre-existing `message_body_style`/`custom_subject_template` test, all green (they should never have needed to change).

- [ ] **Step 6: Run the full suite**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact" 2>&1 | tail -20
```

Expected: 100% pass.

- [ ] **Step 7: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

```bash
git add app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php app/Models/User.php tests/Feature/AlertRuleResourceTest.php
git commit -m "$(cat <<'EOF'
Add alert_template_id Select to AlertRuleForm's Finishing touches step

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Full-suite verification and manual browser check

**Files:** none (verification only)

- [ ] **Step 1: Run the complete test suite**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"
```

Expected: 100% pass, no regressions anywhere in the suite — including `tests/Feature/DeliverScheduledAlertTest.php`, which this plan never touches.

- [ ] **Step 2: Run Pint across the whole diff**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

Expected: `{"tool":"pint","result":"passed"}`.

- [ ] **Step 3: Manual browser verification**

Using the Browser tooling against the running dev panel: log in as a Plus/Pro-tier subscriber, navigate to Alerting → Alert Templates, create a `weather_alerts` template with a subject line and 3-4 sections checked, then go to My Alerts → New Alert → Weather Alerts, confirm the new "Message template" Select on the "Finishing touches" step appears alongside (not instead of) the existing "Message length" and "Custom subject line" fields, lists the just-created template, select it, save the rule. Confirm no console errors and the rule persists with the correct `alert_template_id`. Separately, confirm a Daily Summary rule's "Finishing touches" step looks completely unchanged from before this feature (no new Select visible, since Daily Summary isn't `weather_alerts`/`spc_wpc`).

- [ ] **Step 4: Final commit (if step 3 surfaced any fixes)**

```bash
git add -A
git commit -m "Fix issues found during manual verification of Custom Alert Templates"
```

(Skip this step entirely if step 3 found nothing to fix.)

---

## Self-Review Notes

**Spec coverage:** Every section of the corrected design doc has a corresponding task — schema/model (Tasks 1-2), the Motion formatter and body composer (Tasks 3-4), the additive `DeliverWeatherAlert`/`RenderAlertSubject` wiring (Tasks 5-6), the Filament resource (Task 7), and the additive wizard integration (Task 8). The design's explicit out-of-scope items (Daily Summary/Threshold/Lightning/Forecast Discussion template types, a rich `SpcWpc` picker, live preview, Free-tier access, subscriber-controlled ordering, dropping/migrating the old columns) have no corresponding task, matching the design doc's own scope boundary — no task in this plan touches `DeliverScheduledAlert.php` or `tests/Feature/DeliverScheduledAlertTest.php` at all.

**Placeholder scan:** No TBD/TODO markers. Task 8 Step 2's test deliberately asks the implementer to read the real current `AlertRuleResourceTest.php` and copy an existing test's exact `fillForm()` shape rather than guessing field names — this is a "verify against the real file" instruction (that file's exact dynamic-schema field requirements weren't read during plan-writing), not an unfinished requirement, matching the same pattern used successfully in the narrative-parser plan's Task 4.

**Type consistency:** `AlertTemplateSection`'s 7 cases and their string values are used identically across `AlertTemplate`'s `sections` cast, `ComposeTemplatedAlertBody`'s `match()`, and `AlertTemplateForm`'s `CheckboxList` options — introduced once in Task 1, never redefined. `alert_template_id` is named identically across the migration, the `AlertRule`/`User` relations, the Filament Select field name, and every test.

**On-delete behavior:** Deliberately null-on-delete, not restrict — see Task 2's migration comment and the design doc's corrected note.

**Corrected mid-plan-writing:** an earlier draft of both the design doc and this plan called for dropping `custom_subject_template`/`message_body_style` and running a one-time data migration. That was caught during implementation-context-gathering (`DeliverScheduledAlert`, serving Daily Summary/Threshold Alerts, also depends on those columns and was never mentioned in the original design's scope discussion) and corrected to a purely additive approach before any task was implemented — no column drop, no data migration, and `DeliverScheduledAlert` is untouched by every task in this plan.
