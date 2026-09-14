# Traccar Servers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a subscriber connect one or more Traccar servers, attach specific Traccar devices as mobile `Location`s, and have those locations' live positions feed into the existing alert-matching engine exactly like a stationary location's fixed coordinates do today.

**Architecture:** A new `traccar_servers` table holds per-subscriber server connections (base URL + encrypted API token). `locations` gains a `mobile_source` discriminator plus generic "how fresh is this position" bookkeeping and Traccar-specific attachment columns. A scheduled Artisan command polls each server with due mobile locations via Traccar's REST API (confirmed against its real OpenAPI spec) and writes fresh `lat`/`lng` back onto those locations — no changes needed to how the alert-matching engine reads a Location's coordinates, except a new `hasFix()` guard everywhere coordinates get dereferenced, since a mobile location has none until its first successful poll.

**Tech Stack:** Laravel 13, Filament v5, PHP 8.4, PostgreSQL, Pest.

**Design doc:** `docs/superpowers/specs/2026-09-14-traccar-servers-design.md` — read it first for the full rationale behind every decision below.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/..._create_traccar_servers_table.php` | New table |
| `database/migrations/..._add_mobile_tracking_to_locations_table.php` | Nullable lat/lng + new columns on `locations` |
| `app/Enums/MobileSource.php` | Discriminates which mobile-tracking columns apply |
| `app/Models/TraccarServer.php` | Server connection model |
| `database/factories/TraccarServerFactory.php` | Test factory |
| `app/Models/Location.php` | `hasFix()`, `traccarServer()`, new Fillable/casts, activity-log exclusions |
| `database/factories/LocationFactory.php` | `mobile()`/`withFix()` states |
| `app/Models/User.php` | Tier-limit methods for servers/devices |
| `app/Actions/Traccar/ListTraccarDevices.php` | `GET /api/devices` |
| `app/Actions/Traccar/PollTraccarServer.php` | `GET /api/positions` |
| `app/Console/Commands/PollTraccarPositions.php` | `traccar:poll-positions`, scheduled every 5 min |
| `app/Actions/Weather/MatchWeatherAlertRules.php` | `hasFix()` guards in Location/Polygon matching |
| `app/Console/Commands/DispatchScheduledAlerts.php` | `hasFix()` guard |
| `app/Filament/Dashboard/Resources/TraccarServers/**` | New subscriber-facing CRUD resource |
| `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php` | Mobile branch: server/device/interval fields |
| `app/Filament/Dashboard/Resources/Locations/Tables/LocationsTable.php` | "Position updated" column |
| `app/Filament/Dashboard/Resources/Locations/Pages/CreateLocation.php` | Per-server device-limit gating |
| `bootstrap/app.php` | Schedule registration |

---

## Task 1: `traccar_servers` table + nullable `locations` coordinates

**Files:**
- Create: `database/migrations/2026_09_14_020000_create_traccar_servers_table.php`
- Create: `database/migrations/2026_09_14_020100_add_mobile_tracking_to_locations_table.php`

- [ ] **Step 1: Create the `traccar_servers` migration**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_traccar_servers_table --no-interaction"
```

This creates a timestamped file — rename it (or just edit the generated one in place) so its content is exactly:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traccar_servers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('base_url');
            $table->text('api_token');
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_poll_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traccar_servers');
    }
};
```

- [ ] **Step 2: Create the `locations` mobile-tracking migration**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration add_mobile_tracking_to_locations_table --no-interaction"
```

Content:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Blueprint's ->change() needs doctrine/dbal, which this project
        // doesn't install -- raw SQL avoids that dependency entirely.
        DB::statement('ALTER TABLE locations ALTER COLUMN lat DROP NOT NULL');
        DB::statement('ALTER TABLE locations ALTER COLUMN lng DROP NOT NULL');

        Schema::table('locations', function (Blueprint $table): void {
            $table->string('mobile_source')->nullable()->after('type');
            $table->timestamp('mobile_position_updated_at')->nullable()->after('timezone');
            $table->unsignedTinyInteger('mobile_update_interval_minutes')->nullable()->after('mobile_position_updated_at');
            $table->foreignId('traccar_server_id')->nullable()->after('mobile_update_interval_minutes')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('traccar_device_id')->nullable()->after('traccar_server_id');
            $table->string('traccar_device_name')->nullable()->after('traccar_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('traccar_server_id');
            $table->dropColumn(['mobile_source', 'mobile_position_updated_at', 'mobile_update_interval_minutes', 'traccar_device_id', 'traccar_device_name']);
        });

        DB::statement('ALTER TABLE locations ALTER COLUMN lat SET NOT NULL');
        DB::statement('ALTER TABLE locations ALTER COLUMN lng SET NOT NULL');
    }
};
```

- [ ] **Step 3: Run the migrations and verify**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"
```

Expected: both migrations run with no errors.

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'var_dump(Schema::hasTable(\"traccar_servers\"), Schema::getColumnListing(\"locations\"));'"
```

Expected: `traccar_servers` exists; `locations` column list includes `mobile_source`, `mobile_position_updated_at`, `mobile_update_interval_minutes`, `traccar_server_id`, `traccar_device_id`, `traccar_device_name`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_09_14_020000_create_traccar_servers_table.php database/migrations/2026_09_14_020100_add_mobile_tracking_to_locations_table.php
git commit -m "Add traccar_servers table and mobile-tracking columns on locations"
```

---

## Task 2: `MobileSource` enum, `TraccarServer` model, factory

**Files:**
- Create: `app/Enums/MobileSource.php`
- Create: `app/Models/TraccarServer.php`
- Create: `database/factories/TraccarServerFactory.php`

- [ ] **Step 1: Create the enum**

```php
<?php

namespace App\Enums;

enum MobileSource: string
{
    case Traccar = 'traccar';

    public function label(): string
    {
        return match ($this) {
            self::Traccar => 'Traccar',
        };
    }
}
```

- [ ] **Step 2: Create the model**

```php
<?php

namespace App\Models;

use Database\Factories\TraccarServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['user_id', 'label', 'base_url', 'api_token'])]
class TraccarServer extends Model
{
    /** @use HasFactory<TraccarServerFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        // Deliberately excludes api_token: it's an encrypted column carrying
        // a real Traccar credential, and activitylog logs the decrypted
        // attribute value into a plain-text properties column -- mirrors
        // NotificationChannel's identical treatment of apprise_url.
        return LogOptions::defaults()
            ->logOnly(['user_id', 'label', 'base_url'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'last_polled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
```

- [ ] **Step 3: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\TraccarServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TraccarServer>
 */
class TraccarServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->company().' Traccar',
            'base_url' => 'https://'.fake()->domainName(),
            'api_token' => fake()->uuid(),
        ];
    }
}
```

- [ ] **Step 4: Verify via tinker**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'App\Models\TraccarServer::factory()->make()->toArray();'"
```

Expected: no errors, prints an array with `label`, `base_url`, `api_token` set.

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Enums/MobileSource.php app/Models/TraccarServer.php database/factories/TraccarServerFactory.php
git commit -m "Add MobileSource enum and TraccarServer model"
```

---

## Task 3: `Location` model — `hasFix()`, Traccar relation, mobile factory states

**Files:**
- Modify: `app/Models/Location.php`
- Modify: `database/factories/LocationFactory.php`
- Test: `tests/Feature/LocationModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\LocationType;
use App\Models\Location;

test('a stationary location with lat/lng set has a fix', function () {
    $location = Location::factory()->create();

    expect($location->hasFix())->toBeTrue();
});

test('a mobile location created via the mobile() factory state has no fix yet', function () {
    $location = Location::factory()->mobile()->create();

    expect($location->type)->toBe(LocationType::Mobile);
    expect($location->lat)->toBeNull();
    expect($location->lng)->toBeNull();
    expect($location->hasFix())->toBeFalse();
});

test('a mobile location given a fix via withFix() has one', function () {
    $location = Location::factory()->mobile()->withFix(39.0997, -94.5786)->create();

    expect($location->hasFix())->toBeTrue();
    expect((float) $location->lat)->toBe(39.0997);
    expect((float) $location->lng)->toBe(-94.5786);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/LocationModelTest.php --compact"
```

Expected: FAIL — `hasFix()` doesn't exist and the `mobile()`/`withFix()` factory states don't exist yet.

- [ ] **Step 3: Update the `Location` model**

Replace the full contents of `app/Models/Location.php`:

```php
<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'user_id', 'label', 'type', 'address', 'lat', 'lng', 'timezone',
    'mobile_source', 'mobile_update_interval_minutes',
    'traccar_server_id', 'traccar_device_id', 'traccar_device_name',
])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        // Excludes lat/lng/mobile_position_updated_at: a polled mobile
        // location's position changes every 5-30 minutes forever, and
        // logging that as "activity" would drown out everything else about
        // this model in noise. Everything else still logs normally.
        return LogOptions::defaults()
            ->logOnly(['user_id', 'label', 'type', 'address', 'timezone', 'mobile_source', 'traccar_server_id', 'traccar_device_id', 'traccar_device_name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'mobile_source' => MobileSource::class,
            'mobile_position_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function traccarServer(): BelongsTo
    {
        return $this->belongsTo(TraccarServer::class);
    }

    /**
     * A mobile location has no coordinates until its first successful
     * Traccar poll. Every call site that dereferences lat/lng for matching
     * must check this first -- a plain (float) cast on null silently
     * becomes 0.0 ("Null Island"), not a safe "no data" signal.
     */
    public function hasFix(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }
}
```

- [ ] **Step 4: Add factory states**

Add to `database/factories/LocationFactory.php`, inside the `LocationFactory` class, after `definition()`:

```php
    public function mobile(): static
    {
        return $this->state(fn () => [
            'type' => LocationType::Mobile,
            'address' => null,
            'lat' => null,
            'lng' => null,
            'mobile_source' => MobileSource::Traccar,
            'mobile_update_interval_minutes' => 15,
            'traccar_server_id' => TraccarServer::factory(),
            'traccar_device_id' => fake()->randomNumber(5),
            'traccar_device_name' => fake()->word(),
        ]);
    }

    public function withFix(float $lat, float $lng): static
    {
        return $this->state(fn () => [
            'lat' => $lat,
            'lng' => $lng,
            'mobile_position_updated_at' => now(),
        ]);
    }
```

Add the two new imports at the top of the file, alongside the existing `use App\Enums\LocationType;`:

```php
use App\Enums\MobileSource;
use App\Models\TraccarServer;
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/LocationModelTest.php --compact"
```

Expected: PASS (3 tests).

- [ ] **Step 6: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Models/Location.php database/factories/LocationFactory.php tests/Feature/LocationModelTest.php
git commit -m "Add Location::hasFix() and mobile/withFix factory states"
```

---

## Task 4: `User` model — Traccar tier limits

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/UserTraccarLimitsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Tier;
use App\Models\TraccarServer;
use App\Models\User;

test('Free tier cannot create any Traccar servers', function () {
    $user = User::factory()->create(['tier_override' => Tier::Free]);

    expect($user->traccarServerLimit())->toBe(0);
    expect($user->canCreateTraccarServer())->toBeFalse();
});

test('Plus tier can create up to 1 Traccar server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);

    expect($user->canCreateTraccarServer())->toBeTrue();

    TraccarServer::factory()->for($user)->create();

    expect($user->canCreateTraccarServer())->toBeFalse();
});

test('Pro tier can create up to 3 Traccar servers', function () {
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    TraccarServer::factory()->count(3)->for($user)->create();

    expect($user->canCreateTraccarServer())->toBeFalse();
});

test('Plus tier can attach up to 5 devices per server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $server = TraccarServer::factory()->for($user)->create();

    expect($user->canAddTraccarDevice($server->id))->toBeTrue();

    \App\Models\Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    \App\Models\Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    \App\Models\Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    \App\Models\Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    \App\Models\Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);

    expect($user->canAddTraccarDevice($server->id))->toBeFalse();
});

test('a device limit on one server does not affect a different server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    $serverA = TraccarServer::factory()->for($user)->create();
    $serverB = TraccarServer::factory()->for($user)->create();

    \App\Models\Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $serverA->id]);

    expect($user->canAddTraccarDevice($serverA->id))->toBeTrue();
    expect($user->canAddTraccarDevice($serverB->id))->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/UserTraccarLimitsTest.php --compact"
```

Expected: FAIL — none of these methods exist on `User` yet.

- [ ] **Step 3: Add the methods to `User.php`**

Add this block to `app/Models/User.php` immediately after the existing `notificationChannels()`/`canAddChannel()` methods (before `alertRules()`):

```php
    public function traccarServers(): HasMany
    {
        return $this->hasMany(TraccarServer::class);
    }

    /**
     * Maximum number of Traccar servers this user's tier allows, or null for unlimited.
     */
    public function traccarServerLimit(): ?int
    {
        return match ($this->tier()) {
            Tier::Free => 0,
            Tier::Plus => 1,
            Tier::Pro => 3,
        };
    }

    public function canCreateTraccarServer(): bool
    {
        $limit = $this->traccarServerLimit();

        return $limit === null || $this->traccarServers()->count() < $limit;
    }

    /**
     * Maximum number of devices allowed per Traccar server this user's tier allows, or null for unlimited.
     */
    public function traccarDeviceLimitPerServer(): ?int
    {
        return match ($this->tier()) {
            Tier::Free => 0,
            Tier::Plus => 5,
            Tier::Pro => 10,
        };
    }

    public function canAddTraccarDevice(int $traccarServerId): bool
    {
        $limit = $this->traccarDeviceLimitPerServer();

        return $limit === null || $this->locations()->where('traccar_server_id', $traccarServerId)->count() < $limit;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/UserTraccarLimitsTest.php --compact"
```

Expected: PASS (5 tests).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Models/User.php tests/Feature/UserTraccarLimitsTest.php
git commit -m "Add Traccar server/device tier limits to User"
```

---

## Task 5: `ListTraccarDevices` action

**Files:**
- Create: `app/Actions/Traccar/ListTraccarDevices.php`
- Test: `tests/Feature/ListTraccarDevicesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Traccar\ListTraccarDevices;
use App\Models\TraccarServer;
use Illuminate\Support\Facades\Http;

test('a successful response resolves to a list of devices', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'api_token' => 'tok123']);

    Http::fake([
        'traccar.example.com/api/devices' => Http::response([
            ['id' => 1, 'name' => 'My Truck', 'uniqueId' => 'abc123'],
            ['id' => 2, 'name' => 'My Van', 'uniqueId' => 'def456'],
        ]),
    ]);

    $devices = (new ListTraccarDevices)($server);

    expect($devices)->toBe([
        ['id' => 1, 'name' => 'My Truck'],
        ['id' => 2, 'name' => 'My Van'],
    ]);
});

test('the request is authenticated with the server\'s bearer token', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'api_token' => 'secret-token']);

    Http::fake(['traccar.example.com/api/devices' => Http::response([])]);

    (new ListTraccarDevices)($server);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

test('a non-2xx response resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake(['traccar.example.com/api/devices' => Http::response(['error' => 'Unauthorized'], 401)]);

    expect((new ListTraccarDevices)($server))->toBeNull();
});

test('a connection failure resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://unreachable.example.com']);

    Http::fake(['unreachable.example.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

    expect((new ListTraccarDevices)($server))->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ListTraccarDevicesTest.php --compact"
```

Expected: FAIL — the class doesn't exist.

- [ ] **Step 3: Implement the action**

```php
<?php

namespace App\Actions\Traccar;

use App\Models\TraccarServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches a Traccar server's devices via its confirmed REST API
 * (github.com/traccar/traccar/blob/master/openapi.yaml's GET /api/devices),
 * authenticated with the server's long-lived Bearer token (the ApiKey
 * security scheme in that spec, available since Traccar 5.3).
 *
 * Returns null (not an empty array) on any failure, so callers can tell
 * "this server is unreachable/misconfigured" apart from "reachable, but
 * genuinely has zero devices" -- the same distinction PollTraccarServer
 * makes for the same reason.
 */
class ListTraccarDevices
{
    /**
     * @return ?array<int, array{id: int, name: string}>
     */
    public function __invoke(TraccarServer $server): ?array
    {
        try {
            $response = Http::withToken($server->api_token)
                ->timeout(10)
                ->get(rtrim($server->base_url, '/').'/api/devices');
        } catch (Throwable $e) {
            Log::warning('Failed to reach Traccar server for devices', [
                'traccar_server_id' => $server->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Traccar server returned an error fetching devices', [
                'traccar_server_id' => $server->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        return collect($response->json())
            ->map(fn (array $device): array => ['id' => $device['id'], 'name' => $device['name']])
            ->all();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/ListTraccarDevicesTest.php --compact"
```

Expected: PASS (4 tests).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Traccar/ListTraccarDevices.php tests/Feature/ListTraccarDevicesTest.php
git commit -m "Add ListTraccarDevices action"
```

---

## Task 6: `PollTraccarServer` action

**Files:**
- Create: `app/Actions/Traccar/PollTraccarServer.php`
- Test: `tests/Feature/PollTraccarServerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Traccar\PollTraccarServer;
use App\Models\TraccarServer;
use Illuminate\Support\Facades\Http;

test('a successful response resolves to positions keyed by deviceId', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'api_token' => 'tok123']);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response([
            ['id' => 501, 'deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786, 'fixTime' => '2026-09-14T12:00:00Z'],
            ['id' => 502, 'deviceId' => 2, 'latitude' => 41.8781, 'longitude' => -87.6298, 'fixTime' => '2026-09-14T12:01:00Z'],
        ]),
    ]);

    $positions = (new PollTraccarServer)($server);

    expect($positions)->toBe([
        1 => ['lat' => 39.0997, 'lng' => -94.5786, 'fixTime' => '2026-09-14T12:00:00Z'],
        2 => ['lat' => 41.8781, 'lng' => -87.6298, 'fixTime' => '2026-09-14T12:01:00Z'],
    ]);
});

test('the request is authenticated with the server\'s bearer token', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'api_token' => 'secret-token']);

    Http::fake(['traccar.example.com/api/positions' => Http::response([])]);

    (new PollTraccarServer)($server);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

test('a non-2xx response resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);

    Http::fake(['traccar.example.com/api/positions' => Http::response(['error' => 'Unauthorized'], 401)]);

    expect((new PollTraccarServer)($server))->toBeNull();
});

test('a connection failure resolves to null', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://unreachable.example.com']);

    Http::fake(['unreachable.example.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

    expect((new PollTraccarServer)($server))->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/PollTraccarServerTest.php --compact"
```

Expected: FAIL — the class doesn't exist.

- [ ] **Step 3: Implement the action**

```php
<?php

namespace App\Actions\Traccar;

use App\Models\TraccarServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches every device's latest position from a Traccar server in one call
 * (GET /api/positions with no params, per the confirmed OpenAPI spec --
 * "without any params, it returns a list of last known positions for all
 * the user's Devices"). Returns null (not an empty array) on failure, so
 * the polling command can distinguish "nothing changed" from "this server
 * is broken right now" and leave existing positions untouched rather than
 * blanking them.
 */
class PollTraccarServer
{
    /**
     * @return ?array<int, array{lat: float, lng: float, fixTime: ?string}> keyed by deviceId
     */
    public function __invoke(TraccarServer $server): ?array
    {
        try {
            $response = Http::withToken($server->api_token)
                ->timeout(10)
                ->get(rtrim($server->base_url, '/').'/api/positions');
        } catch (Throwable $e) {
            Log::warning('Failed to reach Traccar server for positions', [
                'traccar_server_id' => $server->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Traccar server returned an error fetching positions', [
                'traccar_server_id' => $server->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        return collect($response->json())
            ->mapWithKeys(fn (array $position): array => [
                $position['deviceId'] => [
                    'lat' => (float) $position['latitude'],
                    'lng' => (float) $position['longitude'],
                    'fixTime' => $position['fixTime'] ?? null,
                ],
            ])
            ->all();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/PollTraccarServerTest.php --compact"
```

Expected: PASS (4 tests).

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Traccar/PollTraccarServer.php tests/Feature/PollTraccarServerTest.php
git commit -m "Add PollTraccarServer action"
```

---

## Task 7: `traccar:poll-positions` command + schedule

**Files:**
- Create: `app/Console/Commands/PollTraccarPositions.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/PollTraccarPositionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Location;
use App\Models\TraccarServer;
use Illuminate\Support\Facades\Http;

test('a due mobile location is updated with its server\'s reported position', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_update_interval_minutes' => 15,
    ]);

    Http::fake([
        'traccar.example.com/api/positions' => Http::response([
            ['deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786, 'fixTime' => '2026-09-14T12:00:00Z'],
        ]),
    ]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    $location->refresh();
    expect((float) $location->lat)->toBe(39.0997);
    expect((float) $location->lng)->toBe(-94.5786);
    expect($location->mobile_position_updated_at)->not->toBeNull();
});

test('a successful poll does not spam the activity log', function () {
    // Location's activity log doesn't exclude lat/lng (they're real,
    // audit-worthy data for a subscriber's own stationary-location edits),
    // so without disableLogging() around the poll's write, this exact
    // command would create a fresh Activity row every 5-30 minutes forever
    // per mobile location. This proves that doesn't happen.
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->create(['traccar_server_id' => $server->id, 'traccar_device_id' => 1]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 39.0997, 'longitude' => -94.5786, 'fixTime' => null],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect(\Spatie\Activitylog\Models\Activity::where('subject_type', Location::class)->where('subject_id', $location->id)->exists())->toBeFalse();
});

test('a location polled more recently than its own interval is left alone', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    $location = Location::factory()->mobile()->withFix(10.0, 10.0)->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_update_interval_minutes' => 30,
        'mobile_position_updated_at' => now()->subMinutes(5),
    ]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 99.0, 'longitude' => 99.0, 'fixTime' => null],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    $location->refresh();
    expect((float) $location->lat)->toBe(10.0);
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'traccar.example.com'));
});

test('a server with no due locations is never queried', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com']);
    Location::factory()->mobile()->withFix(10.0, 10.0)->create([
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 1,
        'mobile_update_interval_minutes' => 30,
        'mobile_position_updated_at' => now()->subMinutes(5),
    ]);

    Http::fake();

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    Http::assertNothingSent();
});

test('one server\'s failure does not block a second server\'s successful poll', function () {
    $badServer = TraccarServer::factory()->create(['base_url' => 'https://bad.example.com']);
    $goodServer = TraccarServer::factory()->create(['base_url' => 'https://good.example.com']);
    $badLocation = Location::factory()->mobile()->create(['traccar_server_id' => $badServer->id, 'traccar_device_id' => 1]);
    $goodLocation = Location::factory()->mobile()->create(['traccar_server_id' => $goodServer->id, 'traccar_device_id' => 5]);

    Http::fake([
        'bad.example.com/*' => Http::response(['error' => 'nope'], 500),
        'good.example.com/*' => Http::response([
            ['deviceId' => 5, 'latitude' => 1.0, 'longitude' => 2.0, 'fixTime' => null],
        ]),
    ]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($goodLocation->refresh()->lat)->not->toBeNull();
    expect($badLocation->refresh()->lat)->toBeNull();
    expect($badServer->refresh()->last_poll_error)->not->toBeNull();
    expect($goodServer->refresh()->last_poll_error)->toBeNull();
});

test('a subsequent successful poll clears a previous poll error', function () {
    $server = TraccarServer::factory()->create(['base_url' => 'https://traccar.example.com', 'last_poll_error' => 'previous failure']);
    Location::factory()->mobile()->create(['traccar_server_id' => $server->id, 'traccar_device_id' => 1]);

    Http::fake(['traccar.example.com/api/positions' => Http::response([
        ['deviceId' => 1, 'latitude' => 1.0, 'longitude' => 2.0, 'fixTime' => null],
    ])]);

    $this->artisan('traccar:poll-positions')->assertSuccessful();

    expect($server->refresh()->last_poll_error)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/PollTraccarPositionsTest.php --compact"
```

Expected: FAIL — the `traccar:poll-positions` command doesn't exist.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Actions\Traccar\PollTraccarServer;
use App\Models\Location;
use App\Models\TraccarServer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Polls every Traccar server that has at least one due mobile Location
 * attached, one /api/positions call per server regardless of how many of
 * its devices are due (the endpoint already returns every device's
 * position at once). Intended to run every 5 minutes (see bootstrap/app.php)
 * -- the finest per-location mobile_update_interval_minutes allowed, so the
 * due-check below is what actually varies polling frequency, not the
 * schedule cadence itself.
 */
class PollTraccarPositions extends Command
{
    protected $signature = 'traccar:poll-positions';

    protected $description = 'Poll due Traccar servers and update their mobile Locations\' positions';

    public function handle(PollTraccarServer $poll): int
    {
        $dueLocations = Location::query()
            ->whereNotNull('traccar_server_id')
            ->whereNotNull('traccar_device_id')
            ->where(function ($query) {
                $query->whereNull('mobile_position_updated_at')
                    ->orWhereRaw(
                        "mobile_position_updated_at <= now() - (mobile_update_interval_minutes::text || ' minutes')::interval",
                    );
            })
            ->get();

        $updated = 0;

        foreach ($dueLocations->groupBy('traccar_server_id') as $serverId => $locations) {
            $server = TraccarServer::find($serverId);

            if ($server === null) {
                continue;
            }

            $updated += $this->pollServer($server, $locations, $poll);
        }

        $this->info("Updated {$updated} mobile location(s).");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Location>  $locations
     */
    private function pollServer(TraccarServer $server, Collection $locations, PollTraccarServer $poll): int
    {
        try {
            $positions = $poll($server);
        } catch (Throwable $e) {
            $this->recordFailure($server, $e->getMessage());

            return 0;
        }

        if ($positions === null) {
            $this->recordFailure($server, 'Traccar server unreachable or returned an error.');

            return 0;
        }

        $server->forceFill(['last_polled_at' => now(), 'last_poll_error' => null])->save();

        $updated = 0;

        foreach ($locations as $location) {
            $position = $positions[$location->traccar_device_id] ?? null;

            if ($position === null) {
                continue;
            }

            // Location's activity log excludes mobile_position_updated_at
            // (see Task 3) but NOT lat/lng -- those stay logged for a
            // subscriber's own manual repositioning of a stationary
            // location. A poll writes lat/lng too, though, every 5-30
            // minutes forever, so this exact write needs its own
            // logging disabled rather than relying on a model-level field
            // exclusion (which can't tell "the poller wrote this" apart
            // from "the subscriber corrected their pin"). disableLogging()/
            // enableLogging() are Spatie Activitylog instance methods
            // (vendor/spatie/laravel-activitylog/src/Models/Concerns/LogsActivity.php).
            $location->disableLogging();

            try {
                $location->forceFill([
                    'lat' => $position['lat'],
                    'lng' => $position['lng'],
                    'mobile_position_updated_at' => now(),
                ])->save();
            } finally {
                $location->enableLogging();
            }

            $updated++;
        }

        return $updated;
    }

    private function recordFailure(TraccarServer $server, string $error): void
    {
        $server->forceFill(['last_polled_at' => now(), 'last_poll_error' => $error])->save();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/PollTraccarPositionsTest.php --compact"
```

Expected: PASS (5 tests).

- [ ] **Step 5: Register the schedule**

In `bootstrap/app.php`, inside the existing `->withSchedule(function (Schedule $schedule): void { ... })` closure, add a line alongside the existing two:

```php
        $schedule->command('shapefile:scan')->daily();
        $schedule->command('alerts:dispatch-scheduled')->everyFifteenMinutes();
        $schedule->command('traccar:poll-positions')->everyFiveMinutes();
```

- [ ] **Step 6: Verify the schedule registered**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && php artisan schedule:list"
```

Expected: a row for `traccar:poll-positions` running every 5 minutes.

- [ ] **Step 7: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Console/Commands/PollTraccarPositions.php bootstrap/app.php tests/Feature/PollTraccarPositionsTest.php
git commit -m "Add traccar:poll-positions command, scheduled every 5 minutes"
```

---

## Task 8: `hasFix()` guards in the alert-matching engine

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php`
- Modify: `app/Console/Commands/DispatchScheduledAlerts.php`
- Test: `tests/Feature/MatchWeatherAlertRulesTest.php`
- Test: `tests/Feature/DispatchScheduledAlertsTest.php`
- Test: `tests/Feature/MatchLightningFlashesTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/MatchWeatherAlertRulesTest.php` (needs `use App\Models\Location;` — check the top of the file already imports it; if not, add it):

```php
test('a Location-mode rule against a fixless mobile location never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->mobile()->create();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ069']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule against a fixless mobile location falls back to Location matching and still never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->mobile()->create();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id,
        'polygon' => [['lat' => 39.0, 'lon' => -95.0], ['lat' => 39.2, 'lon' => -95.0], ['lat' => 39.2, 'lon' => -94.8], ['lat' => 39.0, 'lon' => -94.8]],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});
```

Add to `tests/Feature/DispatchScheduledAlertsTest.php`:

```php
test('a Threshold rule against a fixless mobile location is skipped, not crashed', function () {
    $location = Location::factory()->mobile()->create();
    $rule = AlertRule::factory()->create([
        'category' => 'threshold', 'location_id' => $location->id, 'delivery_time' => '06:00',
        'variable' => 'temperature', 'direction' => 'above', 'threshold_value' => 90, 'timeframe' => 'day',
    ]);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    Http::fake();
    Bus::fake();

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertNothingDispatched();
    Http::assertNothingSent();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});
```

(This test needs `use App\Models\Location;`, `use Illuminate\Support\Facades\Bus;` at the top of the file — add them if not already present.)

Add to `tests/Feature/MatchLightningFlashesTest.php`:

```php
test('a flash near a fixless mobile location never matches (raw SQL, not a PHP guard)', function () {
    Bus::fake();
    $user = User::factory()->create();
    $location = Location::factory()->mobile()->for($user)->create();
    $rule = AlertRule::factory()->lightning()->for($user)->for($location)->create(['radius_miles' => 15]);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    (new MatchLightningFlashes)([['id' => '1', 'lat' => 0.0, 'lon' => 0.0]]);

    Bus::assertNotDispatched(DeliverScheduledAlert::class);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/DispatchScheduledAlertsTest.php tests/Feature/MatchLightningFlashesTest.php --compact"
```

Expected: the two new `MatchWeatherAlertRules` tests and the `DispatchScheduledAlerts` test FAIL (a `(float) null` cast currently produces 0.0, which happens to fall outside these tests' polygons/zones by luck of the fixture coordinates chosen — verify the failure is a genuine "matched when it shouldn't have" or a DB error, not a fixture mistake, before proceeding). The `MatchLightningFlashes` test is expected to already PASS (it's proving existing behavior, not new — a genuinely useful regression guard either way).

- [ ] **Step 3: Add the `hasFix()` guards**

In `app/Actions/Weather/MatchWeatherAlertRules.php`, change:

```php
    private function matchesPolygon(AlertRule $rule, IngestedProduct $product): ?bool
    {
        if ($product->polygon === null || $rule->location === null) {
            return null;
        }
```

to:

```php
    private function matchesPolygon(AlertRule $rule, IngestedProduct $product): ?bool
    {
        if ($product->polygon === null || $rule->location === null || ! $rule->location->hasFix()) {
            return null;
        }
```

and change:

```php
    private function matchesLocationZones(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->location === null || $product->ugc_codes === null) {
            return false;
        }
```

to:

```php
    private function matchesLocationZones(AlertRule $rule, IngestedProduct $product): bool
    {
        if ($rule->location === null || ! $rule->location->hasFix() || $product->ugc_codes === null) {
            return false;
        }
```

In `app/Console/Commands/DispatchScheduledAlerts.php`, change:

```php
        foreach ($rules as $rule) {
            if ($rule->location === null) {
                continue;
            }
```

to:

```php
        foreach ($rules as $rule) {
            if ($rule->location === null || ! $rule->location->hasFix()) {
                continue;
            }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/DispatchScheduledAlertsTest.php tests/Feature/MatchLightningFlashesTest.php --compact"
```

Expected: PASS, all tests in all three files.

- [ ] **Step 5: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Actions/Weather/MatchWeatherAlertRules.php app/Console/Commands/DispatchScheduledAlerts.php tests/Feature/MatchWeatherAlertRulesTest.php tests/Feature/DispatchScheduledAlertsTest.php tests/Feature/MatchLightningFlashesTest.php
git commit -m "Guard the alert-matching engine against fixless mobile locations"
```

---

## Task 9: `TraccarServerResource` (subscriber-facing CRUD)

**Files:**
- Create: `app/Filament/Dashboard/Resources/TraccarServers/TraccarServerResource.php`
- Create: `app/Filament/Dashboard/Resources/TraccarServers/Schemas/TraccarServerForm.php`
- Create: `app/Filament/Dashboard/Resources/TraccarServers/Tables/TraccarServersTable.php`
- Create: `app/Filament/Dashboard/Resources/TraccarServers/Pages/ListTraccarServers.php`
- Create: `app/Filament/Dashboard/Resources/TraccarServers/Pages/CreateTraccarServer.php`
- Create: `app/Filament/Dashboard/Resources/TraccarServers/Pages/EditTraccarServer.php`
- Test: `tests/Feature/TraccarServerResourceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\CreateTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\EditTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\ListTraccarServers;
use App\Models\TraccarServer;
use App\Models\User;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

test('a Plus subscriber can create a Traccar server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Home Server', 'base_url' => 'https://traccar.example.com', 'api_token' => 'tok123'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(TraccarServer::class, ['user_id' => $user->id, 'label' => 'Home Server']);
});

test('a Free subscriber cannot access the resource at all', function () {
    $user = User::factory()->create(['tier_override' => Tier::Free]);
    $this->actingAs($user);

    expect(\App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource::canAccess())->toBeFalse();
});

test('a Plus subscriber is blocked from creating a second server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    TraccarServer::factory()->for($user)->create();
    $this->actingAs($user);

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Second Server', 'base_url' => 'https://two.example.com', 'api_token' => 'tok456'])
        ->call('create')
        ->assertNotified();

    expect(TraccarServer::where('user_id', $user->id)->count())->toBe(1);
});

test('the list only shows the current user\'s own servers', function () {
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    $other = User::factory()->create(['tier_override' => Tier::Pro]);
    $mine = TraccarServer::factory()->for($user)->create();
    TraccarServer::factory()->for($other)->create();
    $this->actingAs($user);

    livewire(ListTraccarServers::class)
        ->assertCanSeeTableRecords([$mine]);
});

test('an existing server can be edited', function () {
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    $server = TraccarServer::factory()->for($user)->create();
    $this->actingAs($user);

    livewire(EditTraccarServer::class, ['record' => $server->id])
        ->fillForm(['label' => 'Renamed'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(TraccarServer::class, ['id' => $server->id, 'label' => 'Renamed']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/TraccarServerResourceTest.php --compact"
```

Expected: FAIL — none of the resource classes exist yet.

- [ ] **Step 3: Create the Resource**

```php
<?php

namespace App\Filament\Dashboard\Resources\TraccarServers;

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\CreateTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\EditTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\ListTraccarServers;
use App\Filament\Dashboard\Resources\TraccarServers\Schemas\TraccarServerForm;
use App\Filament\Dashboard\Resources\TraccarServers\Tables\TraccarServersTable;
use App\Models\TraccarServer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TraccarServerResource extends Resource
{
    protected static ?string $model = TraccarServer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Tools';

    protected static ?string $navigationLabel = 'Traccar Servers';

    protected static ?string $modelLabel = 'Traccar server';

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
        return TraccarServerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TraccarServersTable::configure($table);
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
            'index' => ListTraccarServers::route('/'),
            'create' => CreateTraccarServer::route('/create'),
            'edit' => EditTraccarServer::route('/{record}/edit'),
        ];
    }
}
```

- [ ] **Step 4: Create the Form**

```php
<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class TraccarServerForm
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
            TextInput::make('label')
                ->label('Name this server')
                ->placeholder('My Traccar Server')
                ->required()
                ->maxLength(255),
            TextInput::make('base_url')
                ->label('Server URL')
                ->url()
                ->placeholder('https://demo.traccar.org')
                ->required()
                ->maxLength(255),
            TextInput::make('api_token')
                ->label('API token')
                ->password()
                ->revealable()
                ->required()
                ->maxLength(1000)
                ->helperText('A long-lived user token generated from your Traccar server (Settings → your account → Token, available since Traccar 5.3).'),
        ];
    }
}
```

- [ ] **Step 5: Create the Table**

```php
<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TraccarServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('base_url')
                    ->label('Server URL')
                    ->searchable(),
                TextColumn::make('last_polled_at')
                    ->label('Last polled')
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('last_poll_error')
                    ->label('Status')
                    ->formatStateUsing(fn (?string $state): string => $state ?? 'OK')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'success' : 'danger'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

- [ ] **Step 6: Create the Pages**

`app/Filament/Dashboard/Resources/TraccarServers/Pages/ListTraccarServers.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Pages;

use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTraccarServers extends ListRecords
{
    protected static string $resource = TraccarServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->disabled(fn (): bool => ! auth()->user()->canCreateTraccarServer())
                ->tooltip(fn (): ?string => auth()->user()->canCreateTraccarServer()
                    ? null
                    : 'You\'ve reached your plan\'s Traccar server limit — upgrade to add more.'),
        ];
    }
}
```

`app/Filament/Dashboard/Resources/TraccarServers/Pages/CreateTraccarServer.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Pages;

use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTraccarServer extends CreateRecord
{
    protected static string $resource = TraccarServerResource::class;

    protected function beforeCreate(): void
    {
        if (auth()->user()->canCreateTraccarServer()) {
            return;
        }

        Notification::make()
            ->title('Traccar server limit reached')
            ->body('Your plan allows '.auth()->user()->traccarServerLimit().' Traccar server(s). Upgrade to add more.')
            ->danger()
            ->send();

        $this->halt();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
```

`app/Filament/Dashboard/Resources/TraccarServers/Pages/EditTraccarServer.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Pages;

use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTraccarServer extends EditRecord
{
    protected static string $resource = TraccarServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/TraccarServerResourceTest.php --compact"
```

Expected: PASS (5 tests).

- [ ] **Step 8: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Dashboard/Resources/TraccarServers tests/Feature/TraccarServerResourceTest.php
git commit -m "Add TraccarServerResource with tier-limited CRUD"
```

---

## Task 10: `LocationForm` mobile branch, table column, device-limit gating

**Files:**
- Modify: `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php`
- Modify: `app/Filament/Dashboard/Resources/Locations/Tables/LocationsTable.php`
- Modify: `app/Filament/Dashboard/Resources/Locations/Pages/CreateLocation.php`
- Test: `tests/Feature/LocationResourceTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/LocationResourceTest.php` (check its top imports `CreateLocation`, `Location`, `User`, `livewire`, `assertDatabaseHas` already — reuse those):

```php
test('creating a mobile location requires a Traccar server and device', function () {
    $user = User::factory()->create(['tier_override' => \App\Enums\Tier::Plus]);
    $server = \App\Models\TraccarServer::factory()->for($user)->create(['base_url' => 'https://traccar.example.com']);
    $this->actingAs($user);

    // The device Select's options() calls ListTraccarDevices live -- fake
    // the HTTP layer so this test never makes a real network request
    // (this suite has no global Http::preventStrayRequests(), so an
    // unfaked call here would actually try to reach the network).
    \Illuminate\Support\Facades\Http::fake([
        'traccar.example.com/api/devices' => \Illuminate\Support\Facades\Http::response([
            ['id' => 7, 'name' => 'Truck', 'uniqueId' => 'abc'],
        ]),
    ]);

    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'My Truck',
            'type' => 'mobile',
            'mobile_source' => 'traccar',
            'traccar_server_id' => $server->id,
            'traccar_device_id' => 7,
            'traccar_device_name' => 'Truck',
            'mobile_update_interval_minutes' => 15,
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(Location::class, [
        'user_id' => $user->id,
        'type' => 'mobile',
        'traccar_server_id' => $server->id,
        'traccar_device_id' => 7,
    ]);

    $location = Location::where('user_id', $user->id)->where('type', 'mobile')->sole();
    expect($location->lat)->toBeNull();
    expect($location->lng)->toBeNull();
});

test('a Plus subscriber is blocked from attaching a 6th device to one server', function () {
    $user = User::factory()->create(['tier_override' => \App\Enums\Tier::Plus]);
    $server = \App\Models\TraccarServer::factory()->for($user)->create(['base_url' => 'https://traccar.example.com']);
    Location::factory()->count(5)->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    $this->actingAs($user);

    \Illuminate\Support\Facades\Http::fake([
        'traccar.example.com/api/devices' => \Illuminate\Support\Facades\Http::response([
            ['id' => 99, 'name' => 'Extra Device', 'uniqueId' => 'xyz'],
        ]),
    ]);

    livewire(CreateLocation::class)
        ->fillForm([
            'label' => 'One Too Many',
            'type' => 'mobile',
            'mobile_source' => 'traccar',
            'traccar_server_id' => $server->id,
            'traccar_device_id' => 99,
            'mobile_update_interval_minutes' => 15,
        ])
        ->call('create')
        ->assertNotified();

    expect(Location::where('traccar_server_id', $server->id)->count())->toBe(5);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/LocationResourceTest.php --compact"
```

Expected: FAIL — the form has no `traccar_server_id`/`traccar_device_id`/etc. fields yet, and `CreateLocation` has no device-limit check.

- [ ] **Step 3: Rewrite `LocationForm.php`**

Replace the full contents of `app/Filament/Dashboard/Resources/Locations/Schemas/LocationForm.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\Locations\Schemas;

use App\Actions\Traccar\ListTraccarDevices;
use App\Actions\Weather\ResolveTimezoneFromCoordinates;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use Fahiem\FilamentPinpoint\Pinpoint;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Exists;
use Tapp\FilamentTimezoneField\Forms\Components\TimezoneSelect;

class LocationForm
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
        $isStationary = fn (Get $get): bool => $get('type') === LocationType::Stationary->value;

        return [
            TextInput::make('label')
                ->label('Name this location')
                ->placeholder("Home, Work, Mom's house…")
                ->required()
                ->maxLength(255),
            Radio::make('type')
                ->label('Location type')
                ->options([
                    LocationType::Stationary->value => 'Stationary',
                    LocationType::Mobile->value => 'Mobile (tracked live)',
                ])
                ->default(LocationType::Stationary)
                ->required()
                ->live(),
            ...self::stationaryFields(),
            ...self::mobileFields(),
            TimezoneSelect::make('timezone')
                ->label('Timezone')
                ->searchable()
                ->getTimezoneFromBrowser()
                ->required()
                ->visible($isStationary)
                ->helperText('Defaults to your browser\'s detected timezone — change it if this location is somewhere else (e.g. a relative\'s house). Used for this location\'s quiet hours.'),
        ];
    }

    /**
     * @return array<Component>
     */
    private static function stationaryFields(): array
    {
        $visible = fn (Get $get): bool => $get('type') === LocationType::Stationary->value;

        return [
            Pinpoint::make('coordinates')
                ->label('Location')
                ->provider('leaflet')
                ->latField('lat')
                ->lngField('lng')
                ->addressField('address')
                ->dehydrated(false)
                ->visible($visible),
            Hidden::make('lat')
                ->live()
                ->afterStateUpdated(fn (Set $set, Get $get) => self::seedTimezoneFallback($set, $get))
                ->required($visible)
                ->visible($visible),
            Hidden::make('lng')
                ->live()
                ->afterStateUpdated(fn (Set $set, Get $get) => self::seedTimezoneFallback($set, $get))
                ->required($visible)
                ->visible($visible),
            TextInput::make('address')
                ->label('Resolved address')
                ->readOnly()
                ->visible($visible)
                ->helperText('Filled in automatically from the map above — search, drag the marker, or use your current location.'),
        ];
    }

    /**
     * @return array<Component>
     */
    private static function mobileFields(): array
    {
        $visible = fn (Get $get): bool => $get('type') === LocationType::Mobile->value;

        return [
            Hidden::make('mobile_source')
                ->default(MobileSource::Traccar)
                ->visible($visible),
            Select::make('traccar_server_id')
                ->label('Traccar server')
                ->options(fn (): array => auth()->user()->traccarServers()->pluck('label', 'id')->all())
                ->exists('traccar_servers', 'id', fn (Exists $rule): Exists => $rule->where('user_id', auth()->id()))
                ->required($visible)
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('traccar_device_id', null))
                ->visible($visible)
                ->helperText(fn (): ?string => auth()->user()->traccarServers()->exists()
                    ? null
                    : 'No Traccar servers yet — add one under Tools → Traccar Servers first.'),
            Select::make('traccar_device_id')
                ->label('Device')
                ->options(fn (Get $get): array => self::deviceOptions($get('traccar_server_id')))
                ->required($visible)
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    $devices = self::deviceOptions($get('traccar_server_id'));
                    $set('traccar_device_name', $devices[$get('traccar_device_id')] ?? null);
                })
                ->visible($visible)
                ->helperText(fn (Get $get): ?string => $get('traccar_server_id') !== null && self::deviceOptions($get('traccar_server_id')) === []
                    ? 'Couldn\'t reach this server, or it has no devices — check its URL and token under Tools → Traccar Servers.'
                    : null),
            Hidden::make('traccar_device_name')
                ->visible($visible),
            Select::make('mobile_update_interval_minutes')
                ->label('Update every')
                ->options([
                    5 => '5 minutes',
                    10 => '10 minutes',
                    15 => '15 minutes',
                    20 => '20 minutes',
                    25 => '25 minutes',
                    30 => '30 minutes',
                ])
                ->default(15)
                ->required($visible)
                ->visible($visible)
                ->helperText('Tighter intervals sharpen how current this location is, at the cost of more Traccar/device battery use.'),
            Hidden::make('lat')->visible($visible),
            Hidden::make('lng')->visible($visible),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function deviceOptions(?int $traccarServerId): array
    {
        if ($traccarServerId === null) {
            return [];
        }

        $server = auth()->user()->traccarServers()->find($traccarServerId);

        if ($server === null) {
            return [];
        }

        $devices = Cache::remember(
            "traccar-devices:{$server->id}",
            now()->addSeconds(30),
            fn (): ?array => app(ListTraccarDevices::class)($server),
        );

        if ($devices === null) {
            return [];
        }

        return collect($devices)->pluck('name', 'id')->all();
    }

    /**
     * Fallback for when the browser-JS auto-detect on the timezone field
     * (TimezoneSelect::getTimezoneFromBrowser()) hasn't run — e.g. JS
     * disabled or blocked — leaving the required field blank. Derives a
     * best-guess timezone from the coordinates the subscriber actually
     * placed on the map instead, via ResolveTimezoneFromCoordinates. Only
     * fires when the field is still blank, so it never overwrites a value
     * the browser or the subscriber already set.
     */
    private static function seedTimezoneFallback(Set $set, Get $get): void
    {
        if (! blank($get('timezone'))) {
            return;
        }

        $lat = $get('lat');
        $lng = $get('lng');

        if (blank($lat) || blank($lng)) {
            return;
        }

        $timezone = app(ResolveTimezoneFromCoordinates::class)((float) $lat, (float) $lng);

        if ($timezone !== null) {
            $set('timezone', $timezone);
        }
    }
}
```

- [ ] **Step 4: Add the "Position updated" column to `LocationsTable.php`**

In `app/Filament/Dashboard/Resources/Locations/Tables/LocationsTable.php`, add this column to the `->columns([...])` array, right after the `type` column:

```php
                TextColumn::make('mobile_position_updated_at')
                    ->label('Position updated')
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
```

- [ ] **Step 5: Add device-limit gating to `CreateLocation.php`**

Replace the full contents of `app/Filament/Dashboard/Resources/Locations/Pages/CreateLocation.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\Locations\Pages;

use App\Enums\LocationType;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    protected function beforeCreate(): void
    {
        if (! auth()->user()->canCreateLocation()) {
            Notification::make()
                ->title('Location limit reached')
                ->body('Your plan allows '.auth()->user()->locationLimit().' location(s). Upgrade to add more.')
                ->danger()
                ->send();

            $this->halt();

            return;
        }

        $data = $this->form->getState();

        if (($data['type'] ?? null) !== LocationType::Mobile->value) {
            return;
        }

        if (auth()->user()->canAddTraccarDevice((int) $data['traccar_server_id'])) {
            return;
        }

        Notification::make()
            ->title('Device limit reached')
            ->body('Your plan allows '.auth()->user()->traccarDeviceLimitPerServer().' device(s) per Traccar server. Upgrade to add more.')
            ->danger()
            ->send();

        $this->halt();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/LocationResourceTest.php --compact"
```

Expected: PASS, every test in the file (existing stationary-location tests included — verify none regressed).

- [ ] **Step 7: Run Pint and commit**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add app/Filament/Dashboard/Resources/Locations tests/Feature/LocationResourceTest.php
git commit -m "Add mobile-location support to LocationForm, LocationsTable, CreateLocation"
```

---

## Task 11: Full suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the complete test suite**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"
```

Expected: 100% pass, no regressions anywhere in the suite (not just the files touched by this feature).

- [ ] **Step 2: Run Pint across the whole diff**

```bash
docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
```

Expected: `{"tool":"pint","result":"passed"}` with no further changes needed (each task already ran Pint on its own files, so this is a final sweep).

- [ ] **Step 3: Manual browser verification**

Open `/dashboard/locations` as a Plus/Pro test user (create one via tinker if needed, matching this session's established browser-verification pattern): confirm the Stationary/Mobile radio toggles the form correctly, that selecting a Traccar server populates the device dropdown (or shows the "couldn't reach" message for a fake server), and that `/dashboard/traccar-servers` is reachable for Plus/Pro and absent from the nav for a Free test user.

- [ ] **Step 4: Final commit (if step 3 surfaced any fixes)**

```bash
git add -A
git commit -m "Fix issues found during manual verification of Traccar Servers"
```

(Skip this step entirely if step 3 found nothing to fix.)
