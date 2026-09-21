# App Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A tabbed staff-only "App settings" admin page (General + Tier & threshold defaults) backed by `spatie/laravel-settings`, with the existing hardcoded tier limits and the Lightning radius floor reading from it, a maintenance-mode banner and write guard, and every change recorded in the audit log.

**Architecture:** Two typed settings classes in `app/Settings/` (auto-discovered), created by settings migrations in `database/settings/`. One custom Filament admin page holds a tab per class and saves each class it owns. `User`'s limit methods and the Lightning form read the settings. A model trait rejects subscriber writes during maintenance for authenticated non-staff, non-impersonated web users only, so queue jobs, console commands and token webhooks (no signed-in user) are never blocked. A `SavingSettings` listener writes old/new values to the activity log.

**Tech Stack:** Laravel 13, Filament v5, `spatie/laravel-settings` 3.9 (already installed as part of Filament's suite, together with `filament/spatie-laravel-settings-plugin`), `spatie/laravel-activitylog`, Pest.

**Spec:** `docs/superpowers/specs/2026-09-20-app-settings-design.md`

## Global Constraints

- No new Composer dependencies are needed: `spatie/laravel-settings` (3.9.0) and `filament/spatie-laravel-settings-plugin` are already in `composer.lock`/`vendor`. Do not run `composer require`/`update`.
- Leave `CLAUDE.md` unchanged (composer/boost hooks can rewrite it; `git checkout CLAUDE.md` before committing if it shows modified).
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:seed`. Do NOT run `php artisan migrate` on the dev database (the controller does that after review). Tests use `RefreshDatabase`.
- Run tests only inside the Sail container: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest <path> --compact"`. Run `vendor/bin/pint --dirty --format agent` on the host before each commit. Commit messages end with `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Do not push.
- Before editing, read `.ai/rules/index.md` and the rule files matching the paths you touch (`.ai/rules/filament.md` for `app/Filament/**`, `.ai/rules/models.md` for `app/Models/**`, `.ai/rules/feature.md` for `tests/Feature/**`). Style: curly braces always, explicit return types, constructor promotion.
- Defaults must equal today's hardcoded values, except the Lightning floor. Tier limits: locations Free 1, Plus 5, Pro unlimited (never a setting); Free no-cost channels 1, Plus/Pro channels unlimited (never a setting); Traccar servers Free 0 / Plus 1 / Pro 3; Traccar devices per server Free 0 / Plus 5 / Pro 10.
- Lightning minimum radius: default **10** miles, bounds **5–15** (raises the current hardcoded minimum of 5 for newly created/edited rules; existing rules keep their stored radius and are only re-validated when edited).
- Access: staff only (`is_staff`), same flat rule as the other admin tools.
- Maintenance mode never stops ingestion or alert delivery: the guard only applies when there is an authenticated, non-staff, non-impersonated user.

---

### Task 1: Settings foundation and the two settings classes

**Files:**
- Create: `config/settings.php` (published, then edited)
- Create: `app/Settings/GeneralSettings.php`
- Create: `app/Settings/TierThresholdSettings.php`
- Create: `database/settings/2026_09_20_300001_create_general_settings.php`
- Create: `database/settings/2026_09_20_300002_create_tier_threshold_settings.php`
- Test: `tests/Feature/AppSettingsTest.php`

**Interfaces:**
- Produces (later tasks rely on these exact names):
  - `App\Settings\GeneralSettings` — group `general`; `public bool $maintenance_mode` (default false); `public ?string $maintenance_message` (default null).
  - `App\Settings\TierThresholdSettings` — group `tier_threshold`; public int properties `free_locations` (1), `plus_locations` (5), `free_no_cost_channels` (1), `free_traccar_servers` (0), `plus_traccar_servers` (1), `pro_traccar_servers` (3), `free_traccar_devices_per_server` (0), `plus_traccar_devices_per_server` (5), `pro_traccar_devices_per_server` (10), `lightning_min_radius_miles` (10).
  - Both are resolved with `app(ClassName::class)`; a value is changed with `$settings->prop = x; $settings->save();` and re-read with `->refresh()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AppSettingsTest.php`:

```php
<?php

use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;

test('general settings default to maintenance off with no custom message', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->maintenance_mode)->toBeFalse()
        ->and($settings->maintenance_message)->toBeNull();
});

test('tier and threshold settings default to the limits the app shipped with', function () {
    $settings = app(TierThresholdSettings::class);

    expect($settings->free_locations)->toBe(1)
        ->and($settings->plus_locations)->toBe(5)
        ->and($settings->free_no_cost_channels)->toBe(1)
        ->and($settings->free_traccar_servers)->toBe(0)
        ->and($settings->plus_traccar_servers)->toBe(1)
        ->and($settings->pro_traccar_servers)->toBe(3)
        ->and($settings->free_traccar_devices_per_server)->toBe(0)
        ->and($settings->plus_traccar_devices_per_server)->toBe(5)
        ->and($settings->pro_traccar_devices_per_server)->toBe(10)
        ->and($settings->lightning_min_radius_miles)->toBe(10);
});

test('a saved setting is read back', function () {
    $settings = app(TierThresholdSettings::class);
    $settings->plus_locations = 8;
    $settings->save();

    expect(app(TierThresholdSettings::class)->refresh()->plus_locations)->toBe(8);
});

test('settings values are cached', function () {
    expect(config('settings.cache.enabled'))->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AppSettingsTest.php --compact"`
Expected: FAIL (classes do not exist).

- [ ] **Step 3: Publish and edit the package config**

Run inside the container: `php artisan vendor:publish --provider="Spatie\LaravelSettings\LaravelSettingsServiceProvider" --tag=settings --no-interaction` (this writes `config/settings.php`). Then in `config/settings.php` change the cache block's `enabled` to `(bool) env('SETTINGS_CACHE_ENABLED', true)`. Leave every other key at the published default (`auto_discover_settings` already scans `app/Settings`, `migrations_paths` already includes `database/settings`).

Check that the existing cookie-consent settings (`database/settings/2026_01_01_000000_create_cookie_consent_settings.php`, used by `jeffersongoncalves/filament-cookie-consent`) still resolve after publishing (Step 8's full-suite run covers it; if a cookie-consent test fails because the published config dropped a settings class that package registers, report it — do not paper over it).

- [ ] **Step 4: Write the settings classes**

Create `app/Settings/GeneralSettings.php`:

```php
<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public bool $maintenance_mode;

    public ?string $maintenance_message;

    public static function group(): string
    {
        return 'general';
    }
}
```

Create `app/Settings/TierThresholdSettings.php`:

```php
<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class TierThresholdSettings extends Settings
{
    public int $free_locations;

    public int $plus_locations;

    public int $free_no_cost_channels;

    public int $free_traccar_servers;

    public int $plus_traccar_servers;

    public int $pro_traccar_servers;

    public int $free_traccar_devices_per_server;

    public int $plus_traccar_devices_per_server;

    public int $pro_traccar_devices_per_server;

    public int $lightning_min_radius_miles;

    public static function group(): string
    {
        return 'tier_threshold';
    }
}
```

- [ ] **Step 5: Write the settings migrations**

Create `database/settings/2026_09_20_300001_create_general_settings.php`:

```php
<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('maintenance_mode', false);
            $blueprint->add('maintenance_message', null);
        });
    }
};
```

Create `database/settings/2026_09_20_300002_create_tier_threshold_settings.php`:

```php
<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('tier_threshold', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('free_locations', 1);
            $blueprint->add('plus_locations', 5);
            $blueprint->add('free_no_cost_channels', 1);
            $blueprint->add('free_traccar_servers', 0);
            $blueprint->add('plus_traccar_servers', 1);
            $blueprint->add('pro_traccar_servers', 3);
            $blueprint->add('free_traccar_devices_per_server', 0);
            $blueprint->add('plus_traccar_devices_per_server', 5);
            $blueprint->add('pro_traccar_devices_per_server', 10);
            $blueprint->add('lightning_min_radius_miles', 10);
        });
    }
};
```

- [ ] **Step 6: Run the new test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AppSettingsTest.php --compact"`
Expected: PASS (4 tests). If the settings migrations do not run under `RefreshDatabase`, find out why (the existing cookie-consent settings migration is the working precedent) and fix the wiring, not the test.

- [ ] **Step 7: Run the full suite**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"`
Expected: all pass (report the total).

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/settings.php app/Settings database/settings/2026_09_20_300001_create_general_settings.php database/settings/2026_09_20_300002_create_tier_threshold_settings.php tests/Feature/AppSettingsTest.php
git commit -m "$(cat <<'EOF'
Add the settings foundation with General and Tier & threshold groups

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Wire the tier limits and the Lightning floor to the settings

**Files:**
- Modify: `app/Models/User.php` (`locationLimit`, `traccarServerLimit`, `traccarDeviceLimitPerServer`, `canAddChannel`)
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` (the `radius_miles` field in `lightningFields()`)
- Modify: `tests/Feature/AlertRuleResourceTest.php` (the two existing radius tests at about lines 199-227, see Step 5)
- Test: `tests/Feature/TierThresholdWiringTest.php`

**Interfaces:**
- Consumes: `App\Settings\TierThresholdSettings` (Task 1) and its property names.
- Produces: no new public API; `User::locationLimit(): ?int`, `traccarServerLimit(): ?int`, `traccarDeviceLimitPerServer(): ?int` and `canAddChannel(string): bool` keep their signatures and now read the settings. Pro locations and Pro channels stay unlimited (`null` / unrestricted).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TierThresholdWiringTest.php`:

```php
<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\TierThresholdSettings;

use function Pest\Livewire\livewire;

function setTierSetting(string $property, int $value): void
{
    $settings = app(TierThresholdSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

test('the location limits follow the settings and Pro stays unlimited', function () {
    setTierSetting('free_locations', 2);
    setTierSetting('plus_locations', 9);

    expect(User::factory()->create(['tier_override' => Tier::Free])->locationLimit())->toBe(2)
        ->and(User::factory()->create(['tier_override' => Tier::Plus])->locationLimit())->toBe(9)
        ->and(User::factory()->create(['tier_override' => Tier::Pro])->locationLimit())->toBeNull();
});

test('a Free user can create locations up to the configured limit', function () {
    setTierSetting('free_locations', 2);
    $user = User::factory()->create(['tier_override' => Tier::Free]);
    Location::factory()->for($user)->create();

    expect($user->canCreateLocation())->toBeTrue();

    Location::factory()->for($user)->create();

    expect($user->canCreateLocation())->toBeFalse();
});

test('the Traccar server and device limits follow the settings', function () {
    setTierSetting('free_traccar_servers', 1);
    setTierSetting('plus_traccar_servers', 2);
    setTierSetting('pro_traccar_servers', 4);
    setTierSetting('free_traccar_devices_per_server', 1);
    setTierSetting('plus_traccar_devices_per_server', 6);
    setTierSetting('pro_traccar_devices_per_server', 12);

    $free = User::factory()->create(['tier_override' => Tier::Free]);
    $plus = User::factory()->create(['tier_override' => Tier::Plus]);
    $pro = User::factory()->create(['tier_override' => Tier::Pro]);

    expect([$free->traccarServerLimit(), $plus->traccarServerLimit(), $pro->traccarServerLimit()])->toBe([1, 2, 4])
        ->and([$free->traccarDeviceLimitPerServer(), $plus->traccarDeviceLimitPerServer(), $pro->traccarDeviceLimitPerServer()])->toBe([1, 6, 12]);
});

test('the Free no-cost channel slot count follows the setting', function () {
    setTierSetting('free_no_cost_channels', 2);
    $user = User::factory()->create(['tier_override' => Tier::Free]);
    NotificationChannel::factory()->for($user)->create(['channel_type' => 'discord']);

    expect($user->fresh()->canAddChannel('slack'))->toBeTrue();

    NotificationChannel::factory()->for($user)->create(['channel_type' => 'slack']);

    expect($user->fresh()->canAddChannel('telegram'))->toBeFalse();
});

test('a Free channel slot of zero blocks every no-cost channel, while email stays unlimited', function () {
    setTierSetting('free_no_cost_channels', 0);
    $user = User::factory()->create(['tier_override' => Tier::Free]);

    expect($user->canAddChannel('discord'))->toBeFalse()
        ->and($user->canAddChannel('email'))->toBeTrue();
});

test('the lightning radius floor follows the setting on create', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $location = Location::factory()->for($user)->create();
    $channel = NotificationChannel::factory()->for($user)->create();

    setTierSetting('lightning_min_radius_miles', 12);

    // Copy the exact fill pattern of the existing 'creating a lightning alert rejects a radius
    // below the enforced minimum' test in tests/Feature/AlertRuleResourceTest.php (same category,
    // location and channel fields); only the radius values differ.
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning',
            'location_id' => $location->id,
            'radius_miles' => 11,
            'channel_ids' => [$channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['radius_miles' => 'min']);

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning',
            'location_id' => $location->id,
            'radius_miles' => 12,
            'channel_ids' => [$channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});
```

If the lightning test's fill array does not match how `AlertRuleResourceTest.php` builds a lightning rule (field names, wizard steps, `actingAs` setup), adapt it to that file's exact pattern; do not guess.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/TierThresholdWiringTest.php --compact"`
Expected: FAIL (limits are still hardcoded).

- [ ] **Step 3: Wire `User`**

In `app/Models/User.php` add `use App\Settings\TierThresholdSettings;` and replace these methods:

```php
    public function locationLimit(): ?int
    {
        $limits = app(TierThresholdSettings::class);

        return match ($this->tier()) {
            Tier::Free => $limits->free_locations,
            Tier::Plus => $limits->plus_locations,
            Tier::Pro => null,
        };
    }
```

```php
    public function traccarServerLimit(): ?int
    {
        $limits = app(TierThresholdSettings::class);

        return match ($this->tier()) {
            Tier::Free => $limits->free_traccar_servers,
            Tier::Plus => $limits->plus_traccar_servers,
            Tier::Pro => $limits->pro_traccar_servers,
        };
    }
```

```php
    public function traccarDeviceLimitPerServer(): ?int
    {
        $limits = app(TierThresholdSettings::class);

        return match ($this->tier()) {
            Tier::Free => $limits->free_traccar_devices_per_server,
            Tier::Plus => $limits->plus_traccar_devices_per_server,
            Tier::Pro => $limits->pro_traccar_devices_per_server,
        };
    }
```

In `canAddChannel`, replace the final `return ! $this->notificationChannels->contains(...)` with:

```php
        $used = $this->notificationChannels
            ->filter(fn (NotificationChannel $channel) => $registry->find($channel->channel_type)?->countsTowardFreeSlot() ?? false)
            ->count();

        return $used < app(TierThresholdSettings::class)->free_no_cost_channels;
```

Keep the existing doc comments' intent accurate (the docblocks that say "currently always a concrete number" stay true).

- [ ] **Step 4: Wire the Lightning form**

In `AlertRuleForm::lightningFields()` replace the `radius_miles` field with:

```php
            TextInput::make('radius_miles')
                ->label('Alert radius (miles)')
                ->numeric()
                ->minValue(fn (): int => app(TierThresholdSettings::class)->lightning_min_radius_miles)
                ->maxValue(50)
                ->default(15)
                ->required()
                ->helperText(fn (): string => 'Minimum '.app(TierThresholdSettings::class)->lightning_min_radius_miles.' miles — tighter radii outrun what satellite lightning detection can reliably pinpoint.'),
```

and add `use App\Settings\TierThresholdSettings;` to the file's imports.

- [ ] **Step 5: Update the two existing tests that assert the old floor of 5**

In `tests/Feature/AlertRuleResourceTest.php` the tests `creating a lightning alert rejects a radius below the enforced minimum` (radius 2) and `creating a lightning alert at the minimum radius succeeds` (radius 5, asserts `radius_miles` 5) encode the old hardcoded minimum. This is an approved behavior change (new default 10): change the "at the minimum" test to use radius 10 and assert 10; keep the "below the minimum" test's assertion (2 is still below). Do not delete either test; update titles/values only. If any other test asserts the "Minimum 5 miles" helper text, update it to "Minimum 10 miles".

- [ ] **Step 6: Run the tests to verify they pass, then the wiring-adjacent suites**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/TierThresholdWiringTest.php tests/Feature/UserTraccarLimitsTest.php tests/Feature/AlertRuleResourceTest.php tests/Feature/LocationResourceTest.php tests/Feature/ChannelConnectionTest.php tests/Feature/ChannelTypesTest.php --compact"`
Expected: PASS.

- [ ] **Step 7: Run the full suite**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"`
Expected: all pass (report the total).

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/User.php app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php tests/Feature/TierThresholdWiringTest.php tests/Feature/AlertRuleResourceTest.php
git commit -m "$(cat <<'EOF'
Read tier limits and the Lightning radius floor from settings

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: The App settings admin page

**Files:**
- Create: `app/Filament/Admin/Pages/AppSettings.php`
- Create: `resources/views/filament/admin/pages/app-settings.blade.php`
- Test: `tests/Feature/AppSettingsPageTest.php`

**Interfaces:**
- Consumes: `GeneralSettings`, `TierThresholdSettings` (Task 1).
- Produces: `App\Filament\Admin\Pages\AppSettings` — a Filament page in the `admin` panel, `System` navigation group, staff only. Form state is FLAT (field names equal the settings property names); `save()` writes each settings class from the keys it owns.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AppSettingsPageTest.php`:

```php
<?php

use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('the page loads the current values of both settings groups', function () {
    livewire(AppSettings::class)
        ->assertFormSet([
            'maintenance_mode' => false,
            'plus_locations' => 5,
            'lightning_min_radius_miles' => 10,
        ]);
});

test('saving writes both groups', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'maintenance_mode' => true,
            'maintenance_message' => 'Back at 10:00 UTC.',
            'plus_locations' => 8,
            'lightning_min_radius_miles' => 12,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(app(GeneralSettings::class)->refresh()->maintenance_mode)->toBeTrue()
        ->and(app(GeneralSettings::class)->maintenance_message)->toBe('Back at 10:00 UTC.')
        ->and(app(TierThresholdSettings::class)->refresh()->plus_locations)->toBe(8)
        ->and(app(TierThresholdSettings::class)->lightning_min_radius_miles)->toBe(12);
});

test('out-of-range and non-integer values are rejected and nothing is saved', function (string $field, mixed $value) {
    livewire(AppSettings::class)
        ->fillForm([$field => $value])
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(app(TierThresholdSettings::class)->refresh()->{$field})->not->toBe($value);
})->with([
    'lightning floor below range' => ['lightning_min_radius_miles', 4],
    'lightning floor above range' => ['lightning_min_radius_miles', 16],
    'free locations below one' => ['free_locations', 0],
    'plus locations above cap' => ['plus_locations', 101],
    'negative channel slots' => ['free_no_cost_channels', -1],
    'device limit above cap' => ['pro_traccar_devices_per_server', 101],
    'fractional value' => ['plus_traccar_servers', 1.5],
]);

test('a higher tier cannot be set below a lower tier', function () {
    livewire(AppSettings::class)
        ->fillForm(['free_locations' => 6, 'plus_locations' => 5])
        ->call('save')
        ->assertHasFormErrors(['plus_locations']);

    livewire(AppSettings::class)
        ->fillForm(['free_traccar_servers' => 2, 'plus_traccar_servers' => 1])
        ->call('save')
        ->assertHasFormErrors(['plus_traccar_servers']);

    livewire(AppSettings::class)
        ->fillForm(['plus_traccar_devices_per_server' => 4, 'pro_traccar_devices_per_server' => 3])
        ->call('save')
        ->assertHasFormErrors(['pro_traccar_devices_per_server']);
});

test('only staff can open the page', function () {
    $this->actingAs(User::factory()->create(['is_staff' => false]));

    expect(AppSettings::canAccess())->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AppSettingsPageTest.php --compact"`
Expected: FAIL (page class does not exist).

- [ ] **Step 3: Write the page**

Create `app/Filament/Admin/Pages/AppSettings.php` (follow `app/Filament/Admin/Pages/ResetToFactoryDefaults.php` for the Page + HasForms conventions; this page is a plain `Filament\Pages\Page`, not the plugin's `SettingsPage`, because it owns two settings classes):

```php
<?php

namespace App\Filament\Admin\Pages;

use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use UnitEnum;

class AppSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.admin.pages.app-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'App settings';

    protected static ?string $title = 'App settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->is_staff === true;
    }

    public function mount(): void
    {
        $this->form->fill([
            ...app(GeneralSettings::class)->toArray(),
            ...app(TierThresholdSettings::class)->toArray(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('Settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('General')->schema([
                            Section::make('Maintenance mode')
                                ->description('Puts the subscriber dashboard into a read-only state with a banner. Ingestion and alert delivery keep running.')
                                ->schema([
                                    Toggle::make('maintenance_mode')->label('Maintenance mode'),
                                    Textarea::make('maintenance_message')
                                        ->label('Banner message')
                                        ->rows(2)
                                        ->maxLength(500)
                                        ->helperText('Optional. Leave empty for the default message.'),
                                ]),
                        ]),
                        Tab::make('Tier & threshold defaults')->schema([
                            Section::make('Locations')->columns(2)->schema([
                                $this->count('free_locations', 'Free locations', 1, 10),
                                $this->count('plus_locations', 'Plus locations', 1, 100)->gte('free_locations'),
                            ])->description('Pro locations are unlimited.'),
                            Section::make('Notification channels')->schema([
                                $this->count('free_no_cost_channels', 'Free no-cost channels', 0, 5),
                            ])->description('Email is unlimited at every tier; Plus and Pro have no channel limit.'),
                            Section::make('Traccar servers')->columns(3)->schema([
                                $this->count('free_traccar_servers', 'Free', 0, 20),
                                $this->count('plus_traccar_servers', 'Plus', 0, 20)->gte('free_traccar_servers'),
                                $this->count('pro_traccar_servers', 'Pro', 0, 20)->gte('plus_traccar_servers'),
                            ]),
                            Section::make('Traccar devices per server')->columns(3)->schema([
                                $this->count('free_traccar_devices_per_server', 'Free', 0, 100),
                                $this->count('plus_traccar_devices_per_server', 'Plus', 0, 100)->gte('free_traccar_devices_per_server'),
                                $this->count('pro_traccar_devices_per_server', 'Pro', 0, 100)->gte('plus_traccar_devices_per_server'),
                            ]),
                            Section::make('Thresholds')->schema([
                                $this->count('lightning_min_radius_miles', 'Lightning alert minimum radius (miles)', 5, 15)
                                    ->helperText('Applies when a Lightning rule is created or edited; existing rules keep their stored radius.'),
                            ]),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ([GeneralSettings::class, TierThresholdSettings::class] as $class) {
            $settings = app($class);
            $settings->fill(Arr::only($data, array_keys($settings->toArray())));
            $settings->save();
        }

        Notification::make()->success()->title('Settings saved')->send();
    }

    private function count(string $name, string $label, int $min, int $max): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->required();
    }
}
```

Note: `maintenance_message` empty must be stored as `null`. Filament may submit `''`; in `save()`, after `getState()`, set `$data['maintenance_message'] = filled($data['maintenance_message'] ?? null) ? $data['maintenance_message'] : null;` before filling (add a test line for it in Step 1's "saving writes both groups" if not already implied).

Create `resources/views/filament/admin/pages/app-settings.blade.php`:

```blade
<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit">Save settings</x-filament::button>
    </form>
</x-filament-panels::page>
```

The `->gte('other_field')` calls are the tier-order guardrail. If Filament's `gte()` does not resolve the sibling field's state path on this page (state path is `data.*`), fix the reference (e.g. pass the full `data.free_locations` path) — the "a higher tier cannot be set below a lower tier" test is the proof.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/AppSettingsPageTest.php --compact"`
Expected: PASS. Also add and pass one assertion that clearing the message saves `null`.

- [ ] **Step 5: Confirm the page is registered**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan route:list --path=admin --name=app-settings"`
Expected: one route for the App settings page.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Admin/Pages/AppSettings.php resources/views/filament/admin/pages/app-settings.blade.php tests/Feature/AppSettingsPageTest.php
git commit -m "$(cat <<'EOF'
Add the App settings admin page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Audit-log hook for settings changes

**Files:**
- Create: `app/Listeners/LogSettingsChange.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the listener in `boot()`)
- Test: `tests/Feature/SettingsAuditLogTest.php`

**Interfaces:**
- Consumes: `Spatie\LaravelSettings\Events\SavingSettings` (has `->settings`, `->properties` = collection of property name => new value, `->originalValues` = collection of the values loaded, or null); the existing `CauserResolver` override in `AppServiceProvider::boot()` (attributes to the impersonator when impersonating).
- Produces: an `Activity` row per changed settings save: `log_name` `settings`, description `Updated {group} settings`, properties `['group' => string, 'changes' => [property => ['old' => mixed, 'new' => mixed]]]`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SettingsAuditLogTest.php`:

```php
<?php

use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use Spatie\Activitylog\Models\Activity;

test('changing a setting logs the old and new value against the acting user', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff);

    $settings = app(TierThresholdSettings::class);
    $settings->plus_locations = 8;
    $settings->save();

    $activity = Activity::query()->where('log_name', 'settings')->sole();

    expect($activity->description)->toBe('Updated tier_threshold settings')
        ->and($activity->causer_id)->toBe($staff->id)
        ->and($activity->properties['group'])->toBe('tier_threshold')
        ->and($activity->properties['changes'])->toBe(['plus_locations' => ['old' => 5, 'new' => 8]]);
});

test('saving without changing anything logs nothing', function () {
    $settings = app(GeneralSettings::class);
    $settings->save();

    expect(Activity::query()->where('log_name', 'settings')->count())->toBe(0);
});

test('only the changed properties are logged', function () {
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = true;
    $settings->save();

    $changes = Activity::query()->where('log_name', 'settings')->sole()->properties['changes'];

    expect(array_keys($changes))->toBe(['maintenance_mode'])
        ->and($changes['maintenance_mode'])->toBe(['old' => false, 'new' => true]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SettingsAuditLogTest.php --compact"`
Expected: FAIL (nothing is logged).

- [ ] **Step 3: Write the listener**

Create `app/Listeners/LogSettingsChange.php`:

```php
<?php

namespace App\Listeners;

use Spatie\LaravelSettings\Events\SavingSettings;

class LogSettingsChange
{
    public function handle(SavingSettings $event): void
    {
        $group = $event->settings::group();
        $changes = [];

        foreach ($event->properties as $name => $new) {
            $old = $event->originalValues?->get($name);

            if ($old !== $new) {
                $changes[$name] = ['old' => $old, 'new' => $new];
            }
        }

        if ($changes === []) {
            return;
        }

        activity('settings')
            ->withProperties(['group' => $group, 'changes' => $changes])
            ->log("Updated {$group} settings");
    }
}
```

Register it in `AppServiceProvider::boot()` next to the other `Event::` registrations:

```php
        Event::listen(SavingSettings::class, LogSettingsChange::class);
```

with `use App\Listeners\LogSettingsChange;` and `use Spatie\LaravelSettings\Events\SavingSettings;`.

Design note for the report: this uses `SavingSettings` because it carries both the old and new values; `SettingsSaved` only carries the new object. The event fires just before the write, so a write that then fails would leave a log row; that is acceptable for an audit trail.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SettingsAuditLogTest.php tests/Feature/AppSettingsPageTest.php tests/Feature/AppSettingsTest.php --compact"`
Expected: PASS. Confirm the App settings page's own save (Task 3) now also produces a `settings` activity by adding one assertion to `SettingsAuditLogTest.php` that saves through `livewire(AppSettings::class)` and finds the row.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Listeners/LogSettingsChange.php app/Providers/AppServiceProvider.php tests/Feature/SettingsAuditLogTest.php
git commit -m "$(cat <<'EOF'
Log settings changes to the audit log

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Maintenance mode — banner and write guard

**Files:**
- Create: `app/Support/MaintenanceModeGuard.php`
- Create: `app/Exceptions/MaintenanceModeException.php`
- Create: `app/Models/Concerns/BlockedDuringMaintenance.php`
- Modify: `app/Models/Location.php`, `app/Models/AlertRule.php`, `app/Models/NotificationChannel.php`, `app/Models/AlertTemplate.php`, `app/Models/TraccarServer.php` (add the trait)
- Modify: `app/Providers/Filament/DashboardPanelProvider.php` (banner render hook)
- Create: `resources/views/filament/dashboard/maintenance-banner.blade.php`
- Test: `tests/Feature/MaintenanceModeTest.php`

**Interfaces:**
- Consumes: `GeneralSettings` (Task 1); `STS\FilamentImpersonate\Services\ImpersonateManager::isImpersonating(): bool` (already used in `AppServiceProvider`); `User::$is_staff`.
- Produces: `App\Support\MaintenanceModeGuard::isBlocking(): bool` and `assertWritable(): void` (throws `App\Exceptions\MaintenanceModeException`); trait `App\Models\Concerns\BlockedDuringMaintenance`; the exception renders a readable 503 response.

Blocking rule (exactly this): maintenance on AND `auth()->user()` exists AND that user is not staff AND the request is not an impersonation session. Anything else — including no signed-in user (queue jobs, console commands, the token-authenticated pipeline webhooks, the Traccar poller) — is allowed.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/MaintenanceModeTest.php`:

```php
<?php

use App\Exceptions\MaintenanceModeException;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use STS\FilamentImpersonate\Services\ImpersonateManager;

function setMaintenance(bool $on, ?string $message = null): void
{
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = $on;
    $settings->maintenance_message = $message;
    $settings->save();
}

beforeEach(function () {
    $this->withoutVite();
    $this->subscriber = User::factory()->create();
    $this->location = Location::factory()->for($this->subscriber)->create();
});

test('a signed-in subscriber cannot create, update or delete their data during maintenance', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);

    expect(fn () => Location::factory()->for($this->subscriber)->create())->toThrow(MaintenanceModeException::class)
        ->and(fn () => $this->location->update(['name' => 'Renamed']))->toThrow(MaintenanceModeException::class)
        ->and(fn () => $this->location->delete())->toThrow(MaintenanceModeException::class);
});

test('every guarded model type is protected', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);

    expect(fn () => NotificationChannel::factory()->for($this->subscriber)->create())->toThrow(MaintenanceModeException::class);
    // Also assert AlertRule, AlertTemplate and TraccarServer creation throws: mirror the line above with
    // each model's factory (->for($this->subscriber)).
});

test('the same writes succeed when maintenance is off', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(false);

    $this->location->update(['name' => 'Renamed']);

    expect($this->location->fresh()->name)->toBe('Renamed');
});

test('reads still work during maintenance', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);

    expect(Location::query()->count())->toBe(1);
});

test('staff can still write during maintenance', function () {
    $this->actingAs(User::factory()->create(['is_staff' => true]));
    setMaintenance(true);

    $this->location->update(['name' => 'Staff edit']);

    expect($this->location->fresh()->name)->toBe('Staff edit');
});

test('an impersonation session can still write during maintenance', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));

    $this->location->update(['name' => 'Support edit']);

    expect($this->location->fresh()->name)->toBe('Support edit');
});

test('writes with no signed-in user (queue, console, token webhooks) are never blocked', function () {
    setMaintenance(true);
    expect(auth()->check())->toBeFalse();

    $this->location->update(['name' => 'System edit']);

    expect($this->location->fresh()->name)->toBe('System edit');
});

test('the exception renders a readable 503 with the configured message', function () {
    setMaintenance(true, 'Back at 10:00 UTC.');
    $this->actingAs($this->subscriber);

    try {
        $this->location->update(['name' => 'x']);
    } catch (MaintenanceModeException $e) {
        $response = $e->render(Request::create('/dashboard/locations/1/edit'));
    }

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toContain('Back at 10:00 UTC.');
});

test('the dashboard shows the maintenance banner only while maintenance is on', function () {
    $this->actingAs($this->subscriber);

    setMaintenance(true, 'Planned deploy.');
    $this->get('/dashboard')->assertOk()->assertSee('Maintenance in progress')->assertSee('Planned deploy.');

    setMaintenance(false);
    $this->get('/dashboard')->assertOk()->assertDontSee('Maintenance in progress');
});

test('the default banner message is used when none is set', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);

    $this->get('/dashboard')->assertSee('read-only')->assertSee('Alerts continue to be delivered as normal.');
});
```

If `$this->get('/dashboard')` needs a verified email or other setup to return 200 for a subscriber, follow how existing dashboard page tests reach the panel (e.g. `tests/Feature/DashboardPanelTest.php` or similar) rather than guessing.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MaintenanceModeTest.php --compact"`
Expected: FAIL.

- [ ] **Step 3: Write the guard, exception and trait**

Create `app/Support/MaintenanceModeGuard.php`:

```php
<?php

namespace App\Support;

use App\Exceptions\MaintenanceModeException;
use App\Settings\GeneralSettings;
use STS\FilamentImpersonate\Services\ImpersonateManager;

class MaintenanceModeGuard
{
    public const DEFAULT_MESSAGE = 'The dashboard is temporarily read-only while we perform maintenance. Alerts continue to be delivered as normal.';

    public function message(): string
    {
        return filled($message = app(GeneralSettings::class)->maintenance_message) ? $message : self::DEFAULT_MESSAGE;
    }

    public function isBlocking(): bool
    {
        if (! app(GeneralSettings::class)->maintenance_mode) {
            return false;
        }

        $user = auth()->user();

        return $user !== null
            && ! $user->is_staff
            && ! app(ImpersonateManager::class)->isImpersonating();
    }

    public function assertWritable(): void
    {
        if ($this->isBlocking()) {
            throw new MaintenanceModeException($this->message());
        }
    }
}
```

Create `app/Exceptions/MaintenanceModeException.php`:

```php
<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class MaintenanceModeException extends RuntimeException
{
    public function render(Request $request): Response
    {
        return response($this->getMessage(), 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
```

Create `app/Models/Concerns/BlockedDuringMaintenance.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Support\MaintenanceModeGuard;

trait BlockedDuringMaintenance
{
    protected static function bootBlockedDuringMaintenance(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(fn () => app(MaintenanceModeGuard::class)->assertWritable());
        }
    }
}
```

Add `use BlockedDuringMaintenance;` (and the import) to `Location`, `AlertRule`, `NotificationChannel`, `AlertTemplate` and `TraccarServer`.

Report in your summary how a `MaintenanceModeException` thrown inside a Livewire/Filament action is displayed: Livewire shows non-2xx responses in an error modal, so the 503 text body is what the subscriber sees. Check that behavior in a browser if you can (signed in as a non-staff subscriber with maintenance on, try to save a location) and say what you saw; if it is unusably ugly, propose the smallest fix (e.g. catching the exception in the panel's exception handling to send a Filament notification) but do not build it without reporting.

- [ ] **Step 4: Add the banner**

Create `resources/views/filament/dashboard/maintenance-banner.blade.php`:

```blade
<x-filament::callout
    color="warning"
    icon="heroicon-o-wrench-screwdriver"
    heading="Maintenance in progress"
    :description="$message"
    class="mb-6"
/>
```

In `DashboardPanelProvider::panel()`, add a render hook alongside the existing ones (use `PanelsRenderHook::CONTENT_START`; confirm the constant exists in this Filament version, otherwise use the nearest content-start hook):

```php
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => app(MaintenanceModeGuard::class)->isMaintenanceActive()
                    ? view('filament.dashboard.maintenance-banner', ['message' => app(MaintenanceModeGuard::class)->message()])->render()
                    : '',
            )
```

The banner shows to every dashboard viewer while maintenance is on (including staff, so they know the state), not only to blocked users, so add to `MaintenanceModeGuard`:

```php
    public function isMaintenanceActive(): bool
    {
        return app(GeneralSettings::class)->maintenance_mode;
    }
```

(and have `isBlocking()` call it). Add the `MaintenanceModeGuard` import to the provider.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MaintenanceModeTest.php --compact"`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"`
Expected: all pass (the trait is inert while maintenance is off, so no other test should change; report the total).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/MaintenanceModeGuard.php app/Exceptions/MaintenanceModeException.php app/Models app/Providers/Filament/DashboardPanelProvider.php resources/views/filament/dashboard/maintenance-banner.blade.php tests/Feature/MaintenanceModeTest.php
git commit -m "$(cat <<'EOF'
Add maintenance mode with a read-only write guard and banner

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec coverage**
- Foundation (packages, cache, typed classes, settings migrations) → Task 1. Both packages are already installed; the plan says so and forbids `composer require`.
- Admin page, System group, tab per class, staff only → Task 3.
- `GeneralSettings` (maintenance mode + message) and `TierThresholdSettings` with the exact defaults → Task 1; bounds and the tier-order guardrails → Task 3.
- Wiring `User` limits, Free channel count, Lightning form floor, server-side enforcement, existing rules untouched → Task 2.
- Maintenance banner and write guard with all exemptions (staff, impersonation, no signed-in user) and readable message → Task 5, with the Livewire display risk called out for a browser check.
- Audit log hook with old/new values and the impersonation-aware causer → Task 4.
- Testing list from the spec → present per task.
- Out of scope (integration toggles, feature flags, Secrets, Heads Up values, public API) → not built.

**Placeholder scan:** none. Two steps intentionally tell the implementer to mirror an existing test's fill pattern (the Lightning create test and the dashboard GET setup) because those depend on unseen test scaffolding; each says exactly which file to copy and forbids guessing.

**Type consistency:** `GeneralSettings::maintenance_mode/maintenance_message`, all `TierThresholdSettings` property names, `MaintenanceModeGuard::isBlocking()/isMaintenanceActive()/assertWritable()/message()`, `MaintenanceModeException`, `BlockedDuringMaintenance`, and `LogSettingsChange` are used with the same names across tasks.
