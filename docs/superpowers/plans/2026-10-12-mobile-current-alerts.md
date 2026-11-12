# Mobile Current Alerts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Current watches, warnings and advisories" section above the sent alerts on the mobile Alerts screen: the warnings, watches and advisories in effect at the subscriber's places (saved places and the phone), each with the places it affects and when it ends, tapping one opens its product page.

**Architecture:** A new read-only endpoint `GET /api/v1/active-alerts` backed by a `GetActiveAlertsForUser` action that reuses `GetActiveAlertsForLocation::activeFor()` per located place (up to 25) and merges the results per event. The app's `AlertsScreen` fetches it next to the feed, independently, and renders it as the first native `list-section` of the existing list (the sent alerts become the second section); the last response is cached for offline use.

**Tech Stack:** Laravel 12 / Pest / PostgreSQL+PostGIS (backend, repo root); NativePHP v4 SuperNative (app, `mobile/`).

**Spec:** `docs/superpowers/specs/2026-10-12-mobile-current-alerts-design.md`

## Global Constraints

- Read `.ai/rules/index.md` and every rule file whose globs match before editing a path (`app/Actions/**` → `.ai/rules/actions.md`; `routes/api.php`, `app/Http/Controllers/Api/**`, `app/Http/Resources/**` → `.ai/rules/api.md`; `tests/Feature/**` → `.ai/rules/feature.md`; `app/Models/**` → `.ai/rules/models.md`). Also read `mobile/CLAUDE.md` for app work.
- Events are read ONLY through `GetActiveAlertsForLocation::activeFor()` (zone rows, `active_wwa_areas`), never an event's own `ugc_codes` or polygon (`.ai/rules/pipeline.md`).
- Run Pint on the files you changed, in the directory that owns them (repo root for the backend, `mobile/` for the app): `vendor/bin/pint --format agent <files>`.
- Backend tests run in the app container, never on the host (the host cannot resolve the `pgsql` host): `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact <file>'`. If the container is not running, stop and report. App tests run on the host: `cd mobile && vendor/bin/pest --compact <file>`.
- Never run `native:run`, `native:jump` or `native:watch`. Never push. Commit directly to `main` with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- The endpoint returns public event data only: never zone codes, polygons, coordinates, bulletin text or another user's places.
- Families shown: `warning`, `watch`, `advisory` only; test products (`is_test`) never.
- App views: `native.<kebab-class>`; bare tags (`list`, `list-section`, `list-item`, `pressable`) because the stale `native:validate` does not know them; handler arguments through `{{ }}` (`@tap="openActive({{ $id }})"`); theme tokens only. `Http::fake()` stubs stack and the first match wins: never fake the same URL twice in one test. Pest global helper names must be unique across test files. Tests with time-relative fixtures need `freezeTime()`.

## Review Focus

- An event covering two of the subscriber's places is listed once with both labels, and its end is the latest across the places (none if any place has none): Task 1 tests.
- A subscriber with no located place gets `has_places: false` and a distinct message, not "Nothing in effect": Task 1 and Task 3 tests.
- An event whose end passed while the phone was offline is never shown from the saved copy: Task 3 test.
- A failed section load must not break the sent-alerts feed, and a failed feed must not hide the section: Task 3 tests.
- Test products, statements and SPC/WPC products never appear: Task 1 tests.
- Existing Alerts screen tests all fetch the new endpoint now and must keep passing with a default fake: Task 3.

---

### Task 1: Backend — `GET /api/v1/active-alerts`

**Files:**
- Create: `app/Actions/Alerts/GetActiveAlertsForUser.php`
- Create: `app/Http/Controllers/Api/V1/ActiveAlertController.php`
- Create: `app/Http/Resources/Api/V1/ActiveAlertResource.php`
- Modify: `routes/api.php`
- Modify: `.ai/rules/api.md`, `.ai/rules/actions.md`
- Test: `tests/Feature/ApiActiveAlertsTest.php`

**Interfaces:**
- Produces: `GET /api/v1/active-alerts` → `{data: [{id, event, family, places: [string], expires: ?string, view_url: ?string}], meta: {has_places: bool, generated_at: string}}`; `GetActiveAlertsForUser::__invoke(User $user): array{events: list<array{id: int, event: string, family: string, places: list<string>, expires: ?string, view_url: ?string}>, has_places: bool, generated_at: string}`.
- Consumes: `GetActiveAlertsForLocation::activeFor(Location $location): Collection<ActiveWwa>` (loaded `product`, per-location `expires`), `GetActiveAlertsForLocation::sorted()`, `ActiveWwa::eventName()/eventFamily()/latestIngestedProduct`, `IngestedProduct::viewUrl()`.

- [ ] **Step 1: Read the rule files** named in Global Constraints for the paths above, and `tests/Feature/GetActiveAlertsForLocationTest.php` and `tests/Feature/GetActiveNowAtMyLocationsTest.php` (fixtures: a located `Location` with `forecast_zone_ugc`/`county_ugc` set so no zone lookup runs, `ActiveWwa::factory()` with `ugc_codes` and no area rows is the "own columns" path, `ActiveWwaArea::factory()->for($wwa)` rows are the zone-row path).

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/ApiActiveAlertsTest.php`:

```php
<?php

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;

    $this->place = fn (string $label, string $zone, array $overrides = []) => Location::factory()->for($this->user)->create([
        'label' => $label, 'lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => $zone, 'county_ugc' => 'COC999',
        ...$overrides,
    ]);

    $this->event = fn (string $name, string $family, string $zone, array $attributes = []) => ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => $name, 'family' => $family])->id,
        'ugc_codes' => [$zone],
        'expires' => now()->addHours(2),
        ...$attributes,
    ]);

    $this->call = function (?string $token = null) {
        app('auth')->forgetGuards();

        return $this->withToken($token ?? $this->token)->getJson('/api/v1/active-alerts');
    };
});

test('it needs an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/active-alerts')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    ($this->call)($beacon)->assertForbidden();
});

test('a subscriber with no located place gets no events and has_places false', function () {
    Location::factory()->for($this->user)->create(['label' => 'Nowhere', 'lat' => null, 'lng' => null]);

    ($this->call)()->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.has_places', false)
        ->assertJsonStructure(['meta' => ['generated_at']]);
});

test('a subscriber with places but nothing in effect gets an empty list and has_places true', function () {
    ($this->place)('Home', 'COZ040');

    ($this->call)()->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.has_places', true);
});

test('an event in effect at a place is listed with its name, family, place and end', function () {
    ($this->place)('Home', 'COZ040');
    $tornado = ($this->event)('Tornado Warning', 'warning', 'COZ040');

    $response = ($this->call)()->assertOk();

    $response->assertJsonPath('data.0.id', $tornado->id)
        ->assertJsonPath('data.0.event', 'Tornado Warning')
        ->assertJsonPath('data.0.family', 'warning')
        ->assertJsonPath('data.0.places', ['Home'])
        ->assertJsonPath('data.0.view_url', null);

    expect($response->json('data.0.expires'))->toBeString();
});

test('an event at two places is listed once with both labels, sorted', function () {
    ($this->place)('Home', 'COZ040');
    ($this->place)('Cabin', 'COZ040');
    ($this->event)('Flood Watch', 'watch', 'COZ040');

    $response = ($this->call)()->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.places'))->toBe(['Cabin', 'Home']);
});

test('the end is the latest across the places, and none when any place has none', function () {
    ($this->place)('Home', 'COZ040');
    ($this->place)('Cabin', 'COZ041');

    $later = ($this->event)('Flood Watch', 'watch', 'COZ099', ['expires' => now()->addHours(1)]);
    ActiveWwaArea::factory()->for($later)->create(['ugc_code' => 'COZ040', 'expires' => now()->addHours(1)]);
    ActiveWwaArea::factory()->for($later)->create(['ugc_code' => 'COZ041', 'expires' => now()->addHours(5)]);

    $open = ($this->event)('Wind Advisory', 'advisory', 'COZ099', ['expires' => null]);
    ActiveWwaArea::factory()->for($open)->create(['ugc_code' => 'COZ040', 'expires' => now()->addHours(1)]);
    ActiveWwaArea::factory()->for($open)->create(['ugc_code' => 'COZ041', 'expires' => null]);

    $rows = collect(($this->call)()->assertOk()->json('data'))->keyBy('event');

    expect(strtotime($rows['Flood Watch']['expires']))->toBe(now()->addHours(5)->getTimestamp())
        ->and($rows['Flood Watch']['places'])->toBe(['Cabin', 'Home'])
        ->and($rows['Wind Advisory']['expires'])->toBeNull();
});

test('only warnings, watches and advisories are listed, and never a test product, an expired or a cancelled event', function () {
    ($this->place)('Home', 'COZ040');
    ($this->event)('Tornado Warning', 'warning', 'COZ040');
    ($this->event)('Severe Weather Statement', 'statement', 'COZ040');
    ($this->event)('SPC Outlook', 'spc_wpc', 'COZ040');
    ($this->event)('Test Warning', 'warning', 'COZ040', ['is_test' => true]);
    ($this->event)('Expired Warning', 'warning', 'COZ040', ['expires' => now()->subMinute()]);
    ($this->event)('Cancelled Warning', 'warning', 'COZ040', ['status' => 'Cancelled']);
    ($this->event)('Other Place Warning', 'warning', 'COZ777');

    expect(collect(($this->call)()->assertOk()->json('data'))->pluck('event')->all())->toBe(['Tornado Warning']);
});

test('events are ordered warning, watch, advisory, then by soonest end, then id', function () {
    ($this->place)('Home', 'COZ040');
    ($this->event)('Wind Advisory', 'advisory', 'COZ040', ['expires' => now()->addHour()]);
    ($this->event)('Flood Watch', 'watch', 'COZ040', ['expires' => now()->addHours(3)]);
    ($this->event)('Flood Warning', 'warning', 'COZ040', ['expires' => now()->addHours(4)]);
    ($this->event)('Tornado Warning', 'warning', 'COZ040', ['expires' => now()->addHours(1)]);
    ($this->event)('Open Warning', 'warning', 'COZ040', ['expires' => null]);

    expect(collect(($this->call)()->assertOk()->json('data'))->pluck('event')->all())
        ->toBe(['Tornado Warning', 'Flood Warning', 'Open Warning', 'Flood Watch', 'Wind Advisory']);
});

test('only the first 25 located places are looked at, and another user\'s places never count', function () {
    foreach (range(1, 25) as $n) {
        ($this->place)("Place {$n}", 'COZ040');
    }
    ($this->place)('Place 26', 'COZ555');
    ($this->event)('Far Warning', 'warning', 'COZ555');

    $other = User::factory()->create();
    Location::factory()->for($other)->create(['label' => 'Theirs', 'lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ888', 'county_ugc' => 'COC999']);
    ($this->event)('Their Warning', 'warning', 'COZ888');

    expect(($this->call)()->assertOk()->json('data'))->toBe([]);
});

test('view_url is the latest bulletin page, and the payload carries nothing but the documented fields', function () {
    ($this->place)('Home', 'COZ040');
    $tornado = ($this->event)('Tornado Warning', 'warning', 'COZ040', ['headline' => 'SECRET-HEADLINE']);

    $bulletin = IngestedProduct::factory()->createQuietly(['view_slug' => 'kbou-tor-202610121500', 'text' => 'SECRET-BULLETIN-BODY']);
    $tornado->forceFill(['latest_ingested_product_id' => $bulletin->id])->save();

    $response = ($this->call)()->assertOk();

    expect($response->json('data.0.view_url'))->toBe($bulletin->fresh()->viewUrl())->not->toBeNull()
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'event', 'family', 'places', 'expires', 'view_url'])
        ->and($response->getContent())->not->toContain('SECRET-HEADLINE')->not->toContain('SECRET-BULLETIN-BODY')->not->toContain('COZ040');
});

test('the result is cached for 60 seconds per subscriber', function () {
    ($this->place)('Home', 'COZ040');
    ($this->event)('Tornado Warning', 'warning', 'COZ040');

    expect(($this->call)()->assertOk()->json('data'))->toHaveCount(1);

    ($this->event)('Flood Warning', 'warning', 'COZ040');

    expect(($this->call)()->assertOk()->json('data'))->toHaveCount(1);

    $this->travel(61)->seconds();

    expect(($this->call)()->assertOk()->json('data'))->toHaveCount(2);

    $other = User::factory()->create();
    expect(Cache::has("app-active-alerts:{$other->id}"))->toBeFalse();
});
```

- [ ] **Step 3: Run the tests and verify they fail** (404 on `/api/v1/active-alerts`), command as in Global Constraints with this file.

- [ ] **Step 4: Implement**

Create `app/Actions/Alerts/GetActiveAlertsForUser.php`:

```php
<?php

namespace App\Actions\Alerts;

use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

/**
 * The warnings, watches and advisories in effect at any of a subscriber's located places (the phone
 * included), one entry per event with the labels of the places it affects, for the app's Alerts
 * screen. Events come only from GetActiveAlertsForLocation::activeFor() (the zone rows), never an
 * event's own columns. Test products and every other family are left out. Looks at the first 25
 * located places; cached 60 seconds per subscriber.
 */
class GetActiveAlertsForUser
{
    private const MAX_LOCATIONS = 25;

    private const CACHE_SECONDS = 60;

    private const FAMILIES = ['warning', 'watch', 'advisory'];

    /**
     * @return array{events: list<array{id: int, event: string, family: string, places: list<string>, expires: ?string, view_url: ?string}>, has_places: bool, generated_at: string}
     */
    public function __invoke(User $user): array
    {
        return Cache::remember(
            "app-active-alerts:{$user->id}",
            now()->addSeconds(self::CACHE_SECONDS),
            fn (): array => $this->compute($user),
        );
    }

    /**
     * @return array{events: list<array<string, mixed>>, has_places: bool, generated_at: string}
     */
    private function compute(User $user): array
    {
        $locations = $user->locations()->whereNotNull('lat')->whereNotNull('lng')->orderBy('id')->limit(self::MAX_LOCATIONS)->get();

        if ($locations->isEmpty()) {
            return ['events' => [], 'has_places' => false, 'generated_at' => now()->toIso8601String()];
        }

        $lookup = app(GetActiveAlertsForLocation::class);

        /** @var array<int, array{wwa: ActiveWwa, places: list<string>, ends: list<?CarbonInterface>}> $grouped */
        $grouped = [];

        foreach ($locations as $location) {
            foreach ($lookup->activeFor($location) as $wwa) {
                if (! in_array($wwa->eventFamily(), self::FAMILIES, true)) {
                    continue;
                }

                $grouped[$wwa->id]['wwa'] ??= $wwa;
                $grouped[$wwa->id]['places'][] = (string) $location->label;
                $grouped[$wwa->id]['ends'][] = $wwa->expires;
            }
        }

        $wwas = new EloquentCollection(array_map(function (array $group): ActiveWwa {
            $group['wwa']->expires = in_array(null, $group['ends'], true) ? null : collect($group['ends'])->max();

            return $group['wwa'];
        }, array_values($grouped)));

        $wwas->load('latestIngestedProduct');

        $events = GetActiveAlertsForLocation::sorted($wwas)
            ->map(function (ActiveWwa $wwa) use ($grouped): array {
                $places = collect($grouped[$wwa->id]['places'])->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

                return [
                    'id' => $wwa->id,
                    'event' => $wwa->eventName(),
                    'family' => (string) $wwa->eventFamily(),
                    'places' => $places,
                    'expires' => $wwa->expires?->toIso8601String(),
                    'view_url' => $wwa->latestIngestedProduct?->viewUrl(),
                ];
            })
            ->values()
            ->all();

        return ['events' => $events, 'has_places' => true, 'generated_at' => now()->toIso8601String()];
    }
}
```

Create `app/Http/Resources/Api/V1/ActiveAlertResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One event in effect at the subscriber's places. Public fields only: never zones, polygons or bulletin text.
 *
 * @property array{id: int, event: string, family: string, places: list<string>, expires: ?string, view_url: ?string} $resource
 */
class ActiveAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'event' => $this->resource['event'],
            'family' => $this->resource['family'],
            'places' => $this->resource['places'],
            'expires' => $this->resource['expires'],
            'view_url' => $this->resource['view_url'],
        ];
    }
}
```

Create `app/Http/Controllers/Api/V1/ActiveAlertController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\GetActiveAlertsForUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ActiveAlertResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActiveAlertController extends Controller
{
    public function __invoke(Request $request, GetActiveAlertsForUser $active): AnonymousResourceCollection
    {
        $result = $active($request->user());

        return ActiveAlertResource::collection($result['events'])
            ->additional(['meta' => ['has_places' => $result['has_places'], 'generated_at' => $result['generated_at']]]);
    }
}
```

In `routes/api.php`, inside the app-token group beside `/map`, add `Route::get('/active-alerts', ActiveAlertController::class)->name('api.v1.active-alerts');` and `use App\Http\Controllers\Api\V1\ActiveAlertController;`.

Append to `.ai/rules/api.md`:

```markdown

## GET /active-alerts returns public event data for the caller's own places only
`GET /api/v1/active-alerts` lists the warnings, watches and advisories in effect at the caller's own located places (the phone included): `{id, event, family, places, expires, view_url}` per event plus `meta.has_places` and `meta.generated_at`. It takes no parameters, never returns zone codes, polygons, coordinates or bulletin text, never another user's places, test products or any other family, looks at the first 25 located places and caches 60 seconds per user. Events are read only through `GetActiveAlertsForLocation::activeFor()`.
```

Append to `.ai/rules/actions.md`:

```markdown

## GetActiveAlertsForUser reads events only through GetActiveAlertsForLocation::activeFor()
`App\Actions\Alerts\GetActiveAlertsForUser` merges the per-place results of `activeFor()` (the zone rows, `active_wwa_areas`) into one entry per event: the latest end across the affected places (none when any has none), the sorted place labels, and the latest bulletin's page. Never read an event's own `ugc_codes` or `polygon` here (`.ai/rules/pipeline.md`).
```

- [ ] **Step 5: Run the tests and verify they pass**, then run `tests/Feature/GetActiveAlertsForLocationTest.php` and `tests/Feature/GetActiveNowAtMyLocationsTest.php` (they must be unaffected). If a fixture detail makes a test fail (for example the cancelled status value or a missing zone), fix the smallest thing and say so in the report; do not weaken what a test asserts about the spec's behavior.

- [ ] **Step 6: Measure on the dev data.** In the app container run a `tinker --execute` that finds the user with the most located places (`DB::table('locations')->whereNotNull('lat')->select('user_id', DB::raw('count(*) c'))->groupBy('user_id')->orderByDesc('c')->first()`), then times `app(App\Actions\Alerts\GetActiveAlertsForUser::class)(User::find($id))` once cold (`Cache::flush()` first) and reports the place count, event count and milliseconds. If a cold call with 25 places takes over 800 ms, report it and propose (do not silently apply) a change such as one query using all places' coordinates. Record the numbers in the report.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --format agent app/Actions/Alerts/GetActiveAlertsForUser.php app/Http/Controllers/Api/V1/ActiveAlertController.php app/Http/Resources/Api/V1/ActiveAlertResource.php routes/api.php tests/Feature/ApiActiveAlertsTest.php
git add app routes tests .ai
git commit -m "API: GET /active-alerts lists the warnings, watches and advisories in effect at the subscriber's places

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: App — API client, saved copy and presenter

**Files (all under `mobile/`):**
- Modify: `app/Services/WxNotifyApi.php`
- Modify: `app/Support/LocalCache.php`
- Create: `app/Support/ActiveAlertsPresenter.php`
- Test: `tests/Feature/WxNotifyApiActiveAlertsTest.php`, `tests/Feature/LocalCacheActiveAlertsTest.php`, `tests/Feature/ActiveAlertsPresenterTest.php`

**Interfaces:**
- Produces: `WxNotifyApi::activeAlerts(): array{data: list<array>, meta: array{has_places: bool, generated_at: string}}`; `LocalCache::rememberActiveAlerts(array $body): void`, `LocalCache::activeAlerts(): ?array` (the last body, null until one was fetched), wiped by `wipe()`; `ActiveAlertsPresenter::FAMILY_LABELS` (`warning` → Warning, `watch` → Watch, `advisory` → Advisory), `overline(array $event): string`, `places(array $event): string`, `withoutEnded(array $events): array`, `visible(array $events, bool $expanded): array` (the first 5 unless expanded), `hiddenCount(array $events, bool $expanded): int`, `asOf(string $iso, ?string $timezone): string`.
- Consumes: `WxNotifyApi::send()/data()`, `AlertPresenter::absolute()/when()`, `Carbon`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/WxNotifyApiActiveAlertsTest.php`:

```php
<?php

use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => (new TokenStore)->put('9|secret'));

test('the active alerts are fetched from /api/v1/active-alerts and returned with their meta', function () {
    $body = ['data' => [['id' => 1, 'event' => 'Tornado Warning']], 'meta' => ['has_places' => true, 'generated_at' => '2026-10-12T14:14:00+00:00']];
    Http::fake(['wx.test/api/v1/active-alerts' => Http::response($body)]);

    expect(app(WxNotifyApi::class)->activeAlerts())->toBe($body);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://wx.test/api/v1/active-alerts' && $r->hasHeader('Authorization', 'Bearer 9|secret'));
});

test('a rejected token and a dead connection read as unauthenticated and offline', function () {
    Http::fake(['wx.test/api/v1/active-alerts' => Http::response([], 401)]);

    expect(fn () => app(WxNotifyApi::class)->activeAlerts())->toThrow(Unauthenticated::class);

    $this->network->connected = false;

    expect(fn () => app(WxNotifyApi::class)->activeAlerts())->toThrow(Offline::class);
});
```

`tests/Feature/LocalCacheActiveAlertsTest.php`:

```php
<?php

use App\Support\LocalCache;

test('the last active alerts response is kept and wiped on sign-out', function () {
    $cache = new LocalCache;

    expect($cache->activeAlerts())->toBeNull();

    $body = ['data' => [['id' => 1, 'event' => 'Tornado Warning', 'family' => 'warning', 'places' => ['Home'], 'expires' => null, 'view_url' => null]], 'meta' => ['has_places' => true, 'generated_at' => '2026-10-12T14:14:00+00:00']];
    $cache->rememberActiveAlerts($body);

    expect($cache->activeAlerts())->toBe($body);

    $cache->rememberActiveAlerts(['data' => [], 'meta' => ['has_places' => false, 'generated_at' => '2026-10-12T15:00:00+00:00']]);

    expect($cache->activeAlerts()['data'])->toBe([])->and($cache->activeAlerts()['meta']['has_places'])->toBeFalse();

    $cache->wipe();

    expect($cache->activeAlerts())->toBeNull();
});
```

`tests/Feature/ActiveAlertsPresenterTest.php`:

```php
<?php

use App\Support\ActiveAlertsPresenter;

function presenterEvent(array $overrides = []): array
{
    return ['id' => 1, 'event' => 'Tornado Warning', 'family' => 'warning', 'places' => ['Cabin', 'Home'], 'expires' => null, 'view_url' => null, ...$overrides];
}

beforeEach(fn () => $this->freezeTime());

test('the overline is the family and when the event ends, or that it has no end time', function () {
    expect(ActiveAlertsPresenter::overline(presenterEvent(['expires' => now()->addHours(3)->toIso8601String()])))->toBe('Warning · Ends 3 hours from now')
        ->and(ActiveAlertsPresenter::overline(presenterEvent(['family' => 'watch'])))->toBe('Watch · No end time given')
        ->and(ActiveAlertsPresenter::overline(presenterEvent(['family' => 'advisory', 'expires' => now()->addMinutes(30)->toIso8601String()])))->toStartWith('Advisory · Ends ');
});

test('the places read as a comma-separated line', function () {
    expect(ActiveAlertsPresenter::places(presenterEvent()))->toBe('Cabin, Home');
});

test('events whose end has passed are dropped, and events with no end stay', function () {
    $events = [
        presenterEvent(['id' => 1, 'expires' => now()->subMinute()->toIso8601String()]),
        presenterEvent(['id' => 2, 'expires' => now()->addMinute()->toIso8601String()]),
        presenterEvent(['id' => 3, 'expires' => null]),
    ];

    expect(array_column(ActiveAlertsPresenter::withoutEnded($events), 'id'))->toBe([2, 3]);
});

test('the first five show unless expanded, and the hidden count says how many are left', function () {
    $events = array_map(fn (int $id) => presenterEvent(['id' => $id]), range(1, 8));

    expect(array_column(ActiveAlertsPresenter::visible($events, false), 'id'))->toBe([1, 2, 3, 4, 5])
        ->and(ActiveAlertsPresenter::hiddenCount($events, false))->toBe(3)
        ->and(count(ActiveAlertsPresenter::visible($events, true)))->toBe(8)
        ->and(ActiveAlertsPresenter::hiddenCount($events, true))->toBe(0)
        ->and(ActiveAlertsPresenter::hiddenCount(array_slice($events, 0, 5), false))->toBe(0);
});

test('as-of reads in the subscriber timezone when known, else how long ago', function () {
    $iso = '2026-10-12T19:14:00+00:00';

    expect(ActiveAlertsPresenter::asOf($iso, 'America/Chicago'))->toBe('As of Oct 12, 2:14 PM CDT')
        ->and(ActiveAlertsPresenter::asOf(now()->subMinutes(5)->toIso8601String(), null))->toBe('As of 5 minutes ago');
});
```

- [ ] **Step 2: Run them and verify they fail** (`cd mobile && vendor/bin/pest --compact tests/Feature/WxNotifyApiActiveAlertsTest.php tests/Feature/LocalCacheActiveAlertsTest.php tests/Feature/ActiveAlertsPresenterTest.php`).

- [ ] **Step 3: Implement**

In `app/Services/WxNotifyApi.php`, after `map()` add:

```php
    /**
     * The warnings, watches and advisories in effect at the subscriber's places.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array{has_places: bool, generated_at: string}}
     */
    public function activeAlerts(): array
    {
        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/active-alerts'));

        $this->data($body);

        return $body;
    }
```

In `app/Support/LocalCache.php` add `private const ACTIVE_ALERTS = 'wxnotify.active_alerts';`, then before `wipe()`:

```php
    /**
     * The last "current watches, warnings and advisories" response, for the Alerts screen offline. Replaced on
     * every fetch; the screen drops events that have ended and says when it was fetched.
     *
     * @param  array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}  $body
     */
    public function rememberActiveAlerts(array $body): void
    {
        Cache::forever(self::ACTIVE_ALERTS, $body);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}|null null until it was fetched once
     */
    public function activeAlerts(): ?array
    {
        return Cache::get(self::ACTIVE_ALERTS);
    }
```
and `Cache::forget(self::ACTIVE_ALERTS);` in `wipe()`; mention it in the class docblock.

Create `app/Support/ActiveAlertsPresenter.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The text and slicing the Alerts screen's "current watches, warnings and advisories" section shows.
 */
class ActiveAlertsPresenter
{
    public const FAMILY_LABELS = ['warning' => 'Warning', 'watch' => 'Watch', 'advisory' => 'Advisory'];

    private const SHOWN = 5;

    /**
     * "Warning · Ends 3 hours from now", or "Watch · No end time given".
     *
     * @param  array<string, mixed>  $event
     */
    public static function overline(array $event): string
    {
        $family = self::FAMILY_LABELS[$event['family'] ?? ''] ?? ucfirst((string) ($event['family'] ?? 'Alert'));

        if (blank($event['expires'] ?? null)) {
            return "{$family} · No end time given";
        }

        return $family.' · Ends '.Carbon::parse($event['expires'])->diffForHumans();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function places(array $event): string
    {
        return implode(', ', $event['places'] ?? []);
    }

    /**
     * Events whose end has passed (a saved copy read later); events with no end stay.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public static function withoutEnded(array $events): array
    {
        return array_values(array_filter($events, fn (array $event): bool => blank($event['expires'] ?? null) || Carbon::parse($event['expires'])->isFuture()));
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public static function visible(array $events, bool $expanded): array
    {
        return $expanded ? $events : array_slice($events, 0, self::SHOWN);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public static function hiddenCount(array $events, bool $expanded): int
    {
        return $expanded ? 0 : max(0, count($events) - self::SHOWN);
    }

    /**
     * "As of Oct 12, 2:14 PM CDT" in the subscriber's timezone, else "As of 5 minutes ago".
     */
    public static function asOf(string $iso, ?string $timezone): string
    {
        return 'As of '.(AlertPresenter::absolute($iso, $timezone) ?? AlertPresenter::when($iso));
    }
}
```

- [ ] **Step 4: Run the three files plus `LocalCacheTest.php`; expected PASS.** If `diffForHumans` wording differs from the test's expected "Ends 3 hours from now", fix the test expectation to the real output, not the code.

- [ ] **Step 5: Pint and commit**

```bash
cd mobile && vendor/bin/pint --format agent app/Services/WxNotifyApi.php app/Support/LocalCache.php app/Support/ActiveAlertsPresenter.php tests/Feature/WxNotifyApiActiveAlertsTest.php tests/Feature/LocalCacheActiveAlertsTest.php tests/Feature/ActiveAlertsPresenterTest.php
git add -A . && git commit -m "App: active alerts API client, saved copy and presenter

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: App — the section on the Alerts screen

**Files (all under `mobile/`):**
- Modify: `app/NativeComponents/AlertsScreen.php`
- Modify: `resources/views/native/alerts-screen.blade.php`
- Modify: `tests/Feature/AlertsScreenTest.php` (default fake, structure assertions)
- Modify: `tests/Feature/ScreensRenderTest.php` (the alerts screen test fakes the new URL)
- Test: new tests appended to `tests/Feature/AlertsScreenTest.php`

**Interfaces:**
- Consumes: Task 2's `WxNotifyApi::activeAlerts()`, `LocalCache::rememberActiveAlerts()/activeAlerts()`, `ActiveAlertsPresenter`; the existing `refresh()`, `onResume()`, `mount()`, `open()`, `SignOut::clearLocal()`.
- Produces: the section inside the screen's `<list>`; `AlertsScreen` state `activeEvents` (array), `hasPlaces` (bool), `activeFetchedAt` (?string), `activeOffline` (bool), `activeError` (bool), `showAllActive` (bool), and methods `refreshActive()`, `toggleActive()`, `openActive(int $id)`, `activeRows()`, `activeHiddenCount()`, `activeNote(): ?string`, `activeEmptyLine(): ?string`.

**Behaviour (from the spec, binding):**
- The list becomes two `<list-section>`s (keep the existing `<list>` attributes and add `plain="true"`): header **"Current watches, warnings and advisories"**, then header **"Alerts sent to you"** holding the existing alert rows and the existing empty state ("No alerts yet", as a list item).
- Section one, in order: (a) an offline note row when `activeOffline`: `ActiveAlertsPresenter::asOf(...)` + ". You're offline." (fetch time from the saved copy's `generated_at`, subscriber timezone from `LocalCache::profile()['timezone']`), or with no saved copy "Current alerts need a connection."; (b) the event rows (`ActiveAlertsPresenter::visible()` of the events, with `withoutEnded()` applied when offline): overline `overline()`, headline the event name, supporting `places()`, `@tap="openActive({{ $id }})"` only when the event has a `view_url` (otherwise no tap); (c) when there are no events: the empty line, `"Nothing in effect at your places."`, or when `hasPlaces` is false `"Add a place or turn on location sharing to see what's in effect near you."`, or when the load failed with nothing to show `"Couldn't load current alerts."`; (d) a row `"Show all (N)"` when `activeHiddenCount() > 0`, or `"Show fewer"` when expanded and more than five, tapping calls `toggleActive`.
- Loading: `mount()` paints the saved copy (events, `hasPlaces`, fetched time) then `refresh()` runs; `refresh()` (pull-to-refresh, the stale refresh from `onResume()`, mount) also calls `refreshActive()` after the feed, independently: `refreshActive()` returns at once when there is no token; on success it caches the body and clears `activeOffline`/`activeError`; `Offline` sets `activeOffline` (keeping what is shown); `Unauthenticated` clears local data and replaces with `/sign-in`; any other `ApiException` sets `activeError` (keeping what is shown). `loadMore()` never touches the section. A failure of the feed does not stop `refreshActive()`, and a failure of the section does not touch the feed's state.
- `openActive($id)` opens the event's `view_url` with `Browser::inApp()`; an unknown id or a missing url does nothing.

- [ ] **Step 1: Read** `app/NativeComponents/AlertsScreen.php`, its view, `tests/Feature/AlertsScreenTest.php`, and `grep -rn "AlertsScreen::class" tests` (every test that mounts the screen).

- [ ] **Step 2: Keep the existing tests passing.** Every mount of `AlertsScreen` now requests `https://wx.test/api/v1/active-alerts`, and the test setup fails any request it did not fake. In `tests/Feature/AlertsScreenTest.php`'s `beforeEach` add a default that individual tests can change:

```php
    $this->activeAlerts = ['data' => [], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    $this->activeAlertsStatus = 200;
    Http::fake(['wx.test/api/v1/active-alerts' => fn () => Http::response($this->activeAlerts, $this->activeAlertsStatus)]);
```
(The first matching stub wins and later `Http::fake()` calls only add other URLs, so a test changes the section's response by assigning `$this->activeAlerts` and `$this->activeAlertsStatus`, never by faking the URL again. The stub reads them when the request is made, so assigning before `Native::test()` is enough.) Do the same in any other test file that mounts `AlertsScreen` (at least `ScreensRenderTest.php`'s alerts test). Update the tests whose structure assertions break because the list now holds two sections (for example the one asserting `count($list['children']) === 2`, the badge count, the empty-state text) so they assert the same behaviour against the new structure: find the rows with the existing `findRows()` helper. A request-count assertion (`Http::assertSentCount`) must account for the extra request.

- [ ] **Step 3: Write the failing new tests** (append to `tests/Feature/AlertsScreenTest.php`; add a local helper `activeEvent(int $id, array $overrides = [])` returning `['id' => $id, 'event' => "Tornado Warning {$id}", 'family' => 'warning', 'places' => ['Home'], 'expires' => now()->addHours(3)->toIso8601String(), 'view_url' => "https://wx.test/view-product/e{$id}", ...$overrides]`; `freezeTime()` where relative times are asserted):

```php
test('the section lists the events in effect with their end and places, above the sent alerts under their own headers', function () {
    $this->freezeTime();
    $this->activeAlerts = ['data' => [activeEvent(1), activeEvent(2, ['family' => 'watch', 'places' => ['Cabin', 'Home'], 'expires' => null])], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(7)]))]);

    Native::test(AlertsScreen::class)
        ->assertSee('Current watches, warnings and advisories')
        ->assertSee('Alerts sent to you')
        ->assertSee('Warning · Ends 3 hours from now')
        ->assertSee('Tornado Warning 1')
        ->assertSee('Watch · No end time given')
        ->assertSee('Cabin, Home')
        ->assertSee('Tornado Warning 7');
});

test('an event row opens its product page, and a row without a page has no tap', function () {
    $this->activeAlerts = ['data' => [activeEvent(1), activeEvent(2, ['view_url' => null])], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    $screen = Native::test(AlertsScreen::class);
    $screen->assertElement('list_item', fn (array $item) => $item['props']['headline'] === 'Tornado Warning 1' && isset($item['on_press']))
        ->assertElement('list_item', fn (array $item) => $item['props']['headline'] === 'Tornado Warning 2' && ! isset($item['on_press']));

    $screen->call('openActive', 1);
    $screen->call('openActive', 2);
    $screen->call('openActive', 99);

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/e1']);
});

test('only the first five show with Show all (N), and Show all reveals the rest and becomes Show fewer', function () {
    $this->activeAlerts = ['data' => array_map(fn (int $id) => activeEvent($id), range(1, 8)), 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 5')
        ->assertDontSee('Tornado Warning 6')
        ->assertSee('Show all (3)')
        ->call('toggleActive')
        ->assertSee('Tornado Warning 8')
        ->assertSee('Show fewer')
        ->call('toggleActive')
        ->assertDontSee('Tornado Warning 6');
});

test('nothing in effect says so, and no places asks for a place or location sharing instead', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertSee('Nothing in effect at your places.');

    $this->activeAlerts = ['data' => [], 'meta' => ['has_places' => false, 'generated_at' => now()->toIso8601String()]];

    Native::test(AlertsScreen::class)
        ->assertSee("Add a place or turn on location sharing to see what's in effect near you.")
        ->assertDontSee('Nothing in effect at your places.');
});

test('a successful load saves the response, and a later offline open paints the saved copy marked with when it was fetched', function () {
    $this->freezeTime();
    $this->activeAlerts = ['data' => [activeEvent(1)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class);

    expect((new LocalCache)->activeAlerts()['data'][0]['event'])->toBe('Tornado Warning 1');

    $this->network->connected = false;

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 1')
        ->assertSee("You're offline.")
        ->assertSee('As of');
});

test('offline, an event whose end has passed since the copy was saved is not shown', function () {
    $this->freezeTime();
    (new LocalCache)->rememberActiveAlerts(['data' => [activeEvent(1, ['expires' => now()->addMinutes(10)->toIso8601String()]), activeEvent(2)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]]);
    $this->network->connected = false;
    $this->travel(30)->minutes();

    Native::test(AlertsScreen::class)
        ->assertDontSee('Tornado Warning 1')
        ->assertSee('Tornado Warning 2');
});

test('offline with no saved copy the section says current alerts need a connection', function () {
    $this->network->connected = false;

    Native::test(AlertsScreen::class)->assertSee('Current alerts need a connection.');
});

test('a failing section load keeps the feed working and says it could not load', function () {
    $this->activeAlerts = ['message' => 'boom'];
    $this->activeAlertsStatus = 500;
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(7)]))]);

    Native::test(AlertsScreen::class)
        ->assertSee("Couldn't load current alerts.")
        ->assertSee('Tornado Warning 7')
        ->assertDontSee('could not load your alerts');
});

test('a failing feed keeps the section and its own message', function () {
    $this->activeAlerts = ['data' => [activeEvent(1)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(['message' => 'boom'], 500)]);

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 1')
        ->assertSee('WxNotify could not load your alerts. Pull down to try again.');
});

test('a rejected token on the section signs out', function () {
    $this->activeAlerts = [];
    $this->activeAlertsStatus = 401;
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertReplacedWith('/sign-in');
});
```
(Each failure test assigns `$this->activeAlertsStatus` before mounting, so the 500 and 401 really reach the screen.)

- [ ] **Step 4: Run them and verify they fail**, command: `cd mobile && vendor/bin/pest --compact tests/Feature/AlertsScreenTest.php`.

- [ ] **Step 5: Implement** the behaviour above in `AlertsScreen.php` and `alerts-screen.blade.php`. In the view: keep the file's first line comment (un-prefixed `list`/`list-section`/`list-item`), keep every existing `<list>` attribute and add `plain="true"`; wrap the section rows in `<list-section header="Current watches, warnings and advisories">…</list-section>` and the existing `@forelse` of alerts in `<list-section header="Alerts sent to you">…</list-section>`; the existing empty state ("No alerts yet" and its sentence) becomes a `list-item` (`headline`, `supporting`) inside the second section. Do not key the alert rows (keep the existing comment about LazyColumn anchoring). Follow the existing `open()` / `@tap="open({{ $alert['id'] }})"` handler style. If `list-section` inside `list` breaks pull-to-refresh or end-reached in a way a test shows, report it (do not work around it silently).

- [ ] **Step 6: Run** `vendor/bin/pest --compact tests/Feature/AlertsScreenTest.php tests/Feature/ScreensRenderTest.php`, then the full app suite and `php artisan native:validate`. All green.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --format agent app/NativeComponents/AlertsScreen.php tests/Feature/AlertsScreenTest.php tests/Feature/ScreensRenderTest.php
git add -A . && git commit -m "App: current watches, warnings and advisories section above the sent alerts

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Device check and notes (owner-assisted)

**Files:**
- Create: `<Claude project memory>/mobile-current-alerts-state.md` and its line in `MEMORY.md`.

- [ ] **Step 1:** Run the full backend suite in parallel in the app container (`docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`) and the full app suite; both green.

- [ ] **Step 2:** Ask the owner to push `main` so the dev host serves `/api/v1/active-alerts` (the controller does not push), and to run the app on the Android emulator and iOS simulator (hot reload picks up app changes). Drive both against the dev server and check: the section above the list with its header and the "Alerts sent to you" header; real events for a place the owner has; a row opening its product page; Show all; pull-to-refresh refreshing both; the section with no places/nothing in effect; offline (airplane mode on Android). Judge how the native section headers look (spacing, stickiness with `plain`) and whether pull-to-refresh and loading older alerts still work.

- [ ] **Step 3:** Write the memory note (what was built, the Task 1 timing, the device-check results and findings) and index it in `MEMORY.md`.
