# Mobile App Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A subscriber signs in to the NativePHP app through the real WxNotify login (browser handoff + Sanctum token) and sees an offline-capable Recent alerts feed with a detail screen, on a new versioned `/api/v1`.

**Architecture:** Backend (this repo, `app/`, `routes/`): Sanctum app tokens (ability `app`, sliding 90-day expiry), a PKCE-style authorize/exchange handoff, `/api/v1/{me,alerts,auth/token}`, and a "Signed-in devices" dashboard page. App (`mobile/`): a thin `WxNotifyApi` client over `Http`, a Secure-Storage token store, a SQLite cache for offline, and native EDGE screens (Sign in, Alerts, Alert detail, Account).

**Tech Stack:** Laravel 13 / PHP 8.4, Sanctum 4, Filament 5, Pest 5, Postgres 18 (backend); NativePHP Mobile v4 (SuperNative, EDGE), SQLite, Pest (app).

**Spec:** `docs/superpowers/specs/2026-10-04-mobile-app-foundation-design.md`. The plan argues from the spec; where the plan deviates, the deviation is recorded under "Rulings" below and the spec is patched in Task 2.

## Global Constraints

Every task's requirements implicitly include this section.

- Backend: PHP 8.4, Laravel 13, Pest feature tests under `tests/Feature/` (flat, like the existing files). Follow `.ai/rules/*` (index: `.ai/rules/index.md`; read the rule files whose globs cover the paths you touch). Run `vendor/bin/pint --dirty --format agent` on the host before each commit.
- Run backend tests in the app container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact <file>'`. (A full-suite run is the controller's job, in parallel with 6 processes.)
- Run app tests on the host from `mobile/`: `cd mobile && vendor/bin/pest --compact <file>`.
- Never build or launch the app yourself (`native:run`, `native:jump`, …): the controller tells the user to.
- Do not add or change Composer/npm dependencies. (`laravel/sanctum` is already required by the uncommitted `composer.json` change; Task 1 commits it.)
- `mobile/` UI is **native only**: `NativeComponent` + EDGE elements + Tailwind classes, theme tokens (`bg-theme-*`, `text-theme-*`), `native:icon`. No web-view/Livewire/Inertia screens, no inline `style`, no emoji in UI text. Before using an EDGE element, open its class in `mobile/vendor/nativephp/mobile/src/Edge/Elements/` and use only props it defines.
- **Never expose** `error_detail`, a channel destination/URL, or any token in an API response, an activity-log entry or a log line.
- App tokens carry **only** the ability `app`. API routes accept bearer tokens only (never a dashboard session cookie).
- Exact values from the spec: authorize `redirect` must equal `wxnotify://auth/callback`; `state` is `[A-Za-z0-9_-]{16,128}`; `challenge` is 43 base64url characters (S256); `device_name` 1-80 characters; code = 64 random characters, held 120 s, single-use; token expiry sliding 90 days, extended at most once a day; rate limits: exchange 10/min per IP, API 120/min per token, authorize page 20/min per user; feed `limit` 1-50 (default 25); the app caches the latest 50 feed entries.
- Commit messages end with: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Commit to `main`; never push.

## Pre-flight (controller, before Task 1)

`bootstrap/app.php` carries an **uncommitted edit that is not part of this work**: `$middleware->trustProxies(at: '*');` (added by the owner, likely for local development behind a proxy or tunnel). Tasks 1 and 2 also edit that file, so the controller must settle it with the owner first: either the owner commits it (or reverts it) before Task 1 starts, or the implementers stage only their own hunks. Never let a task commit that line by accident. Note the security effect: trusting every proxy makes `$request->ip()` spoofable through `X-Forwarded-For`, which weakens the per-IP limits in this plan (`app-auth`, the authorize page's guest fallback) and conflicts with the app's opt-in `App\Support\ClientIp` design (`WXNOTIFY_TRUSTED_PROXIES`); it should not reach production.

## Rulings (plan vs spec)

- **Callback uses path segments, not a query string.** NativePHP hands a deep link to a native route, and a route's `param()` values come only from the URI *path*; query strings are not exposed. So the server redirects to `wxnotify://auth/callback/{code}/{state}` (and `wxnotify://auth/denied/{state}` on Cancel) instead of `…?code=…&state=…`. The authorize `redirect` parameter and `callback_uri` config stay `wxnotify://auth/callback`; a new `denied_uri` config is `wxnotify://auth/denied`. Cost if wrong: none for security (PKCE and `state` are unchanged); the spec text is patched in Task 2.
- **Resources keep Laravel's `data` wrapper.** `/me` returns `{"data": {...}}`; the token response's `user` is the unwrapped array. The spec's field lists are unchanged.
- **API sessions are bearer-only.** `config/sanctum.php` `guard` is set to `[]` so a logged-in dashboard session cannot call `/api/v1` (the spec says "app tokens only"; a Sanctum default would also accept the session).
- **Two additive feed fields.** List entries and channels also carry `category_label` and `type_label` so the app never needs the label registries (spec fields are all still present).
- **Offline banner wording.** "Showing alerts from {time}" renders the time in the subscriber's timezone when `/me` has one, otherwise as a relative time ("12 minutes ago"); the app cannot assume the device timezone.
- **Consent pages are self-contained HTML** (one small Blade view with inline CSS and dark-mode support), not Filament-layout pages, because the routes sit outside the Filament panel.

---

## Task 1: Sanctum foundation, `/api/v1/me`, sign-out, sliding expiry

**Files:**
- Modify: `composer.json`, `composer.lock`, `config/sanctum.php`, `config/wxnotify.php`, `app/Models/User.php`, `bootstrap/app.php`, `routes/api.php`, `app/Providers/AppServiceProvider.php`, `.ai/rules/index.md`
- Create: `database/migrations/2026_10_04_214259_create_personal_access_tokens_table.php` (already generated, uncommitted), `app/Http/Controllers/Api/V1/MeController.php`, `app/Http/Controllers/Api/V1/AppTokenController.php`, `app/Http/Middleware/ExtendAppTokenExpiry.php`, `app/Http/Resources/Api/V1/MeResource.php`, `.ai/rules/api.md`
- Test: `tests/Feature/ApiMeTest.php`

**Interfaces:**
- Produces: `GET /api/v1/me` → `{"data": {id, name, email, tier: {value, label}, unit_system, timezone}}`; `DELETE /api/v1/auth/token` → 204; route group `api/v1` guarded by `auth:sanctum`, `ExtendAppTokenExpiry`, `ability:app`, `throttle:app-api`; named limiters `app-api`, `app-auth`; config `wxnotify.app_auth.{callback_uri,denied_uri,code_ttl_seconds,token_days}`; `App\Http\Resources\Api\V1\MeResource`; `AppTokenController::destroy` (Task 2 adds `store`).

- [ ] **Step 1: Read the rules and confirm the generated Sanctum files**

Read `.ai/rules/index.md` and every rule file whose globs cover `app/Http/**`, `app/Models/**`, `app/Support/**`. Then confirm: `git status --short` shows `composer.json`, `composer.lock`, `config/sanctum.php` and the `personal_access_tokens` migration as the only changes, and `config/sanctum.php` has `'expiration' => null` (leave it: expiry is driven only by each token's own `expires_at`, so a sliding window works).

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/ApiMeTest.php`:

```php
<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Pat Smith', 'timezone' => 'America/Chicago']);
});

test('an app token reads the subscriber summary', function () {
    $token = $this->user->createToken('iPhone', ['app'], now()->addDays(90))->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $this->user->id,
            'name' => 'Pat Smith',
            'email' => $this->user->email,
            'tier' => ['value' => 'free', 'label' => 'Free'],
            'unit_system' => 'imperial',
            'timezone' => 'America/Chicago',
        ]]);
});

test('a request without a token is refused with JSON', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

test('a token without the app ability is refused', function () {
    $token = $this->user->createToken('My script', ['api'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
});

test('an expired token is refused', function () {
    $token = $this->user->createToken('iPhone', ['app'], now()->subMinute())->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

test('a dashboard session does not authenticate the API', function () {
    $this->actingAs($this->user)->getJson('/api/v1/me')->assertUnauthorized();
});

test('signing out revokes only the calling token', function () {
    $calling = $this->user->createToken('This phone', ['app']);
    $this->user->createToken('Other phone', ['app']);

    $this->withToken($calling->plainTextToken)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect($this->user->tokens()->pluck('name')->all())->toBe(['Other phone']);
});

test('a token last extended over a day ago gets a fresh 90 days', function () {
    $token = $this->user->createToken('iPhone', ['app'], now()->addDays(80));

    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();

    expect($token->accessToken->fresh()->expires_at->toDateString())->toBe(now()->addDays(90)->toDateString());
});

test('a token extended today is not rewritten', function () {
    $expires = now()->addDays(90)->subHours(2)->startOfSecond();
    $token = $this->user->createToken('iPhone', ['app'], $expires);

    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();

    expect($token->accessToken->fresh()->expires_at->toDateTimeString())->toBe($expires->toDateTimeString());
});

test('the API is limited to 120 requests a minute per token', function () {
    $token = $this->user->createToken('iPhone', ['app'])->plainTextToken;

    foreach (range(1, 120) as $_) {
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(429);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/ApiMeTest.php'`
Expected: FAIL (`createToken` undefined on `User` / 404 for `/api/v1/me`).

- [ ] **Step 4: Implement**

`app/Models/User.php`: add `use Laravel\Sanctum\HasApiTokens;` and add `HasApiTokens` to the class's `use` list (`use HasAnnouncements, HasApiTokens, HasFactory, InteractsWithAppAuthentication, LogsActivity, Notifiable;`).

`config/sanctum.php`: set `'guard' => [],` (bearer tokens only; keep the existing explanatory comment and add: "Empty on purpose: the dashboard's web session must not authenticate the mobile API.").

`config/wxnotify.php`: add before the closing `];`:

```php
    /*
    |--------------------------------------------------------------------------
    | Mobile app sign-in
    |--------------------------------------------------------------------------
    |
    | callback_uri / denied_uri  The deep links the browser handoff returns to;
    |                            the code and state are appended as path segments.
    | code_ttl_seconds           How long a single-use authorization code lives.
    | token_days                 Sliding lifetime of an app token.
    |
    */
    'app_auth' => [
        'callback_uri' => 'wxnotify://auth/callback',
        'denied_uri' => 'wxnotify://auth/denied',
        'code_ttl_seconds' => 120,
        'token_days' => 90,
    ],
```

`bootstrap/app.php`: add imports `use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;` and extend the alias call:

```php
        $middleware->alias([
            'pipeline.webhook' => VerifyPipelineWebhookToken::class,
            'ability' => CheckForAnyAbility::class,
        ]);
```

`app/Providers/AppServiceProvider.php` `boot()`: add (with `use Illuminate\Cache\RateLimiting\Limit; use Illuminate\Http\Request; use Illuminate\Support\Facades\RateLimiter;`):

```php
        RateLimiter::for('app-api', fn (Request $request) => Limit::perMinute(120)
            ->by('app-api:'.($request->user()?->currentAccessToken()?->id ?? $request->ip())));

        RateLimiter::for('app-auth', fn (Request $request) => Limit::perMinute(10)
            ->by('app-auth:'.$request->ip()));
```

`app/Http/Middleware/ExtendAppTokenExpiry.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an app token alive for as long as it is used: each authenticated request pushes
 * its expiry back to the full lifetime, but only once a day (at most one write per token
 * per day), so an unused phone's token still lapses after `token_days`.
 */
class ExtendAppTokenExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->expires_at !== null) {
            $target = now()->addDays((int) config('wxnotify.app_auth.token_days'));

            if ($token->expires_at->lt($target->copy()->subDay())) {
                $token->forceFill(['expires_at' => $target])->save();
            }
        }

        return $next($request);
    }
}
```

`app/Http/Resources/Api/V1/MeResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tier = $this->tier();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'tier' => ['value' => $tier->value, 'label' => ucfirst($tier->value)],
            'unit_system' => Units::for($this->resource)->system->value,
            'timezone' => $this->timezone,
        ];
    }
}
```

`app/Http/Controllers/Api/V1/MeController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): MeResource
    {
        return new MeResource($request->user());
    }
}
```

`app/Http/Controllers/Api/V1/AppTokenController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AppTokenController extends Controller
{
    /**
     * Sign out: revokes the token that made this request, and only that one.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
```

`routes/api.php`: add imports (`AppTokenController`, `MeController`, `ExtendAppTokenExpiry`) and append:

```php
Route::prefix('v1')->group(function (): void {
    Route::middleware(['auth:sanctum', ExtendAppTokenExpiry::class, 'ability:app', 'throttle:app-api'])->group(function (): void {
        Route::get('/me', MeController::class)->name('api.v1.me');
        Route::delete('/auth/token', [AppTokenController::class, 'destroy'])->name('api.v1.auth.token.destroy');
    });
});
```

Create `.ai/rules/api.md`:

```markdown
---
paths:
  - 'routes/api.php'
  - 'app/Http/Controllers/Api/**'
  - 'app/Http/Resources/**'
---

# API

## The mobile API is versioned, bearer-only and scoped to the token's user
Routes live under `/api/v1` in `routes/api.php` and return Eloquent API Resources (Laravel's default `data` wrapper). They authenticate with `auth:sanctum` and `config/sanctum.php` has `guard => []`, so a dashboard session cookie never reaches them. App tokens carry only the ability `app` (`ability:app`) and must never be accepted as, or confused with, the Public API's personal keys (a later, Plus/Pro-only feature with its own abilities). Every query is scoped to `$request->user()`; another user's id is a 404. Responses never include `error_detail`, a channel destination or URL, or any token.

## The sign-in handoff code is single-use and consumed before it is verified
`POST /api/v1/auth/token` pulls the authorization code from the cache first (under a lock), then checks the PKCE verifier, so a wrong verifier still burns the code and a replay always fails. Every failure answers with the same generic `invalid_grant`. The token is named from the device name stored with the code, never from the request. The deep-link callback carries `code` and `state` as URI path segments, because NativePHP route params come only from the path.
```

`.ai/rules/index.md`: add a row `| routes/api.php, app/Http/Controllers/Api/**, app/Http/Resources/** | .ai/rules/api.md |` to the table.

- [ ] **Step 5: Run the tests to verify they pass**

Run the Step 3 command. Expected: all 9 PASS. If the throttle test is slow (> 30 s) report it rather than lowering the limit.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock config/sanctum.php config/wxnotify.php app/Models/User.php bootstrap/app.php routes/api.php app/Providers/AppServiceProvider.php app/Http database/migrations/2026_10_04_214259_create_personal_access_tokens_table.php tests/Feature/ApiMeTest.php .ai/rules/api.md .ai/rules/index.md
git commit -m "Add Sanctum app tokens, /api/v1/me and sign-out with sliding expiry

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 2: Sign-in handoff (authorize pages + token exchange)

**Files:**
- Create: `app/Http/Controllers/AppAuthorizationController.php`, `app/Http/Requests/AuthorizeAppRequest.php`, `app/Http/Requests/ExchangeAppTokenRequest.php`, `app/Actions/AppAuth/IssueAppAuthorizationCode.php`, `app/Actions/AppAuth/ExchangeAppAuthorizationCode.php`, `resources/views/app-auth/page.blade.php`
- Modify: `routes/web.php`, `routes/api.php`, `app/Http/Controllers/Api/V1/AppTokenController.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `docs/superpowers/specs/2026-10-04-mobile-app-foundation-design.md`
- Test: `tests/Feature/AppAuthorizationTest.php`, `tests/Feature/AppTokenExchangeTest.php`

**Interfaces:**
- Consumes (Task 1): `MeResource`, config `wxnotify.app_auth.*`, limiter `app-auth`, `AppTokenController`.
- Produces: `GET|POST /app-auth/authorize` (route names `app-auth.authorize`, `app-auth.authorize.store`); `POST /api/v1/auth/token` (`code`, `code_verifier`) → `{token, expires_at, user}`; `IssueAppAuthorizationCode::__invoke(User $user, string $challenge, string $deviceName): string` and static `IssueAppAuthorizationCode::key(string $code): string`; `ExchangeAppAuthorizationCode::__invoke(string $code, string $verifier): ?array{token: string, expires_at: CarbonInterface, user: User}`; activity events `app-signed-in`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AppAuthorizationTest.php`:

```php
<?php

use App\Models\User;
use STS\FilamentImpersonate\ImpersonateManager;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->verifier = str_repeat('v', 43);
    $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    $this->query = [
        'challenge' => $this->challenge,
        'state' => str_repeat('s', 32),
        'device_name' => 'iPhone 16',
        'redirect' => 'wxnotify://auth/callback',
    ];
});

test('a guest is sent to the dashboard login and returned to the authorize page afterwards', function () {
    $url = '/app-auth/authorize?'.http_build_query($this->query);

    $this->get($url)->assertRedirect(route('filament.dashboard.auth.login'));

    expect(session('url.intended'))->toBe(url($url));
});

test('the consent page names the device and the account, escaped', function () {
    $this->actingAs($this->user)
        ->get('/app-auth/authorize?'.http_build_query([...$this->query, 'device_name' => '<script>alert(1)</script>']))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee($this->user->email);
});

test('malformed parameters are rejected', function (string $key, string $value) {
    $this->actingAs($this->user)
        ->get('/app-auth/authorize?'.http_build_query([...$this->query, $key => $value]))
        ->assertStatus(422);
})->with([
    'short challenge' => ['challenge', 'abc'],
    'bad state characters' => ['state', str_repeat('!', 20)],
    'short state' => ['state', 'short'],
    'empty device' => ['device_name', ''],
    'long device' => ['device_name', str_repeat('d', 81)],
    'control characters in device' => ['device_name', "bad\x07name"],
    'wrong redirect' => ['redirect', 'https://evil.example/callback'],
]);

test('an unverified subscriber is sent to email verification', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/app-auth/authorize?'.http_build_query($this->query))
        ->assertRedirect(route('filament.dashboard.auth.email-verification.prompt'));
});

test('staff impersonating a subscriber are refused and no code is issued', function () {
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));

    $this->actingAs($this->user)->get('/app-auth/authorize?'.http_build_query($this->query))->assertForbidden();
    $this->actingAs($this->user)->post('/app-auth/authorize', [...$this->query, 'decision' => 'allow'])->assertForbidden();
});

test('Allow redirects to the app with a code and the state in the path', function () {
    $response = $this->actingAs($this->user)
        ->post('/app-auth/authorize', [...$this->query, 'decision' => 'allow'])
        ->assertRedirect();

    expect($response->headers->get('Location'))->toMatch('#^wxnotify://auth/callback/[A-Za-z0-9]{64}/'.preg_quote($this->query['state'], '#').'$#');
});

test('Cancel redirects to the app as denied, with the state, and issues no code', function () {
    $this->actingAs($this->user)
        ->post('/app-auth/authorize', [...$this->query, 'decision' => 'deny'])
        ->assertRedirect('wxnotify://auth/denied/'.$this->query['state']);
});

test('the decision must be allow or deny', function () {
    $this->actingAs($this->user)->post('/app-auth/authorize', [...$this->query, 'decision' => 'maybe'])->assertStatus(422);
});
```

`tests/Feature/AppTokenExchangeTest.php`:

```php
<?php

use App\Actions\AppAuth\IssueAppAuthorizationCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->verifier = str_repeat('v', 43);
    $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    $this->code = (new IssueAppAuthorizationCode)($this->user, $this->challenge, 'iPhone 16');
});

test('the code and verifier are exchanged once for an app token', function () {
    $response = $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertOk()
        ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'tier', 'unit_system', 'timezone']]);

    $token = $this->user->tokens()->sole();

    expect($token->name)->toBe('iPhone 16')
        ->and($token->abilities)->toBe(['app'])
        ->and($token->expires_at->toDateString())->toBe(now()->addDays(90)->toDateString())
        ->and($response->json('user.id'))->toBe($this->user->id);

    $this->withToken($response->json('token'))->getJson('/api/v1/me')->assertOk();
});

test('a replayed code fails', function () {
    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])->assertOk();

    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertStatus(422)->assertJson(['error' => 'invalid_grant']);
});

test('a wrong verifier fails and also burns the code', function () {
    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => str_repeat('x', 43)])
        ->assertStatus(422)->assertJson(['error' => 'invalid_grant']);

    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertStatus(422)->assertJson(['error' => 'invalid_grant']);

    expect($this->user->tokens()->count())->toBe(0);
});

test('an expired code fails', function () {
    $this->travel(121)->seconds();

    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertStatus(422)->assertJson(['error' => 'invalid_grant']);
});

test('an unknown or malformed code fails with the same generic error', function (array $body) {
    $this->postJson('/api/v1/auth/token', $body)
        ->assertStatus(422)->assertExactJson(['error' => 'invalid_grant', 'message' => 'The sign-in could not be completed. Start again from the app.']);
})->with([
    'unknown code' => [['code' => str_repeat('a', 64), 'code_verifier' => str_repeat('v', 43)]],
    'short code' => [['code' => 'abc', 'code_verifier' => str_repeat('v', 43)]],
    'no verifier' => [['code' => str_repeat('a', 64)]],
    'nothing' => [[]],
]);

test('a subscriber whose email is no longer verified cannot exchange', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();

    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])->assertStatus(422);
    expect($this->user->tokens()->count())->toBe(0);
});

test('the token is named from the stored device name, not the request', function () {
    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier, 'device_name' => 'Spoofed'])->assertOk();

    expect($this->user->tokens()->sole()->name)->toBe('iPhone 16');
});

test('a successful exchange is recorded without the token', function () {
    $response = $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])->assertOk();

    $entry = Activity::where('event', 'app-signed-in')->sole();

    expect($entry->causer_id)->toBe($this->user->id)
        ->and($entry->properties->get('device'))->toBe('iPhone 16')
        ->and(json_encode($entry->toArray()))->not->toContain(explode('|', $response->json('token'))[1]);
});

test('the exchange is limited to 10 requests a minute per IP', function () {
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/auth/token', ['code' => str_repeat('a', 64), 'code_verifier' => str_repeat('v', 43)])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/token', ['code' => str_repeat('a', 64), 'code_verifier' => str_repeat('v', 43)])->assertStatus(429);
});

test('the code is stored only as a hash', function () {
    expect(Cache::get('app-auth-code:'.$this->code))->toBeNull()
        ->and(Cache::get(IssueAppAuthorizationCode::key($this->code)))->toBeArray();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run both files with the container command from Global Constraints (one `pest --compact` per file). Expected: FAIL (404s / missing classes).

- [ ] **Step 3: Implement the actions**

`app/Actions/AppAuth/IssueAppAuthorizationCode.php`:

```php
<?php

namespace App\Actions\AppAuth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mints the single-use code the mobile app trades for a token. The cache holds it under the
 * SHA-256 of the code (a leaked cache dump cannot be replayed) for a couple of minutes,
 * together with the PKCE challenge and the device name the subscriber just approved.
 */
class IssueAppAuthorizationCode
{
    public function __invoke(User $user, string $challenge, string $deviceName): string
    {
        $code = Str::random(64);

        Cache::put(
            self::key($code),
            ['user_id' => $user->id, 'challenge' => $challenge, 'device_name' => $deviceName],
            now()->addSeconds((int) config('wxnotify.app_auth.code_ttl_seconds')),
        );

        return $code;
    }

    public static function key(string $code): string
    {
        return 'app-auth-code:'.hash('sha256', $code);
    }
}
```

`app/Actions/AppAuth/ExchangeAppAuthorizationCode.php`:

```php
<?php

namespace App\Actions\AppAuth;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Trades an authorization code plus its PKCE verifier for an app token. The code is consumed
 * first, under a lock, so any attempt (right or wrong) burns it and two concurrent requests
 * cannot both succeed; every failure returns null, and the caller answers them all alike.
 */
class ExchangeAppAuthorizationCode
{
    /**
     * @return array{token: string, expires_at: CarbonInterface, user: User}|null
     */
    public function __invoke(string $code, string $verifier): ?array
    {
        $key = IssueAppAuthorizationCode::key($code);

        $result = Cache::lock($key.':lock', 5)->get(function () use ($key, $verifier): ?array {
            $grant = Cache::pull($key);

            if (! is_array($grant)) {
                return null;
            }

            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            if (! hash_equals($grant['challenge'], $expected)) {
                return null;
            }

            $user = User::find($grant['user_id']);

            if ($user === null || ! $user->hasVerifiedEmail()) {
                return null;
            }

            $expiresAt = now()->addDays((int) config('wxnotify.app_auth.token_days'));
            $token = $user->createToken($grant['device_name'], ['app'], $expiresAt);

            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->event('app-signed-in')
                ->withProperties(['device' => $grant['device_name']])
                ->log('Signed in to the WxNotify app');

            return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt, 'user' => $user];
        });

        return is_array($result) ? $result : null;
    }
}
```

- [ ] **Step 4: Implement the requests, controllers, view and routes**

`app/Http/Requests/AuthorizeAppRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AuthorizeAppRequest extends FormRequest
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
        $rules = [
            'challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'state' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,128}$/'],
            'device_name' => ['required', 'string', 'min:1', 'max:80', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'redirect' => ['required', 'string', Rule::in([config('wxnotify.app_auth.callback_uri')])],
        ];

        if ($this->isMethod('POST')) {
            $rules['decision'] = ['required', Rule::in(['allow', 'deny'])];
        }

        return $rules;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->view('app-auth.page', [
            'title' => 'This sign-in link is not valid',
            'message' => 'Go back to the WxNotify app and try signing in again.',
        ], 422));
    }
}
```

`app/Http/Requests/ExchangeAppTokenRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ExchangeAppTokenRequest extends FormRequest
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
            'code' => ['required', 'string', 'size:64'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
        ];
    }

    /**
     * A malformed request gets the same answer as a wrong code, so the response never says which check failed.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(AppTokenController::invalidGrant());
    }
}
```
(import `App\Http\Controllers\Api\V1\AppTokenController`.)

`app/Http/Controllers/Api/V1/AppTokenController.php`: add `store` and the shared response; full file:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AppAuth\ExchangeAppAuthorizationCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeAppTokenRequest;
use App\Http\Resources\Api\V1\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AppTokenController extends Controller
{
    /**
     * Trade the handoff's authorization code and PKCE verifier for an app token.
     */
    public function store(ExchangeAppTokenRequest $request, ExchangeAppAuthorizationCode $exchange): JsonResponse
    {
        $result = $exchange($request->validated('code'), $request->validated('code_verifier'));

        if ($result === null) {
            return self::invalidGrant();
        }

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'user' => (new MeResource($result['user']))->resolve(),
        ]);
    }

    /**
     * Sign out: revokes the token that made this request, and only that one.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public static function invalidGrant(): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_grant',
            'message' => 'The sign-in could not be completed. Start again from the app.',
        ], 422);
    }
}
```

`app/Http/Controllers/AppAuthorizationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Actions\AppAuth\IssueAppAuthorizationCode;
use App\Http\Requests\AuthorizeAppRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use STS\FilamentImpersonate\ImpersonateManager;

/**
 * The browser half of the mobile sign-in handoff: after the subscriber has signed in the
 * normal way, ask them to approve the app, then send a single-use code back to it.
 */
class AppAuthorizationController extends Controller
{
    public function show(AuthorizeAppRequest $request): View|RedirectResponse|Response
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        return view('app-auth.page', [
            'title' => 'Sign in to the WxNotify app',
            'form' => [
                'device' => $request->validated('device_name'),
                'email' => $request->user()->email,
                'fields' => $request->safe()->only(['challenge', 'state', 'device_name', 'redirect']),
            ],
        ]);
    }

    public function store(AuthorizeAppRequest $request, IssueAppAuthorizationCode $issue): RedirectResponse|Response
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $state = $request->validated('state');

        if ($request->validated('decision') === 'deny') {
            return redirect()->away(config('wxnotify.app_auth.denied_uri').'/'.$state);
        }

        $code = $issue($request->user(), $request->validated('challenge'), $request->validated('device_name'));

        return redirect()->away(config('wxnotify.app_auth.callback_uri').'/'.$code.'/'.$state);
    }

    private function refusal(AuthorizeAppRequest $request): RedirectResponse|Response|null
    {
        if (app(ImpersonateManager::class)->isImpersonating()) {
            return response()->view('app-auth.page', [
                'title' => 'Sign-in is unavailable',
                'message' => 'Not available while impersonating a subscriber.',
            ], 403);
        }

        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('filament.dashboard.auth.email-verification.prompt');
        }

        return null;
    }
}
```

Note: `AuthorizeAppRequest` validation runs before the impersonation check; for the impersonation test the parameters are valid, so a 403 is reached. Keep that order.

`resources/views/app-auth/page.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} - {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f4f5f7; --card: #fff; --text: #18181b; --muted: #52525b; --primary: #2563eb; --border: #e4e4e7; }
        @media (prefers-color-scheme: dark) { :root { --bg: #09090b; --card: #18181b; --text: #fafafa; --muted: #a1a1aa; --primary: #3b82f6; --border: #27272a; } }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, sans-serif; }
        main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p { margin: 0 0 20px; color: var(--muted); }
        strong { color: var(--text); word-break: break-word; }
        .actions { display: flex; gap: 12px; }
        button { flex: 1; font: inherit; font-weight: 600; padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: transparent; color: var(--text); cursor: pointer; }
        button.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
    </style>
</head>
<body>
<main>
    <h1>{{ $title }}</h1>
    @isset($form)
        <p>Sign the WxNotify app on <strong>{{ $form['device'] }}</strong> in as <strong>{{ $form['email'] }}</strong>?</p>
        <form method="POST" action="{{ route('app-auth.authorize.store') }}">
            @csrf
            @foreach ($form['fields'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <div class="actions">
                <button type="submit" name="decision" value="deny">Cancel</button>
                <button type="submit" name="decision" value="allow" class="primary">Allow</button>
            </div>
        </form>
    @else
        <p>{{ $message }}</p>
    @endisset
</main>
</body>
</html>
```

`routes/web.php`: add `use App\Http\Controllers\AppAuthorizationController;` and:

```php
Route::middleware(['auth', 'throttle:app-authorize'])->prefix('app-auth')->group(function () {
    Route::get('/authorize', [AppAuthorizationController::class, 'show'])->name('app-auth.authorize');
    Route::post('/authorize', [AppAuthorizationController::class, 'store'])->name('app-auth.authorize.store');
});
```

`routes/api.php`: add, inside `Route::prefix('v1')` but outside the authenticated group:

```php
    Route::post('/auth/token', [AppTokenController::class, 'store'])
        ->middleware('throttle:app-auth')
        ->name('api.v1.auth.token.store');
```

`bootstrap/app.php`, inside `->withMiddleware(...)`: guests of web `auth` routes go to the dashboard login (the default `route('login')` does not exist), JSON callers keep their 401:

```php
        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : route('filament.dashboard.auth.login'));
```

`app/Providers/AppServiceProvider.php` `boot()`: add

```php
        RateLimiter::for('app-authorize', fn (Request $request) => Limit::perMinute(20)
            ->by('app-authorize:'.($request->user()?->id ?? $request->ip())));
```

- [ ] **Step 5: Run the tests to verify they pass**

Run both test files. Expected: all PASS. If the guest-redirect test fails because `session('url.intended')` is empty, the redirect must go through `redirect()->guest()` (Laravel's `Authenticate` does this): do not replace it with a bare `redirect()`.

- [ ] **Step 6: Patch the spec to match the rulings**

Edit `docs/superpowers/specs/2026-10-04-mobile-app-foundation-design.md`:
- Config bullet: replace `redirect_uri` (`wxnotify://auth/callback`) with `callback_uri` (`wxnotify://auth/callback`) and `denied_uri` (`wxnotify://auth/denied`).
- Handoff step 2: replace "redirects to `wxnotify://auth/callback?code=…&state=…`; **Cancel** redirects with `error=access_denied&state=…`" with "redirects to `wxnotify://auth/callback/{code}/{state}`; **Cancel** redirects to `wxnotify://auth/denied/{state}`".
- App sign-in flow step 3: replace the callback description with: "A native route `/auth/callback/{code}/{state}` receives the deep link (NativePHP route params come only from the URI path, which is why the code and state travel as path segments). … `/auth/denied/{state}` returns quietly to Sign in."
- Risks: replace the "Callback delivery" bullet with "Callback delivery: confirm on both iOS and Android that `Browser::auth()` delivers `wxnotify://auth/callback/{code}/{state}` to the native route with both path parameters."
- Feed section: add the two additive fields (`category_label`, channel `type_label`) to the list-entry description.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app resources/views/app-auth routes bootstrap/app.php tests/Feature/AppAuthorizationTest.php tests/Feature/AppTokenExchangeTest.php docs/superpowers/specs/2026-10-04-mobile-app-foundation-design.md
git commit -m "Add the browser sign-in handoff: authorize pages and PKCE token exchange

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 3: Alerts feed API

**Files:**
- Create: `app/Support/AppAlert.php`, `app/Support/AppAlertCursor.php`, `app/Actions/Alerts/ListAppAlerts.php`, `app/Actions/Alerts/FindAppAlert.php`, `app/Http/Requests/ListAppAlertsRequest.php`, `app/Http/Resources/Api/V1/AlertResource.php`, `app/Http/Resources/Api/V1/AlertDetailResource.php`, `app/Http/Controllers/Api/V1/AlertController.php`, `database/migrations/<timestamp>_add_user_dispatched_index_to_delivery_logs_table.php` (via `php artisan make:migration`)
- Modify: `routes/api.php`
- Test: `tests/Feature/ApiAlertsTest.php`

**Interfaces:**
- Consumes (Task 1): the `auth:sanctum` + `ability:app` + `throttle:app-api` group.
- Produces: `GET /api/v1/alerts?limit&before` → `{data: [entry…], meta: {next_cursor}}`; `GET /api/v1/alerts/{id}` → `{data: detail}`. Entry: `{id, subject, category, category_label, dispatched_at, channels: [{type, type_label, status}], has_failure}`; detail adds `rule_name`, `product_url`, and channel `label`. `AppAlert` (rows of one alert, ordered by id; `id()` = lowest row id), `ListAppAlerts::__invoke(User, int $limit = 25, ?string $before = null): array{alerts: Collection<int, AppAlert>, next_cursor: ?string}`, `FindAppAlert::__invoke(User, int $id): ?AppAlert`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ApiAlertsTest.php`:

```php
<?php

use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;
    $this->product = IngestedProduct::factory()->create();
    $this->rule = AlertRule::factory()->for($this->user)->create(['name' => 'Storm warnings']);

    $this->log = fn (array $overrides = []) => DeliveryLog::factory()->for($this->user)->create([
        'alert_rule_id' => $this->rule->id,
        'ingested_product_id' => $this->product->id,
        'category' => 'weather_alerts',
        'subject' => 'Tornado Warning',
        'channel_type' => 'discord',
        'status' => 'success',
        ...$overrides,
    ]);
});

test('deliveries of one rule and product collapse into one alert listing its channels', function () {
    ($this->log)(['channel_type' => 'discord', 'dispatched_at' => now()->subMinutes(5)]);
    ($this->log)(['channel_type' => 'pushover', 'dispatched_at' => now()->subMinutes(4), 'status' => 'failure', 'error_detail' => 'SECRET-DETAIL']);

    $response = $this->withToken($this->token)->getJson('/api/v1/alerts')->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'Tornado Warning')
        ->assertJsonPath('data.0.category', 'weather_alerts')
        ->assertJsonPath('data.0.category_label', 'Weather Alerts')
        ->assertJsonPath('data.0.has_failure', true)
        ->assertJsonPath('data.0.channels.0', ['type' => 'discord', 'type_label' => 'Discord', 'status' => 'success'])
        ->assertJsonPath('data.0.channels.1', ['type' => 'pushover', 'type_label' => 'Pushover', 'status' => 'failure'])
        ->assertJsonPath('meta.next_cursor', null);

    expect($response->getContent())->not->toContain('SECRET-DETAIL');
});

test('the alert id is the lowest delivery id and its time the latest', function () {
    $first = ($this->log)(['dispatched_at' => now()->subMinutes(5)]);
    ($this->log)(['dispatched_at' => now()->subMinutes(1)]);

    $this->withToken($this->token)->getJson('/api/v1/alerts')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.0.dispatched_at', now()->subMinutes(1)->toIso8601String());
});

test('deliveries with no product stay separate alerts', function () {
    ($this->log)(['ingested_product_id' => null, 'category' => 'daily_summary', 'subject' => 'Daily summary 1', 'dispatched_at' => now()->subMinutes(3)]);
    ($this->log)(['ingested_product_id' => null, 'category' => 'daily_summary', 'subject' => 'Daily summary 2', 'dispatched_at' => now()->subMinutes(2)]);

    $this->withToken($this->token)->getJson('/api/v1/alerts')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.subject', 'Daily summary 2');
});

test('a different rule on the same product is its own alert', function () {
    $other = AlertRule::factory()->for($this->user)->create();
    ($this->log)(['dispatched_at' => now()->subMinutes(3)]);
    ($this->log)(['alert_rule_id' => $other->id, 'dispatched_at' => now()->subMinutes(2)]);

    $this->withToken($this->token)->getJson('/api/v1/alerts')->assertJsonCount(2, 'data');
});

test('alerts are newest first and paginate by cursor without gaps or repeats, even with equal times', function () {
    $same = now()->subMinutes(10)->startOfSecond();
    $ids = collect(range(1, 5))->map(fn ($i) => ($this->log)([
        'ingested_product_id' => IngestedProduct::factory()->create()->id,
        'subject' => "Alert {$i}",
        'dispatched_at' => $i <= 3 ? $same : now()->subMinutes(10 - $i),
    ])->id);

    $seen = [];
    $cursor = null;

    do {
        $page = $this->withToken($this->token)->getJson('/api/v1/alerts?limit=2'.($cursor ? '&before='.$cursor : ''))->assertOk();
        $seen = [...$seen, ...collect($page->json('data'))->pluck('id')->all()];
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($seen)->toHaveCount(5)->and(array_unique($seen))->toHaveCount(5)
        ->and($seen[0])->toBe($ids[4])
        ->and($seen[1])->toBe($ids[3]);
});

test('limit is validated and an unreadable cursor is rejected', function (string $query) {
    $this->withToken($this->token)->getJson('/api/v1/alerts?'.$query)->assertStatus(422);
})->with(['limit 0' => ['limit=0'], 'limit 51' => ['limit=51'], 'bad cursor' => ['before=not-a-cursor']]);

test('only the subscriber\'s own alerts are listed', function () {
    ($this->log)();
    DeliveryLog::factory()->for(User::factory()->create())->create(['subject' => 'Not mine']);

    $this->withToken($this->token)->getJson('/api/v1/alerts')
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['subject' => 'Not mine']);
});

test('an older row without a subject falls back to the rule name, then to Alert', function () {
    ($this->log)(['subject' => null, 'dispatched_at' => now()->subMinutes(2)]);
    ($this->log)(['subject' => null, 'alert_rule_id' => null, 'ingested_product_id' => null, 'dispatched_at' => now()->subMinute()]);

    $data = $this->withToken($this->token)->getJson('/api/v1/alerts')->json('data');

    expect($data[0]['subject'])->toBe('Alert')->and($data[1]['subject'])->toBe('Storm warnings');
});

test('the detail adds the rule, the channel labels and the product page, and never an error detail', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['channel_type' => 'discord', 'label' => 'Team Discord']);
    $log = ($this->log)(['notification_channel_id' => $channel->id, 'status' => 'failure', 'error_detail' => 'SECRET-DETAIL']);

    $response = $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")->assertOk();

    $response->assertJsonPath('data.id', $log->id)
        ->assertJsonPath('data.rule_name', 'Storm warnings')
        ->assertJsonPath('data.product_url', $this->product->viewUrl())
        ->assertJsonPath('data.channels.0.label', 'Team Discord')
        ->assertJsonPath('data.channels.0.status', 'failure');

    expect($response->getContent())->not->toContain('SECRET-DETAIL');
});

test('the detail tolerates a deleted rule and channel', function () {
    $log = ($this->log)(['alert_rule_id' => null, 'notification_channel_id' => null, 'ingested_product_id' => null]);

    $this->withToken($this->token)->getJson("/api/v1/alerts/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.rule_name', null)
        ->assertJsonPath('data.product_url', null)
        ->assertJsonPath('data.channels.0.label', null);
});

test('another subscriber\'s alert and an unknown id are not found', function () {
    $theirs = DeliveryLog::factory()->for(User::factory()->create())->create();

    $this->withToken($this->token)->getJson("/api/v1/alerts/{$theirs->id}")->assertNotFound();
    $this->withToken($this->token)->getJson('/api/v1/alerts/999999')->assertNotFound();
});

test('the tier does not matter', function () {
    ($this->log)();

    $this->withToken($this->token)->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Container command on `tests/Feature/ApiAlertsTest.php`. Expected: FAIL (404 for `/api/v1/alerts`). If a factory call fails because a factory needs required columns, adjust the test's factory arguments (not the feature code) and say so in your report.

- [ ] **Step 3: Implement**

`php artisan make:migration add_user_dispatched_index_to_delivery_logs_table --no-interaction` (run it in the container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && php artisan make:migration add_user_dispatched_index_to_delivery_logs_table --no-interaction'`), then:

```php
    public function up(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->index(['user_id', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'dispatched_at']);
        });
    }
```

`app/Support/AppAlertCursor.php`:

```php
<?php

namespace App\Support;

/**
 * An opaque pagination cursor for the app's alert feed: the sort time and id of the last
 * entry on the previous page, base64url-encoded JSON.
 */
final class AppAlertCursor
{
    public static function encode(string $at, int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['at' => $at, 'id' => $id])), '+/', '-_'), '=');
    }

    /**
     * @return array{at: string, id: int}|null null when the cursor cannot be read
     */
    public static function decode(string $cursor): ?array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data) || ! is_string($data['at'] ?? null) || ! is_int($data['id'] ?? null) || strtotime($data['at']) === false) {
            return null;
        }

        return ['at' => $data['at'], 'id' => $data['id']];
    }
}
```

`app/Support/AppAlert.php`:

```php
<?php

namespace App\Support;

use App\Models\DeliveryLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One alert as the app sees it: every delivery row of one subscriber for one rule and one
 * product (a row with no product is an alert on its own). The id is the lowest row id, so it
 * stays the same when a later delivery joins the group.
 */
final class AppAlert
{
    /**
     * @param  Collection<int, DeliveryLog>  $rows  ordered by id, never empty
     */
    public function __construct(public readonly Collection $rows) {}

    public function id(): int
    {
        return $this->rows->first()->id;
    }

    public function first(): DeliveryLog
    {
        return $this->rows->first();
    }

    public function latestAt(): CarbonInterface
    {
        return $this->rows->sortBy('dispatched_at')->last()->dispatched_at;
    }

    public function hasFailure(): bool
    {
        return $this->rows->contains('status', 'failure');
    }

    /**
     * The grouping rule, mirrored in SQL by ListAppAlerts::GROUP_KEY.
     */
    public static function keyFor(DeliveryLog $row): string
    {
        return $row->ingested_product_id === null
            ? 'row-'.$row->id
            : ($row->alert_rule_id ?? 'none').'-'.$row->ingested_product_id;
    }
}
```

`app/Actions/Alerts/ListAppAlerts.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;
use App\Support\AppAlert;
use App\Support\AppAlertCursor;
use Illuminate\Support\Collection;

/**
 * A page of the subscriber's alerts for the mobile app, newest first. Deliveries of one rule
 * and product collapse into one alert; ordering is by the group's latest delivery time, then
 * its id, and the cursor carries both so pages are stable when times tie.
 */
class ListAppAlerts
{
    /** Mirrors AppAlert::keyFor(). */
    public const GROUP_KEY = "CASE WHEN ingested_product_id IS NULL THEN 'row-' || id ELSE COALESCE(alert_rule_id::text, 'none') || '-' || ingested_product_id END";

    /**
     * @return array{alerts: Collection<int, AppAlert>, next_cursor: ?string}
     */
    public function __invoke(User $user, int $limit = 25, ?string $before = null): array
    {
        $cursor = $before === null ? null : AppAlertCursor::decode($before);

        $groups = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->selectRaw('MIN(id) AS entry_id, MAX(dispatched_at) AS sort_at')
            ->groupByRaw(self::GROUP_KEY)
            ->when($cursor, fn ($query) => $query->havingRaw('(MAX(dispatched_at), MIN(id)) < (?, ?)', [$cursor['at'], $cursor['id']]))
            ->orderByDesc('sort_at')
            ->orderByDesc('entry_id')
            ->limit($limit + 1)
            ->toBase()
            ->get();

        $page = $groups->take($limit);
        $last = $page->last();

        return [
            'alerts' => $this->assemble($user, $page),
            'next_cursor' => $groups->count() > $limit && $last !== null
                ? AppAlertCursor::encode($last->sort_at, (int) $last->entry_id)
                : null,
        ];
    }

    /**
     * @param  Collection<int, object{entry_id: int}>  $page
     * @return Collection<int, AppAlert>
     */
    private function assemble(User $user, Collection $page): Collection
    {
        if ($page->isEmpty()) {
            return collect();
        }

        $entryIds = $page->pluck('entry_id');

        $anchors = DeliveryLog::query()->where('user_id', $user->id)->whereIn('id', $entryIds)->get()->keyBy('id');
        $productIds = $anchors->pluck('ingested_product_id')->filter()->unique();

        $byKey = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereIn('id', $entryIds)->orWhereIn('ingested_product_id', $productIds))
            ->with(['alertRule.products', 'alertRule.location', 'notificationChannel', 'ingestedProduct'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (DeliveryLog $row) => AppAlert::keyFor($row));

        return $page
            ->map(fn (object $group) => new AppAlert($byKey[AppAlert::keyFor($anchors[$group->entry_id])]->values()))
            ->values();
    }
}
```

`app/Actions/Alerts/FindAppAlert.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;
use App\Support\AppAlert;

/**
 * One of the subscriber's alerts for the mobile app, by the id the feed gave out, or null
 * when it is unknown or belongs to someone else.
 */
class FindAppAlert
{
    public function __invoke(User $user, int $id): ?AppAlert
    {
        $anchor = DeliveryLog::query()->where('user_id', $user->id)->find($id);

        if ($anchor === null) {
            return null;
        }

        $rows = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->when(
                $anchor->ingested_product_id === null,
                fn ($query) => $query->whereKey($anchor->id),
                fn ($query) => $query
                    ->where('ingested_product_id', $anchor->ingested_product_id)
                    ->where('alert_rule_id', $anchor->alert_rule_id),
            )
            ->with(['alertRule.products', 'alertRule.location', 'notificationChannel', 'ingestedProduct'])
            ->orderBy('id')
            ->get();

        return new AppAlert($rows);
    }
}
```

Known edge: for an anchor with a null `alert_rule_id` (rule deleted) and a product, `where('alert_rule_id', null)` becomes `IS NULL` in Eloquent, which matches the grouping rule. Keep it.

`app/Http/Requests/ListAppAlertsRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Support\AppAlertCursor;
use Illuminate\Foundation\Http\FormRequest;

class ListAppAlertsRequest extends FormRequest
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
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'before' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (AppAlertCursor::decode((string) $value) === null) {
                    $fail('The cursor is not valid.');
                }
            }],
        ];
    }
}
```

`app/Http/Resources/Api/V1/AlertResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AlertCategory;
use App\Models\DeliveryLog;
use App\Support\AppAlert;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One feed entry. Never carries error_detail, a destination or a URL secret.
 *
 * @property AppAlert $resource
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $first = $this->resource->first();
        $category = AlertCategory::tryFrom((string) $first->category);

        return [
            'id' => $this->resource->id(),
            'subject' => $first->subject
                ?? $first->alertRule?->displayName(Units::for($request->user())->system)
                ?? 'Alert',
            'category' => $first->category,
            'category_label' => $category?->label() ?? $first->category,
            'dispatched_at' => $this->resource->latestAt()->toIso8601String(),
            'channels' => $this->resource->rows->map(fn (DeliveryLog $row) => $this->channel($row))->values()->all(),
            'has_failure' => $this->resource->hasFailure(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function channel(DeliveryLog $row): array
    {
        return [
            'type' => $row->channel_type,
            'type_label' => app(ChannelTypeRegistry::class)->find($row->channel_type)?->label() ?? $row->channel_type,
            'status' => $row->status,
        ];
    }
}
```

`app/Http/Resources/Api/V1/AlertDetailResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DeliveryLog;
use App\Support\Units;
use Illuminate\Http\Request;

/**
 * An alert's detail: the feed entry plus the rule, each channel's own label, and the public
 * product page when there is one.
 */
class AlertDetailResource extends AlertResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $first = $this->resource->first();

        return [
            ...parent::toArray($request),
            'rule_name' => $first->alertRule?->displayName(Units::for($request->user())->system),
            'product_url' => $first->ingestedProduct?->viewUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function channel(DeliveryLog $row): array
    {
        return [...parent::channel($row), 'label' => $row->notificationChannel?->label];
    }
}
```

`app/Http/Controllers/Api/V1/AlertController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\FindAppAlert;
use App\Actions\Alerts\ListAppAlerts;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListAppAlertsRequest;
use App\Http\Resources\Api\V1\AlertDetailResource;
use App\Http\Resources\Api\V1\AlertResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController extends Controller
{
    public function index(ListAppAlertsRequest $request, ListAppAlerts $list): AnonymousResourceCollection
    {
        $page = $list($request->user(), (int) $request->validated('limit', 25), $request->validated('before'));

        return AlertResource::collection($page['alerts'])
            ->additional(['meta' => ['next_cursor' => $page['next_cursor']]]);
    }

    public function show(Request $request, int $id, FindAppAlert $find): AlertDetailResource
    {
        return new AlertDetailResource($find($request->user(), $id) ?? abort(404));
    }
}
```

`routes/api.php`: inside the authenticated group add:

```php
        Route::get('/alerts', [AlertController::class, 'index'])->name('api.v1.alerts.index');
        Route::get('/alerts/{id}', [AlertController::class, 'show'])->whereNumber('id')->name('api.v1.alerts.show');
```

- [ ] **Step 4: Run the tests to verify they pass**

Run `tests/Feature/ApiAlertsTest.php` in the container. Expected: all PASS. If the cursor test shows a repeated or missing id with equal timestamps, the cause is the `HAVING` row comparison or the `ORDER BY`: fix those, do not loosen the test. The tests run on Postgres (the SQL is Postgres-specific on purpose).

- [ ] **Step 5: Check the grouped query on a realistic table**

In the container, using the test database or a throwaway local database (not the dev data), seed 50,000 `delivery_logs` rows for one user (e.g. a one-off `DB::table('delivery_logs')->insert(...)` in `php artisan tinker --execute`) and run `EXPLAIN ANALYZE` on the first-page query from `ListAppAlerts`. Record the timing in your report; if the first page takes over 150 ms, say so and propose a fix instead of shipping it. Delete the seeded rows afterwards.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app database/migrations routes/api.php tests/Feature/ApiAlertsTest.php
git commit -m "Add the mobile alerts feed API: grouped alerts, cursor pagination and detail

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 4: "Signed-in devices" dashboard page

**Files:**
- Create: `app/Filament/Dashboard/Pages/SignedInDevices.php`, `resources/views/filament/dashboard/pages/signed-in-devices.blade.php`
- Modify: `.ai/rules/filament.md`
- Test: `tests/Feature/SignedInDevicesPageTest.php`

**Interfaces:**
- Consumes (Task 2): app tokens are `PersonalAccessToken` rows with `abilities` exactly `["app"]`, named for the device.
- Produces: Dashboard page `SignedInDevices` (slug `signed-in-devices`) with a table action `revoke`; activity event `revoked` on the user, properties `{device}`.

- [ ] **Step 1: Read the Filament rules**

Read `.ai/rules/filament.md` and `.ai/rules/pages.md` (the page rules: scope to the user in the query, never trust the panel gate, impersonation).

- [ ] **Step 2: Write the failing tests**

`tests/Feature/SignedInDevicesPageTest.php`:

```php
<?php

use App\Filament\Dashboard\Pages\SignedInDevices;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;
use STS\FilamentImpersonate\ImpersonateManager;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('only the subscriber\'s own app devices are listed', function () {
    $mine = $this->user->createToken('iPhone 16', ['app'])->accessToken;
    $apiKey = $this->user->createToken('My script', ['api'])->accessToken;
    $theirs = User::factory()->create()->createToken('Their phone', ['app'])->accessToken;

    livewire(SignedInDevices::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$apiKey, $theirs]);
});

test('revoking deletes the token and is logged without it', function () {
    $new = $this->user->createToken('iPhone 16', ['app']);

    livewire(SignedInDevices::class)
        ->callAction(TestAction::make('revoke')->table($new->accessToken))
        ->assertNotified();

    expect(PersonalAccessToken::find($new->accessToken->id))->toBeNull();

    $entry = Activity::where('event', 'revoked')->sole();

    expect($entry->causer_id)->toBe($this->user->id)
        ->and($entry->properties->get('device'))->toBe('iPhone 16')
        ->and(json_encode($entry->toArray()))->not->toContain(explode('|', $new->plainTextToken)[1]);
});

test('revoking is blocked while staff impersonate the subscriber', function () {
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));
    $token = $this->user->createToken('iPhone 16', ['app'])->accessToken;

    livewire(SignedInDevices::class)->callAction(TestAction::make('revoke')->table($token));

    expect(PersonalAccessToken::find($token->id))->not->toBeNull()
        ->and(Activity::where('event', 'revoked')->exists())->toBeFalse();
});

test('a revoked device can no longer use the API', function () {
    $new = $this->user->createToken('iPhone 16', ['app']);
    $this->withToken($new->plainTextToken)->getJson('/api/v1/me')->assertOk();

    livewire(SignedInDevices::class)->callAction(TestAction::make('revoke')->table($new->accessToken));

    $this->app['auth']->forgetGuards();
    $this->withToken($new->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Container command on `tests/Feature/SignedInDevicesPageTest.php`. Expected: FAIL (class `SignedInDevices` not found).

- [ ] **Step 4: Implement**

`app/Filament/Dashboard/Pages/SignedInDevices.php`:

```php
<?php

namespace App\Filament\Dashboard\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Laravel\Sanctum\PersonalAccessToken;
use STS\FilamentImpersonate\ImpersonateManager;
use UnitEnum;

/**
 * The phones signed in to the WxNotify app, with a way to sign one out remotely (a lost
 * phone). The query is scoped to the signed-in subscriber itself: this panel's access gate
 * allows everyone, so scoping is the protection. App tokens are exactly the ones whose
 * abilities are ["app"]; Public API keys never appear here.
 */
class SignedInDevices extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.dashboard.pages.signed-in-devices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Signed-in devices';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => auth()->user()->tokens()->getQuery()->where('abilities', json_encode(['app'])))
            ->defaultSort('last_used_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Device'),
                TextColumn::make('created_at')->label('Signed in')->since(),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                TextColumn::make('expires_at')->label('Expires')->date(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::XMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Sign this device out?')
                    ->modalDescription('The app on this device is signed out the next time it contacts WxNotify.')
                    ->disabled(fn (): bool => app(ImpersonateManager::class)->isImpersonating())
                    ->tooltip(fn (): ?string => app(ImpersonateManager::class)->isImpersonating()
                        ? 'Not available while impersonating a subscriber'
                        : null)
                    ->action(function (PersonalAccessToken $record): void {
                        abort_unless(
                            $record->tokenable_type === User::class && (int) $record->tokenable_id === auth()->id(),
                            403,
                        );

                        activity()
                            ->performedOn(auth()->user())
                            ->causedBy(auth()->user())
                            ->event('revoked')
                            ->withProperties(['device' => $record->name])
                            ->log('Revoked a signed-in app device');

                        $record->delete();

                        Notification::make()->title('Device signed out')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No signed-in devices')
            ->emptyStateDescription('Sign in to the WxNotify app and the phone shows up here.');
    }
}
```

`resources/views/filament/dashboard/pages/signed-in-devices.blade.php`:

```blade
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
```

`.ai/rules/filament.md`: append a section:

```markdown
## The Signed-in devices page scopes to the subscriber in its own query and blocks Revoke while impersonating
`SignedInDevices` lists the signed-in subscriber's tokens whose abilities are exactly `["app"]` (never Public API keys) and re-checks ownership inside the Revoke action; the panel gate allows everyone, so that scoping is the protection. Revoke is disabled while staff impersonate (a disabled Filament action is refused server-side) and logs `revoked` with the device name, never the token.
```

- [ ] **Step 5: Run the tests to verify they pass**

Run the Step 3 command. Expected: all 4 PASS. If `$this->app['auth']->forgetGuards()` is not enough to drop the cached Sanctum user in the last test, build the second request through a fresh `User::find()` + token header on a new test client rather than weakening the assertion.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Filament/Dashboard/Pages/SignedInDevices.php resources/views/filament/dashboard/pages/signed-in-devices.blade.php tests/Feature/SignedInDevicesPageTest.php .ai/rules/filament.md
git commit -m "Add the Signed-in devices page: list and revoke mobile app sign-ins

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 5: App plumbing: config, PKCE, token store, API client, test fakes

All of Tasks 5-9 are in `mobile/`. Run app tests from there: `cd mobile && vendor/bin/pest --compact <file>`.

**Files:**
- Create: `mobile/config/wxnotify.php`, `mobile/app/Support/Pkce.php`, `mobile/app/Support/TokenStore.php`, `mobile/app/Services/WxNotifyApi.php`, `mobile/app/Exceptions/Api/{ApiException,Unauthenticated,Offline,Retryable,Rejected}.php`, `mobile/tests/Fakes/{InMemorySecureStorage,RecordingBrowser,FakeNetwork}.php`
- Modify: `mobile/.env.example`, `mobile/tests/Pest.php`
- Test: `mobile/tests/Feature/PkceTest.php`, `mobile/tests/Feature/TokenStoreTest.php`, `mobile/tests/Feature/WxNotifyApiTest.php`

**Interfaces:**
- Produces: `config('wxnotify.api_url')`, `config('wxnotify.callback_uri')`, `config('wxnotify.feed_cache_size')`; `Pkce::pair(): array{verifier, challenge}`, `Pkce::challengeFor(string): string`, `Pkce::state(): string`; `TokenStore::{token(): ?string, put(string): void, forget(): void, begin(string $verifier, string $state): void, pending(): ?array{verifier, state}, clearPending(): void}`; `WxNotifyApi::{exchangeCode(string $code, string $verifier): array, me(): array, alerts(?string $before = null, int $limit = 25): array, alert(int $id): array, signOut(): void}` (the last four return the decoded JSON body, `me()`/`alert()` already unwrapped from `data`); exceptions `App\Exceptions\Api\{Unauthenticated, Offline, Retryable, Rejected}` all extending `ApiException`; test fakes bound for every Feature test: `$this->secureStorage`, `$this->browser`, `$this->network`.

- [ ] **Step 1: Read the app's conventions**

Read `mobile/CLAUDE.md` and `mobile/AGENTS.md`, and skim `mobile/.claude/skills/nativephp-mobile/SKILL.md` (Testing, Device APIs, SecureStorage sections). Confirm `cd mobile && vendor/bin/pest --compact tests/Feature/ExampleTest.php` runs green on the host before changing anything.

- [ ] **Step 2: Write the failing tests**

`mobile/tests/Feature/PkceTest.php`:

```php
<?php

use App\Support\Pkce;

test('the challenge matches the RFC 7636 example', function () {
    expect(Pkce::challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))
        ->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

test('a pair has a 43-character URL-safe verifier and its S256 challenge', function () {
    ['verifier' => $verifier, 'challenge' => $challenge] = Pkce::pair();

    expect($verifier)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($challenge)->toBe(Pkce::challengeFor($verifier))
        ->and(Pkce::pair()['verifier'])->not->toBe($verifier);
});

test('a state is 32 URL-safe characters and never repeats', function () {
    expect(Pkce::state())->toMatch('/^[A-Za-z0-9_-]{32}$/')->not->toBe(Pkce::state());
});
```

`mobile/tests/Feature/TokenStoreTest.php`:

```php
<?php

use App\Support\TokenStore;
use Native\Mobile\SecureStorageAccessibility;

beforeEach(fn () => $this->store = new TokenStore);

test('there is no token until one is stored', function () {
    expect($this->store->token())->toBeNull();

    $this->store->put('1|abc');

    expect($this->store->token())->toBe('1|abc');
});

test('the token is kept after first unlock so background work can read it, and only in secure storage', function () {
    $this->store->put('1|abc');

    expect($this->secureStorage->accessibilityOf('wxnotify_api_token'))->toBe(SecureStorageAccessibility::AfterFirstUnlock)
        ->and($this->secureStorage->items)->toHaveKey('wxnotify_api_token');
});

test('forgetting removes the token', function () {
    $this->store->put('1|abc');
    $this->store->forget();

    expect($this->store->token())->toBeNull();
});

test('a failed secure write is an error, not a silent sign-out', function () {
    $this->secureStorage->failWrites = true;

    expect(fn () => $this->store->put('1|abc'))->toThrow(RuntimeException::class);
});

test('a pending sign-in survives until it is cleared', function () {
    $this->store->begin('verifier-value', 'state-value');

    expect($this->store->pending())->toBe(['verifier' => 'verifier-value', 'state' => 'state-value']);

    $this->store->clearPending();

    expect($this->store->pending())->toBeNull();
});
```
`mobile/tests/Feature/WxNotifyApiTest.php`:

```php
<?php

use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\Unauthenticated;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    $this->api = app(WxNotifyApi::class);
});

test('requests carry the bearer token and ask for JSON', function () {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => ['id' => 1, 'name' => 'Pat']])]);

    expect($this->api->me())->toBe(['id' => 1, 'name' => 'Pat']);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://wx.test/api/v1/me'
        && $r->hasHeader('Authorization', 'Bearer 9|secret')
        && $r->hasHeader('Accept', 'application/json'));
});

test('the feed passes the cursor and limit', function () {
    Http::fake(['wx.test/*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null]])]);

    $this->api->alerts('abc', 50);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'before=abc') && str_contains($r->url(), 'limit=50'));
});

test('one alert is unwrapped from its data key', function () {
    Http::fake(['wx.test/api/v1/alerts/7' => Http::response(['data' => ['id' => 7]])]);

    expect($this->api->alert(7))->toBe(['id' => 7]);
});

test('the code exchange sends no bearer token', function () {
    Http::fake(['wx.test/*' => Http::response(['token' => '1|x', 'expires_at' => '2027-01-01T00:00:00+00:00', 'user' => ['id' => 1]])]);
    (new TokenStore)->forget();

    expect($this->api->exchangeCode('code', 'verifier')['token'])->toBe('1|x');

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization')
        && $r['code'] === 'code' && $r['code_verifier'] === 'verifier');
});

test('a 401 is an unauthenticated error', function () {
    Http::fake(['*' => Http::response([], 401)]);

    expect(fn () => $this->api->me())->toThrow(Unauthenticated::class);
});

test('no stored token is an unauthenticated error without a request', function () {
    (new TokenStore)->forget();
    Http::fake();

    expect(fn () => $this->api->me())->toThrow(Unauthenticated::class);
    Http::assertNothingSent();
});

test('429 and 5xx are retryable', function (int $status) {
    Http::fake(['*' => Http::response([], $status)]);

    expect(fn () => $this->api->me())->toThrow(Retryable::class);
})->with([429, 500, 503]);

test('a rejected exchange is a rejection', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 422)]);

    expect(fn () => $this->api->exchangeCode('code', 'verifier'))->toThrow(Rejected::class);
});

test('a dropped connection is offline', function () {
    Http::fake(fn () => throw new ConnectionException('no route'));

    expect(fn () => $this->api->me())->toThrow(Offline::class);
});

test('a device that reports no network is offline without a request', function () {
    $this->network->connected = false;
    Http::fake();

    expect(fn () => $this->api->me())->toThrow(Offline::class);
    Http::assertNothingSent();
});

test('signing out sends a DELETE and tolerates the empty 204 body', function () {
    Http::fake(['wx.test/api/v1/auth/token' => Http::response(null, 204)]);

    $this->api->signOut();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run `cd mobile && vendor/bin/pest --compact tests/Feature/PkceTest.php` (and the other two). Expected: FAIL (classes missing).

- [ ] **Step 4: Implement**

`mobile/.env.example`: add at the end

```dotenv
WXNOTIFY_API_URL=https://wxnotify.test
NATIVEPHP_DEEPLINK_SCHEME=wxnotify
```
and set `APP_NAME=WxNotify`. Also add the same keys to `mobile/.env` (gitignored; do not commit it) using the dev backend's reachable HTTPS URL; if you do not know it, leave `WXNOTIFY_API_URL` as the example value and say so in your report.

`mobile/config/wxnotify.php`:

```php
<?php

return [

    /*
    | Base URL of the WxNotify backend the app talks to (no trailing slash). Build-time only;
    | a user-editable server URL for self-hosted instances is a later feature.
    */
    'api_url' => rtrim((string) env('WXNOTIFY_API_URL', ''), '/'),

    /*
    | The deep link the browser sign-in returns to. Must equal the backend's
    | config('wxnotify.app_auth.callback_uri'); the code and state follow as path segments.
    */
    'callback_uri' => 'wxnotify://auth/callback',

    /*
    | How many feed entries the offline cache keeps.
    */
    'feed_cache_size' => 50,

];
```

`mobile/app/Support/Pkce.php`:

```php
<?php

namespace App\Support;

/**
 * PKCE (RFC 7636, S256) helpers for the browser sign-in. The verifier never leaves the app
 * until the code exchange, which is what makes an intercepted deep-link code useless.
 */
class Pkce
{
    /**
     * @return array{verifier: string, challenge: string}
     */
    public static function pair(): array
    {
        $verifier = self::base64Url(random_bytes(32));

        return ['verifier' => $verifier, 'challenge' => self::challengeFor($verifier)];
    }

    public static function challengeFor(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function state(): string
    {
        return self::base64Url(random_bytes(24));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
```

`mobile/app/Support/TokenStore.php`:

```php
<?php

namespace App\Support;

use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\SecureStorageAccessibility;
use RuntimeException;

/**
 * Everything secret the app keeps: the API token and the in-flight sign-in (PKCE verifier and
 * state, held in secure storage so a sign-in survives the OS suspending the app while the
 * system browser is open). Both are readable after first unlock, because background work
 * (location uploads, planned) runs while the phone is locked. Nothing here is written to
 * SQLite, the session or a log.
 */
class TokenStore
{
    private const TOKEN = 'wxnotify_api_token';

    private const PENDING = 'wxnotify_pending_signin';

    public function token(): ?string
    {
        return SecureStorage::get(self::TOKEN) ?: null;
    }

    public function put(string $token): void
    {
        $this->write(self::TOKEN, $token);
    }

    public function forget(): void
    {
        SecureStorage::delete(self::TOKEN);
    }

    public function begin(string $verifier, string $state): void
    {
        $this->write(self::PENDING, json_encode(['verifier' => $verifier, 'state' => $state]));
    }

    /**
     * @return array{verifier: string, state: string}|null
     */
    public function pending(): ?array
    {
        $data = json_decode((string) SecureStorage::get(self::PENDING), true);

        return is_array($data) && is_string($data['verifier'] ?? null) && is_string($data['state'] ?? null)
            ? ['verifier' => $data['verifier'], 'state' => $data['state']]
            : null;
    }

    public function clearPending(): void
    {
        SecureStorage::delete(self::PENDING);
    }

    private function write(string $key, string $value): void
    {
        if (! SecureStorage::set($key, $value, SecureStorageAccessibility::AfterFirstUnlock)) {
            throw new RuntimeException('Could not store the sign-in securely on this device.');
        }
    }
}
```

`mobile/app/Exceptions/Api/ApiException.php`:

```php
<?php

namespace App\Exceptions\Api;

use RuntimeException;

abstract class ApiException extends RuntimeException {}
```
and four one-line subclasses (each `final class X extends ApiException {}` in its own file under `App\Exceptions\Api`): `Unauthenticated` (token missing or refused), `Offline` (no connection), `Retryable` (429/5xx), `Rejected` (any other refusal, including a failed code exchange).

`mobile/app/Services/WxNotifyApi.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\Unauthenticated;
use App\Support\TokenStore;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\Network;

/**
 * The app's only way to reach WxNotify. Failures become typed exceptions the screens handle:
 * Unauthenticated (clear the token, go to Sign in), Offline, Retryable (429/5xx), Rejected.
 */
class WxNotifyApi
{
    public function __construct(private TokenStore $tokens) {}

    /**
     * @return array{token: string, expires_at: string, user: array<string, mixed>}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        return $this->send(
            fn (PendingRequest $request) => $request->post('/api/v1/auth/token', ['code' => $code, 'code_verifier' => $verifier]),
            authenticated: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get('/api/v1/me'))['data'];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{next_cursor: ?string}}
     */
    public function alerts(?string $before = null, int $limit = 25): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get('/api/v1/alerts', array_filter(
            ['limit' => $limit, 'before' => $before],
            fn ($value) => $value !== null,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public function alert(int $id): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get("/api/v1/alerts/{$id}"))['data'];
    }

    public function signOut(): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete('/api/v1/auth/token'));
    }

    /**
     * @param  Closure(PendingRequest): \Illuminate\Http\Client\Response  $call
     * @return array<string, mixed>
     */
    private function send(Closure $call, bool $authenticated = true): array
    {
        $this->assertOnline();

        $request = Http::baseUrl(config('wxnotify.api_url'))->acceptJson()->asJson()->timeout(15);

        if ($authenticated) {
            $token = $this->tokens->token();

            if ($token === null) {
                throw new Unauthenticated;
            }

            $request = $request->withToken($token);
        }

        try {
            $response = $call($request);
        } catch (ConnectionException) {
            throw new Offline;
        }

        return match (true) {
            $response->successful() => $response->json() ?? [],
            $response->status() === 401 => throw new Unauthenticated,
            $response->status() === 429, $response->serverError() => throw new Retryable,
            default => throw new Rejected,
        };
    }

    private function assertOnline(): void
    {
        $status = Network::status();

        if ($status !== null && ($status->connected ?? true) === false) {
            throw new Offline;
        }
    }
}
```

Test fakes (`mobile/tests/Fakes/`):

`InMemorySecureStorage.php`:

```php
<?php

namespace Tests\Fakes;

use Native\Mobile\SecureStorage;
use Native\Mobile\SecureStorageAccessibility;

class InMemorySecureStorage extends SecureStorage
{
    /** @var array<string, string> */
    public array $items = [];

    /** @var array<string, SecureStorageAccessibility|null> */
    public array $accessibility = [];

    public bool $failWrites = false;

    public function set(string $key, ?string $value, ?SecureStorageAccessibility $accessibility = null): bool
    {
        if ($this->failWrites) {
            return false;
        }

        if ($value === null) {
            unset($this->items[$key], $this->accessibility[$key]);

            return true;
        }

        $this->items[$key] = $value;
        $this->accessibility[$key] = $accessibility;

        return true;
    }

    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key], $this->accessibility[$key]);

        return true;
    }

    public function accessibilityOf(string $key): ?SecureStorageAccessibility
    {
        return $this->accessibility[$key] ?? null;
    }
}
```

`RecordingBrowser.php`:

```php
<?php

namespace Tests\Fakes;

use Native\Mobile\Browser;

class RecordingBrowser extends Browser
{
    /** @var array<int, string> */
    public array $authUrls = [];

    /** @var array<int, string> */
    public array $inAppUrls = [];

    public bool $authSucceeds = true;

    public function auth(string $url): bool
    {
        $this->authUrls[] = $url;

        return $this->authSucceeds;
    }

    public function inApp(string $url): bool
    {
        $this->inAppUrls[] = $url;

        return true;
    }
}
```

`FakeNetwork.php`:

```php
<?php

namespace Tests\Fakes;

use Native\Mobile\Network;

class FakeNetwork extends Network
{
    public bool $connected = true;

    public function status(): ?object
    {
        return (object) ['connected' => $this->connected, 'type' => $this->connected ? 'wifi' : 'none'];
    }
}
```

`mobile/tests/Pest.php`: replace the `pest()->extend(...)` block with the following (keep the rest of the file):

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Browser;
use Native\Mobile\Network;
use Native\Mobile\SecureStorage;
use Tests\Fakes\FakeNetwork;
use Tests\Fakes\InMemorySecureStorage;
use Tests\Fakes\RecordingBrowser;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Every native capability the app touches is replaced by an in-memory fake, and any
        // HTTP request a test did not fake fails at once.
        $this->secureStorage = new InMemorySecureStorage;
        $this->browser = new RecordingBrowser;
        $this->network = new FakeNetwork;

        app()->instance(SecureStorage::class, $this->secureStorage);
        app()->instance(Browser::class, $this->browser);
        app()->instance(Network::class, $this->network);

        config(['wxnotify.api_url' => 'https://wx.test']);
        Http::preventStrayRequests();
    })
    ->in('Feature');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run each of the three files (or `cd mobile && vendor/bin/pest --compact`). Expected: all PASS, including the untouched ExampleTest. If a facade still resolves the real class (a test hits the real bridge), call `Illuminate\Support\Facades\Facade::clearResolvedInstance(<Native class>)` in the `beforeEach` for that class.

- [ ] **Step 6: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/config/wxnotify.php mobile/app mobile/.env.example mobile/tests
git commit -m "App: add PKCE, secure token store and the typed WxNotify API client

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 6: Offline cache

**Files:**
- Create: `mobile/database/migrations/<timestamp>_create_cached_alerts_table.php` (via `php artisan make:migration`), `mobile/app/Models/CachedAlert.php`, `mobile/app/Support/LocalCache.php`
- Test: `mobile/tests/Feature/LocalCacheTest.php`

**Interfaces:**
- Consumes: `config('wxnotify.feed_cache_size')`.
- Produces: `CachedAlert` model (`alert_id` primary key, `sort_at`, `list`, `detail`, `in_feed`); `LocalCache::{feed(): array, replaceFeed(array $entries): void, storeDetail(array $detail): void, detail(int $id): ?array, syncedAt(): ?CarbonImmutable, rememberProfile(array $user): void, profile(): ?array, wipe(): void}` where `feed()` returns the list payloads (as the API gave them) newest first.

- [ ] **Step 1: Write the failing tests**

`mobile/tests/Feature/LocalCacheTest.php`:

```php
<?php

use App\Models\CachedAlert;
use App\Support\LocalCache;

function feedEntry(int $id, string $at = '2026-10-04T12:00:00+00:00'): array
{
    return ['id' => $id, 'subject' => "Alert {$id}", 'dispatched_at' => $at, 'channels' => [], 'has_failure' => false];
}

beforeEach(fn () => $this->cache = new LocalCache);

test('an empty cache has no feed, sync time, profile or detail', function () {
    expect($this->cache->feed())->toBe([])
        ->and($this->cache->syncedAt())->toBeNull()
        ->and($this->cache->profile())->toBeNull()
        ->and($this->cache->detail(1))->toBeNull();
});

test('replacing the feed stores entries newest first and stamps the sync time', function () {
    $this->cache->replaceFeed([
        feedEntry(2, '2026-10-04T12:05:00+00:00'),
        feedEntry(1, '2026-10-04T12:00:00+00:00'),
    ]);

    expect(array_column($this->cache->feed(), 'id'))->toBe([2, 1])
        ->and($this->cache->syncedAt())->not->toBeNull();
});

test('a refresh replaces the feed: entries no longer in it disappear', function () {
    $this->cache->replaceFeed([feedEntry(1), feedEntry(2)]);
    $this->cache->replaceFeed([feedEntry(2), feedEntry(3)]);

    expect(array_column($this->cache->feed(), 'id'))->toContain(2, 3)->not->toContain(1)
        ->and(CachedAlert::count())->toBe(2);
});

test('only the configured number of entries is kept', function () {
    config(['wxnotify.feed_cache_size' => 3]);

    $this->cache->replaceFeed(array_map(fn ($i) => feedEntry($i, "2026-10-04T12:0{$i}:00+00:00"), range(1, 5)));

    expect($this->cache->feed())->toHaveCount(3)
        ->and(array_column($this->cache->feed(), 'id'))->toBe([5, 4, 3]);
});

test('an opened detail is kept even after it leaves the feed', function () {
    $this->cache->replaceFeed([feedEntry(1)]);
    $this->cache->storeDetail([...feedEntry(1), 'rule_name' => 'Storm warnings']);
    $this->cache->replaceFeed([feedEntry(2)]);

    expect($this->cache->detail(1)['rule_name'])->toBe('Storm warnings')
        ->and(array_column($this->cache->feed(), 'id'))->toBe([2]);
});

test('a detail opened for an alert that is not in the feed is stored without joining it', function () {
    $this->cache->storeDetail([...feedEntry(9), 'rule_name' => null]);

    expect($this->cache->detail(9))->not->toBeNull()
        ->and($this->cache->feed())->toBe([]);
});

test('the profile is remembered', function () {
    $this->cache->rememberProfile(['name' => 'Pat', 'timezone' => 'America/Chicago']);

    expect($this->cache->profile())->toBe(['name' => 'Pat', 'timezone' => 'America/Chicago']);
});

test('wiping removes everything', function () {
    $this->cache->replaceFeed([feedEntry(1)]);
    $this->cache->storeDetail([...feedEntry(1), 'rule_name' => 'x']);
    $this->cache->rememberProfile(['name' => 'Pat']);

    $this->cache->wipe();

    expect($this->cache->feed())->toBe([])
        ->and($this->cache->detail(1))->toBeNull()
        ->and($this->cache->profile())->toBeNull()
        ->and($this->cache->syncedAt())->toBeNull()
        ->and(CachedAlert::count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

`cd mobile && vendor/bin/pest --compact tests/Feature/LocalCacheTest.php`. Expected: FAIL (`LocalCache` not found).

- [ ] **Step 3: Implement**

`cd mobile && php artisan make:migration create_cached_alerts_table --no-interaction`, then:

```php
    public function up(): void
    {
        Schema::create('cached_alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('alert_id')->primary();
            $table->timestamp('sort_at')->index();
            $table->json('list');
            $table->json('detail')->nullable();
            $table->boolean('in_feed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_alerts');
    }
```

`mobile/app/Models/CachedAlert.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One alert the app has seen: its feed entry, and its detail once opened. Only rows flagged
 * `in_feed` belong to the latest refreshed feed; the others exist for their detail.
 */
#[Fillable(['alert_id', 'sort_at', 'list', 'detail', 'in_feed'])]
class CachedAlert extends Model
{
    protected $primaryKey = 'alert_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'sort_at' => 'datetime',
            'list' => 'array',
            'detail' => 'array',
            'in_feed' => 'boolean',
        ];
    }
}
```

`mobile/app/Support/LocalCache.php`:

```php
<?php

namespace App\Support;

use App\Models\CachedAlert;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the app keeps on the phone so it opens instantly and works offline: the latest feed
 * page, any alert detail the subscriber opened, the profile from /me, and when the feed was
 * last refreshed. Signing out wipes all of it.
 */
class LocalCache
{
    private const SYNCED_AT = 'wxnotify.feed.synced_at';

    private const PROFILE = 'wxnotify.profile';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function feed(): array
    {
        return CachedAlert::query()
            ->where('in_feed', true)
            ->orderByDesc('sort_at')
            ->orderByDesc('alert_id')
            ->limit((int) config('wxnotify.feed_cache_size'))
            ->get()
            ->map(fn (CachedAlert $alert): array => $alert->list)
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries  newest first, as the API returned them
     */
    public function replaceFeed(array $entries): void
    {
        DB::transaction(function () use ($entries): void {
            CachedAlert::query()->update(['in_feed' => false]);

            foreach (array_slice($entries, 0, (int) config('wxnotify.feed_cache_size')) as $entry) {
                CachedAlert::query()->updateOrCreate(
                    ['alert_id' => $entry['id']],
                    ['sort_at' => Carbon::parse($entry['dispatched_at']), 'list' => $entry, 'in_feed' => true],
                );
            }

            CachedAlert::query()->where('in_feed', false)->whereNull('detail')->delete();
        });

        Cache::forever(self::SYNCED_AT, now()->toIso8601String());
    }

    /**
     * @param  array<string, mixed>  $detail  a detail payload (a superset of the feed entry)
     */
    public function storeDetail(array $detail): void
    {
        $alert = CachedAlert::query()->firstOrNew(['alert_id' => $detail['id']]);

        if (! $alert->exists) {
            $alert->fill(['sort_at' => Carbon::parse($detail['dispatched_at']), 'list' => $detail, 'in_feed' => false]);
        }

        $alert->detail = $detail;
        $alert->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        return CachedAlert::query()->find($id)?->detail;
    }

    public function syncedAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::SYNCED_AT);

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * @param  array<string, mixed>  $user
     */
    public function rememberProfile(array $user): void
    {
        Cache::forever(self::PROFILE, $user);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function profile(): ?array
    {
        return Cache::get(self::PROFILE);
    }

    public function wipe(): void
    {
        CachedAlert::query()->delete();
        Cache::forget(self::SYNCED_AT);
        Cache::forget(self::PROFILE);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run the Step 2 command. Expected: all PASS.

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/database/migrations mobile/app/Models mobile/app/Support/LocalCache.php mobile/tests/Feature/LocalCacheTest.php
git commit -m "App: add the offline cache for the feed, alert details and profile

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 7: Sign-in flow: services, Sign in / callback / denied screens, signed-out guard

**Files:**
- Create: `mobile/app/Services/SignIn.php`, `mobile/app/Services/SignOut.php`, `mobile/app/Exceptions/InvalidSignIn.php`, `mobile/app/NativeComponents/{SignInScreen,AuthCallback,AuthDenied}.php` and their views (create with `php artisan native:make`), `mobile/app/NativeComponents/Concerns/RequiresSignIn.php`, `mobile/routes/mobile.php`
- Modify: `mobile/config/native-ui.php` (publish it first: `php artisan vendor:publish --tag=native-ui-config`)
- Test: `mobile/tests/Feature/SignInTest.php`, `mobile/tests/Feature/SignInScreensTest.php`

**Interfaces:**
- Consumes (Tasks 5-6): `Pkce`, `TokenStore`, `WxNotifyApi`, `LocalCache`, the test fakes.
- Produces: `SignIn::{begin(): bool, complete(string $code, string $state): array, denied(): void}` (`complete` returns the user array, stores the token, throws `InvalidSignIn` on a state mismatch or missing pending sign-in, and lets `Rejected`/`Offline`/`Retryable` propagate); `SignOut::{__invoke(): void, clearLocal(): void}`; routes `/sign-in`, `/auth/callback/{code}/{state}`, `/auth/denied/{state}`; trait `RequiresSignIn::redirectIfSignedOut(): bool` (replaces with `/sign-in` and returns true when there is no token).

- [ ] **Step 1: Write the failing tests**

`mobile/tests/Feature/SignInTest.php`:

```php
<?php

use App\Exceptions\Api\Rejected;
use App\Exceptions\InvalidSignIn;
use App\Services\SignIn;
use App\Support\LocalCache;
use App\Support\Pkce;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->signIn = app(SignIn::class);
    $this->tokens = new TokenStore;
});

test('beginning stores a pending sign-in and opens the authorize page with the PKCE challenge', function () {
    Native::fakeBridge()->respondTo('Device.GetInfo', ['info' => json_encode(['model' => 'iPhone 16'])]);

    expect($this->signIn->begin())->toBeTrue();

    $pending = $this->tokens->pending();
    parse_str((string) parse_url($this->browser->authUrls[0], PHP_URL_QUERY), $query);

    expect($this->browser->authUrls[0])->toStartWith('https://wx.test/app-auth/authorize?')
        ->and($pending['verifier'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['challenge'])->toBe(Pkce::challengeFor($pending['verifier']))
        ->and($query['state'])->toBe($pending['state'])
        ->and($query['redirect'])->toBe('wxnotify://auth/callback')
        ->and($query['device_name'])->toBe('iPhone 16');
});

test('the device name falls back when the device reports nothing usable, and is trimmed to 80 characters', function () {
    $this->signIn->begin();
    parse_str((string) parse_url($this->browser->authUrls[0], PHP_URL_QUERY), $query);

    expect($query['device_name'])->toBe('WxNotify app');

    Native::fakeBridge()->respondTo('Device.GetInfo', ['info' => json_encode(['model' => str_repeat("x\x07", 60)])]);
    $this->signIn->begin();
    parse_str((string) parse_url($this->browser->authUrls[1], PHP_URL_QUERY), $second);

    expect(strlen($second['device_name']))->toBeLessThanOrEqual(80)->and($second['device_name'])->not->toContain("\x07");
});

test('completing trades the code and verifier for a token and clears the pending sign-in', function () {
    $this->tokens->begin('the-verifier', 'the-state');
    Http::fake(['wx.test/api/v1/auth/token' => Http::response([
        'token' => '5|abc', 'expires_at' => '2027-01-01T00:00:00+00:00', 'user' => ['id' => 5, 'name' => 'Pat'],
    ])]);

    $user = $this->signIn->complete('the-code', 'the-state');

    expect($user)->toBe(['id' => 5, 'name' => 'Pat'])
        ->and($this->tokens->token())->toBe('5|abc')
        ->and($this->tokens->pending())->toBeNull()
        ->and((new LocalCache)->profile())->toBe(['id' => 5, 'name' => 'Pat']);

    Http::assertSent(fn (Request $r) => $r['code'] === 'the-code' && $r['code_verifier'] === 'the-verifier');
});

test('a state mismatch is refused without contacting the server, and the pending sign-in is spent', function () {
    $this->tokens->begin('the-verifier', 'the-state');
    Http::fake();

    expect(fn () => $this->signIn->complete('the-code', 'another-state'))->toThrow(InvalidSignIn::class);

    Http::assertNothingSent();
    expect($this->tokens->pending())->toBeNull()->and($this->tokens->token())->toBeNull();
});

test('a callback with no pending sign-in is refused', function () {
    Http::fake();

    expect(fn () => $this->signIn->complete('the-code', 'the-state'))->toThrow(InvalidSignIn::class);
    Http::assertNothingSent();
});

test('a rejected exchange stores no token', function () {
    $this->tokens->begin('the-verifier', 'the-state');
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 422)]);

    expect(fn () => $this->signIn->complete('the-code', 'the-state'))->toThrow(Rejected::class);
    expect($this->tokens->token())->toBeNull()->and($this->tokens->pending())->toBeNull();
});

test('a denied sign-in clears the pending state', function () {
    $this->tokens->begin('the-verifier', 'the-state');

    $this->signIn->denied();

    expect($this->tokens->pending())->toBeNull();
});
```

`mobile/tests/Feature/SignInScreensTest.php`:

```php
<?php

use App\NativeComponents\AuthCallback;
use App\NativeComponents\AuthDenied;
use App\NativeComponents\SignInScreen;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

test('the sign-in screen offers one sign-in button that opens the browser handoff', function () {
    Native::test(SignInScreen::class)
        ->assertSee('Sign in to WxNotify')
        ->tap('Sign in to WxNotify');

    expect($this->browser->authUrls)->toHaveCount(1)
        ->and((new TokenStore)->pending())->not->toBeNull();
});

test('the sign-in screen says so when the browser could not be opened', function () {
    $this->browser->authSucceeds = false;

    Native::test(SignInScreen::class)
        ->tap('Sign in to WxNotify')
        ->assertSee('Could not open the sign-in page');
});

test('a valid callback signs the subscriber in and replaces the screen with the feed', function () {
    (new TokenStore)->begin('v', 'state-1');
    Http::fake(['*' => Http::response(['token' => '5|abc', 'expires_at' => '2027-01-01T00:00:00+00:00', 'user' => ['id' => 5]])]);

    Native::test(AuthCallback::class, ['code' => 'c', 'state' => 'state-1'])->assertReplacedWith('/');

    expect((new TokenStore)->token())->toBe('5|abc');
});

test('a callback that cannot complete shows an error with a way back', function () {
    (new TokenStore)->begin('v', 'state-1');
    Http::fake();

    Native::test(AuthCallback::class, ['code' => 'c', 'state' => 'other'])
        ->assertSee('Sign-in could not be completed')
        ->assertDontSee('Signing you in')
        ->tap('Try again')
        ->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
});

test('a denied callback returns quietly to the sign-in screen', function () {
    (new TokenStore)->begin('v', 'state-1');

    Native::test(AuthDenied::class, ['state' => 'state-1'])->assertReplacedWith('/sign-in');

    expect((new TokenStore)->pending())->toBeNull();
});
```
(The signed-out guard on the feed screen is tested in Task 8, when `AlertsScreen` exists.)

- [ ] **Step 2: Run the tests to verify they fail**

Run both files. Expected: FAIL (classes missing).

- [ ] **Step 3: Implement the services**

`mobile/app/Exceptions/InvalidSignIn.php`: `final class InvalidSignIn extends \RuntimeException {}` (namespace `App\Exceptions`).

`mobile/app/Services/SignIn.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\InvalidSignIn;
use App\Support\LocalCache;
use App\Support\Pkce;
use App\Support\TokenStore;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Device;

/**
 * The app half of the browser sign-in: start it (PKCE pair, state, system browser), finish
 * it when the deep link comes back (check the state, trade the code for a token), or drop it
 * when the subscriber cancels. The pending sign-in is single-use: any outcome spends it.
 */
class SignIn
{
    public function __construct(
        private TokenStore $tokens,
        private WxNotifyApi $api,
        private LocalCache $cache,
    ) {}

    public function begin(): bool
    {
        ['verifier' => $verifier, 'challenge' => $challenge] = Pkce::pair();
        $state = Pkce::state();

        $this->tokens->begin($verifier, $state);

        return Browser::auth(config('wxnotify.api_url').'/app-auth/authorize?'.http_build_query([
            'challenge' => $challenge,
            'state' => $state,
            'device_name' => $this->deviceName(),
            'redirect' => config('wxnotify.callback_uri'),
        ]));
    }

    /**
     * @return array<string, mixed> the signed-in subscriber, as /api/v1/me describes them
     *
     * @throws InvalidSignIn when there is no sign-in in progress or the state does not match
     */
    public function complete(string $code, string $state): array
    {
        $pending = $this->tokens->pending();
        $this->tokens->clearPending();

        if ($pending === null || ! hash_equals($pending['state'], $state)) {
            throw new InvalidSignIn;
        }

        $result = $this->api->exchangeCode($code, $pending['verifier']);

        $this->tokens->put($result['token']);
        $this->cache->rememberProfile($result['user']);

        return $result['user'];
    }

    public function denied(): void
    {
        $this->tokens->clearPending();
    }

    private function deviceName(): string
    {
        $info = json_decode((string) Device::getInfo(), true);
        $name = is_array($info) ? ($info['name'] ?? $info['model'] ?? null) : null;
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $name);

        return filled($name) ? Str::limit($name, 80, '') : 'WxNotify app';
    }
}
```

`mobile/app/Services/SignOut.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Support\LocalCache;
use App\Support\TokenStore;

/**
 * Signs the subscriber out of this phone: best-effort revoke on the server (a failed call
 * must not leave them signed in), then forget the token and wipe everything cached.
 */
class SignOut
{
    public function __construct(
        private TokenStore $tokens,
        private WxNotifyApi $api,
        private LocalCache $cache,
    ) {}

    public function __invoke(): void
    {
        try {
            $this->api->signOut();
        } catch (ApiException) {
            // Offline, expired or already revoked: signing out locally is what matters.
        }

        $this->clearLocal();
    }

    public function clearLocal(): void
    {
        $this->tokens->forget();
        $this->tokens->clearPending();
        $this->cache->wipe();
    }
}
```

- [ ] **Step 4: Implement the screens and routes**

First `cd mobile && php artisan vendor:publish --tag=native-ui-config --no-interaction` and set the theme palette in the published `config/native-ui.php` (keep every key the file already defines; set the WxNotify brand: primary blue `#2563eb` light / `#60a5fa` dark, plus background, surface and text tokens that read well on both modes, matching `public/splash.png` / `public/splash-dark.png`). Read the file's own comments for the exact token names.

Scaffold: `php artisan native:make SignInScreen`, `native:make AuthCallback`, `native:make AuthDenied`. Do not run `native:run`.

`mobile/app/NativeComponents/Concerns/RequiresSignIn.php`:

```php
<?php

namespace App\NativeComponents\Concerns;

use App\Support\TokenStore;

trait RequiresSignIn
{
    /**
     * Sends a signed-out visitor to Sign in; returns true when it did, so mount() can stop.
     */
    protected function redirectIfSignedOut(): bool
    {
        if (app(TokenStore::class)->token() !== null) {
            return false;
        }

        $this->replace('/sign-in');

        return true;
    }
}
```

`mobile/app/NativeComponents/SignInScreen.php`:

```php
<?php

namespace App\NativeComponents;

use App\Services\SignIn;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class SignInScreen extends NativeComponent
{
    public ?string $error = null;

    public function signIn(): void
    {
        $this->error = app(SignIn::class)->begin()
            ? null
            : 'Could not open the sign-in page. Check your connection and try again.';
    }

    public function render(): View
    {
        return view('native.sign-in-screen');
    }
}
```

`mobile/resources/views/native/sign-in-screen.blade.php`:

```blade
<native:column class="w-full h-full items-center justify-center p-6 gap-6 bg-theme-background">
    <native:image src="logo.png" class="w-24 h-24" />
    <native:text class="text-2xl font-bold text-theme-on-background text-center">WxNotify</native:text>
    <native:text class="text-base text-theme-on-background text-center">Weather alerts for the places that matter to you.</native:text>
    @if ($error)
        <native:text class="text-sm text-theme-error text-center">{{ $error }}</native:text>
    @endif
    <native:button label="Sign in to WxNotify" @tap="signIn" class="w-full" />
</native:column>
```
(Check `Image.php` for the `src` form that loads `public/logo.png` and the theme token names; adjust the markup, not the visible text the tests assert.)

`mobile/app/NativeComponents/AuthCallback.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\InvalidSignIn;
use App\Services\SignIn;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Where the browser sign-in lands (wxnotify://auth/callback/{code}/{state}).
 */
class AuthCallback extends NativeComponent
{
    public ?string $error = null;

    public function mount(): void
    {
        try {
            app(SignIn::class)->complete((string) $this->param('code'), (string) $this->param('state'));
        } catch (InvalidSignIn|ApiException) {
            $this->error = 'Sign-in could not be completed. Please try again.';

            return;
        }

        $this->replace('/');
    }

    public function tryAgain(): void
    {
        $this->replace('/sign-in');
    }

    public function render(): View
    {
        return view('native.auth-callback');
    }
}
```

`mobile/resources/views/native/auth-callback.blade.php`:

```blade
<native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-theme-background">
    @if ($error)
        <native:text class="text-base text-theme-on-background text-center">{{ $error }}</native:text>
        <native:button label="Try again" @tap="tryAgain" />
    @else
        <native:activity-indicator />
        <native:text class="text-base text-theme-on-background">Signing you in…</native:text>
    @endif
</native:column>
```

`mobile/app/NativeComponents/AuthDenied.php`:

```php
<?php

namespace App\NativeComponents;

use App\Services\SignIn;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Where a cancelled browser sign-in lands (wxnotify://auth/denied/{state}).
 */
class AuthDenied extends NativeComponent
{
    public function mount(): void
    {
        app(SignIn::class)->denied();

        $this->replace('/sign-in');
    }

    public function render(): View
    {
        return view('native.auth-denied');
    }
}
```
`mobile/resources/views/native/auth-denied.blade.php`: a bare `<native:column class="w-full h-full bg-theme-background" />`.

`mobile/routes/mobile.php` (create if the scaffold did not; the package loads it only in native contexts):

```php
<?php

use App\NativeComponents\AuthCallback;
use App\NativeComponents\AuthDenied;
use App\NativeComponents\SignInScreen;
use Illuminate\Support\Facades\Route;

Route::native('/sign-in', SignInScreen::class);
Route::native('/auth/callback/{code}/{state}', AuthCallback::class);
Route::native('/auth/denied/{state}', AuthDenied::class);
```
(Tasks 8-9 add `/`, `/alerts/{id}`, `/account`.)

- [ ] **Step 5: Run the tests to verify they pass**

Run both files. Expected: all PASS. If `assertReplacedWith` fails because a `replace()` inside `mount()` is not recorded, read `Testing/TestableComponent.php` for how navigation intents raised during mount are reported and adapt the screen (for example calling `replace()` from `onResume()`), not the assertion's intent.

- [ ] **Step 6: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/app mobile/resources mobile/routes mobile/config/native-ui.php mobile/tests
git commit -m "App: add the browser sign-in flow and the sign-in, callback and denied screens

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 8: Alerts feed screen

**Files:**
- Create: `mobile/app/Support/AlertPresenter.php`, `mobile/app/NativeComponents/AlertsScreen.php` + view (`native:make AlertsScreen`)
- Modify: `mobile/routes/mobile.php`
- Test: `mobile/tests/Feature/AlertPresenterTest.php`, `mobile/tests/Feature/AlertsScreenTest.php`

**Interfaces:**
- Consumes (Tasks 5-7): `WxNotifyApi::alerts()`, `LocalCache`, `SignOut::clearLocal()`, `RequiresSignIn`, typed exceptions.
- Produces: route `/` → `AlertsScreen` (public props `alerts`, `nextCursor`, `offline`, `error`, `syncedAt`, `timezone`; methods `refresh()`, `loadMore()`, `open(int $id)`); `AlertPresenter::{when(string $iso): string, absolute(string $iso, ?string $timezone): ?string, subtitle(array $alert): string, channels(array $alert): string}`.

- [ ] **Step 1: Write the failing tests**

`mobile/tests/Feature/AlertPresenterTest.php`:

```php
<?php

use App\Support\AlertPresenter;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-10-04 12:00:00'));

test('times read as relative text', function () {
    expect(AlertPresenter::when('2026-10-04T11:57:00+00:00'))->toBe('3 minutes ago');
});

test('an absolute time needs the subscriber\'s timezone', function () {
    expect(AlertPresenter::absolute('2026-10-04T20:42:00+00:00', 'America/Chicago'))->toBe('Oct 4, 3:42 PM CDT')
        ->and(AlertPresenter::absolute('2026-10-04T20:42:00+00:00', null))->toBeNull();
});

test('the subtitle joins the category and the relative time', function () {
    expect(AlertPresenter::subtitle(['category_label' => 'Weather Alerts', 'dispatched_at' => '2026-10-04T11:57:00+00:00']))
        ->toBe('Weather Alerts · 3 minutes ago');
});

test('channels list each type once', function () {
    expect(AlertPresenter::channels(['channels' => [
        ['type_label' => 'Discord'], ['type_label' => 'Pushover'], ['type_label' => 'Discord'],
    ]]))->toBe('Sent via Discord, Pushover');
});
```

`mobile/tests/Feature/AlertsScreenTest.php`:

```php
<?php

use App\NativeComponents\AlertsScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function apiEntry(int $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'subject' => "Tornado Warning {$id}",
        'category' => 'weather_alerts',
        'category_label' => 'Weather Alerts',
        'dispatched_at' => now()->subMinutes(5)->toIso8601String(),
        'channels' => [['type' => 'discord', 'type_label' => 'Discord', 'status' => 'success']],
        'has_failure' => false,
        ...$overrides,
    ];
}

function feedResponse(array $entries, ?string $next = null): array
{
    return ['data' => $entries, 'meta' => ['next_cursor' => $next]];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();

    Native::test(AlertsScreen::class)->assertReplacedWith('/sign-in');
});

test('the feed loads from the server, shows each alert, and refreshes the cache', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(1), apiEntry(2, ['has_failure' => true])]))]);

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 1')
        ->assertSee('Weather Alerts · 5 minutes ago')
        ->assertSee('Sent via Discord')
        ->assertSee('Tornado Warning 2')
        ->assertSee('Delivery problem')
        ->assertSet('offline', false);

    expect(array_column((new LocalCache)->feed(), 'id'))->toBe([1, 2]);
});

test('the cached feed paints first, then the refresh replaces it', function () {
    (new LocalCache)->replaceFeed([apiEntry(7, ['subject' => 'Cached alert'])]);
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(8, ['subject' => 'Fresh alert'])]))]);

    Native::test(AlertsScreen::class)->assertSee('Fresh alert')->assertDontSee('Cached alert');
});

test('offline, the cached feed stays with a banner and nothing breaks', function () {
    (new LocalCache)->replaceFeed([apiEntry(7, ['subject' => 'Cached alert'])]);
    $this->network->connected = false;
    Http::fake();

    Native::test(AlertsScreen::class)
        ->assertSee('Cached alert')
        ->assertSee("You're offline. Showing alerts from")
        ->assertSet('offline', true);

    Http::assertNothingSent();
});

test('a dropped connection shows the offline banner too', function () {
    (new LocalCache)->replaceFeed([apiEntry(7, ['subject' => 'Cached alert'])]);
    Http::fake(fn () => throw new ConnectionException('no route'));

    Native::test(AlertsScreen::class)->assertSee('Cached alert')->assertSet('offline', true);
});

test('the banner shows the time in the subscriber\'s timezone when it is known', function () {
    (new LocalCache)->replaceFeed([apiEntry(7)]);
    (new LocalCache)->rememberProfile(['timezone' => 'America/Chicago']);
    $this->network->connected = false;

    Native::test(AlertsScreen::class)->assertSee('CDT')->assertDontSee('minutes ago.');
});

test('a refused token wipes the phone and returns to sign in', function () {
    (new LocalCache)->replaceFeed([apiEntry(7)]);
    Http::fake(['*' => Http::response([], 401)]);

    Native::test(AlertsScreen::class)->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull()->and((new LocalCache)->feed())->toBe([]);
});

test('a server error shows a retry message and keeps the cached feed', function () {
    (new LocalCache)->replaceFeed([apiEntry(7, ['subject' => 'Cached alert'])]);
    Http::fake(['*' => Http::response([], 500)]);

    Native::test(AlertsScreen::class)
        ->assertSee('Cached alert')
        ->assertSee('could not load your alerts');
});

test('an empty feed says there are no alerts yet', function () {
    Http::fake(['*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertSee('No alerts yet');
});

test('load more appends the next page with the cursor and stops when there is none', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::response(feedResponse([apiEntry(1)])),
    ]);

    Native::test(AlertsScreen::class)
        ->assertSee('Load more')
        ->tap('Load more')
        ->assertSee('Tornado Warning 1')
        ->assertDontSee('Load more')
        ->assertSet('nextCursor', null);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'before=CURSOR1'));
});

test('load more is unavailable offline', function () {
    Http::fake(['*' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1'))]);

    $screen = Native::test(AlertsScreen::class);
    $this->network->connected = false;
    $screen->tap('Load more')->assertSet('offline', true);

    Http::assertSentCount(1);
});

test('tapping an alert opens its detail', function () {
    Http::fake(['*' => Http::response(feedResponse([apiEntry(4)]))]);

    Native::test(AlertsScreen::class)->tap('Tornado Warning 4')->assertNavigatedTo('/alerts/4');
});

test('the bottom bar has Alerts and Account', function () {
    Http::fake(['*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertHasTab('Alerts')->assertHasTab('Account');
});
```
(If `Native::test(...)->tap('<visible text>')` cannot target a row by its subject text, use the id/target form the test API documents in `TestableComponent::tap()`, and keep the assertions on navigation intent.)

- [ ] **Step 2: Run the tests to verify they fail**

Run both files. Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

`mobile/app/Support/AlertPresenter.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Turns API alert payloads into the text the screens show.
 */
class AlertPresenter
{
    public static function when(string $iso): string
    {
        return Carbon::parse($iso)->diffForHumans();
    }

    /**
     * An absolute time, only when the subscriber has a timezone: the app cannot assume the device's.
     */
    public static function absolute(string $iso, ?string $timezone): ?string
    {
        return $timezone === null ? null : Carbon::parse($iso)->setTimezone($timezone)->format('M j, g:i A T');
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    public static function subtitle(array $alert): string
    {
        return $alert['category_label'].' · '.self::when($alert['dispatched_at']);
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    public static function channels(array $alert): string
    {
        return 'Sent via '.implode(', ', array_values(array_unique(array_column($alert['channels'], 'type_label'))));
    }
}
```

`mobile/app/NativeComponents/AlertsScreen.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ApiException;
use App\NativeComponents\Concerns\RequiresSignIn;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\AlertPresenter;
use App\Support\LocalCache;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The home screen: the subscriber's recent alerts. Paints from the local cache first, then
 * refreshes; offline it keeps showing what it has, with a banner.
 */
class AlertsScreen extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $alerts = [];

    public ?string $nextCursor = null;

    public bool $offline = false;

    public ?string $error = null;

    public ?string $syncedAt = null;

    public ?string $timezone = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cache = app(LocalCache::class);

        $this->alerts = $cache->feed();
        $this->timezone = $cache->profile()['timezone'] ?? null;
        $this->syncedAt = $cache->syncedAt()?->toIso8601String();

        $this->refresh();
    }

    public function onResume(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $cache = app(LocalCache::class);

        try {
            $page = app(WxNotifyApi::class)->alerts(limit: (int) config('wxnotify.feed_cache_size'));

            $cache->replaceFeed($page['data']);

            $this->alerts = $page['data'];
            $this->nextCursor = $page['meta']['next_cursor'] ?? null;
            $this->syncedAt = $cache->syncedAt()?->toIso8601String();
            $this->offline = false;
            $this->error = null;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
        } catch (ApiException) {
            $this->error = 'WxNotify could not load your alerts. Pull down to try again.';
        }
    }

    public function loadMore(): void
    {
        if ($this->nextCursor === null) {
            return;
        }

        try {
            $page = app(WxNotifyApi::class)->alerts($this->nextCursor);

            $this->alerts = [...$this->alerts, ...$page['data']];
            $this->nextCursor = $page['meta']['next_cursor'] ?? null;
            $this->offline = false;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
        } catch (ApiException) {
            $this->error = 'WxNotify could not load more alerts. Try again.';
        }
    }

    public function open(int $id): void
    {
        $this->navigate("/alerts/{$id}");
    }

    public function subtitle(array $alert): string
    {
        return AlertPresenter::subtitle($alert);
    }

    public function channels(array $alert): string
    {
        return AlertPresenter::channels($alert);
    }

    public function syncedLabel(): string
    {
        if ($this->syncedAt === null) {
            return '';
        }

        return AlertPresenter::absolute($this->syncedAt, $this->timezone) ?? AlertPresenter::when($this->syncedAt);
    }

    public function render(): View
    {
        return view('native.alerts-screen');
    }
}
```

`mobile/resources/views/native/alerts-screen.blade.php` (open `Edge/Elements/{Refreshable,Row,Column,Text,Pressable,Button,BottomNav,BottomNavItem,TopBar}.php` first and use only props they define; the structure and the visible strings are what the tests rely on):

```blade
@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:top-bar title="Alerts" display-mode="large" />

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" />
    <native:bottom-nav-item id="account" label="Account" url="/account" icon="person" />
</native:bottom-nav>

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:row class="w-full p-3 bg-theme-surface-variant">
            <native:text class="text-sm text-theme-on-surface-variant">You're offline. Showing alerts from {{ $this->syncedLabel() }}.</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:row class="w-full p-3 bg-theme-surface-variant">
            <native:text class="text-sm text-theme-error">{{ $error }}</native:text>
        </native:row>
    @endif

    <native:refreshable @refresh="refresh">
        @forelse ($alerts as $alert)
            <native:pressable @tap="open({{ $alert['id'] }})" key="alert-{{ $alert['id'] }}" class="w-full">
                <native:column class="w-full p-4 gap-1">
                    <native:text class="text-base font-semibold text-theme-on-background">{{ $alert['subject'] }}</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($alert) }}</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $this->channels($alert) }}</native:text>
                    @if ($alert['has_failure'])
                        <native:text class="text-sm text-theme-error">Delivery problem</native:text>
                    @endif
                </native:column>
            </native:pressable>
            <native:divider />
        @empty
            <native:column class="w-full p-6 items-center gap-2">
                <native:text class="text-lg font-semibold text-theme-on-background">No alerts yet</native:text>
                <native:text class="text-sm text-theme-on-surface-variant text-center">Alerts you receive show up here once one of your rules matches.</native:text>
            </native:column>
        @endforelse

        @if ($nextCursor !== null)
            <native:button label="Load more" @tap="loadMore" class="m-4" />
        @endif
    </native:refreshable>
</native:column>
```

`mobile/routes/mobile.php`: add `use App\NativeComponents\AlertsScreen;` and `Route::native('/', AlertsScreen::class);`.

Generate the scaffold first with `cd mobile && php artisan native:make AlertsScreen --force` and then overwrite both files with the above.

- [ ] **Step 4: Run the tests to verify they pass**

Run both files. Expected: all PASS. If the `?limit=50` / `?limit=25&before=CURSOR1` fake URLs do not match because of query ordering, switch the fakes to `Http::sequence()` and keep the `before=CURSOR1` `assertSent`.

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/app mobile/resources mobile/routes mobile/tests
git commit -m "App: add the alerts feed screen with cache-first rendering, offline banner and load more

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Task 9: Alert detail and Account screens, sign-out

**Files:**
- Create: `mobile/app/NativeComponents/{AlertDetail,AccountScreen}.php` + views (`native:make`)
- Modify: `mobile/routes/mobile.php`
- Test: `mobile/tests/Feature/AlertDetailTest.php`, `mobile/tests/Feature/AccountScreenTest.php`

**Interfaces:**
- Consumes (Tasks 5-8): `WxNotifyApi::{alert, me, signOut}`, `LocalCache::{detail, storeDetail, profile, rememberProfile}`, `SignOut`, `AlertPresenter`, `RequiresSignIn`, `RecordingBrowser::inAppUrls`.
- Produces: routes `/alerts/{id}` → `AlertDetail`, `/account` → `AccountScreen`.

- [ ] **Step 1: Write the failing tests**

`mobile/tests/Feature/AlertDetailTest.php`:

```php
<?php

use App\NativeComponents\AlertDetail;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function detailPayload(array $overrides = []): array
{
    return [
        'id' => 4,
        'subject' => 'Tornado Warning',
        'category' => 'weather_alerts',
        'category_label' => 'Weather Alerts',
        'dispatched_at' => now()->subMinutes(5)->toIso8601String(),
        'channels' => [
            ['type' => 'discord', 'type_label' => 'Discord', 'status' => 'success', 'label' => 'Team Discord'],
            ['type' => 'pushover', 'type_label' => 'Pushover', 'status' => 'failure', 'label' => null],
        ],
        'has_failure' => true,
        'rule_name' => 'Storm warnings',
        'product_url' => 'https://wx.test/view-product/abc',
        ...$overrides,
    ];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the detail shows the subject, rule, and each channel with its status', function () {
    Http::fake(['wx.test/api/v1/alerts/4' => Http::response(['data' => detailPayload()])]);

    Native::test(AlertDetail::class, ['id' => 4])
        ->assertSee('Tornado Warning')
        ->assertSee('Storm warnings')
        ->assertSee('Team Discord')
        ->assertSee('Delivered')
        ->assertSee('Pushover')
        ->assertSee('Failed');

    expect((new LocalCache)->detail(4)['rule_name'])->toBe('Storm warnings');
});

test('View full product opens the public page in the in-app browser', function () {
    Http::fake(['*' => Http::response(['data' => detailPayload()])]);

    Native::test(AlertDetail::class, ['id' => 4])->tap('View full product');

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/abc']);
});

test('no button is shown when the alert has no product page', function () {
    Http::fake(['*' => Http::response(['data' => detailPayload(['product_url' => null])])]);

    Native::test(AlertDetail::class, ['id' => 4])->assertDontSee('View full product');
});

test('offline, an alert opened before is shown from the cache with a notice', function () {
    (new LocalCache)->storeDetail(detailPayload());
    $this->network->connected = false;
    Http::fake();

    Native::test(AlertDetail::class, ['id' => 4])
        ->assertSee('Storm warnings')
        ->assertSee("You're offline");
});

test('offline, an alert never opened says it needs a connection', function () {
    Http::fake(fn () => throw new ConnectionException('no route'));

    Native::test(AlertDetail::class, ['id' => 4])->assertSee('Connect to the internet to see this alert');
});

test('an alert that no longer exists says so', function () {
    Http::fake(['*' => Http::response([], 404)]);

    Native::test(AlertDetail::class, ['id' => 4])->assertSee('This alert is no longer available');
});

test('a refused token returns to sign in', function () {
    Http::fake(['*' => Http::response([], 401)]);

    Native::test(AlertDetail::class, ['id' => 4])->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();

    Native::test(AlertDetail::class, ['id' => 4])->assertReplacedWith('/sign-in');
});
```

`mobile/tests/Feature/AccountScreenTest.php`:

```php
<?php

use App\NativeComponents\AccountScreen;
use App\Models\CachedAlert;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

$profile = ['id' => 5, 'name' => 'Pat Smith', 'email' => 'pat@example.com', 'tier' => ['value' => 'plus', 'label' => 'Plus'], 'unit_system' => 'imperial', 'timezone' => 'America/Chicago'];

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the account shows the subscriber, their plan and the app version', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    config(['nativephp.version' => '1.2.3']);

    Native::test(AccountScreen::class)
        ->assertSee('Pat Smith')
        ->assertSee('pat@example.com')
        ->assertSee('Plus')
        ->assertSee('1.2.3')
        ->assertHasTab('Account');

    expect((new LocalCache)->profile()['timezone'])->toBe('America/Chicago');
});

test('offline, the remembered profile is shown', function () use ($profile) {
    (new LocalCache)->rememberProfile($profile);
    $this->network->connected = false;
    Http::fake();

    Native::test(AccountScreen::class)->assertSee('Pat Smith');
});

test('signing out revokes the token on the server, forgets it, wipes the cache and returns to sign in', function () use ($profile) {
    (new LocalCache)->replaceFeed([['id' => 1, 'subject' => 'x', 'dispatched_at' => now()->toIso8601String(), 'channels' => [], 'has_failure' => false]]);
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/auth/token' => Http::response(null, 204),
    ]);

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/auth/token');
    expect((new TokenStore)->token())->toBeNull()
        ->and(CachedAlert::count())->toBe(0)
        ->and((new LocalCache)->profile())->toBeNull();
});

test('signing out works offline: the phone is cleared even though the server could not be told', function () use ($profile) {
    (new LocalCache)->rememberProfile($profile);
    $this->network->connected = false;
    Http::fake();

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
});

test('a refused token returns to sign in', function () {
    Http::fake(['*' => Http::response([], 401)]);

    Native::test(AccountScreen::class)->assertReplacedWith('/sign-in');
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();

    Native::test(AccountScreen::class)->assertReplacedWith('/sign-in');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run both files. Expected: FAIL (classes missing).

- [ ] **Step 3: Implement**

`mobile/app/NativeComponents/AlertDetail.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Unauthenticated;
use App\NativeComponents\Concerns\RequiresSignIn;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\AlertPresenter;
use App\Support\LocalCache;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * One alert: what was sent, by which rule, over which channels, and how each delivery went.
 * An alert opened before is kept for offline use.
 */
class AlertDetail extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<string, mixed>|null */
    public ?array $alert = null;

    public bool $offline = false;

    public ?string $message = null;

    public ?string $timezone = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $id = (int) $this->param('id');
        $cache = app(LocalCache::class);

        $this->timezone = $cache->profile()['timezone'] ?? null;
        $this->alert = $cache->detail($id);

        try {
            $this->alert = app(WxNotifyApi::class)->alert($id);
            $cache->storeDetail($this->alert);
            $this->offline = false;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
            $this->message = $this->alert === null ? 'Connect to the internet to see this alert.' : null;
        } catch (Rejected) {
            $this->alert = null;
            $this->message = 'This alert is no longer available.';
        } catch (ApiException) {
            $this->message = $this->alert === null ? 'WxNotify could not load this alert. Go back and try again.' : null;
        }
    }

    public function openProduct(): void
    {
        if (filled($this->alert['product_url'] ?? null)) {
            Browser::inApp($this->alert['product_url']);
        }
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'success' => 'Delivered',
            'failure' => 'Failed',
            'suppressed' => 'Held back',
            'skipped' => 'Skipped',
            default => ucfirst($status),
        };
    }

    public function sentAt(): string
    {
        $iso = $this->alert['dispatched_at'];

        return AlertPresenter::absolute($iso, $this->timezone) ?? AlertPresenter::when($iso);
    }

    public function render(): View
    {
        return view('native.alert-detail');
    }
}
```
(Key detail: a 404 from the API is `Rejected` in `WxNotifyApi`; a 401 is `Unauthenticated`. The `catch` order above matters: `Rejected` before the `ApiException` fallback.)

`mobile/resources/views/native/alert-detail.blade.php`:

```blade
<native:top-bar title="Alert" />

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($offline && $alert !== null)
            <native:text class="text-sm text-theme-on-surface-variant">You're offline. Showing the last copy of this alert.</native:text>
        @endif

        @if ($alert === null)
            <native:text class="text-base text-theme-on-background">{{ $message }}</native:text>
        @else
            <native:text class="text-xl font-bold text-theme-on-background">{{ $alert['subject'] }}</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">{{ $alert['category_label'] }} · {{ $this->sentAt() }}</native:text>

            @if (filled($alert['rule_name'] ?? null))
                <native:text class="text-sm text-theme-on-surface-variant">Rule: {{ $alert['rule_name'] }}</native:text>
            @endif

            <native:divider />

            @foreach ($alert['channels'] as $channel)
                <native:row class="w-full justify-between">
                    <native:text class="text-base text-theme-on-background">{{ $channel['label'] ?? $channel['type_label'] }}</native:text>
                    <native:text class="text-base {{ $channel['status'] === 'failure' ? 'text-theme-error' : 'text-theme-on-surface-variant' }}">{{ $this->statusLabel($channel['status']) }}</native:text>
                </native:row>
            @endforeach

            @if (filled($alert['product_url'] ?? null))
                <native:button label="View full product" @tap="openProduct" />
            @endif
        @endif
    </native:column>
</native:scroll-view>
```
(Use the labelled channel name when the subscriber named it, else the type; the test asserts both "Team Discord" and "Pushover".)

`mobile/app/NativeComponents/AccountScreen.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Unauthenticated;
use App\NativeComponents\Concerns\RequiresSignIn;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\LocalCache;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class AccountScreen extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<string, mixed>|null */
    public ?array $profile = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cache = app(LocalCache::class);
        $this->profile = $cache->profile();

        try {
            $this->profile = app(WxNotifyApi::class)->me();
            $cache->rememberProfile($this->profile);
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (ApiException) {
            // Offline or a server hiccup: the remembered profile stays on screen.
        }
    }

    public function signOut(): void
    {
        app(SignOut::class)();

        $this->replace('/sign-in');
    }

    public function appVersion(): string
    {
        return (string) config('nativephp.version');
    }

    public function render(): View
    {
        return view('native.account-screen');
    }
}
```

`mobile/resources/views/native/account-screen.blade.php`:

```blade
<native:top-bar title="Account" display-mode="large" />

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" />
    <native:bottom-nav-item id="account" label="Account" url="/account" icon="person" />
</native:bottom-nav>

<native:column class="w-full h-full p-4 gap-4 bg-theme-background">
    @if ($profile !== null)
        <native:column class="w-full gap-1">
            <native:text class="text-xl font-bold text-theme-on-background">{{ $profile['name'] }}</native:text>
            <native:text class="text-base text-theme-on-surface-variant">{{ $profile['email'] }}</native:text>
            <native:text class="text-base text-theme-on-surface-variant">{{ $profile['tier']['label'] }} plan</native:text>
        </native:column>
    @endif

    <native:text class="text-sm text-theme-on-surface-variant">WxNotify app version {{ $this->appVersion() }}</native:text>

    <native:button label="Sign out" @tap="signOut" />
</native:column>
```

`mobile/routes/mobile.php`: add `use App\NativeComponents\AccountScreen; use App\NativeComponents\AlertDetail;` and

```php
Route::native('/alerts/{id}', AlertDetail::class);
Route::native('/account', AccountScreen::class);
```

Scaffold with `native:make AlertDetail --force` / `native:make AccountScreen --force` first, then overwrite.

- [ ] **Step 4: Run the tests to verify they pass**

Run both files, then the whole app suite: `cd mobile && vendor/bin/pest --compact`. Expected: all PASS. If `assertHasTab('Account')` is not available for inline `bottom-nav` chrome (it targets layout tab bars), assert the rendered tree with `assertElement('bottom-nav-item', ...)` instead.

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/app mobile/resources mobile/routes mobile/tests
git commit -m "App: add the alert detail and account screens with sign-out

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Controller verification (after the final review, not a subagent task)

1. Full backend suite in the container, parallel with 6 processes (see the `run-full-suite-in-parallel` memory); the app suite on the host (`cd mobile && vendor/bin/pest --compact`).
2. Dev migration in the container (`php artisan migrate`) for the `personal_access_tokens` table and the new `delivery_logs` index.
3. **Backend in the browser** (throwaway subscriber, deleted afterwards): open `/app-auth/authorize?...` signed out and confirm the dashboard login returns to the consent page with its query intact (the plan's open risk); Allow redirects to a `wxnotify://auth/callback/{code}/{state}` Location; the exchange with `curl` returns a token that reads `/api/v1/me` and `/api/v1/alerts` (seed a few `delivery_logs` rows, including one rule delivered over two channels); the Signed-in devices page lists the device and Revoke makes the token fail.
4. **Dev reachability**: confirm the dev backend is reachable from the iOS Simulator over HTTPS (or that the dev build has an App Transport Security exception). Set `WXNOTIFY_API_URL` in `mobile/.env` accordingly.
5. **App on the iOS Simulator** (needs the user: ask them to run `cd mobile && php artisan native:run ios`; never run it yourself): call the simulator `attach` first. Sign in through the real browser handoff (Allow, then Cancel), check the feed, detail, "View full product", offline banner (disable the network), sign out, and revocation from the dashboard returning the app to Sign in on its next request. Note the real `Device::getInfo()` key used for the device name and the real behaviour of `Browser::auth()` callbacks.
6. Android: ask the user for a compile check (`php artisan native:run android`) before release; record the result.
7. Delete all throwaway data.

## Self-review (writing-plans checklist)

**Spec coverage:** Sanctum + expiry + abilities + config (Task 1); handoff incl. consent/impersonation/unverified/single-use/PKCE/throttles/audit (Task 2); `/me`, sign-out (Tasks 1-2); feed grouping, cursor, detail, scoping, no `error_detail` (Task 3); Signed-in devices page (Task 4); app config/PKCE/secure storage/API client/typed errors (Task 5); offline cache (Task 6); sign-in flow, screens, deep-link routes (Task 7); Alerts screen, offline banner, load more, bottom bar (Task 8); detail, account, sign-out wipe (Task 9); project rules (Tasks 1, 4); the spec's risk items map to Task 3 Step 5 (grouped query), Task 2 Step 5 and the controller verification (intended URL, dev reachability, callback delivery, native test API in Tasks 7-9 steps). Out-of-scope items are untouched. Follow-ups (mirror exclusion, universal links, refresh tokens, server URL) are left out on purpose.

**Placeholder scan:** none; code is given for every step. Native screen markup is stated as the intended structure with an instruction to check element props against the installed classes, because those props are only knowable from the installed package; the visible strings the tests assert are fixed.

**Type consistency:** `TokenStore` (`token/put/forget/begin/pending/clearPending`), `WxNotifyApi` (`exchangeCode/me/alerts/alert/signOut`), `LocalCache` (`feed/replaceFeed/storeDetail/detail/syncedAt/rememberProfile/profile/wipe`), `SignIn` (`begin/complete/denied`), `SignOut` (`__invoke/clearLocal`), `AppAlert`/`ListAppAlerts`/`FindAppAlert`, `IssueAppAuthorizationCode::{__invoke,key}` and `ExchangeAppAuthorizationCode::__invoke` are used with the same names and signatures across tasks; the API field names (`category_label`, `type_label`, `has_failure`, `rule_name`, `product_url`, `next_cursor`) match between Task 3 and Tasks 6-9.
