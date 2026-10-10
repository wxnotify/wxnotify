# Mobile Map (the Map tab) — Design

Source: owner request — slice 5 of the mobile app (Phase 6): "Map: current-location pin plus radar, warnings and outlook layers" (`2026-10-04-mobile-app-foundation-design.md`). The bottom bar already has room for it (Alerts · Settings today, five tabs maximum). Outlooks are deferred; this slice is places, radar and active warnings.

## Goal

A subscriber opens a Map tab and sees, centered on their phone, the weather radar, the active warnings and watches near them and their saved places, and where those places are. A native list under the map names what is near them and opens it.

## Decisions (user-approved, from brainstorming)

- **Approach: a web view running Leaflet.** The app has no native map: the installed `nativephp/mobile-ui` has no map element and the NativePHP v4 marketplace has no map plugin. Rejected: a custom native plugin (MapKit on iOS, Google or MapLibre on Android: two native implementations, an Android key and billing, a slice of its own); a static radar image (no pan, zoom or warning shapes). The server-provided snapshot keeps a later native map possible without an API change.
- **Layers in this slice: My places, Radar, Active warnings.** SPC outlooks are a later slice.
- **Opening view: the phone's own position,** falling back to the first saved place with a position, then a US-wide view.
- **Warnings drawn: those near the phone and the saved places** (within 150 miles of any of them), warning and watch families only. Rejected: everything nationwide (heavy); near-me with a nationwide switch (the switch can come later).
- **Base map: USGS National Map** (Topo, with Imagery as a second choice): free US government tiles, public domain, no key, US coverage only, no dark theme. Rejected: OpenStreetMap's public tiles (its policy forbids heavy app use); a paid provider (key and monthly cost).
- **Radar: NOAA's public MRMS composite reflectivity** (`https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows`, layer `conus_bref_qcd`, WMS, continental US), the latest frame, refreshed by the page every two minutes while it is open. Both the USGS tile services and this WMS answered a test request during brainstorming.
- **The map page is display-only; actions live in a native list.** Tapping a pin or a shape shows a popup (name, expiry). Opening a warning's product page or a place's screen is done from the native "Near you" list under the map, because a web view has no dependable way to call back into PHP.
- **Leaflet 1.9.4 is bundled into the app** (BSD-2, the version the dashboard already loads) instead of coming from a CDN: no third-party script at runtime and no integrity-pin to maintain.

## Backend

One new endpoint in the existing `/api/v1` app-token group (`auth:sanctum`, `ability:app`, `ExtendAppTokenExpiry`, `throttle:app-api`), returning an Eloquent API Resource, scoped to the token's user (`.ai/rules/api.md`). The action is `GetMapSnapshot` (`app/Actions/Map/`).

### `GET /api/v1/map`

```
data: {
  center:   { lat, lng, source: "phone" | "place" | null, label },
  phone:    { lat, lng, updated_at, stale } | null,
  places:   [ { id, label, type, lat, lng, stale } ],
  warnings: [ { id, event, family, headline, expires, view_url, geometry } ],
  layers:   { basemaps: [ { key, label, url, max_zoom } ], radar: { wms_url, layer }, attribution },
  generated_at
}
```

- **The phone** is the Location kept current by this app token (`app_token_id` = the token's id), the same link `GET /locations` reports as `linked`. `phone` is null when sharing is off, the phone has no fix, or the token has no linked location; `stale` follows the dashboard map's threshold (`GetTraccarDeviceMap::STALE_AFTER_MINUTES`).
- **`center`:** the phone's position when it has a fix, else the first of the user's other locations that has a position (ordered by label, then id), else null with `source` null. The label is the Location's.
- **`places`:** every location of the user with a position (stationary and mobile, the phone included), with the dashboard map's `stale` rule (stationary never stale). Nothing else of another user's, and no Traccar or token identifiers, leaves the action.
- **`warnings`:** active events (`active_wwas` joined to `active_wwa_areas`) that are not tests, whose area row is `Active` and unexpired (`expires` null or in the future), whose family is `warning` or `watch` (`ActiveWwa::eventFamily()`), and whose area lies within 150 miles of the phone or any place (a PostGIS `ST_DWithin` on the geography). An area's geometry is its own polygon when it has one, else the boundary of its zone, found by `ugc_code` in `public_zones` (`state_zone`), `counties`, `fire_zones` or the marine zone tables. An event is returned once, its in-range area geometries combined, then simplified (about 0.01°, roughly a kilometre, topology preserved) and written as GeoJSON with four decimals. `event` is `eventName()`, `family` is `eventFamily()`, `expires` is the latest end of the in-range area rows (null if any has none, as `GetActiveAlertsForLocation` does), `view_url` is the latest ingested product's `viewUrl()` (null without one). Events are ordered by family rank (warnings first), then distance to the nearest anchor, and capped at 100.
- **`layers`:** from `config/wxnotify.php` (`map.*`), not hard-coded in the app: the USGS Topo and Imagery tile URL templates and their maximum zoom, the radar WMS URL and layer name, and the attribution text ("USGS The National Map · NOAA/NWS MRMS"). The server may change them without an app release.
- **Privacy and limits:** the endpoint takes no coordinates, so nothing about a position is logged or put in a URL; it returns only the caller's own places and public warning data; the response has no secrets or tokens.

## App (`mobile/`)

### Screens and behaviour

- **Bottom bar:** Alerts · Map · Settings. The Map tab goes to `/map` (`MapScreen`), a tab root like Alerts.
- **Layout:** a native top bar "Map" with a Refresh action; a web view filling the upper part of the screen; a native list under it. The list is "Near you": a row per warning in the snapshot (event name, "until …", and its family), then a row per place (name and "Current" or "No recent update"). A warning row opens its `view_url` in the in-app browser (a row with none is not tappable); a place row pushes `/locations/{id}`. A line under the list says "Turn on location sharing in Account to center the map on this phone" when `phone` is null.
- **The map page** (a Blade view rendered by a `MapPage` support class from the snapshot) draws the base maps (a Leaflet layer control switches USGS Topo and Imagery), the radar WMS overlay (65% opacity, re-requested every two minutes with a cache-busting parameter), a polygon layer per warning (coloured by family and event, popup with name and expiry), a pin per place (popup with the label; faded when stale) and a distinct "You" marker for the phone. Radar and warnings are toggles in the same control. The initial view is `center` at a regional zoom, or the continental US when null. Leaflet's CSS and JS are bundled under `mobile/resources/map/` and inlined into the page.
- **Delivery:** the plan starts with a throwaway spike, run on an iOS simulator and an Android emulator, that checks an inline-HTML web view carrying bundled Leaflet renders, loads USGS and NOAA tiles, takes pan, zoom and taps, and copes with the HTML's size. If it does not, the fallback is the web view's PHP mode (the page served by an app route, with the `window.Native` bridge). The page is one Blade view either way, so the choice changes delivery, not design. The screen must never rebuild the page's HTML when only the native list's state changes (the web view would reload and lose pan and zoom): the HTML is computed once per snapshot.
- **States:** offline, the map area says "The map needs a connection" and the list shows the last cached snapshot with the usual offline note; with no places and no phone position the map opens on the US view with radar only and the list says "No places to show yet"; a snapshot failure shows a message with Try again; Refresh refetches the snapshot and rebuilds the page.
- **Cache:** `LocalCache` keeps the last snapshot's `places`, `warnings` (without geometry) and `generated_at` for the offline list; it is wiped at sign-out with everything else. The geometry and the page are never cached.

### Safety

Place names and warning headlines are user and feed text: they are encoded into the page with `json_encode` flags that escape tags, quotes and ampersands, and popups are built with text nodes, never `innerHTML`. The page carries a content security policy allowing only inline script and style, and images and WMS requests from the configured tile and radar hosts. The page never receives the app token.

## Testing

- **Backend** (`GetMapSnapshot` and the endpoint): the radius (a polygon event inside and outside 150 miles; a zone-only event, with geometry taken from each of the four zone tables); expired, test, cancelled and other-family events excluded; an event listed once with several areas and the latest end; the count cap and the ordering; the phone as this token's own location (another token's phone is just a place); each `center` fallback; stale; another user's locations never appearing; no Traccar or token fields; the payload shape; the app-token ability and 401.
- **App:** `MapPage` (the page includes the layers and data, escapes hostile text, never contains the token, is stable for one snapshot); `MapScreen` states (data, no places, offline with a cached snapshot, error, refresh); the list rows and their actions; the hint line; the sign-out wipe; `native:validate` clean; the tab bar test updated to three tabs.
- **Device:** the spike on both platforms first, then the finished screen on the Android emulator and the iOS simulator against the dev server.

## Rules to record

In `.ai/rules/api.md`: `GET /map` returns only the caller's own places and public warning data, takes no coordinates, caps its payload (100 events, simplified geometry), and its `layers` come from config. In `.ai/rules/actions.md`: `GetMapSnapshot` selects warnings through `active_wwa_areas`, never the event row's own `ugc_codes`/`polygon` (`.ai/rules/pipeline.md`).

## Out of scope

SPC outlooks; radar looping; a nationwide warnings switch; loading warnings as the map is panned; advisories and statements; choosing a location on the map; opening alerts from inside the map page; a native map plugin; a one-shot GPS fix when location sharing is off; offline map tiles.

## Risks to settle in the plan

- **Web view behaviour:** the inline page's size, the opaque origin's network access and Android's system WebView are untested; the spike decides inline versus PHP mode.
- **USGS and NOAA service terms and availability:** both are public and keyless; neither is a paid service with an SLA, so an outage shows as a blank layer. Radar covers the continental US only (Alaska, Hawaii and Puerto Rico have separate MRMS layers, not in this slice).
- **Geometry cost:** the zone-boundary union and simplification run per request; the plan measures it and adds a short cache or a cap if it is slow.
