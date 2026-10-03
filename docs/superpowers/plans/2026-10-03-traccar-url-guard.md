# Traccar URL Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop a subscriber-supplied Traccar server URL from being used to make WxNotify's server (and its Bearer token) reach internal addresses: a guard checked when a server is saved and before every Traccar request, strict in `cloud` mode and LAN-friendly in `selfhost` mode.

**Architecture:** A small `TraccarUrlGuard` composes the two existing guards instead of copying their IP logic: `selfhost` delegates to `HeartbeatUrlGuard` (private addresses allowed, metadata/link-local blocked); `cloud` reuses `WebhookUrlGuard`'s address classification. A validation rule applies it at save time; the three Traccar actions apply it before every request and refuse redirects.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5, Pest, Laravel HTTP client (Guzzle).

**Spec:** `docs/superpowers/specs/2026-10-03-traccar-url-guard-design.md` (binding; read it first).

## Global Constraints

- Mode is read from `config('wxnotify.mode')`: only the exact value `selfhost` relaxes private-address checks; anything else (including missing/unknown) is strict (`cloud`).
- Both modes: URL must parse with a scheme and host; scheme must be `http` or `https`; no username/password in the URL. http is allowed (Traccar's default port is 8082); no port restriction.
- `selfhost` → `HeartbeatUrlGuard::violation()` unchanged (private/LAN allowed; metadata, link-local, numeric-shorthand hosts, zone identifiers blocked). `cloud` → `WebhookUrlGuard::hostRisk()` classification, with TraccarUrlGuard's own fixed wording.
- Guard messages are **fixed strings** that never echo the URL, host or any part of it. Logs on refusal contain only a fixed message and `traccar_server_id`.
- Every Traccar request carries the Bearer token: all of them must refuse redirects.
- A violating server behaves like an unreachable one (actions return null / the map excludes it); no migration, no subscriber notification.
- The DNS-vs-HTTP-client TOCTOU gap is accepted and documented (same as webhooks); no DNS pinning, no port rule, no admin override.
- `WebhookUrlGuard` behavior and its existing tests must not change (only `hostRisk()` visibility).
- Tests never do real DNS or network I/O: guards take a resolver closure; global test bootstrap stubs the Traccar guard (Task 1 step 5).
- Conventions: Pest feature tests; Pint (`vendor/bin/pint --dirty --format agent`) before each commit; commit directly to `main` (no push); commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Read `.ai/rules/index.md` and the rule files covering touched paths (`support.md`, `delivery.md` for the webhook-guard conventions this mirrors, `filament.md` for the form, `feature.md` for tests) before editing.

## How to run tests (read this once)

Run Pest inside the app container (the host cannot reach the DB):

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

Every "Run:" below means that command with the stated files. The full suite takes ~6 minutes and is run by the controller in the background at the end. Pint runs on the host.

## File Structure

| Path | Responsibility |
| --- | --- |
| `app/Support/TraccarUrlGuard.php` | the guard: `violation(string $url): ?string` |
| `app/Rules/SafeTraccarUrl.php` | validation rule wrapping the guard (mirrors `SafeWebhookUrl`) |
| `app/Support/Delivery/WebhookUrlGuard.php` | `hostRisk()` becomes `public` (no behavior change) |
| `tests/Pest.php` | global Feature `beforeEach` binding a stub-resolver guard |
| `app/Filament/Dashboard/Resources/TraccarServers/Schemas/TraccarServerForm.php` | `base_url` gets the rule |
| `app/Actions/Traccar/ListTraccarDevices.php`, `PollTraccarServer.php`, `GetTraccarDeviceMap.php` | request-time check; no redirects |
| `.ai/rules/support.md` | "Traccar URLs" rule |
| `tests/Feature/TraccarUrlGuardTest.php` (new); `TraccarServerResourceTest.php`, `ListTraccarDevicesTest.php`, `PollTraccarServerTest.php`, `GetTraccarDeviceMapTest.php` (extended) | tests |

---

### Task 1: The guard, its rule, and the test bootstrap

**Files:**
- Create: `app/Support/TraccarUrlGuard.php`, `app/Rules/SafeTraccarUrl.php`
- Modify: `app/Support/Delivery/WebhookUrlGuard.php` (`hostRisk` visibility), `tests/Pest.php`
- Test: `tests/Feature/TraccarUrlGuardTest.php`

**Interfaces:**
- Produces (used by Task 2):
  - `new TraccarUrlGuard(?Closure $resolver = null)`; `->violation(string $url): ?string` (fixed-string reason or null).
  - Container-resolvable: `app(TraccarUrlGuard::class)` (tests override with `app()->instance(TraccarUrlGuard::class, ...)`).
  - `new SafeTraccarUrl` (Laravel `ValidationRule`), failing with the guard's message.
  - In every Feature test, `TraccarUrlGuard` resolves to a guard whose resolver returns `['93.184.216.34']` for any hostname (so fake hosts like `traccar.example.com` pass without DNS).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TraccarUrlGuardTest.php`:

```php
<?php

use App\Rules\SafeTraccarUrl;
use App\Support\TraccarUrlGuard;
use Illuminate\Support\Facades\Validator;

function traccarGuard(array $ips = ['93.184.216.34']): TraccarUrlGuard
{
    return new TraccarUrlGuard(fn (string $host): array => $ips);
}

test('cloud allows a public host over http and https, including the default Traccar port', function (string $url) {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard()->violation($url))->toBeNull();
})->with([
    'https' => 'https://traccar.example.com',
    'http' => 'http://traccar.example.com',
    'default port' => 'http://traccar.example.com:8082',
    'path' => 'https://traccar.example.com/traccar',
]);

test('cloud blocks loopback, private, metadata and reserved targets', function (string $url) {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard()->violation($url))->toBeString();
})->with([
    'localhost' => 'http://localhost:8082',
    '127.0.0.1' => 'http://127.0.0.1:8082',
    '10.x' => 'http://10.0.0.5:8082',
    '172.16.x' => 'http://172.16.4.4',
    '192.168.x' => 'http://192.168.1.50:8082',
    'metadata ip' => 'http://169.254.169.254/latest',
    'ipv6 loopback' => 'http://[::1]:8082',
    'ipv4-mapped private' => 'http://[::ffff:10.0.0.1]:8082',
    '.internal name' => 'http://traccar.internal',
    '.lan name' => 'http://traccar.lan',
    '.local name' => 'http://traccar.local',
]);

test('cloud blocks a host name that resolves to a private address, and one that does not resolve', function () {
    config(['wxnotify.mode' => 'cloud']);

    expect(traccarGuard(['10.0.0.5'])->violation('https://traccar.example.com'))->toBeString()
        ->and(traccarGuard([])->violation('https://traccar.example.com'))->toBeString();
});

test('both modes reject credentials, non-http schemes and malformed URLs', function (string $mode, string $url) {
    config(['wxnotify.mode' => $mode]);

    expect(traccarGuard()->violation($url))->toBeString();
})->with(['cloud', 'selfhost'])->with([
    'userinfo' => 'https://user:pass@traccar.example.com',
    'ftp' => 'ftp://traccar.example.com',
    'file' => 'file:///etc/passwd',
    'no host' => 'https:///api',
    'not a url' => 'nonsense',
]);

test('selfhost allows private and LAN targets', function (string $url) {
    config(['wxnotify.mode' => 'selfhost']);

    expect(traccarGuard(['10.0.0.5'])->violation($url))->toBeNull();
})->with([
    'private ip' => 'http://192.168.1.50:8082',
    'localhost' => 'http://localhost:8082',
    'docker name' => 'http://traccar:8082',
    'private-resolving host' => 'https://traccar.home.example.com',
]);

test('selfhost still blocks metadata and link-local targets', function (string $url) {
    config(['wxnotify.mode' => 'selfhost']);

    expect(traccarGuard()->violation($url))->toBeString();
})->with([
    'metadata ip' => 'http://169.254.169.254/latest',
    'link-local' => 'http://169.254.10.10',
    'google metadata' => 'http://metadata.google.internal',
    'ipv6 link-local' => 'http://[fe80::1]',
]);

test('an unknown or missing mode is treated as cloud', function () {
    config(['wxnotify.mode' => 'staging']);
    expect(traccarGuard()->violation('http://192.168.1.50:8082'))->toBeString();

    config(['wxnotify.mode' => null]);
    expect(traccarGuard()->violation('http://192.168.1.50:8082'))->toBeString();
});

test('no violation message repeats the URL or host', function () {
    config(['wxnotify.mode' => 'cloud']);

    foreach (['http://secret-host.example.com:8082', 'http://10.99.88.77:8082', 'https://u:p@secret-host.example.com'] as $url) {
        $message = traccarGuard(['10.99.88.77'])->violation($url);

        expect($message)->toBeString()
            ->not->toContain('secret-host')
            ->not->toContain('10.99.88.77');
    }
});

test('the default container binding is stubbed so fake hosts never hit DNS', function () {
    config(['wxnotify.mode' => 'cloud']);

    expect(app(TraccarUrlGuard::class)->violation('https://traccar.example.com'))->toBeNull();
});

test('the validation rule reports the guard message', function () {
    config(['wxnotify.mode' => 'cloud']);
    app()->instance(TraccarUrlGuard::class, traccarGuard(['10.0.0.5']));

    $validator = Validator::make(['url' => 'https://traccar.example.com'], ['url' => [new SafeTraccarUrl]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('url'))->toContain('private');
});

test('the validation rule passes a safe URL', function () {
    config(['wxnotify.mode' => 'cloud']);

    $validator = Validator::make(['url' => 'https://traccar.example.com'], ['url' => [new SafeTraccarUrl]]);

    expect($validator->passes())->toBeTrue();
});
```

(`localhost` and `traccar` host names in the selfhost case go through `HeartbeatUrlGuard`, which resolves via the closure; `traccarGuard(['10.0.0.5'])` is a private-resolving stub. The `.internal`/`.lan`/`.local` cloud cases are caught by `WebhookUrlGuard`'s name suffix list before any lookup. If `http://[::ffff:10.0.0.1]` does not survive `parse_url`'s host handling the way the other IP literals do, keep the case but give it the form `WebhookUrlGuardTest` uses for the same address.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/TraccarUrlGuardTest.php`
Expected: FAIL — `App\Support\TraccarUrlGuard` / `App\Rules\SafeTraccarUrl` not found.

- [ ] **Step 3: Make `WebhookUrlGuard::hostRisk()` public**

In `app/Support/Delivery/WebhookUrlGuard.php` change `private function hostRisk(string $host): ?string` to `public function hostRisk(string $host): ?string`. Leave its body and docblock behavior intact; extend the docblock with one sentence: callers outside this class (TraccarUrlGuard) use it for the shared IP classification and supply their own wording.

- [ ] **Step 4: The guard and the rule**

`app/Support/TraccarUrlGuard.php`:

```php
<?php

namespace App\Support;

use App\Support\Delivery\HeartbeatUrlGuard;
use App\Support\Delivery\WebhookUrlGuard;
use Closure;

/**
 * Outbound safety for subscriber-supplied Traccar server URLs. Every Traccar
 * request carries the server's Bearer token, so a URL aimed at an internal
 * address would both probe the network and hand the token to whatever answers.
 *
 * Strict by default (the hosted cloud product): no loopback, private, reserved
 * or metadata targets. Only mode `selfhost` relaxes that, because a Traccar on
 * the same network as WxNotify is the expected self-hosted setup: it then defers
 * to HeartbeatUrlGuard, which allows private hosts and refuses only cloud-metadata
 * and link-local targets. Both modes require http(s) and forbid credentials in
 * the URL. Messages are fixed strings and never repeat any part of the URL.
 *
 * The guard's DNS lookup and the HTTP client's own lookup are separate (the same
 * accepted TOCTOU gap as WebhookUrlGuard).
 */
final class TraccarUrlGuard
{
    private readonly HeartbeatUrlGuard $heartbeat;

    private readonly WebhookUrlGuard $webhook;

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  returns the IP addresses of a host name; for tests
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->heartbeat = new HeartbeatUrlGuard($resolver);
        $this->webhook = new WebhookUrlGuard($resolver);
    }

    /** A human-readable reason the URL must not be called, or null when it is safe. */
    public function violation(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'Enter a full http:// or https:// URL.';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'The URL must start with http:// or https://.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL must not contain a username or password.';
        }

        if (config('wxnotify.mode') === 'selfhost') {
            return $this->heartbeat->violation($url);
        }

        return match ($this->webhook->hostRisk($parts['host'])) {
            'name' => 'That host name points at a private network address.',
            'unresolved' => 'That host name could not be resolved.',
            'private' => 'That address resolves to a private or reserved network, which is not allowed.',
            default => null,
        };
    }
}
```

`app/Rules/SafeTraccarUrl.php`:

```php
<?php

namespace App\Rules;

use App\Support\TraccarUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeTraccarUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $violation = app(TraccarUrlGuard::class)->violation((string) $value);

        if ($violation !== null) {
            $fail($violation);
        }
    }
}
```

- [ ] **Step 5: Global test bootstrap**

Fake hosts like `traccar.example.com` do not resolve in DNS, so the strict guard would reject them in every existing Traccar test. In `tests/Pest.php`, extend the existing Feature binding:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => app()->instance(
        TraccarUrlGuard::class,
        new TraccarUrlGuard(fn (string $host): array => ['93.184.216.34']),
    ))
    ->in('Feature');
```

(+ `use App\Support\TraccarUrlGuard;`.) Tests that exercise blocking replace the binding with `app()->instance(TraccarUrlGuard::class, ...)` or construct their own guard; mode is controlled with `config(['wxnotify.mode' => ...])`. Keep the existing `->extend(...)->use(...)->in('Feature')` call a single chain.

- [ ] **Step 6: Run to verify it passes**

Run: `tests/Feature/TraccarUrlGuardTest.php tests/Feature/WebhookUrlGuardTest.php tests/Feature/HeartbeatUrlGuardTest.php`
(use whichever heartbeat-guard test file exists: `ls tests/Feature | grep -i heartbeat`)
Expected: all PASS (the webhook/heartbeat suites unchanged).

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/TraccarUrlGuard.php app/Rules/SafeTraccarUrl.php app/Support/Delivery/WebhookUrlGuard.php tests/Pest.php tests/Feature/TraccarUrlGuardTest.php
git commit -m "Add TraccarUrlGuard: strict in cloud, LAN-friendly in selfhost

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Apply the guard at save time and before every Traccar request

**Files:**
- Modify: `app/Filament/Dashboard/Resources/TraccarServers/Schemas/TraccarServerForm.php`, `app/Actions/Traccar/ListTraccarDevices.php`, `app/Actions/Traccar/PollTraccarServer.php`, `app/Actions/Traccar/GetTraccarDeviceMap.php`, `.ai/rules/support.md`
- Test: extend `tests/Feature/TraccarServerResourceTest.php`, `ListTraccarDevicesTest.php`, `PollTraccarServerTest.php`, `GetTraccarDeviceMapTest.php`

**Interfaces:**
- Consumes: `TraccarUrlGuard::violation()`, container binding, `SafeTraccarUrl` (Task 1).
- Produces: a violating `base_url` is rejected at save, and every Traccar request is pre-checked and refuses redirects.

Read `.ai/rules/filament.md` before editing the form.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ListTraccarDevicesTest.php` (add `use App\Support\TraccarUrlGuard;`):

```php
test('a server whose URL violates the guard is never called and resolves to null', function () {
    config(['wxnotify.mode' => 'cloud']);
    app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(fn (string $host): array => ['10.0.0.5']));
    $server = TraccarServer::factory()->create(['base_url' => 'http://traccar.internal.example.com:8082']);

    Http::fake();

    expect((new ListTraccarDevices)($server))->toBeNull();
    Http::assertNothingSent();
});

test('a redirect is not followed and resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake([
        'traccar.example.com/api/devices' => Http::response('', 302, ['Location' => 'https://elsewhere.example.com/api/devices']),
        'elsewhere.example.com/*' => Http::response([['id' => 1, 'name' => 'Leaked']]),
    ]);

    expect((new ListTraccarDevices)($server))->toBeNull();
    Http::assertSentCount(1);
});
```

Append the equivalent two tests to `tests/Feature/PollTraccarServerTest.php` against `/api/positions` (redirect target response: `[['deviceId' => 1, 'latitude' => 39.0, 'longitude' => -94.0]]`), using `PollTraccarServer`.

Append to `tests/Feature/GetTraccarDeviceMapTest.php` (uses the file's `mapServer()`, `fakeTraccar()`, `traccarPosition()` helpers and its pinned clock):

```php
test('a server whose URL violates the guard is excluded without affecting the others', function () {
    config(['wxnotify.mode' => 'cloud']);
    app()->instance(TraccarUrlGuard::class, new TraccarUrlGuard(
        fn (string $host): array => $host === 'internal.example.com' ? ['10.0.0.5'] : ['93.184.216.34'],
    ));
    $bad = mapServer('Internal', 'internal.example.com');
    $good = mapServer('Fleet', 'fleet.example.com');
    Http::fake(fakeTraccar('fleet.example.com', [['id' => 1, 'name' => 'Truck 1', 'lastUpdate' => '2026-10-02T11:55:00Z']], [traccarPosition(1)]));

    $result = (new GetTraccarDeviceMap)([$bad, $good]);

    expect(collect($result['devices'])->pluck('server')->unique()->all())->toBe(['Fleet']);
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'internal.example.com'));
});
```

(+ `use App\Support\TraccarUrlGuard;`. Check how the existing map tests assert an unreachable server's result shape and mirror it if `$result` carries a per-server failure marker.)

Append to `tests/Feature/TraccarServerResourceTest.php` (its existing tests show the Plus-tier user setup and `Http::fake` of `/api/positions`; `Pages\CreateTraccarServer`/`EditTraccarServer` are already imported; add `use App\Support\TraccarUrlGuard;`):

```php
test('a Traccar server pointing at a private address is rejected in cloud mode', function () {
    config(['wxnotify.mode' => 'cloud']);
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake();

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Internal', 'base_url' => 'http://192.168.1.50:8082', 'api_token' => 'tok'])
        ->call('create')
        ->assertHasFormErrors(['base_url']);

    expect(TraccarServer::count())->toBe(0);
    Http::assertNothingSent();
});

test('a Traccar server pointing at a private address is accepted in selfhost mode', function () {
    config(['wxnotify.mode' => 'selfhost']);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['192.168.1.50:8082/api/positions' => Http::response([])]);

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'LAN', 'base_url' => 'http://192.168.1.50:8082', 'api_token' => 'tok'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TraccarServer::where('user_id', $user->id)->where('label', 'LAN')->exists())->toBeTrue();
});

test('the metadata address is rejected even in selfhost mode', function () {
    config(['wxnotify.mode' => 'selfhost']);
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Bad', 'base_url' => 'http://169.254.169.254', 'api_token' => 'tok'])
        ->call('create')
        ->assertHasFormErrors(['base_url']);
});

test('editing a server to a private address is rejected in cloud mode', function () {
    config(['wxnotify.mode' => 'cloud']);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    $server = TraccarServer::factory()->for($user)->create(['base_url' => 'https://traccar.example.com']);

    livewire(EditTraccarServer::class, ['record' => $server->id])
        ->fillForm(['base_url' => 'http://10.0.0.5:8082'])
        ->call('save')
        ->assertHasFormErrors(['base_url']);

    expect($server->fresh()->base_url)->toBe('https://traccar.example.com');
});
```

(Follow the existing "an existing server can be edited" test for how the edit page is opened and saved — including any Http fake it needs — and keep it in step. In the selfhost test, if creation also issues the devices call, fake it as well.)

- [ ] **Step 2: Run to verify they fail**

Run: `tests/Feature/ListTraccarDevicesTest.php tests/Feature/PollTraccarServerTest.php tests/Feature/GetTraccarDeviceMapTest.php tests/Feature/TraccarServerResourceTest.php`
Expected: the new guard tests FAIL; every pre-existing test passes (the global stub makes fake hosts valid).

- [ ] **Step 3: Save-time rule**

In `TraccarServerForm::fields()` add `->rule(new SafeTraccarUrl)` to the `base_url` TextInput (after `->url()`), + `use App\Rules\SafeTraccarUrl;`. Update the placeholder/helper only if needed to mention that http is allowed (do not add copy that reveals which ranges are blocked).

- [ ] **Step 4: Request-time checks and no-redirects**

`ListTraccarDevices::__invoke` and `PollTraccarServer::__invoke` — before the existing `try`, add:

```php
        if (app(TraccarUrlGuard::class)->violation((string) $server->base_url) !== null) {
            Log::warning('Traccar server URL refused', ['traccar_server_id' => $server->id]);

            return null;
        }
```

(+ `use App\Support\TraccarUrlGuard;`) and add `->withoutRedirecting()` to the `Http::withToken(...)->timeout(10)` chain before `->get(...)` in both. `GetTraccarDeviceMap::fetch()` — in the per-server pre-flight loop, treat a violating server like a misconfigured one:

```php
        foreach ($servers as $server) {
            if (! $this->isConfigured($server)) {
                Log::warning('Traccar device map: server misconfigured', ['traccar_server_id' => $server->id]);
                $results[$server->id] = null;
            } elseif (app(TraccarUrlGuard::class)->violation((string) $server->base_url) !== null) {
                Log::warning('Traccar server URL refused', ['traccar_server_id' => $server->id]);
                $results[$server->id] = null;
            } else {
                $pooled[] = $server;
            }
        }
```

(+ import; the pool requests already pass `'allow_redirects' => false` — leave that.)

Prove the redirect tests bite: temporarily remove `->withoutRedirecting()` from `ListTraccarDevices`, show "a redirect is not followed" fails (the faked `Location` target is then fetched and the devices list returned); restore it. If the test cannot be made to fail because `Http::fake` does not follow redirects, report DONE_WITH_CONCERNS, keep the null-on-302 assertions, and say so.

- [ ] **Step 5: Record the rule**

Append to `.ai/rules/support.md`, in the file's style:

```markdown
## Traccar URLs must pass TraccarUrlGuard at save and before every request, and never follow redirects
A Traccar server's `base_url` is subscriber-controlled and every Traccar request carries its Bearer token, so `App\Support\TraccarUrlGuard` must pass both when the server is saved (`SafeTraccarUrl` on `TraccarServerForm`) and before each outbound call (`ListTraccarDevices`, `PollTraccarServer`, `GetTraccarDeviceMap`); a refused URL behaves like an unreachable server (null / excluded), logged with a fixed message and the server id only. Every Traccar request must also refuse redirects (`withoutRedirecting()` / `allow_redirects => false`). Cloud is strict (no loopback/private/reserved/metadata, http allowed); only `WXNOTIFY_MODE=selfhost` allows private/LAN hosts, by delegating to HeartbeatUrlGuard (metadata and link-local stay blocked in both modes); an unknown mode is strict. Guard messages are fixed strings and never echo the URL. The guard's DNS lookup and the HTTP client's are separate (same accepted TOCTOU gap as webhooks; CURLOPT_RESOLVE pinning is the follow-up). Any new Traccar call must go through the guard. In tests, `tests/Pest.php` binds a stub-resolver TraccarUrlGuard for every Feature test so fake hosts never hit DNS; override it with `app()->instance(TraccarUrlGuard::class, ...)` to test blocking.
```

- [ ] **Step 6: Run to verify they pass, plus every Traccar suite**

Run: `tests/Feature/ListTraccarDevicesTest.php tests/Feature/PollTraccarServerTest.php tests/Feature/GetTraccarDeviceMapTest.php tests/Feature/TraccarServerResourceTest.php tests/Feature/PollTraccarPositionsTest.php tests/Feature/TraccarDevicesMapWidgetTest.php tests/Feature/UserTraccarLimitsTest.php tests/Feature/LocationResourceTest.php tests/Feature/TraccarUrlGuardTest.php`
Expected: all PASS.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Dashboard/Resources/TraccarServers/Schemas/TraccarServerForm.php app/Actions/Traccar/ListTraccarDevices.php app/Actions/Traccar/PollTraccarServer.php app/Actions/Traccar/GetTraccarDeviceMap.php .ai/rules/support.md tests/Feature/TraccarServerResourceTest.php tests/Feature/ListTraccarDevicesTest.php tests/Feature/PollTraccarServerTest.php tests/Feature/GetTraccarDeviceMapTest.php
git commit -m "Apply TraccarUrlGuard when saving servers and before every Traccar request

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Post-implementation verification (controller, in a real browser)

1. Create a throwaway Plus-tier subscriber (random test password), log in at the local app (dev `WXNOTIFY_MODE` is `cloud` unless `.env` says otherwise — check `php artisan config:show wxnotify.mode` in the container).
2. Traccar Servers → create: `http://localhost:8082` and `http://192.168.1.50:8082` are rejected with the guard's message under the URL field and nothing is saved; no network call is made.
3. A public-looking URL (e.g. `https://demo.traccar.org` with a dummy token) passes the URL validation (it will then fail the poll with the existing "could not poll" message, which is expected and unrelated).
4. Delete the throwaway user and any rows afterwards; reset the viewport. (The selfhost branch is covered by the feature tests; switching the container's mode is not required for the browser check.)

---

## Self-Review (against the spec)

**Coverage:** mode-dependent guard composing Heartbeat/Webhook guards, fixed messages, `hostRisk()` public (T1); `SafeTraccarUrl` rule on the shared `fields()` for Create and Edit (T2); request-time check in all three actions, violating map server excluded without affecting others (T2); redirects refused on List and Poll, map already did (T2); unknown mode strict (T1); no-echo messages (T1); global test stub so fake hosts don't hit DNS (T1); webhook tests unchanged (T1 step 6); rule recorded (T2); browser check listed; non-goals untouched.

**Placeholder scan:** none. A few test bodies tell the implementer to mirror an existing test's setup (edit-page open/save, map result shape) with the differing data given — deliberate, because that setup is file-specific.

**Type consistency:** `TraccarUrlGuard::violation(string): ?string`, `new TraccarUrlGuard(?Closure)`, `SafeTraccarUrl`, and `app(TraccarUrlGuard::class)` are used identically in T1 and T2; `WebhookUrlGuard::hostRisk(string): ?string` returns `'name'|'unresolved'|'private'|null` as mapped in the guard.

**Known trap (recorded in the rule):** the strict guard rejects unresolvable hosts, so any test using a made-up Traccar host depends on the Pest.php stub binding.
