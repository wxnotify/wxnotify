# Alert Templates v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a Plus/Pro subscriber set a per-rule custom notification subject line (with a small set of safe placeholders) and choose between the full message body and a shorter, mechanically-truncated one.

**Architecture:** Two new nullable columns on `alert_rules`, a new `RenderAlertSubject` action that both delivery jobs (`DeliverWeatherAlert`, `DeliverScheduledAlert`) call right before dispatching to Apprise, and two new fields in the alert-rule wizard's existing "Finishing touches" step, hidden entirely for Free subscribers.

**Tech Stack:** Laravel 13, Filament v5, PHP 8.4, Pest.

**Design doc:** `docs/superpowers/specs/2026-09-14-alert-templates-design.md` — read it first for the full rationale behind every decision below, especially why this is a deliberately scoped-down v1 (the master plan's full section-picker template builder needs a text parser that doesn't exist yet).

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/..._add_message_customization_to_alert_rules_table.php` | New columns |
| `app/Enums/MessageBodyStyle.php` | `Full`/`Brief` enum |
| `app/Models/AlertRule.php` | Fillable + cast additions |
| `app/Actions/Weather/RenderAlertSubject.php` | Placeholder substitution, falls back to `displayName()` |
| `app/Jobs/DeliverWeatherAlert.php` | Wire in subject override + body-length choice |
| `app/Jobs/DeliverScheduledAlert.php` | Same wiring, no `Product` available on this path |
| `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` | Two new wizard fields, Plus/Pro-only |

---

## Task 1: Migration + `MessageBodyStyle` enum

**Files:**
- Create: `database/migrations/2026_09_14_040000_add_message_customization_to_alert_rules_table.php`
- Create: `app/Enums/MessageBodyStyle.php`

- [ ] **Step 1: Create the migration**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration add_message_customization_to_alert_rules_table --no-interaction"
```

Rename the generated file (Artisan will timestamp it with today's actual time, not `040000`) to exactly `database/migrations/2026_09_14_040000_add_message_customization_to_alert_rules_table.php`, then set its content to:

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
            $table->string('custom_subject_template')->nullable()->after('quiet_hours_end');
            $table->string('message_body_style')->nullable()->after('custom_subject_template');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->dropColumn(['custom_subject_template', 'message_body_style']);
        });
    }
};
```

- [ ] **Step 2: Create the enum**

```php
<?php

namespace App\Enums;

enum MessageBodyStyle: string
{
    case Full = 'full';
    case Brief = 'brief';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Brief => 'Brief',
        };
    }
}
```

- [ ] **Step 3: Run the migration and verify**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

Expected: the migration runs with no errors.

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'var_dump(in_array(\"custom_subject_template\", Schema::getColumnListing(\"alert_rules\")), in_array(\"message_body_style\", Schema::getColumnListing(\"alert_rules\")));'"
```

Expected: `bool(true)` twice.

- [ ] **Step 4: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations/2026_09_14_040000_add_message_customization_to_alert_rules_table.php app/Enums/MessageBodyStyle.php
git commit -m "Add message customization columns to alert_rules and MessageBodyStyle enum"
```

---

## Task 2: `AlertRule` model — Fillable + cast

**Files:**
- Modify: `app/Models/AlertRule.php`

- [ ] **Step 1: Add the two new columns to `#[Fillable]`**

In `app/Models/AlertRule.php`, change:

```php
#[Fillable([
    'user_id',
    'category',
    'name',
    'matching_mode',
    'location_id',
    'wfo',
    'county_zone_codes',
    'risk_levels',
    'variable',
    'direction',
    'threshold_value',
    'timeframe',
    'delivery_time',
    'last_scheduled_run_on',
    'radius_miles',
    'lightning_last_alerted_at',
    'quiet_hours_start',
    'quiet_hours_end',
    'is_active',
])]
```

to:

```php
#[Fillable([
    'user_id',
    'category',
    'name',
    'matching_mode',
    'location_id',
    'wfo',
    'county_zone_codes',
    'risk_levels',
    'variable',
    'direction',
    'threshold_value',
    'timeframe',
    'delivery_time',
    'last_scheduled_run_on',
    'radius_miles',
    'lightning_last_alerted_at',
    'quiet_hours_start',
    'quiet_hours_end',
    'is_active',
    'custom_subject_template',
    'message_body_style',
])]
```

- [ ] **Step 2: Add the cast**

In the same file, change:

```php
    protected function casts(): array
    {
        return [
            'category' => AlertCategory::class,
            'matching_mode' => MatchingMode::class,
            'risk_levels' => 'array',
            'variable' => ThresholdVariable::class,
            'direction' => ThresholdDirection::class,
            'threshold_value' => 'decimal:1',
            'timeframe' => ThresholdTimeframe::class,
            'last_scheduled_run_on' => 'date',
            'radius_miles' => 'integer',
            'lightning_last_alerted_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
```

to:

```php
    protected function casts(): array
    {
        return [
            'category' => AlertCategory::class,
            'matching_mode' => MatchingMode::class,
            'risk_levels' => 'array',
            'variable' => ThresholdVariable::class,
            'direction' => ThresholdDirection::class,
            'threshold_value' => 'decimal:1',
            'timeframe' => ThresholdTimeframe::class,
            'last_scheduled_run_on' => 'date',
            'radius_miles' => 'integer',
            'lightning_last_alerted_at' => 'datetime',
            'is_active' => 'boolean',
            'message_body_style' => MessageBodyStyle::class,
        ];
    }
```

Add the import alongside the other `App\Enums\*` imports at the top of the file:

```php
use App\Enums\MessageBodyStyle;
```

(`custom_subject_template` needs no cast — it's a plain string.)

- [ ] **Step 3: Verify via tinker**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'App\Models\AlertRule::factory()->create([\"custom_subject_template\" => \"test\", \"message_body_style\" => \"brief\"])->message_body_style;'"
```

Expected: prints `App\Enums\MessageBodyStyle Enum ( [value] => brief )` (or equivalent enum dump) — proves the cast round-trips correctly.

- [ ] **Step 4: Run the full test suite and Pint, then commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"
```

Expected: 100% pass, zero regressions (this modifies a model every existing `AlertRule` test depends on).

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Models/AlertRule.php
git commit -m "Add custom_subject_template/message_body_style to AlertRule's Fillable and casts"
```

---

## Task 3: `RenderAlertSubject` action

**Files:**
- Create: `app/Actions/Weather/RenderAlertSubject.php`
- Test: `tests/Feature/RenderAlertSubjectTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Weather\RenderAlertSubject;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\Product;

test('no custom template falls back to displayName()', function () {
    $rule = AlertRule::factory()->create(['category' => 'threshold', 'custom_subject_template' => null]);

    expect((new RenderAlertSubject)($rule))->toBe($rule->displayName());
});

test('a template using all three placeholders resolves correctly', function () {
    $product = Product::factory()->create(['name' => 'Tornado Warning']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'nationwide',
        'location_id' => null,
        'custom_subject_template' => '{category}: {product} for {location}',
    ]);

    $result = (new RenderAlertSubject)($rule, $product);

    expect($result)->toBe('Weather Alerts: Tornado Warning for your area');
});

test('{product} falls back to the category label when no product is given', function () {
    $rule = AlertRule::factory()->create([
        'category' => 'threshold',
        'custom_subject_template' => 'Alert: {product}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Alert: Threshold Alerts');
});

test('{location} resolves to the rule\'s location label when present', function () {
    $location = Location::factory()->create(['label' => 'Home']);
    $rule = AlertRule::factory()->create([
        'category' => 'threshold',
        'location_id' => $location->id,
        'custom_subject_template' => 'Near {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Near Home');
});

test('{location} falls back to the WFO code when the rule has a WFO but no location', function () {
    $rule = AlertRule::factory()->create([
        'category' => 'forecast_discussion',
        'matching_mode' => null,
        'location_id' => null,
        'wfo' => 'OKX',
        'custom_subject_template' => 'Discussion for {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Discussion for WFO OKX');
});

test('{location} falls back to "your area" when the rule has neither a location nor a WFO', function () {
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'nationwide',
        'location_id' => null,
        'custom_subject_template' => 'Near {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Near your area');
});

test('an unrecognized placeholder renders literally rather than erroring', function () {
    $rule = AlertRule::factory()->create([
        'category' => 'threshold',
        'custom_subject_template' => 'Alert: {bogus}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Alert: {bogus}');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/RenderAlertSubjectTest.php --compact"
```

Expected: FAIL — the class doesn't exist.

- [ ] **Step 3: Implement the action**

```php
<?php

namespace App\Actions\Weather;

use App\Models\AlertRule;
use App\Models\Product;

/**
 * Renders a per-rule custom notification subject/title via plain
 * placeholder substitution -- logic-less strtr(), nothing executable,
 * since a subscriber-authored template runs on every dispatch of their
 * alerts. Falls back to AlertRule::displayName() (the existing admin/
 * list-table label, unchanged and untouched by this class) when no
 * custom_subject_template is set.
 */
class RenderAlertSubject
{
    public function __invoke(AlertRule $rule, ?Product $product = null): string
    {
        if (blank($rule->custom_subject_template)) {
            return $rule->displayName();
        }

        return strtr($rule->custom_subject_template, [
            '{product}' => $product?->name ?? $rule->category->label(),
            '{location}' => $rule->location?->label ?? ($rule->wfo !== null ? "WFO {$rule->wfo}" : 'your area'),
            '{category}' => $rule->category->label(),
        ]);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/RenderAlertSubjectTest.php --compact"
```

Expected: PASS (7 tests).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Weather/RenderAlertSubject.php tests/Feature/RenderAlertSubjectTest.php
git commit -m "Add RenderAlertSubject action"
```

---

## Task 4: Wire into `DeliverWeatherAlert`

**Files:**
- Modify: `app/Jobs/DeliverWeatherAlert.php`
- Test: `tests/Feature/DeliverWeatherAlertTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DeliverWeatherAlertTest.php` (it already imports `AlertRule`, `IngestedProduct`, `NotificationChannel`, `Http`; add `use App\Models\Product;` if not already present):

```php
test('a custom subject template is used as the Apprise title instead of displayName()', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $product = Product::factory()->create(['name' => 'Tornado Warning']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'custom_subject_template' => 'Alert: {product}',
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $ingested->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['title'] === 'Alert: Tornado Warning');
});

test('message_body_style Brief truncates the body to 280 characters', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'message_body_style' => 'brief',
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $longText = str_repeat('Tornado Warning issued for the area. ', 30);
    $product = IngestedProduct::factory()->create(['text' => $longText]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => strlen($request['body']) <= 280);
});

test('message_body_style Full (or null) preserves today\'s 1500-character behavior', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'message_body_style' => null,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $longText = str_repeat('Tornado Warning issued for the area. ', 60);
    $product = IngestedProduct::factory()->create(['text' => $longText]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => strlen($request['body']) > 280 && strlen($request['body']) <= 1500);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverWeatherAlertTest.php --compact"
```

Expected: the 3 new tests FAIL (title is still `displayName()`, body is still capped at exactly 1500 regardless of `message_body_style`); the pre-existing tests still PASS.

- [ ] **Step 3: Wire in the subject/body-length override**

In `app/Jobs/DeliverWeatherAlert.php`, add these imports alongside the existing ones:

```php
use App\Actions\Weather\RenderAlertSubject;
use App\Enums\MessageBodyStyle;
```

Then change:

```php
        $delivered = $client->notify(
            url: $channel->apprise_url,
            body: Str::limit($product->text, 1500, preserveWords: true),
            title: $rule->displayName(),
        );
```

to:

```php
        $delivered = $client->notify(
            url: $channel->apprise_url,
            body: Str::limit($product->text, $rule->message_body_style === MessageBodyStyle::Brief ? 280 : 1500, preserveWords: true),
            title: app(RenderAlertSubject::class)($rule, $product->product),
        );
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverWeatherAlertTest.php --compact"
```

Expected: PASS, all tests in the file (the 6 pre-existing + 3 new = 9).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Jobs/DeliverWeatherAlert.php tests/Feature/DeliverWeatherAlertTest.php
git commit -m "Wire custom subject/message length into DeliverWeatherAlert"
```

---

## Task 5: Wire into `DeliverScheduledAlert`

**Files:**
- Modify: `app/Jobs/DeliverScheduledAlert.php`
- Test: `tests/Feature/DeliverScheduledAlertTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DeliverScheduledAlertTest.php` (it already imports `AlertRule`, `NotificationChannel`, `Http`):

```php
test('a custom subject template is used as the Apprise title instead of the passed-in title', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->dailySummary()->create(['custom_subject_template' => 'My custom title']);
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Ignored default title', 'Today: Sunny, high 88.'))
        ->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['title'] === 'My custom title');
});

test('message_body_style Brief truncates the passed-in body to 280 characters', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->dailySummary()->create(['message_body_style' => 'brief']);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $longBody = str_repeat('Sunny with a high of 88 degrees. ', 30);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', $longBody))
        ->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => strlen($request['body']) <= 280);
});

test('message_body_style Full (or null) preserves the passed-in body unchanged', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->dailySummary()->create(['message_body_style' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny, high 88.'))
        ->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Today: Sunny, high 88.');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverScheduledAlertTest.php --compact"
```

Expected: the 3 new tests FAIL; the pre-existing tests still PASS.

- [ ] **Step 3: Wire in the subject/body-length override**

In `app/Jobs/DeliverScheduledAlert.php`, add these imports alongside the existing ones:

```php
use App\Actions\Weather\RenderAlertSubject;
use App\Enums\MessageBodyStyle;
use Illuminate\Support\Str;
```

Then change:

```php
        $delivered = $client->notify(
            url: $channel->apprise_url,
            body: $this->body,
            title: $this->title,
        );
```

to:

```php
        $title = app(RenderAlertSubject::class)($rule);
        $body = $rule->message_body_style === MessageBodyStyle::Brief
            ? Str::limit($this->body, 280, preserveWords: true)
            : $this->body;

        $delivered = $client->notify(
            url: $channel->apprise_url,
            body: $body,
            title: $title,
        );
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DeliverScheduledAlertTest.php --compact"
```

Expected: PASS, all tests in the file (the 5 pre-existing + 3 new = 8).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Jobs/DeliverScheduledAlert.php tests/Feature/DeliverScheduledAlertTest.php
git commit -m "Wire custom subject/message length into DeliverScheduledAlert"
```

---

## Task 6: Wizard UI (Plus/Pro-gated)

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`
- Test: `tests/Feature/AlertRuleResourceTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AlertRuleResourceTest.php` (it already has a `beforeEach` setting up `$this->user`/`$this->location`/`$this->channel`, and already imports `Tier`, `CreateAlertRule`, `EditAlertRule`, `AlertRule`):

```php
test('a Free subscriber\'s wizard never shows the message customization fields', function () {
    $this->user->forceFill(['tier_override' => Tier::Free])->save();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'threshold'])
        ->assertFormFieldHidden('message_body_style')
        ->assertFormFieldHidden('custom_subject_template');
});

test('a Plus subscriber can set and persist a custom subject and message length', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold',
            'variable' => 'temperature',
            'direction' => 'below',
            'threshold_value' => 32,
            'timeframe' => 'overnight',
            'location_id' => $this->location->id,
            'delivery_time' => '06:00',
            'channel_ids' => [$this->channel->id],
            'custom_subject_template' => 'Cold snap near {location}',
            'message_body_style' => 'brief',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();
    expect($rule->custom_subject_template)->toBe('Cold snap near {location}')
        ->and($rule->message_body_style->value)->toBe('brief');
});

test('editing an existing rule round-trips the custom subject and message length', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create([
        'custom_subject_template' => 'Original subject',
        'message_body_style' => 'brief',
    ]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet([
            'custom_subject_template' => 'Original subject',
            'message_body_style' => 'brief',
        ]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php --compact"
```

Expected: the 3 new tests FAIL — `message_body_style`/`custom_subject_template` don't exist in the wizard schema yet, so `assertFormFieldHidden` fails with "field does not exist" and the other two tests fail on missing form state. The pre-existing tests still PASS.

- [ ] **Step 3: Add the two fields to the wizard**

In `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`, add these imports alongside the existing `use App\Enums\*` lines:

```php
use App\Enums\MessageBodyStyle;
use App\Enums\Tier;
```

Then change `quietHoursStep()`:

```php
    protected static function quietHoursStep(): Step
    {
        return Step::make('Finishing touches')
            ->schema([
                TextInput::make('name')
                    ->label('Name this alert (optional)')
                    ->maxLength(255)
                    ->helperText('Leave blank and we\'ll generate one from your settings.'),
                TimePicker::make('quiet_hours_start')
                    ->label('Quiet hours start')
                    ->seconds(false),
                TimePicker::make('quiet_hours_end')
                    ->label('Quiet hours end')
                    ->seconds(false)
                    ->helperText('Optional — skip notifications for this alert during a window each day.'),
            ]);
    }
```

to:

```php
    protected static function quietHoursStep(): Step
    {
        return Step::make('Finishing touches')
            ->schema([
                TextInput::make('name')
                    ->label('Name this alert (optional)')
                    ->maxLength(255)
                    ->helperText('Leave blank and we\'ll generate one from your settings.'),
                TimePicker::make('quiet_hours_start')
                    ->label('Quiet hours start')
                    ->seconds(false),
                TimePicker::make('quiet_hours_end')
                    ->label('Quiet hours end')
                    ->seconds(false)
                    ->helperText('Optional — skip notifications for this alert during a window each day.'),
                Select::make('message_body_style')
                    ->label('Message length')
                    ->options([
                        MessageBodyStyle::Full->value => 'Full',
                        MessageBodyStyle::Brief->value => 'Brief',
                    ])
                    ->default(MessageBodyStyle::Full)
                    ->visible(fn (): bool => auth()->user()->tier() !== Tier::Free),
                TextInput::make('custom_subject_template')
                    ->label('Custom subject line (optional)')
                    ->maxLength(255)
                    ->helperText('Leave blank to use the default. Placeholders: {product}, {location}, {category}.')
                    ->visible(fn (): bool => auth()->user()->tier() !== Tier::Free),
            ]);
    }
```

(`Select` is already imported in this file.)

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AlertRuleResourceTest.php --compact"
```

Expected: PASS, every test in the file (existing tests included — verify none regressed).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php tests/Feature/AlertRuleResourceTest.php
git commit -m "Add Plus/Pro-gated message customization fields to the alert-rule wizard"
```

---

## Task 7: Full suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the complete test suite**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"
```

Expected: 100% pass, no regressions anywhere in the suite.

- [ ] **Step 2: Run Pint across the whole diff**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

Expected: `{"tool":"pint","result":"passed"}`.

- [ ] **Step 3: Manual browser verification**

As a Plus/Pro test user, create or edit an alert rule and confirm: the "Finishing touches" step shows "Message length" and "Custom subject line" fields; as a Free test user, confirm those two fields are absent from the same step. Trigger a real delivery (or inspect via tinker) to confirm a rendered subject actually reaches Apprise's payload as the `title`.

- [ ] **Step 4: Final commit (if step 3 surfaced any fixes)**

```bash
git add -A
git commit -m "Fix issues found during manual verification of Alert Templates"
```

(Skip this step entirely if step 3 found nothing to fix.)
