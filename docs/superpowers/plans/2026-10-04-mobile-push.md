# Mobile Push (Firebase) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A subscriber signed in to the mobile app can turn on push, choose that phone in their alert rules on the web dashboard, and receive each matching alert as a system notification that opens the app on the feed when tapped.

**Architecture:** Each registered phone is a `NotificationChannel` of a new type `mobile_push`, tied to the app's Sanctum token (so revoking or signing out removes it). The Alert Engine already sends through `ChannelDispatcher`; a new `MobilePushTransport` sends through an in-house `FcmClient` (service-account JWT signed with openssl, FCM v1 HTTP API). The app registers its FCM token through `PUT /api/v1/push-token` and manages permission and the on/off state locally.

**Tech Stack:** Laravel 13 / PHP 8.4 / Filament v5 / Pest 5 / Sanctum; NativePHP v4 mobile (`nativephp/mobile-firebase` plugin, already installed and registered as `PushNotificationsServiceProvider`). No new Composer packages.

**Spec:** `docs/superpowers/specs/2026-10-04-mobile-push-design.md`

## Global Constraints

- **No new dependencies** (Composer or npm). The FCM sender uses `openssl_sign` and Laravel's `Http` client.
- **The FCM token is a secret.** It must never appear in an API response, a log line, an activity-log row, an exception message, or a `DeliveryResult`/`FcmResult` error. Error text is a fixed string plus the numeric HTTP status, never a response body (`.ai/rules/delivery.md`).
- **Fixed outbound hosts only** (`oauth2.googleapis.com`, `fcm.googleapis.com`), redirects off, 10 s timeout, a single attempt, no retries.
- **Activity log:** session-less code (API requests, queued jobs) must pin the causer with `app(CauserResolver::class)->withCauser($user, fn () => …)`; `causedBy()` is ignored (`.ai/rules/actions.md`).
- **API rules** (`.ai/rules/api.md`): `/api/v1`, `auth:sanctum` + `ability:app`, every query scoped to `$request->user()`; responses never carry a channel destination, URL or token.
- **Channel types** (`.ai/rules/delivery.md`): a new type needs a `channel_types` migration AND a `ChannelTypeSeeder::SHIPPED` entry in matching sort order, plus `destinationLabel()` (a fixed caption) and a `displaySummary()` that never returns a value granting delivery access.
- **Tests:** Pest feature tests, `Http::fake()` for every outbound call (the suite runs `Http::preventStrayRequests()`). Main-app tests run in the container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest <path>'`. Mobile tests run on the host: `cd mobile && vendor/bin/pest --compact`. Shared test helpers go in `tests/Pest.php` (existing convention: `chanFakePushService()`), prefixed (`fcm…`).
- **Never run `native:run`, `native:jump` or any build** — the owner runs builds. `cd mobile && php artisan native:validate` must stay clean.
- **Style:** run `vendor/bin/pint --dirty --format agent` before each commit. Commit messages end with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Commit to `main`; do not push.
- **Rulings recorded while planning** (read the spec first; these refine it):
  1. The plugin creates and owns its Android notification channel (`fcm_default_channel`) and builds the notification itself, so the payload carries **no** `android.notification.channel_id`.
  2. The picker is hidden through the existing `isAvailable(): false`; no new contract method is added.
  3. Device channels are created with `verified_at = now()` (the dashboard marks unverified channels and hides "Send Test" for them), and `countsTowardFreeSlot()` is `false` (a phone must not use Free's single no-cost slot).
  4. The FCM token is encrypted inside `config`, so de-duplication uses a dedicated `device_token_hash` column (SHA-256 of the token, indexed) instead of scanning rows.
  5. The invalid-token prune job is delayed 60 s (so the delivery-log row exists first; `delivery_logs.notification_channel_id` is `nullOnDelete`) and only deletes the channel if it still holds the token that failed (a re-registered phone survives).

---

### Task 1: FcmClient

**Files:**
- Create: `app/Support/Delivery/FcmClient.php`
- Create: `app/Support/Delivery/FcmResult.php`
- Modify: `tests/Pest.php` (add `fcmUseCredentials()` and `fcmFakeGoogle()` after `chanFakePushService()`)
- Test: `tests/Feature/FcmClientTest.php`

**Interfaces:**
- Consumes: `config('services.firebase.service_account')` (already in `config/services.php`; path to the service-account JSON).
- Produces: `FcmClient::send(string $deviceToken, string $title, string $body, array $data = []): FcmResult`; `FcmResult` with `bool $success`, `?string $error`, `bool $tokenInvalid` and static constructors `delivered()`, `failed(string $error)`, `unregistered(string $error)`.

- [ ] **Step 1: Add the shared test helpers to `tests/Pest.php`** (after `chanFakePushService`)

```php
/**
 * Writes a throwaway service-account file, points the app at it and returns the file path and
 * the public key to verify signed JWTs with. Remove the file in afterEach.
 *
 * @return array{path: string, public: string}
 */
function fcmUseCredentials(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);

    $path = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($path, json_encode([
        'type' => 'service_account',
        'project_id' => 'wx-test',
        'client_email' => 'push@wx-test.iam.gserviceaccount.com',
        'private_key' => $privatePem,
    ]));

    config(['services.firebase.service_account' => $path]);

    return ['path' => $path, 'public' => openssl_pkey_get_details($key)['key']];
}

/**
 * Fakes Google's token endpoint and the FCM send endpoint. Pass an Http::response() to change
 * what the send endpoint answers.
 */
function fcmFakeGoogle(mixed $sendResponse = null): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test-access-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => $sendResponse ?? Http::response(['name' => 'projects/wx-test/messages/1']),
    ]);
}
```

(`Http` is already imported in `tests/Pest.php`; if it is not, add `use Illuminate\Support\Facades\Http;`.)

- [ ] **Step 2: Write the failing tests** — `tests/Feature/FcmClientTest.php`

```php
<?php

use App\Support\Delivery\FcmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->credentials = fcmUseCredentials();
    Cache::flush();
});

afterEach(function () {
    @unlink($this->credentials['path']);
});

test('a message goes to the FCM v1 API with a bearer token and the alert as a notification', function () {
    fcmFakeGoogle();

    $result = app(FcmClient::class)->send('device-token-1', 'SPC Day 1', 'Slight risk of severe storms', ['url' => '/']);

    expect($result->success)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/wx-test/messages:send'
        && $request->hasHeader('Authorization', 'Bearer ya29.test-access-token')
        && $request['message']['token'] === 'device-token-1'
        && $request['message']['notification'] === ['title' => 'SPC Day 1', 'body' => 'Slight risk of severe storms']
        && $request['message']['data'] === ['url' => '/']
        && $request['message']['android']['priority'] === 'HIGH'
        && $request['message']['apns']['payload']['aps']['sound'] === 'default'
        && ! isset($request['message']['android']['notification']['channel_id']));
});

test('the access token is requested with a JWT signed by the service account key', function () {
    fcmFakeGoogle();

    app(FcmClient::class)->send('t', 'a', 'b');

    [$request] = Http::recorded(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token')->first();
    [$header, $claims, $signature] = explode('.', $request['assertion']);
    $decode = fn (string $segment) => base64_decode(strtr($segment, '-_', '+/'));
    $claimData = json_decode($decode($claims), true);

    expect($request['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer')
        ->and(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and(openssl_verify("{$header}.{$claims}", $decode($signature), $this->credentials['public'], OPENSSL_ALGO_SHA256))->toBe(1)
        ->and($claimData['iss'])->toBe('push@wx-test.iam.gserviceaccount.com')
        ->and($claimData['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
        ->and($claimData['aud'])->toBe('https://oauth2.googleapis.com/token')
        ->and($claimData['exp'] - $claimData['iat'])->toBe(3600);
});

test('the access token is cached across sends', function () {
    fcmFakeGoogle();
    $client = app(FcmClient::class);

    $client->send('device-a', 't', 'b');
    $client->send('device-b', 't', 'b');

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com')))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com')))->toHaveCount(2);
});

test('without usable credentials nothing is sent and the failure says push is not configured', function (string $break) {
    $path = $this->credentials['path'];
    match ($break) {
        'no setting' => config(['services.firebase.service_account' => null]),
        'missing file' => config(['services.firebase.service_account' => $path.'.missing']),
        'not json' => file_put_contents($path, 'nope'),
        'no private key' => file_put_contents($path, json_encode(['project_id' => 'x', 'client_email' => 'a@b.c'])),
    };
    Http::fake();

    $result = app(FcmClient::class)->send('t', 'a', 'b');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Push is not configured on this server.');
    Http::assertNothingSent();
})->with(['no setting', 'missing file', 'not json', 'no private key']);

test('FCM errors are classified and the failure text is fixed', function (int $status, array $body, bool $tokenInvalid, string $error) {
    fcmFakeGoogle(Http::response($body, $status));

    $result = app(FcmClient::class)->send('super-secret-device-token', 'a', 'b');

    expect($result->success)->toBeFalse()
        ->and($result->tokenInvalid)->toBe($tokenInvalid)
        ->and($result->error)->toBe($error)
        ->and($result->error)->not->toContain('super-secret-device-token');
})->with([
    'unregistered' => [404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found. super-secret-device-token', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], true, 'This device is no longer registered for push.'],
    'invalid token' => [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token']], true, 'This device is no longer registered for push.'],
    'another invalid argument' => [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Request contains an invalid argument.']], false, 'Firebase rejected the message (HTTP 400).'],
    'a 404 that is not the token' => [404, ['error' => ['status' => 'NOT_FOUND', 'message' => 'Project not found']], false, 'Firebase rejected the message (HTTP 404).'],
    'quota' => [429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], false, 'Firebase rejected the message (HTTP 429).'],
    'server error' => [503, [], false, 'Firebase rejected the message (HTTP 503).'],
]);

test('a rejected access token is forgotten so the next send asks Google again', function () {
    fcmFakeGoogle(Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401));
    $cacheKey = 'fcm.access_token.'.sha1('push@wx-test.iam.gserviceaccount.com');

    $result = app(FcmClient::class)->send('t', 'a', 'b');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Firebase rejected the server credentials (HTTP 401).')
        ->and(Cache::has($cacheKey))->toBeFalse();
});

test('a failed token exchange stops before the send', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $result = app(FcmClient::class)->send('t', 'a', 'b');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Could not get a Firebase access token.');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com'));
});

test('an unreachable Firebase fails with a fixed message that carries nothing from the exception', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => fn () => throw new ConnectionException('cURL error: secret-host-detail'),
    ]);

    $result = app(FcmClient::class)->send('t', 'a', 'b');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Firebase could not be reached.');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/FcmClientTest.php'`
Expected: FAIL (class `App\Support\Delivery\FcmClient` not found).

- [ ] **Step 4: Create `app/Support/Delivery/FcmResult.php`**

```php
<?php

namespace App\Support\Delivery;

/**
 * The outcome of one FCM send. The error is always a fixed string plus the numeric HTTP status:
 * never a response body, an exception message, the device token or a credential.
 */
final class FcmResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly bool $tokenInvalid = false,
    ) {}

    public static function delivered(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }

    /**
     * FCM says the device token will never work again (app uninstalled, token rotated away).
     */
    public static function unregistered(string $error): self
    {
        return new self(false, $error, tokenInvalid: true);
    }
}
```

- [ ] **Step 5: Create `app/Support/Delivery/FcmClient.php`**

```php
<?php

namespace App\Support\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Sends one push message through the Firebase Cloud Messaging v1 HTTP API. Authentication is a
 * service-account JWT (RS256, signed with openssl) traded at Google's token endpoint for an
 * access token that is cached until shortly before it expires. Both hosts are fixed (no
 * subscriber-controlled URL), redirects are off, and there is exactly one attempt: the outcome
 * is the delivery-log row, not a retried job.
 */
class FcmClient
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * @param  array<string, string>  $data  string values only (an FCM requirement)
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): FcmResult
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return FcmResult::failed('Push is not configured on this server.');
        }

        $accessToken = $this->accessToken($credentials);

        if ($accessToken === null) {
            return FcmResult::failed('Could not get a Firebase access token.');
        }

        try {
            $response = $this->request()->withToken($accessToken)->post(
                'https://fcm.googleapis.com/v1/projects/'.rawurlencode($credentials['project_id']).'/messages:send',
                ['message' => [
                    'token' => $deviceToken,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => (object) $data,
                    'android' => ['priority' => 'HIGH'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ]],
            );
        } catch (ConnectionException) {
            return FcmResult::failed('Firebase could not be reached.');
        }

        if ($response->status() === 401) {
            Cache::forget($this->cacheKey($credentials));

            return FcmResult::failed('Firebase rejected the server credentials (HTTP 401).');
        }

        return $this->classify($response);
    }

    private function classify(Response $response): FcmResult
    {
        if ($response->successful()) {
            return FcmResult::delivered();
        }

        if ($this->tokenIsInvalid($response)) {
            return FcmResult::unregistered('This device is no longer registered for push.');
        }

        return FcmResult::failed("Firebase rejected the message (HTTP {$response->status()}).");
    }

    /**
     * Reads the error body only to classify it; nothing from it is ever echoed.
     */
    private function tokenIsInvalid(Response $response): bool
    {
        if (! in_array($response->status(), [400, 404], true)) {
            return false;
        }

        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && ($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                return true;
            }
        }

        return $response->json('error.status') === 'INVALID_ARGUMENT'
            && str_contains(strtolower((string) $response->json('error.message')), 'registration token');
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        $path = config('services.firebase.service_account');

        if (blank($path) || ! is_readable($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (! is_array($json) || ! is_string($json[$key] ?? null) || $json[$key] === '') {
                return null;
            }
        }

        return ['project_id' => $json['project_id'], 'client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function accessToken(array $credentials): ?string
    {
        $cached = Cache::get($this->cacheKey($credentials));

        if (is_string($cached)) {
            return $cached;
        }

        $jwt = $this->signedJwt($credentials);

        if ($jwt === null) {
            return null;
        }

        try {
            $response = $this->request()->asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        Cache::put($this->cacheKey($credentials), $token, now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 300)));

        return $token;
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function signedJwt(array $credentials): ?string
    {
        $now = time();
        $segments = [
            self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $key = openssl_pkey_get_private($credentials['private_key']);

        if ($key === false) {
            return null;
        }

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return implode('.', [...$segments, self::base64Url($signature)]);
    }

    /**
     * @param  array{project_id: string, client_email: string, private_key: string}  $credentials
     */
    private function cacheKey(array $credentials): string
    {
        return 'fcm.access_token.'.sha1($credentials['client_email']);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(10)->connectTimeout(5)->withOptions(['allow_redirects' => false]);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
```

Note: `'data' => (object) $data` makes an empty data array serialize as `{}` (FCM rejects `[]`); the test compares `$request['message']['data']` to an array, which Laravel decodes from the JSON object.

- [ ] **Step 6: Run the tests and make them pass**

Run the Step 3 command. Expected: PASS (all tests in the file).

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/Delivery/FcmClient.php app/Support/Delivery/FcmResult.php tests/Pest.php tests/Feature/FcmClientTest.php
git commit -m "Delivery: add an FCM v1 client with service-account JWT auth" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The `mobile_push` channel type and its schema

**Files:**
- Modify: `app/Enums/ChannelTransport.php` (add `case MobilePush = 'mobile_push';`)
- Create: `app/Support/ChannelTypes/MobilePushChannelType.php`
- Modify: `app/Support/ChannelTypes/ChannelTypeRegistry.php` (add `'mobile_push' => MobilePushChannelType::class,`)
- Create: `database/migrations/2026_10_05_000001_add_mobile_push_channel_type.php`
- Create: `database/migrations/2026_10_05_000002_add_device_columns_to_notification_channels_table.php`
- Modify: `database/seeders/ChannelTypeSeeder.php` (`SHIPPED` gains `['slug' => 'mobile_push', 'min_tier' => 'free', 'sort_order' => 10, 'enabled' => true]`)
- Modify: `app/Models/NotificationChannel.php` (add `personal_access_token_id` and `device_token_hash` to `#[Fillable]`)
- Modify: `database/factories/NotificationChannelFactory.php` (add `mobilePush()` state)
- Test: `tests/Feature/MobilePushChannelTypeTest.php`

**Interfaces:**
- Consumes: `ChannelType` contract (`app/Support/ChannelTypes/Contracts/ChannelType.php`).
- Produces: slug `mobile_push`; `ChannelTransport::MobilePush`; columns `notification_channels.personal_access_token_id` (nullable, unique, FK to `personal_access_tokens` `cascadeOnDelete`) and `notification_channels.device_token_hash` (nullable string(64), indexed); config shape `['fcm_token' => string, 'platform' => 'ios'|'android']`; factory `NotificationChannel::factory()->mobilePush(?string $token = null, string $platform = 'android')`.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/MobilePushChannelTypeTest.php`

```php
<?php

use App\Enums\ChannelTransport;
use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\MobilePushChannelType;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

test('the mobile app type is a free, non-slot, app-created channel hidden from the picker', function () {
    $type = app(ChannelTypeRegistry::class)->find('mobile_push');

    expect($type)->toBeInstanceOf(MobilePushChannelType::class)
        ->and($type->label())->toBe('Mobile app')
        ->and($type->transport())->toBe(ChannelTransport::MobilePush)
        ->and($type->countsTowardFreeSlot())->toBeFalse()
        ->and($type->supportsInlineCreate())->toBeFalse()
        ->and($type->supportsRichContent())->toBeFalse()
        ->and($type->isAvailable())->toBeFalse()
        ->and($type->destinationLabel())->toBe('Mobile device')
        ->and(app(ChannelTypeRegistry::class)->forPicker()->map(fn ($row) => $row['type']->slug())->all())->not->toContain('mobile_push');
});

test('the catalog row ships for free subscribers', function () {
    expect(DB::table('channel_types')->where('slug', 'mobile_push')->first())
        ->min_tier->toBe('free')->enabled->toBeTrue()->sort_order->toBe(10);
});

test('a device channel cannot be built by hand', function () {
    app(ChannelTypeRegistry::class)->find('mobile_push')->buildDestination([]);
})->throws(LogicException::class);

test('the destination shows only the platform, never the token', function (string $platform, string $summary) {
    $channel = NotificationChannel::factory()->mobilePush('super-secret-fcm-token', $platform)->make();

    $shown = app(ChannelTypeRegistry::class)->find('mobile_push')->displaySummary($channel);

    expect($shown)->toBe($summary)->and($shown)->not->toContain('super-secret');
})->with([
    'android' => ['android', 'Android device'],
    'ios' => ['ios', 'iPhone'],
    'unknown' => ['plan9', '—'],
]);

test('the channels table lists a device with its caption and offers Send Test', function () {
    $user = User::factory()->create();
    $channel = NotificationChannel::factory()->for($user)->mobilePush(platform: 'android')->create(['label' => 'Pixel 9']);

    $this->actingAs($user);

    livewire(ListNotificationChannels::class)
        ->assertCanSeeTableRecords([$channel])
        ->assertSee('Pixel 9')
        ->assertSee('Android device')
        ->assertSee('Mobile device')
        ->assertTableActionVisible('sendTest', $channel);
});

test('a device channel can be renamed and the rename touches nothing else', function () {
    $user = User::factory()->create();
    $channel = NotificationChannel::factory()->for($user)->mobilePush('keep-this-token')->create(['label' => 'Pixel 9']);
    $this->actingAs($user);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('edit')->table($channel), ['label' => 'Work phone'])
        ->assertHasNoFormErrors();

    expect($channel->fresh())
        ->label->toBe('Work phone')
        ->config->toBe(['fcm_token' => 'keep-this-token', 'platform' => 'android']);
});

test('removing the access token removes the device channel and its rule links', function () {
    $user = User::factory()->create();
    $accessToken = $user->createToken('Pixel 9', ['app'])->accessToken;
    $channel = NotificationChannel::factory()->for($user)->mobilePush()->create(['personal_access_token_id' => $accessToken->id]);
    $rule = AlertRule::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel->id);

    $accessToken->delete();

    expect(NotificationChannel::find($channel->id))->toBeNull()
        ->and(DB::table('alert_rule_notification_channel')->where('notification_channel_id', $channel->id)->count())->toBe(0);
});
```

(If `AlertRule::factory()` needs more than `->for($user)` to be valid, copy the minimal setup from `tests/Feature/ChannelDeletionGuardTest.php`.)

- [ ] **Step 2: Run to verify failure**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/MobilePushChannelTypeTest.php'`
Expected: FAIL (no `mobile_push` type, no factory state).

- [ ] **Step 3: Add the enum case** in `app/Enums/ChannelTransport.php`

```php
    case Mail = 'mail';
    case MobilePush = 'mobile_push';
```

- [ ] **Step 4: Create the channel type** — `app/Support/ChannelTypes/MobilePushChannelType.php`

```php
<?php

namespace App\Support\ChannelTypes;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\Contracts\ChannelType;
use LogicException;

/**
 * A phone signed in to the WxNotify app. These channels are created by the app's registration
 * endpoint (one per signed-in device), never by hand, so the type is hidden from the picker and
 * has no setup form. The stored config is the FCM token and the platform; only the platform is
 * ever shown.
 */
class MobilePushChannelType implements ChannelType
{
    public function slug(): string
    {
        return 'mobile_push';
    }

    public function label(): string
    {
        return 'Mobile app';
    }

    public function icon(): string
    {
        return 'heroicon-o-device-phone-mobile';
    }

    public function formSchema(): array
    {
        return [];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::MobilePush;
    }

    public function buildDestination(array $data): array
    {
        throw new LogicException('Mobile app channels are created by the app, not by hand.');
    }

    public function supportsInlineCreate(): bool
    {
        return false;
    }

    /**
     * Never offered in the "add a channel" picker: a phone appears when the app registers it.
     */
    public function isAvailable(): bool
    {
        return false;
    }

    public function supportsRichContent(): bool
    {
        return false;
    }

    /**
     * App-created, so it can't be blocked by (or use up) Free's single no-cost channel slot.
     */
    public function countsTowardFreeSlot(): bool
    {
        return false;
    }

    public function displaySummary(NotificationChannel $record): string
    {
        return match ($record->config['platform'] ?? null) {
            'android' => 'Android device',
            'ios' => 'iPhone',
            default => '—',
        };
    }

    public function destinationLabel(): string
    {
        return 'Mobile device';
    }
}
```

- [ ] **Step 5: Register it** — add `'mobile_push' => MobilePushChannelType::class,` as the last entry of `ChannelTypeRegistry::$classes`.

- [ ] **Step 6: Catalog migration** — `database/migrations/2026_10_05_000001_add_mobile_push_channel_type.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('channel_types')->where('slug', 'mobile_push')->exists()) {
            DB::table('channel_types')->insert([
                'slug' => 'mobile_push', 'enabled' => true, 'min_tier' => 'free', 'sort_order' => 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('channel_types')->where('slug', 'mobile_push')->delete();
    }
};
```

and add `['slug' => 'mobile_push', 'min_tier' => 'free', 'sort_order' => 10, 'enabled' => true],` after the `webpush` row of `ChannelTypeSeeder::SHIPPED`.

- [ ] **Step 7: Columns migration** — `database/migrations/2026_10_05_000002_add_device_columns_to_notification_channels_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            // A device channel lives and dies with the app token that registered it.
            $table->foreignId('personal_access_token_id')->nullable()->unique()
                ->constrained('personal_access_tokens')->cascadeOnDelete();
            // The FCM token itself is encrypted inside `config`; its hash makes "who holds this token" a query.
            $table->string('device_token_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personal_access_token_id');
            $table->dropColumn('device_token_hash');
        });
    }
};
```

(`dropConstrainedForeignId` also drops the unique index created by `->unique()` on that column in Postgres; if the rollback complains, drop `notification_channels_personal_access_token_id_unique` first.)

- [ ] **Step 8: Model and factory.** In `NotificationChannel`, extend the `#[Fillable]` list to `['user_id', 'channel_type', 'label', 'apprise_url', 'config', 'personal_access_token_id', 'device_token_hash']`. In `NotificationChannelFactory` add:

```php
    public function mobilePush(?string $token = null, string $platform = 'android'): static
    {
        $token ??= 'fcm-token-'.fake()->unique()->uuid();

        return $this->state(fn () => [
            'channel_type' => 'mobile_push',
            'label' => 'Pixel 9',
            'apprise_url' => null,
            'config' => ['fcm_token' => $token, 'platform' => $platform],
            'device_token_hash' => hash('sha256', $token),
        ]);
    }
```

- [ ] **Step 9: Run the new tests and the existing channel suites**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/MobilePushChannelTypeTest.php tests/Feature/ChannelTypesTest.php tests/Feature/ChannelTypeSeederTest.php tests/Feature/NotificationChannelResourceTest.php tests/Feature/ChannelDeletionGuardTest.php'`
Expected: PASS. If an existing test that loops over `ChannelTypeRegistry::all()` fails only because `mobile_push` has no form fields or Apprise URL, narrow that test with the smallest possible exclusion (for example `->reject(fn ($type) => $type->slug() === 'mobile_push')`) and say so in the report; do not weaken what the test checks for the other types.

- [ ] **Step 10: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app database tests
git commit -m "Channels: add the app-created mobile_push channel type" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: MobilePushTransport, the prune job and the dispatcher arm

**Files:**
- Create: `app/Support/Delivery/Transports/MobilePushTransport.php`
- Create: `app/Jobs/PruneMobilePushChannel.php`
- Modify: `app/Support/Delivery/ChannelDispatcher.php` (import + `ChannelTransport::MobilePush => app(MobilePushTransport::class),`)
- Test: `tests/Feature/MobilePushTransportTest.php`

**Interfaces:**
- Consumes: `FcmClient::send(...)`, `FcmResult` (Task 1); `NotificationChannel::factory()->mobilePush()` and `ChannelTransport::MobilePush` (Task 2); `SendTestNotification` (existing).
- Produces: `MobilePushTransport implements Transport`; `PruneMobilePushChannel::__construct(int $channelId, string $tokenHash)` (queued, `->delay(60 s)`).

- [ ] **Step 1: Write the failing tests** — `tests/Feature/MobilePushTransportTest.php`

```php
<?php

use App\Actions\Channels\SendTestNotification;
use App\Jobs\PruneMobilePushChannel;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->credentials = fcmUseCredentials();
    $this->channel = NotificationChannel::factory()->mobilePush('device-token-1')->create();
});

afterEach(function () {
    @unlink($this->credentials['path']);
});

test('an alert is pushed to the device with its subject as the title and the feed as the tap target', function () {
    fcmFakeGoogle();

    $result = app(ChannelDispatcher::class)->send($this->channel, 'Slight risk of severe storms this afternoon.', 'SPC Day 1 Convective Outlook');

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && $request['message']['token'] === 'device-token-1'
        && $request['message']['notification']['title'] === 'SPC Day 1 Convective Outlook'
        && $request['message']['notification']['body'] === 'Slight risk of severe storms this afternoon.'
        && $request['message']['data']['url'] === '/');
});

test('a long message is cut to about 200 characters', function () {
    fcmFakeGoogle();

    app(ChannelDispatcher::class)->send($this->channel, str_repeat('word ', 100), 'Title');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && mb_strlen($request['message']['notification']['body']) <= 203);
});

test('a missing title falls back to the product name', function () {
    fcmFakeGoogle();

    app(ChannelDispatcher::class)->send($this->channel, 'Body', null);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && $request['message']['notification']['title'] === 'WxNotify');
});

test('a device with no stored token fails without calling Firebase', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->mobilePush()->create(['config' => ['platform' => 'android']]);

    $result = app(ChannelDispatcher::class)->send($channel, 'Body', 'Title');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('This device channel has no push token; open the app to register it again.');
    Http::assertNothingSent();
});

test('an unconfigured server fails with the fixed message', function () {
    config(['services.firebase.service_account' => null]);
    Http::fake();

    $result = app(ChannelDispatcher::class)->send($this->channel, 'Body', 'Title');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Push is not configured on this server.');
});

test('a token Firebase no longer knows fails and queues a delayed prune of the channel', function () {
    Queue::fake();
    fcmFakeGoogle(Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404));

    $result = app(ChannelDispatcher::class)->send($this->channel, 'Body', 'Title');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('This device is no longer registered for push.')
        ->and($result->error)->not->toContain('device-token-1');
    Queue::assertPushed(PruneMobilePushChannel::class, fn (PruneMobilePushChannel $job) => $job->channelId === $this->channel->id
        && $job->tokenHash === sha1('device-token-1')
        && $job->delay === 60);
    expect($this->channel->fresh())->not->toBeNull();
});

test('other Firebase failures neither queue a prune nor leak anything', function () {
    Queue::fake();
    fcmFakeGoogle(Http::response(['error' => ['message' => 'secret detail device-token-1']], 503));

    $result = app(ChannelDispatcher::class)->send($this->channel, 'Body', 'Title');

    expect($result->error)->toBe('Firebase rejected the message (HTTP 503).');
    Queue::assertNothingPushed();
});

test('the prune job deletes the channel that still holds the failed token and keeps the delivery log', function () {
    $log = DeliveryLog::create([
        'user_id' => $this->channel->user_id,
        'notification_channel_id' => $this->channel->id,
        'channel_type' => 'mobile_push',
        'status' => 'failure',
        'dispatched_at' => now(),
    ]);

    (new PruneMobilePushChannel($this->channel->id, sha1('device-token-1')))->handle();

    expect(NotificationChannel::find($this->channel->id))->toBeNull()
        ->and($log->fresh())->not->toBeNull()
        ->and($log->fresh()->notification_channel_id)->toBeNull();
});

test('the prune job spares a channel whose phone registered a new token meanwhile', function () {
    $this->channel->forceFill(['config' => ['fcm_token' => 'device-token-2', 'platform' => 'android']])->save();

    (new PruneMobilePushChannel($this->channel->id, sha1('device-token-1')))->handle();

    expect(NotificationChannel::find($this->channel->id))->not->toBeNull();
});

test('the prune job ignores a channel of another type and one that is already gone', function () {
    $discord = NotificationChannel::factory()->create();

    (new PruneMobilePushChannel($discord->id, sha1('x')))->handle();
    (new PruneMobilePushChannel(999999, sha1('x')))->handle();

    expect(NotificationChannel::find($discord->id))->not->toBeNull();
});

test('the Send Test action reaches a device channel', function () {
    fcmFakeGoogle();

    expect(app(SendTestNotification::class)($this->channel))->toBeTrue();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && $request['message']['notification']['title'] === 'WxNotify test notification');
});
```

(`DeliveryLog::create` here must satisfy its `$fillable`/required columns; copy a valid minimal row from `DeliverWeatherAlert`'s `DeliveryLog::create([...])` call if `subject`/`alert_rule_id` are required. `unused User` import must be removed if not needed.)

- [ ] **Step 2: Run to verify failure**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/MobilePushTransportTest.php'`
Expected: FAIL (`UnhandledMatchError` / class not found).

- [ ] **Step 3: Create the job** — `php artisan make:job PruneMobilePushChannel --no-interaction`, then:

```php
<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a device channel whose FCM token Firebase said is no longer valid. It runs a minute
 * after the failed send so the delivery-log row is written first, and it only deletes a channel
 * that still holds the token that failed: a phone that registered a fresh token in the meantime
 * keeps its channel. The queue payload carries a hash of the token, never the token.
 */
class PruneMobilePushChannel implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $channelId, public string $tokenHash) {}

    public function handle(): void
    {
        $channel = NotificationChannel::query()
            ->where('id', $this->channelId)
            ->where('channel_type', 'mobile_push')
            ->first();

        if ($channel === null) {
            return;
        }

        if (hash_equals($this->tokenHash, sha1((string) ($channel->config['fcm_token'] ?? '')))) {
            $channel->delete();
        }
    }
}
```

(Match the exact `Queueable` import used by sibling jobs in `app/Jobs`.)

- [ ] **Step 4: Create the transport** — `app/Support/Delivery/Transports/MobilePushTransport.php`

```php
<?php

namespace App\Support\Delivery\Transports;

use App\Jobs\PruneMobilePushChannel;
use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\FcmClient;
use Illuminate\Support\Str;

/**
 * Pushes one alert to one signed-in phone. Failure text comes from FcmClient (fixed strings plus a
 * status), never from a response body, an exception or the device token.
 */
class MobilePushTransport implements Transport
{
    /** Long enough for DeliverWeatherAlert to write its delivery-log row first. */
    public const PRUNE_DELAY_SECONDS = 60;

    public function __construct(private readonly FcmClient $fcm) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        $token = $channel->config['fcm_token'] ?? null;

        if (blank($token)) {
            return DeliveryResult::failed('This device channel has no push token; open the app to register it again.');
        }

        // The tap target is always the feed: the delivery-log row (the alert's id) does not exist yet.
        $result = $this->fcm->send(
            $token,
            $title ?? 'WxNotify',
            Str::limit($body, 200, preserveWords: true),
            ['url' => '/', 'type' => (string) ($meta['type'] ?? 'alert')],
        );

        if ($result->success) {
            return DeliveryResult::delivered();
        }

        if ($result->tokenInvalid) {
            PruneMobilePushChannel::dispatch($channel->id, sha1($token))->delay(self::PRUNE_DELAY_SECONDS);
        }

        return DeliveryResult::failed((string) $result->error);
    }
}
```

- [ ] **Step 5: Wire the dispatcher** — add `use App\Support\Delivery\Transports\MobilePushTransport;` and, in `transportFor()`, `ChannelTransport::MobilePush => app(MobilePushTransport::class),`.

- [ ] **Step 6: Run the tests and make them pass**

Run the Step 2 command, then also `tests/Feature/ChannelDispatcherTest.php`. Expected: PASS. (`$job->delay === 60`: `delay(60)` stores the int on the job; if the project's Laravel stores an interval/Carbon instead, assert the equivalent on `$job->delay`.)

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app tests
git commit -m "Delivery: send alerts to device channels through FCM, pruning dead tokens" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Registration API (`PUT` / `DELETE /api/v1/push-token`)

**Files:**
- Create: `app/Actions/Channels/RegisterMobilePushDevice.php`
- Create: `app/Actions/Channels/UnregisterMobilePushDevice.php`
- Create: `app/Http/Requests/RegisterPushTokenRequest.php`
- Create: `app/Http/Controllers/Api/V1/PushTokenController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/ApiPushTokenTest.php`

**Interfaces:**
- Consumes: `NotificationChannel` columns and factory from Task 2; the existing `/api/v1` authenticated route group.
- Produces: `PUT /api/v1/push-token` `{token, platform: ios|android}` → 204; `DELETE /api/v1/push-token` → 204; route names `api.v1.push-token.update`, `api.v1.push-token.destroy`; `RegisterMobilePushDevice::__invoke(User $user, PersonalAccessToken $accessToken, string $fcmToken, string $platform): NotificationChannel`; `UnregisterMobilePushDevice::__invoke(User $user, PersonalAccessToken $accessToken): void`.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/ApiPushTokenTest.php`

```php
<?php

use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->accessToken = $this->user->createToken('Pixel 9', ['app']);
    $this->bearer = $this->accessToken->plainTextToken;
});

function pushRegister($test, array $body = [], ?string $bearer = null)
{
    return $test->withToken($bearer ?? $test->bearer)->putJson('/api/v1/push-token', $body + ['token' => 'fcm-token-1', 'platform' => 'android']);
}

test('registering creates a verified device channel tied to the calling token', function () {
    pushRegister($this)->assertNoContent();

    $channel = NotificationChannel::query()->where('channel_type', 'mobile_push')->sole();

    expect($channel->user_id)->toBe($this->user->id)
        ->and($channel->label)->toBe('Pixel 9')
        ->and($channel->personal_access_token_id)->toBe($this->accessToken->accessToken->id)
        ->and($channel->isVerified())->toBeTrue()
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->config)->toBe(['fcm_token' => 'fcm-token-1', 'platform' => 'android'])
        ->and($channel->device_token_hash)->toBe(hash('sha256', 'fcm-token-1'));
});

test('registering again from the same sign-in updates the one channel and keeps a renamed label', function () {
    pushRegister($this)->assertNoContent();
    NotificationChannel::query()->where('channel_type', 'mobile_push')->update(['label' => 'My phone']);

    pushRegister($this, ['token' => 'fcm-token-2', 'platform' => 'ios'])->assertNoContent();

    $channel = NotificationChannel::query()->where('channel_type', 'mobile_push')->sole();
    expect($channel->label)->toBe('My phone')
        ->and($channel->config)->toBe(['fcm_token' => 'fcm-token-2', 'platform' => 'ios'])
        ->and($channel->device_token_hash)->toBe(hash('sha256', 'fcm-token-2'));
});

test('a rule that already uses the channel keeps using it after a token refresh', function () {
    pushRegister($this)->assertNoContent();
    $channel = NotificationChannel::query()->where('channel_type', 'mobile_push')->sole();
    AlertRule::factory()->for($this->user)->create()->notificationChannels()->attach($channel->id);

    pushRegister($this, ['token' => 'fcm-token-2'])->assertNoContent();

    expect(DB::table('alert_rule_notification_channel')->where('notification_channel_id', $channel->id)->count())->toBe(1);
});

test('a phone handed to another account stops receiving the old account\'s alerts', function () {
    $other = User::factory()->create();
    $stale = NotificationChannel::factory()->for($other)->mobilePush('fcm-token-1')->create();

    pushRegister($this)->assertNoContent();

    expect(NotificationChannel::find($stale->id))->toBeNull()
        ->and(NotificationChannel::query()->where('channel_type', 'mobile_push')->sole()->user_id)->toBe($this->user->id);
});

test('an older sign-in of the same account on the same phone gives up its channel', function () {
    $older = $this->user->createToken('Pixel 9 (old install)', ['app']);
    $stale = NotificationChannel::factory()->for($this->user)->mobilePush('fcm-token-1')
        ->create(['personal_access_token_id' => $older->accessToken->id]);

    pushRegister($this)->assertNoContent();

    expect(NotificationChannel::find($stale->id))->toBeNull()
        ->and(NotificationChannel::query()->where('channel_type', 'mobile_push')->count())->toBe(1);
});

test('the request is validated', function (array $body) {
    $this->withToken($this->bearer)->putJson('/api/v1/push-token', $body)->assertUnprocessable();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->count())->toBe(0);
})->with([
    'no token' => [['platform' => 'ios']],
    'empty token' => [['token' => '', 'platform' => 'ios']],
    'token too long' => [['token' => str_repeat('a', 4097), 'platform' => 'ios']],
    'no platform' => [['token' => 'x']],
    'unknown platform' => [['token' => 'x', 'platform' => 'windows']],
]);

test('unregistering removes the calling token\'s channel and is repeatable', function () {
    pushRegister($this)->assertNoContent();

    $this->withToken($this->bearer)->deleteJson('/api/v1/push-token')->assertNoContent();
    $this->withToken($this->bearer)->deleteJson('/api/v1/push-token')->assertNoContent();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->count())->toBe(0);
});

test('unregistering leaves other devices alone', function () {
    pushRegister($this)->assertNoContent();
    $otherPhone = $this->user->createToken('iPad', ['app']);
    pushRegister($this, ['token' => 'fcm-token-ipad', 'platform' => 'ios'], $otherPhone->plainTextToken)->assertNoContent();

    $this->withToken($this->bearer)->deleteJson('/api/v1/push-token')->assertNoContent();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->sole()->label)->toBe('iPad');
});

test('signing out or revoking a device removes its channel', function () {
    pushRegister($this)->assertNoContent();

    $this->withToken($this->bearer)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->count())->toBe(0);
});

test('requests without the app ability are refused', function () {
    $script = $this->user->createToken('My script', ['api'])->plainTextToken;

    pushRegister($this, bearer: $script)->assertForbidden();
    $this->withToken($script)->deleteJson('/api/v1/push-token')->assertForbidden();
    $this->putJson('/api/v1/push-token', ['token' => 'x', 'platform' => 'ios'])->assertUnauthorized();
});

test('the push token is never echoed, logged or written to the activity log', function () {
    pushRegister($this, ['token' => 'super-secret-fcm-token'])->assertNoContent()->assertContent('');

    expect(Activity::query()->get()->toJson())->not->toContain('super-secret-fcm-token');
    // The registration logs the channel as created, attributed to the subscriber.
    $entry = Activity::query()->where('subject_type', NotificationChannel::class)->where('event', 'created')->first();
    expect($entry)->not->toBeNull()->and($entry->causer_id)->toBe($this->user->id);
});
```

(`pushRegister` is a file-local helper; give it a unique name if another feature file already defines a global `pushRegister`.)

- [ ] **Step 2: Run to verify failure**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/ApiPushTokenTest.php'`
Expected: FAIL (404 on the routes).

- [ ] **Step 3: Create the Form Request** — `app/Http/Requests/RegisterPushTokenRequest.php`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterPushTokenRequest extends FormRequest
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
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['required', Rule::in(['ios', 'android'])],
        ];
    }
}
```

- [ ] **Step 4: Create the actions**

`app/Actions/Channels/RegisterMobilePushDevice.php`:

```php
<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Creates or refreshes the channel for one signed-in phone (one per app token). A request has no
 * session user and the CauserResolver override ignores causedBy(), so each write pins its causer.
 * The FCM token is a secret: it lives only in the encrypted `config`, plus a one-way hash used to
 * find stale holders of the same token.
 */
class RegisterMobilePushDevice
{
    public function __invoke(User $user, PersonalAccessToken $accessToken, string $fcmToken, string $platform): NotificationChannel
    {
        $hash = hash('sha256', $fcmToken);
        $resolver = app(CauserResolver::class);

        // The same phone under another account (it was handed on) or an older sign-in (a reinstall)
        // must stop receiving through its old channel.
        NotificationChannel::query()
            ->where('channel_type', 'mobile_push')
            ->where('device_token_hash', $hash)
            ->where(fn ($query) => $query->whereNull('personal_access_token_id')
                ->orWhere('personal_access_token_id', '!=', $accessToken->id))
            ->with('user')
            ->get()
            ->each(fn (NotificationChannel $stale) => $resolver->withCauser($stale->user, fn () => $stale->delete()));

        $channel = null;

        $resolver->withCauser($user, function () use (&$channel, $user, $accessToken, $fcmToken, $platform, $hash): void {
            $save = fn (): NotificationChannel => tap(
                NotificationChannel::query()->firstOrNew(['personal_access_token_id' => $accessToken->id]),
                fn (NotificationChannel $device) => $device->forceFill([
                    'user_id' => $user->id,
                    'channel_type' => 'mobile_push',
                    // A label the subscriber renamed on the dashboard survives a token refresh.
                    'label' => $device->exists ? $device->label : Str::limit($accessToken->name, 80, ''),
                    'apprise_url' => null,
                    'config' => ['fcm_token' => $fcmToken, 'platform' => $platform],
                    'device_token_hash' => $hash,
                    // The phone proved itself by enrolling under an authenticated app token.
                    'verified_at' => $device->verified_at ?? now(),
                ])->save(),
            );

            try {
                $channel = $save();
            } catch (UniqueConstraintViolationException) {
                // Two registrations for one sign-in raced; the loser updates the winner's row.
                $channel = $save();
            }
        });

        return $channel;
    }
}
```

`app/Actions/Channels/UnregisterMobilePushDevice.php`:

```php
<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Turns push off for one phone: removes the channel its app token registered. Nothing to do (and
 * no error) when the phone never registered or already unregistered.
 */
class UnregisterMobilePushDevice
{
    public function __invoke(User $user, PersonalAccessToken $accessToken): void
    {
        NotificationChannel::query()
            ->where('user_id', $user->id)
            ->where('personal_access_token_id', $accessToken->id)
            ->get()
            ->each(fn (NotificationChannel $channel) => app(CauserResolver::class)->withCauser($user, fn () => $channel->delete()));
    }
}
```

- [ ] **Step 5: Create the controller** — `app/Http/Controllers/Api/V1/PushTokenController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Channels\RegisterMobilePushDevice;
use App\Actions\Channels\UnregisterMobilePushDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterPushTokenRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class PushTokenController extends Controller
{
    /**
     * Register (or refresh) this phone's FCM token. Idempotent; never returns the token.
     */
    public function update(RegisterPushTokenRequest $request, RegisterMobilePushDevice $register): Response
    {
        $register(
            $request->user(),
            $this->accessToken($request),
            $request->validated('token'),
            $request->validated('platform'),
        );

        return response()->noContent();
    }

    /**
     * Turn push off for this phone.
     */
    public function destroy(Request $request, UnregisterMobilePushDevice $unregister): Response
    {
        $unregister($request->user(), $this->accessToken($request));

        return response()->noContent();
    }

    private function accessToken(Request $request): PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken, 403);

        return $token;
    }
}
```

- [ ] **Step 6: Routes** — in `routes/api.php`, import `PushTokenController` and add inside the authenticated group (after the `alerts/{id}` route):

```php
        Route::put('/push-token', [PushTokenController::class, 'update'])->name('api.v1.push-token.update');
        Route::delete('/push-token', [PushTokenController::class, 'destroy'])->name('api.v1.push-token.destroy');
```

- [ ] **Step 7: Run the tests and make them pass**

Run the Step 2 command plus `tests/Feature/ApiMeTest.php tests/Feature/AppTokenExchangeTest.php tests/Feature/SignedInDevicesPageTest.php`. Expected: PASS. If the activity-log assertion in the last test fails because the `created` entry has a null causer, the `withCauser` wrap in `RegisterMobilePushDevice` is wrong; fix the action, not the test.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app routes tests
git commit -m "API: register and unregister a phone's push token" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: App — API client, local push state, the registration listener

**Files:**
- Create: `mobile/app/Support/PushState.php`
- Create: `mobile/app/Services/PushRegistration.php`
- Create: `mobile/app/Listeners/RegisterPushToken.php`
- Modify: `mobile/app/Services/WxNotifyApi.php` (add `registerPushToken()` and `unregisterPushToken()`)
- Modify: `mobile/app/Services/SignOut.php` (clear push state in `clearLocal()`)
- Modify: `mobile/tests/Feature/NativePluginsTest.php` (guard the Firebase plugin)
- Test: `mobile/tests/Feature/PushRegistrationTest.php`

**Interfaces:**
- Consumes: `PUT/DELETE /api/v1/push-token` (Task 4); `Native\Mobile\Facades\PushNotifications` (`checkPermission(): ?string`, `enroll()`, `getToken(): ?string`); `Native\Mobile\Facades\System::isIos()`; `Native\Mobile\Events\PushNotification\TokenGenerated(string $token, ?string $id)`.
- Produces: `PushState` (`wantsPush(): bool`, `declined(): bool`, `markEnabled()`, `markDisabled()`, `markDeclined()`, `markRegistered()`, `registeredWithin(int $hours): bool`, `clear()`); `PushRegistration` (`permission(): ?string`, `shouldPrompt(): bool`, `enable(): void`, `decline(): void`, `disable(): bool`, `register(string $token): void`, `refreshIfDue(): void`); `WxNotifyApi::registerPushToken(string $token, string $platform): void`, `unregisterPushToken(): void`.

- [ ] **Step 1: Write the failing tests** — `mobile/tests/Feature/PushRegistrationTest.php`

```php
<?php

use App\Services\PushRegistration;
use App\Services\SignOut;
use App\Support\PushState;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    System::shouldReceive('isIos')->andReturn(false)->byDefault();
});

function pushRegistered(string $token = 'fcm-1', string $platform = 'android'): Closure
{
    return fn (Request $r) => $r->method() === 'PUT'
        && $r->url() === 'https://wx.test/api/v1/push-token'
        && $r['token'] === $token
        && $r['platform'] === $platform;
}

test('the prompt is offered only while undecided and the OS has not refused', function (?string $permission, bool $declined, bool $enabled, bool $expected) {
    PushNotifications::shouldReceive('checkPermission')->andReturn($permission);
    $state = new PushState;
    $declined ? $state->markDeclined() : null;
    $enabled ? $state->markEnabled() : null;

    expect(app(PushRegistration::class)->shouldPrompt())->toBe($expected);
})->with([
    'not asked yet' => ['not_determined', false, false, true],
    'already granted, never opted in' => ['granted', false, false, true],
    'said not now' => ['not_determined', true, false, false],
    'already on' => ['granted', false, true, false],
    'refused by the OS' => ['denied', false, false, false],
    'not on a device' => [null, false, false, false],
]);

test('enabling remembers the choice and asks the OS to enroll', function () {
    PushNotifications::shouldReceive('enroll')->once();

    app(PushRegistration::class)->enable();

    expect((new PushState)->wantsPush())->toBeTrue();
});

test('"not now" is remembered and never undone by turning push on later', function () {
    $registration = app(PushRegistration::class);
    $registration->decline();

    expect((new PushState)->declined())->toBeTrue();

    PushNotifications::shouldReceive('enroll')->once();
    $registration->enable();

    expect((new PushState)->declined())->toBeFalse()->and((new PushState)->wantsPush())->toBeTrue();
});

test('a token the OS hands over is registered with the server and the platform', function (bool $ios, string $platform) {
    System::shouldReceive('isIos')->andReturn($ios);
    Http::fake(['wx.test/api/v1/push-token' => Http::response(null, 204)]);
    (new PushState)->markEnabled();

    event(new TokenGenerated('fcm-1'));

    Http::assertSent(pushRegistered('fcm-1', $platform));
    expect((new PushState)->registeredWithin(1))->toBeTrue();
})->with([[false, 'android'], [true, 'ios']]);

test('a token arriving after the subscriber turned push off, or signed out, registers nothing', function () {
    Http::fake();

    event(new TokenGenerated('fcm-1'));
    (new TokenStore)->forget();
    (new PushState)->markEnabled();
    event(new TokenGenerated('fcm-1'));

    Http::assertNothingSent();
});

test('a server refusal while registering is swallowed so the app carries on', function () {
    Http::fake(['wx.test/api/v1/push-token' => Http::response([], 503)]);
    (new PushState)->markEnabled();

    event(new TokenGenerated('fcm-1'));

    expect((new PushState)->registeredWithin(1))->toBeFalse();
});

test('turning push off tells the server and remembers it', function () {
    Http::fake(['wx.test/api/v1/push-token' => Http::response(null, 204)]);
    (new PushState)->markEnabled();

    $done = app(PushRegistration::class)->disable();

    expect($done)->toBeTrue()->and((new PushState)->wantsPush())->toBeFalse()->and((new PushState)->declined())->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/push-token');
});

test('push stays on when the server could not be told it is off', function () {
    Http::fake(['wx.test/api/v1/push-token' => Http::response([], 503)]);
    (new PushState)->markEnabled();

    expect(app(PushRegistration::class)->disable())->toBeFalse()->and((new PushState)->wantsPush())->toBeTrue();
});

test('the token is re-registered on launch once a day while push is on', function () {
    Http::fake(['wx.test/api/v1/push-token' => Http::response(null, 204)]);
    PushNotifications::shouldReceive('getToken')->andReturn('fcm-1');
    (new PushState)->markEnabled();

    app(PushRegistration::class)->refreshIfDue();
    app(PushRegistration::class)->refreshIfDue();

    Http::assertSentCount(1);
    Http::assertSent(pushRegistered('fcm-1'));
});

test('nothing is re-registered while push is off or there is no token yet', function () {
    Http::fake();
    PushNotifications::shouldReceive('getToken')->andReturn(null);

    app(PushRegistration::class)->refreshIfDue();
    (new PushState)->markEnabled();
    app(PushRegistration::class)->refreshIfDue();

    Http::assertNothingSent();
});

test('signing out forgets every push flag', function () {
    $state = new PushState;
    $state->markEnabled();
    $state->markDeclined();
    $state->markRegistered();
    Http::fake(['*' => Http::response(null, 204)]);

    app(SignOut::class)();

    expect($state->wantsPush())->toBeFalse()->and($state->declined())->toBeFalse()->and($state->registeredWithin(48))->toBeFalse();
});
```

- [ ] **Step 2: Add the plugin guard row** to `mobile/tests/Feature/NativePluginsTest.php` (import `Native\Mobile\Providers\PushNotificationsServiceProvider`):

```php
    'PushNotifications (permission, enrollment and the FCM token)' => PushNotificationsServiceProvider::class,
```

- [ ] **Step 3: Run to verify failure**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/PushRegistrationTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 4: Create `PushState`** — `mobile/app/Support/PushState.php`

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * What the app remembers about push on this phone: whether the subscriber turned it on, said
 * "not now" to the prompt, and when the token was last sent to the server. Signing out forgets
 * all of it. None of it is secret (the FCM token itself is never stored here).
 */
class PushState
{
    private const ENABLED = 'wxnotify.push.enabled';

    private const DECLINED = 'wxnotify.push.declined';

    private const REGISTERED_AT = 'wxnotify.push.registered_at';

    public function wantsPush(): bool
    {
        return (bool) Cache::get(self::ENABLED, false);
    }

    public function declined(): bool
    {
        return (bool) Cache::get(self::DECLINED, false);
    }

    /** Push on: also withdraws an earlier "not now". */
    public function markEnabled(): void
    {
        Cache::forever(self::ENABLED, true);
        Cache::forget(self::DECLINED);
    }

    /** Push off by choice: the prompt must not come back by itself. */
    public function markDisabled(): void
    {
        Cache::forget(self::ENABLED);
        Cache::forget(self::REGISTERED_AT);
        Cache::forever(self::DECLINED, true);
    }

    public function markDeclined(): void
    {
        Cache::forever(self::DECLINED, true);
    }

    public function markRegistered(): void
    {
        Cache::forever(self::REGISTERED_AT, now()->toIso8601String());
    }

    public function registeredWithin(int $hours): bool
    {
        $at = Cache::get(self::REGISTERED_AT);

        return $at !== null && now()->subHours($hours)->lessThan($at);
    }

    public function clear(): void
    {
        Cache::forget(self::ENABLED);
        Cache::forget(self::DECLINED);
        Cache::forget(self::REGISTERED_AT);
    }
}
```

(`lessThan($at)` needs a Carbon: use `\Carbon\Carbon::parse($at)` — write `now()->subHours($hours)->lessThan(Carbon::parse($at))` and import `Carbon\Carbon`.)

- [ ] **Step 5: API client methods** in `mobile/app/Services/WxNotifyApi.php` (after `signOut()`):

```php
    public function registerPushToken(string $token, string $platform): void
    {
        $this->send(fn (PendingRequest $request) => $request->put('/api/v1/push-token', ['token' => $token, 'platform' => $platform]));
    }

    public function unregisterPushToken(): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete('/api/v1/push-token'));
    }
```

- [ ] **Step 6: Create `PushRegistration`** — `mobile/app/Services/PushRegistration.php`

```php
<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Support\PushState;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;

/**
 * Everything the app does about push: when to prompt, turning it on and off, and keeping the
 * server's copy of the FCM token current. Registering never throws at the screens: a server hiccup
 * leaves the token to be sent on a later launch.
 */
class PushRegistration
{
    /** Re-send the token on launch when the last send is older than this (hours). */
    private const REFRESH_AFTER_HOURS = 20;

    public function __construct(private WxNotifyApi $api, private PushState $state) {}

    /**
     * "granted", "denied", "not_determined", "provisional" or "ephemeral"; null off a device.
     */
    public function permission(): ?string
    {
        return PushNotifications::checkPermission();
    }

    public function shouldPrompt(): bool
    {
        return ! $this->state->declined()
            && ! $this->state->wantsPush()
            && in_array($this->permission(), ['not_determined', 'granted', 'provisional'], true);
    }

    public function enable(): void
    {
        $this->state->markEnabled();

        PushNotifications::enroll();
    }

    public function decline(): void
    {
        $this->state->markDeclined();
    }

    /**
     * @return bool false when the server could not be told, in which case push stays on
     */
    public function disable(): bool
    {
        try {
            $this->api->unregisterPushToken();
        } catch (ApiException) {
            return false;
        }

        $this->state->markDisabled();

        return true;
    }

    public function register(string $token): void
    {
        if (! $this->state->wantsPush()) {
            return;
        }

        try {
            $this->api->registerPushToken($token, System::isIos() ? 'ios' : 'android');
        } catch (ApiException) {
            return;
        }

        $this->state->markRegistered();
    }

    /**
     * On launch while push is on: send the token again if it has not been sent today, which also
     * recreates a channel the server pruned.
     */
    public function refreshIfDue(): void
    {
        if (! $this->state->wantsPush() || $this->state->registeredWithin(self::REFRESH_AFTER_HOURS)) {
            return;
        }

        $token = PushNotifications::getToken();

        if ($token !== null) {
            $this->register($token);
        }
    }
}
```

(`Unauthenticated` extends `ApiException`, so a 401 is also swallowed here; the screens that call the API directly already handle sign-out on 401.)

- [ ] **Step 7: Create the listener** — `mobile/app/Listeners/RegisterPushToken.php` (Laravel discovers it from the type-hinted `handle()`):

```php
<?php

namespace App\Listeners;

use App\Services\PushRegistration;
use Native\Mobile\Events\PushNotification\TokenGenerated;

/**
 * A plain listener, not a screen handler, so a token the OS rotates is picked up even when no
 * push screen is mounted. Does nothing unless the subscriber turned push on and is signed in.
 */
class RegisterPushToken
{
    public function __construct(private PushRegistration $registration) {}

    public function handle(TokenGenerated $event): void
    {
        $this->registration->register($event->token);
    }
}
```

When signed out, `WxNotifyApi::send` throws `Unauthenticated` (an `ApiException`) which `register()` swallows, so no extra guard is needed; the test "signed out registers nothing" proves it.

- [ ] **Step 8: Clear on sign-out** — in `SignOut`, inject `PushState $push` and call `$this->push->clear();` in `clearLocal()` (constructor: `private TokenStore $tokens, private WxNotifyApi $api, private LocalCache $cache, private PushState $push`).

- [ ] **Step 9: Run the tests and the whole mobile suite**

Run: `cd mobile && vendor/bin/pest --compact` and `cd mobile && php artisan native:validate`
Expected: PASS and validate clean. (If `event(new TokenGenerated(...))` does not reach the listener, the listener is not auto-discovered: register it explicitly in `mobile/app/Providers/AppServiceProvider.php` with `Event::listen(TokenGenerated::class, RegisterPushToken::class)`; do not change the test.)

- [ ] **Step 10: Pint and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile
git commit -m "App: register the FCM token and remember the push choice" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: App — the permission prompt after sign-in

**Files:**
- Create: `mobile/app/NativeComponents/PushPrompt.php`
- Create: `mobile/resources/views/native/push-prompt.blade.php`
- Modify: `mobile/routes/mobile.php` (`Route::native('/enable-push', PushPrompt::class);`)
- Modify: `mobile/app/NativeComponents/AuthCallback.php` (go to the prompt when due)
- Modify: `mobile/app/NativeComponents/AlertsScreen.php` (call `refreshIfDue()` at the end of `mount()`)
- Modify: `mobile/tests/Feature/ScreensRenderTest.php` (add the new screen)
- Test: `mobile/tests/Feature/PushPromptTest.php`; extend `mobile/tests/Feature/SignInScreensTest.php` and `mobile/tests/Feature/AlertsScreenTest.php`

**Interfaces:**
- Consumes: `PushRegistration` (Task 5); `RequiresSignIn` trait; existing screens' style (`native:` elements, `bg-theme-*` tokens).
- Produces: route `/enable-push` (screen `PushPrompt`: `enable()`, `notNow()`); `AuthCallback` replaces with `/enable-push` instead of `/` when `PushRegistration::shouldPrompt()`.

- [ ] **Step 1: Write the failing tests** — `mobile/tests/Feature/PushPromptTest.php`

```php
<?php

use App\NativeComponents\PushPrompt;
use App\Support\PushState;
use App\Support\TokenStore;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Testing\Native;

test('the prompt explains why and offers Enable and Not now', function () {
    (new TokenStore)->put('9|secret');

    Native::test(PushPrompt::class)
        ->assertSee('Get alerts on this phone')
        ->assertSee('Enable notifications')
        ->assertSee('Not now');
});

test('Enable turns push on, asks the OS and continues to the feed', function () {
    (new TokenStore)->put('9|secret');
    PushNotifications::shouldReceive('enroll')->once();

    Native::test(PushPrompt::class)->tap('Enable notifications')->assertReplacedWith('/');

    expect((new PushState)->wantsPush())->toBeTrue();
});

test('Not now is remembered and continues to the feed without asking the OS', function () {
    (new TokenStore)->put('9|secret');
    PushNotifications::shouldReceive('enroll')->never();

    Native::test(PushPrompt::class)->tap('Not now')->assertReplacedWith('/');

    expect((new PushState)->declined())->toBeTrue()->and((new PushState)->wantsPush())->toBeFalse();
});

test('a signed-out visitor is sent to sign in', function () {
    Native::test(PushPrompt::class)->assertReplacedWith('/sign-in');
});
```

Add to `SignInScreensTest.php`:

```php
test('a valid callback goes to the push prompt while the subscriber has not decided', function () {
    (new TokenStore)->begin('v', 'state-1');
    Http::fake(['*' => Http::response(['token' => '5|abc', 'expires_at' => '2027-01-01T00:00:00+00:00', 'user' => ['id' => 5]])]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined');

    Native::test(AuthCallback::class, ['code' => 'c', 'state' => 'state-1'])->assertReplacedWith('/enable-push');
});
```

(Existing "a valid callback … replaces the screen with the feed" test: in a test environment `checkPermission()` returns `null`, so `shouldPrompt()` is false and it still lands on `/`; keep it unchanged.) Import `Native\Mobile\Facades\PushNotifications` in that file.

Add to `AlertsScreenTest.php` a test that opening the feed while push is on and not yet registered today sends the token:

```php
test('opening the feed while push is on re-registers the token once a day', function () {
    (new TokenStore)->put('9|secret');
    (new PushState)->markEnabled();
    PushNotifications::shouldReceive('getToken')->andReturn('fcm-1');
    Http::fake([
        'wx.test/api/v1/alerts*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null]]),
        'wx.test/api/v1/push-token' => Http::response(null, 204),
    ]);

    Native::test(AlertsScreen::class);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/push-token');
});
```

(Copy the imports and the Http fake shape from the neighbouring tests in that file; `System::isIos` is a facade call, so add `System::shouldReceive('isIos')->andReturn(false);` if the facade is not already stubbed there.)

- [ ] **Step 2: Run to verify failure**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/PushPromptTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Create the screen** — `mobile/app/NativeComponents/PushPrompt.php`

```php
<?php

namespace App\NativeComponents;

use App\Services\PushRegistration;
use App\Support\Concerns\RequiresSignIn;
use Native\Mobile\Edge\NativeComponent;

/**
 * Shown once, right after the first sign-in: explains push before the OS asks, so the system
 * dialog is not the subscriber's first sight of it. "Not now" is remembered; Account can turn it
 * on later.
 */
class PushPrompt extends NativeComponent
{
    use RequiresSignIn;

    public function mount(): void
    {
        $this->redirectIfSignedOut();
    }

    public function enable(): void
    {
        app(PushRegistration::class)->enable();

        $this->replace('/');
    }

    public function notNow(): void
    {
        app(PushRegistration::class)->decline();

        $this->replace('/');
    }
}
```

Create `mobile/resources/views/native/push-prompt.blade.php`:

```blade
<native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-theme-background">
    <native:text class="text-2xl font-bold text-theme-on-background text-center">Get alerts on this phone</native:text>
    <native:text class="text-base text-theme-on-surface-variant text-center">WxNotify can send the alerts you choose straight to this phone as notifications. You pick which alerts in your alert rules at wxnotify.com.</native:text>

    <native:button label="Enable notifications" @tap="enable" class="w-full" />
    <native:button label="Not now" variant="secondary" @tap="notNow" class="w-full" />
</native:column>
```

- [ ] **Step 4: Route, callback and feed.** In `mobile/routes/mobile.php` import `PushPrompt` and add `Route::native('/enable-push', PushPrompt::class);`. In `AuthCallback::mount()` replace the final `$this->replace('/');` with:

```php
        $this->replace(app(PushRegistration::class)->shouldPrompt() ? '/enable-push' : '/');
```

(import `App\Services\PushRegistration`). At the end of `AlertsScreen::mount()` (after `$this->refresh();`) add `app(PushRegistration::class)->refreshIfDue();` (import it). Add `PushPrompt` to the screen list in `ScreensRenderTest.php` following that file's existing pattern.

- [ ] **Step 5: Run the mobile suite and the validator**

Run: `cd mobile && vendor/bin/pest --compact && php artisan native:validate`
Expected: PASS; validate clean (if the validator rejects the view, rewrite the markup to the element forms it accepts and keep the visible strings).

- [ ] **Step 6: Pint and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile
git commit -m "App: ask for notification permission after the first sign-in" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: App — the Account screen's push row

**Files:**
- Modify: `mobile/app/NativeComponents/AccountScreen.php`
- Modify: `mobile/resources/views/native/account-screen.blade.php`
- Test: extend `mobile/tests/Feature/AccountScreenTest.php`

**Interfaces:**
- Consumes: `PushRegistration` (`permission()`, `enable()`, `disable()`), `PushState::wantsPush()`; `Native\Mobile\Facades\System::appSettings()` (opens the OS app settings).
- Produces: Account shows a "Push notifications" status (`On`, `Off`, `Blocked in system settings`) with a matching button (`Turn off`, `Turn on`, `Open system settings`) and the note "Choose which alerts go to this phone in your alert rules at wxnotify.com."

- [ ] **Step 1: Write the failing tests** (append to `AccountScreenTest.php`; add imports `App\Support\PushState`, `Native\Mobile\Facades\PushNotifications`, `Native\Mobile\Facades\System`):

```php
test('the push row says Off with a Turn on button until push is enabled', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined');

    Native::test(AccountScreen::class)
        ->assertSee('Push notifications')
        ->assertSee('Off')
        ->assertSee('Turn on')
        ->assertDontSee('Turn off')
        ->assertSee('Choose which alerts go to this phone in your alert rules at wxnotify.com.');
});

test('the push row says On with a Turn off button while push is on and allowed', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');
    (new PushState)->markEnabled();

    Native::test(AccountScreen::class)->assertSee('On')->assertSee('Turn off')->assertDontSee('Turn on');
});

test('Turn on enrolls the device', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined');
    PushNotifications::shouldReceive('enroll')->once();

    Native::test(AccountScreen::class)->tap('Turn on');

    expect((new PushState)->wantsPush())->toBeTrue();
});

test('Turn off removes the phone from the server and flips the row', function () use ($profile) {
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/push-token' => Http::response(null, 204),
    ]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');
    (new PushState)->markEnabled();

    Native::test(AccountScreen::class)->tap('Turn off')->assertSee('Turn on');

    expect((new PushState)->wantsPush())->toBeFalse();
});

test('Turn off that cannot reach the server says so and stays on', function () use ($profile) {
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/push-token' => Http::response([], 503),
    ]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');
    (new PushState)->markEnabled();

    Native::test(AccountScreen::class)->tap('Turn off')->assertSee('Couldn\'t turn push off')->assertSee('Turn off');

    expect((new PushState)->wantsPush())->toBeTrue();
});

test('when the OS refused notifications the row points at the system settings', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('denied');
    System::shouldReceive('appSettings')->once();

    Native::test(AccountScreen::class)
        ->assertSee('Blocked in system settings')
        ->assertDontSee('Turn on')
        ->tap('Open system settings');
});
```

The existing Account tests do not stub `PushNotifications`; with the real facade `checkPermission()` returns `null` off a device, which the screen treats as Off — they keep passing. If any existing test breaks, stub the facade in a `beforeEach`.

- [ ] **Step 2: Run to verify failure**

Run: `cd mobile && vendor/bin/pest --compact tests/Feature/AccountScreenTest.php`
Expected: FAIL (no push row).

- [ ] **Step 3: Update `AccountScreen`** — add imports `App\Services\PushRegistration`, `App\Support\PushState`, `Native\Mobile\Facades\System`; add:

```php
    public ?string $pushMessage = null;

    /**
     * 'on', 'off' or 'blocked' (the OS refused notifications, so only the system settings can change it).
     */
    public function pushStatus(): string
    {
        if (app(PushRegistration::class)->permission() === 'denied') {
            return 'blocked';
        }

        return app(PushState::class)->wantsPush() ? 'on' : 'off';
    }

    public function pushStatusLabel(): string
    {
        return match ($this->pushStatus()) {
            'on' => 'On',
            'blocked' => 'Blocked in system settings',
            default => 'Off',
        };
    }

    public function enablePush(): void
    {
        $this->pushMessage = null;

        app(PushRegistration::class)->enable();
    }

    public function disablePush(): void
    {
        $this->pushMessage = app(PushRegistration::class)->disable()
            ? null
            : 'Couldn\'t turn push off. Check your connection and try again.';
    }

    public function openSystemSettings(): void
    {
        System::appSettings();
    }
```

- [ ] **Step 4: Update the view** — in `account-screen.blade.php`, between the profile column and the version line add:

```blade
    <native:column class="w-full gap-2">
        <native:text class="text-base font-bold text-theme-on-background">Push notifications</native:text>
        <native:text class="text-base text-theme-on-surface-variant">{{ $this->pushStatusLabel() }}</native:text>

        @if ($this->pushStatus() === 'on')
            <native:button label="Turn off" variant="secondary" @tap="disablePush" class="w-full" />
        @elseif ($this->pushStatus() === 'blocked')
            <native:button label="Open system settings" variant="secondary" @tap="openSystemSettings" class="w-full" />
        @else
            <native:button label="Turn on" variant="secondary" @tap="enablePush" class="w-full" />
        @endif

        @if ($pushMessage)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $pushMessage }}</native:text>
        @endif

        <native:text class="text-sm text-theme-on-surface-variant">Choose which alerts go to this phone in your alert rules at wxnotify.com.</native:text>
    </native:column>
```

- [ ] **Step 5: Run the mobile suite and the validator**

Run: `cd mobile && vendor/bin/pest --compact && php artisan native:validate`
Expected: PASS; validate clean.

- [ ] **Step 6: Pint and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile
git commit -m "App: add a push notifications row to Account" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Rules, spec corrections and the backend/mobile suites

**Files:**
- Modify: `.ai/rules/delivery.md` (append a section)
- Modify: `.ai/rules/api.md` (append a section)
- Modify: `docs/superpowers/specs/2026-10-04-mobile-push-design.md` (apply the five rulings above; remove the `channel_id` mention)

- [ ] **Step 1: Append to `.ai/rules/delivery.md`**

```markdown

## Mobile push goes through FcmClient: fixed strings only, a delayed token-guarded prune
`MobilePushTransport` sends through `App\Support\Delivery\FcmClient` (service-account JWT signed with openssl; token cached ~55 min; fixed hosts `oauth2.googleapis.com` and `fcm.googleapis.com`; redirects off; one attempt). The device's FCM token is a secret: `FcmResult` and `DeliveryResult` errors are fixed strings plus the numeric HTTP status, never a response body, exception message, token or credential (the body is read only to recognise an `UNREGISTERED` / invalid-token reply). When FCM says the token is dead, the transport queues `PruneMobilePushChannel` with a 60 s delay (so `DeliverWeatherAlert` writes its log row first) and a hash of the failed token; the job deletes the channel only if it still holds that token, so a phone that re-registered survives. The plugin owns the Android notification channel (`fcm_default_channel`): never send an `android.notification.channel_id`. Device channels (`mobile_push`) are created only by the registration API, are verified on creation, do not use Free's no-cost channel slot and are hidden from the picker through `isAvailable() === false`.
```

- [ ] **Step 2: Append to `.ai/rules/api.md`**

```markdown

## /push-token never returns, logs or audits the FCM token
`PUT /api/v1/push-token` creates or refreshes the calling app token's `mobile_push` channel (one per `personal_access_token_id`, deleted with the token by the database cascade) and answers 204 with no body; `DELETE` removes it. The FCM token is stored only in the encrypted `config`, plus `device_token_hash` (SHA-256) so a token held by another channel (a phone handed to another account, an older sign-in) is found with a query and that channel is deleted. Every write pins the activity causer with `withCauser`; a subscriber's rename of the channel label survives a token refresh.
```

- [ ] **Step 3: Update the spec** — replace the `android.notification` channel wording ("on a 'Weather alerts' notification channel") with "the plugin's own channel, no `channel_id`", add `device_token_hash`, "verified on creation", "`countsTowardFreeSlot()` false", "hidden through `isAvailable()` false instead of a new contract method", and the prune job's token guard.

- [ ] **Step 4: Run both suites**

Run the backend suite in the container (never fewer than 6 processes, per project rule):
`docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`
Run the mobile suite: `cd mobile && vendor/bin/pest --compact && php artisan native:validate`
Expected: all pass (backend ~2300+ tests, mobile all, validate clean).

- [ ] **Step 5: Commit**

```bash
git add .ai docs
git commit -m "Docs: record the mobile push rules and tighten the spec" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Device verification on the Android emulator (controller task, not an implementer)

This task is run by the controller after the final review and one fix wave. It needs the owner for the build.

**Prerequisites (owner):** `mobile/.env` has `NATIVEPHP_APP_ID=com.wxnotify.dev.app` (done); `mobile/google-services.json` and `mobile/GoogleService-Info.plist` present and git-ignored (done); the app rebuilt and reinstalled with `cd mobile && php artisan native:run android` (the owner runs it from a terminal whose emulator has LAN access, see the memory note); a Google Play system image emulator (FCM does not work on an image without Play services).

- [ ] **Step 1: Confirm LAN reach and the install.** `adb devices`; `adb shell "toybox nc -z -w 5 <dev-server-ip> 443; echo exit=\$?"` must print `exit=0`; `adb shell pm list packages | grep com.wxnotify.dev.app`.
- [ ] **Step 2: Sign in** (the owner signs in; never type credentials), then screenshot: the "Get alerts on this phone" prompt appears. Tap Enable, accept the system dialog.
- [ ] **Step 3: Server state.** In the container: `php artisan tinker --execute 'echo App\Models\NotificationChannel::where("channel_type","mobile_push")->count();'` must print 1; confirm the label is the device name and `verified_at` is set. Account shows "On".
- [ ] **Step 4: Attach to a rule and send a real push.** Attach the channel to one of `user@host.com`'s rules with tinker (`$rule->notificationChannels()->attach($channel->id)`), then send a test through the dispatcher: `app(App\Actions\Channels\SendTestNotification::class)($channel)` must return true, a notification appears on the emulator (`adb shell dumpsys notification --noredact | grep -i wxnotify` or a screenshot of the shade), tapping it opens the app on the feed.
- [ ] **Step 5: Dead-token path.** Corrupt nothing in production data: change the channel's stored token to a made-up one via tinker, send a test, expect failure text "This device is no longer registered for push."; run `PruneMobilePushChannel` by hand and confirm the channel is gone. Then re-open the app, confirm the daily/launch registration recreates the channel (clear `wxnotify.push.registered_at` through a sign-out/sign-in if needed).
- [ ] **Step 6: Turn off and revoke.** Account → Turn off: the channel disappears; turn on again; then revoke the device on the "Signed-in devices" page (or sign out) and confirm the channel and its rule link are gone.
- [ ] **Step 7: Record the outcome** in the memory file `mobile-app-foundation-state.md` (what was verified on a device, what was not: iOS needs a physical device and an APNs key in Firebase), then ask the owner before pushing the commits.

If Step 2 or 4 shows the plugin's `TokenGenerated` does not reach the plain listener (no `PUT /push-token` in the server log `docker logs wxnotify-nextgen-front-end-1`), fix it by adding an `#[On(TokenGenerated::class)]` handler on `PushPrompt`/`AccountScreen` that calls `PushRegistration::register()` (foreground path) and keep the listener; report the finding.
