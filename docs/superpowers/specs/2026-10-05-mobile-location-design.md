# Mobile Location (the phone as a second mobile source) — Design

Source: owner request — Phase 6, sub-project 3 of the mobile app (foundation and push are shipped; see `2026-10-04-mobile-app-foundation-design.md` and `2026-10-04-mobile-push-design.md`). Foreground and background position as a second mobile source beside Traccar (`MobileSource` has only `Traccar` today).

## Goal

A subscriber who signs in to the mobile app can turn on "Share this phone's location". The phone's position then keeps one of their mobile Locations current, in the background and after the app is closed, so alert rules that use that Location match where they actually are. Everything downstream (alert matching, the Locations map, the stale rule, reverse geocoding) works unchanged.

## Decisions (user-approved, from brainstorming)

- **A second mobile source on `Location`**: `MobileSource::App`. Rejected: a separate "my phone" position outside `Location` (alert matching, the map and the stale logic would each need a second code path).
- **A native beacon, background included.** The `nativephp/mobile-geolocation` plugin's `sharePosition()` records in a native foreground service / background `CLLocationManager` and POSTs the latest fix to a URL with a bearer token on an interval, with no PHP running, surviving app kills and reboots. Rejected: foreground-only (alerts stop being accurate the moment the app is closed); a background recorder drained by PHP through a periodic task (more moving parts, only runs when the OS lets it).
- **The app creates the Location when sharing is turned on** (an Account toggle, like push). Rejected: the subscriber creating it on the dashboard and linking a phone.
- **Never delete the Location when the phone goes away.** `alert_rule_location` cascades on Location delete, so deleting it would silently strip rules of their location. Signing out, revoking the device or turning sharing off only unlinks it; it keeps its last position, turns grey after an hour (existing stale rule) and appears in Needs attention like a Traccar device that went missing.
- **A dedicated, narrow beacon token.** The plugin keeps the beacon's bearer token in plain `SharedPreferences`/`UserDefaults`, so the app token is never handed to it.
- **Only the latest position is stored** (no history), the same as Traccar.
- **Interval** is the Location's existing `mobile_update_interval_minutes` (5-30 minutes on the dashboard), default 5.
- **Tier**: an app Location counts against the tier's location limit (`User::canCreateLocation()`), like any other.

## Backend

### Schema

- `MobileSource::App` (label "WxNotify app").
- `locations.app_token_id`: nullable FK to `personal_access_tokens`, `nullOnDelete`; unique (one Location per phone). Added to `Location`'s `#[Fillable]` only if the model needs it from server-side code; it is never set from request input. `Location::saving` clears it for a stationary location, next to the existing Traccar clearing (a mobile -> stationary edit must not keep a link).
- `personal_access_tokens.parent_token_id`: nullable FK to `personal_access_tokens`, `cascadeOnDelete`. A beacon token's parent is the app token that minted it, so revoking or signing out the app token removes it at the database level. The migration for the table is the project's own, so this is a new migration adding the column.

### Tokens

- App tokens keep ability `['app']`. A beacon token is created with `createToken($name, ['location'], $expires)` (explicit ability, never the default `*`), named "Location beacon - {device}", with `parent_token_id` set to the app token. The "Signed-in devices" page lists only tokens whose abilities are exactly `['app']`, so beacon tokens do not appear there.
- A `location` token can reach exactly one route (`POST /api/v1/location`); `ability:app` routes refuse it and `ability:location` routes refuse an app token. Its expiry slides on use like the app token's (the beacon is continuous): `ExtendAppTokenExpiry` is reused unchanged (only its docblock was updated), because it extends whichever token it is given.

### Endpoints (`/api/v1`)

- `PUT /location-sharing` (app token, ability `app`, throttle `app-api`): creates or re-links the phone's Location and mints a beacon token. Order of resolution: the Location already linked to this app token (reuse); else an unlinked app Location of the same user whose label equals this device's name (re-link, so signing in again on the same phone does not create duplicates); else a new Location labelled with the device name (`type` mobile, `mobile_source` app, `mobile_update_interval_minutes` 5). A new Location counts against `canCreateLocation()`: at the limit the answer is 422 with a fixed message. Any previous beacon token of this app token is replaced inside one transaction (one beacon token per phone), so a refused request (the tier limit) revokes nothing. Response: `{data: {location_id, beacon_token, url, interval_seconds}}` (the only place a beacon token is ever returned). `url` is `route('api.v1.location.store')`.
- `GET /location-sharing`: `{data: {linked: bool, location_id, interval_seconds}}` so the app can re-arm after the interval changes on the dashboard or learn that the Location was unlinked. Never returns a token.
- `DELETE /location-sharing`: revokes the beacon token and unlinks the Location (`app_token_id` null). 204, idempotent.
- `POST /location` (beacon token, ability `location`, its own throttle limiter sized for one request per few seconds per token): body is the plugin's exact contract, `{"points": [{latitude, longitude, accuracy?, speed?, heading?, altitude?, timestamp}]}`. Validation: `points` 1-50 items; latitude -90..90, longitude -180..180; `timestamp` an ISO-8601 date, not more than 5 minutes in the future. If any point is invalid the whole batch is rejected with 422 (the plugin sends exactly one point per request). The newest point not older than the stored `mobile_position_fixed_at` is applied; if none qualifies the answer is still 204 (the plugin drops failures and ignores the body). The Location is the one linked to the calling beacon token's parent app token; no linked Location (the phone was unlinked) answers 404, and a beacon token with no parent app token reaches no location (guarded explicitly, because `where(col, null)` is `IS NULL` in Laravel and would match unlinked rows). In tests the stored datetime columns keep whole seconds. Writes `lat`, `lng`, `mobile_position_fixed_at` (the fix time) and `mobile_position_updated_at` (receipt time), and queues the existing reverse-geocode job when the phone has moved more than `Location::ADDRESS_RELOOKUP_METERS` since the last lookup. That decision currently lives in `PollTraccarPositions::queueAddressLookup()`; it moves into a shared action used by both sources.
- Responses never include a token other than the one `PUT` returns; no endpoint logs a coordinate or token. The position write must not create an activity row: like `PollTraccarPositions` (which wraps its write in `$location->disableLogging()` / `enableLogging()`, because the model's log options exclude the bookkeeping timestamps but deliberately still log `lat`/`lng` for a subscriber's own manual repositioning), the position action disables logging around its `save()`. The shared action that both sources use for the reverse-geocode decision is the natural home for that wrapper too.
- Each of the above follows `.ai/rules/api.md` and `.ai/rules/actions.md` (an Action class per operation, Form Requests, `withCauser` for activity writes).

### Dashboard

- `LocationForm` shows "WxNotify app" as the read-only source of an app Location, with the linked phone's name or "Not linked to a phone"; label and update interval stay editable; Traccar fields stay hidden for it.
- `GetAlertsNeedingAttention`'s existing "mobile location whose device is gone" group also covers an app Location with no linked phone (its position is kept, so it is also covered by the "no position" group when it never had one).

## App (`mobile/`)

- **Account row "Share this phone's location"**: Off, On ("updates about every N minutes - last shared 14:03"), "While using only - stops if you close the app" with an Open settings button, Blocked in system settings, or the tier-limit message. State comes from the plugin's `Geolocation::backgroundWatchStatus()` (`active`, `shareActive`, `lastShareAt`, `lastShareStatus`) plus a local "wanted" flag: when sharing is wanted but not active the row shows the waiting-for-permission state.
- **Turning it on**: the Account row's "Share location" button opens an explainer screen (not shown automatically; what is sent: the latest position every N minutes, no history; why; that Android shows a persistent notification while sharing), then the OS permission request (the plugin runs the two-step While-using -> Always flow; on Android 11+ the upgrade is a Settings redirect), then `PUT /location-sharing`, then `Geolocation::sharePosition(url:, token:, interval:, fineAccuracy: false)` with `minDistance(250)`, no expiry and the fixed watch id `wxnotify-location` so a relaunched app can always find the watch. The app stores the beacon token and the url the server returned in `TokenStore` (secure storage) so it can re-arm after a reboot, and re-arms with the stored url; the plugin keeps its own plain copy of the token, which is why the token is narrow.
- **Re-arming on launch** (feed `mount()`): if sharing is wanted, `GET /location-sharing`; if the interval changed or the native watch is not active, re-start the beacon (fetching a fresh beacon token through `PUT` when the server no longer knows ours); if the server says the Location is unlinked, stop the watch and mark sharing off.
- **Turning it off / signing out**: stop the native watch with `clearBuffer: true` whether or not the server can be reached. Turning it off calls `DELETE /location-sharing` and stays on if the server cannot be told (as push does); sign-out relies on the app-token cascade to revoke the beacon token and clears local flags and the stored beacon token.
- **Revoked on the dashboard while the app is closed**: the beacon gets 401s until the next app open, where the launch check stops it. Accepted, bounded cost.
- All new screens/rows use the existing element vocabulary; `native:validate` stays clean; every native call is listed in `NativePluginsTest` against its plugin (`GeolocationServiceProvider` joins the list).

## Configuration and prerequisites (owner)

- `NATIVEPHP_GEOLOCATION_BACKGROUND_LOCATION=true` in `mobile/.env`, then rebuild (the plugin inserts `ACCESS_BACKGROUND_LOCATION`, the foreground service and `RECEIVE_BOOT_COMPLETED` on Android and the Always usage string and `location` background mode on iOS only when the flag is set).
- Before the first store build: the Google Play background-location + foreground-service declaration, and an App Store review reason for background location.
- iOS needs a physical device to verify; the simulator can fake a position but not the Always / killed-app behaviour.

## Testing

Pest feature tests (backend in the container, app on the host):

- **Backend**: sharing creates / reuses / re-links by device name / creates a new Location; the tier-limit 422; `GET` and `DELETE` of sharing (an unlinked Location survives with its rule links); the `parent_token_id` cascade (revoking or signing out the app token deletes the beacon token and unlinks nothing else); a beacon token is refused on app routes and an app token on `POST /location`; `POST /location` validation (range, future timestamp, older-than-stored fix, newest of several points, empty/invalid batch), the writes to `lat`/`lng`/fix and receipt time, the geocode job queued only past 100 m, 404 for an unlinked phone; no token or coordinate in any response (other than the one `PUT` token), activity log or exception; the form shows the read-only source; the Needs-attention row for an unlinked app Location; `Location::saving` clears the link for a stationary location.
- **App**: a `Geolocation` fake in `tests/Fakes` (like `FakeNetwork`); Account row states; the explainer and "Not now"; enabling sends the `PUT` and starts the beacon with the returned values; re-arm on launch (interval change, watch not active, unlinked); stop on sign-out and on turning off; 401 handling; the plugin guard row.
- **Device check** (controller, after execution, Android emulator with a Google Play image): enable sharing; the first fix arrives immediately (`adb emu geo fix <lon> <lat>`); a second fix arrives on the interval and moves the Location; a mobile-location alert match uses the new position; revoke the device on the dashboard and see the 401 stop on next open; sign out and see the watch stop and the Location unlinked but kept. iOS reported unverified until a physical device is used.

## Rules to record

- `.ai/rules/api.md`: a `location` beacon token has one ability and one route, its parent app token cascades it, it is never the app token, and `PUT /location-sharing` is the only place it is returned.
- `.ai/rules/models.md`: a Location is never deleted because its phone went away (rule links cascade); an app Location is unlinked, not removed; stationary clears `app_token_id`.

## Out of scope

A position history or trail, the map, "Heads Up" travel paths, managing locations from the app (the parity slice), more than one phone per Location, pause or schedule controls, a configurable accuracy, and iOS device verification.
