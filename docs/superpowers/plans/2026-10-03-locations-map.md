# Locations Map Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Leaflet "Location map" card above the Locations table showing every location the subscriber owns (stationary red, mobile blue, stale mobile gray), with a click popup and an Edit link.

**Architecture:** First extract the Traccar map's reusable JS helpers into a shared Blade partial (behavior unchanged). Then add a database-only `GetLocationMap` action, a `LocationsMapWidget` mirroring `TraccarDevicesMapWidget`, a view that uses the shared helpers, and register it on the Locations list page.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament v5 widgets, Livewire 4, Leaflet 1.9.4 (already loaded by `filament.partials.leaflet-loader`), Pest.

**Spec:** `docs/superpowers/specs/2026-10-03-locations-map-design.md` (binding; read it first).

## Global Constraints

- **Database only**: the Locations map makes no Traccar or other external HTTP call; positions come from `locations.lat/lng/mobile_position_updated_at`. Scoped to `auth()->user()` only.
- **Colors**: stationary `#dc2626`, mobile `#2563eb`, stale `#9ca3af`. Stale = a mobile location whose `mobile_position_updated_at` is **null or older than** `GetTraccarDeviceMap::STALE_AFTER_MINUTES` (60) — the same rule the Traccar map uses (its `isStale` also treats a missing timestamp as stale). A stationary location is never stale.
- **Mobile without a fix** (`! hasFix()`) is excluded from markers and counted in `unplaced`; a stationary location with null coordinates is skipped without error and not counted.
- **Payload fields only**: `key, label, type, address, lat, lng, stale, updated_at, edit_url` per location, plus `unplaced`. No user id, Traccar ids/server, timezone. Order: by `label` then `id`.
- **XSS**: popups and captions are built with DOM nodes and `textContent` only (labels/addresses are subscriber strings). The only HTML is the pin SVG whose color comes from constants. The Edit `<a>` gets its `href` from the server-generated `edit_url` and a constant text.
- **Behavior**: renders for every tier and with zero locations (caption "Add a location to see it here."); refresh every 60 s; fit bounds on first render only with `maxZoom: 12`; never re-center afterwards.
- **The Traccar Servers map's behavior must be unchanged** after the helper extraction.
- Conventions: Pest feature tests; Pint (`vendor/bin/pint --dirty --format agent`) before each commit; commit directly to `main` (no push); commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Read `.ai/rules/index.md` and the rule files covering touched paths (`filament.md` covers `app/Filament/**` and `resources/views/filament/**`; `feature.md` for tests; `support.md`/`models.md` if relevant) before editing.

## How to run tests (read this once)

Run Pest inside the app container (the host cannot reach the DB):

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

Every "Run:" below means that command with the stated files. The full suite takes ~6 minutes and is run by the controller in the background at the end. Pint runs on the host. JS behavior cannot be exercised by PHP tests; the controller verifies it in a real browser afterwards (section at the end) — keep the view's JS straightforward and defensively coded.

## File Structure

| Path | Responsibility |
| --- | --- |
| `resources/views/filament/partials/map-helpers.blade.php` | shared `window.wxMapHelpers` (`pinIcon`, `relativeTime`, `formatTimestamp`), defined once per page |
| `resources/views/filament/dashboard/widgets/traccar-devices-map.blade.php` | uses the shared helpers (behavior unchanged) |
| `app/Actions/Locations/GetLocationMap.php` | payload builder |
| `app/Filament/Dashboard/Resources/Locations/Widgets/LocationsMapWidget.php` | the widget |
| `resources/views/filament/dashboard/widgets/locations-map.blade.php` | the map view |
| `app/Filament/Dashboard/Resources/Locations/LocationResource.php`, `Pages/ListLocations.php` | register the widget |
| `.ai/rules/filament.md` | "Locations map" rule |
| `tests/Feature/GetLocationMapTest.php`, `LocationsMapWidgetTest.php` (new); `TraccarDevicesMapWidgetTest.php` (small extension) | tests |

---

### Task 1: Extract the shared map helpers (Traccar map unchanged)

**Files:**
- Create: `resources/views/filament/partials/map-helpers.blade.php`
- Modify: `resources/views/filament/dashboard/widgets/traccar-devices-map.blade.php`
- Test: extend `tests/Feature/TraccarDevicesMapWidgetTest.php`

**Interfaces:**
- Produces (used by Task 2): `window.wxMapHelpers = { pinIcon(color), relativeTime(date), formatTimestamp(iso) }`, defined once per page by `@include('filament.partials.map-helpers')`.
  - `pinIcon(color)` → the `L.divIcon` pin SVG (identical markup to today's Traccar `pinIcon`); callable only after Leaflet is ready.
  - `relativeTime(date: Date)` → string via `Intl.RelativeTimeFormat(undefined, {numeric: 'auto'})` (seconds/minutes/hours/days), identical to today's.
  - `formatTimestamp(iso: ?string)` → `'unknown'` for null/invalid, else `"<medium date, short time> · <relative>"` — identical to today's `formatCheckin`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/TraccarDevicesMapWidgetTest.php`:

```php
test('the widget loads the shared map helpers and no longer defines its own', function () {
    livewire(TraccarDevicesMapWidget::class)
        ->assertSeeHtml('wxMapHelpers')
        ->assertDontSeeHtml('const formatCheckin')
        ->assertDontSeeHtml('const relativeTime')
        ->assertDontSeeHtml('const pinIcon');
});
```

(Existing tests in that file must keep passing unchanged.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/TraccarDevicesMapWidgetTest.php`
Expected: the new test FAILS (the view still defines its own helpers and does not mention `wxMapHelpers`); all other tests pass.

- [ ] **Step 3: The shared partial**

`resources/views/filament/partials/map-helpers.blade.php` — move the three helpers out of the Traccar view **verbatim in behavior**, guarded so a page with two maps defines them once (same pattern as `leaflet-loader.blade.php`):

```blade
<script>
    // Shared by every Leaflet map widget (Traccar devices, Locations). Defined once per page.
    // pinIcon() needs Leaflet (window.L): call it only inside window.whenLeafletReady callbacks.
    if (! window.wxMapHelpers) {
        window.wxMapHelpers = {
            // The only markup built as HTML: a pin whose colour comes from caller-side constants.
            pinIcon: function (color) {
                return L.divIcon({
                    className: '',
                    html: '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">'
                        + '<path d="M13 0C5.8 0 0 5.8 0 13c0 9.5 13 23 13 23s13-13.5 13-23C26 5.8 20.2 0 13 0z" fill="' + color + '" stroke="#ffffff" stroke-width="2"/>'
                        + '<circle cx="13" cy="13" r="5" fill="#ffffff"/></svg>',
                    iconSize: [26, 36],
                    iconAnchor: [13, 36],
                    popupAnchor: [0, -32],
                });
            },

            relativeTime: function (date) {
                const seconds = Math.round((date.getTime() - Date.now()) / 1000);
                const abs = Math.abs(seconds);
                const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

                if (abs < 60) { return rtf.format(seconds, 'second'); }
                if (abs < 3600) { return rtf.format(Math.round(seconds / 60), 'minute'); }
                if (abs < 86400) { return rtf.format(Math.round(seconds / 3600), 'hour'); }

                return rtf.format(Math.round(seconds / 86400), 'day');
            },

            formatTimestamp: function (iso) {
                if (! iso) { return 'unknown'; }

                const date = new Date(iso);

                if (isNaN(date.getTime())) { return 'unknown'; }

                const absolute = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);

                return absolute + ' · ' + window.wxMapHelpers.relativeTime(date);
            },
        };
    }
</script>
```

- [ ] **Step 4: Switch the Traccar view to the shared helpers**

In `traccar-devices-map.blade.php`: add `@include('filament.partials.map-helpers')` next to the existing `@include('filament.partials.leaflet-loader')`; delete the view's own `pinIcon`, `relativeTime` and `formatCheckin` definitions; replace their uses with `window.wxMapHelpers.pinIcon(...)` and `window.wxMapHelpers.formatTimestamp(...)` (`relativeTime` is only used inside `formatTimestamp`). Change nothing else — colors, popup, caption, refresh, `fitBounds`, destroy logic stay exactly as they are.

- [ ] **Step 5: Run to verify it passes**

Run: `tests/Feature/TraccarDevicesMapWidgetTest.php tests/Feature/GetTraccarDeviceMapTest.php`
Expected: all PASS.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/filament/partials/map-helpers.blade.php resources/views/filament/dashboard/widgets/traccar-devices-map.blade.php tests/Feature/TraccarDevicesMapWidgetTest.php
git commit -m "Extract the shared map JavaScript helpers used by the Traccar map

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The Locations map

**Files:**
- Create: `app/Actions/Locations/GetLocationMap.php`, `app/Filament/Dashboard/Resources/Locations/Widgets/LocationsMapWidget.php`, `resources/views/filament/dashboard/widgets/locations-map.blade.php`
- Modify: `app/Filament/Dashboard/Resources/Locations/LocationResource.php`, `app/Filament/Dashboard/Resources/Locations/Pages/ListLocations.php`, `.ai/rules/filament.md`
- Test: `tests/Feature/GetLocationMapTest.php`, `tests/Feature/LocationsMapWidgetTest.php`

**Interfaces:**
- Consumes: `window.wxMapHelpers` (Task 1), `window.whenLeafletReady` (existing), `GetTraccarDeviceMap::STALE_AFTER_MINUTES`, `Location::hasFix()`, `LocationType`.
- Produces: `GetLocationMap::__invoke(iterable $locations): array{locations: list<array{key: string, label: string, type: string, address: ?string, lat: float, lng: float, stale: bool, updated_at: ?string, edit_url: string}>, unplaced: int}`; `LocationsMapWidget::locations(): array` (same shape); the widget on `ListLocations`.

Read `.ai/rules/filament.md` before editing anything under `app/Filament` or `resources/views/filament`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/GetLocationMapTest.php`:

```php
<?php

use App\Actions\Locations\GetLocationMap;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    Carbon::setTestNow('2026-10-03 12:00:00');
    $this->user = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a stationary location is placed with its address, never stale, and an edit link', function () {
    $location = Location::factory()->for($this->user)->create([
        'label' => 'Home', 'address' => '1 Main St', 'lat' => 40.0, 'lng' => -105.0,
    ]);

    $result = (new GetLocationMap)([$location]);

    expect($result['unplaced'])->toBe(0)
        ->and($result['locations'])->toHaveCount(1)
        ->and($result['locations'][0])->toMatchArray([
            'key' => (string) $location->id,
            'label' => 'Home',
            'type' => 'stationary',
            'address' => '1 Main St',
            'lat' => 40.0,
            'lng' => -105.0,
            'stale' => false,
            'updated_at' => null,
        ])
        ->and($result['locations'][0]['edit_url'])->toEndWith("/locations/{$location->id}/edit");
});

test('a mobile location is fresh within the hour and stale beyond it or without a timestamp', function () {
    $fresh = Location::factory()->for($this->user)->mobile()->create(['label' => 'A fresh', 'lat' => 40.0, 'lng' => -105.0, 'mobile_position_updated_at' => now()->subMinutes(59)]);
    $stale = Location::factory()->for($this->user)->mobile()->create(['label' => 'B stale', 'lat' => 41.0, 'lng' => -106.0, 'mobile_position_updated_at' => now()->subMinutes(61)]);
    $unknown = Location::factory()->for($this->user)->mobile()->create(['label' => 'C unknown', 'lat' => 42.0, 'lng' => -107.0, 'mobile_position_updated_at' => null]);

    $result = (new GetLocationMap)([$fresh, $stale, $unknown]);

    expect(collect($result['locations'])->pluck('stale', 'label')->all())
        ->toBe(['A fresh' => false, 'B stale' => true, 'C unknown' => true])
        ->and($result['locations'][0]['type'])->toBe('mobile')
        ->and($result['locations'][0]['address'])->toBeNull()
        ->and($result['locations'][0]['updated_at'])->toBe(now()->subMinutes(59)->toIso8601String());
});

test('a mobile location with no position yet is left off the map and counted', function () {
    $mobileNoFix = Location::factory()->for($this->user)->mobile()->create(['label' => 'Truck']);
    $placed = Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 40.0, 'lng' => -105.0]);

    $result = (new GetLocationMap)([$mobileNoFix, $placed]);

    expect(collect($result['locations'])->pluck('label')->all())->toBe(['Home'])
        ->and($result['unplaced'])->toBe(1);
});

test('a stationary location with null coordinates is skipped without error and not counted', function () {
    $broken = Location::factory()->for($this->user)->create(['label' => 'Broken', 'lat' => null, 'lng' => null]);

    expect((new GetLocationMap)([$broken]))->toBe(['locations' => [], 'unplaced' => 0]);
});

test('locations are ordered by label then id', function () {
    $b2 = Location::factory()->for($this->user)->create(['label' => 'B', 'lat' => 1.0, 'lng' => 1.0]);
    $a = Location::factory()->for($this->user)->create(['label' => 'A', 'lat' => 2.0, 'lng' => 2.0]);
    $b1 = Location::factory()->for($this->user)->create(['label' => 'B', 'lat' => 3.0, 'lng' => 3.0]);

    $result = (new GetLocationMap)([$b2, $a, $b1]);

    expect(collect($result['locations'])->pluck('key')->all())->toBe([(string) $a->id, (string) $b2->id, (string) $b1->id]);
});

test('the payload exposes nothing beyond the documented fields', function () {
    $location = Location::factory()->for($this->user)->mobile()->create(['lat' => 40.0, 'lng' => -105.0, 'mobile_position_updated_at' => now(), 'timezone' => 'America/Denver']);

    $entry = (new GetLocationMap)([$location])['locations'][0];

    expect(array_keys($entry))->toBe(['key', 'label', 'type', 'address', 'lat', 'lng', 'stale', 'updated_at', 'edit_url']);
});

test('an empty list yields an empty result', function () {
    expect((new GetLocationMap)([]))->toBe(['locations' => [], 'unplaced' => 0]);
});
```

`tests/Feature/LocationsMapWidgetTest.php`:

```php
<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\Locations\Pages\ListLocations;
use App\Filament\Dashboard\Resources\Locations\Widgets\LocationsMapWidget;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->user = User::factory()->create(['tier_override' => Tier::Free]);
    $this->actingAs($this->user);
});

test('the widget returns only the signed-in subscriber\'s locations', function () {
    Location::factory()->for($this->user)->create(['label' => 'Mine', 'lat' => 40.0, 'lng' => -105.0]);
    Location::factory()->create(['label' => 'Theirs', 'lat' => 41.0, 'lng' => -106.0]);

    $data = livewire(LocationsMapWidget::class)->instance()->locations();

    expect(collect($data['locations'])->pluck('label')->all())->toBe(['Mine']);
});

test('with no locations the widget returns an empty payload', function () {
    expect(livewire(LocationsMapWidget::class)->instance()->locations())->toBe(['locations' => [], 'unplaced' => 0]);
});

test('every tier can view the widget', function () {
    foreach ([Tier::Free, Tier::Plus, Tier::Pro] as $tier) {
        $this->actingAs(User::factory()->create(['tier_override' => $tier]));

        expect(LocationsMapWidget::canView())->toBeTrue();
    }
});

test('the widget renders a map container that Livewire leaves alone and no external call is made', function () {
    Location::factory()->for($this->user)->mobile()->create();
    \Illuminate\Support\Facades\Http::fake();

    livewire(LocationsMapWidget::class)
        ->assertSeeHtml('data-locations-map')
        ->assertSeeHtml('data-locations-caption')
        ->assertSeeHtml('wire:ignore')
        ->assertSeeHtml('wxMapHelpers');

    \Illuminate\Support\Facades\Http::assertNothingSent();
});

test('the Locations list page shows the map, with locations and with none', function () {
    livewire(ListLocations::class)->assertSeeLivewire(LocationsMapWidget::class);

    Location::factory()->for($this->user)->create();

    livewire(ListLocations::class)->assertSeeLivewire(LocationsMapWidget::class);
});

test('the widget payload never contains traccar identifiers or the owner', function () {
    Location::factory()->for($this->user)->mobile()->create(['lat' => 40.0, 'lng' => -105.0, 'mobile_position_updated_at' => now()]);

    $json = json_encode(livewire(LocationsMapWidget::class)->instance()->locations());

    expect($json)->not->toContain('traccar')->not->toContain('user_id');
});
```

(Move the two `Http` facade references into a `use Illuminate\Support\Facades\Http;` import. `Tier::Pro` and `tier_override` exist — see `TraccarDevicesMapWidgetTest.php`. If `->mobile()->create()` needs a `traccar_server_id` the factory already supplies one.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/GetLocationMapTest.php tests/Feature/LocationsMapWidgetTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: The action**

`app/Actions/Locations/GetLocationMap.php`:

```php
<?php

namespace App\Actions\Locations;

use App\Actions\Traccar\GetTraccarDeviceMap;
use App\Enums\LocationType;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Models\Location;

/**
 * The data behind the Locations page's map: one entry per location with a
 * known position, from the database only (mobile positions are already stored
 * by the Traccar poller, so no external call is made). A mobile location with
 * no fix yet is left off the map and counted in `unplaced`. A mobile location
 * is stale when its last position update is missing or older than the same
 * threshold the Traccar devices map uses; a stationary one is never stale.
 *
 * Only the fields the map needs leave this class -- no owner id, Traccar
 * server/device identifiers or timezone.
 */
class GetLocationMap
{
    /**
     * @param  iterable<Location>  $locations
     * @return array{locations: list<array{key: string, label: string, type: string, address: ?string, lat: float, lng: float, stale: bool, updated_at: ?string, edit_url: string}>, unplaced: int}
     */
    public function __invoke(iterable $locations): array
    {
        $placed = [];
        $unplaced = 0;

        $ordered = collect($locations)->sort(
            fn (Location $a, Location $b): int => [$a->label, $a->id] <=> [$b->label, $b->id],
        );

        foreach ($ordered as $location) {
            $isMobile = $location->type === LocationType::Mobile;

            if (! $location->hasFix()) {
                if ($isMobile) {
                    $unplaced++;
                }

                continue;
            }

            $updatedAt = $isMobile ? $location->mobile_position_updated_at : null;

            $placed[] = [
                'key' => (string) $location->id,
                'label' => (string) $location->label,
                'type' => $location->type->value,
                'address' => $isMobile ? null : $location->address,
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
                'stale' => $isMobile && ($updatedAt === null || $updatedAt->lt(now()->subMinutes(GetTraccarDeviceMap::STALE_AFTER_MINUTES))),
                'updated_at' => $updatedAt?->toIso8601String(),
                'edit_url' => LocationResource::getUrl('edit', ['record' => $location]),
            ];
        }

        return ['locations' => $placed, 'unplaced' => $unplaced];
    }
}
```

- [ ] **Step 4: The widget and its registration**

`app/Filament/Dashboard/Resources/Locations/Widgets/LocationsMapWidget.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\Locations\Widgets;

use App\Actions\Locations\GetLocationMap;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use Filament\Widgets\Widget;
use Livewire\Attributes\Renderless;

/**
 * The location map above the Locations table. The page renders the map
 * container immediately; the browser then calls locations() (database only,
 * the signed-in subscriber's own rows) now and every minute, so a mobile
 * location's pin follows the Traccar poller.
 */
class LocationsMapWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.locations-map';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /**
     * Mirrors the resource's gate. Filament checks canView() on every
     * hydrate, so locations() is unreachable to anyone who cannot open the page.
     */
    public static function canView(): bool
    {
        return LocationResource::canAccess();
    }

    /**
     * @return array{locations: list<array<string, mixed>>, unplaced: int}
     */
    #[Renderless]
    public function locations(): array
    {
        return app(GetLocationMap::class)(auth()->user()->locations()->get());
    }
}
```

`LocationResource`: add (+ imports)

```php
    public static function getWidgets(): array
    {
        return [
            LocationsMapWidget::class,
        ];
    }
```

`ListLocations`: add (+ import)

```php
    protected function getHeaderWidgets(): array
    {
        return [
            LocationsMapWidget::class,
        ];
    }
```

- [ ] **Step 5: The view**

`resources/views/filament/dashboard/widgets/locations-map.blade.php`, structured like `traccar-devices-map.blade.php`:

```blade
<x-filament-widgets::widget>
    <x-filament::section heading="Location map" compact>
        @include('filament.partials.leaflet-loader')
        @include('filament.partials.map-helpers')

        {{-- wire:ignore on the root: Leaflet owns the map div and JS owns the caption. --}}
        <div
            wire:ignore
            x-data="{ state: {}, destroy() { window.destroyLocationsMap(this.state); } }"
            x-init="window.initLocationsMap($el, $wire, state)"
        >
            <div data-locations-map style="height: 380px; border-radius: 0.5rem; overflow: hidden; position: relative; z-index: 0;"></div>
            <p data-locations-caption class="mt-2 text-sm text-gray-500 dark:text-gray-400"></p>
        </div>

        <script>
            window.destroyLocationsMap = function (state) {
                state.destroyed = true;

                if (state.timer) { clearInterval(state.timer); state.timer = null; }
                if (state.map) { state.map.remove(); state.map = null; }
            };

            window.initLocationsMap = function (root, $wire, state) {
                const mapEl = root.querySelector('[data-locations-map]');
                const captionEl = root.querySelector('[data-locations-caption]');
                const DEFAULT_CENTER = [39.8283, -98.5795];
                const DEFAULT_ZOOM = 4;
                const REFRESH_MS = 60000;
                const COLORS = { stationary: '#dc2626', mobile: '#2563eb', stale: '#9ca3af' };

                state.markers = new Map();
                state.fitted = false;
                state.destroyed = false;

                const colorFor = (location) => location.stale ? COLORS.stale : (location.type === 'mobile' ? COLORS.mobile : COLORS.stationary);

                // DOM nodes and textContent only: labels and addresses are subscriber-supplied strings.
                const popupContent = (location) => {
                    const wrap = document.createElement('div');

                    const line = (label, value) => {
                        const row = document.createElement('div');
                        const strong = document.createElement('strong');
                        strong.textContent = label + ' ';
                        row.appendChild(strong);
                        row.appendChild(document.createTextNode(value));
                        wrap.appendChild(row);
                    };

                    line('Name:', location.label);
                    line('Type:', location.type === 'mobile' ? 'Mobile' : 'Stationary');

                    if (location.type === 'mobile') {
                        line('Last position update:', window.wxMapHelpers.formatTimestamp(location.updated_at));

                        if (location.stale) {
                            const note = document.createElement('div');
                            note.textContent = 'No update for over an hour';
                            note.style.color = '#6b7280';
                            wrap.appendChild(note);
                        }
                    } else if (location.address) {
                        line('Address:', location.address);
                    }

                    const link = document.createElement('a');
                    link.href = location.edit_url;
                    link.textContent = 'Edit';
                    link.style.display = 'inline-block';
                    link.style.marginTop = '0.25rem';
                    wrap.appendChild(link);

                    return wrap;
                };

                const caption = (data) => {
                    const parts = [];

                    if (data.locations.length === 0 && data.unplaced === 0) {
                        parts.push('Add a location to see it here.');
                    } else if (data.locations.length === 0) {
                        parts.push('No locations with a position yet.');
                    }

                    if (data.unplaced > 0) {
                        parts.push(data.unplaced + (data.unplaced === 1 ? ' mobile location has' : ' mobile locations have') + ' no position yet.');
                    }

                    captionEl.textContent = parts.join(' ');
                };

                const render = (data) => {
                    const seen = new Set();
                    const points = [];

                    data.locations.forEach((location) => {
                        seen.add(location.key);
                        points.push([location.lat, location.lng]);

                        const icon = window.wxMapHelpers.pinIcon(colorFor(location));
                        const existing = state.markers.get(location.key);

                        if (existing) {
                            existing.setLatLng([location.lat, location.lng]);
                            existing.options.title = existing.options.alt = location.label;
                            existing.setIcon(icon);
                            existing.setPopupContent(popupContent(location));
                        } else {
                            const marker = L.marker([location.lat, location.lng], { icon: icon, title: location.label, alt: location.label })
                                .bindPopup(popupContent(location))
                                .addTo(state.map);
                            state.markers.set(location.key, marker);
                        }
                    });

                    state.markers.forEach((marker, key) => {
                        if (! seen.has(key)) {
                            marker.remove();
                            state.markers.delete(key);
                        }
                    });

                    if (points.length > 0 && ! state.fitted) {
                        state.map.fitBounds(L.latLngBounds(points), { padding: [40, 40], maxZoom: 12 });
                        state.fitted = true;
                    }

                    caption(data);
                };

                const refresh = async () => {
                    try {
                        const data = await $wire.locations();

                        if (state.destroyed || ! state.map) { return; }

                        if (! data || ! Array.isArray(data.locations)) {
                            throw new Error('Unexpected map payload');
                        }

                        render(data);
                    } catch (error) {
                        if (! state.destroyed) { captionEl.textContent = "Couldn't refresh the map."; }
                    }
                };

                window.whenLeafletReady(() => {
                    if (state.destroyed) { return; }

                    state.map = L.map(mapEl).setView(DEFAULT_CENTER, DEFAULT_ZOOM);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                    }).addTo(state.map);

                    refresh();
                    state.timer = setInterval(refresh, REFRESH_MS);
                }, () => {
                    if (! state.destroyed) { captionEl.textContent = "Couldn't load the map."; }
                });
            };
        </script>
    </x-filament::section>
</x-filament-widgets::widget>
```

(A page showing both maps never happens, but the global names are distinct anyway: `initLocationsMap`/`destroyLocationsMap` vs the Traccar pair.)

- [ ] **Step 6: Record the rule**

Append to `.ai/rules/filament.md`, in the file's style:

```markdown
## Map widgets share one helper partial and build popups with textContent
The Locations map and the Traccar devices map share `resources/views/filament/partials/map-helpers.blade.php` (`window.wxMapHelpers`: pinIcon, relativeTime, formatTimestamp) next to `leaflet-loader` — a new Leaflet widget must include and use it rather than copy the JS. Popups and captions are built with DOM nodes and `textContent` only (labels, addresses and device names are subscriber/external strings); the only HTML is the pin SVG, whose color comes from constants, and links get their `href` from a server-generated URL. The Locations map reads only the database (GetLocationMap): no Traccar or other external call from that page. Both widgets gate `canView()` on their resource, because Filament re-checks it on every hydrate and that is what protects the Renderless data endpoint.
```

- [ ] **Step 7: Run to verify it passes**

Run: `tests/Feature/GetLocationMapTest.php tests/Feature/LocationsMapWidgetTest.php tests/Feature/LocationResourceTest.php tests/Feature/TraccarDevicesMapWidgetTest.php`
Expected: all PASS.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Locations/GetLocationMap.php app/Filament/Dashboard/Resources/Locations/Widgets/LocationsMapWidget.php resources/views/filament/dashboard/widgets/locations-map.blade.php app/Filament/Dashboard/Resources/Locations/LocationResource.php app/Filament/Dashboard/Resources/Locations/Pages/ListLocations.php .ai/rules/filament.md tests/Feature/GetLocationMapTest.php tests/Feature/LocationsMapWidgetTest.php
git commit -m "Add a map of the subscriber's locations above the Locations table

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Post-implementation verification (controller, in a real browser)

1. Create a throwaway subscriber; seed locations: a stationary (with address), a fresh mobile (position updated now), a stale mobile (updated 2 h ago), a mobile with no fix, a stationary labelled `<b>x</b>`, and another user's location. Log in (Livewire login component), open `/dashboard/locations` at 1280x900.
2. Confirm: the "Location map" card above the table; markers red/blue/gray as expected; the other user's location absent; the unplaced caption ("1 mobile location has no position yet."); popups show plain text (the `<b>x</b>` label appears literally); the Edit link goes to the edit page; the map fits the markers once and a manual pan/zoom is not undone by the 60-second refresh.
3. Empty state: a subscriber with no locations sees the map and "Add a location to see it here."
4. **Helpers still work on the Traccar map**: open `/dashboard/traccar-servers` as a Plus subscriber, confirm `window.wxMapHelpers` exists and has the three functions, then exercise the Traccar map's full render path by calling `window.initTraccarDevicesMap(rootEl, {devices: async () => ({devices: [...fresh and stale devices...], unplaced: 0, unreachable: [], has_servers: true})}, {})` against a fresh container: red/gray pins, popup text, "Last check-in" formatting.
5. Check the console for errors; delete throwaway data afterwards and reset the viewport.

---

## Self-Review (against the spec)

**Coverage:** colors and stale rule incl. null timestamp (T2 action + view); mobile-without-fix excluded and counted (T2); popup fields and Edit link (T2 view); database-only (T2, no-HTTP test); always shown / every tier / empty state (T2 widget + view caption); 60 s refresh, fit once, maxZoom 12 (T2 view); shared helper partial with Traccar map unchanged (T1); payload field list and ordering (T2 tests); `getWidgets()` + `getHeaderWidgets()` (T2); rule recorded (T2); browser verification incl. Traccar helper path (final section); non-goals untouched.

**Placeholder scan:** none. One test note tells the implementer to hoist two inline `Http` references into a `use` import (cosmetic).

**Type consistency:** the payload shape is identical in `GetLocationMap`, `LocationsMapWidget::locations()`, the view's use of `key/label/type/address/lat/lng/stale/updated_at/edit_url`, and the tests; `window.wxMapHelpers.{pinIcon,relativeTime,formatTimestamp}` names are the same in the partial, the Traccar view (T1) and the Locations view (T2).
