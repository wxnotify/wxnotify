# App Settings: Secrets Group Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Secrets tab on the App settings page where the Owner can set, rotate and clear WxNotify's own production credentials (Stripe, Paddle, Firebase, Traccar polling token), stored encrypted, never displayed after entry, never written to the audit log in clear, plus a resolver that returns the stored value with `.env` as the fallback.

**Architecture:** A `SecretSettings` class whose properties are flagged encrypted through `spatie/laravel-settings` (the package encrypts them in the `settings` table and in the settings cache). A `Secret` enum and `Secrets` resolver read it fail-safe with a `config('services.*')` fallback. The existing audit listener is changed first to redact encrypted properties, then the Owner-only, write-only tab is added to the existing `AppSettings` page, which never loads a secret into its form state.

**Tech Stack:** Laravel 13, Filament v5, `spatie/laravel-settings` 3.9 (already installed), `spatie/laravel-activitylog`, Pest.

**Spec:** `docs/superpowers/specs/2026-09-21-app-settings-secrets-design.md`

## Global Constraints

- No new Composer dependencies; do not run `composer require`/`update`.
- Leave `CLAUDE.md` unchanged (`git checkout CLAUDE.md` before committing if a hook modified it).
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:seed`. Do NOT run `php artisan migrate` on the dev database. Tests use `RefreshDatabase`.
- Run tests only inside the Sail container: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest <path> --compact"`. The full suite takes about two minutes and is moved to the background by the tool; wait for it. Run `vendor/bin/pint --dirty --format agent` on the host before each commit. Commit messages end with `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Do not push.
- Before editing, read `.ai/rules/index.md` and the rule files matching the paths you touch (`.ai/rules/filament.md`, `.ai/rules/feature.md`, `.ai/rules/support.md`). Style: curly braces always, explicit return types, constructor promotion.
- A secret value must NEVER appear in: the audit log, form state sent to the browser, rendered HTML, logs, exception messages, or test failure output you paste into reports. Tests use obviously fake values such as `sk_test_FAKE_1234`.
- Only `is_owner` users may see or write secrets; owners are always staff (`User` forces `is_staff` when `is_owner`). The page itself stays staff-only.
- The six slots (exact property names): `stripe_secret_key`, `stripe_webhook_secret`, `paddle_api_key`, `paddle_webhook_secret`, `firebase_service_account`, `traccar_polling_token`. All `?string`, default null, encrypted.
- `APP_KEY`, database and Redis credentials stay in `.env`; no consumers of these secrets are built in this plan.

---

### Task 1: SecretSettings, Secret enum, Secrets resolver and fallback config

**Files:**
- Create: `app/Settings/SecretSettings.php`
- Create: `database/settings/2026_09_21_300003_create_secret_settings.php`
- Create: `app/Enums/Secret.php`
- Create: `app/Support/Secrets.php`
- Modify: `config/services.php` (add `stripe`, `paddle`, `firebase`, `traccar` entries; check none of those keys already exist)
- Modify: `.env.example` (add the six env vars, empty)
- Test: `tests/Feature/SecretSettingsTest.php`

**Interfaces:**
- Produces (later tasks rely on these exact names):
  - `App\Settings\SecretSettings` (group `secrets`): the six `?string` properties; `public static function encrypted(): array` returns all six names.
  - `App\Enums\Secret` cases `StripeSecretKey`, `StripeWebhookSecret`, `PaddleApiKey`, `PaddleWebhookSecret`, `FirebaseServiceAccount`, `TraccarPollingToken`, with `label(): string`, `settingsProperty(): string` (the property names above) and `configKey(): string` (`services.stripe.secret`, `services.stripe.webhook_secret`, `services.paddle.api_key`, `services.paddle.webhook_secret`, `services.firebase.service_account`, `services.traccar.polling_token`).
  - `App\Support\Secrets::get(Secret): ?string` (stored value if filled, else `config(configKey)` if filled, else null; never throws) and `::isStored(Secret): bool` (stored only; never returns the value).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SecretSettingsTest.php`:

```php
<?php

use App\Enums\Secret;
use App\Settings\SecretSettings;
use App\Support\Secrets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

const FAKE_STRIPE_KEY = 'sk_test_FAKE_1234';

function storeSecret(string $property, ?string $value): void
{
    $settings = app(SecretSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

test('every slot defaults to null and every slot is flagged encrypted', function () {
    $settings = app(SecretSettings::class);

    foreach (Secret::cases() as $secret) {
        expect($settings->{$secret->settingsProperty()})->toBeNull()
            ->and(SecretSettings::encrypted())->toContain($secret->settingsProperty());
    }

    expect(SecretSettings::encrypted())->toHaveCount(count(Secret::cases()));
});

test('the enum maps each secret to its property and fallback config key', function (Secret $secret, string $property, string $configKey) {
    expect($secret->settingsProperty())->toBe($property)
        ->and($secret->configKey())->toBe($configKey)
        ->and($secret->label())->not->toBeEmpty();
})->with([
    [Secret::StripeSecretKey, 'stripe_secret_key', 'services.stripe.secret'],
    [Secret::StripeWebhookSecret, 'stripe_webhook_secret', 'services.stripe.webhook_secret'],
    [Secret::PaddleApiKey, 'paddle_api_key', 'services.paddle.api_key'],
    [Secret::PaddleWebhookSecret, 'paddle_webhook_secret', 'services.paddle.webhook_secret'],
    [Secret::FirebaseServiceAccount, 'firebase_service_account', 'services.firebase.service_account'],
    [Secret::TraccarPollingToken, 'traccar_polling_token', 'services.traccar.polling_token'],
]);

test('every fallback config key exists in config/services.php', function () {
    foreach (Secret::cases() as $secret) {
        expect(Illuminate\Support\Arr::has(config()->all(), $secret->configKey()))->toBeTrue();
    }
});

test('a stored secret is encrypted in the settings table and decrypts to the value', function () {
    storeSecret('stripe_secret_key', FAKE_STRIPE_KEY);

    $payload = DB::table('settings')->where('group', 'secrets')->where('name', 'stripe_secret_key')->value('payload');

    expect($payload)->not->toContain(FAKE_STRIPE_KEY)
        ->and(decrypt(json_decode($payload)))->toBe(FAKE_STRIPE_KEY);
});

test('the settings cache entry does not contain the plaintext', function () {
    storeSecret('stripe_secret_key', FAKE_STRIPE_KEY);
    Cache::flush();
    app()->forgetInstance(SecretSettings::class);

    expect(app(SecretSettings::class)->stripe_secret_key)->toBe(FAKE_STRIPE_KEY);

    $cached = Cache::get('settings.'.SecretSettings::cacheKey());

    expect($cached)->toBeString()->not->toContain(FAKE_STRIPE_KEY);
});

test('the resolver prefers the stored value over the .env fallback', function () {
    config(['services.stripe.secret' => 'env-value']);
    storeSecret('stripe_secret_key', FAKE_STRIPE_KEY);

    expect(Secrets::get(Secret::StripeSecretKey))->toBe(FAKE_STRIPE_KEY)
        ->and(Secrets::isStored(Secret::StripeSecretKey))->toBeTrue();
});

test('the resolver falls back to the .env config when nothing is stored', function () {
    config(['services.stripe.secret' => 'env-value']);

    expect(Secrets::get(Secret::StripeSecretKey))->toBe('env-value')
        ->and(Secrets::isStored(Secret::StripeSecretKey))->toBeFalse();
});

test('the resolver returns null when neither is set, and a blank stored value counts as unset', function () {
    config(['services.paddle.api_key' => null]);
    expect(Secrets::get(Secret::PaddleApiKey))->toBeNull();

    storeSecret('paddle_api_key', '');
    expect(Secrets::isStored(Secret::PaddleApiKey))->toBeFalse();
});

test('the resolver falls back to config and never throws when the settings rows are missing', function () {
    config(['services.stripe.secret' => 'env-value']);
    DB::table('settings')->where('group', 'secrets')->delete();
    Cache::flush();
    app()->forgetInstance(SecretSettings::class);

    expect(Secrets::get(Secret::StripeSecretKey))->toBe('env-value')
        ->and(Secrets::isStored(Secret::StripeSecretKey))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SecretSettingsTest.php --compact"`
Expected: FAIL (classes do not exist).

- [ ] **Step 3: Write the settings class and migration**

Create `app/Settings/SecretSettings.php`:

```php
<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * WxNotify's own production credentials. Every property is encrypted by the
 * settings package, both in the settings table and in the settings cache.
 * Never fill this class into form state or log its values.
 */
class SecretSettings extends Settings
{
    public ?string $stripe_secret_key;

    public ?string $stripe_webhook_secret;

    public ?string $paddle_api_key;

    public ?string $paddle_webhook_secret;

    public ?string $firebase_service_account;

    public ?string $traccar_polling_token;

    public static function group(): string
    {
        return 'secrets';
    }

    /**
     * @return array<int, string>
     */
    public static function encrypted(): array
    {
        return [
            'stripe_secret_key',
            'stripe_webhook_secret',
            'paddle_api_key',
            'paddle_webhook_secret',
            'firebase_service_account',
            'traccar_polling_token',
        ];
    }
}
```

Create `database/settings/2026_09_21_300003_create_secret_settings.php`:

```php
<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('secrets', function (SettingsBlueprint $blueprint): void {
            $blueprint->addEncrypted('stripe_secret_key', null);
            $blueprint->addEncrypted('stripe_webhook_secret', null);
            $blueprint->addEncrypted('paddle_api_key', null);
            $blueprint->addEncrypted('paddle_webhook_secret', null);
            $blueprint->addEncrypted('firebase_service_account', null);
            $blueprint->addEncrypted('traccar_polling_token', null);
        });
    }
};
```

Confirm `SettingsBlueprint::addEncrypted` exists in this package version (vendor/spatie/laravel-settings/src/Migrations/SettingsBlueprint.php); if the blueprint method is named differently, use the equivalent that produces an encrypted null payload and say so in your report.

- [ ] **Step 4: Write the enum, the resolver and the fallback config**

Create `app/Enums/Secret.php`:

```php
<?php

namespace App\Enums;

enum Secret: string
{
    case StripeSecretKey = 'stripe_secret_key';
    case StripeWebhookSecret = 'stripe_webhook_secret';
    case PaddleApiKey = 'paddle_api_key';
    case PaddleWebhookSecret = 'paddle_webhook_secret';
    case FirebaseServiceAccount = 'firebase_service_account';
    case TraccarPollingToken = 'traccar_polling_token';

    public function label(): string
    {
        return match ($this) {
            self::StripeSecretKey => 'Stripe secret key',
            self::StripeWebhookSecret => 'Stripe webhook signing secret',
            self::PaddleApiKey => 'Paddle API key',
            self::PaddleWebhookSecret => 'Paddle webhook signing secret',
            self::FirebaseServiceAccount => 'Firebase service-account JSON',
            self::TraccarPollingToken => 'Traccar polling token',
        };
    }

    /**
     * The encrypted property on SecretSettings that holds this secret.
     */
    public function settingsProperty(): string
    {
        return $this->value;
    }

    /**
     * The config key (reading .env) used when nothing is stored.
     */
    public function configKey(): string
    {
        return match ($this) {
            self::StripeSecretKey => 'services.stripe.secret',
            self::StripeWebhookSecret => 'services.stripe.webhook_secret',
            self::PaddleApiKey => 'services.paddle.api_key',
            self::PaddleWebhookSecret => 'services.paddle.webhook_secret',
            self::FirebaseServiceAccount => 'services.firebase.service_account',
            self::TraccarPollingToken => 'services.traccar.polling_token',
        };
    }
}
```

Create `app/Support/Secrets.php`:

```php
<?php

namespace App\Support;

use App\Enums\Secret;
use App\Settings\SecretSettings;
use Throwable;

/**
 * Reads WxNotify's own production credentials: the value stored (encrypted)
 * in App settings wins, the .env-backed config is the fallback. Never throws;
 * if the settings cannot be read the .env value is used.
 */
class Secrets
{
    public static function get(Secret $secret): ?string
    {
        $stored = self::stored($secret);

        if (filled($stored)) {
            return $stored;
        }

        $fallback = config($secret->configKey());

        return filled($fallback) ? $fallback : null;
    }

    public static function isStored(Secret $secret): bool
    {
        return filled(self::stored($secret));
    }

    private static function stored(Secret $secret): ?string
    {
        try {
            return app(SecretSettings::class)->{$secret->settingsProperty()};
        } catch (Throwable) {
            return null;
        }
    }
}
```

In `config/services.php` add (before the closing `];`, after the `docker` entry):

```php
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paddle' => [
        'api_key' => env('PADDLE_API_KEY'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
    ],

    'firebase' => [
        'service_account' => env('FIREBASE_SERVICE_ACCOUNT'),
    ],

    'traccar' => [
        'polling_token' => env('TRACCAR_POLLING_TOKEN'),
    ],
```

Append to `.env.example`:

```
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
PADDLE_API_KEY=
PADDLE_WEBHOOK_SECRET=
FIREBASE_SERVICE_ACCOUNT=
TRACCAR_POLLING_TOKEN=
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SecretSettingsTest.php --compact"`
Expected: PASS. If the cache-ciphertext test fails because the cache key or serialization differs, look at `vendor/spatie/laravel-settings/src/SettingsCache.php` (`resolveCacheKey`) and fix the key, NOT the assertion's intent (the cached string must not contain the plaintext).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Settings/SecretSettings.php database/settings/2026_09_21_300003_create_secret_settings.php app/Enums/Secret.php app/Support/Secrets.php config/services.php .env.example tests/Feature/SecretSettingsTest.php
git commit -m "$(cat <<'EOF'
Add encrypted secret settings with a resolver and env fallback

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Redact encrypted settings in the audit log

**Files:**
- Modify: `app/Listeners/LogSettingsChange.php`
- Test: `tests/Feature/SettingsAuditLogTest.php` (append; keep every existing test)

**Interfaces:**
- Consumes: `SavingSettings` (`->settings`, `->properties` = property => NEW value in PLAINTEXT, `->originalValues` = loaded values, decrypted); `Settings::settingsConfig()->isEncrypted(string $name): bool`; `SecretSettings` (Task 1).
- Produces: for an encrypted property the activity row's `changes[property]` is `['old' => '[set]'|'[not set]', 'new' => '[set]'|'[not set]']` — a rotation logs `[set]` → `[set]`. Non-encrypted properties log exactly as before.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SettingsAuditLogTest.php` (add imports as needed: `App\Settings\SecretSettings`):

```php
test('a secret being set logs markers, never the value', function () {
    $staff = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $this->actingAs($staff);

    $settings = app(SecretSettings::class);
    $settings->stripe_secret_key = 'sk_test_FAKE_1234';
    $settings->save();

    $activity = Activity::query()->where('log_name', 'settings')->sole();

    expect($activity->description)->toBe('Updated secrets settings')
        ->and($activity->causer_id)->toBe($staff->id)
        ->and($activity->properties['changes'])->toBe(['stripe_secret_key' => ['old' => '[not set]', 'new' => '[set]']]);
});

test('rotating and clearing a secret log markers and no value ever reaches the activity log', function () {
    $settings = app(SecretSettings::class);
    $settings->stripe_secret_key = 'sk_test_FAKE_1234';
    $settings->save();

    $settings->stripe_secret_key = 'sk_test_FAKE_5678';
    $settings->save();

    $settings->stripe_secret_key = null;
    $settings->save();

    $changes = Activity::query()->where('log_name', 'settings')->orderBy('id')->get()
        ->map(fn ($activity) => $activity->properties['changes']['stripe_secret_key'])
        ->all();

    expect($changes)->toBe([
        ['old' => '[not set]', 'new' => '[set]'],
        ['old' => '[set]', 'new' => '[set]'],
        ['old' => '[set]', 'new' => '[not set]'],
    ]);

    $everything = json_encode(Activity::query()->get()->toArray());

    expect($everything)->not->toContain('sk_test_FAKE_1234')->not->toContain('sk_test_FAKE_5678');
});

test('an unchanged secret save logs nothing and non-encrypted settings still log their values', function () {
    app(SecretSettings::class)->save();

    expect(Activity::query()->where('log_name', 'settings')->count())->toBe(0);

    $general = app(GeneralSettings::class);
    $general->maintenance_mode = true;
    $general->save();

    expect(Activity::query()->where('log_name', 'settings')->sole()->properties['changes'])
        ->toBe(['maintenance_mode' => ['old' => false, 'new' => true]]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SettingsAuditLogTest.php --compact"`
Expected: FAIL (the secret value is currently logged in clear).

- [ ] **Step 3: Redact in the listener**

In `app/Listeners/LogSettingsChange.php` replace the `handle()` body's loop so encrypted properties are redacted, and update the class docblock with one sentence noting that encrypted properties (secrets) are logged as `[set]` / `[not set]` markers only:

```php
    public function handle(SavingSettings $event): void
    {
        $group = $event->settings::group();
        $config = $event->settings->settingsConfig();
        $changes = [];

        foreach ($event->properties as $name => $new) {
            $old = $event->originalValues?->get($name);

            if ($old === $new) {
                continue;
            }

            $changes[$name] = $config->isEncrypted($name)
                ? ['old' => $this->marker($old), 'new' => $this->marker($new)]
                : ['old' => $old, 'new' => $new];
        }

        if ($changes === []) {
            return;
        }

        activity('settings')
            ->withProperties(['group' => $group, 'changes' => $changes])
            ->log("Updated {$group} settings");
    }

    private function marker(mixed $value): string
    {
        return filled($value) ? '[set]' : '[not set]';
    }
```

Note: a rotation from one value to another compares unequal, so it is logged as `[set]` → `[set]`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SettingsAuditLogTest.php tests/Feature/SecretSettingsTest.php tests/Feature/FeatureFlagsTest.php tests/Feature/IntegrationsTabTest.php --compact"`
Expected: PASS (every existing audit test still passes).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Listeners/LogSettingsChange.php tests/Feature/SettingsAuditLogTest.php
git commit -m "$(cat <<'EOF'
Redact encrypted settings in the settings audit log

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: The Owner-only, write-only Secrets tab

**Files:**
- Modify: `app/Filament/Admin/Pages/AppSettings.php`
- Test: `tests/Feature/SecretsTabTest.php`

**Interfaces:**
- Consumes: `SecretSettings`, `Secret`, `Secrets::isStored()` (Task 1); the redacting audit listener (Task 2). Read `app/Filament/Admin/Pages/AppSettings.php` first: flat form state; `mount()` fills four settings classes (do NOT add `SecretSettings` there); `save()` fills `[GeneralSettings, TierThresholdSettings, FeatureFlagsSettings, IntegrationSettings]` inside a `DB::transaction` from `Arr::only($data, array_keys($settings->toArray()))`.
- Produces: a fifth tab "Secrets" (owners only); form fields named after the six property names plus `clear_<property>` checkboxes; `save()` writes secrets only for owners; typed values are cleared from `$this->data` after save.

Behavior (exactly this):
- The Secrets `Tab` is `->visible(fn (): bool => auth()->user()?->is_owner === true)`. Its fields therefore only exist in `getState()` for owners.
- One field per `Secret` case, named `$secret->settingsProperty()`: a `TextInput::make(...)->password()->autocomplete('new-password')` (NOT revealable), except `firebase_service_account`, which is a `Textarea` (rows 4). Never pre-filled. Helper text: `Secrets::isStored($secret)` ? "Set. Enter a new value to replace it." : "Not set. Falls back to the .env value if one is configured."
- Each slot has `Checkbox::make("clear_{$property}")->label('Remove stored value')`, visible only while `Secrets::isStored($secret)`.
- `firebase_service_account` must be valid JSON that contains non-empty `client_email` and `private_key` (a closure rule, `fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {...}`), evaluated only when filled.
- `save()`: the four existing classes are saved as before; secrets are handled by a separate private method that returns immediately unless the signed-in user is an owner. For each secret: if `$data["clear_{$property}"]` is truthy → set null; else if `$data[$property]` is filled → set that value (trimmed); otherwise untouched. If nothing changed, do not save. Inside the same `DB::transaction`.
- After saving, reset every secret property and `clear_*` key in `$this->data` to `null`/`false` so nothing typed is retained or echoed back.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SecretsTabTest.php`:

```php
<?php

use App\Enums\Secret;
use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\SecretSettings;
use App\Support\Secrets;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

const TAB_FAKE_KEY = 'sk_test_FAKE_1234';

function fakeServiceAccount(): string
{
    return json_encode(['client_email' => 'svc@example.iam.gserviceaccount.com', 'private_key' => '-----BEGIN FAKE-----']);
}

function storeTabSecret(string $property, ?string $value): void
{
    $settings = app(SecretSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->owner = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $this->staff = User::factory()->create(['is_staff' => true, 'is_owner' => false]);
});

test('an owner can set a secret and it is stored encrypted', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Secrets::isStored(Secret::StripeSecretKey))->toBeTrue()
        ->and(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBe(TAB_FAKE_KEY);
});

test('a stored secret is never loaded into the page state or the rendered page', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->owner);

    $component = livewire(AppSettings::class)
        ->assertFormSet(['stripe_secret_key' => null])
        ->assertSee('Set. Enter a new value to replace it.')
        ->assertDontSee(TAB_FAKE_KEY, false);

    expect(json_encode($component->get('data')))->not->toContain(TAB_FAKE_KEY);
});

test('a typed secret is cleared from the page state after saving', function () {
    $this->actingAs($this->owner);

    $component = livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY])
        ->call('save')
        ->assertDontSee(TAB_FAKE_KEY, false);

    expect($component->get('data.stripe_secret_key'))->toBeNull()
        ->and(json_encode($component->get('data')))->not->toContain(TAB_FAKE_KEY);
});

test('leaving a slot blank keeps the stored value', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => '', 'maintenance_mode' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBe(TAB_FAKE_KEY);
});

test('a typed value replaces the stored one and the checkbox clears it', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => 'sk_test_FAKE_5678'])
        ->call('save');

    expect(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBe('sk_test_FAKE_5678');

    livewire(AppSettings::class)
        ->assertFormFieldVisible('clear_stripe_secret_key')
        ->fillForm(['clear_stripe_secret_key' => true])
        ->call('save');

    expect(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBeNull();
});

test('the remove checkbox is hidden for a slot that has nothing stored', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)->assertFormFieldHidden('clear_paddle_api_key');
});

test('the firebase slot only accepts service-account JSON', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['firebase_service_account' => 'not json'])
        ->call('save')
        ->assertHasFormErrors(['firebase_service_account']);

    livewire(AppSettings::class)
        ->fillForm(['firebase_service_account' => '{"client_email":"a@b.c"}'])
        ->call('save')
        ->assertHasFormErrors(['firebase_service_account']);

    livewire(AppSettings::class)
        ->fillForm(['firebase_service_account' => fakeServiceAccount()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Secrets::isStored(Secret::FirebaseServiceAccount))->toBeTrue();
});

test('a staff user who is not an owner cannot see the tab or write a secret', function () {
    $this->actingAs($this->staff);

    livewire(AppSettings::class)
        ->assertFormFieldHidden('stripe_secret_key')
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY, 'clear_stripe_secret_key' => true])
        ->call('save');

    expect(Secrets::isStored(Secret::StripeSecretKey))->toBeFalse();
});

test('a staff user who is not an owner cannot clear or replace an existing secret either', function () {
    storeTabSecret('stripe_secret_key', TAB_FAKE_KEY);
    $this->actingAs($this->staff);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => 'sk_test_FAKE_9999', 'clear_stripe_secret_key' => true])
        ->call('save');

    expect(app(SecretSettings::class)->refresh()->stripe_secret_key)->toBe(TAB_FAKE_KEY);
});

test('a secret change from the page is audit-logged with markers only', function () {
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['stripe_secret_key' => TAB_FAKE_KEY])
        ->call('save');

    $activity = Activity::query()->where('log_name', 'settings')->where('description', 'Updated secrets settings')->sole();

    expect($activity->causer_id)->toBe($this->owner->id)
        ->and($activity->properties['changes'])->toBe(['stripe_secret_key' => ['old' => '[not set]', 'new' => '[set]']])
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain(TAB_FAKE_KEY);
});

test('saving other settings as an owner does not disturb stored secrets', function () {
    storeTabSecret('paddle_api_key', 'pd_FAKE_1');
    $this->actingAs($this->owner);

    livewire(AppSettings::class)
        ->fillForm(['maintenance_message' => 'Back soon.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SecretSettings::class)->refresh()->paddle_api_key)->toBe('pd_FAKE_1');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SecretsTabTest.php --compact"`
Expected: FAIL (the fields do not exist).

- [ ] **Step 3: Extend the page**

In `app/Filament/Admin/Pages/AppSettings.php`:

1. Add imports: `App\Enums\Secret`, `App\Settings\SecretSettings`, `App\Support\Secrets`, `Filament\Forms\Components\Checkbox`, and `Closure` if not already imported.
2. Do NOT add `SecretSettings` to `mount()` or to the generic `save()` class list.
3. In `form()`, add a fifth `Tab::make('Secrets')` built by a private `secretsTab(): Tab`, `->visible(fn (): bool => auth()->user()?->is_owner === true)`, with a short `Section` description saying these credentials are stored encrypted, are never shown again after saving, and that leaving a field blank keeps the current value. One field per `Secret::cases()` as specified above (labels from `$secret->label()`), plus the `clear_*` checkboxes (`->visible(fn (): bool => Secrets::isStored($secret))`, `->dehydrated()`), the Firebase JSON closure rule, and `->autocomplete('new-password')` on the password inputs.
4. In `save()`, inside the existing `DB::transaction`, after the class loop call `$this->saveSecrets($data)`. Implement `private function saveSecrets(array $data): void` per the behavior notes (owner check first, `Arr` not needed; build `$changes`, `fill($changes)->save()` only when non-empty).
5. After the transaction (and before the success notification), reset the secret state: for each `Secret` case set `$this->data[$property] = null` and `$this->data["clear_{$property}"] = false`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/SecretsTabTest.php tests/Feature/AppSettingsPageTest.php tests/Feature/IntegrationsTabTest.php tests/Feature/FeatureFlagsTest.php tests/Feature/SettingsAuditLogTest.php tests/Feature/SecretSettingsTest.php --compact"`
Expected: PASS.

Things to verify rather than assume: (a) a hidden `Tab` (non-owner) really excludes its children from `getState()` — the two non-owner tests are the proof; if a tampered `fillForm` value still reaches `save()`, the owner check in `saveSecrets()` is the second line of defence and the second non-owner test must still pass; (b) `assertDontSee(TAB_FAKE_KEY, false)` really inspects the rendered HTML (if the Livewire test helper does not support it as used, assert against `$component->html()` instead); (c) resetting `$this->data` after save does not wipe other tabs' values.

- [ ] **Step 5: Run the full suite**

Run: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"` (about two minutes; it is moved to the background — wait for it).
Expected: all pass (report the total).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Admin/Pages/AppSettings.php tests/Feature/SecretsTabTest.php
git commit -m "$(cat <<'EOF'
Add the Owner-only, write-only Secrets tab to App settings

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

**Spec coverage**
- Encrypted `SecretSettings` with the six slots, encrypted in the table and the cache → Task 1 (raw-row and cache-ciphertext tests).
- `Secret` enum, `Secrets::get/isStored`, stored-wins-over-env, env fallback, fail-safe on missing rows, `config/services.php` keys and `.env.example` → Task 1.
- Audit redaction of encrypted properties with `[set]`/`[not set]` markers, rotation as `[set]`→`[set]`, no plaintext anywhere in the activity table → Task 2 (and re-asserted end to end in Task 3).
- Owner-only tab, non-owner cannot see or write (hidden-tab state exclusion plus the explicit owner check in `saveSecrets()`), write-only fields never filled, status text, remove-checkbox, blank keeps, Firebase JSON validation, typed value cleared after save → Task 3.
- Out of scope (consumers, mail credentials, `APP_KEY` rotation, per-subscriber secrets) → not built.

**Placeholder scan:** none. Task 3 Step 4 lists three verify-don't-assume points with a named fallback for each.

**Type consistency:** `SecretSettings` property names, `Secret::settingsProperty()/configKey()/label()`, `Secrets::get()/isStored()`, and the `clear_<property>` field names are used identically across all three tasks and tests.
