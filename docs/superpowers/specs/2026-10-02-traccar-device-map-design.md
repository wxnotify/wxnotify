# Traccar Servers Page — Device Map — Design

Source: owner request — the Traccar Server list page needs a Leaflet map above the table showing all Traccar devices across all of the subscriber's defined Traccar servers. The map is displayed even when no servers are defined. Clicking a device marker (red by default) opens a pop-up with its server name, device name, and last check-in timestamp. A device whose location is stale gets a different color (e.g. gray).

## Goal

A full-width map above the Traccar Servers table that plots every device, on every one of the subscriber's Traccar servers, that has a position; red when the device has checked in recently, gray when its last check-in is stale; a click shows server, device and last check-in. It loads without ever slowing or breaking the page, works with zero servers, and never exposes a Traccar API token to the browser.

## Decisions (user-approved, from brainstorming)

- **Data source: live fetch after page load, cached 60 seconds per server.** The map renders immediately; a Livewire call then fetches the devices. Rejected: fetching while rendering the page (one dead server would make the whole page wait up to 10 s per server) and storing every device in a new table via the poller (stores data for devices the subscriber never linked to a Location, needs a migration/retention, and is only as fresh as the poll interval).
- **"Last check-in" = the device's `lastUpdate`** (Traccar's "last time the server heard from this device"), falling back to the position's `serverTime` when `lastUpdate` is null. **Stale is judged on the same time.** A device that stopped reporting turns gray even though its last coordinates are still plotted. (Rejected: GPS `fixTime` — a parked tracker that keeps checking in with an old fix would look stale.) The popup shows only the last check-in.
- **Stale threshold: a fixed 1 hour** in code (a named constant, easy to turn into a setting later). A device with no known check-in time at all is treated as stale.
- **Component: a Filament header widget on `ListTraccarServers`** (`getHeaderWidgets()`), full width, rendering above the table. It has its own Livewire component for the data call, so no new route is needed.
- **Leaflet with no new dependency.** Leaflet 1.9.4 is loaded lazily from the same CDN the admin shapefile map already uses (`resources/views/filament/admin/pages/shapefile-layer-map.blade.php`), with OpenStreetMap tiles and the same default view (continental US, `[39.8283, -98.5795]`, zoom 4). The admin page is left untouched; the new widget gets a small shared loader partial modelled on it.
- **Markers are SVG pins** (`L.divIcon`, no image assets): red `#dc2626` by default, gray `#9ca3af` when stale.
- **Auto-refresh every 60 seconds** (matches the cache), updating markers in place; the map fits its bounds to the markers on the first load only, so a refresh never moves the subscriber's view or closes an open popup unnecessarily.
- **Scope**: only the signed-in subscriber's own servers, on this page only.

## Verified against the codebase

- Leaflet is already used with no npm/composer dependency: the admin shapefile map lazy-loads Leaflet 1.9.4 from `unpkg.com`, and the Locations form's `Pinpoint` field uses a Leaflet provider.
- The app stores positions only for devices linked to a mobile `Location` (`traccar:poll-positions` updates just those); `ListTraccarDevices` returns only `id` and `name`; `PollTraccarServer` parses `deviceId`/`latitude`/`longitude`/`fixTime` from `GET /api/positions` (no parameters = last known positions of every device). Showing *all* devices therefore needs a live fetch of `GET /api/devices` (for `name`, `lastUpdate`, `disabled`) plus `GET /api/positions`, joined by device id.
- Requests authenticate with the server's long-lived Bearer token (`api_token`, encrypted cast), `rtrim(base_url, '/')`, 10 s timeout today.
- `users` have no timezone setting, so the popup time is formatted in the viewer's browser locale.
- `TraccarServerForm`'s `base_url` is validated only with `->url()`; there is **no private-address (SSRF) guard** on any Traccar request today (poller and device lister included). See Security.

## Data

### `App\Actions\Traccar\GetTraccarDeviceMap` (new)

`__invoke(Collection|array $servers): array` — returns:

```php
[
    'devices' => [
        ['server' => 'Fleet', 'key' => '12:1', 'name' => 'Truck 1', 'lat' => 39.74, 'lng' => -104.99,
         'last_checkin' => '2026-10-02T17:15:00+00:00'|null, 'stale' => false],
        …
    ],
    'unplaced' => 2,                    // devices on reachable servers with no position yet
    'unreachable' => ['Server B'],      // labels of servers that failed or returned malformed data
]
```

Two deltas to this shape were made during implementation. Each device entry carries a `key` (`"{server id}:{Traccar device id}"`), the stable identity the widget uses to update markers in place across refreshes (a device id alone is not unique across servers). And the widget's `devices()` payload adds `has_servers` (bool, whether the subscriber has any server at all), so the caption can tell "no servers defined" from "servers defined, nothing plottable".

- **Per server**: `GET {base_url}/api/devices` and `GET {base_url}/api/positions`, Bearer token, short timeout (**5 s**, tighter than the poller's 10 s because a viewer is waiting), no redirects followed. Servers are fetched **in parallel** (`Http::pool`) so total time is the slowest server, not the sum.
- **Join**: positions keyed by `deviceId`; each device with a numeric `latitude`/`longitude` position becomes a map entry. A device with no position counts toward `unplaced`. A **disabled** device (`disabled: true`) is skipped entirely.
- **Validation** mirrors the existing actions' hardening: a non-array body, or any malformed device/position entry, voids **that server's** result (it is reported in `unreachable`); other servers are unaffected. A server whose token is rejected (401/403) or unreachable is likewise `unreachable`. Logs use **fixed strings** plus `traccar_server_id` and an HTTP status only — never an exception message, URL, or token.
- **Cache**: each server's normalized device list (everything except `stale`) is cached for **60 seconds** under a key containing the server id **and its `updated_at`**, so editing a URL or token takes effect immediately. A failed fetch is **not** cached (the next tick retries). `stale` is computed on every call from `now()` against `last_checkin` (> 1 hour old, or null), so a cached entry never serves an out-of-date flag by more than the cache TTL.
- `last_checkin` is an ISO-8601 string (plain data, no Carbon); `lat`/`lng` are floats.

### Staleness constant

`GetTraccarDeviceMap::STALE_AFTER_MINUTES = 60`.

## The widget

`App\Filament\Dashboard\Resources\TraccarServers\Widgets\TraccarDevicesMapWidget` (Filament `Widget`, `$columnSpan = 'full'`, `$view` its own Blade):

- Registered via `ListTraccarServers::getHeaderWidgets()`.
- A public Livewire method `devices(): array` authorizes implicitly by loading **only** `auth()->user()`'s servers, calls `GetTraccarDeviceMap`, and returns the structure above. It is called from Alpine with `await $wire.devices()` — the API token never appears in the payload.
- The Blade view: a map container (`wire:ignore`, fixed height ≈ 380 px, rounded border) and a small caption area underneath. An Alpine component initialises Leaflet once (via the shared loader), calls `$wire.devices()` immediately and then every 60 s, and on teardown (`destroy()`, including Livewire SPA navigation away) clears the interval and removes the map.
- **Rendering the pop-up**: built with DOM nodes and `textContent` — never `innerHTML` — because server and device names are external strings. Content: "Server: {server}", "Device: {name}", "Last check-in: {absolute time} · {relative}" (e.g. "Oct 2, 11:15 AM · 5 min ago", via `Intl.DateTimeFormat` in the viewer's locale and `Intl.RelativeTimeFormat`), or "Last check-in: unknown" when `last_checkin` is null. A stale device's popup also shows "No check-in for over an hour".
- **Markers**: an SVG `L.divIcon` pin per device; red or gray by `stale`. Re-rendered on each refresh by id (server+device) so an open popup survives a refresh when its device still exists.
- **View**: on the first successful load, `fitBounds` to all markers (padding, `maxZoom` 12 so a single device doesn't zoom to street level); with no markers, the default US view. Later refreshes never change the view.

### Caption states (below the map)

- No servers defined: "Add a Traccar server to see its devices here."
- Servers defined, no plottable devices: "No devices with a position yet."
- `unplaced > 0`: "{n} device(s) have no position yet."
- `unreachable` non-empty: "Couldn't reach: Server A, Server B." (labels only, escaped).
- A failed `devices()` call (network error between browser and app): keep the last markers, show "Couldn't refresh the map." — never an exception in the console loop.

## Security

- Tokens, URLs and raw Traccar payloads never reach the browser; only server label, device name, coordinates, `last_checkin` and `stale`.
- All external strings are rendered with `textContent` (pop-up and caption) — no HTML injection from a hostile or misconfigured Traccar server.
- Every query is scoped to `auth()->user()`'s own servers.
- **Flagged, not changed here:** subscriber-supplied `base_url` is fetched server-side with no private-address guard — pre-existing for the poller and `ListTraccarDevices`. This feature adds a fetch on every page view (with a short response, parsed into a narrow shape, redirects disabled in the new action), so it widens that surface slightly but exposes no response bodies. The recommended follow-up is one `TraccarUrlGuard` (block private/reserved hosts, credentials in the URL, and redirects — but, unlike `WebhookUrlGuard`, allow plain `http`, since self-hosted Traccar is commonly `http://host:8082`) applied at form-save and request time to all three Traccar actions. That is a separate change; the owner decides whether it ships with this one.
- The agreed `TraccarUrlGuard` follow-up should apply at save (Create and Edit, since only Create probes the server today) and at request time to all three Traccar actions, with redirects disabled in each. It should carry over the DNS-vs-HTTP-client TOCTOU note from `.ai/rules/delivery.md` (pinning via `CURLOPT_RESOLVE` is the eventual fix). It can hook into the per-server pre-flight step that `GetTraccarDeviceMap` already runs before building its request pool, so a blocked host lands in `unreachable` like any other misconfigured server.
- A device reporting exactly (0, 0) ("Null Island", a common no-fix placeholder) is currently plotted like any other position; hiding or flagging those is a possible follow-up.
- A server that is misconfigured (unparseable `base_url`, e.g. a typo'd port on the unprobed Edit form, or an `api_token` that no longer decrypts) is caught in that pre-flight step, logged with a fixed string and its id only, and reported in `unreachable`; it never fails the whole request. Out-of-range or non-finite coordinates, and non-scalar or non-integer-like ids and names, void that server's result as malformed.

## Non-goals

- Device trails/history, geofences, clustering, per-server colors, editing devices from the map.
- Showing devices anywhere other than this page; the admin panel.
- Changing how Traccar-linked mobile Locations or the poller work.
- A subscriber-configurable stale threshold (a constant for now).

## Edge cases

- **No servers**: map shown at the default view; the data call returns empty without any HTTP request.
- **One slow/dead server**: the others still plot; the dead one is named in the caption; total latency is bounded by the 5 s timeout.
- **Device with no position**: not plotted, counted in "no position yet". **Disabled device**: ignored.
- **`lastUpdate` null**: falls back to the position's `serverTime`; if both are null → "unknown" and gray.
- **Two devices at identical coordinates**: markers overlap (no clustering); both popups reachable by clicking the top one then the other after zooming — acceptable for v1.
- **Server edited mid-session**: the `updated_at` in the cache key means the next refresh uses the new URL/token.
- **Rapid navigation away**: interval cleared and the map removed so no timers leak across Livewire SPA navigations.

## Testing

Feature tests with `Http::fake()` (no real network):

- `GetTraccarDeviceMap`: devices joined to positions across two servers; the stale boundary (exactly 1 hour = not stale, 1 hour + 1 s = stale; null check-in = stale); `lastUpdate` null falls back to position `serverTime`; no position → counted in `unplaced`; a disabled device is skipped; one server returning a 500 / malformed body / non-numeric coordinates → listed in `unreachable` while the other still plots; 401 → unreachable; the API token never appears in the returned structure; per-server caching (second call sends no requests; changing the server's `updated_at` busts it; a failed fetch is not cached); parallel fetch (both servers' requests were sent); logs contain only fixed strings.
- Widget (Livewire): `devices()` returns only the signed-in user's servers' devices (another subscriber's server never appears); zero servers returns the empty structure with no HTTP request.
- Page: `ListTraccarServers` renders the widget (header widget present, map container and `wire:ignore`) with zero servers and with servers.
- Real-browser verification (by hand, not a Pest test): a throwaway stub Traccar server serving `/api/devices` and `/api/positions` with one fresh and one stale device; confirm red and gray markers, the pop-up text, the zero-server view, the unreachable-server caption, and that a refresh keeps the view.

Run the affected tests, Pint, then the full suite inside the app container.

## Open items for the plan (not design questions)

- Exact Livewire/Alpine wiring for `await $wire.devices()` inside a Filament widget view and the teardown on SPA navigation (the widget view is the riskiest file; verify in the browser).
- Where the shared Leaflet loader partial lives (`resources/views/filament/partials/leaflet-loader.blade.php` is the suggestion) and whether the admin map should adopt it later.
- ~~Traccar field names~~ — confirmed against Traccar's `openapi.yaml` (2026-10-02): `Device` has `id`, `name`, `disabled` (boolean), `lastUpdate` (ISO 8601 string or null) and `positionId`; `Position` has `deviceId`, `latitude`, `longitude`, `fixTime`, `deviceTime` and `serverTime`.
