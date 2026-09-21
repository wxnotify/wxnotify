# Heartbeat Pings (Healthchecks) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single Heartbeat endpoint with three Healthchecks ping URLs (NWWS, EMWIN Byteblaster, EMWIN HTTP) and send a GET to a feed's URL each time that feed delivers a product to Laravel.

**Architecture:** Three new nullable settings on `IntegrationSettings` (settings migration), exposed through `Integrations::heartbeatUrl(PipelineFeed)` and edited on the App settings Integrations tab. The three ingest endpoints call a never-throwing `DispatchHeartbeatPing` action that queues a `SendHeartbeatPing` job; the job re-reads the URL, checks it with a dedicated `HeartbeatUrlGuard`, and performs a no-redirect GET.

**Tech Stack:** Laravel 13, spatie/laravel-settings, Filament v5 (App settings page), Laravel HTTP client + queue, Pest.

**Spec:** `docs/superpowers/specs/2026-09-21-heartbeat-pings-design.md`

## Global Constraints

- Run PHP/tests inside Sail: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact <path>"`. Pint: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"`. Run Pint before every commit. `phpunit.xml` sets `QUEUE_CONNECTION=sync`, so dispatched jobs run inline in tests unless you `Bus::fake()`.
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:seed`, and do NOT run `php artisan migrate` on the dev DB (tests use `RefreshDatabase`, which applies the settings migrations in `database/settings`). The user runs the migration.
- No new Composer or npm dependencies. Do not edit `CLAUDE.md` (if a hook rewrites it, `git checkout CLAUDE.md`). Do not push.
- Commits end with the trailer `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Work on `main`; stage only the files each task names, never `git add -A`.
- Read `.ai/rules/index.md` and the rule files matching the paths you edit before editing (`app/Support/**` → support.md, `app/Filament/Admin/Pages/**` → pages.md, `tests/Feature/**` → feature.md, `app/Models/**` → models.md). Use `search-docs` (Laravel Boost) for Filament/Laravel API questions.
- Feed keys are exactly `nwws`, `emwin` (Byteblaster), `emwin_http` (the keys `PipelineHeartbeat` and `App\Enums\PipelineFeed` use).
- The three ping URLs are plain settings (not in the Secrets group), each optional, validated `url:http,https`, max 255 characters, saved as null when blank. The page stays staff-only; no new routes.
- Every accepted delivery pings (including deduplicated ones), with no throttle. A rejected path (404) sends no ping. Nothing is queued when Heartbeat is off or that feed's URL is blank.
- The ping URL contains the check's UUID and must never appear in logs, exception messages or job failure output. Warnings name only the feed key, throttled to one per feed per five minutes, and the throttle itself must not be able to throw.
- Ingest must never fail or slow because of a ping: dispatch is queued and wrapped, and the job never rethrows.
- Job: one try, 5-second timeout, no redirect following. Guard: only http/https, no embedded credentials, refuse link-local and cloud-metadata targets (169.254.0.0/16, `metadata.google.internal`, and the IPv6 equivalents), allow other private hosts.

---

### Task 1: Settings, enum and `Integrations::heartbeatUrl()`

**Files:**
- Create: `database/settings/2026_09_21_400001_add_heartbeat_ping_urls.php`
- Modify: `app/Settings/IntegrationSettings.php`
- Modify: `app/Enums/Integration.php`
- Modify: `app/Enums/PipelineFeed.php`
- Modify: `app/Support/Integrations.php`
- Test: `tests/Feature/IntegrationSettingsTest.php` (update + append)

**Interfaces:**
- Produces: `IntegrationSettings` properties `?string $heartbeat_nwws_url`, `?string $heartbeat_emwin_url`, `?string $heartbeat_emwin_http_url` (all default null); `heartbeat_endpoint` is removed. `Integration::Heartbeat->endpointProperty()` returns null.
- Produces: `App\Enums\PipelineFeed::heartbeatUrlProperty(): string` (`nwws` → `heartbeat_nwws_url`, `emwin` → `heartbeat_emwin_url`, `emwin_http` → `heartbeat_emwin_http_url`).
- Produces: `App\Support\Integrations::heartbeatUrl(PipelineFeed $feed): ?string` — the feed's URL only when `heartbeat_enabled` is true and the URL is filled, else null; returns null (never throws) when the settings cannot be read.

- [ ] **Step 1: Update the existing tests and add the new ones** in `tests/Feature/IntegrationSettingsTest.php`:
  - In `endpoints, provider and mail overrides default to null`, replace `->and($settings->heartbeat_endpoint)->toBeNull()` with `->and($settings->heartbeat_nwws_url)->toBeNull()->and($settings->heartbeat_emwin_url)->toBeNull()->and($settings->heartbeat_emwin_http_url)->toBeNull()`.
  - In the `each integration maps to its expected properties` dataset, change the heartbeat row to `'heartbeat' => [Integration::Heartbeat, 'heartbeat_enabled', null],`.
  - Replace the test `endpoint() returns the stored short urls and heartbeat endpoints` with one that only covers short urls:

```php
test('endpoint() returns the stored short urls endpoint', function () {
    setIntegrationSetting('short_urls_endpoint', 'https://s.example.test');

    expect(Integrations::endpoint(Integration::ShortUrls))->toBe('https://s.example.test')
        ->and(Integrations::endpoint(Integration::Heartbeat))->toBeNull();
});
```

  - Append (add `use App\Enums\PipelineFeed;` and `use Illuminate\Support\Facades\DB;` to the imports):

```php
test('each feed maps to its own heartbeat url property', function () {
    expect(PipelineFeed::Nwws->heartbeatUrlProperty())->toBe('heartbeat_nwws_url')
        ->and(PipelineFeed::Emwin->heartbeatUrlProperty())->toBe('heartbeat_emwin_url')
        ->and(PipelineFeed::EmwinHttp->heartbeatUrlProperty())->toBe('heartbeat_emwin_http_url');
});

test('heartbeatUrl returns each feed url only while heartbeat is enabled', function () {
    setIntegrationSetting('heartbeat_nwws_url', 'https://hc.example.test/ping/nwws');
    setIntegrationSetting('heartbeat_emwin_url', 'https://hc.example.test/ping/emwin');
    setIntegrationSetting('heartbeat_emwin_http_url', 'https://hc.example.test/ping/http');

    expect(Integrations::heartbeatUrl(PipelineFeed::Nwws))->toBeNull();

    setIntegrationSetting('heartbeat_enabled', true);

    expect(Integrations::heartbeatUrl(PipelineFeed::Nwws))->toBe('https://hc.example.test/ping/nwws')
        ->and(Integrations::heartbeatUrl(PipelineFeed::Emwin))->toBe('https://hc.example.test/ping/emwin')
        ->and(Integrations::heartbeatUrl(PipelineFeed::EmwinHttp))->toBe('https://hc.example.test/ping/http');
});

test('heartbeatUrl is null for a blank url', function () {
    setIntegrationSetting('heartbeat_enabled', true);
    setIntegrationSetting('heartbeat_emwin_url', '');

    expect(Integrations::heartbeatUrl(PipelineFeed::Emwin))->toBeNull()
        ->and(Integrations::heartbeatUrl(PipelineFeed::Nwws))->toBeNull();
});

test('heartbeatUrl fails open when the integration settings cannot be read', function () {
    DB::table('settings')->where('group', 'integrations')->delete();
    app()->forgetInstance(IntegrationSettings::class);

    expect(Integrations::heartbeatUrl(PipelineFeed::Nwws))->toBeNull();
});
```

  The last test must really reach the failure path: settings rows deleted and the settings cache defeated. If the package's settings cache still serves the old values (check `config('settings.cache')`), clear it the same way the existing settings/guard tests simulate missing rows (search the tests for how `MaintenanceModeGuard` fail-open is exercised) and keep the test meaningful.

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/pest --compact tests/Feature/IntegrationSettingsTest.php`. Expected: FAIL (properties/methods missing).

- [ ] **Step 3: Implement.** Create the settings migration:

```php
<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('integrations', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('heartbeat_nwws_url', null);
            $blueprint->add('heartbeat_emwin_url', null);
            $blueprint->add('heartbeat_emwin_http_url', null);
            $blueprint->delete('heartbeat_endpoint');
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('integrations', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('heartbeat_endpoint', null);
            $blueprint->delete('heartbeat_nwws_url');
            $blueprint->delete('heartbeat_emwin_url');
            $blueprint->delete('heartbeat_emwin_http_url');
        });
    }
};
```

In `IntegrationSettings` replace `public ?string $heartbeat_endpoint;` with:

```php
    public ?string $heartbeat_nwws_url;

    public ?string $heartbeat_emwin_url;

    public ?string $heartbeat_emwin_http_url;
```

In `Integration::endpointProperty()` remove the `self::Heartbeat => 'heartbeat_endpoint',` arm and add `self::Heartbeat` to the null arm: `self::ExceptionReporting, self::EmailRelay, self::Heartbeat => null,`.

In `PipelineFeed` add:

```php
    public function heartbeatUrlProperty(): string
    {
        return match ($this) {
            self::Nwws => 'heartbeat_nwws_url',
            self::Emwin => 'heartbeat_emwin_url',
            self::EmwinHttp => 'heartbeat_emwin_http_url',
        };
    }
```

In `Integrations` add (imports `App\Enums\PipelineFeed`, `Throwable`):

```php
    public static function heartbeatUrl(PipelineFeed $feed): ?string
    {
        try {
            $settings = app(IntegrationSettings::class);

            if (! $settings->heartbeat_enabled) {
                return null;
            }

            $value = $settings->{$feed->heartbeatUrlProperty()};

            return filled($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
```

Do not touch `AppSettings.php` here (Task 2), so the page briefly references the removed property — Task 1 leaves the tree consistent by also making the minimal change: in `app/Filament/Admin/Pages/AppSettings.php` remove `'heartbeat_endpoint',` from `NULLABLE_STRINGS` and remove the single `$this->endpointField('heartbeat_endpoint', ...)` line from the Heartbeat section (Task 2 adds the three new fields). Also remove the `'heartbeat' => ['heartbeat_enabled', 'heartbeat_endpoint'],` row from the dataset in `tests/Feature/IntegrationsTabTest.php` (it is replaced by Task 2's tests) so the suite stays green.

- [ ] **Step 4: Run to verify it passes** — `vendor/bin/pest --compact tests/Feature/IntegrationSettingsTest.php tests/Feature/IntegrationsTabTest.php`. Expected: all pass.

- [ ] **Step 5: Commit** (Pint first):

```bash
git add database/settings/2026_09_21_400001_add_heartbeat_ping_urls.php app/Settings/IntegrationSettings.php app/Enums/Integration.php app/Enums/PipelineFeed.php app/Support/Integrations.php app/Filament/Admin/Pages/AppSettings.php tests/Feature/IntegrationSettingsTest.php tests/Feature/IntegrationsTabTest.php
git commit -m "Replace the heartbeat endpoint with three per-feed ping URL settings

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Heartbeat form fields on the Integrations tab

**Files:**
- Modify: `app/Filament/Admin/Pages/AppSettings.php`
- Test: `tests/Feature/IntegrationsTabTest.php` (append)

**Interfaces:**
- Consumes: the three `IntegrationSettings` properties and the `AppSettings::NULLABLE_STRINGS` blank-to-null handling (Task 1 removed `heartbeat_endpoint` from it).
- Produces: form fields `heartbeat_nwws_url`, `heartbeat_emwin_url`, `heartbeat_emwin_http_url`, visible only while `heartbeat_enabled` is on, optional, `url:http,https`, saved as null when blank; the stored values survive turning Heartbeat off.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/IntegrationsTabTest.php`:

```php
test('the heartbeat ping url fields are hidden until heartbeat is enabled', function () {
    livewire(AppSettings::class)
        ->assertFormFieldHidden('heartbeat_nwws_url')
        ->assertFormFieldHidden('heartbeat_emwin_url')
        ->assertFormFieldHidden('heartbeat_emwin_http_url')
        ->fillForm(['heartbeat_enabled' => true])
        ->assertFormFieldVisible('heartbeat_nwws_url')
        ->assertFormFieldVisible('heartbeat_emwin_url')
        ->assertFormFieldVisible('heartbeat_emwin_http_url');
});

test('heartbeat urls are optional and saved per feed, with blank saved as null', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'heartbeat_enabled' => true,
            'heartbeat_nwws_url' => 'https://hc.example.test/ping/aaaa',
            'heartbeat_emwin_url' => '',
            'heartbeat_emwin_http_url' => 'http://healthchecks.internal:8000/ping/cccc',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->heartbeat_enabled)->toBeTrue()
        ->and($settings->heartbeat_nwws_url)->toBe('https://hc.example.test/ping/aaaa')
        ->and($settings->heartbeat_emwin_url)->toBeNull()
        ->and($settings->heartbeat_emwin_http_url)->toBe('http://healthchecks.internal:8000/ping/cccc');
});

test('heartbeat can be enabled with no urls at all', function () {
    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => true, 'heartbeat_nwws_url' => null, 'heartbeat_emwin_url' => null, 'heartbeat_emwin_http_url' => null])
        ->call('save')
        ->assertHasNoFormErrors();
});

test('only http and https heartbeat urls are accepted', function (string $field, string $value) {
    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => true, $field => $value])
        ->call('save')
        ->assertHasFormErrors([$field]);
})->with([
    'nwws ftp' => ['heartbeat_nwws_url', 'ftp://hc.example.test/ping/x'],
    'emwin not a url' => ['heartbeat_emwin_url', 'not a url'],
    'http backup javascript' => ['heartbeat_emwin_http_url', 'javascript:alert(1)'],
]);

test('turning heartbeat off keeps the stored ping urls', function () {
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = true;
    $settings->heartbeat_nwws_url = 'https://hc.example.test/ping/aaaa';
    $settings->heartbeat_emwin_http_url = 'https://hc.example.test/ping/cccc';
    $settings->save();

    livewire(AppSettings::class)
        ->fillForm(['heartbeat_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(IntegrationSettings::class)->refresh();

    expect($settings->heartbeat_enabled)->toBeFalse()
        ->and($settings->heartbeat_nwws_url)->toBe('https://hc.example.test/ping/aaaa')
        ->and($settings->heartbeat_emwin_http_url)->toBe('https://hc.example.test/ping/cccc');
});
```

If the last test shows hidden fields wiping stored values (the page's save path may set missing/hidden keys), fix the page's save handling so keys absent from the submitted state are left untouched — the existing test `a disabled integration keeps its stored endpoint and is not required` shows the intended behavior; mirror whatever it relies on.

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/pest --compact tests/Feature/IntegrationsTabTest.php`. Expected: the new tests FAIL (fields do not exist).

- [ ] **Step 3: Implement** in `app/Filament/Admin/Pages/AppSettings.php`:

Add the three keys to `NULLABLE_STRINGS` (after `'short_urls_endpoint',`):

```php
        'heartbeat_nwws_url',
        'heartbeat_emwin_url',
        'heartbeat_emwin_http_url',
```

Replace the Heartbeat section (which Task 1 left with only the toggle) with:

```php
            Section::make('Heartbeat')
                ->description('Healthchecks ping URLs. A feed\'s URL is requested each time that feed delivers a product; leave a URL blank to skip that feed.')
                ->schema([
                    Toggle::make('heartbeat_enabled')->label('Heartbeat enabled')->live(),
                    $this->endpointField('heartbeat_nwws_url', 'NWWS ping URL', 'heartbeat_enabled', required: false),
                    $this->endpointField('heartbeat_emwin_url', 'EMWIN Byteblaster ping URL', 'heartbeat_enabled', required: false),
                    $this->endpointField('heartbeat_emwin_http_url', 'EMWIN HTTP ping URL', 'heartbeat_enabled', required: false),
                ]),
```

Change the helper so other integrations keep their required behaviour:

```php
    private function endpointField(string $name, string $label, string $toggle, bool $required = true): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->maxLength(255)
            ->rules(['url:http,https'])
            ->required($required ? fn (Get $get): bool => (bool) $get($toggle) : false)
            ->visible(fn (Get $get): bool => (bool) $get($toggle));
    }
```

- [ ] **Step 4: Run to verify it passes** — `vendor/bin/pest --compact tests/Feature/IntegrationsTabTest.php tests/Feature/IntegrationSettingsTest.php`. Expected: all pass (the existing required-endpoint tests for the other integrations still pass).

- [ ] **Step 5: Verify in the browser.** Use the Browser tools (`mcp__Claude_Browser__*`; load via ToolSearch if deferred): open `http://localhost:8080/dashboard/login`, click the "Admin (admin@host.com)" one-click button (never type credentials), then open `http://localhost:8080/admin/app-settings` and the Integrations tab. NOTE: the dev DB has NOT run the new settings migration, so the page may error until the user migrates; if it does, say so in your report instead of running the migration, and rely on the Livewire tests. If the page renders, confirm the three fields appear only when Heartbeat is toggled on.

- [ ] **Step 6: Commit** (Pint first; stage `app/Filament/Admin/Pages/AppSettings.php` and `tests/Feature/IntegrationsTabTest.php`; message "Add the three heartbeat ping URL fields to the Integrations tab" with the trailer).

---

### Task 3: Ping guard, job and dispatch action

**Files:**
- Create: `app/Support/Delivery/HeartbeatUrlGuard.php`
- Create: `app/Jobs/SendHeartbeatPing.php`
- Create: `app/Actions/Pipeline/DispatchHeartbeatPing.php`
- Test: `tests/Feature/HeartbeatPingTest.php`

**Interfaces:**
- Consumes: `Integrations::heartbeatUrl(PipelineFeed): ?string` and `PipelineFeed::tryFrom(string)` (Task 1 / existing enum).
- Produces: `HeartbeatUrlGuard::violation(string $url): ?string` (null when safe; constructor takes an optional `?Closure $resolver` returning a list of IP strings for a host, for tests).
- Produces: `App\Jobs\SendHeartbeatPing` — `public function __construct(public readonly string $feed)`, queued, `public int $tries = 1`, never rethrows.
- Produces: `App\Actions\Pipeline\DispatchHeartbeatPing::__invoke(string $feed): void` — queues the job only when `Integrations::heartbeatUrl()` is non-null; swallows and reports any dispatch failure.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/HeartbeatPingTest.php`:

```php
<?php

use App\Actions\Pipeline\DispatchHeartbeatPing;
use App\Jobs\SendHeartbeatPing;
use App\Settings\IntegrationSettings;
use App\Support\Delivery\HeartbeatUrlGuard;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function heartbeatSetup(array $overrides = []): void
{
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = true;

    foreach (['heartbeat_nwws_url' => 'https://hc.example.test/ping/nwws-uuid', 'heartbeat_emwin_url' => 'https://hc.example.test/ping/emwin-uuid', 'heartbeat_emwin_http_url' => 'https://hc.example.test/ping/http-uuid', ...$overrides] as $property => $value) {
        $settings->{$property} = $value;
    }

    $settings->save();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
});

// --- guard ---

test('the guard allows public https and private http hosts', function (string $url) {
    $guard = new HeartbeatUrlGuard(fn (string $host): array => ['10.0.0.5']);

    expect($guard->violation($url))->toBeNull();
})->with([
    'public https' => 'https://hc.example.test/ping/abc',
    'private http host' => 'http://healthchecks.internal:8000/ping/abc',
    'private ip' => 'http://192.168.1.20/ping/abc',
    'loopback' => 'http://127.0.0.1:8000/ping/abc',
]);

test('the guard refuses other schemes, embedded credentials and unparseable urls', function (string $url) {
    expect((new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']))->violation($url))->not->toBeNull();
})->with([
    'ftp' => 'ftp://hc.example.test/ping/abc',
    'file' => 'file:///etc/passwd',
    'credentials' => 'https://user:pass@hc.example.test/ping/abc',
    'password only' => 'https://:pass@hc.example.test/ping/abc',
    'no scheme' => 'hc.example.test/ping/abc',
    'no host' => 'http:///ping/abc',
]);

test('the guard refuses link-local and cloud metadata targets', function (string $url) {
    expect((new HeartbeatUrlGuard(fn (string $host): array => []))->violation($url))->not->toBeNull();
})->with([
    'aws/azure/gcp ipv4' => 'http://169.254.169.254/latest/meta-data',
    'link-local range' => 'http://169.254.10.20/ping',
    'gcp name' => 'http://metadata.google.internal/computeMetadata/v1/',
    'ipv6 link-local' => 'http://[fe80::1]/ping',
    'aws ipv6 imds' => 'http://[fd00:ec2::254]/latest',
    'ipv4-mapped metadata' => 'http://[::ffff:169.254.169.254]/latest',
]);

test('the guard refuses a host name that resolves to a metadata address', function () {
    $guard = new HeartbeatUrlGuard(fn (string $host): array => ['169.254.169.254']);

    expect($guard->violation('https://looks-harmless.example.test/ping'))->not->toBeNull();
});

// --- job ---

test('the job sends one GET to the feed url and nothing else', function () {
    heartbeatSetup();
    Http::fake(['hc.example.test/*' => Http::response('OK', 200)]);

    (new SendHeartbeatPing('emwin'))->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://hc.example.test/ping/emwin-uuid');
});

test('the job reads the url when it runs, so a url removed after queueing is not called', function () {
    heartbeatSetup();
    $job = new SendHeartbeatPing('nwws');
    heartbeatSetup(['heartbeat_nwws_url' => null]);

    $job->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    Http::assertNothingSent();
});

test('the job does nothing for an unknown feed or when heartbeat is off', function () {
    heartbeatSetup();
    (new SendHeartbeatPing('bogus'))->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = false;
    $settings->save();
    (new SendHeartbeatPing('nwws'))->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    Http::assertNothingSent();
});

test('the job does not call a url the guard refuses and logs only the feed', function () {
    heartbeatSetup(['heartbeat_nwws_url' => 'http://169.254.169.254/latest/nwws-uuid']);
    Log::spy();

    (new SendHeartbeatPing('nwws'))->handle(new HeartbeatUrlGuard(fn (string $host): array => []));

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => $context === ['feed' => 'nwws']);
});

test('a non-2xx response or a transport error is swallowed and logged without the url', function (Closure $fake) {
    heartbeatSetup();
    Log::spy();
    Http::fake(['hc.example.test/*' => $fake]);

    (new SendHeartbeatPing('emwin_http'))->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []) {
        return $context === ['feed' => 'emwin_http'] && ! str_contains($message.json_encode($context), 'http-uuid');
    });
})->with([
    'server error' => fn () => fn () => Http::response('nope', 500),
    'redirect is not followed and counts as failure' => fn () => fn () => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
    'transport error' => fn () => fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 7 https://hc.example.test/ping/http-uuid'),
]);

test('the failure warning is throttled per feed and the throttle cannot throw', function () {
    heartbeatSetup();
    Log::spy();
    Http::fake(['hc.example.test/*' => Http::response('nope', 500)]);
    $guard = new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']);

    (new SendHeartbeatPing('nwws'))->handle($guard);
    (new SendHeartbeatPing('nwws'))->handle($guard);

    Log::shouldHaveReceived('warning')->once();
});

test('the job builds its request with redirects disabled and a short timeout', function () {
    $job = new SendHeartbeatPing('nwws');
    $request = (function (): PendingRequest {
        return $this->pendingRequest();
    })->call($job);

    $options = (new ReflectionProperty(PendingRequest::class, 'options'))->getValue($request);

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['timeout'])->toBe(5);
});

// --- dispatch action ---

test('the dispatch action queues the job only when the feed has a url and heartbeat is on', function () {
    Bus::fake();

    (app(DispatchHeartbeatPing::class))('nwws');
    Bus::assertNothingDispatched();

    heartbeatSetup();
    (app(DispatchHeartbeatPing::class))('nwws');
    (app(DispatchHeartbeatPing::class))('emwin_http');

    Bus::assertDispatchedTimes(SendHeartbeatPing::class, 2);
    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'nwws');
    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'emwin_http');
});

test('the dispatch action ignores a blank url and an unknown feed', function () {
    Bus::fake();
    heartbeatSetup(['heartbeat_emwin_url' => null]);

    (app(DispatchHeartbeatPing::class))('emwin');
    (app(DispatchHeartbeatPing::class))('bogus');

    Bus::assertNothingDispatched();
});

test('the dispatch action never throws when queueing fails', function () {
    heartbeatSetup();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    $this->instance(Dispatcher::class, $dispatcher);

    expect(fn () => (app(DispatchHeartbeatPing::class))('nwws'))->not->toThrow(Throwable::class);
});
```

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/pest --compact tests/Feature/HeartbeatPingTest.php`. Expected: FAIL (classes missing).

- [ ] **Step 3: Implement.** `app/Support/Delivery/HeartbeatUrlGuard.php`:

```php
<?php

namespace App\Support\Delivery;

use Closure;

/**
 * Outbound safety for the staff-set Healthchecks ping URLs. Unlike WebhookUrlGuard it allows
 * plain http and private hosts (a self-hosted Healthchecks on the private network is the point)
 * and refuses only what could reach a cloud metadata service or a link-local address.
 */
class HeartbeatUrlGuard
{
    private const BLOCKED_HOSTS = ['metadata.google.internal', 'metadata.goog'];

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  returns the IP addresses of a host name; for tests
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /** A human-readable reason the URL must not be called, or null when it is safe. */
    public function violation(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Enter a full http:// or https:// URL.';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'The URL must start with http:// or https://.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL must not contain a username or password.';
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'That host is a cloud metadata service.';
        }

        foreach ($this->addressesFor($host) as $address) {
            if ($this->isBlockedAddress($address)) {
                return 'That address is a link-local or cloud metadata address.';
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function addressesFor(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    private function isBlockedAddress(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        $normalized = strtolower((string) inet_ntop($packed));

        if (strlen($packed) === 4) {
            return str_starts_with($normalized, '169.254.');
        }

        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $normalized, $mapped) === 1) {
            return $this->isBlockedAddress($mapped[1]);
        }

        return $normalized === 'fd00:ec2::254'
            || preg_match('/^fe[89ab][0-9a-f]:/', $normalized) === 1;
    }
}
```

`app/Jobs/SendHeartbeatPing.php` (create with `php artisan make:job SendHeartbeatPing --no-interaction` then fill; check sibling jobs such as `SendActivityAlert.php` for the house style):

```php
<?php

namespace App\Jobs;

use App\Enums\PipelineFeed;
use App\Support\Delivery\HeartbeatUrlGuard;
use App\Support\Integrations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendHeartbeatPing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $feed) {}

    public function handle(HeartbeatUrlGuard $guard): void
    {
        $feed = PipelineFeed::tryFrom($this->feed);
        $url = $feed === null ? null : Integrations::heartbeatUrl($feed);

        if ($url === null) {
            return;
        }

        if ($guard->violation($url) !== null) {
            $this->warnOnce();

            return;
        }

        try {
            $response = $this->pendingRequest()->get($url);

            if (! $response->successful()) {
                $this->warnOnce();
            }
        } catch (Throwable) {
            // The exception message can carry the ping URL, so it is deliberately not logged.
            $this->warnOnce();
        }
    }

    protected function pendingRequest(): PendingRequest
    {
        return Http::timeout(5)->withOptions(['allow_redirects' => false]);
    }

    private function warnOnce(): void
    {
        try {
            if (Cache::add("pipeline.heartbeat-ping-warning.{$this->feed}", true, 300)) {
                Log::warning('Heartbeat ping failed', ['feed' => $this->feed]);
            }
        } catch (Throwable) {
            // A failing cache or log must never fail the job.
        }
    }
}
```

`app/Actions/Pipeline/DispatchHeartbeatPing.php`:

```php
<?php

namespace App\Actions\Pipeline;

use App\Enums\PipelineFeed;
use App\Jobs\SendHeartbeatPing;
use App\Support\Integrations;
use Illuminate\Support\Facades\Bus;
use Throwable;

class DispatchHeartbeatPing
{
    public function __invoke(string $feed): void
    {
        try {
            $pipelineFeed = PipelineFeed::tryFrom($feed);

            if ($pipelineFeed === null || Integrations::heartbeatUrl($pipelineFeed) === null) {
                return;
            }

            Bus::dispatch(new SendHeartbeatPing($feed));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
```

Adjust only what the installed framework forces (e.g. if `Http::timeout(5)->withOptions(...)` stores `timeout` differently in `PendingRequest::$options`, keep the test's intent: both `allow_redirects === false` and a 5-second timeout are set on the request the job sends). The job's `handle()` is injected with the guard by the container; the default container-built guard uses real DNS, so job tests pass a guard with an injected resolver.

- [ ] **Step 4: Run to verify it passes** — `vendor/bin/pest --compact tests/Feature/HeartbeatPingTest.php`. Expected: all pass.

- [ ] **Step 5: Commit** (Pint first; stage the guard, job, action and test; message "Add the heartbeat ping job, dispatch action and URL guard" with the trailer).

---

### Task 4: Ping from the three ingest endpoints, and the rule note

**Files:**
- Modify: `app/Http/Controllers/IngestNwwsProductController.php`
- Modify: `app/Http/Controllers/IngestEmwinProductController.php`
- Test: `tests/Feature/IngestNwwsProductControllerTest.php` (append), `tests/Feature/IngestEmwinProductControllerTest.php` (append)
- Modify: `.ai/rules/support.md` (via the `record-rule` tool)

**Interfaces:**
- Consumes: `DispatchHeartbeatPing::__invoke(string $feed): void` and `SendHeartbeatPing` (Task 3); the existing `RecordPipelineDelivery` call sites and `$feed` variable in `IngestEmwinProductController`.
- Produces: after an accepted delivery each controller queues at most one ping for its feed key (`nwws`; `emwin` for byteblaster; `emwin_http` for feed `http`).

- [ ] **Step 1: Write the failing tests.** Append to `tests/Feature/IngestEmwinProductControllerTest.php` (add imports `use App\Jobs\SendHeartbeatPing;`, `use App\Settings\IntegrationSettings;`, `use Illuminate\Support\Facades\Bus;`, `use Illuminate\Contracts\Bus\Dispatcher;`; it already has `emwinApiPost`, `emwinApiWarning` and the temp-root `beforeEach`):

```php
function enableHeartbeatUrls(): void
{
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = true;
    $settings->heartbeat_nwws_url = 'https://hc.example.test/ping/nwws';
    $settings->heartbeat_emwin_url = 'https://hc.example.test/ping/emwin';
    $settings->heartbeat_emwin_http_url = 'https://hc.example.test/ping/http';
    $settings->save();
}

test('a byteblaster delivery queues a ping for the emwin feed only', function () {
    Bus::fake();
    enableHeartbeatUrls();
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();

    Bus::assertDispatchedTimes(SendHeartbeatPing::class, 1);
    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'emwin');
});

test('an http delivery queues a ping for the emwin_http feed, even when it is a duplicate', function () {
    Bus::fake();
    enableHeartbeatUrls();
    IngestedProduct::factory()->create(['file_path' => 'x/y.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::put($this->emwinRoot.'/delivered/kbou/dup.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/dup.txt', 'http')->assertNoContent();

    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'emwin_http');
    expect(IngestedProduct::count())->toBe(1);
});

test('no ping is queued when heartbeat is off, the feed url is blank, or the path is rejected', function () {
    Bus::fake();
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();
    Bus::assertNothingDispatched();

    enableHeartbeatUrls();
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_emwin_url = null;
    $settings->save();
    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();
    Bus::assertNothingDispatched();

    $settings->heartbeat_emwin_url = 'https://hc.example.test/ping/emwin';
    $settings->save();
    emwinApiPost($this, 'missing.txt')->assertNotFound();
    Bus::assertNothingDispatched();
});

test('a queueing failure never fails the ingest request', function () {
    enableHeartbeatUrls();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    $this->instance(Dispatcher::class, $dispatcher);
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();

    expect(IngestedProduct::count())->toBe(1);
});
```

(If the shared `enableHeartbeatUrls()` helper name would collide with the NWWS test file, name the NWWS copy differently — Pest test files share one PHP process, so global function names must be unique.) Append to `tests/Feature/IngestNwwsProductControllerTest.php` (imports as above; it has the temp `productsRoot` `beforeEach`):

```php
function enableNwwsHeartbeat(): void
{
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = true;
    $settings->heartbeat_nwws_url = 'https://hc.example.test/ping/nwws';
    $settings->save();
}

test('an nwws delivery queues a ping for the nwws feed', function () {
    Bus::fake();
    enableNwwsHeartbeat();
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', "FTUS21 KKCI 221432\nTAFORD\nsample");

    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNoContent();

    Bus::assertDispatchedTimes(SendHeartbeatPing::class, 1);
    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'nwws');
});

test('an nwws delivery queues no ping when heartbeat is off or the path is rejected', function () {
    Bus::fake();
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', "FTUS21 KKCI 221432\nTAFORD\nsample");

    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNoContent();

    enableNwwsHeartbeat();
    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kkci/missing.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNotFound();

    Bus::assertNothingDispatched();
});
```

- [ ] **Step 2: Run to verify they fail** — `vendor/bin/pest --compact tests/Feature/IngestEmwinProductControllerTest.php tests/Feature/IngestNwwsProductControllerTest.php --filter="ping|heartbeat|queue"`. Expected: FAIL (nothing dispatched).

- [ ] **Step 3: Implement.** In both controllers add `use App\Actions\Pipeline\DispatchHeartbeatPing;`, inject `private readonly DispatchHeartbeatPing $dispatchHeartbeatPing` into the constructor, and call it on the line directly after the existing `($this->recordPipelineDelivery)(...)` call:

```php
        ($this->dispatchHeartbeatPing)($feed);      // IngestEmwinProductController — $feed is 'emwin' or 'emwin_http'
        ($this->dispatchHeartbeatPing)('nwws');     // IngestNwwsProductController
```

Change nothing else: not the heartbeat cache call, the delivery recording, the dedup, or the response.

- [ ] **Step 4: Run to verify they pass** — run both controller test files in full, then `tests/Feature/HeartbeatPingTest.php tests/Feature/IntegrationsTabTest.php tests/Feature/IntegrationSettingsTest.php tests/Feature/PipelineDeliveryTest.php`. Expected: all pass.

- [ ] **Step 5: Record the rule** with the `record-rule` tool: glob `app/Support/**`, title "Heartbeat pings", note: "Healthchecks ping URLs (IntegrationSettings heartbeat_*_url) are staff-set and requested from SendHeartbeatPing once per accepted delivery of that feed (including duplicates). The job re-reads the URL at run time, refuses redirects, checks HeartbeatUrlGuard (http/https, no credentials, no link-local/cloud-metadata; private hosts are allowed on purpose), and must never log the URL — it contains the check's UUID. Failures log only the feed key, throttled to one per feed per five minutes. Dispatch is best-effort (DispatchHeartbeatPing never throws) so a ping can never fail an ingest request." Afterwards run `git checkout .ai/rules/index.md` if the tool reordered unrelated rows, and check the tool did not duplicate a section already in `.ai/rules/support.md`.

- [ ] **Step 6: Commit** (Pint first; stage both controllers, both test files and `.ai/rules/support.md`; message "Send a heartbeat ping when an ingest endpoint accepts a product" with the trailer).

---

## Self-Review Notes

- **Spec coverage:** settings + migration + enum + `heartbeatUrl()` (fail open) → Task 1; form (three optional fields, toggle-visible, blank→null, stored values kept when off, other integrations' required behaviour unchanged) → Task 2; job, guard, action, throttled feed-only logging, no redirects, run-time URL read → Task 3; controller wiring for all three feeds, duplicates ping, rejected path no ping, dispatch failure never fails ingest, rule note → Task 4; migration note is a Global Constraint (user runs it).
- **Type consistency:** `heartbeatUrlProperty()`, `Integrations::heartbeatUrl()`, `SendHeartbeatPing::$feed`, `DispatchHeartbeatPing::__invoke(string)`, `HeartbeatUrlGuard::violation(string): ?string` and the feed keys `nwws|emwin|emwin_http` are identical across tasks.
