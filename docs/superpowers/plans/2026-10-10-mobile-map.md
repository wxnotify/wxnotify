# Mobile Map Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Map tab shows a Leaflet map (USGS base map, NOAA radar, the phone and saved places, and the active warnings and watches near them) in a web view, with a native "Near you" list underneath that opens warning products and places.

**Architecture:** One backend endpoint, `GET /api/v1/map`, returns a snapshot built by `GetMapSnapshot` (places and phone from the user's locations; warnings from `active_wwa_areas` within 150 miles via `GetMapWarnings`). The app renders the snapshot into one inline-HTML page (`MapPage`, a Blade view with Leaflet bundled and inlined) shown in `<webview>`, plus native list rows. The map page is display-only; actions are native.

**Tech Stack:** Laravel 12 / PHP 8.4 / Pest / PostGIS (existing); NativePHP Mobile v4 with `nativephp/mobile-ui` in `mobile/`; Leaflet 1.9.4 bundled (BSD-2).

**Spec:** `docs/superpowers/specs/2026-10-10-mobile-map-design.md`

## Global Constraints

- The endpoint joins the existing `/api/v1` app-token group (`auth:sanctum`, `ability:app`, `ExtendAppTokenExpiry`, `throttle:app-api`), returns an Eloquent API Resource (default `data` wrapper), and is scoped to the token's user.
- The endpoint takes no coordinates. The phone is the Location whose `app_token_id` equals the calling token's id (and `mobile_source` is `App`). Another user's locations never appear; no Traccar or token identifiers leave the action.
- Warnings: active (`active_wwas.status = 'Active'`, `is_test = false`), area row `Active` and unexpired, `eventFamily()` in `warning`/`watch`, within 150 miles (config `wxnotify.map.warning_radius_miles`) of the phone or any place; selected through `active_wwa_areas` (never the event row's `ugc_codes`/`polygon`, `.ai/rules/pipeline.md`); one entry per event; geometry simplified at 0.01° and written with 4 decimals; ordered by family rank (warning, watch), then distance, then id; capped at 100 (`wxnotify.map.max_warnings`).
- `layers` come from `config/wxnotify.php` (`map.*`): USGS Topo and Imagery tile URLs (`https://basemap.nationalmap.gov/arcgis/rest/services/{USGSTopo|USGSImageryTopo}/MapServer/tile/{z}/{y}/{x}`, max zoom 16), NOAA radar WMS `https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows` layer `conus_bref_qcd`, attribution "USGS The National Map · NOAA/NWS MRMS".
- Page safety: all data is embedded with `json_encode` flags `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES`; popups are built from DOM text nodes, never `innerHTML`; a `<meta http-equiv="Content-Security-Policy">` allows only inline script and style and images from the configured tile/radar hosts and `data:`; the page never contains the app token.
- The page's HTML is computed once per snapshot and must be byte-identical for an identical snapshot (no timestamps or random values), so the web view is not reloaded when only native state changes.
- App rules: native components registered with `Route::native`; write `pressable`, `webview`, `list`, `list-item`, `outlined-text-input`, `select` as BARE tags; pass handler arguments as `{{ $int }}`; no `[` or quotes inside `{{ }}` in `native:model`/`@change`; never run `native:run` or `native:jump`.
- Commits end with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never push. Run `vendor/bin/pint --dirty --format agent` before each commit (root for backend, `mobile/` for app files). Read `.ai/rules/index.md` and the rule files covering the paths you edit.
- Backend tests: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact <file>'`. App tests: `cd mobile && vendor/bin/pest <file>`; `php artisan view:clear` after view changes; `php artisan native:validate` must pass.
- `Http::fake()` stubs STACK and the first match wins (never re-fake inside one test); `TokenStore` has `forget()`, not `clear()`.

## Review Focus

1. Hostile text (a place label or warning headline with `</script>` or HTML) must never break out of the page or execute (Task 5).
2. Another user's places never appear, and the phone is this token's own linked location, not another phone of the same user (Task 3).
3. The radius edge, expiry, test events, other families and a cancelled area must be excluded; an event with several areas appears once with the latest end (Task 2).
4. The map page is not rebuilt, and so the web view not reloaded, by anything but a new snapshot (Tasks 5 and 6).
5. Offline, no-places and error states must show something sensible instead of a blank or a crash (Task 6).
6. The payload stays small: geometry is simplified and rounded, and the event count is capped (Task 2).

## Task order and the device gate

Task 1 builds a throwaway spike screen so the web-view approach can be proven on a device. The controller (not a subagent) runs the **device gate** after the owner rebuilds the app: iOS simulator and Android emulator must show the spike map rendering with USGS tiles and NOAA radar, pan, zoom, a tap popup and the layer control. Backend Tasks 2 and 3 do not depend on the gate and may run first; app Tasks 5 and 6 wait for the gate's recorded ruling. If the gate fails for the inline-HTML approach, the controller rules on the PHP-mode fallback (the page served by an app route with the same Blade view) and amends Tasks 5 and 6 before dispatching them.

---

### Task 1: Bundle Leaflet and build the throwaway spike screen

**Files (under `mobile/`):**
- Create: `resources/map/leaflet.css`, `resources/map/leaflet.js`, `resources/map/LEAFLET-LICENSE.txt`
- Create: `app/NativeComponents/MapSpikeScreen.php`, `resources/views/native/map-spike.blade.php`, `resources/views/map/spike.blade.php`
- Modify: `routes/mobile.php`, `app/NativeComponents/SettingsScreen.php`, `resources/views/native/settings-screen.blade.php`
- Test: `tests/Feature/MapSpikeScreenTest.php`

**Interfaces:**
- Produces: the bundled Leaflet files (used by Task 5); route `/map-spike` and a temporary Settings row "Map spike (temporary)" (both removed in Task 6).

- [ ] **Step 1: Download Leaflet 1.9.4 and verify it against the integrity hashes the dashboard already pins**

```bash
cd mobile && mkdir -p resources/map
curl -fsSL https://unpkg.com/leaflet@1.9.4/dist/leaflet.css -o resources/map/leaflet.css
curl -fsSL https://unpkg.com/leaflet@1.9.4/dist/leaflet.js -o resources/map/leaflet.js
curl -fsSL https://unpkg.com/leaflet@1.9.4/LICENSE -o resources/map/LEAFLET-LICENSE.txt
echo "css: sha256-$(openssl dgst -sha256 -binary resources/map/leaflet.css | openssl base64 -A)"
echo "js:  sha256-$(openssl dgst -sha256 -binary resources/map/leaflet.js | openssl base64 -A)"
```

Expected: `css: sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=` and `js: sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=` (these are the pins in `resources/views/filament/partials/leaflet-loader.blade.php`). If either differs, STOP and report BLOCKED.

- [ ] **Step 2: Write the failing test**

`mobile/tests/Feature/MapSpikeScreenTest.php`:

```php
<?php

use App\NativeComponents\MapSpikeScreen;
use App\Support\TokenStore;
use Native\Mobile\Testing\Native;

test('the spike screen shows a web view whose page carries Leaflet, the USGS tiles and the NOAA radar', function () {
    (new TokenStore)->put('9|secret');

    Native::test(MapSpikeScreen::class)
        ->assertNavTitle('Map spike')
        ->assertElement('webview', fn (array $node): bool => str_contains((string) ($node['props']['html'] ?? ''), 'L.map(')
            && str_contains($node['props']['html'], 'basemap.nationalmap.gov')
            && str_contains($node['props']['html'], 'opengeo.ncep.noaa.gov')
            && ($node['props']['javascript'] ?? false) === true);
});
```

- [ ] **Step 3: Run it to see it fail** (`cd mobile && vendor/bin/pest tests/Feature/MapSpikeScreenTest.php`).

- [ ] **Step 4: Implement the spike (throwaway; keep it small)**

`mobile/app/NativeComponents/MapSpikeScreen.php`:

```php
<?php

namespace App\NativeComponents;

use App\Support\Concerns\RequiresSignIn;
use Native\Mobile\Edge\NativeComponent;

/**
 * THROWAWAY (slice 5 spike): proves an inline-HTML web view can run bundled Leaflet with the USGS
 * base map, the NOAA radar, a polygon, a marker and a popup. Removed in the Map screen task.
 */
class MapSpikeScreen extends NativeComponent
{
    use RequiresSignIn;

    public string $html = '';

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->html = view('map.spike', [
            'leafletCss' => file_get_contents(resource_path('map/leaflet.css')),
            'leafletJs' => file_get_contents(resource_path('map/leaflet.js')),
        ])->render();
    }
}
```

`mobile/resources/views/native/map-spike.blade.php`:

```blade
<native:top-bar title="Map spike" show-navigation-icon="true"></native:top-bar>

<webview :html="$html" javascript="true" dom-storage="true" class="w-full flex-1" />
```

`mobile/resources/views/map/spike.blade.php` (a plain Blade page, not a native screen):

```blade
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<style>{!! $leafletCss !!}</style>
<style>html,body,#map{height:100%;margin:0}</style>
</head>
<body>
<div id="map"></div>
<script>{!! $leafletJs !!}</script>
<script>
var map = L.map('map').setView([39.74, -104.99], 8);
var topo = L.tileLayer('https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', {maxZoom: 16, attribution: 'USGS The National Map'}).addTo(map);
var imagery = L.tileLayer('https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}', {maxZoom: 16});
var radar = L.tileLayer.wms('https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows', {layers: 'conus_bref_qcd', format: 'image/png', transparent: true, opacity: 0.65, version: '1.1.1'}).addTo(map);
var warn = L.geoJSON({type: 'Polygon', coordinates: [[[-105.5, 39.5], [-105.5, 40.5], [-104.5, 40.5], [-104.5, 39.5], [-105.5, 39.5]]]}, {style: {color: '#d32f2f', weight: 2, fillOpacity: 0.15}}).bindPopup(document.createTextNode('Spike polygon')).addTo(map);
var pin = L.circleMarker([39.74, -104.99], {radius: 8, color: '#1d4ed8', fillColor: '#3b82f6', fillOpacity: 0.9}).bindPopup(document.createTextNode('Spike place')).addTo(map);
L.control.layers({Topo: topo, Imagery: imagery}, {Radar: radar, Warnings: warn, Places: pin}).addTo(map);
</script>
</body>
</html>
```

In `mobile/routes/mobile.php`, add `use App\NativeComponents\MapSpikeScreen;` and `Route::native('/map-spike', MapSpikeScreen::class);` after the `/settings` route. In `SettingsScreen.php` add:

```php
    /** THROWAWAY (slice 5 spike). */
    public function openMapSpike(): void
    {
        $this->navigate('/map-spike');
    }
```

In `settings-screen.blade.php`, after the Account `list-item`:

```blade
    <list-item headline="Map spike (temporary)" supporting="Throwaway check of the web-view map" @tap="openMapSpike"></list-item>
```

- [ ] **Step 5: Run the test, `php artisan view:clear`, `php artisan native:validate`, and the Settings tests** (`tests/Feature/SettingsScreenTest.php`). Expected: PASS and "All components passed validation". If `native:validate` rejects `<webview>` or its attributes, report the exact message (BLOCKED).

- [ ] **Step 6: Format and commit** (label the commit as the spike):

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/resources/map mobile/app mobile/resources/views mobile/routes mobile/tests
git commit -m "App: bundle Leaflet 1.9.4 and add a throwaway map web-view spike (slice 5)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `GetMapWarnings`: the warnings near a set of points

**Files:**
- Modify: `config/wxnotify.php`
- Create: `app/Support/WarningColors.php`, `app/Actions/Map/GetMapWarnings.php`
- Test: `tests/Feature/GetMapWarningsTest.php`, `tests/Unit/WarningColorsTest.php`

**Interfaces:**
- Produces: `GetMapWarnings::__invoke(array $anchors): array` where `$anchors` is `list<array{lat: float, lng: float}>` and the result is `list<array{id: int, event: string, family: string, color: string, headline: ?string, expires: ?string, view_url: ?string, geometry: array}>` (GeoJSON `GeometryCollection`), ordered and capped as in the Global Constraints; `WarningColors::for(string $event, ?string $family): string`; config keys `wxnotify.map.*`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/WarningColorsTest.php`:

```php
<?php

use App\Support\WarningColors;

test('known events have their NWS colour and others fall back by family', function () {
    expect(WarningColors::for('Tornado Warning', 'warning'))->toBe('#ff0000')
        ->and(WarningColors::for('Severe Thunderstorm Warning', 'warning'))->toBe('#ffa500')
        ->and(WarningColors::for('Flash Flood Warning', 'warning'))->toBe('#8b0000')
        ->and(WarningColors::for('Tornado Watch', 'watch'))->toBe('#ffff00')
        ->and(WarningColors::for('Severe Thunderstorm Watch', 'watch'))->toBe('#db7093')
        ->and(WarningColors::for('Something Unlisted Warning', 'warning'))->toBe('#d32f2f')
        ->and(WarningColors::for('Something Unlisted Watch', 'watch'))->toBe('#f9a825')
        ->and(WarningColors::for('Whatever', null))->toBe('#6b7280');
});
```

`tests/Feature/GetMapWarningsTest.php` (the fixtures mirror `GetActiveAlertsForLocationTest`; all helper names are unique to this file):

```php
<?php

use App\Actions\Map\GetMapWarnings;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00');
    $this->anchors = [['lat' => 40.0, 'lng' => -105.0]];
});

afterEach(function () {
    Carbon::setTestNow();
});

const MAP_NEAR = 'POLYGON((-103.9 39.9, -103.9 40.1, -103.7 40.1, -103.7 39.9, -103.9 39.9))';   // about 95 km east
const MAP_EDGE_OUT = 'POLYGON((-101.9 39.9, -101.9 40.1, -101.7 40.1, -101.7 39.9, -101.9 39.9))'; // about 265 km east, beyond 150 miles
const MAP_COVERING = 'POLYGON((-105.5 39.5, -105.5 40.5, -104.5 40.5, -104.5 39.5, -105.5 39.5))';

function mapEvent(string $name, string $family, array $attributes = []): ActiveWwa
{
    return ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => $name, 'family' => $family])->id,
        'status' => 'Active',
        'is_test' => false,
        ...$attributes,
    ]);
}

function mapArea(ActiveWwa $wwa, ?string $polygonWkt = null, array $attributes = []): ActiveWwaArea
{
    $factory = ActiveWwaArea::factory()->state([
        'active_wwa_id' => $wwa->id,
        'ugc_code' => 'XXZ000',
        'status' => 'Active',
        'expires' => now()->addHour(),
        ...$attributes,
    ]);

    return ($polygonWkt === null ? $factory : $factory->withPolygon($polygonWkt))->create();
}

function mapZoneRow(string $table, array $attributes, string $wkt): void
{
    DB::table($table)->insert([
        ...$attributes,
        'lon' => 0,
        'lat' => 0,
        'boundary' => DB::raw("ST_Multi(ST_SetSRID(ST_GeomFromText('{$wkt}'), 4269))"),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function mapEvents(array $anchors): array
{
    return (new GetMapWarnings)($anchors);
}

test('a polygon warning within 150 miles is included and one just beyond is not', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_NEAR);
    mapArea(mapEvent('Severe Thunderstorm Warning', 'warning'), MAP_EDGE_OUT);

    $events = mapEvents($this->anchors);

    expect(collect($events)->pluck('event')->all())->toBe(['Tornado Warning']);
});

test('the payload carries the name, family, colour, headline, end, product page and simplified GeoJSON', function () {
    $product = IngestedProduct::factory()->create(['view_slug' => 'abc123']);
    $wwa = mapEvent('Tornado Warning', 'warning', ['headline' => 'Tornado Warning issued', 'latest_ingested_product_id' => $product->id]);
    mapArea($wwa, MAP_COVERING);

    $event = mapEvents($this->anchors)[0];

    expect($event)->toMatchArray([
        'id' => $wwa->id, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000',
        'headline' => 'Tornado Warning issued', 'view_url' => $product->viewUrl(),
    ])
        ->and($event['expires'])->toBe(now()->addHour()->toIso8601String())
        ->and($event['geometry']['type'])->toBe('GeometryCollection')
        ->and($event['geometry']['geometries'][0]['type'])->toBe('Polygon');
});

test('only warnings and watches are drawn', function () {
    mapArea(mapEvent('Winter Weather Advisory', 'advisory'), MAP_COVERING);
    mapArea(mapEvent('Special Weather Statement', 'statement'), MAP_COVERING);
    mapArea(mapEvent('Tornado Watch', 'watch'), MAP_COVERING);
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_COVERING);

    expect(collect(mapEvents($this->anchors))->pluck('event')->all())->toBe(['Tornado Warning', 'Tornado Watch']);
});

test('test events, cancelled or expired events and cancelled or lapsed areas are left out', function () {
    mapArea(mapEvent('Test Warning', 'warning', ['is_test' => true]), MAP_COVERING);
    mapArea(mapEvent('Cancelled Event', 'warning', ['status' => 'Cancelled']), MAP_COVERING);
    mapArea(mapEvent('Cancelled Area', 'warning'), MAP_COVERING, ['status' => 'Cancelled']);
    mapArea(mapEvent('Lapsed Area', 'warning'), MAP_COVERING, ['expires' => now()->subMinute()]);
    mapArea(mapEvent('Open Ended', 'warning'), MAP_COVERING, ['expires' => null]);

    expect(collect(mapEvents($this->anchors))->pluck('event')->all())->toBe(['Open Ended']);
});

test('an event with several areas is listed once, with the latest end, or none when an area has none', function () {
    $later = mapEvent('Flash Flood Warning', 'warning');
    mapArea($later, MAP_COVERING, ['ugc_code' => 'XXZ001', 'expires' => now()->addHour()]);
    mapArea($later, MAP_NEAR, ['ugc_code' => 'XXZ002', 'expires' => now()->addHours(3)]);
    $open = mapEvent('Tornado Warning', 'warning');
    mapArea($open, MAP_COVERING, ['ugc_code' => 'XXZ003', 'expires' => now()->addHour()]);
    mapArea($open, MAP_NEAR, ['ugc_code' => 'XXZ004', 'expires' => null]);

    $events = collect(mapEvents($this->anchors))->keyBy('event');

    expect($events)->toHaveCount(2)
        ->and($events['Flash Flood Warning']['expires'])->toBe(now()->addHours(3)->toIso8601String())
        ->and($events['Flash Flood Warning']['geometry']['geometries'])->toHaveCount(2)
        ->and($events['Tornado Warning']['expires'])->toBeNull();
});

test('a zone-only event takes its geometry from the public zone, county, fire zone or marine zone it names', function () {
    mapZoneRow('public_zones', ['state' => 'CO', 'cwa' => 'BOU', 'zone' => '040', 'name' => 'Front Range', 'state_zone' => 'CO040'], MAP_NEAR);
    mapZoneRow('counties', ['state' => 'CO', 'cwa' => 'BOU', 'countyname' => 'Denver', 'fips' => '08031'], MAP_COVERING);
    mapZoneRow('fire_zones', ['state' => 'CO', 'zone' => '215', 'cwa' => 'BOU', 'name' => 'Fire Zone', 'state_zone' => 'CO215'], MAP_COVERING);
    mapZoneRow('marine_zones_coastal', ['zone_type' => 'coastal', 'zone_id' => 'GMZ655', 'name' => 'Marine Zone'], MAP_COVERING);

    foreach (['COZ040' => 'Zone Warning', 'COC031' => 'County Warning', 'COZ215' => 'Fire Warning', 'GMZ655' => 'Marine Warning'] as $ugc => $name) {
        mapArea(mapEvent($name, 'warning'), null, ['ugc_code' => $ugc]);
    }

    mapArea(mapEvent('Unknown Zone Warning', 'warning'), null, ['ugc_code' => 'COZ999']);

    expect(collect(mapEvents($this->anchors))->pluck('event')->sort()->values()->all())
        ->toBe(['County Warning', 'Fire Warning', 'Marine Warning', 'Zone Warning']);
});

test('the nearest anchor of several decides the distance, and an event near any anchor is in', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_EDGE_OUT);

    expect(mapEvents($this->anchors))->toBe([])
        ->and(collect(mapEvents([...$this->anchors, ['lat' => 40.0, 'lng' => -102.0]]))->pluck('event')->all())->toBe(['Tornado Warning']);
});

test('events are ordered by family, then distance, then id, and capped', function () {
    config(['wxnotify.map.max_warnings' => 3]);
    mapArea(mapEvent('Tornado Watch', 'watch'), MAP_COVERING);
    mapArea(mapEvent('Far Warning', 'warning'), MAP_NEAR);
    mapArea(mapEvent('Near Warning', 'warning'), MAP_COVERING);
    mapArea(mapEvent('Extra Warning', 'warning'), MAP_NEAR);

    expect(collect(mapEvents($this->anchors))->pluck('event')->all())->toBe(['Near Warning', 'Far Warning', 'Extra Warning']);
});

test('geometry is rounded to four decimals', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), 'POLYGON((-105.123456789 39.1234567891, -105.123456789 40.5, -104.5 40.5, -104.5 39.1234567891, -105.123456789 39.1234567891))');

    $json = json_encode(mapEvents($this->anchors)[0]['geometry']);

    expect($json)->toContain('-105.1235')->not->toContain('-105.123456');
});

test('with no anchors there are no warnings and nothing is queried', function () {
    mapArea(mapEvent('Tornado Warning', 'warning'), MAP_COVERING);

    expect(mapEvents([]))->toBe([]);
});
```

- [ ] **Step 2: Run them to see them fail** (class not found).

- [ ] **Step 3: Implement**

In `config/wxnotify.php` add (before the closing `];`):

```php
    'map' => [
        'warning_radius_miles' => 150,
        'max_warnings' => 100,
        'simplify_degrees' => 0.01,
        'basemaps' => [
            ['key' => 'topo', 'label' => 'Topo', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16],
            ['key' => 'imagery', 'label' => 'Imagery', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16],
        ],
        'radar' => [
            'wms_url' => 'https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows',
            'layer' => 'conus_bref_qcd',
        ],
        'attribution' => 'USGS The National Map · NOAA/NWS MRMS',
    ],
```

`app/Support/WarningColors.php`:

```php
<?php

namespace App\Support;

/**
 * The colour a warning or watch is drawn in on the map: the NWS convention for the common events,
 * else one per family.
 */
class WarningColors
{
    private const BY_EVENT = [
        'Tornado Warning' => '#ff0000',
        'Severe Thunderstorm Warning' => '#ffa500',
        'Flash Flood Warning' => '#8b0000',
        'Flood Warning' => '#00ff00',
        'Special Marine Warning' => '#ffa500',
        'Extreme Wind Warning' => '#ff8c00',
        'Hurricane Warning' => '#dc143c',
        'Tropical Storm Warning' => '#b22222',
        'Winter Storm Warning' => '#ff69b4',
        'Tornado Watch' => '#ffff00',
        'Severe Thunderstorm Watch' => '#db7093',
    ];

    private const BY_FAMILY = ['warning' => '#d32f2f', 'watch' => '#f9a825'];

    private const OTHER = '#6b7280';

    public static function for(string $event, ?string $family): string
    {
        return self::BY_EVENT[$event] ?? self::BY_FAMILY[$family ?? ''] ?? self::OTHER;
    }
}
```

`app/Actions/Map/GetMapWarnings.php`:

```php
<?php

namespace App\Actions\Map;

use App\Models\ActiveWwa;
use App\Support\WarningColors;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The warnings and watches drawn on the map: active events with an active, unexpired area within the
 * configured distance of any of the given points. Areas are read from active_wwa_areas (never the
 * event row's own ugc_codes/polygon): an area's geometry is its own polygon, else the boundary of the
 * zone it names, found by UGC code in the public zones, counties, fire zones and marine zones.
 * An event is returned once with its areas' simplified geometries as one GeoJSON GeometryCollection.
 */
class GetMapWarnings
{
    private const METERS_PER_MILE = 1609.344;

    /** A generous index-friendly pre-filter in degrees; the exact test below is in metres. */
    private const PREFILTER_DEGREES = 6.0;

    private const FAMILY_RANK = ['warning' => 0, 'watch' => 1];

    /**
     * @param  list<array{lat: float, lng: float}>  $anchors
     * @return list<array{id: int, event: string, family: string, color: string, headline: ?string, expires: ?string, view_url: ?string, geometry: array<string, mixed>}>
     */
    public function __invoke(array $anchors): array
    {
        $anchors = $this->unique($anchors);

        if ($anchors === []) {
            return [];
        }

        $rows = collect($this->areaRows($anchors))->groupBy('active_wwa_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $events = ActiveWwa::query()
            ->with(['product', 'latestIngestedProduct'])
            ->whereIn('id', $rows->keys()->all())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(fn ($areaRows, $id): ?array => $this->entry($events->get($id), $areaRows))
            ->filter()
            ->sortBy([
                fn (array $a, array $b): int => self::FAMILY_RANK[$a['family']] <=> self::FAMILY_RANK[$b['family']],
                fn (array $a, array $b): int => $a['_meters'] <=> $b['_meters'],
                fn (array $a, array $b): int => $a['id'] <=> $b['id'],
            ])
            ->take((int) config('wxnotify.map.max_warnings'))
            ->map(fn (array $entry): array => collect($entry)->except('_meters')->all())
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $areaRows
     * @return ?array<string, mixed>
     */
    private function entry(?ActiveWwa $wwa, $areaRows): ?array
    {
        $family = $wwa?->eventFamily();

        if ($wwa === null || ! isset(self::FAMILY_RANK[$family])) {
            return null;
        }

        $expires = $areaRows->contains(fn ($row): bool => $row->expires === null) ? null : $areaRows->max('expires');

        return [
            'id' => $wwa->id,
            'event' => $wwa->eventName(),
            'family' => $family,
            'color' => WarningColors::for($wwa->eventName(), $family),
            'headline' => $wwa->headline,
            'expires' => $expires === null ? null : Carbon::parse($expires)->toIso8601String(),
            'view_url' => $wwa->latestIngestedProduct?->viewUrl(),
            'geometry' => [
                'type' => 'GeometryCollection',
                'geometries' => $areaRows->map(fn ($row): array => json_decode($row->geojson, true))->values()->all(),
            ],
            '_meters' => (float) $areaRows->min('meters'),
        ];
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $anchors
     * @return list<object>
     */
    private function areaRows(array $anchors): array
    {
        $values = implode(', ', array_fill(0, count($anchors), '(?::float8, ?::float8)'));
        $bindings = [(float) config('wxnotify.map.simplify_degrees')];

        foreach ($anchors as $anchor) {
            $bindings[] = $anchor['lng'];
            $bindings[] = $anchor['lat'];
        }

        $bindings[] = now()->toDateTimeString();
        $bindings[] = self::PREFILTER_DEGREES;
        $bindings[] = (float) config('wxnotify.map.warning_radius_miles') * self::METERS_PER_MILE;

        return DB::select(<<<SQL
            SELECT t.area_id, t.active_wwa_id, t.expires, MIN(t.meters) AS meters, MIN(t.geojson) AS geojson
            FROM (
                SELECT a.id AS area_id, a.active_wwa_id, a.expires,
                       ST_Distance(ST_SetSRID(g.geom, 4326)::geography, ST_SetSRID(ST_MakePoint(p.lng, p.lat), 4326)::geography) AS meters,
                       ST_AsGeoJSON(ST_SimplifyPreserveTopology(g.geom, ?), 4) AS geojson
                FROM active_wwa_areas a
                JOIN active_wwas w ON w.id = a.active_wwa_id
                JOIN LATERAL (
                    SELECT COALESCE(a.polygon, (
                        SELECT b.boundary FROM (
                            SELECT boundary FROM public_zones WHERE substr(a.ugc_code, 3, 1) = 'Z' AND state_zone = substr(a.ugc_code, 1, 2) || substr(a.ugc_code, 4)
                            UNION ALL
                            SELECT boundary FROM fire_zones WHERE substr(a.ugc_code, 3, 1) = 'Z' AND state_zone = substr(a.ugc_code, 1, 2) || substr(a.ugc_code, 4)
                            UNION ALL
                            SELECT boundary FROM counties WHERE substr(a.ugc_code, 3, 1) = 'C' AND state = substr(a.ugc_code, 1, 2) AND right(fips, 3) = substr(a.ugc_code, 4)
                            UNION ALL
                            SELECT boundary FROM marine_zones_coastal WHERE zone_id = a.ugc_code
                            UNION ALL
                            SELECT boundary FROM marine_zones_offshore WHERE zone_id = a.ugc_code
                            UNION ALL
                            SELECT boundary FROM marine_zones_high_seas WHERE zone_id = a.ugc_code
                        ) b LIMIT 1
                    )) AS geom
                ) g ON g.geom IS NOT NULL
                CROSS JOIN (VALUES {$values}) AS p(lng, lat)
                WHERE a.status = 'Active'
                  AND (a.expires IS NULL OR a.expires > ?)
                  AND w.status = 'Active'
                  AND w.is_test = false
                  AND ST_DWithin(g.geom, ST_SetSRID(ST_MakePoint(p.lng, p.lat), 4269), ?)
                  AND ST_DWithin(ST_SetSRID(g.geom, 4326)::geography, ST_SetSRID(ST_MakePoint(p.lng, p.lat), 4326)::geography, ?)
            ) t
            GROUP BY t.area_id, t.active_wwa_id, t.expires
            SQL, $bindings);
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $anchors
     * @return list<array{lat: float, lng: float}>
     */
    private function unique(array $anchors): array
    {
        return collect($anchors)
            ->map(fn (array $anchor): array => ['lat' => round((float) $anchor['lat'], 3), 'lng' => round((float) $anchor['lng'], 3)])
            ->unique(fn (array $anchor): string => $anchor['lat'].','.$anchor['lng'])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 4: Run the tests** (`GetMapWarningsTest.php` and `tests/Unit/WarningColorsTest.php`, in the container). Expected: PASS. The expiry comparison deliberately uses a bound Carbon `now()` (not the database clock) so the frozen test clock works. If a test fails because of a PostGIS detail (an invalid fixture polygon, a bind-order slip: the placeholders appear in the order simplify tolerance, then each anchor's lng and lat, then the expiry timestamp, then the pre-filter degrees, then the metres), fix it and report what you changed.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/wxnotify.php app/Support/WarningColors.php app/Actions/Map tests/Feature/GetMapWarningsTest.php tests/Unit/WarningColorsTest.php
git commit -m "Map: GetMapWarnings selects the warnings and watches near a set of points

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `GetMapSnapshot`, the `/api/v1/map` endpoint and the recorded rules

**Files:**
- Create: `app/Actions/Map/GetMapSnapshot.php`, `app/Http/Resources/Api/V1/MapSnapshotResource.php`, `app/Http/Controllers/Api/V1/MapController.php`
- Modify: `routes/api.php`, `.ai/rules/api.md`, `.ai/rules/actions.md`
- Test: `tests/Feature/ApiMapTest.php`

**Interfaces:**
- Consumes: `GetMapWarnings` (Task 2), `config('wxnotify.map')`.
- Produces: `GetMapSnapshot::__invoke(User $user, ?int $tokenId): array` returning exactly the spec's `data` shape; `GET /api/v1/map` (route name `api.v1.map`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/ApiMapTest.php`:

```php
<?php

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
});

function apiMapCall($test, ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->phone->plainTextToken)->getJson('/api/v1/map');
}

test('the map needs an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/map')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    apiMapCall($this, $beacon)->assertForbidden();
});

test('with no locations the snapshot has no centre, phone, places or warnings and still carries the layers', function () {
    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['center'])->toBe(['lat' => null, 'lng' => null, 'source' => null, 'label' => null])
        ->and($data['phone'])->toBeNull()
        ->and($data['places'])->toBe([])
        ->and($data['warnings'])->toBe([])
        ->and($data['layers']['basemaps'][0])->toBe(['key' => 'topo', 'label' => 'Topo', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16])
        ->and($data['layers']['radar'])->toBe(['wms_url' => 'https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows', 'layer' => 'conus_bref_qcd'])
        ->and($data['layers']['attribution'])->toBe('USGS The National Map · NOAA/NWS MRMS')
        ->and($data['generated_at'])->not->toBeNull();
});

test('the phone is the location kept current by this token and it is the centre', function () {
    $mine = Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create([
        'label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4, 'mobile_position_updated_at' => now()->subMinutes(5), 'mobile_position_fixed_at' => now()->subMinutes(5),
    ]);
    $otherPhoneToken = $this->user->createToken('iPad', ['app']);
    Location::factory()->for($this->user)->mobileApp($otherPhoneToken->accessToken)->create(['label' => 'iPad', 'lat' => 30.0, 'lng' => -90.0, 'mobile_position_updated_at' => now(), 'mobile_position_fixed_at' => now()]);
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 39.7, 'lng' => -105.0]);

    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['phone'])->toMatchArray(['lat' => 35.2, 'lng' => -97.4, 'stale' => false])
        ->and($data['center'])->toBe(['lat' => 35.2, 'lng' => -97.4, 'source' => 'phone', 'label' => 'Pixel 9'])
        ->and(collect($data['places'])->pluck('label')->all())->toBe(['Home', 'iPad', 'Pixel 9'])
        ->and(collect($data['places'])->firstWhere('id', $mine->id))->toMatchArray(['type' => 'mobile', 'stale' => false]);
});

test('without a phone fix the centre is the first place with a position, and a place without one is left out', function () {
    Location::factory()->for($this->user)->create(['label' => 'Zed', 'lat' => 40.0, 'lng' => -100.0]);
    Location::factory()->for($this->user)->create(['label' => 'Alpha', 'lat' => 41.0, 'lng' => -101.0]);
    Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'No fix', 'lat' => null, 'lng' => null]);

    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['phone'])->toBeNull()
        ->and($data['center'])->toBe(['lat' => 41.0, 'lng' => -101.0, 'source' => 'place', 'label' => 'Alpha'])
        ->and(collect($data['places'])->pluck('label')->all())->toBe(['Alpha', 'Zed']);
});

test('a stale phone and a stale mobile place are flagged; a stationary place never is', function () {
    Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4, 'mobile_position_updated_at' => now()->subHours(3), 'mobile_position_fixed_at' => now()->subHours(3)]);
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 39.7, 'lng' => -105.0]);

    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['phone']['stale'])->toBeTrue()
        ->and(collect($data['places'])->firstWhere('label', 'Home')['stale'])->toBeFalse();
});

test('another subscriber\'s locations never appear, and nothing internal leaves', function () {
    Location::factory()->create(['label' => 'Theirs', 'lat' => 33.0, 'lng' => -96.0]);
    Location::factory()->for($this->user)->mobileApp($this->phone->accessToken)->create(['label' => 'Pixel 9', 'lat' => 35.2, 'lng' => -97.4, 'mobile_position_updated_at' => now(), 'mobile_position_fixed_at' => now()]);

    $response = apiMapCall($this)->assertOk();

    expect(collect($response->json('data.places'))->pluck('label')->all())->toBe(['Pixel 9'])
        ->and($response->getContent())->not->toContain('Theirs')
        ->not->toContain('app_token_id')->not->toContain('traccar')->not->toContain('mobile_source');
});

test('warnings near the phone and the places are included, with the layers from config', function () {
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 40.0, 'lng' => -105.0]);
    $wwa = ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tornado Warning', 'family' => 'warning'])->id,
        'status' => 'Active', 'is_test' => false,
    ]);
    ActiveWwaArea::factory()->state(['active_wwa_id' => $wwa->id, 'ugc_code' => 'XXZ000', 'expires' => now()->addHour()])
        ->withPolygon('POLYGON((-105.5 39.5, -105.5 40.5, -104.5 40.5, -104.5 39.5, -105.5 39.5))')->create();

    $data = apiMapCall($this)->assertOk()->json('data');

    expect($data['warnings'])->toHaveCount(1)
        ->and($data['warnings'][0])->toMatchArray(['id' => $wwa->id, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000'])
        ->and(array_keys($data['warnings'][0]))->toBe(['id', 'event', 'family', 'color', 'headline', 'expires', 'view_url', 'geometry']);
});
```

- [ ] **Step 2: Run it to see it fail** (404).

- [ ] **Step 3: Implement**

`app/Actions/Map/GetMapSnapshot.php`:

```php
<?php

namespace App\Actions\Map;

use App\Actions\Traccar\GetTraccarDeviceMap;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the app's Map tab needs in one response: the phone (the Location this app token keeps
 * current), the user's places with a position, the centre to open on, the active warnings near them
 * and the layer configuration. Only the caller's own locations and public warning data leave here,
 * and no coordinates come in.
 */
class GetMapSnapshot
{
    public function __construct(private GetMapWarnings $warnings) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user, ?int $tokenId): array
    {
        /** @var Collection<int, Location> $placed */
        $placed = $user->locations()->get()
            ->filter(fn (Location $location): bool => $location->hasFix())
            ->sortBy(fn (Location $location): array => [$location->label, $location->id])
            ->values();

        $phone = $tokenId === null ? null : $placed->first(
            fn (Location $location): bool => $location->mobile_source === MobileSource::App && $location->app_token_id === $tokenId,
        );

        $center = $phone ?? $placed->first();

        return [
            'center' => [
                'lat' => $center === null ? null : (float) $center->lat,
                'lng' => $center === null ? null : (float) $center->lng,
                'source' => $center === null ? null : ($phone !== null ? 'phone' : 'place'),
                'label' => $center?->label,
            ],
            'phone' => $phone === null ? null : [
                'lat' => (float) $phone->lat,
                'lng' => (float) $phone->lng,
                'updated_at' => $phone->mobile_position_updated_at?->toIso8601String(),
                'stale' => $this->isStale($phone),
            ],
            'places' => $placed->map(fn (Location $location): array => [
                'id' => $location->id,
                'label' => (string) $location->label,
                'type' => $location->type->value,
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
                'stale' => $this->isStale($location),
            ])->all(),
            'warnings' => $this->warnings->__invoke(
                $placed->map(fn (Location $location): array => ['lat' => (float) $location->lat, 'lng' => (float) $location->lng])->all(),
            ),
            'layers' => [
                'basemaps' => config('wxnotify.map.basemaps'),
                'radar' => config('wxnotify.map.radar'),
                'attribution' => config('wxnotify.map.attribution'),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function isStale(Location $location): bool
    {
        if ($location->type !== LocationType::Mobile) {
            return false;
        }

        $updatedAt = $location->mobile_position_updated_at;

        return $updatedAt === null || $updatedAt->lt(now()->subMinutes(GetTraccarDeviceMap::STALE_AFTER_MINUTES));
    }
}
```

`app/Http/Resources/Api/V1/MapSnapshotResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The map snapshot as built by GetMapSnapshot, which already holds only what the app may see.
 */
class MapSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
```

`app/Http/Controllers/Api/V1/MapController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Map\GetMapSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MapSnapshotResource;
use Illuminate\Http\Request;

class MapController extends Controller
{
    public function __invoke(Request $request, GetMapSnapshot $snapshot): MapSnapshotResource
    {
        return new MapSnapshotResource($snapshot($request->user(), $request->user()->currentAccessToken()?->id));
    }
}
```

In `routes/api.php` add `use App\Http\Controllers\Api\V1\MapController;` (alphabetical) and, in the app-token group after the channels routes:

```php
        Route::get('/map', MapController::class)->name('api.v1.map');
```

Append to `.ai/rules/api.md`:

```markdown
## The map snapshot returns only the caller's own places and public warning data, and takes no coordinates
`GET /api/v1/map` builds its snapshot server-side: the phone is the Location whose `app_token_id` is the calling token's id (another phone of the same user is just a place), the places are the caller's own locations with a position, and the warnings are public active events. It takes no coordinates (nothing about a position is logged or put in a URL), caps the warnings at `wxnotify.map.max_warnings` with geometry simplified at `wxnotify.map.simplify_degrees` and rounded to four decimals, and takes its `layers` (base maps, radar WMS, attribution) from `config('wxnotify.map')` so they can change without an app release.
```

Append to `.ai/rules/actions.md`:

```markdown
## GetMapWarnings selects through active_wwa_areas, never the event row's own columns
`App\Actions\Map\GetMapWarnings` reads an event's extent from its `active_wwa_areas` rows: an area's own polygon, else the boundary of the zone it names (public zones and fire zones by `state_zone`, counties by state and the last three digits of the FIPS, marine zones by `zone_id`). Do not fall back to `active_wwas.ugc_codes` or `polygon` (they only describe the latest bulletin of an event issued as many; see `.ai/rules/pipeline.md`). The expiry comparison binds Carbon's `now()` (not the database clock), so a test with a frozen clock keeps its fixtures consistent.
```

- [ ] **Step 4: Run** `tests/Feature/ApiMapTest.php`, `tests/Feature/GetMapWarningsTest.php`, `tests/Feature/ApiSavedLocationsTest.php`. Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Map app/Http routes/api.php .ai/rules tests/Feature/ApiMapTest.php
git commit -m "API: GET /map snapshot (phone, places, nearby warnings, layers)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: App API client and the cached snapshot

**Files (under `mobile/`):**
- Modify: `app/Services/WxNotifyApi.php`, `app/Support/LocalCache.php`
- Test: `tests/Feature/WxNotifyApiMapTest.php`, `tests/Feature/LocalCacheMapTest.php`

**Interfaces:**
- Produces: `WxNotifyApi::map(): array` (the unwrapped `data`); `LocalCache::rememberMap(array $snapshot): void` (stores `center`, `phone`, `places`, `warnings` WITHOUT `geometry`, and `generated_at`; never the layers), `LocalCache::map(): ?array`, wiped by `wipe()`.

- [ ] **Step 1: Write the failing tests**

`mobile/tests/Feature/WxNotifyApiMapTest.php`:

```php
<?php

use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('the map snapshot is fetched from /api/v1/map and unwrapped from its data key', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => ['places' => [], 'warnings' => []]])]);

    expect(app(WxNotifyApi::class)->map())->toBe(['places' => [], 'warnings' => []]);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://wx.test/api/v1/map' && $r->hasHeader('Authorization', 'Bearer 9|secret'));
});
```

`mobile/tests/Feature/LocalCacheMapTest.php`:

```php
<?php

use App\Support\LocalCache;

test('the cached snapshot keeps the list data without geometry or layers and is wiped on sign-out', function () {
    $cache = new LocalCache;

    expect($cache->map())->toBeNull();

    $cache->rememberMap([
        'center' => ['lat' => 40.0, 'lng' => -105.0, 'source' => 'phone', 'label' => 'Pixel'],
        'phone' => ['lat' => 40.0, 'lng' => -105.0, 'updated_at' => null, 'stale' => false],
        'places' => [['id' => 1, 'label' => 'Home', 'type' => 'stationary', 'lat' => 40.0, 'lng' => -105.0, 'stale' => false]],
        'warnings' => [['id' => 7, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000', 'headline' => null, 'expires' => null, 'view_url' => null, 'geometry' => ['type' => 'GeometryCollection', 'geometries' => []]]],
        'layers' => ['attribution' => 'x'],
        'generated_at' => '2026-10-10T18:00:00+00:00',
    ]);

    $cached = $cache->map();

    expect($cached['warnings'][0])->not->toHaveKey('geometry')
        ->and($cached['warnings'][0]['event'])->toBe('Tornado Warning')
        ->and($cached)->not->toHaveKey('layers')
        ->and($cached['places'][0]['label'])->toBe('Home')
        ->and($cached['generated_at'])->toBe('2026-10-10T18:00:00+00:00');

    $cache->wipe();

    expect($cache->map())->toBeNull();
});
```

- [ ] **Step 2: Run them to see them fail.**

- [ ] **Step 3: Implement**

In `WxNotifyApi.php`, before `searchPlaces()`:

```php
    /**
     * The map snapshot: the phone, the places, the warnings near them and the layer configuration.
     *
     * @return array<string, mixed>
     */
    public function map(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/map')));
    }
```

In `LocalCache.php`: add `private const MAP = 'wxnotify.map';`, mention "the map snapshot's lists" in the class docblock, add before `wipe()`:

```php
    /**
     * The map snapshot's list data for the offline "Near you" list: the places, phone, centre and the
     * warnings WITHOUT their geometry (the page and the shapes are never cached), plus when it was made.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function rememberMap(array $snapshot): void
    {
        Cache::forever(self::MAP, [
            'center' => $snapshot['center'] ?? null,
            'phone' => $snapshot['phone'] ?? null,
            'places' => $snapshot['places'] ?? [],
            'warnings' => array_map(fn (array $warning): array => collect($warning)->except('geometry')->all(), $snapshot['warnings'] ?? []),
            'generated_at' => $snapshot['generated_at'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>|null null until a snapshot was fetched once
     */
    public function map(): ?array
    {
        return Cache::get(self::MAP);
    }
```

and `Cache::forget(self::MAP);` in `wipe()`.

- [ ] **Step 4: Run both files plus `WxNotifyApiTest.php`, `LocalCacheTest.php`, `LocalCacheManagementTest.php`.** Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/app mobile/tests
git commit -m "App: map snapshot API call and its cached list data

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `MapPage`, the map page builder (waits for the device gate)

**Files (under `mobile/`):**
- Create: `app/Support/MapPage.php`, `resources/views/map/page.blade.php`
- Test: `tests/Feature/MapPageTest.php`

**Interfaces:**
- Consumes: the bundled `resources/map/leaflet.{css,js}` (Task 1) and the snapshot shape of Task 3.
- Produces: `MapPage::html(array $snapshot): string`, deterministic for a snapshot.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Support\MapPage;

function mapPageSnapshot(array $overrides = []): array
{
    return [
        'center' => ['lat' => 40.0, 'lng' => -105.0, 'source' => 'phone', 'label' => 'Pixel 9'],
        'phone' => ['lat' => 40.0, 'lng' => -105.0, 'updated_at' => '2026-10-10T18:00:00+00:00', 'stale' => false],
        'places' => [['id' => 1, 'label' => 'Home', 'type' => 'stationary', 'lat' => 40.1, 'lng' => -105.1, 'stale' => false]],
        'warnings' => [[
            'id' => 7, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000', 'headline' => 'Tornado Warning issued',
            'expires' => '2026-10-10T19:00:00+00:00', 'view_url' => 'https://wx.test/view-product/abc',
            'geometry' => ['type' => 'GeometryCollection', 'geometries' => [['type' => 'Polygon', 'coordinates' => [[[-105.5, 39.5], [-105.5, 40.5], [-104.5, 40.5], [-104.5, 39.5], [-105.5, 39.5]]]]]],
        ]],
        'layers' => [
            'basemaps' => [['key' => 'topo', 'label' => 'Topo', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16]],
            'radar' => ['wms_url' => 'https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows', 'layer' => 'conus_bref_qcd'],
            'attribution' => 'USGS The National Map · NOAA/NWS MRMS',
        ],
        'generated_at' => '2026-10-10T18:00:00+00:00',
        ...$overrides,
    ];
}

test('the page carries Leaflet, the configured layers and the snapshot, and is the same for the same snapshot', function () {
    $html = (new MapPage)->html(mapPageSnapshot());

    expect($html)->toContain('L.map(')
        ->toContain('basemap.nationalmap.gov')
        ->toContain('conus_bref_qcd')
        ->toContain('Tornado Warning')
        ->toContain('USGS The National Map')
        ->and((new MapPage)->html(mapPageSnapshot()))->toBe($html);
});

test('the page restricts what it may load to inline code and the configured tile and radar hosts', function () {
    $html = (new MapPage)->html(mapPageSnapshot());

    expect($html)->toContain('Content-Security-Policy')
        ->toContain("default-src 'none'")
        ->toContain('https://basemap.nationalmap.gov')
        ->toContain('https://opengeo.ncep.noaa.gov')
        ->not->toContain('unpkg.com');
});

test('hostile text in a place name or a headline cannot close the script or inject markup', function () {
    $hostile = '</script><img src=x onerror=alert(1)>"\'&';
    $benign = (new MapPage)->html(mapPageSnapshot());

    $html = (new MapPage)->html(mapPageSnapshot([
        'places' => [['id' => 1, 'label' => $hostile, 'type' => 'stationary', 'lat' => 40.1, 'lng' => -105.1, 'stale' => false]],
        'warnings' => [[...mapPageSnapshot()['warnings'][0], 'headline' => $hostile, 'event' => $hostile]],
    ]));

    expect(substr_count($html, '</script>'))->toBe(substr_count($benign, '</script>'))
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->toContain('<\/script>');
});

test('the page never contains an app token', function () {
    (new App\Support\TokenStore)->put('9|secret-token');

    $html = (new MapPage)->html(mapPageSnapshot());

    expect($html)->not->toContain('secret-token')->not->toContain('Bearer');
});

test('the page\'s own script builds popups from text nodes and never uses innerHTML', function () {
    // Leaflet itself mentions innerHTML, so check the page with the library left out.
    $html = (new MapPage(leafletJs: '', leafletCss: ''))->html(mapPageSnapshot());

    expect($html)->toContain('createTextNode')->not->toContain('innerHTML');
});

test('with no centre the page opens on the whole United States', function () {
    $html = (new MapPage)->html(mapPageSnapshot(['center' => ['lat' => null, 'lng' => null, 'source' => null, 'label' => null], 'phone' => null, 'places' => [], 'warnings' => []]));

    expect($html)->toContain('fitBounds');
});
```

- [ ] **Step 2: Run it to see it fail.**

- [ ] **Step 3: Implement**

`mobile/app/Support/MapPage.php`:

```php
<?php

namespace App\Support;

/**
 * The map's web page: one HTML document with Leaflet inlined, the layer configuration and the
 * snapshot's places, phone and warnings. It holds no token and is display-only (a popup per pin or
 * shape). Deterministic for a snapshot, so the web view is only reloaded when the snapshot changes.
 */
class MapPage
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

    public function __construct(private ?string $leafletJs = null, private ?string $leafletCss = null) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function html(array $snapshot): string
    {
        $layers = $snapshot['layers'] ?? [];

        $data = [
            'center' => $snapshot['center'] ?? null,
            'phone' => $snapshot['phone'] ?? null,
            'places' => $snapshot['places'] ?? [],
            'warnings' => $snapshot['warnings'] ?? [],
            'basemaps' => $layers['basemaps'] ?? [],
            'radar' => $layers['radar'] ?? null,
            'attribution' => $layers['attribution'] ?? '',
        ];

        return view('map.page', [
            'leafletCss' => $this->leafletCss ?? file_get_contents(resource_path('map/leaflet.css')),
            'leafletJs' => $this->leafletJs ?? file_get_contents(resource_path('map/leaflet.js')),
            'json' => json_encode($data, self::JSON_FLAGS),
            'imageHosts' => $this->hosts($layers),
        ])->render();
    }

    /**
     * The https origins the page may load images from: the base maps' and the radar's.
     *
     * @param  array<string, mixed>  $layers
     * @return list<string>
     */
    private function hosts(array $layers): array
    {
        $urls = array_column($layers['basemaps'] ?? [], 'url');

        if (isset($layers['radar']['wms_url'])) {
            $urls[] = $layers['radar']['wms_url'];
        }

        return collect($urls)
            ->map(function (string $url): ?string {
                $parts = parse_url($url);

                return isset($parts['host'], $parts['scheme']) && $parts['scheme'] === 'https' ? "https://{$parts['host']}" : null;
            })
            ->filter()->unique()->values()->all();
    }
}
```

`mobile/resources/views/map/page.blade.php`:

```blade
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: {{ implode(' ', $imageHosts) }}">
<style>{!! $leafletCss !!}</style>
<style>
html, body, #map { height: 100%; margin: 0; }
.leaflet-control-layers-toggle { background-image: none; width: auto; height: auto; padding: 6px 10px; }
.leaflet-control-layers-toggle::after { content: "Layers"; font: 600 13px sans-serif; color: #111; }
.leaflet-popup-content { font: 13px/1.4 sans-serif; }
</style>
</head>
<body>
<div id="map"></div>
<script>{!! $leafletJs !!}</script>
<script>
(function () {
    var data = {!! $json !!};

    function text(parent, tag, value, style) {
        var el = document.createElement(tag);
        el.appendChild(document.createTextNode(value));
        if (style) { el.style.cssText = style; }
        parent.appendChild(el);
        return el;
    }

    function popup(title, lines) {
        var box = document.createElement('div');
        text(box, 'strong', title);
        lines.forEach(function (line) { if (line) { text(box, 'div', line); } });
        return box;
    }

    function until(iso) {
        if (!iso) { return 'No end time given'; }
        var d = new Date(iso);
        return 'Until ' + d.toLocaleString([], { weekday: 'short', hour: 'numeric', minute: '2-digit' });
    }

    var map = L.map('map', { zoomControl: true, attributionControl: true });
    map.attributionControl.setPrefix('');

    var bases = {};
    var firstBase = null;
    data.basemaps.forEach(function (base) {
        var layer = L.tileLayer(base.url, { maxZoom: base.max_zoom, attribution: data.attribution });
        bases[base.label] = layer;
        if (!firstBase) { firstBase = layer; }
    });
    if (firstBase) { firstBase.addTo(map); }

    var overlays = {};

    if (data.radar) {
        var radar = L.tileLayer.wms(data.radar.wms_url, { layers: data.radar.layer, format: 'image/png', transparent: true, opacity: 0.65, version: '1.1.1' }).addTo(map);
        overlays['Radar'] = radar;
        setInterval(function () { radar.setParams({ _: Date.now() }, false); radar.redraw(); }, 120000);
    }

    var warnings = L.layerGroup();
    data.warnings.forEach(function (warning) {
        L.geoJSON(warning.geometry, { style: { color: warning.color, weight: 2, fillColor: warning.color, fillOpacity: 0.15 } })
            .bindPopup(popup(warning.event, [until(warning.expires), warning.headline]))
            .addTo(warnings);
    });
    warnings.addTo(map);
    overlays['Warnings'] = warnings;

    var places = L.layerGroup();
    data.places.forEach(function (place) {
        L.circleMarker([place.lat, place.lng], { radius: 7, color: '#1d4ed8', weight: 2, fillColor: '#3b82f6', fillOpacity: place.stale ? 0.3 : 0.9, opacity: place.stale ? 0.5 : 1 })
            .bindPopup(popup(place.label, [place.stale ? 'No recent update' : null]))
            .addTo(places);
    });
    if (data.phone) {
        L.circleMarker([data.phone.lat, data.phone.lng], { radius: 9, color: '#065f46', weight: 3, fillColor: '#10b981', fillOpacity: data.phone.stale ? 0.4 : 1 })
            .bindPopup(popup('You', [data.phone.stale ? 'No recent update' : null]))
            .addTo(places);
    }
    places.addTo(map);
    overlays['Places'] = places;

    L.control.layers(bases, overlays, { collapsed: true }).addTo(map);

    if (data.center && data.center.lat !== null) {
        map.setView([data.center.lat, data.center.lng], 8);
    } else {
        map.fitBounds([[24, -125], [50, -66]]);
    }
})();
</script>
</body>
</html>
```

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/MapPageTest.php`. Expected: PASS. (`view:clear` first.)

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add mobile/app mobile/resources/views/map mobile/tests
git commit -m "App: MapPage builds the Leaflet map page from a snapshot

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The Map screen, the Map tab, and removing the spike (waits for the device gate)

**Files (under `mobile/`):**
- Create: `app/NativeComponents/MapScreen.php`, `resources/views/native/map-screen.blade.php`
- Modify: `resources/views/native/partials/tabs.blade.php`, `routes/mobile.php`, `app/NativeComponents/SettingsScreen.php`, `resources/views/native/settings-screen.blade.php`
- Delete: `app/NativeComponents/MapSpikeScreen.php`, `resources/views/native/map-spike.blade.php`, `resources/views/map/spike.blade.php`, `tests/Feature/MapSpikeScreenTest.php`
- Test: `tests/Feature/MapScreenTest.php`; modify `tests/Feature/SettingsScreenTest.php` and the tab-bar assertions

**Interfaces:**
- Consumes: `WxNotifyApi::map()`, `LocalCache::map()/rememberMap()`, `MapPage::html()`.
- Produces: route `/map`; `MapScreen::refresh()`, `openWarning(int $index)` (opens `view_url` in the in-app browser), `openPlace(int $id)` (pushes `/locations/{id}`); the bottom bar becomes Alerts · Map · Settings.

- [ ] **Step 1: Write the failing test**

`mobile/tests/Feature/MapScreenTest.php`:

```php
<?php

use App\NativeComponents\MapScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function mapScreenSnapshot(array $overrides = []): array
{
    return [
        'center' => ['lat' => 40.0, 'lng' => -105.0, 'source' => 'phone', 'label' => 'Pixel 9'],
        'phone' => ['lat' => 40.0, 'lng' => -105.0, 'updated_at' => '2026-10-10T18:00:00+00:00', 'stale' => false],
        'places' => [
            ['id' => 1, 'label' => 'Home', 'type' => 'stationary', 'lat' => 40.1, 'lng' => -105.1, 'stale' => false],
            ['id' => 2, 'label' => 'Pixel 9', 'type' => 'mobile', 'lat' => 40.0, 'lng' => -105.0, 'stale' => true],
        ],
        'warnings' => [
            ['id' => 7, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000', 'headline' => 'Issued', 'expires' => '2026-10-10T19:00:00+00:00', 'view_url' => 'https://wx.test/view-product/abc', 'geometry' => ['type' => 'GeometryCollection', 'geometries' => []]],
            ['id' => 8, 'event' => 'Tornado Watch', 'family' => 'watch', 'color' => '#ffff00', 'headline' => null, 'expires' => null, 'view_url' => null, 'geometry' => ['type' => 'GeometryCollection', 'geometries' => []]],
        ],
        'layers' => [
            'basemaps' => [['key' => 'topo', 'label' => 'Topo', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16]],
            'radar' => ['wms_url' => 'https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows', 'layer' => 'conus_bref_qcd'],
            'attribution' => 'USGS The National Map · NOAA/NWS MRMS',
        ],
        'generated_at' => '2026-10-10T18:00:00+00:00',
        ...$overrides,
    ];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the map shows a web view with the page, the Near you list, and the Map tab as active', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    Native::test(MapScreen::class)
        ->assertNavTitle('Map')
        ->assertHasTab('Map')
        ->assertTabActive('Map')
        ->assertElement('webview', fn (array $node): bool => str_contains((string) ($node['props']['html'] ?? ''), 'L.map(') && ($node['props']['javascript'] ?? false) === true)
        ->assertSee('Near you')
        ->assertSee('Tornado Warning')
        ->assertSee('Tornado Watch')
        ->assertSee('Home')
        ->assertSee('No recent update');
});

test('a warning row opens its product page in the in-app browser, and one without a page is not tappable', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    Native::test(MapScreen::class)->tap('warning-0');

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/abc']);

    Native::test(MapScreen::class)->assertMissingElement('pressable', fn (array $p) => ($p['ref'] ?? null) === 'warning-1');
});

test('a place row opens its location screen', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    Native::test(MapScreen::class)->tap('place-1')->assertNavigatedTo('/locations/1');
});

test('without a phone position the screen suggests turning on location sharing', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot(['phone' => null, 'center' => ['lat' => 40.1, 'lng' => -105.1, 'source' => 'place', 'label' => 'Home']])])]);

    Native::test(MapScreen::class)->assertSee('Turn on location sharing in Account to center the map on this phone');
});

test('with nothing to show it says so and still shows the map', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot(['phone' => null, 'places' => [], 'warnings' => [], 'center' => ['lat' => null, 'lng' => null, 'source' => null, 'label' => null]])])]);

    Native::test(MapScreen::class)
        ->assertSee('No places to show yet')
        ->assertElement('webview');
});

test('offline it says the map needs a connection and lists the last snapshot', function () {
    (new LocalCache)->rememberMap(mapScreenSnapshot());
    $this->network->connected = false;

    Native::test(MapScreen::class)
        ->assertSee('The map needs a connection')
        ->assertSee('You\'re offline')
        ->assertSee('Tornado Warning')
        ->assertSee('Home')
        ->assertMissingElement('webview');
});

test('offline with nothing remembered it only says the map needs a connection', function () {
    $this->network->connected = false;

    Native::test(MapScreen::class)->assertSee('The map needs a connection')->assertDontSee('No places to show yet');
});

test('a failing server shows a message with Try again', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['message' => 'boom'], 500)]);

    Native::test(MapScreen::class)->assertSee('WxNotify could not load the map. Try again in a moment.')->assertSee('Try again');
});

test('Refresh refetches the snapshot, and the page only changes when the snapshot does', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    $screen = Native::test(MapScreen::class);
    $before = $screen->get('html');

    $screen->tap('refresh-map');

    expect($screen->get('html'))->toBe($before);
    Http::assertSentCount(2);
});

test('a signed-out visitor is sent to Sign in, and a rejected token signs out', function () {
    (new TokenStore)->forget();
    Native::test(MapScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/map' => Http::response([], 401)]);
    Native::test(MapScreen::class)->assertReplacedWith('/sign-in');
});
```

(If the harness has no `->get('html')`, read the public property through whatever accessor the existing tests use, for example `$screen->instance()->html` or an `assertSet('html', $before)`; keep the assertion that the HTML is identical after Refresh.)

Update the existing tests: in `tests/Feature/SettingsScreenTest.php`, remove the throwaway Map spike row expectations if any were added in Task 1 (there are none beyond the row itself), and in `tests/Feature/AlertsScreenTest.php` ('the bottom bar has Alerts and Settings') and `ScreensRenderTest.php` add `->assertHasTab('Map')` where the three tabs are asserted. Delete `tests/Feature/MapSpikeScreenTest.php` with the spike.

- [ ] **Step 2: Run the new tests to see them fail.**

- [ ] **Step 3: Implement**

`mobile/app/NativeComponents/MapScreen.php`:

```php
<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\MapPage;
use Illuminate\Support\Carbon;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * The Map tab: a Leaflet page in a web view (radar, the warnings and watches near the phone and the
 * places, the places and the phone), and a native "Near you" list that opens a warning's product page
 * or a place. The map page is display-only. Its HTML is rebuilt only when the snapshot changes, so
 * the web view is not reloaded (and pan and zoom not lost) by anything else.
 */
class MapScreen extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<string, mixed> the snapshot's list data (never the geometry) */
    public array $snapshot = [];

    /** The web view's page; null until a snapshot has been fetched this visit. */
    public ?string $html = null;

    public bool $offline = false;

    public bool $loaded = false;

    public ?string $error = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cached = app(LocalCache::class)->map();
        $this->snapshot = $cached ?? [];
        $this->loaded = $cached !== null;

        $this->refresh();
    }

    public function refresh(): void
    {
        try {
            $snapshot = app(WxNotifyApi::class)->map();
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');

            return;
        } catch (Offline) {
            $this->offline = true;
            $this->error = null;
            $this->html = null;

            return;
        } catch (Maintenance $e) {
            $this->offline = false;
            $this->error = $e->getMessage();

            return;
        } catch (ApiException) {
            $this->offline = false;
            $this->error = 'WxNotify could not load the map. Try again in a moment.';

            return;
        }

        app(LocalCache::class)->rememberMap($snapshot);

        $page = app(MapPage::class)->html($snapshot);

        if ($page !== $this->html) {
            $this->html = $page;
        }

        $this->snapshot = app(LocalCache::class)->map() ?? [];
        $this->offline = false;
        $this->error = null;
        $this->loaded = true;
    }

    public function openWarning(int $index): void
    {
        $url = $this->snapshot['warnings'][$index]['view_url'] ?? null;

        if (filled($url)) {
            Browser::inApp($url);
        }
    }

    public function openPlace(int $id): void
    {
        $this->navigate("/locations/{$id}");
    }

    /**
     * @param  array<string, mixed>  $warning
     */
    public function warningSubtitle(array $warning): string
    {
        return filled($warning['expires'] ?? null)
            ? 'Ends '.Carbon::parse($warning['expires'])->diffForHumans()
            : 'No end time given';
    }

    /**
     * @param  array<string, mixed>  $place
     */
    public function placeSubtitle(array $place): string
    {
        return $place['stale'] ? 'No recent update' : ($place['type'] === 'mobile' ? 'Current' : 'Saved place');
    }

    public function hasNothingToShow(): bool
    {
        return $this->loaded && ($this->snapshot['places'] ?? []) === [] && ($this->snapshot['warnings'] ?? []) === [] && ($this->snapshot['phone'] ?? null) === null;
    }
}
```

`mobile/resources/views/native/map-screen.blade.php`:

```blade
{{-- Un-prefixed on purpose: native:validate does not know <webview> or <pressable>. Handler arguments go through {{ }} so the validator skips them. --}}

<native:top-bar title="Map" display-mode="large"></native:top-bar>

@include('native.partials.tabs', ['active' => 'map'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:column class="w-full p-4 gap-1 bg-theme-surface">
            <native:text class="text-base text-theme-on-background">The map needs a connection</native:text>
            @if ($loaded)
                <native:text class="text-sm text-theme-on-surface-variant">You're offline. Showing what was near you when this was last loaded.</native:text>
            @endif
        </native:column>
    @endif

    @if ($error)
        <native:column class="w-full p-3 gap-2 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            <native:button label="Try again" variant="secondary" @tap="refresh" ref="retry-map" class="w-full" />
        </native:column>
    @endif

    @if ($html !== null)
        <webview :html="$html" javascript="true" dom-storage="true" class="w-full h-80" />
    @endif

    <native:scroll-view class="w-full flex-1 bg-theme-background">
        <native:column class="w-full p-4 gap-2">
            <native:row class="w-full justify-between items-center">
                <native:text class="text-lg font-bold text-theme-on-background">Near you</native:text>
                <native:button label="Refresh" variant="secondary" @tap="refresh" ref="refresh-map" />
            </native:row>

            @foreach (($snapshot['warnings'] ?? []) as $index => $warning)
                @if (filled($warning['view_url'] ?? null))
                    <pressable @tap="openWarning({{ $index }})" ref="warning-{{ $index }}" class="w-full">
                        <native:column class="w-full gap-1 p-2">
                            <native:text class="text-base font-semibold text-theme-on-background">{{ $warning['event'] }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->warningSubtitle($warning) }}</native:text>
                        </native:column>
                    </pressable>
                @else
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $warning['event'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->warningSubtitle($warning) }}</native:text>
                    </native:column>
                @endif
                <native:divider></native:divider>
            @endforeach

            @foreach (($snapshot['places'] ?? []) as $place)
                <pressable @tap="openPlace({{ $place['id'] }})" ref="place-{{ $place['id'] }}" class="w-full">
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $place['label'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->placeSubtitle($place) }}</native:text>
                    </native:column>
                </pressable>
                <native:divider></native:divider>
            @endforeach

            @if ($this->hasNothingToShow())
                <native:text class="text-sm text-theme-on-surface-variant">No places to show yet. Save a location or turn on location sharing in Account.</native:text>
            @endif

            @if ($loaded && ($snapshot['phone'] ?? null) === null)
                <native:text class="text-sm text-theme-on-surface-variant">Turn on location sharing in Account to center the map on this phone.</native:text>
            @endif
        </native:column>
    </native:scroll-view>
</native:column>
```

Update `partials/tabs.blade.php`: add between Alerts and Settings:

```blade
    <native:bottom-nav-item id="map" label="Map" url="/map" icon="map" :ios-icon="Ios::Map" :android-icon="Android::Map" :active="$active === 'map'"></native:bottom-nav-item>
```

In `routes/mobile.php` add `use App\NativeComponents\MapScreen;` and `Route::native('/map', MapScreen::class);`; REMOVE the `MapSpikeScreen` import and route. In `SettingsScreen.php` remove `openMapSpike()`; in `settings-screen.blade.php` remove the "Map spike (temporary)" `list-item`. Delete the spike files listed above (`git rm`).

- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/MapScreenTest.php tests/Feature/MapPageTest.php`, then the full app suite, `php artisan view:clear`, and `php artisan native:validate`. Expected: PASS and "All components passed validation". If `native:validate` rejects `<webview>` or `h-80`, report the exact message (BLOCKED).

- [ ] **Step 5: Format and commit**

```bash
cd mobile && vendor/bin/pint --dirty --format agent && cd ..
git add -A mobile/app mobile/resources mobile/routes mobile/tests
git commit -m "App: Map tab with the Leaflet page and a Near you list; remove the spike

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Whole-slice verification and notes (controller)

- [ ] **Step 1:** run the full backend suite (`vendor/bin/pest --parallel --processes=6` in the container) and the full app suite plus `native:validate`.
- [ ] **Step 2:** the controller runs the finished screen on the Android emulator and the iOS simulator against the dev server (after the owner pushes and rebuilds): the map opens on the phone (or a place), radar and base map load, pin and shape popups work, the layer control switches Topo and Imagery and toggles Radar, Warnings and Places, the "Near you" rows open the product page and the location screen, Refresh does not reset pan and zoom when nothing changed, offline shows the cached list.
- [ ] **Step 3:** write the memory note `mobile-map-state.md` (what exists, the gate result, traps, the device-check result, owner decisions) and index it in `MEMORY.md`.
