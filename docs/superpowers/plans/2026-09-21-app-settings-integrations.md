# App Settings: Integration Toggles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An "Integrations" tab on the App settings page giving staff an enabled switch per integration plus provider and non-secret endpoint where it has one, with email relaying wired to real runtime behavior and the other integrations exposed through a helper until their backends exist.

**Architecture:** One `IntegrationSettings` class (group `integrations`) plus an `Integration` enum and `Integrations` helper mirroring the existing `Feature`/`FeatureFlags` pair. The existing `AppSettings` page gains a tab and saves the new class. A small `ApplyEmailSettings` action, called from `AppServiceProvider::boot()`, overrides `mail.default` and `mail.from` from the settings, reading fail-safe so `.env` config wins whenever the settings cannot be read.

**Tech Stack:** Laravel 13, Filament v5, `spatie/laravel-settings` 3.9 (already installed and in use), Pest.

**Spec:** `docs/superpowers/specs/2026-09-21-app-settings-integrations-design.md`

## Global Constraints

- No new Composer dependencies; do not run `composer require`/`update`.
- Leave `CLAUDE.md` unchanged (`git checkout CLAUDE.md` before committing if a hook modified it).
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:seed`. Do NOT run `php artisan migrate` on the dev database. Tests use `RefreshDatabase`.
- Run tests only inside the Sail container: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest <path> --compact"`. The full suite takes about two minutes and is moved to the background by the tool; wait for it. Run `vendor/bin/pint --dirty --format agent` on the host before each commit. Commit messages end with `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Do not push.
- Before editing, read `.ai/rules/index.md` and the rule files matching the paths you touch (`.ai/rules/filament.md` for `app/Filament/**`, `.ai/rules/feature.md` for `tests/Feature/**`). Style: curly braces always, explicit return types, constructor promotion.
- Integrations whose backend does not exist yet default to OFF; email relaying defaults to ON. Null `email_mailer` / `email_from_address` / `email_from_name` means "keep the `.env` value" (the default), so nothing changes until an admin sets something.
- Credentials (API keys, tokens, DSNs) stay in `.env`; do not add any secret fields.
- The email boot-time read must be fail-safe: if the settings cannot be read (missing table or rows, fresh install, during `migrate`), use the `.env` configuration and throw nothing.
- Access stays staff-only (`is_staff`); audit logging is already handled by the existing `LogSettingsChange` listener, no new code needed for it.

---

### Task 1: IntegrationSettings, Integration enum and Integrations helper

**Files:**
- Create: `app/Settings/IntegrationSettings.php`
- Create: `database/settings/2026_09_21_300002_create_integration_settings.php`
- Create: `app/Enums/Integration.php`
- Create: `app/Support/Integrations.php`
- Test: `tests/Feature/IntegrationSettingsTest.php`

**Interfaces:**
- Produces (later tasks rely on these exact names):
  - `App\Settings\IntegrationSettings` (group `integrations`): `bool translation_enabled`, `?string translation_endpoint`, `bool short_urls_enabled`, `?string short_urls_endpoint`, `bool heartbeat_enabled`, `?string heartbeat_endpoint`, `bool exception_reporting_enabled`, `bool archival_enabled`, `?string archival_provider`, `?string archival_endpoint`, `?string archival_bucket`, `bool status_page_enabled`, `?string status_page_url`, `bool email_relay_enabled`, `?string email_mailer`, `?string email_from_address`, `?string email_from_name`.
  - `App\Enums\Integration` cases `Translation`, `ShortUrls`, `Heartbeat`, `ExceptionReporting`, `Archival`, `StatusPage`, `EmailRelay` with `enabledProperty(): string` and `endpointProperty(): ?string`.
  - `App\Support\Integrations::enabled(Integration): bool` and `::endpoint(Integration): ?string` (null when the integration has no endpoint property or the value is blank).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/IntegrationSettingsTest.php`:

```php
<?php

use App\Enums\Integration;
use App\Settings\IntegrationSettings;
use App\Support\Integrations;

function setIntegrationSetting(string $property, mixed $value): void
{
    $settings = app(IntegrationSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

test('integrations without a backend default to off and email relaying defaults to on', function () {
    $settings = app(IntegrationSettings::class);

    expect($settings->translation_enabled)->toBeFalse()
        ->and($settings->short_urls_enabled)->toBeFalse()
        ->and($settings->heartbeat_enabled)->toBeFalse()
        ->and($settings->exception_reporting_enabled)->toBeFalse()
        ->and($settings->archival_enabled)->toBeFalse()
        ->and($settings->status_page_enabled)->toBeFalse()
        ->and($settings->email_relay_enabled)->toBeTrue();
});

test('endpoints, provider and mail overrides default to null', function () {
    $settings = app(IntegrationSettings::class);

    expect($settings->translation_endpoint)->toBeNull()
        ->and($settings->short_urls_endpoint)->toBeNull()
        ->and($settings->heartbeat_endpoint)->toBeNull()
        ->and($settings->archival_provider)->toBeNull()
        ->and($settings->archival_endpoint)->toBeNull()
        ->and($settings->archival_bucket)->toBeNull()
        ->and($settings->status_page_url)->toBeNull()
        ->and($settings->email_mailer)->toBeNull()
        ->and($settings->email_from_address)->toBeNull()
        ->and($settings->email_from_name)->toBeNull();
});

test('enabled() follows the saved setting for every integration', function (Integration $integration) {
    expect(Integrations::enabled($integration))->toBe($integration === Integration::EmailRelay);

    setIntegrationSetting($integration->enabledProperty(), $integration !== Integration::EmailRelay);

    expect(Integrations::enabled($integration))->toBe($integration !== Integration::EmailRelay);
})->with(Integration::cases());

test('endpoint() returns the stored endpoint, and null when blank or not applicable', function () {
    expect(Integrations::endpoint(Integration::Translation))->toBeNull();

    setIntegrationSetting('translation_endpoint', 'https://translate.example.test');
    setIntegrationSetting('status_page_url', 'https://status.example.test');
    setIntegrationSetting('archival_endpoint', '');

    expect(Integrations::endpoint(Integration::Translation))->toBe('https://translate.example.test')
        ->and(Integrations::endpoint(Integration::StatusPage))->toBe('https://status.example.test')
        ->and(Integrations::endpoint(Integration::Archival))->toBeNull()
        ->and(Integrations::endpoint(Integration::ExceptionReporting))->toBeNull()
        ->and(Integrations::endpoint(Integration::EmailRelay))->toBeNull();
});

test('every integration maps to real properties on the settings class', function () {
    $properties = array_keys(app(IntegrationSettings::class)->toArray());

    foreach (Integration::cases() as $integration) {
        expect($properties)->toContain($integration->enabledProperty());

        if ($integration->endpointProperty() !== null) {
            expect($properties)->toContain($integration->endpointProperty());
        }
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IntegrationSettingsTest.php --compact"`
Expected: FAIL (classes do not exist).

- [ ] **Step 3: Write the settings class and migration**

Create `app/Settings/IntegrationSettings.php`:

```php
<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class IntegrationSettings extends Settings
{
    public bool $translation_enabled;

    public ?string $translation_endpoint;

    public bool $short_urls_enabled;

    public ?string $short_urls_endpoint;

    public bool $heartbeat_enabled;

    public ?string $heartbeat_endpoint;

    public bool $exception_reporting_enabled;

    public bool $archival_enabled;

    public ?string $archival_provider;

    public ?string $archival_endpoint;

    public ?string $archival_bucket;

    public bool $status_page_enabled;

    public ?string $status_page_url;

    public bool $email_relay_enabled;

    public ?string $email_mailer;

    public ?string $email_from_address;

    public ?string $email_from_name;

    public static function group(): string
    {
        return 'integrations';
    }
}
```

Create `database/settings/2026_09_21_300002_create_integration_settings.php`:

```php
<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('integrations', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('translation_enabled', false);
            $blueprint->add('translation_endpoint', null);
            $blueprint->add('short_urls_enabled', false);
            $blueprint->add('short_urls_endpoint', null);
            $blueprint->add('heartbeat_enabled', false);
            $blueprint->add('heartbeat_endpoint', null);
            $blueprint->add('exception_reporting_enabled', false);
            $blueprint->add('archival_enabled', false);
            $blueprint->add('archival_provider', null);
            $blueprint->add('archival_endpoint', null);
            $blueprint->add('archival_bucket', null);
            $blueprint->add('status_page_enabled', false);
            $blueprint->add('status_page_url', null);
            $blueprint->add('email_relay_enabled', true);
            $blueprint->add('email_mailer', null);
            $blueprint->add('email_from_address', null);
            $blueprint->add('email_from_name', null);
        });
    }
};
```

- [ ] **Step 4: Write the enum and the helper**

Create `app/Enums/Integration.php`:

```php
<?php

namespace App\Enums;

enum Integration: string
{
    case Translation = 'translation';
    case ShortUrls = 'short_urls';
    case Heartbeat = 'heartbeat';
    case ExceptionReporting = 'exception_reporting';
    case Archival = 'archival';
    case StatusPage = 'status_page';
    case EmailRelay = 'email_relay';

    /**
     * The boolean property on IntegrationSettings that switches this integration.
     */
    public function enabledProperty(): string
    {
        return "{$this->value}_enabled";
    }

    /**
     * The property holding this integration's endpoint or URL, if it has one.
     */
    public function endpointProperty(): ?string
    {
        return match ($this) {
            self::Translation => 'translation_endpoint',
            self::ShortUrls => 'short_urls_endpoint',
            self::Heartbeat => 'heartbeat_endpoint',
            self::Archival => 'archival_endpoint',
            self::StatusPage => 'status_page_url',
            self::ExceptionReporting, self::EmailRelay => null,
        };
    }
}
```

Create `app/Support/Integrations.php`:

```php
<?php

namespace App\Support;

use App\Enums\Integration;
use App\Settings\IntegrationSettings;

/**
 * Runtime switches and non-secret endpoints for third-party integrations.
 * Credentials stay in .env. Backends call this rather than reading the
 * settings class directly; most integrations have no backend yet.
 */
class Integrations
{
    public static function enabled(Integration $integration): bool
    {
        return (bool) app(IntegrationSettings::class)->{$integration->enabledProperty()};
    }

    public static function endpoint(Integration $integration): ?string
    {
        $property = $integration->endpointProperty();

        if ($property === null) {
            return null;
        }

        $value = app(IntegrationSettings::class)->{$property};

        return filled($value) ? $value : null;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IntegrationSettingsTest.php --compact"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Settings/IntegrationSettings.php database/settings/2026_09_21_300002_create_integration_settings.php app/Enums/Integration.php app/Support/Integrations.php tests/Feature/IntegrationSettingsTest.php
git commit -m "$(cat <<'EOF'
Add integration settings with an enabled/endpoint helper

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: The Integrations tab on the App settings page

**Files:**
- Modify: `app/Filament/Admin/Pages/AppSettings.php`
- Test: `tests/Feature/IntegrationsTabTest.php`

**Interfaces:**
- Consumes: `IntegrationSettings` and its property names (Task 1). Read `app/Filament/Admin/Pages/AppSettings.php` first: it already loads, saves and lists `GeneralSettings`, `TierThresholdSettings` and `FeatureFlagsSettings` with FLAT form state and a `save()` that converts an empty `maintenance_message` to null.
- Produces: a fourth tab "Integrations"; `save()` also fills/saves `IntegrationSettings`. Form field names equal the property names.

Behavior (exactly this):
- Each integration section has its `*_enabled` toggle (`->live()`); its other fields are `->visible(fn (Get $get): bool => (bool) $get('<toggle>'))` and required only while enabled.
- URL/endpoint fields (`translation_endpoint`, `short_urls_endpoint`, `heartbeat_endpoint`, `archival_endpoint`, `status_page_url`): `->maxLength(255)`, `->rules(['url:http,https'])`, so only http/https URLs pass.
- `archival_provider`: `Select` (native) with options `b2` "Backblaze B2", `s3` "Amazon S3", `other` "Other S3-compatible"; `archival_endpoint`, `archival_bucket` (`->maxLength(255)`), all required while archival is enabled.
- Email section: `email_relay_enabled` toggle (`->live()`) with a helper text warning that turning it off in production stops password resets and email verification; `email_mailer` native `Select`, nullable, placeholder "Use the .env setting", options built from `config('mail.mailers')` keys minus `array`, `log`, `failover`, `roundrobin`; `email_from_address` `->email()` nullable; `email_from_name` `->maxLength(255)` nullable. Mailer/from fields visible only while relaying is enabled. Helper text on the section: credentials for the chosen mailer stay in `.env`, and a long-lived `queue:work` needs a restart to pick up a change (`queue:listen`, as in this stack, does not).
- Values hidden by a toggle are KEPT: a hidden field is absent from `getState()`, and `save()` only fills keys that are present, so the stored value is untouched.
- In `save()`, empty strings for the nullable string properties (`translation_endpoint`, `short_urls_endpoint`, `heartbeat_endpoint`, `archival_provider`, `archival_endpoint`, `archival_bucket`, `status_page_url`, `email_mailer`, `email_from_address`, `email_from_name`, plus the existing `maintenance_message`) become `null`, but only when the key is present in the state.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/IntegrationsTabTest.php`:

```php
<?php

use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\IntegrationSettings;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('the page loads the integration defaults', function () {
    livewire(AppSettings::class)
        ->assertFormSet([
            'translation_enabled' => false,
            'short_urls_enabled' => false,
            'heartbeat_enabled' => false,
            'exception_reporting_enabled' => false,
            'archival_enabled' => false,
            'status_page_enabled' => false,
            'email_relay_enabled' => true,
        ]);
});

test('an endpoint field is hidden until its integration is enabled', function () {
    livewire(AppSettings::class)
        ->assertFormFieldHidden('translation_endpoint')
        ->fillForm(['translation_enabled' => true])
        ->assertFormFieldVisible('translation_endpoint');
});

test('saving an enabled integration stores its endpoint', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'translation_enabled' => true,
            'translation_endpoint' => 'https://translate.example.test',
            'status_page_enabled' => true,
            'status_page_url' => 'http://status.internal:3001',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->translation_enabled)->toBeTrue()
        ->and($settings->translation_endpoint)->toBe('https://translate.example.test')
        ->and($settings->status_page_enabled)->toBeTrue()
        ->and($settings->status_page_url)->toBe('http://status.internal:3001');
});

test('an enabled integration requires its endpoint', function (string $toggle, string $field) {
    livewire(AppSettings::class)
        ->fillForm([$toggle => true, $field => null])
        ->call('save')
        ->assertHasFormErrors([$field => 'required']);
})->with([
    'translation' => ['translation_enabled', 'translation_endpoint'],
    'short urls' => ['short_urls_enabled', 'short_urls_endpoint'],
    'heartbeat' => ['heartbeat_enabled', 'heartbeat_endpoint'],
    'status page' => ['status_page_enabled', 'status_page_url'],
    'archival endpoint' => ['archival_enabled', 'archival_endpoint'],
    'archival bucket' => ['archival_enabled', 'archival_bucket'],
    'archival provider' => ['archival_enabled', 'archival_provider'],
]);

test('only http and https URLs are accepted', function (string $value) {
    livewire(AppSettings::class)
        ->fillForm(['translation_enabled' => true, 'translation_endpoint' => $value])
        ->call('save')
        ->assertHasFormErrors(['translation_endpoint']);
})->with(['ftp://example.test', 'javascript:alert(1)', 'not a url', 'file:///etc/passwd']);

test('a disabled integration keeps its stored endpoint and is not required', function () {
    $settings = app(IntegrationSettings::class);
    $settings->translation_enabled = true;
    $settings->translation_endpoint = 'https://translate.example.test';
    $settings->save();

    livewire(AppSettings::class)
        ->fillForm(['translation_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->translation_enabled)->toBeFalse()
        ->and($settings->translation_endpoint)->toBe('https://translate.example.test');
});

test('archival stores its provider, endpoint and bucket', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'archival_enabled' => true,
            'archival_provider' => 'b2',
            'archival_endpoint' => 'https://s3.us-west-004.backblazeb2.com',
            'archival_bucket' => 'wxnotify-products',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->archival_provider)->toBe('b2')
        ->and($settings->archival_bucket)->toBe('wxnotify-products');
});

test('the email mailer choices come from the configured mailers and exclude the internal ones', function () {
    $options = collect(livewire(AppSettings::class)->instance()->getForm('form')->getComponent('email_mailer')?->getOptions() ?? []);

    expect($options->keys()->all())->toContain('smtp')
        ->not->toContain('array')->not->toContain('log')->not->toContain('failover')->not->toContain('roundrobin');
});

test('an unknown mailer and a bad from address are rejected', function () {
    livewire(AppSettings::class)
        ->fillForm(['email_mailer' => 'bogus'])
        ->call('save')
        ->assertHasFormErrors(['email_mailer']);

    livewire(AppSettings::class)
        ->fillForm(['email_from_address' => 'not-an-email'])
        ->call('save')
        ->assertHasFormErrors(['email_from_address']);
});

test('saving email overrides stores them and empty values become null', function () {
    livewire(AppSettings::class)
        ->fillForm(['email_mailer' => 'smtp', 'email_from_address' => 'alerts@example.test', 'email_from_name' => 'WxNotify Alerts'])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->email_mailer)->toBe('smtp')
        ->and($settings->email_from_address)->toBe('alerts@example.test')
        ->and($settings->email_from_name)->toBe('WxNotify Alerts');

    livewire(AppSettings::class)
        ->fillForm(['email_mailer' => null, 'email_from_address' => '', 'email_from_name' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->email_mailer)->toBeNull()
        ->and($settings->email_from_address)->toBeNull()
        ->and($settings->email_from_name)->toBeNull();
});

test('a saved integration change is written to the audit log', function () {
    livewire(AppSettings::class)
        ->fillForm(['exception_reporting_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Spatie\Activitylog\Models\Activity::query()
        ->where('log_name', 'settings')
        ->where('description', 'Updated integrations settings')
        ->sole();

    expect($activity->properties['changes'])->toBe(['exception_reporting_enabled' => ['old' => false, 'new' => true]]);
});
```

If `getForm('form')->getComponent('email_mailer')->getOptions()` is not how this Filament version exposes the options in a Livewire test, replace that one test with whatever asserts the same thing (the option keys include `smtp` and exclude `array`, `log`, `failover`, `roundrobin`), for example by reading the options off the page's schema through the same helper other tests in this repo use for Select options; do not delete the coverage.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IntegrationsTabTest.php --compact"`
Expected: FAIL (the fields do not exist).

- [ ] **Step 3: Extend the page**

In `app/Filament/Admin/Pages/AppSettings.php`:

1. Add imports: `use App\Settings\IntegrationSettings;`, `use Filament\Forms\Components\Select;`, `use Filament\Schemas\Components\Utilities\Get;`.
2. In `mount()`, add `...app(IntegrationSettings::class)->toArray(),` to the fill array.
3. In `form()`, add a fifth `Tab::make('Integrations')` after the Feature flags tab, built from a private method `integrationsTab(): Tab` that returns one `Section` per integration following the behavior list above. Use a private helper `endpointField(string $name, string $label, string $toggle): TextInput` (`TextInput::make($name)->label($label)->maxLength(255)->rules(['url:http,https'])->required(fn (Get $get): bool => (bool) $get($toggle))->visible(fn (Get $get): bool => (bool) $get($toggle))`). Each section's toggle is `Toggle::make('<x>_enabled')->label(...)->live()`. The mailer options: `collect(array_keys(config('mail.mailers')))->diff(['array', 'log', 'failover', 'roundrobin'])->mapWithKeys(fn (string $name): array => [$name => $name])->all()`; the Select is `->native()`, `->placeholder('Use the .env setting')`, nullable (no `required`), with `->in(array_keys($options))` so an unknown mailer is rejected.
4. In `save()`: add `IntegrationSettings::class` to the class list, and replace the `maintenance_message` line with a loop over a `private const NULLABLE_STRINGS = [...]` (the list in the behavior notes above) that sets `$data[$key] = filled($data[$key]) ? $data[$key] : null;` only when `array_key_exists($key, $data)`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IntegrationsTabTest.php tests/Feature/AppSettingsPageTest.php tests/Feature/FeatureFlagsTest.php tests/Feature/SettingsAuditLogTest.php --compact"`
Expected: PASS (the existing page tests must still pass, including the maintenance message clearing to null).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Admin/Pages/AppSettings.php tests/Feature/IntegrationsTabTest.php
git commit -m "$(cat <<'EOF'
Add the Integrations tab to the App settings page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Apply the email settings at boot

**Files:**
- Create: `app/Support/ApplyEmailSettings.php`
- Modify: `app/Providers/AppServiceProvider.php` (call it at the start of `boot()`)
- Test: `tests/Feature/ApplyEmailSettingsTest.php`

**Interfaces:**
- Consumes: `IntegrationSettings` (Task 1). Config keys: `mail.default` (the test environment sets `MAIL_MAILER=array`), `mail.mailers.*`, `mail.from.address`, `mail.from.name`.
- Produces: `App\Support\ApplyEmailSettings::__invoke(): void` — mutates config only; never throws.

Rules (exactly this):
- Any failure reading the settings (`Throwable`) → return without touching config.
- `email_relay_enabled` false → `mail.default` = `log`.
- Otherwise, if `email_mailer` is filled AND `config("mail.mailers.{$mailer}")` is an array → `mail.default` = it. An unknown/missing mailer is ignored.
- If `email_from_address` is filled → `mail.from.address`; if `email_from_name` is filled → `mail.from.name` (applied whether or not relaying is enabled).
- Null values change nothing.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ApplyEmailSettingsTest.php`:

```php
<?php

use App\Providers\AppServiceProvider;
use App\Settings\IntegrationSettings;
use App\Support\ApplyEmailSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function setEmailSettings(array $values): void
{
    $settings = app(IntegrationSettings::class);

    foreach ($values as $property => $value) {
        $settings->{$property} = $value;
    }

    $settings->save();
}

function removeIntegrationSettingsRows(): void
{
    DB::table('settings')->where('group', 'integrations')->delete();
    Cache::flush();
    app()->forgetInstance(IntegrationSettings::class);
}

test('the defaults leave the .env mail configuration untouched', function () {
    $default = config('mail.default');
    $from = config('mail.from');

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default)
        ->and(config('mail.from'))->toBe($from);
});

test('a configured mailer replaces the default mailer', function () {
    setEmailSettings(['email_mailer' => 'smtp']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe('smtp');
});

test('an unknown mailer is ignored', function () {
    $default = config('mail.default');
    setEmailSettings(['email_mailer' => 'bogus']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default);
});

test('turning relaying off falls back to the log mailer even when a mailer is set', function () {
    setEmailSettings(['email_relay_enabled' => false, 'email_mailer' => 'smtp']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe('log');
});

test('the from address and name are overridden independently', function () {
    $name = config('mail.from.name');
    setEmailSettings(['email_from_address' => 'alerts@example.test']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.from.address'))->toBe('alerts@example.test')
        ->and(config('mail.from.name'))->toBe($name);

    setEmailSettings(['email_from_name' => 'WxNotify Alerts']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.from.name'))->toBe('WxNotify Alerts');
});

test('missing settings rows leave the .env configuration untouched and throw nothing', function () {
    $default = config('mail.default');
    $from = config('mail.from');
    removeIntegrationSettingsRows();

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default)
        ->and(config('mail.from'))->toBe($from);
});

test('a failing settings read leaves the .env configuration untouched and throws nothing', function () {
    $default = config('mail.default');
    app()->bind(IntegrationSettings::class, fn () => throw new RuntimeException('settings store down'));

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default);
});

test('the app applies the email settings when it boots', function () {
    setEmailSettings(['email_mailer' => 'smtp']);

    // Re-running the provider's boot is enough to prove the wiring; re-registering its
    // observers and listeners for the rest of this one test is harmless.
    $this->app->getProvider(AppServiceProvider::class)->boot();

    expect(config('mail.default'))->toBe('smtp');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ApplyEmailSettingsTest.php --compact"`
Expected: FAIL (class does not exist).

- [ ] **Step 3: Write the action**

Create `app/Support/ApplyEmailSettings.php`:

```php
<?php

namespace App\Support;

use App\Settings\IntegrationSettings;
use Throwable;

/**
 * Applies the admin-editable email settings on top of the .env mail config.
 * Runs at boot, so it must never throw: when the settings cannot be read
 * (missing table or rows, a fresh install, during migrate) the .env
 * configuration simply stays in force.
 */
class ApplyEmailSettings
{
    public function __invoke(): void
    {
        try {
            $settings = app(IntegrationSettings::class);

            $relayEnabled = $settings->email_relay_enabled;
            $mailer = $settings->email_mailer;
            $fromAddress = $settings->email_from_address;
            $fromName = $settings->email_from_name;
        } catch (Throwable) {
            return;
        }

        if (! $relayEnabled) {
            config(['mail.default' => 'log']);
        } elseif (filled($mailer) && is_array(config("mail.mailers.{$mailer}"))) {
            config(['mail.default' => $mailer]);
        }

        if (filled($fromAddress)) {
            config(['mail.from.address' => $fromAddress]);
        }

        if (filled($fromName)) {
            config(['mail.from.name' => $fromName]);
        }
    }
}
```

- [ ] **Step 4: Call it at boot**

In `app/Providers/AppServiceProvider.php`, add `use App\Support\ApplyEmailSettings;` and make the first statement of `boot()`:

```php
        $this->app->make(ApplyEmailSettings::class)();
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ApplyEmailSettingsTest.php --compact"`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"` (about two minutes; it is moved to the background — wait for it).
Expected: all pass (report the total). The boot-time read runs for every test; on the test database the settings exist after migration, and before migration the read fails safe. Also confirm `php artisan route:list --path=admin --name=app-settings` still works inside the container (the boot-time read must not break console commands on an un-migrated dev database; it fails safe).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/ApplyEmailSettings.php app/Providers/AppServiceProvider.php tests/Feature/ApplyEmailSettingsTest.php
git commit -m "$(cat <<'EOF'
Apply the admin-editable email settings at boot

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec coverage**
- `IntegrationSettings` with every property and default in the spec table → Task 1 (defaults: backends off, email on, overrides null).
- `Integration` enum + `Integrations` helper mirroring `FeatureFlags`, enum→property mapping test → Task 1.
- Integrations tab: toggle per integration, fields shown while enabled, endpoint URL validation (http/https only), required only while enabled, values kept when disabled, email address rule, mailer list limited to configured mailers → Task 2.
- Email application at boot: null = keep `.env`, relay off → `log`, unknown mailer ignored, from overrides, fail-safe read → Task 3; the page-side warning and `queue:work` note → Task 2.
- Audit log via the existing listener, asserted for an integrations change → Task 2.
- Out of scope (credentials in DB, the actual backends, Chatwoot toggle) → not built.

**Placeholder scan:** none. One test (the mailer options one) carries an explicit fallback instruction because the exact Livewire test API for reading Select options is not known from here; it must be replaced, not deleted.

**Type consistency:** `IntegrationSettings` property names, `Integration::enabledProperty()/endpointProperty()`, `Integrations::enabled()/endpoint()` and `ApplyEmailSettings` are used with the same names across all three tasks.
