# Mobile Location (the phone as a second mobile source) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A subscriber signed in to the mobile app can turn on "Share this phone's location"; the phone then keeps one of their mobile Locations current in the background, so alert rules on that Location match where they are.

**Architecture:** A new `MobileSource::App`. A mobile Location is linked to a phone by `locations.app_token_id` (FK to the phone's app token, `nullOnDelete`: the Location is never deleted when the phone goes away). Turning sharing on mints a narrow second Sanctum token (ability `location`, `parent_token_id` = the app token, cascading) that the plugin's native beacon uses to `POST /api/v1/location`; that endpoint writes the Location's position through a shared action that Traccar's poller also uses for its reverse-geocode decision. The app drives it from an Account toggle plus an explainer screen, re-arms on launch, and stops on sign-out.

**Tech Stack:** Laravel 13 / PHP 8.4 / Filament v5 / Pest 5 / Sanctum; NativePHP v4 mobile with `nativephp/mobile-geolocation` 2.1 (`Geolocation::sharePosition()` beacon, `backgroundWatchStatus()`, `stopBackgroundWatch()`), already installed and registered. No new Composer/npm packages.

**Spec:** `docs/superpowers/specs/2026-10-05-mobile-location-design.md`

## Global Constraints

- **No new dependencies** (Composer or npm).
- **Never delete a Location because its phone went away.** `alert_rule_location` cascades on Location delete, so a delete would strip rules of their location. Signing out, revoking the device, or turning sharing off only sets `locations.app_token_id` to null.
- **Tokens:** an app token has abilities `['app']`; a beacon token has exactly `['location']`, is created with explicit abilities (never the default `*`), has `parent_token_id` = the minting app token, and is returned only by `PUT /api/v1/location-sharing`. A beacon token reaches exactly one route (`POST /api/v1/location`); `ability:app` routes refuse it and `ability:location` routes refuse an app token. `personal_access_token_id`-style link columns (`app_token_id`, `parent_token_id`) are set only from server-side values with `forceFill`, never from request input and never added to `#[Fillable]`.
- **No token and no coordinate** in any response (other than the one `PUT` token), log line, activity-log row or exception message.
- **Activity log:** session-less API code pins the causer with `app(CauserResolver::class)->withCauser($user, fn () => …)` (`causedBy()` is ignored; `.ai/rules/actions.md`). A position write must not create an activity row: wrap it in `$location->disableLogging()` / `enableLogging()` exactly as `PollTraccarPositions` does.
- **API rules** (`.ai/rules/api.md`): `/api/v1`, `auth:sanctum` + the right `ability:`, every query scoped to the token's user.
- **Tests:** Pest feature tests; `Http::fake()` for every outbound call. Backend tests run in the container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest <files>'`. Mobile tests run on the host: `cd mobile && vendor/bin/pest --compact`; `cd mobile && php artisan native:validate` must stay clean. Sanctum caches the authenticated user inside one test: when a test switches bearer tokens between requests, call `app('auth')->forgetGuards()` before the second request (see `ApiPushTokenTest`).
- **Never run `native:run`, `native:jump` or any build** — the owner runs builds.
- **Style:** `vendor/bin/pint --dirty --format agent` before each commit (from `mobile/` for the app). Commit messages end with the line `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Commit to `main`; do not push.
- **Rulings recorded while planning** (read the spec first; these refine it):
  1. `ExtendAppTokenExpiry` already works for any `PersonalAccessToken` with an `expires_at` (it reads `config('wxnotify.app_auth.token_days')`), so it is reused unchanged for beacon tokens; only its docblock is updated.
  2. `Location::hasMissingDevice()` is generalised: for an app-source mobile Location it is true while `app_token_id` is null; for Traccar it is unchanged. The dashboard table, form and Needs-attention rows all read it (or the same two conditions in SQL).
  3. The explainer screen is reached from the Account toggle (not shown automatically after sign-in, unlike push).
  4. Permission status is asynchronous in the plugin (events), so the app derives state from the synchronous `Geolocation::backgroundWatchStatus()` plus its own `wanted` flag: wanted and an active share = On; wanted and no active share = "Waiting for location permission" with an Open settings button; not wanted = Off. "While using only" is shown only where the status carries `authorization === 'whenInUse'` (iOS).
  5. The beacon watch id is the constant `wxnotify-location`; accuracy is balanced (`fineAccuracy: false`) with `minDistance(250)`.

---

### Task 1: Schema, `MobileSource::App` and the Location model

**Files:**
- Modify: `app/Enums/MobileSource.php`
- Create: `database/migrations/2026_10_05_100001_add_app_token_to_locations_table.php`
- Create: `database/migrations/2026_10_05_100002_add_parent_token_to_personal_access_tokens_table.php`
- Modify: `app/Models/Location.php`
- Modify: `database/factories/LocationFactory.php`
- Test: `tests/Feature/LocationAppSourceTest.php`

**Interfaces:**
- Produces: `MobileSource::App` (label "WxNotify app"); `locations.app_token_id` (nullable, unique, FK `personal_access_tokens`, `nullOnDelete`); `personal_access_tokens.parent_token_id` (nullable FK to itself, `cascadeOnDelete`); `Location::appToken(): BelongsTo`; `Location::hasMissingDevice()` generalised (ruling 2); factory state `Location::factory()->mobileApp(?PersonalAccessToken $token = null)`.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/LocationAppSourceTest.php`

```php
<?php

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->appToken = $this->user->createToken('Pixel 9', ['app'])->accessToken;
});

test('the app is a mobile source', function () {
    expect(MobileSource::from('app'))->toBe(MobileSource::App)
        ->and(MobileSource::App->label())->toBe('WxNotify app');
});

test('an app location is linked to its phone and has no Traccar fields', function () {
    $location = Location::factory()->for($this->user)->mobileApp($this->appToken)->create();

    expect($location->mobile_source)->toBe(MobileSource::App)
        ->and($location->type)->toBe(LocationType::Mobile)
        ->and($location->appToken->is($this->appToken))->toBeTrue()
        ->and($location->traccar_server_id)->toBeNull()
        ->and($location->mobile_update_interval_minutes)->toBe(5);
});

test('removing the phone unlinks its location but never deletes it or its rule links', function () {
    $location = Location::factory()->for($this->user)->mobileApp($this->appToken)->create();
    $rule = AlertRule::factory()->for($this->user)->create();
    $rule->locations()->attach($location->id);

    $this->appToken->delete();

    $location->refresh();
    expect($location->app_token_id)->toBeNull()
        ->and(Location::find($location->id))->not->toBeNull()
        ->and(DB::table('alert_rule_location')->where('location_id', $location->id)->count())->toBe(1);
});

test('one phone can be linked to one location only', function () {
    Location::factory()->for($this->user)->mobileApp($this->appToken)->create();

    Location::factory()->for($this->user)->mobileApp($this->appToken)->create();
})->throws(UniqueConstraintViolationException::class);

test('a location made stationary drops its phone link', function () {
    $location = Location::factory()->for($this->user)->mobileApp($this->appToken)->create();

    $location->update(['type' => LocationType::Stationary, 'lat' => 35.1, 'lng' => -97.1, 'address' => '1 Main St']);

    expect($location->fresh())
        ->app_token_id->toBeNull()
        ->mobile_source->toBeNull()
        ->mobile_update_interval_minutes->toBeNull();
});

test('an app location counts as missing its device exactly while no phone is linked', function () {
    $linked = Location::factory()->for($this->user)->mobileApp($this->appToken)->create();
    $unlinked = Location::factory()->for($this->user)->mobileApp()->create();
    $traccar = Location::factory()->for($this->user)->mobile()->create();
    $traccarGone = Location::factory()->for($this->user)->mobile()->create(['traccar_device_missing_at' => now()]);

    expect($linked->hasMissingDevice())->toBeFalse()
        ->and($unlinked->hasMissingDevice())->toBeTrue()
        ->and($traccar->hasMissingDevice())->toBeFalse()
        ->and($traccarGone->hasMissingDevice())->toBeTrue();
});

test('a beacon token is removed with the app token that minted it', function () {
    $beacon = $this->user->createToken('Location beacon - Pixel 9', ['location'])->accessToken;
    $beacon->forceFill(['parent_token_id' => $this->appToken->id])->save();
    $other = $this->user->createToken('Location beacon - iPad', ['location'])->accessToken;

    $this->appToken->delete();

    expect(DB::table('personal_access_tokens')->where('id', $beacon->id)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('id', $other->id)->exists())->toBeTrue();
});
```

(`AlertRule::factory()` may need more than `->for($user)`; copy the minimal setup from `tests/Feature/ChannelDeletionGuardTest.php`.)

- [ ] **Step 2: Run to verify failure**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/LocationAppSourceTest.php'`
Expected: FAIL (no `MobileSource::App`, no `mobileApp()` state).

- [ ] **Step 3: Enum** — `app/Enums/MobileSource.php`

```php
enum MobileSource: string
{
    case Traccar = 'traccar';
    case App = 'app';

    public function label(): string
    {
        return match ($this) {
            self::Traccar => 'Traccar',
            self::App => 'WxNotify app',
        };
    }
}
```

- [ ] **Step 4: Migrations**

`database/migrations/2026_10_05_100001_add_app_token_to_locations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // The phone that keeps this location's position current. A location is never deleted
            // with its phone (alert_rule_location cascades), so the link just goes null.
            $table->foreignId('app_token_id')->nullable()->unique()
                ->constrained('personal_access_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_token_id');
        });
    }
};
```

`database/migrations/2026_10_05_100002_add_parent_token_to_personal_access_tokens_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // A location beacon token is minted by an app token and dies with it.
            $table->foreignId('parent_token_id')->nullable()
                ->constrained('personal_access_tokens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_token_id');
        });
    }
};
```

- [ ] **Step 5: Model** — in `app/Models/Location.php`: add `use Laravel\Sanctum\PersonalAccessToken;` and `use Illuminate\Database\Eloquent\Relations\BelongsTo` is already imported. Do NOT add `app_token_id` to `#[Fillable]`. In the `saving` hook's stationary branch add `'app_token_id' => null,` to the `forceFill` array. Add the relation and replace `hasMissingDevice()`:

```php
    public function appToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'app_token_id');
    }

    /**
     * Whether this mobile location has lost the device that keeps it current: a Traccar
     * device deleted from the server, or an app location whose phone signed out, was
     * revoked or stopped sharing. Its last position is kept either way.
     */
    public function hasMissingDevice(): bool
    {
        if ($this->type !== LocationType::Mobile) {
            return false;
        }

        return $this->mobile_source === MobileSource::App
            ? $this->app_token_id === null
            : $this->traccar_device_missing_at !== null;
    }
```

- [ ] **Step 6: Factory** — in `database/factories/LocationFactory.php` add (import `Laravel\Sanctum\PersonalAccessToken`):

```php
    /**
     * A mobile location kept current by a phone running the app (no Traccar link). Pass the
     * phone's app token to link it; leave it null for a location whose phone is gone.
     */
    public function mobileApp(?PersonalAccessToken $token = null): static
    {
        return $this->state(fn () => [
            'type' => LocationType::Mobile,
            'address' => null,
            'lat' => null,
            'lng' => null,
            'mobile_source' => MobileSource::App,
            'mobile_update_interval_minutes' => 5,
            'traccar_server_id' => null,
            'traccar_device_id' => null,
            'traccar_device_name' => null,
            'app_token_id' => $token?->id,
        ]);
    }
```

(`app_token_id` is not fillable, but factories use `forceFill`-style attribute setting through `create()` with state attributes, which bypasses mass assignment; if it does not here, set it in an `afterMaking` callback with `forceFill`.)

- [ ] **Step 7: Run the new tests and the existing location suites**

Run: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest tests/Feature/LocationAppSourceTest.php tests/Feature/GetAlertsNeedingAttentionTest.php tests/Feature/PollTraccarPositionsTest.php'` (use the real names of the existing Traccar poller and Locations test files; `ls tests/Feature | grep -i "location\|traccar"`).
Expected: PASS.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app database tests
git commit -m "Locations: add the app as a mobile source with a phone link" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: A shared position action and address-lookup action

**Files:**
- Create: `app/Actions/Locations/QueueAddressLookup.php`
- Create: `app/Actions/Locations/RecordMobilePosition.php`
- Modify: `app/Console/Commands/PollTraccarPositions.php` (use `QueueAddressLookup`, delete its private `queueAddressLookup()`)
- Test: `tests/Feature/RecordMobilePositionTest.php`

**Interfaces:**
- Consumes: `Location::needsAddressLookup()`, `ReverseGeocodeLocation` job, `Integrations::enabled(Integration::ReverseGeocoding)`.
- Produces: `QueueAddressLookup::__invoke(Location $location): void`; `RecordMobilePosition::__invoke(Location $location, float $lat, float $lng, CarbonInterface $fixedAt): bool` (false when the fix is older than the stored one).

- [ ] **Step 1: Write the failing tests** — `tests/Feature/RecordMobilePositionTest.php`

```php
<?php

use App\Actions\Locations\RecordMobilePosition;
use App\Jobs\ReverseGeocodeLocation;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->location = Location::factory()->for(User::factory())->mobileApp()->create();
});

test('a fix sets the position, the fix time and the receipt time', function () {
    $this->freezeTime();
    $fixedAt = now()->subMinutes(2);

    $applied = app(RecordMobilePosition::class)($this->location, 35.2, -97.4, $fixedAt);

    $fresh = $this->location->fresh();
    expect($applied)->toBeTrue()
        ->and((float) $fresh->lat)->toBe(35.2)
        ->and((float) $fresh->lng)->toBe(-97.4)
        ->and($fresh->mobile_position_fixed_at->equalTo($fixedAt))->toBeTrue()
        ->and($fresh->mobile_position_updated_at->equalTo(now()))->toBeTrue();
});

test('a fix older than the stored one is ignored', function () {
    $this->location->forceFill(['lat' => 35.2, 'lng' => -97.4, 'mobile_position_fixed_at' => now()])->save();

    $applied = app(RecordMobilePosition::class)($this->location, 10.0, 10.0, now()->subHour());

    expect($applied)->toBeFalse()->and((float) $this->location->fresh()->lat)->toBe(35.2);
});

test('a fix with the same time as the stored one is applied again', function () {
    $this->freezeTime();
    $this->location->forceFill(['lat' => 35.2, 'lng' => -97.4, 'mobile_position_fixed_at' => now()])->save();

    expect(app(RecordMobilePosition::class)($this->location, 35.3, -97.5, now()))->toBeTrue();
});

test('recording a position writes no activity row', function () {
    $before = Activity::count();

    app(RecordMobilePosition::class)($this->location, 35.2, -97.4, now());

    expect(Activity::count())->toBe($before);
});

test('an address lookup is queued for a first position and for a move of 100 m or more, not for a small one', function () {
    Queue::fake();
    // Reverse geocoding is on by default in tests; this makes the precondition explicit.
    setIntegrationSetting('reverse_geocoding_enabled', true);

    app(RecordMobilePosition::class)($this->location, 35.2, -97.4, now()->subMinutes(10));
    Queue::assertPushed(ReverseGeocodeLocation::class, 1);

    // The lookup job records where it resolved; pretend it has run.
    $this->location->refresh()->forceFill(['address_geocoded_lat' => 35.2, 'address_geocoded_lng' => -97.4])->save();
    Queue::fake();

    app(RecordMobilePosition::class)($this->location->fresh(), 35.2001, -97.4001, now()->subMinutes(5));
    Queue::assertNothingPushed();

    app(RecordMobilePosition::class)($this->location->fresh(), 35.3, -97.4, now());
    Queue::assertPushed(ReverseGeocodeLocation::class, 1);
});
```

`setIntegrationSetting()` is the existing helper in `tests/Pest.php`; if the integration also needs an endpoint value to read as enabled, set `reverse_geocoding_endpoint` the way `tests/Feature/IntegrationSettingsTest.php` does.

- [ ] **Step 2: Run to verify failure**

Run the new file in the container. Expected: FAIL (classes not found).

- [ ] **Step 3: Create `app/Actions/Locations/QueueAddressLookup.php`** (moved from the poller's private method, same behaviour)

```php
<?php

namespace App\Actions\Locations;

use App\Enums\Integration;
use App\Jobs\ReverseGeocodeLocation;
use App\Models\Location;
use App\Support\Integrations;
use Throwable;

/**
 * A new position needs a street address when the location was never resolved or has moved far
 * enough since it was. Queued, so a slow geocoder never slows the write, and never allowed to
 * fail it. Shared by the Traccar poller and the app's position endpoint.
 */
class QueueAddressLookup
{
    public function __invoke(Location $location): void
    {
        try {
            if (Integrations::enabled(Integration::ReverseGeocoding) && $location->needsAddressLookup()) {
                ReverseGeocodeLocation::dispatch($location->id);
            }
        } catch (Throwable) {
            // The next position tries again.
        }
    }
}
```

- [ ] **Step 4: Create `app/Actions/Locations/RecordMobilePosition.php`**

```php
<?php

namespace App\Actions\Locations;

use App\Models\Location;
use Carbon\CarbonInterface;

/**
 * Applies one reported fix to a mobile location. A fix older than the stored one is ignored
 * (a late beacon must not move the pin backwards). The write must not create an activity row:
 * the model logs lat/lng so a subscriber's own repositioning is recorded, which a position that
 * changes every few minutes forever would drown, so logging is switched off around this save,
 * exactly as PollTraccarPositions does.
 */
class RecordMobilePosition
{
    public function __construct(private readonly QueueAddressLookup $lookup) {}

    public function __invoke(Location $location, float $lat, float $lng, CarbonInterface $fixedAt): bool
    {
        if ($location->mobile_position_fixed_at !== null && $fixedAt->lt($location->mobile_position_fixed_at)) {
            return false;
        }

        $location->disableLogging();

        try {
            $location->forceFill([
                'lat' => $lat,
                'lng' => $lng,
                'mobile_position_updated_at' => now(),
                'mobile_position_fixed_at' => $fixedAt,
            ])->save();
        } finally {
            $location->enableLogging();
        }

        ($this->lookup)($location);

        return true;
    }
}
```

- [ ] **Step 5: Use it in the poller.** In `PollTraccarPositions` inject nothing new: replace `$this->queueAddressLookup($location);` with `app(QueueAddressLookup::class)($location);`, delete the private `queueAddressLookup()` method, and remove the now-unused imports (`Integration`, `ReverseGeocodeLocation`, `Integrations`, `Throwable` only if nothing else uses them; check before removing).

- [ ] **Step 6: Run the new tests and the Traccar poller tests**

Expected: PASS (the poller tests prove the refactor kept behaviour).

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app tests
git commit -m "Locations: share the position write and address lookup between sources" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The beacon endpoint (`POST /api/v1/location`)

**Files:**
- Create: `app/Actions/Locations/RecordBeaconPoints.php`
- Create: `app/Http/Requests/StoreLocationPointsRequest.php`
- Create: `app/Http/Controllers/Api/V1/LocationController.php`
- Modify: `routes/api.php`
- Modify: `app/Providers/AppServiceProvider.php` (limiter `app-location`)
- Modify: `app/Http/Middleware/ExtendAppTokenExpiry.php` (docblock only)
- Test: `tests/Feature/ApiLocationBeaconTest.php`

**Interfaces:**
- Consumes: `RecordMobilePosition` (Task 2); `Location::factory()->mobileApp()` and the `parent_token_id` column (Task 1).
- Produces: route `POST /api/v1/location` (name `api.v1.location.store`, middleware `auth:sanctum`, `ability:location`, `ExtendAppTokenExpiry`, `throttle:app-location`) answering 204; `RecordBeaconPoints::__invoke(PersonalAccessToken $beacon, array $points): ?Location` (null = no linked Location).

- [ ] **Step 1: Write the failing tests** — `tests/Feature/ApiLocationBeaconTest.php`

```php
<?php

use App\Jobs\ReverseGeocodeLocation;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->app = $this->user->createToken('Pixel 9', ['app']);
    $this->location = Location::factory()->for($this->user)->mobileApp($this->app->accessToken)->create(['label' => 'Pixel 9']);
    $this->beacon = $this->user->createToken('Location beacon - Pixel 9', ['location'], now()->addDays(90));
    $this->beacon->accessToken->forceFill(['parent_token_id' => $this->app->accessToken->id])->save();
});

function beaconPoint(array $overrides = []): array
{
    return $overrides + ['latitude' => 35.2, 'longitude' => -97.4, 'accuracy' => 8.5, 'timestamp' => now()->subMinute()->toIso8601String()];
}

function beaconPost($test, array $points, ?string $bearer = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($bearer ?? $test->beacon->plainTextToken)->postJson('/api/v1/location', ['points' => $points]);
}

test('the newest point of a batch moves the linked location', function () {
    $this->freezeTime();

    beaconPost($this, [
        beaconPoint(['latitude' => 10.0, 'longitude' => 10.0, 'timestamp' => now()->subMinutes(5)->toIso8601String()]),
        beaconPoint(['latitude' => 35.2, 'longitude' => -97.4, 'timestamp' => now()->subMinute()->toIso8601String()]),
    ])->assertNoContent();

    $fresh = $this->location->fresh();
    expect((float) $fresh->lat)->toBe(35.2)
        ->and((float) $fresh->lng)->toBe(-97.4)
        ->and($fresh->mobile_position_fixed_at->equalTo(now()->subMinute()))->toBeTrue()
        ->and($fresh->mobile_position_updated_at->equalTo(now()))->toBeTrue();
});

test('a point older than the stored fix is accepted and ignored', function () {
    $this->location->forceFill(['lat' => 35.2, 'lng' => -97.4, 'mobile_position_fixed_at' => now()])->save();

    beaconPost($this, [beaconPoint(['latitude' => 10.0, 'timestamp' => now()->subHour()->toIso8601String()])])->assertNoContent();

    expect((float) $this->location->fresh()->lat)->toBe(35.2);
});

test('the request is validated', function (array $points) {
    beaconPost($this, $points)->assertUnprocessable();

    expect($this->location->fresh()->lat)->toBeNull();
})->with([
    'empty batch' => [[]],
    'too many points' => [fn () => array_fill(0, 51, beaconPoint())],
    'latitude out of range' => [[beaconPoint(['latitude' => 91])]],
    'longitude out of range' => [[beaconPoint(['longitude' => -181])]],
    'not numeric' => [[beaconPoint(['latitude' => 'north'])]],
    'no timestamp' => [[['latitude' => 35.2, 'longitude' => -97.4]]],
    'unparseable timestamp' => [[beaconPoint(['timestamp' => 'yesterday-ish'])]],
    'timestamp too far ahead' => [[beaconPoint(['timestamp' => now()->addMinutes(10)->toIso8601String()])]],
]);

test('an unlinked phone gets a 404 and nothing is written', function () {
    $this->location->forceFill(['app_token_id' => null])->save();

    beaconPost($this, [beaconPoint()])->assertNotFound();

    expect($this->location->fresh()->lat)->toBeNull();
});

test('a location that belongs to another account is never written', function () {
    $this->location->forceFill(['user_id' => User::factory()->create()->id])->save();

    beaconPost($this, [beaconPoint()])->assertNotFound();

    expect($this->location->fresh()->lat)->toBeNull();
});

test('the two token abilities do not cross', function () {
    beaconPost($this, [beaconPoint()], $this->app->plainTextToken)->assertForbidden();

    foreach (['/api/v1/me', '/api/v1/location-sharing'] as $path) {
        app('auth')->forgetGuards();
        $this->withToken($this->beacon->plainTextToken)->getJson($path)->assertForbidden();
    }
    app('auth')->forgetGuards();
    $this->withToken($this->beacon->plainTextToken)->putJson('/api/v1/push-token', ['token' => 'x', 'platform' => 'ios'])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($this->beacon->plainTextToken)->deleteJson('/api/v1/auth/token')->assertForbidden();
});

test('a request without a token is refused', function () {
    $this->postJson('/api/v1/location', ['points' => [beaconPoint()]])->assertUnauthorized();
});

test('using the beacon token keeps it alive for as long as it is used', function () {
    $this->freezeTime();
    $this->beacon->accessToken->forceFill(['expires_at' => now()->addDays(80)])->save();

    beaconPost($this, [beaconPoint()])->assertNoContent();

    expect($this->beacon->accessToken->fresh()->expires_at->toDateString())->toBe(now()->addDays(90)->toDateString());
});

test('a position write adds no activity row', function () {
    $before = Activity::count();

    beaconPost($this, [beaconPoint()])->assertNoContent();

    expect(Activity::count())->toBe($before);
});

test('a first position queues the address lookup', function () {
    Queue::fake();
    setIntegrationSetting('reverse_geocoding_enabled', true);

    beaconPost($this, [beaconPoint()])->assertNoContent();

    Queue::assertPushed(ReverseGeocodeLocation::class);
});

test('the endpoint is rate limited per token', function () {
    foreach (range(1, 30) as $i) {
        beaconPost($this, [beaconPoint()])->assertNoContent();
    }

    beaconPost($this, [beaconPoint()])->assertStatus(429);
});
```

(The "too many points" dataset case builds its array lazily: if the dataset helper does not accept a closure, compute the array inside the test body instead.)

- [ ] **Step 2: Run to verify failure** — Expected: FAIL (404 on the route).

- [ ] **Step 3: Limiter** — in `AppServiceProvider::boot()` after `app-api`:

```php
        // One request per few seconds per beacon token is normal (the plugin clamps its interval to
        // 5 s at best); more than this is a runaway client.
        RateLimiter::for('app-location', fn (Request $request) => Limit::perMinute(30)
            ->by('app-location:'.($request->user()?->currentAccessToken()?->id ?? $request->ip())));
```

- [ ] **Step 4: Form Request** — `app/Http/Requests/StoreLocationPointsRequest.php`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body the geolocation plugin's beacon sends: {"points": [{latitude, longitude, accuracy?,
 * speed?, heading?, altitude?, timestamp}]}. Only the newest valid point is used.
 */
class StoreLocationPointsRequest extends FormRequest
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
            'points' => ['required', 'array', 'min:1', 'max:50'],
            'points.*' => ['array'],
            'points.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'points.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy' => ['nullable', 'numeric', 'min:0'],
            'points.*.speed' => ['nullable', 'numeric'],
            'points.*.heading' => ['nullable', 'numeric'],
            'points.*.altitude' => ['nullable', 'numeric'],
            'points.*.timestamp' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ];
    }
}
```

- [ ] **Step 5: Action** — `app/Actions/Locations/RecordBeaconPoints.php`

```php
<?php

namespace App\Actions\Locations;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Applies a beacon's batch: the newest point moves the location linked to the beacon token's
 * parent app token. Returns null when no location is linked (the phone was unlinked), which the
 * controller answers with a 404. The location must belong to the token's own account.
 */
class RecordBeaconPoints
{
    public function __construct(private readonly RecordMobilePosition $record) {}

    /**
     * @param  list<array{latitude: float|int|string, longitude: float|int|string, timestamp: string}>  $points
     */
    public function __invoke(PersonalAccessToken $beacon, array $points): ?Location
    {
        $location = Location::query()
            ->where('app_token_id', $beacon->parent_token_id)
            ->where('user_id', $beacon->tokenable_id)
            ->where('type', LocationType::Mobile->value)
            ->where('mobile_source', MobileSource::App->value)
            ->first();

        if ($location === null) {
            return null;
        }

        $newest = collect($points)
            ->map(fn (array $point): array => [...$point, 'at' => CarbonImmutable::parse($point['timestamp'])])
            ->sortByDesc('at')
            ->first();

        ($this->record)($location, (float) $newest['latitude'], (float) $newest['longitude'], $newest['at']);

        return $location;
    }
}
```

(`parent_token_id` is read from the model attribute: add `parent_token_id` to nothing; reading an unfillable column is fine. If `$beacon->parent_token_id` is null the `where` matches nothing, so a parentless token reaches no location.)

- [ ] **Step 6: Controller** — `app/Http/Controllers/Api/V1/LocationController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Locations\RecordBeaconPoints;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationPointsRequest;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class LocationController extends Controller
{
    /**
     * The beacon's position upload. The plugin ignores the body and never retries, so a stale
     * point is accepted (204) and simply not applied.
     */
    public function store(StoreLocationPointsRequest $request, RecordBeaconPoints $record): Response
    {
        $beacon = $request->user()->currentAccessToken();

        abort_unless($beacon instanceof PersonalAccessToken, 403);

        $location = $record($beacon, $request->validated('points'));

        abort_if($location === null, 404);

        return response()->noContent();
    }
}
```

- [ ] **Step 7: Route** — in `routes/api.php` import `LocationController` and `ExtendAppTokenExpiry` is already imported; add a second group inside the `v1` prefix, after the app group:

```php
    Route::middleware(['auth:sanctum', 'ability:location', ExtendAppTokenExpiry::class, 'throttle:app-location'])->group(function (): void {
        Route::post('/location', [LocationController::class, 'store'])->name('api.v1.location.store');
    });
```

- [ ] **Step 8: Docblock** — in `ExtendAppTokenExpiry` change the class docblock first sentence to say it keeps an app token or a location beacon token alive; no code change.

- [ ] **Step 9: Run the tests; they must pass.** The route-level 403 cases (`/api/v1/location-sharing`) only matter once Task 4 adds that route: until then a 404 is acceptable for that one path; adjust that path out of the loop and add it in Task 4's tests if needed.

- [ ] **Step 10: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app routes tests
git commit -m "API: accept the location beacon's position uploads" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Location sharing endpoints (`PUT` / `GET` / `DELETE /api/v1/location-sharing`)

**Files:**
- Create: `app/Actions/Locations/EnableLocationSharing.php`
- Create: `app/Actions/Locations/DisableLocationSharing.php`
- Create: `app/Exceptions/LocationLimitReached.php`
- Create: `app/Http/Controllers/Api/V1/LocationSharingController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/ApiLocationSharingTest.php`

**Interfaces:**
- Consumes: Tasks 1 and 3 (`mobileApp()` factory, `parent_token_id`, `POST /location`).
- Produces: `PUT /api/v1/location-sharing` → 200 `{data: {location_id, beacon_token, url, interval_seconds}}` or 422 `{error: "location_limit", message}`; `GET` → `{data: {linked, location_id, interval_seconds}}`; `DELETE` → 204. Route names `api.v1.location-sharing.update|show|destroy`. `EnableLocationSharing::__invoke(User $user, PersonalAccessToken $appToken): array{location: Location, beacon_token: string}` (throws `LocationLimitReached`); `DisableLocationSharing::__invoke(User $user, PersonalAccessToken $appToken): void`.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/ApiLocationSharingTest.php`

```php
<?php

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->app = $this->user->createToken('Pixel 9', ['app']);
});

function sharingCall($test, string $method, ?string $bearer = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($bearer ?? $test->app->plainTextToken)->json($method, '/api/v1/location-sharing');
}

test('turning sharing on creates the phone\'s location and a narrow beacon token', function () {
    $response = sharingCall($this, 'PUT')->assertOk();

    $location = Location::query()->sole();
    $beacon = PersonalAccessToken::query()->where('abilities', json_encode(['location']))->sole();

    expect($location->user_id)->toBe($this->user->id)
        ->and($location->label)->toBe('Pixel 9')
        ->and($location->type)->toBe(LocationType::Mobile)
        ->and($location->mobile_source)->toBe(MobileSource::App)
        ->and($location->mobile_update_interval_minutes)->toBe(5)
        ->and($location->app_token_id)->toBe($this->app->accessToken->id)
        ->and($beacon->parent_token_id)->toBe($this->app->accessToken->id)
        ->and($beacon->tokenable_id)->toBe($this->user->id)
        ->and($beacon->expires_at)->not->toBeNull();

    $response->assertJsonPath('data.location_id', $location->id)
        ->assertJsonPath('data.interval_seconds', 300)
        ->assertJsonPath('data.url', route('api.v1.location.store'));
    expect($response->json('data.beacon_token'))->toStartWith($beacon->id.'|');
});

test('turning it on again reuses the location and replaces the beacon token', function () {
    sharingCall($this, 'PUT')->assertOk();
    $first = PersonalAccessToken::query()->where('abilities', json_encode(['location']))->sole();

    sharingCall($this, 'PUT')->assertOk();

    expect(Location::count())->toBe(1)
        ->and(PersonalAccessToken::query()->where('abilities', json_encode(['location']))->count())->toBe(1)
        ->and(PersonalAccessToken::find($first->id))->toBeNull();
});

test('signing in again on the same phone re-links its unlinked location instead of duplicating it', function () {
    $old = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4]);
    $rule = AlertRule::factory()->for($this->user)->create();
    $rule->locations()->attach($old->id);

    sharingCall($this, 'PUT')->assertOk()->assertJsonPath('data.location_id', $old->id);

    expect(Location::count())->toBe(1)
        ->and($old->fresh()->app_token_id)->toBe($this->app->accessToken->id)
        ->and((float) $old->fresh()->lat)->toBe(35.2);
});

test('an unlinked location with another name is left alone and a new one is created', function () {
    $other = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'iPad']);

    sharingCall($this, 'PUT')->assertOk();

    expect(Location::count())->toBe(2)->and($other->fresh()->app_token_id)->toBeNull();
});

test('another account\'s unlinked location is never re-linked', function () {
    $foreign = Location::factory()->for(User::factory())->mobileApp()->create(['label' => 'Pixel 9']);

    sharingCall($this, 'PUT')->assertOk();

    expect($foreign->fresh()->app_token_id)->toBeNull()->and(Location::count())->toBe(2);
});

test('at the plan\'s location limit a new location is refused but an existing one is still re-linked', function () {
    // One location allowed, one already used.
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();
    Location::factory()->for($this->user)->create();

    sharingCall($this, 'PUT')->assertUnprocessable()->assertJsonPath('error', 'location_limit');
    expect(PersonalAccessToken::query()->where('abilities', json_encode(['location']))->count())->toBe(0);

    $existing = Location::factory()->for($this->user)->mobileApp()->create(['label' => 'Pixel 9']);

    sharingCall($this, 'PUT')->assertOk()->assertJsonPath('data.location_id', $existing->id);
});

test('GET says whether the phone is linked and never returns a token', function () {
    sharingCall($this, 'GET')->assertOk()
        ->assertExactJson(['data' => ['linked' => false, 'location_id' => null, 'interval_seconds' => null]]);

    sharingCall($this, 'PUT')->assertOk();
    $location = Location::query()->sole();
    $location->update(['mobile_update_interval_minutes' => 15]);

    sharingCall($this, 'GET')->assertOk()
        ->assertExactJson(['data' => ['linked' => true, 'location_id' => $location->id, 'interval_seconds' => 900]]);
});

test('turning sharing off revokes the beacon token and unlinks the location, which keeps its rules', function () {
    sharingCall($this, 'PUT')->assertOk();
    $location = Location::query()->sole();
    $rule = AlertRule::factory()->for($this->user)->create();
    $rule->locations()->attach($location->id);

    sharingCall($this, 'DELETE')->assertNoContent();
    sharingCall($this, 'DELETE')->assertNoContent();

    expect(PersonalAccessToken::query()->where('abilities', json_encode(['location']))->count())->toBe(0)
        ->and($location->fresh()->app_token_id)->toBeNull()
        ->and(DB::table('alert_rule_location')->where('location_id', $location->id)->count())->toBe(1);
});

test('signing out removes the beacon token and unlinks the location', function () {
    sharingCall($this, 'PUT')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($this->app->plainTextToken)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect(PersonalAccessToken::query()->where('abilities', json_encode(['location']))->count())->toBe(0)
        ->and(Location::query()->sole()->app_token_id)->toBeNull();
});

test('a beacon token cannot manage sharing and an api token is refused', function () {
    $response = sharingCall($this, 'PUT')->assertOk();
    $beacon = $response->json('data.beacon_token');
    $script = $this->user->createToken('My script', ['api'])->plainTextToken;

    foreach (['GET', 'PUT', 'DELETE'] as $method) {
        sharingCall($this, $method, $beacon)->assertForbidden();
        sharingCall($this, $method, $script)->assertForbidden();
    }
    app('auth')->forgetGuards();
    $this->json('GET', '/api/v1/location-sharing')->assertUnauthorized();
});

test('the beacon token is never written to the activity log and the creation is attributed to the subscriber', function () {
    $token = sharingCall($this, 'PUT')->assertOk()->json('data.beacon_token');

    expect(Activity::query()->get()->toJson())->not->toContain($token);
    $created = Activity::query()->where('subject_type', Location::class)->where('event', 'created')->first();
    expect($created)->not->toBeNull()->and($created->causer_id)->toBe($this->user->id);
});

test('beacon tokens do not appear on the Signed-in devices page', function () {
    sharingCall($this, 'PUT')->assertOk();

    $listed = $this->user->tokens()->getQuery()->where('abilities', json_encode(['app']))->pluck('name')->all();

    expect($listed)->toBe(['Pixel 9']);
});
```

(The user from `User::factory()` is on the Free tier by default; if `canCreateLocation()` does not refuse with that, create the user with `['tier_override' => Tier::Free]`.)

- [ ] **Step 2: Run to verify failure** — Expected: FAIL (404 on the routes).

- [ ] **Step 3: Exception** — `app/Exceptions/LocationLimitReached.php`

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class LocationLimitReached extends RuntimeException
{
}
```

- [ ] **Step 4: Enable action** — `app/Actions/Locations/EnableLocationSharing.php`

```php
<?php

namespace App\Actions\Locations;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Exceptions\LocationLimitReached;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Turns location sharing on for one phone: finds or creates its mobile Location and mints the
 * narrow beacon token the native beacon uses. Order: the Location already linked to this app
 * token; else this user's unlinked app Location with the same device name (so signing in again
 * on the same phone does not duplicate it); else a new one, which must fit the tier's location
 * limit. Any earlier beacon token of this phone is revoked, so a phone has exactly one. The
 * request has no session user, so writes pin the subscriber as the activity causer.
 */
class EnableLocationSharing
{
    /**
     * @return array{location: Location, beacon_token: string}
     *
     * @throws LocationLimitReached
     */
    public function __invoke(User $user, PersonalAccessToken $appToken): array
    {
        return app(CauserResolver::class)->withCauser($user, fn (): array => DB::transaction(function () use ($user, $appToken): array {
            PersonalAccessToken::query()->where('parent_token_id', $appToken->id)->delete();

            $location = $this->linked($user, $appToken) ?? $this->relinked($user, $appToken) ?? $this->created($user, $appToken);

            $beacon = $user->createToken(
                'Location beacon - '.$appToken->name,
                ['location'],
                now()->addDays((int) config('wxnotify.app_auth.token_days')),
            );
            $beacon->accessToken->forceFill(['parent_token_id' => $appToken->id])->save();

            return ['location' => $location, 'beacon_token' => $beacon->plainTextToken];
        }));
    }

    private function linked(User $user, PersonalAccessToken $appToken): ?Location
    {
        return Location::query()->where('user_id', $user->id)->where('app_token_id', $appToken->id)->first();
    }

    private function relinked(User $user, PersonalAccessToken $appToken): ?Location
    {
        $location = Location::query()
            ->where('user_id', $user->id)
            ->where('type', LocationType::Mobile->value)
            ->where('mobile_source', MobileSource::App->value)
            ->whereNull('app_token_id')
            ->where('label', Str::limit($appToken->name, 80, ''))
            ->orderBy('id')
            ->first();

        $location?->forceFill(['app_token_id' => $appToken->id])->save();

        return $location;
    }

    private function created(User $user, PersonalAccessToken $appToken): Location
    {
        if (! $user->canCreateLocation()) {
            throw new LocationLimitReached;
        }

        $location = new Location([
            'user_id' => $user->id,
            'label' => Str::limit($appToken->name, 80, ''),
            'type' => LocationType::Mobile,
            'mobile_source' => MobileSource::App,
            'mobile_update_interval_minutes' => 5,
        ]);
        $location->forceFill(['app_token_id' => $appToken->id])->save();

        return $location;
    }
}
```

(If `Location`'s `#[Fillable]` does not include a key used above, use `forceFill` for it; `user_id`, `label`, `type`, `mobile_source` and `mobile_update_interval_minutes` are in the existing list. If `withCauser` returns the callback's value in this Spatie version, the arrow function is fine; if it does not, assign through a captured variable as `RegisterMobilePushDevice` does.)

- [ ] **Step 5: Disable action** — `app/Actions/Locations/DisableLocationSharing.php`

```php
<?php

namespace App\Actions\Locations;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Turns sharing off for one phone: revokes its beacon token and unlinks its Location, which is
 * kept with its last position and its rule links. Nothing to do (and no error) when sharing
 * was never on.
 */
class DisableLocationSharing
{
    public function __invoke(User $user, PersonalAccessToken $appToken): void
    {
        app(CauserResolver::class)->withCauser($user, fn () => DB::transaction(function () use ($user, $appToken): void {
            PersonalAccessToken::query()->where('parent_token_id', $appToken->id)->delete();

            Location::query()
                ->where('user_id', $user->id)
                ->where('app_token_id', $appToken->id)
                ->get()
                ->each(fn (Location $location) => $location->forceFill(['app_token_id' => null])->save());
        }));
    }
}
```

- [ ] **Step 6: Controller** — `app/Http/Controllers/Api/V1/LocationSharingController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Locations\DisableLocationSharing;
use App\Actions\Locations\EnableLocationSharing;
use App\Exceptions\LocationLimitReached;
use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class LocationSharingController extends Controller
{
    /**
     * Turn sharing on for this phone. The only response that ever carries a beacon token.
     */
    public function update(Request $request, EnableLocationSharing $enable): JsonResponse
    {
        try {
            $result = $enable($request->user(), $this->appToken($request));
        } catch (LocationLimitReached) {
            return response()->json([
                'error' => 'location_limit',
                'message' => 'Your plan\'s location limit is reached. Remove a location on the dashboard, or upgrade, to share this phone\'s location.',
            ], 422);
        }

        return response()->json(['data' => [
            'location_id' => $result['location']->id,
            'beacon_token' => $result['beacon_token'],
            'url' => route('api.v1.location.store'),
            'interval_seconds' => $result['location']->mobile_update_interval_minutes * 60,
        ]]);
    }

    public function show(Request $request): JsonResponse
    {
        $location = Location::query()
            ->where('user_id', $request->user()->id)
            ->where('app_token_id', $this->appToken($request)->id)
            ->first();

        return response()->json(['data' => [
            'linked' => $location !== null,
            'location_id' => $location?->id,
            'interval_seconds' => $location === null ? null : $location->mobile_update_interval_minutes * 60,
        ]]);
    }

    public function destroy(Request $request, DisableLocationSharing $disable): Response
    {
        $disable($request->user(), $this->appToken($request));

        return response()->noContent();
    }

    private function appToken(Request $request): PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken, 403);

        return $token;
    }
}
```

- [ ] **Step 7: Routes** — in `routes/api.php` import `LocationSharingController` and add inside the existing `ability:app` group:

```php
        Route::put('/location-sharing', [LocationSharingController::class, 'update'])->name('api.v1.location-sharing.update');
        Route::get('/location-sharing', [LocationSharingController::class, 'show'])->name('api.v1.location-sharing.show');
        Route::delete('/location-sharing', [LocationSharingController::class, 'destroy'])->name('api.v1.location-sharing.destroy');
```

- [ ] **Step 8: Run these tests and `tests/Feature/ApiLocationBeaconTest.php`, `ApiPushTokenTest.php`, `ApiMeTest.php`, `SignedInDevicesPageTest.php`.** Expected: PASS. Restore the `/api/v1/location-sharing` 403 case in the beacon test's abilities loop if you removed it in Task 3.

- [ ] **Step 9: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app routes tests
git commit -m "API: turn a phone's location sharing on and off" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Dashboard — form, table and Needs-attention for app locations

**Files:**
- Modify: `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php`
- Modify: `app/Filament/Dashboard/Resources/Locations/Tables/LocationsTable.php`
- Modify: `app/Actions/Alerts/GetAlertsNeedingAttention.php`
- Test: extend the existing Locations resource test and `tests/Feature/GetAlertsNeedingAttentionTest.php` (find the Locations resource test with `ls tests/Feature | grep -i location`)

**Interfaces:**
- Consumes: `Location::hasMissingDevice()` (Task 1), `MobileSource::App`.
- Produces: the form shows "WxNotify app" as a read-only source with the phone's name (or "not linked to a phone"); the Traccar fields show only for a non-app mobile Location; the table's "Position updated" column and the Needs-attention rows treat an unlinked app Location as missing its device.

- [ ] **Step 1: Write the failing tests.** Add to the Locations resource test (Livewire/Filament test style used there):
  - editing an app Location (label and interval changed) saves with no Traccar server or device selected, keeps `mobile_source` app and its `app_token_id`, and does not require the Traccar fields;
  - the Traccar server and device fields are absent for an app Location and present for a Traccar one;
  - the source placeholder says "WxNotify app" with the linked token's name, and "not linked to a phone" when `app_token_id` is null;
  - an unlinked app Location's row in the table shows "Device not found on server"-style text ("No phone is sharing") while a linked one shows its position time.
  Add to `GetAlertsNeedingAttentionTest.php`: an active scheduled alert (use the same category helper the existing `location_device_missing` test uses) on an unlinked app Location yields one `location_device_missing` row whose detail says no phone is sharing its location any more, and no duplicate `location_no_position` row; a linked app Location with no position yields `location_no_position`; Traccar behaviour unchanged.

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Form** — in `LocationForm::mobileFields()` (and its callers) introduce two closures next to `$visible` and use them:

```php
        $isApp = fn (Get $get): bool => $get('mobile_source') === MobileSource::App->value;
        $traccarVisible = fn (Get $get): bool => $visible($get) && ! $isApp($get);
        $appVisible = fn (Get $get): bool => $visible($get) && $isApp($get);
```

Replace `->visible($visible)` and `->required($visible)` with `$traccarVisible` on exactly these fields: `traccar_server_id`, the missing-device `Callout` (keep its extra conditions), `traccar_device_id`, the `traccar_device_name` hidden field. Keep `mobile_source` (Hidden), the interval `Select` and the quiet-hours note on `$visible`. Add, after the `traccar_device_name` field:

```php
            Placeholder::make('mobile_app_source')
                ->label('Source')
                ->content(fn (?Location $record): string => $record?->appToken !== null
                    ? 'WxNotify app — '.$record->appToken->name
                    : 'WxNotify app — not linked to a phone. Turn on location sharing in the app to link a phone again.')
                ->visible($appVisible),
```

Make the interval helper text source-aware: for an app location use "The app re-reads this each time it opens; tighter intervals sharpen how current this location is at the cost of the phone's battery." (a second `helperText` closure on `$isApp`). The `type` radio and everything else stay as is. Import `App\Enums\MobileSource` if not already imported.

- [ ] **Step 4: Table** — in `LocationsTable` make the "Position updated" state text and tooltip source-aware:

```php
                    ->state(fn (Location $record): CarbonInterface|string|null => $record->hasMissingDevice()
                        ? ($record->mobile_source === MobileSource::App ? 'No phone is sharing' : 'Device not found on server')
                        : $record->positionLocatedAt())
                    ...
                    ->tooltip(fn (Location $record): ?string => match (true) {
                        ! $record->hasMissingDevice() => null,
                        $record->mobile_source === MobileSource::App => 'No phone is sharing this location any more (it signed out or stopped sharing). Turn on location sharing in the app to link a phone again.',
                        default => 'This device no longer exists on the Traccar server. Edit this location and pick its device again.',
                    })
```

(import `App\Enums\MobileSource`).

- [ ] **Step 5: Needs attention** — in `GetAlertsNeedingAttention`:
  - `mobileLocationsWithMissingDevice()`: change the `whereHas('location', ...)` closure to

```php
            ->whereHas('location', fn ($query) => $query->where('type', LocationType::Mobile->value)->where(fn ($q) => $q
                ->whereNotNull('traccar_device_missing_at')
                ->orWhere(fn ($app) => $app->where('mobile_source', MobileSource::App->value)->whereNull('app_token_id'))))
```

and make `detail` source-aware: for `$alert->location->mobile_source === MobileSource::App` use `"can't follow ".$alert->location->label.': no phone is sharing its location any more'`, otherwise the existing Traccar sentence. Update the method docblock.
  - `mobileLocationsWithoutPosition()`: keep `whereNull('traccar_device_missing_at')` and add `->where(fn ($q) => $q->whereNull('mobile_source')->orWhere('mobile_source', '!=', MobileSource::App->value)->orWhereNotNull('app_token_id'))` so an unlinked app Location is reported once, as missing its device.
  - Import `App\Enums\MobileSource`.

- [ ] **Step 6: Run the tests** — the new ones, plus the full Locations resource tests, `GetAlertsNeedingAttentionTest.php`, `AlertWidgetsTest.php` (the widget's query-count test must still pass). Expected: PASS.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app tests
git commit -m "Dashboard: show app-sourced locations and flag the ones that lost their phone" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: App — API client, sharing service, state and sign-out

**Files:**
- Create: `mobile/app/Services/LocationSharing.php`
- Create: `mobile/app/Support/LocationSharingState.php`
- Create: `mobile/app/Exceptions/Api/LocationLimitReached.php`
- Modify: `mobile/app/Services/WxNotifyApi.php` (three methods; map the limit 422)
- Modify: `mobile/app/Support/TokenStore.php` (beacon token get/put/forget)
- Modify: `mobile/app/Services/SignOut.php` (stop the watch in `clearLocal()`)
- Create: `mobile/tests/Fakes/RecordingGeolocation.php`
- Modify: `mobile/tests/Pest.php` (bind the fake)
- Modify: `mobile/tests/Feature/NativePluginsTest.php` (guard the geolocation plugin)
- Test: `mobile/tests/Feature/LocationSharingTest.php`

**Interfaces:**
- Consumes: the Task 3/4 endpoints; facade `Native\Mobile\Facades\Geolocation` resolves `Native\Mobile\Geolocation` (the plugin binds its subclass `NativePHP\Geolocation\Geolocation`, which has `sharePosition(url, token, interval, expiresAt, stopWatchOnExpiry, fineAccuracy): PendingSharedLocationWatch`, plus the core `backgroundWatchStatus(): ?array` and `stopBackgroundWatch(string $id, bool $clearBuffer = false): void`).
- Produces: `WxNotifyApi::enableLocationSharing(): array{location_id: int, beacon_token: string, url: string, interval_seconds: int}`, `locationSharing(): array{linked: bool, location_id: ?int, interval_seconds: ?int}`, `disableLocationSharing(): void`, and a `LocationLimitReached` API exception (extends `ApiException`) for the 422 `location_limit` answer; `LocationSharing` (`WATCH_ID = 'wxnotify-location'`): `state(): array{status: 'off'|'on'|'waiting', interval_minutes: ?int, last_shared_at: ?string, while_using_only: bool}`, `enable(): string` (`'started'|'limit'|'offline'|'failed'`), `disable(): bool`, `refreshOnLaunch(): void`, `stopLocal(): void`; `LocationSharingState` (`wanted()`, `markWanted()`, `markOff()`, `clear()`); `TokenStore::beaconToken()/putBeaconToken()/forgetBeaconToken()`.

- [ ] **Step 1: The test fake** — `mobile/tests/Fakes/RecordingGeolocation.php`

```php
<?php

namespace Tests\Fakes;

use DateTimeInterface;
use NativePHP\Geolocation\Geolocation;
use NativePHP\Geolocation\PendingSharedLocationWatch;

/**
 * Records what the app asks the native location layer to do. The builders it returns are real
 * but inert off a device (no nativephp_call), so nothing starts.
 */
class RecordingGeolocation extends Geolocation
{
    /** @var list<array<string, mixed>> */
    public array $shared = [];

    /** @var list<array{id: string, clearBuffer: bool}> */
    public array $stopped = [];

    /** @var array<string, mixed>|null */
    public ?array $status = null;

    public function sharePosition(
        string $url,
        ?string $token = null,
        int $interval = 60,
        DateTimeInterface|string|null $expiresAt = null,
        bool $stopWatchOnExpiry = false,
        bool $fineAccuracy = true,
    ): PendingSharedLocationWatch {
        $this->shared[] = compact('url', 'token', 'interval', 'expiresAt', 'fineAccuracy');

        return parent::sharePosition($url, $token, $interval, $expiresAt, $stopWatchOnExpiry, $fineAccuracy);
    }

    public function stopBackgroundWatch(string $id, bool $clearBuffer = false): void
    {
        $this->stopped[] = ['id' => $id, 'clearBuffer' => $clearBuffer];
        $this->status = null;
    }

    public function backgroundWatchStatus(): ?array
    {
        return $this->status;
    }
}
```

In `mobile/tests/Pest.php` add `use Native\Mobile\Geolocation;` and `use Tests\Fakes\RecordingGeolocation;` and, in the `beforeEach`, `$this->geolocation = new RecordingGeolocation; app()->instance(Geolocation::class, $this->geolocation);`.

- [ ] **Step 2: Write the failing tests** — `mobile/tests/Feature/LocationSharingTest.php`

```php
<?php

use App\Exceptions\Api\LocationLimitReached;
use App\Services\LocationSharing;
use App\Services\SignOut;
use App\Support\LocationSharingState;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

function enabledResponse(array $overrides = []): array
{
    return ['data' => $overrides + ['location_id' => 7, 'beacon_token' => '12|beacon', 'url' => 'https://wx.test/api/v1/location', 'interval_seconds' => 300]];
}

test('turning it on asks the server, keeps the beacon token and starts the native beacon', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(enabledResponse())]);

    $outcome = app(LocationSharing::class)->enable();

    expect($outcome)->toBe('started')
        ->and((new LocationSharingState)->wanted())->toBeTrue()
        ->and((new TokenStore)->beaconToken())->toBe('12|beacon')
        ->and($this->geolocation->shared)->toHaveCount(1)
        ->and($this->geolocation->shared[0])->toMatchArray([
            'url' => 'https://wx.test/api/v1/location',
            'token' => '12|beacon',
            'interval' => 300,
            'expiresAt' => null,
            'fineAccuracy' => false,
        ]);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/location-sharing');
});

test('a plan at its location limit is reported and nothing starts', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['error' => 'location_limit', 'message' => 'x'], 422)]);

    expect(app(LocationSharing::class)->enable())->toBe('limit')
        ->and((new LocationSharingState)->wanted())->toBeFalse()
        ->and($this->geolocation->shared)->toBeEmpty();
});

test('offline or a server error starts nothing and keeps sharing off', function (int $status, string $outcome) {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response([], $status)]);

    expect(app(LocationSharing::class)->enable())->toBe($outcome)
        ->and((new LocationSharingState)->wanted())->toBeFalse();
})->with([[503, 'failed'], [500, 'failed']]);

test('the state is derived from the native watch and the wanted flag', function () {
    $sharing = app(LocationSharing::class);

    expect($sharing->state()['status'])->toBe('off');

    (new LocationSharingState)->markWanted();
    expect($sharing->state()['status'])->toBe('waiting');

    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000, 'lastShareAt' => '2026-10-05T14:03:22Z', 'lastShareStatus' => 204];
    expect($sharing->state())->toMatchArray(['status' => 'on', 'interval_minutes' => 5, 'last_shared_at' => '2026-10-05T14:03:22Z', 'while_using_only' => false]);

    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000, 'authorization' => 'whenInUse'];
    expect($sharing->state()['while_using_only'])->toBeTrue();
});

test('turning it off tells the server, stops the native watch and forgets the token', function () {
    Http::fake([
        'wx.test/api/v1/location-sharing' => Http::sequence()->push(enabledResponse())->push(null, 204),
    ]);
    $sharing = app(LocationSharing::class);
    $sharing->enable();

    expect($sharing->disable())->toBeTrue()
        ->and((new LocationSharingState)->wanted())->toBeFalse()
        ->and((new TokenStore)->beaconToken())->toBeNull()
        ->and($this->geolocation->stopped)->toBe([['id' => 'wxnotify-location', 'clearBuffer' => true]]);
});

test('it stays on when the server could not be told it is off', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::sequence()->push(enabledResponse())->push([], 503)]);
    $sharing = app(LocationSharing::class);
    $sharing->enable();

    expect($sharing->disable())->toBeFalse()
        ->and((new LocationSharingState)->wanted())->toBeTrue()
        ->and($this->geolocation->stopped)->toBeEmpty();
});

test('on launch an inactive native watch is re-armed with a fresh token', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::sequence()
        ->push(['data' => ['linked' => true, 'location_id' => 7, 'interval_seconds' => 300]])
        ->push(enabledResponse(['beacon_token' => '13|fresh']))]);
    (new LocationSharingState)->markWanted();

    app(LocationSharing::class)->refreshOnLaunch();

    expect($this->geolocation->shared)->toHaveCount(1)
        ->and($this->geolocation->shared[0]['token'])->toBe('13|fresh');
});

test('on launch a changed interval restarts the beacon with the stored token', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['data' => ['linked' => true, 'location_id' => 7, 'interval_seconds' => 900]])]);
    (new LocationSharingState)->markWanted();
    (new TokenStore)->putBeaconToken('12|beacon');
    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000];

    app(LocationSharing::class)->refreshOnLaunch();

    expect($this->geolocation->shared)->toHaveCount(1)
        ->and($this->geolocation->shared[0])->toMatchArray(['token' => '12|beacon', 'interval' => 900]);
});

test('on launch a healthy beacon is left alone', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['data' => ['linked' => true, 'location_id' => 7, 'interval_seconds' => 300]])]);
    (new LocationSharingState)->markWanted();
    (new TokenStore)->putBeaconToken('12|beacon');
    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000];

    app(LocationSharing::class)->refreshOnLaunch();

    expect($this->geolocation->shared)->toBeEmpty()->and($this->geolocation->stopped)->toBeEmpty();
});

test('on launch an unlinked location stops the watch and turns sharing off', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['data' => ['linked' => false, 'location_id' => null, 'interval_seconds' => null]])]);
    (new LocationSharingState)->markWanted();
    (new TokenStore)->putBeaconToken('12|beacon');

    app(LocationSharing::class)->refreshOnLaunch();

    expect((new LocationSharingState)->wanted())->toBeFalse()
        ->and((new TokenStore)->beaconToken())->toBeNull()
        ->and($this->geolocation->stopped)->toHaveCount(1);
});

test('on launch nothing happens while sharing is off or when the server cannot be reached', function () {
    Http::fake();
    app(LocationSharing::class)->refreshOnLaunch();
    Http::assertNothingSent();

    Http::fake(['wx.test/api/v1/location-sharing' => Http::response([], 503)]);
    (new LocationSharingState)->markWanted();
    app(LocationSharing::class)->refreshOnLaunch();

    expect((new LocationSharingState)->wanted())->toBeTrue()->and($this->geolocation->shared)->toBeEmpty();
});

test('signing out stops the native watch, forgets the token and the flag even offline', function () {
    (new LocationSharingState)->markWanted();
    (new TokenStore)->putBeaconToken('12|beacon');
    $this->network->connected = false;
    Http::fake();

    app(SignOut::class)();

    expect((new LocationSharingState)->wanted())->toBeFalse()
        ->and((new TokenStore)->beaconToken())->toBeNull()
        ->and($this->geolocation->stopped)->toBe([['id' => 'wxnotify-location', 'clearBuffer' => true]]);
});
```

Add to `mobile/tests/Feature/WxNotifyApiTest.php` two small tests: `enableLocationSharing()` returns the `data` array on 200 and throws `LocationLimitReached` on a 422 body `{"error":"location_limit"}`, while another 422 still throws `Rejected`; `disableLocationSharing()` accepts a 204. Add to `NativePluginsTest.php` the row `'Geolocation::sharePosition() and backgroundWatchStatus() (the location beacon)' => GeolocationServiceProvider::class` (import `Native\Mobile\Providers\GeolocationServiceProvider`).

- [ ] **Step 3: Run to verify failure** — `cd mobile && vendor/bin/pest --compact tests/Feature/LocationSharingTest.php` — Expected: FAIL.

- [ ] **Step 4: `TokenStore` additions**

```php
    private const BEACON = 'wxnotify_location_beacon_token';

    public function beaconToken(): ?string
    {
        return SecureStorage::get(self::BEACON) ?: null;
    }

    public function putBeaconToken(string $token): void
    {
        $this->write(self::BEACON, $token);
    }

    public function forgetBeaconToken(): void
    {
        SecureStorage::delete(self::BEACON);
    }
```

- [ ] **Step 5: API client** — in `WxNotifyApi` add `use App\Exceptions\Api\LocationLimitReached;` and:

```php
    /**
     * @return array{location_id: int, beacon_token: string, url: string, interval_seconds: int}
     */
    public function enableLocationSharing(): array
    {
        $data = $this->data($this->send(fn (PendingRequest $request) => $request->put('/api/v1/location-sharing')));

        if (! is_string($data['beacon_token'] ?? null) || ! is_string($data['url'] ?? null) || ! is_int($data['interval_seconds'] ?? null)) {
            throw new Retryable;
        }

        return $data;
    }

    /**
     * @return array{linked: bool, location_id: ?int, interval_seconds: ?int}
     */
    public function locationSharing(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/location-sharing')));
    }

    public function disableLocationSharing(): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete('/api/v1/location-sharing'));
    }
```

and in `send()`'s `match`, before `default`, add: `$response->status() === 422 && $response->json('error') === 'location_limit' => throw new LocationLimitReached,`. Create `mobile/app/Exceptions/Api/LocationLimitReached.php` as `final class LocationLimitReached extends ApiException {}`.

- [ ] **Step 6: State** — `mobile/app/Support/LocationSharingState.php`

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Whether the subscriber wants this phone to share its location. The native watch is the source
 * of truth for whether it is running; this flag is what the app is supposed to be doing. Signing
 * out forgets it.
 */
class LocationSharingState
{
    private const WANTED = 'wxnotify.location.wanted';

    public function wanted(): bool
    {
        return (bool) Cache::get(self::WANTED, false);
    }

    public function markWanted(): void
    {
        Cache::forever(self::WANTED, true);
    }

    public function markOff(): void
    {
        Cache::forget(self::WANTED);
    }

    public function clear(): void
    {
        $this->markOff();
    }
}
```

- [ ] **Step 7: Service** — `mobile/app/Services/LocationSharing.php`

```php
<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\LocationLimitReached;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Support\LocationSharingState;
use App\Support\TokenStore;
use Native\Mobile\Facades\Geolocation;

/**
 * Everything the app does about sharing this phone's position: asking the server for the phone's
 * location and a narrow beacon token, running the plugin's native beacon with them (it keeps
 * posting while the app is closed), and re-arming or stopping it. The native watch is the source
 * of truth for "is it running"; the wanted flag is what the subscriber asked for.
 */
class LocationSharing
{
    public const WATCH_ID = 'wxnotify-location';

    /** Do not report a move smaller than this (metres): it spares the battery. */
    private const MIN_DISTANCE_METERS = 250;

    public function __construct(
        private WxNotifyApi $api,
        private TokenStore $tokens,
        private LocationSharingState $state,
    ) {}

    /**
     * @return array{status: 'off'|'on'|'waiting', interval_minutes: ?int, last_shared_at: ?string, while_using_only: bool}
     */
    public function state(): array
    {
        $watch = Geolocation::backgroundWatchStatus();
        $sharing = is_array($watch) && ($watch['shareActive'] ?? false);

        return [
            'status' => $sharing ? 'on' : ($this->state->wanted() ? 'waiting' : 'off'),
            'interval_minutes' => $sharing && isset($watch['shareIntervalMs']) ? (int) round($watch['shareIntervalMs'] / 60000) : null,
            'last_shared_at' => $sharing ? ($watch['lastShareAt'] ?? null) : null,
            'while_using_only' => $sharing && ($watch['authorization'] ?? null) === 'whenInUse',
        ];
    }

    /**
     * @return 'started'|'limit'|'offline'|'failed'
     */
    public function enable(): string
    {
        try {
            $result = $this->api->enableLocationSharing();
        } catch (LocationLimitReached) {
            return 'limit';
        } catch (Offline) {
            return 'offline';
        } catch (ApiException) {
            return 'failed';
        }

        $this->tokens->putBeaconToken($result['beacon_token']);
        $this->state->markWanted();
        $this->startBeacon($result['url'], $result['beacon_token'], $result['interval_seconds']);

        return 'started';
    }

    /**
     * @return bool false when the server could not be told, in which case sharing stays on
     */
    public function disable(): bool
    {
        try {
            $this->api->disableLocationSharing();
        } catch (Unauthenticated) {
            // The phone is signed out on the server anyway: stop locally.
        } catch (ApiException) {
            return false;
        }

        $this->stopLocal();

        return true;
    }

    /**
     * On launch while sharing is wanted: learn from the server whether the location is still linked
     * and what interval it wants, and bring the native beacon in line. Never throws at the screens.
     */
    public function refreshOnLaunch(): void
    {
        if (! $this->state->wanted()) {
            return;
        }

        try {
            $remote = $this->api->locationSharing();

            if (! ($remote['linked'] ?? false)) {
                $this->stopLocal();

                return;
            }

            $watch = Geolocation::backgroundWatchStatus();
            $token = $this->tokens->beaconToken();
            $active = is_array($watch) && ($watch['shareActive'] ?? false);
            $wantedMs = (int) $remote['interval_seconds'] * 1000;

            if (! $active || $token === null) {
                $fresh = $this->api->enableLocationSharing();
                $this->tokens->putBeaconToken($fresh['beacon_token']);
                $this->startBeacon($fresh['url'], $fresh['beacon_token'], $fresh['interval_seconds']);

                return;
            }

            if ((int) ($watch['shareIntervalMs'] ?? 0) !== $wantedMs) {
                $this->startBeacon(rtrim((string) config('wxnotify.api_url'), '/').'/api/v1/location', $token, (int) $remote['interval_seconds']);
            }
        } catch (Unauthenticated) {
            // The screens handle a revoked sign-in on their own API calls.
        } catch (ApiException) {
            // Offline or a server hiccup: the next launch tries again.
        }
    }

    /**
     * Stop sharing on this phone without asking the server (signing out, or a server that says the
     * location is no longer linked): stop the native watch and forget the token and the flag.
     */
    public function stopLocal(): void
    {
        Geolocation::stopBackgroundWatch(self::WATCH_ID, clearBuffer: true);
        $this->tokens->forgetBeaconToken();
        $this->state->markOff();
    }

    private function startBeacon(string $url, string $token, int $intervalSeconds): void
    {
        // A watch with this id may already be running with the old settings.
        Geolocation::stopBackgroundWatch(self::WATCH_ID);

        Geolocation::sharePosition(url: $url, token: $token, interval: $intervalSeconds, fineAccuracy: false)
            ->id(self::WATCH_ID)
            ->minDistance(self::MIN_DISTANCE_METERS)
            ->start();
    }
}
```

The restart URL in `refreshOnLaunch()` rebuilds the beacon endpoint from `config('wxnotify.api_url')`; if the server's `PUT` response is the better source of truth, store the beacon `url` next to the token in `TokenStore` and use it instead (simpler: a second key `wxnotify_location_beacon_url`). Choose one, keep the tests passing, and say which in the report. In the "changed interval" test the stopped-watch assertion changes accordingly (`startBeacon` stops the old watch first); update the test's `expect` so it matches what the code does, keeping the assertion that exactly one new beacon starts with the stored token and the new interval.

- [ ] **Step 8: Sign-out** — in `SignOut`, inject `LocationSharing $location` and call `$this->location->stopLocal();` at the top of `clearLocal()` (before the other clears); keep the constructor order `TokenStore, WxNotifyApi, LocalCache, PushState, LocationSharing`. `LocationSharingState` is cleared by `stopLocal()`.

- [ ] **Step 9: Run the whole mobile suite and the validator**

Run: `cd mobile && vendor/bin/pest --compact && php artisan native:validate`
Expected: PASS and clean. Existing tests that construct `SignOut` through the container keep working; any test that asserts the exact set of `Geolocation` calls on sign-out is new, none existing.

- [ ] **Step 10: Pint and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile
git commit -m "App: share this phone's location through the native beacon" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: App — the explainer screen, the Account row and re-arming on launch

**Files:**
- Create: `mobile/app/NativeComponents/LocationPrompt.php`
- Create: `mobile/resources/views/native/location-prompt.blade.php`
- Modify: `mobile/routes/mobile.php` (`Route::native('/share-location', LocationPrompt::class);`)
- Modify: `mobile/app/NativeComponents/AccountScreen.php` and `mobile/resources/views/native/account-screen.blade.php`
- Modify: `mobile/app/NativeComponents/AlertsScreen.php` (call `refreshOnLaunch()` in `mount()`)
- Modify: `mobile/tests/Feature/ScreensRenderTest.php`
- Test: `mobile/tests/Feature/LocationPromptTest.php`; extend `AccountScreenTest.php` and `AlertsScreenTest.php`

**Interfaces:**
- Consumes: `LocationSharing` (Task 6); `System::appSettings()`.
- Produces: route `/share-location`; Account shows "Share this phone's location" with the states and buttons below.

- [ ] **Step 1: Write the failing tests** — `mobile/tests/Feature/LocationPromptTest.php`

```php
<?php

use App\NativeComponents\LocationPrompt;
use App\Support\LocationSharingState;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the explainer says what is shared and offers Share and Not now', function () {
    Native::test(LocationPrompt::class)
        ->assertSee('Share this phone\'s location')
        ->assertSee('Share my location')
        ->assertSee('Not now');
});

test('Share starts the beacon and returns to Account', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['data' => ['location_id' => 7, 'beacon_token' => '12|b', 'url' => 'https://wx.test/api/v1/location', 'interval_seconds' => 300]])]);

    Native::test(LocationPrompt::class)->tap('Share my location')->assertReplacedWith('/account');

    expect((new LocationSharingState)->wanted())->toBeTrue()->and($this->geolocation->shared)->toHaveCount(1);
});

test('a plan at its limit is explained and nothing starts', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['error' => 'location_limit', 'message' => 'x'], 422)]);

    Native::test(LocationPrompt::class)
        ->tap('Share my location')
        ->assertSee('location limit')
        ->assertNoNavigation();

    expect((new LocationSharingState)->wanted())->toBeFalse();
});

test('a failed or offline start says so and stays on the screen', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response([], 503)]);

    Native::test(LocationPrompt::class)->tap('Share my location')->assertSee('Couldn\'t start sharing')->assertNoNavigation();
});

test('Not now returns to Account without asking the server', function () {
    Http::fake();

    Native::test(LocationPrompt::class)->tap('Not now')->assertReplacedWith('/account');

    Http::assertNothingSent();
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();

    Native::test(LocationPrompt::class)->assertReplacedWith('/sign-in');
});
```

(Use the navigation assertions the existing tests use: `assertReplacedWith`, `assertNavigatedTo`, `assertNoNavigation`; if Account's "Turn on" should push rather than replace, assert `assertNavigatedTo('/share-location')` there.)

Add to `AccountScreenTest.php` (stubbing `PushNotifications::checkPermission` as the existing push tests do): Off shows "Share this phone's location", "Off" and a "Share location" button that navigates to `/share-location`; with a recording status (`$this->geolocation->status = [...shareActive true, shareIntervalMs 300000, lastShareAt …]`) it shows "On", "about every 5 minutes", the last-shared time and a "Stop sharing" button; wanted but no active watch shows "Waiting for location permission" with an "Open settings" button that calls `System::appSettings()`; `authorization: whenInUse` shows the "Stops when you close the app" warning; "Stop sharing" calls `DELETE /location-sharing` (faked 204), stops the watch and flips the row to Off; "Stop sharing" with a 503 says "Couldn't stop sharing" and stays On; `onResume()` re-renders from the current native status. Add to `AlertsScreenTest.php`: with sharing wanted, opening the feed calls `GET /location-sharing` once (and sends nothing when sharing is off).

- [ ] **Step 2: Run to verify failure** — Expected: FAIL.

- [ ] **Step 3: The screen** — `mobile/app/NativeComponents/LocationPrompt.php`

```php
<?php

namespace App\NativeComponents;

use App\Services\LocationSharing;
use App\Support\Concerns\RequiresSignIn;
use Native\Mobile\Edge\NativeComponent;

/**
 * The explainer before the OS asks for location: what is sent and why, so the system dialog is
 * not the subscriber's first sight of it. Reached from the Account row.
 */
class LocationPrompt extends NativeComponent
{
    use RequiresSignIn;

    public ?string $error = null;

    public function mount(): void
    {
        $this->redirectIfSignedOut();
    }

    public function share(): void
    {
        $this->error = null;

        $outcome = app(LocationSharing::class)->enable();

        if ($outcome === 'started') {
            $this->replace('/account');

            return;
        }

        $this->error = match ($outcome) {
            'limit' => 'Your plan\'s location limit is reached. Remove a location on the dashboard, or upgrade, to share this phone\'s location.',
            'offline' => 'Couldn\'t start sharing: you appear to be offline. Try again when you are back online.',
            default => 'Couldn\'t start sharing right now. Please try again.',
        };
    }

    public function notNow(): void
    {
        $this->replace('/account');
    }
}
```

`mobile/resources/views/native/location-prompt.blade.php` (same vocabulary as `push-prompt.blade.php`):

```blade
<native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-theme-background">
    <native:text class="text-2xl font-bold text-theme-on-background text-center">Share this phone's location</native:text>
    <native:text class="text-base text-theme-on-surface-variant text-center">WxNotify can keep one of your locations at this phone's position, so alerts follow where you are. It sends only the latest position about every 5 minutes, with no history, even while the app is closed.</native:text>
    <native:text class="text-sm text-theme-on-surface-variant text-center">Your phone will ask for location access, including "Allow all the time". Without it, sharing stops when you close the app. Android shows a notification while sharing is on.</native:text>

    @if ($error)
        <native:text class="text-sm text-theme-on-background text-center">{{ $error }}</native:text>
    @endif

    <native:button label="Share my location" @tap="share" class="w-full" />
    <native:button label="Not now" variant="secondary" @tap="notNow" class="w-full" />
</native:column>
```

- [ ] **Step 4: Route and the Account row.** In `mobile/routes/mobile.php` import `LocationPrompt` and add `Route::native('/share-location', LocationPrompt::class);`. In `AccountScreen` add (imports `App\Services\LocationSharing`, `Native\Mobile\Facades\System` already imported):

```php
    public ?string $locationMessage = null;

    /**
     * @return array{status: 'off'|'on'|'waiting', interval_minutes: ?int, last_shared_at: ?string, while_using_only: bool}
     */
    public function locationState(): array
    {
        return app(LocationSharing::class)->state();
    }

    public function locationStatusLabel(): string
    {
        $state = $this->locationState();

        return match ($state['status']) {
            'on' => 'On — about every '.($state['interval_minutes'] ?? 5).' minutes'
                .($state['last_shared_at'] !== null ? ' · last shared '.\Illuminate\Support\Carbon::parse($state['last_shared_at'])->setTimezone($this->profile['timezone'] ?? config('app.timezone'))->format('g:i a') : ''),
            'waiting' => 'Waiting for location permission',
            default => 'Off',
        };
    }

    public function shareLocation(): void
    {
        $this->locationMessage = null;

        $this->navigate('/share-location');
    }

    public function stopSharingLocation(): void
    {
        $this->locationMessage = app(LocationSharing::class)->disable()
            ? null
            : 'Couldn\'t stop sharing. Check your connection and try again.';
    }

    public function openLocationSettings(): void
    {
        System::appSettings();
    }

    /**
     * Back from the system settings or the explainer: the native watch may have changed.
     */
    public function onResume(): void {}
```

View — insert after the push block in `account-screen.blade.php`, in the same vocabulary:

```blade
    @php($location = $this->locationState())
    <native:column class="w-full gap-2">
        <native:text class="text-base font-bold text-theme-on-background">Share this phone's location</native:text>
        <native:text class="text-base text-theme-on-surface-variant">{{ $this->locationStatusLabel() }}</native:text>

        @if ($location['status'] === 'on')
            @if ($location['while_using_only'])
                <native:text class="text-sm text-theme-on-surface-variant">Stops when you close the app. Allow location "Always" in your phone's settings to keep it going.</native:text>
                <native:button label="Open settings" variant="secondary" @tap="openLocationSettings" class="w-full" />
            @endif
            <native:button label="Stop sharing" variant="secondary" @tap="stopSharingLocation" class="w-full" />
        @elseif ($location['status'] === 'waiting')
            <native:button label="Open settings" variant="secondary" @tap="openLocationSettings" class="w-full" />
            <native:button label="Stop sharing" variant="secondary" @tap="stopSharingLocation" class="w-full" />
        @else
            <native:button label="Share location" variant="secondary" @tap="shareLocation" class="w-full" />
        @endif

        @if ($locationMessage)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $locationMessage }}</native:text>
        @endif
    </native:column>
```

`AccountScreen` already uses the `HandlesPushToken` trait and an `onResume` may already exist; if it does, extend it instead of redefining it. The screen already has the `$profile` property used for the timezone.

- [ ] **Step 5: Re-arm on launch** — in `AlertsScreen::mount()` after the existing `refreshIfDue()` call add `app(LocationSharing::class)->refreshOnLaunch();` (import `App\Services\LocationSharing`). It never throws.

- [ ] **Step 6: Render guard** — add `LocationPrompt` to `ScreensRenderTest.php` following that file's pattern.

- [ ] **Step 7: Run the whole mobile suite and the validator** — Expected: PASS; `native:validate` clean (if it rejects the view, rewrite the markup into the forms it accepts and keep the visible strings).

- [ ] **Step 8: Pint and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile
git commit -m "App: add the location-sharing explainer, Account row and launch re-arm" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Rules, spec wording and both full suites

**Files:**
- Modify: `.ai/rules/api.md` (append a section)
- Modify: `.ai/rules/models.md` (append a section)
- Modify: `docs/superpowers/specs/2026-10-05-mobile-location-design.md` (only where the build differs; keep edits factual and short)

- [ ] **Step 1: Append to `.ai/rules/api.md`**

```markdown

## The location beacon token has one ability and one route, and cascades with its app token
`PUT /api/v1/location-sharing` (app token) mints a Sanctum token with exactly the ability `['location']`, `parent_token_id` = the app token, and is the only response that ever carries it. It can call only `POST /api/v1/location` (`ability:location`, throttle `app-location`); `ability:app` routes refuse it and that route refuses an app token. It exists because the geolocation plugin keeps its bearer token in plain `SharedPreferences`/`UserDefaults`: never hand the plugin the app token. `personal_access_tokens.parent_token_id` cascades, so revoking or signing out the app token deletes the beacon token at the database level; "Signed-in devices" lists only `['app']` tokens so beacon tokens never appear. Position writes go through `RecordMobilePosition` (ignores a fix older than the stored one, logging off around the save, address lookup queued by `QueueAddressLookup`), and the endpoint answers 204 even for a stale point because the plugin ignores the body and never retries.
```

- [ ] **Step 2: Append to `.ai/rules/models.md`**

```markdown

## A Location is never deleted because its phone went away
`alert_rule_location` cascades on Location delete, so deleting a Location silently strips alert rules of their location. An app-sourced mobile Location (`mobile_source` `app`) is linked to a phone through `app_token_id` (FK to the app token, `nullOnDelete`, unique); signing out, revoking the device or turning sharing off only nulls the link, and the Location keeps its last position (it turns grey after an hour) and shows in Needs attention while `hasMissingDevice()` is true. Signing in again on the same phone re-links the unlinked app Location with the same device name instead of creating a duplicate. A Location made stationary clears `app_token_id` and the other mobile fields in `Location::saving`. `app_token_id` is set only from server code with `forceFill`, never from request input.
```

- [ ] **Step 3: Spec wording.** Make the spec match the build: the explainer is reached from the Account row (not shown automatically), the permission-state wording of ruling 4, the `fineAccuracy: false` + `minDistance(250)` choice, `ExtendAppTokenExpiry` reused unchanged, and the beacon `url` handling you chose in Task 6.

- [ ] **Step 4: Run both full suites.** Backend (parallel, 6 processes, about 4 minutes): `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`. Mobile: `cd mobile && vendor/bin/pest --compact && php artisan native:validate`. If a test fails, do not fix code in this task: report which tests failed, whether they relate to this plan's commits, and whether the test passes alone (parallel-run flakes: lock-memory overflow, global-namespace `use`, unordered assertions, stray HTTP).

- [ ] **Step 5: Commit**

```bash
git add .ai docs
git commit -m "Docs: record the mobile location rules and align the spec" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Device verification on the Android emulator (controller task, not an implementer)

Run by the controller after the final review and one fix wave. It needs the owner for the build.

**Prerequisites (owner):** run `php artisan migrate` on the dev database (the container) — or let the controller do it and say so; `NATIVEPHP_GEOLOCATION_BACKGROUND_LOCATION=true` in `mobile/.env`; the app rebuilt and reinstalled with `cd /Users/jimmy/Documents/WxNotify/wxnotify-nextgen/mobile && php artisan native:run android` (the Android build directory is a symlink outside iCloud; see the project memory); an emulator with a Google Play image and LAN access (`adb shell "toybox nc -z -w 5 192.168.36.15 443; echo exit=\$?"` prints `exit=0`).

- [ ] **Step 1:** Open Account, tap "Share location", the explainer appears; tap "Share my location"; accept the location permission (the Android plugin requests only fine/coarse location: there is no "Allow all the time" prompt on Android; that two-step upgrade exists on iOS only).
- [ ] **Step 2: Server state.** In the container: one new mobile Location with `mobile_source` `app`, linked (`app_token_id` set), one token with abilities `["location"]` and a `parent_token_id`; the Location's position appears within seconds (`adb emu geo fix -97.4 35.2` first if the emulator has no fix).
- [ ] **Step 3: Movement.** `adb emu geo fix -97.5 35.3`; after the interval (5 minutes; use the dashboard to set the Location's interval to 5, or wait) the Location's `lat`/`lng` and `mobile_position_fixed_at` change; the dashboard Locations map shows the pin; an alert rule using the Location matches against the new position.
- [ ] **Step 4: Account row.** Shows On with "about every 5 minutes" and a last-shared time; "Stop sharing" stops the native watch (`adb shell dumpsys activity services | grep -i location`) and the Location is unlinked but kept, with its rule links.
- [ ] **Step 5: Revoke and sign-out.** Share again, then revoke the device on the dashboard "Signed-in devices" page: the beacon gets 401s (`docker logs`), and opening the app stops the watch; share again and sign out in the app: the watch stops, the beacon token and link are gone, the Location is kept.
- [ ] **Step 6: Record the outcome** in the memory file for the mobile project (what was verified on a device, what was not: iOS needs a physical device and the Always permission flow), then ask the owner before pushing.
- [ ] **Step 7: Extra checks from the final review.** (a) the native buffer: `bufferBytes` in `Geolocation::backgroundWatchStatus()` (or the app's JSONL file under `noBackupFilesDir/nativephp_location/`) must not grow without bound across launches (the launch re-arm trims it); (b) battery: leave sharing on for about an hour with the screen off and read the battery stats (`adb shell dumpsys batterystats`) against a run with sharing off; (c) a forced 401: delete the beacon token in the dev database (`DELETE FROM personal_access_tokens WHERE abilities = '["location"]'`), wait for a beacon attempt (the Account row's last-shared time must STOP advancing once `lastShareStatus` is 401), then open the app: the launch re-arm must mint a fresh beacon token (a new `["location"]` row) and the Location starts moving again; (d) the Account row after the OS permission dialog and after Share: whether it re-renders from "Waiting for location permission" to On by itself.
