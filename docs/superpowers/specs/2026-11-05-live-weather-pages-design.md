# Live Weather pages (Map and Products) — design

## Why

The dashboard is about setting up alerts; a subscriber on a severe weather day has nowhere on the web to look at what is happening right now. The owner wants a "Live Weather" navigation group with two pages, **Map** and **Products**:

- **Map:** radar that loops and refreshes itself, with base reflectivity and velocity layers; the current short-fuse warnings, refreshed automatically, that open on tap with a link to the product's page; and layers for SPC watches, SPC convective outlooks and Mesoscale Discussions.
- **Products:** a live list of incoming products, pushed over WebSockets (Reverb) instead of a table that reloads on a timer.

The mobile app already has its own Map tab (`GET /api/v1/map`, `GetMapWarnings`, an NOAA MRMS radar layer, USGS base maps). This work is the web dashboard's counterpart and reuses what that built.

## What I checked in the code and at NOAA

- **Leaflet is already on the dashboard**, pinned and integrity-checked (`resources/views/filament/partials/leaflet-loader.blade.php`), with admin-configurable base tiles (`App\Support\MapTiles`) and a tile fallback partial.
- **Warnings and watches are already map-ready**: `App\Actions\Map\GetMapWarnings` returns active events (families warning and watch) with simplified GeoJSON, colours (`WarningColors`), headline, expiry and `view_url`. It is bounded by points and a 150-mile radius today.
- **Radar config exists**: `config('wxnotify.map.radar')` is NOAA's MRMS composite base reflectivity WMS (`conus_bref_qcd`).
- **The radar service supports a loop**: I fetched its capabilities. The national composite exposes a TIME dimension of about 60 frames over about 2 hours (roughly 2 minutes apart). A single radar site (for example `kfws`) exposes `kfws_sr_bref` and `kfws_sr_bvel` with about 22 frames over about 2 hours (roughly 5 minutes apart).
- **Velocity exists only per radar site.** There is no national velocity composite, so the Velocity layer needs a radar site.
- **Reverb is not installed.** There is no `laravel/reverb`, no Echo, no `config/broadcasting.php`, and `BROADCAST_CONNECTION=log`. The Products page needs new infrastructure (see "Needs the owner's approval").

## What the owner decided

- **Navigation:** a "Live Weather" group in the dashboard panel with two pages named **Map** and **Products**.
- **Map content** and **Products behaviour** as listed above.

## My calls (not yet confirmed by the owner)

**Both pages**
- Filament pages in the dashboard panel, group `Live Weather`, Map first, Products second (slugs `live-weather/map` and `live-weather/products`). Available to every signed-in tier: the data is public NWS data.

**Map**
- **Layout:** a full-width map. A collapsible side panel lists the current short-fuse warnings. A layer control sits top right. A radar control bar sits under the map: play and pause, step back and forward, a scrubber with each frame's time in the subscriber's timezone, speed, and the number of frames (6, 12, 24 or 36; default 12).
- **Radar layers:** `Reflectivity (national)` is the composite (`conus_bref_qcd`); `Reflectivity (site)` is `{site}_sr_bref`; `Velocity (site)` is `{site}_sr_bvel`. The two site layers share one radar-site picker. The default site is the one nearest the subscriber's first saved place, else a picker with no default. Tiles load straight from NOAA in the browser, as the app does.
- **Frame list:** the backend reads NOAA's capabilities (cached 60 seconds) and returns the list of frame times, so the browser never parses XML. The site code is validated before any request, and the host is fixed.
- **Looping:** all chosen frames are preloaded as tile layers and shown one at a time. The loop pauses when the browser tab is hidden and respects "reduce motion" (starts paused).
- **Auto-refresh:** frame times are re-read every 2 minutes (the national composite updates about that often; site layers about every 5 minutes). If the viewer is on the newest frame it stays on the newest; a paused viewer is not moved. A banner says "Radar is N minutes old" when the newest frame is older than 15 minutes, and "Radar is unavailable right now" when the service does not answer.
- **Short-fuse warnings:** Tornado, Severe Thunderstorm, Flash Flood, Special Marine, Extreme Wind and Snow Squall Warnings, with filter chips. They come from the active warnings the app already stores, for the part of the map the viewer is looking at, refreshed every 30 seconds. A new action based on `GetMapWarnings` takes a bounding box and an event filter instead of points and a radius. Tapping a polygon or a list row opens a popup (event, headline, expiry) with a "View product" link to the public product page; a list row also flies to its polygon.
- **SPC watches:** the active watch events (families watch, issued by SPC) from the same action.
- **SPC convective outlooks:** Day 1, Day 2 and Day 3 categorical outlooks from the data `FetchSpcOutlooks` already fetches and caches, with a day selector and the standard category colours.
- **Mesoscale Discussions:** the active SPC Mesoscale Discussions with their polygons from our ingested products. The pipeline stores no expiry for them, so the build adds one read from the discussion's own "VALID …Z – …Z" line. The popup shows the discussion number, areas affected and a link to its page.
- **Not remembered between visits** except the subscriber's last layer choices (browser storage). A legend for the visible radar layer is shown.

**Products**
- **Source:** a broadcast event when a product is stored by the pipeline, on a private channel (`live-products`) that any signed-in subscriber may join. The data is public, but the page is not for anonymous visitors.
- **Payload:** only what the row shows: id, product name and code, issuing office, issued time, a one-line headline, and the public page slug. No raw product text.
- **The page:** a Livewire component that loads the latest 50 products and prepends each broadcast one. It keeps at most 100 rows. When the viewer has scrolled down, new rows are counted ("3 new products") instead of shifting the list.
- **Filters:** product categories only for now (warnings and watches, outlooks and discussions, everything else). "Only products near my places" is a later addition, because matching a product to places is the expensive part.
- **Reliability:** when the socket drops, the component polls once a minute and fills the gap by id when it reconnects; the row list never shows a duplicate.

## Needs the owner's approval before it is built

1. **Installing Reverb:** the Products page needs `laravel/reverb` (Composer), `laravel-echo` and `pusher-js` (npm), a new `reverb` service in `compose.yaml` (`php artisan reverb:start`, scaled on Redis), the `REVERB_*` environment values, `BROADCAST_CONNECTION=reverb`, a WebSocket route on the web proxy, and TLS for `wss://`. This changes the project's dependencies and the deployment, so it needs approval first. The Map page needs none of it.
2. **NOAA services:** the radar comes from a free public NOAA server (no key, no service guarantee). If it is down the page says so. Confirm that is acceptable.

## Goals

1. A subscriber sees live radar, current short-fuse warnings and the SPC layers on one page, and a live product feed on the other.
2. Nothing on the pages needs a manual reload.
3. The Map page works without Reverb, so it can ship first.

## Non-goals and anti-goals

- No storm tracks, lightning layer, rain forecast, or editing alert rules from the map.
- No hosting or storing of radar imagery: tiles come from NOAA.
- No change to the mobile app, its Map tab or the API.
- Must not hammer NOAA: the frame list is cached for 60 seconds and shared, and hidden tabs pause.
- A broadcast problem must never slow or fail product ingestion: the event is queued and its failure is logged, not thrown.

## Constraints

- Filament v5 pages in the dashboard panel; follow `.ai/rules/filament.md`, `pages.md` and `support.md`; no new Composer or npm dependency without approval (Reverb, Echo and pusher-js are the listed exception, once approved).
- Warning data is public and read only; never include another subscriber's data. Responses carry no personal data.
- The dashboard's Content-Security-Policy must allow NOAA's WMS host for images; the build checks it.
- The Map page reuses the pinned Leaflet loader and the `MapTiles` base tiles; no CDN link outside that.

## Left to the builder

The page and component class names, the JSON endpoint shape and routes for frames and warnings, how the browser code is packaged, how many warnings a bounding box returns before it is capped, the radar-site list and how "nearest site" is found, the colour tables and legend images, the Mesoscale Discussion expiry parser, the broadcast event and channel names, and the tests (including channel authorization and the polling fallback).

## Edge cases the design must cover

- No frames, a service error, a frame list that changes mid-loop, a site with no radar layer.
- A subscriber with no saved places: the map opens on the whole United States and the site picker has no default.
- Many warnings at once (a large outbreak): capped, with a "zoom in to see more" note.
- A warning that expires while the page is open: it drops off on the next refresh and its popup closes.
- A Mesoscale Discussion with no readable VALID line: shown for a default of 3 hours after receipt, never forever.
- A hidden tab, a returning tab (refresh at once), a dropped socket, a duplicate broadcast.
- Test products and replays (`is_test`, `replay_of_id`) never appear on either page.
- Dark mode and small screens: the controls wrap and stay reachable; the warning list becomes a drawer.
