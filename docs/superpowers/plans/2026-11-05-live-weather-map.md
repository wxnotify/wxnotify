# Live Weather Map Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Live Weather" navigation group in the subscriber dashboard with a **Map** page: looping, auto-refreshing NOAA radar (national base reflectivity, plus site reflectivity and site velocity), the current short-fuse warnings (tap for a popup with a link to the product page), and layers for SPC watches, SPC convective outlooks and Mesoscale Discussions.

**Architecture:** One Filament page (`LiveWeather\Map`) in the dashboard panel with a Leaflet map (the pinned loader and `MapTiles` base tiles the dashboard already uses). Radar tiles load straight from NOAA's WMS in the browser; the backend only supplies the list of frame times (`GetRadarFrames`, cached 60 s). Warnings, watches, outlooks and discussions are read through public Livewire methods on the page and redrawn by plain JavaScript on a timer and when the viewer moves the map.

**Tech Stack:** Laravel 13 / PHP 8.4 / Filament v5 / Livewire / Leaflet 1.9.4 (existing loader) / Pest. No new dependency.

**Spec:** `docs/superpowers/specs/2026-11-05-live-weather-pages-design.md`

## Global Constraints

- Read `.ai/rules/index.md` and the rule files whose globs cover your paths (at least `filament.md`, `pages.md`, `support.md`, `pipeline.md`, `actions.md`, `feature.md`). `.ai/rules` is written only by the controller through `record-rule`.
- Backend tests run in the app container (start it with `docker compose up -d front-end pgsql redis` if down): `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php artisan test --compact <files>'`; Pint: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --dirty --format agent'`. Create files with `php artisan make:* --no-interaction` in the container; read the `testing-best-practices` skill; Pest helper functions need file-prefixed names; TDD with RED/GREEN evidence.
- Commit directly on `main`, one commit per task, message ending with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never push.
- Navigation: group `Live Weather`, page labelled `Map` (sort 1); every signed-in tier may open it. The page's data is public NWS/SPC data only; nothing about the viewer's own alerts, channels or other subscribers.
- NOAA services: fixed hosts only (`opengeo.ncep.noaa.gov`, `www.spc.noaa.gov`), no redirects followed, a site code is validated against the known site list before it reaches a URL, and an unavailable service is shown as "Radar is unavailable right now", never as "no rain".
- Short-fuse warnings are exactly Tornado, Severe Thunderstorm, Flash Flood, Special Marine, Extreme Wind, Snow Squall and Dust Storm Warnings (VTEC significance `W`, phenomena `TO SV FF MA EW SQ DS`). Test products and replays (`is_test`, `replay_of_id`) never appear.
- Radar loop: default 12 frames, selectable 6, 12, 24 or 36; frame list refreshed every 2 minutes; warnings refreshed every 30 seconds; the loop pauses while the tab is hidden and starts paused when the browser asks for reduced motion.
- All text from outside (event names, headlines, discussion text) is inserted into the page as text (DOM `textContent` or escaped Blade), never as HTML.

## Review Focus

Inputs and conditions the spec implies that no happy-path test would cover, most likely first (each gets a test in the owning task):

1. NOAA's WMS does not answer, returns a capabilities document with no time list, or a site layer does not exist: the page loads, says radar is unavailable, and the rest of the map still works (Task 2, Task 3).
2. A bounding box that is invalid (NaN, south ≥ north, outside ±90/±180, or the whole world): rejected or clamped, never an unbounded query (Task 4).
3. A large outbreak (more warnings than the cap): capped, with a "zoom in to see more" note, never a crash or a huge payload (Task 4).
4. A warning that expires while the page is open drops off on the next refresh; test products and replays never appear (Task 4).
5. A Mesoscale Discussion with no readable VALID line is shown for 3 hours after receipt and then hidden; an expired one is hidden (Task 5).

---

### Task 1: The Live Weather group and the Map page shell

**Files:**
- Create: `app/Filament/Dashboard/Pages/LiveWeather/Map.php`, `resources/views/filament/dashboard/pages/live-weather/map.blade.php`
- Modify: `app/Providers/Filament/DashboardPanelProvider.php` only if the panel needs the group declared to order it
- Test: `tests/Feature/LiveWeatherMapPageTest.php`

**Interfaces:**
- Produces: `App\Filament\Dashboard\Pages\LiveWeather\Map` (a `Filament\Pages\Page`; `navigationGroup` `Live Weather`, `navigationLabel` `Map`, `navigationSort` 1, a map icon, `title` `Live map`, slug `live-weather/map`; discovered automatically by `discoverPages`). The view renders a `wire:ignore` map container with a fixed minimum height, includes `filament.partials.leaflet-loader`, `map-helpers` and `map-tiles` (see `resources/views/filament/dashboard/widgets/traccar-devices-map.blade.php` for the init/destroy pattern), and a JSON config block for later tasks (`data-config` with empty `radar`, `warnings`, `layers` keys).

- [ ] **Step 1: Write failing tests**: any signed-in subscriber (create `User::factory()`, `actingAs`, `Filament::setCurrentPanel('dashboard')`) can render the page (`livewire(Map::class)->assertSuccessful()`); the page's `getNavigationGroup()`/`getNavigationLabel()` are `Live Weather`/`Map`; the panel's navigation lists the page under that group (read `Filament::getCurrentPanel()->getNavigation()` or the registered pages) ; a guest is redirected to the panel login.
- [ ] **Step 2: Run** and see them fail. **Step 3: Implement** the page and view (container, base tiles through `MapTiles::forFrontend()`, default view the whole United States `[39.83, -98.58]` zoom 4, centred on the viewer's first located saved place when there is one). **Step 4: Run** the tests and Pint.
- [ ] **Step 5: Commit** `Live Weather: the group and the Map page`.

### Task 2: Radar frames and the radar-site list

**Files:**
- Create: `app/Support/NexradSites.php`, `database/data/nexrad_sites.php`, `app/Actions/Weather/GetRadarFrames.php`
- Modify: `config/wxnotify.php` (extend `map.radar` with the site WMS URL template and the layer keys)
- Test: `tests/Feature/NexradSitesTest.php`, `tests/Feature/GetRadarFramesTest.php`

**Interfaces:**
- Produces:
  - `database/data/nexrad_sites.php`: `array<string, array{name: string, lat: float, lng: float}>` keyed by lower-case four-letter site id (kfws), generated once from `https://api.weather.gov/radar/stations?stationType=WSR-88D` (send a `User-Agent: WxNotify`; commit the file; it is static data).
  - `NexradSites::all(): array`, `NexradSites::exists(string $site): bool`, `NexradSites::nearest(float $lat, float $lng): ?string` (great-circle nearest, lower-case id).
  - `GetRadarFrames::__invoke(string $layer, ?string $site = null): array{layer: string, status: 'ok'|'unavailable', wms_url: ?string, wms_layer: ?string, times: list<string>}` where `$layer` is `composite` (URL and layer from `config('wxnotify.map.radar')`: `conus_bref_qcd`), `site_reflectivity` (`https://opengeo.ncep.noaa.gov/geoserver/{site}/ows`, layer `{site}_sr_bref`) or `site_velocity` (layer `{site}_sr_bvel`). It requests the WMS 1.3.0 `GetCapabilities`, reads the layer's `<Dimension name="time">` explicit list (ISO 8601 UTC strings, oldest first), caches a success for 60 seconds and a failure for 30 seconds per layer+site, and never throws. An unknown layer, an unknown site, a site with no such layer, a non-200, an unparsable body or a missing/empty time list is `unavailable` with no URL.

- [ ] **Step 1: Write failing tests** with `Http::fake`: a capabilities body with a 5-time list gives those five times, oldest first, the right WMS URL and layer name for each of the three layers; the second call within 60 s makes no request; a 500, a timeout (`ConnectionException`), an HTML body, a body with no time dimension and an unknown layer/site each give `unavailable` with empty times and no exception; a site not in the list is refused before any request; `NexradSites::nearest()` for Fort Worth returns `kfws` and for a point in the Pacific returns the nearest site, `exists()` is case-insensitive.
- [ ] **Step 2: Run** and see them fail. **Step 3: Implement** (parse with `simplexml_load_string` or `DOMDocument` with external entities and network access disabled; cap the number of times kept at 120). **Step 4: Run** and Pint.
- [ ] **Step 5: Commit** `Radar: frame times from NOAA's WMS and the radar-site list`.

### Task 3: The radar layers, loop controls and refresh

**Files:**
- Modify: `app/Filament/Dashboard/Pages/LiveWeather/Map.php`, `resources/views/filament/dashboard/pages/live-weather/map.blade.php`
- Create: `resources/views/filament/dashboard/pages/live-weather/map-radar.blade.php` (the radar script and controls, included by the page)
- Test: `tests/Feature/LiveWeatherMapPageTest.php` (add cases)

**Interfaces:**
- Consumes: `GetRadarFrames`, `NexradSites` (Task 2).
- Produces: public Livewire methods on the page: `radarFrames(string $layer, ?string $site): array` (the `GetRadarFrames` result, with the site validated; a bad value returns `unavailable`), `nearestRadarSite(float $lat, float $lng): ?string`, `radarSites(): array` (id and name for the picker, sorted by name). The page's initial config carries the default site (nearest to the viewer's first located place, else null) and the three layer keys. The view's JavaScript: a layer picker (Reflectivity national, Reflectivity site, Velocity site), a site picker (hidden for the national layer) with "Nearest to the map centre", and a control bar with play and pause, step back and forward, a scrubber labelled with each frame's time in the subscriber's timezone, speed (slow, normal, fast) and the frame count (6, 12, 24, 36; default 12). All chosen frames are added as WMS tile layers (`L.tileLayer.wms` with the frame's `time`, transparent PNG, opacity 0) and shown one at a time. Every 2 minutes it calls `radarFrames` again, keeps the viewer on the newest frame if they were on it, and leaves a paused viewer where they are. It pauses on `visibilitychange` hidden and refreshes at once when visible again, and starts paused when `prefers-reduced-motion` is set. A banner shows "Radar is unavailable right now" for `unavailable` and "Radar is N minutes old" when the newest frame is older than 15 minutes. A legend for the visible layer uses the WMS `GetLegendGraphic` URL. The choices (layer, site, frames, speed) are kept in `localStorage` (every read and write in try/catch).

- [ ] **Step 1: Write failing tests**: the Livewire methods return the `GetRadarFrames` shape for a good layer and `unavailable` for a bad layer or an unknown site; `nearestRadarSite()` returns the nearest id; `radarSites()` is sorted and lists id and name only; the rendered page carries the default site derived from the viewer's first located saved place (and null for a viewer with none) and the control markup (play, step, speed, frame-count select with exactly 6, 12, 24 and 36, layer and site pickers); no radar request is made while rendering the page (the browser asks later).
- [ ] **Step 2: Run** and see them fail. **Step 3: Implement** the methods, the page config and the script (follow the init/destroy pattern in the Traccar map widget: global functions defined once, `destroy()` clears timers and removes the map). **Step 4: Run** the tests and Pint.
- [ ] **Step 5:** controller check: load the page in the browser pane with a test subscriber on the local server, confirm the three layers draw and loop, the refresh timer re-reads frames, and the unavailable banner shows when the radar host is blocked (note in the report what could not be checked).
- [ ] **Step 6: Commit** `Live map: radar layers with loop controls and auto-refresh`.

### Task 4: Short-fuse warnings

**Files:**
- Create: `app/Actions/Map/GetLiveWarnings.php`, `app/Support/LiveWarningKinds.php`
- Modify: `app/Actions/Map/GetMapWarnings.php` (only to share the zone and polygon resolution), `app/Support/WarningColors.php`, the Map page and view
- Create: `resources/views/filament/dashboard/pages/live-weather/map-warnings.blade.php`
- Test: `tests/Feature/GetLiveWarningsTest.php`, `tests/Feature/GetMapWarningsTest.php` (must stay green), `tests/Feature/LiveWeatherMapPageTest.php` (add cases)

**Interfaces:**
- Produces:
  - `LiveWarningKinds`: `SHORT_FUSE` (phenomena `TO SV FF MA EW SQ DS`, significance `W`) and `WATCHES` (phenomena `TO SV`, significance `A`, SPC-issued watches), plus a helper listing the kinds' event names for filter chips.
  - `GetLiveWarnings::__invoke(array $bounds, string $kind): array{events: list<array{id: int, event: string, family: string, color: string, headline: ?string, expires: ?string, view_url: ?string, geometry: array<string, mixed>}>, truncated: bool}`: `$bounds` is `{south, west, north, east}` degrees. Active, unexpired, non-test areas whose geometry intersects the box; the same payload shape and per-event geometry de-duplication as `GetMapWarnings`; ordered by expiry soonest first; at most `config('wxnotify.map.max_live_warnings')` (new, default 150) events, `truncated` true when more matched. Invalid bounds (non-finite, outside ±90/±180, south ≥ north, or a box wider than 120 degrees or taller than 60) are clamped to those limits, never queried unbounded.
  - The zone/polygon resolution SQL that `GetMapWarnings` already has is extracted to one shared place and used by both actions (no copied SQL); `GetMapWarnings`' output and its performance profile do not change and its tests stay green unmodified.
  - `WarningColors::BY_EVENT` gains `Snow Squall Warning` (`#c71585`) and `Dust Storm Warning` (`#ffe4c4`).
  - Page methods `liveWarnings(array $bounds, string $kind): array` (validates `$kind` against the two kinds).
- The view's script: draws the events as coloured GeoJSON polygons, refreshes every 30 s and (debounced 400 ms) when the map stops moving, and a collapsible side panel (a drawer on small screens) lists the short-fuse warnings sorted by expiry with filter chips (one per event name, all on by default). Tapping a polygon or a list row opens a popup (event, headline, expiry in the subscriber's timezone) with a "View product" link (`view_url`, new tab, only when present and `https`); a list row first flies to its polygon. A "Zoom in to see all warnings" note shows when `truncated`. A warning absent from the next refresh is removed and its popup closed.

- [ ] **Step 1: Write failing tests** (seed `active_wwas` and `active_wwa_areas` like `GetMapWarningsTest` does): events of the seven phenomena in the box are returned and others (a Flood Warning, an advisory, a watch for kind `SHORT_FUSE`) are not; an event outside the box, expired, inactive or `is_test` is not; the cap sets `truncated`; invalid or huge bounds are clamped (assert the executed query count and that the result does not contain events far outside the clamped box); the Snow Squall and Dust Storm colours; `WATCHES` returns only Tornado and Severe Thunderstorm Watches; the page method rejects an unknown kind; `GetMapWarnings` tests unchanged and green.
- [ ] **Step 2-4:** red, implement (including the SQL extraction), green, Pint. **Step 5:** controller browser check as in Task 3. **Step 6: Commit** `Live map: current short-fuse warnings with popups and a list`.

### Task 5: SPC watches, convective outlooks and Mesoscale Discussions

**Files:**
- Create: `app/Actions/Map/GetActiveMesoscaleDiscussions.php`, `app/Support/SpcOutlookColors.php`
- Modify: the Map page and view; create `resources/views/filament/dashboard/pages/live-weather/map-layers.blade.php`
- Test: `tests/Feature/GetActiveMesoscaleDiscussionsTest.php`, `tests/Feature/SpcOutlookColorsTest.php`, `tests/Feature/LiveWeatherMapPageTest.php` (add cases)

**Interfaces:**
- Consumes: `GetLiveWarnings` with kind `WATCHES` (Task 4); `FetchSpcOutlooks` (existing; keys `day_1`..`day_3`, layer `cat`, features `{label, geometry}` with labels `TSTM MRGL SLGT ENH MDT HIGH`).
- Produces:
  - `SpcOutlookColors::for(string $label): string` (`TSTM #c1e9c1`, `MRGL #66c57d`, `SLGT #f6f67b`, `ENH #e6b366`, `MDT #e67f7f`, `HIGH #ff7cff`, anything else `#6b7280`).
  - `GetActiveMesoscaleDiscussions::__invoke(): list<array{id: int, number: ?string, areas: ?string, expires: string, view_url: ?string, geometry: array<string, mixed>}>`: non-test, non-replay ingested products of the catalog product `SPC.MD` received in the last 12 hours that have a polygon (`ingested_products.polygon`, a list of `{lat, lon}` points); `number` from "MESOSCALE DISCUSSION 1572", `areas` from the "AREAS AFFECTED..." line (trimmed, at most 160 characters); `expires` from the text's "VALID ddhhmmZ - ddhhmmZ" end (day-of-month resolved against the month of `received_at`, rolling into the next month when it would be in the past), else `received_at` plus 3 hours; expired discussions are dropped; the polygon becomes a GeoJSON Polygon (lng, lat order, ring closed).
  - Page methods `spcOutlook(int $day): array{available: bool, issued_at: ?string, features: list<array{label: string, color: string, geometry: array<string, mixed>}>}` (day 1 to 3, else unavailable; uses `FetchSpcOutlooks`; an unavailable day is `available: false`, never "no storms") and `mesoscaleDiscussions(): array`.
- The view's script adds the layer control: SPC watches (the `WATCHES` kind, refreshed with the warnings), SPC Convective Outlook (a Day 1/2/3 selector, filled polygons with the category colours and a small legend, refreshed every 5 minutes) and Mesoscale Discussions (outlined polygons with a popup: number, areas, expiry, "View product" link), each off by default except the warnings; the layer choices are kept in `localStorage`.

- [ ] **Step 1: Write failing tests**: MD: a fresh discussion with a VALID line returns its number, areas and the right expiry (including a month rollover); one with no VALID line is shown until received + 3 hours and then hidden; an expired, test, replayed or polygon-less one is hidden; `number` and `areas` are null when absent; outlook: a day with features returns coloured features, a failed day is `available: false`, day 4 or 0 is unavailable (fake `FetchSpcOutlooks` through `Http::fake` of the SPC URLs, or bind a fake); the colours; the page renders the layer control markup.
- [ ] **Step 2-4:** red, implement, green, Pint. **Step 5:** controller browser check. **Step 6: Commit** `Live map: SPC watches, convective outlooks and Mesoscale Discussions`.

### Task 6: Polish, legend, accessibility and the full check

**Files:** the Map page view and scripts; tests as needed.

- [ ] **Step 1:** review the page at phone width and in dark mode in the browser pane: controls wrap and stay reachable, the warnings list is a drawer, popups are readable in both themes, the legend does not cover the controls; fix what is wrong.
- [ ] **Step 2:** keyboard and screen-reader pass: every control is a real button or select with a name (the radar play/pause button's name changes with its state), the scrubber is an `input type=range` with an `aria-valuetext` of the frame's time, banners use `role="status"`; add a markup test for the names.
- [ ] **Step 3:** run the full backend suite in parallel (`docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6'`) and Pint; fix only what this work broke.
- [ ] **Step 4: Commit** `Live map: polish, accessibility names and a full-suite pass`.

## Controller steps after the tasks (not for implementers)

- Final whole-branch review on the most capable model; record rules with `record-rule` (the NOAA radar frame cache and fixed hosts and site validation; the short-fuse kinds and bounding-box clamp; the Mesoscale Discussion expiry rule; "Live weather page data is public only").
- Rollout: no migration and no environment change; the page needs the dashboard assets as they are.
