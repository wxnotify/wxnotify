# Traccar Servers (mobile-location alert support) — Design

## Context

`LocationType::Mobile` has existed as an enum case since the `Location` model was first written, but nothing behind it was ever built — `LocationForm` hardcodes `type` to `Stationary`, and there's no way to create a mobile location at all. The Phase 0 nav design lists "Traccar Servers" as a Plus/Pro-gated Tools item that's never been implemented. This is that feature: a subscriber connects one or more Traccar servers, attaches specific Traccar devices as mobile `Location`s, and those locations' live positions feed into the alert-matching engine exactly like a stationary location's fixed coordinates do today.

**`LocationType::Mobile` is not Traccar-specific.** The master plan groups Traccar and the future native mobile apps (Phase 6) as two sources of the same "mobile location" concept — e.g. its Heads Up section discusses the same configurable update-interval tradeoff for "Traccar and the native apps" together. This design introduces a `mobile_source` discriminator column on `locations` (mirroring how `AlertRule.category` already discriminates which nullable-column-family applies on that table) so Traccar-specific fields stay clearly separated from generic "how fresh is this position" bookkeeping that a future native-app source will also need. Only the `traccar` source is implemented here; native-app support is out of scope and deferred to its own future design.

**Traccar's REST API was verified directly against its published OpenAPI spec** (`github.com/traccar/traccar/blob/master/openapi.yaml`), not assumed:
- Auth: a long-lived token (available since Traccar 5.3) is used as a plain `Authorization: Bearer {token}` header (the spec's `ApiKey` security scheme).
- `GET /api/positions` with no query params returns the latest known position for *every* device on the account in one call — no per-device looping needed.
- `GET /api/devices` returns each device's `id`/`name`/`uniqueId`, letting a subscriber pick a device by name instead of typing Traccar's internal numeric id.

Because the API is a small, synchronous JSON REST API, polling is a plain Laravel HTTP client call on a schedule — unlike the GLM lightning pipeline, no external sidecar process is needed.

## Data model

### `traccar_servers` (new table)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `user_id` | FK → users | cascade delete |
| `label` | string | subscriber's own name for the server |
| `base_url` | string | e.g. `https://demo.traccar.org` |
| `api_token` | string, `encrypted` cast | mirrors `notification_channels.apprise_url`'s encrypted cast |
| `last_polled_at` | timestamp, nullable | last poll attempt (success or failure) |
| `last_poll_error` | text, nullable | set on failure, cleared on next success |
| timestamps | | |

### `locations` (extend existing table)

| Column | Type | Notes |
|---|---|---|
| `lat`, `lng` | **now nullable** | currently `NOT NULL`; a mobile location has no position until its first successful poll |
| `mobile_source` | string, nullable | `traccar` today; discriminates which of the columns below apply. Null for stationary locations. |
| `mobile_position_updated_at` | timestamp, nullable | generic — last time this location's position was actually updated, regardless of source |
| `mobile_update_interval_minutes` | unsigned int, nullable | generic — subscriber-configured, 5–30 in steps of 5, default 15 |
| `traccar_server_id` | FK → traccar_servers, nullable | cascade delete (a mobile location with no server is meaningless) |
| `traccar_device_id` | unsigned int, nullable | Traccar's own device id |
| `traccar_device_name` | string, nullable | denormalized device name, avoids an extra API call for display |

`Location` gets a `hasFix(): bool` helper (`lat !== null && lng !== null`). This is the load-bearing correctness fix this feature requires: several existing actions (`ResolveLocationZones`, `GetSevenDayForecast`, `GetGridpointForecastData`, `MatchWeatherAlertRules::matchesPolygon`) do a plain `(float) $location->lat` cast, which silently turns PHP `null` into `0.0` ("Null Island") rather than a safe "no data." Every call site that dereferences a Location's coordinates for matching gets an early `hasFix()` guard — a mobile location with no fix yet simply doesn't match/evaluate this cycle, the same shape as today's "no polygon → fall back to zone matching" or "no location → false" patterns already in `MatchWeatherAlertRules`. `MatchLightningFlashes`'s raw-SQL `ST_MakePoint` needs no change — Postgres already evaluates `ST_DWithin` against a null-coordinate point as SQL `NULL`, which `WHERE` excludes automatically.

## Tier gating

Enforced now via the existing `tier()`/`tier_override` mechanism (already built and used for `Location`'s own count limit), independent of real Spark billing:

```php
// User.php — mirrors locationLimit()/canCreateLocation() exactly
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
    if ($limit === null) {
        return true;
    }
    return $this->locations()->where('traccar_server_id', $traccarServerId)->count() < $limit;
}
```

A `0` limit for Free naturally blocks server creation entirely through the exact same `$limit === null || count() < $limit` check every other tier uses — no special-casing needed.

## `TraccarServerResource` (new, subscriber-facing)

Plain CRUD (`label`, `base_url`, `api_token`) under the Tools nav group. Tier limit enforced the same double way `LocationResource` already enforces its own limit:
- `ListTraccarServers`: `CreateAction` disabled + tooltipped once `canCreateTraccarServer()` is false.
- `CreateTraccarServer::beforeCreate()`: halts with a danger notification as defense in depth (covers e.g. two browser tabs racing past the disabled-button check).

The Phase 0 nav design's aspiration of a "visible but locked, opens an upgrade explainer" treatment for gated tools has no working implementation anywhere in this codebase yet to build on. Rather than inventing that mechanism as a side effect of this feature, `TraccarServerResource` mirrors the one gating pattern that *is* already live (`Billing`'s `shouldRegisterNavigation()`/`canAccess()`, both gated on the same condition): the nav item and resource are hidden outright for Free, not shown-then-locked. Building the shared "visible but locked" pattern properly is worth doing once, across every gated tool at once, rather than one-off inside this feature — out of scope here.

## `ListTraccarDevices` and `PollTraccarServer` (new Actions)

```
app/Actions/Traccar/ListTraccarDevices.php
  __invoke(TraccarServer $server): ?array   // [{id, name}, ...] or null on failure

app/Actions/Traccar/PollTraccarServer.php
  __invoke(TraccarServer $server): ?array   // [deviceId => ['lat' => ..., 'lng' => ..., 'fixTime' => ...]] or null on failure
```

Both call the server's `base_url` with `Http::withToken($server->api_token)`. Returning `null` (not throwing, not an empty array) on any non-2xx response or connection failure is the deliberate contract both callers rely on: `ListTraccarDevices` lets the form distinguish "server unreachable" from "server reachable, zero devices"; `PollTraccarServer` lets the polling command distinguish "nothing to update" from "this server is broken right now."

## `LocationForm` changes

`Hidden::make('type')->default(LocationType::Stationary)` becomes a real `Radio::make('type')->options(...)->live()`. Stationary keeps today's Pinpoint-map fields exactly as-is (a fully separate branch, unchanged). Mobile shows:

- `Hidden::make('mobile_source')->default(MobileTrackingSource::Traccar)` — hardcoded, not a visible picker, since Traccar is the only implemented source; becomes a real `Radio` the same way `type` itself does once native apps exist.
- `Select::make('traccar_server_id')` — options are the subscriber's own `TraccarServer` records. Empty state shows helper text linking to the Traccar Servers page rather than an inline create-option modal — server setup is a base-URL-plus-token round trip that doesn't fit the lightweight "+ Add" pattern used for Locations/Channels elsewhere in this app.
- `Select::make('traccar_device_id')->live()` — options populated by calling `ListTraccarDevices` when the server selection changes; `null` result shows "couldn't reach this server — check its URL and token" instead of a silently empty dropdown. Selecting a device also stashes its name into `traccar_device_name` via `afterStateUpdated`.
- `Select::make('mobile_update_interval_minutes')` — options 5/10/15/20/25/30, default 15, helper text framed around the plan's own battery-vs-precision tradeoff.
- `lat`/`lng` become plain `Hidden` fields, left `null` on create.

Device-count-per-server tier limit (`canAddTraccarDevice()`) enforced the same double way as `canCreateLocation()` and `canCreateTraccarServer()` above.

## Polling command

New Artisan command `traccar:poll-positions`, scheduled `everyFiveMinutes()` in `bootstrap/app.php` alongside `alerts:dispatch-scheduled`/`shapefile:scan` — 5 minutes is the finest per-location interval allowed (5–30), so this cadence satisfies any location's own interval without the schedule itself needing to vary; the due-check inside the command is what actually decides whether a given location gets polled this run.

1. Find mobile `Location`s that are due: `mobile_position_updated_at` is null, or older than that location's own `mobile_update_interval_minutes`.
2. Group due locations by `traccar_server_id` — each server gets **one** `/api/positions` call per run no matter how many of its devices are due, since the endpoint already returns every device's position at once.
3. For each due location, look up its `traccar_device_id` in that server's returned positions and `forceFill` `lat`/`lng`/`mobile_position_updated_at`.

Error isolation mirrors `MatchWeatherAlertRules::matchesGeographySafely()`'s per-rule try/catch shape: one server's request failure is caught, logged, and recorded on that server (`last_poll_error`, `last_polled_at`) without aborting polling for other servers in the same run. A failed poll leaves the affected locations' existing `lat`/`lng` untouched — last known position is preserved, never blanked.

## Staleness visibility

`TraccarServersTable` shows `last_poll_error` as a danger badge when set. `LocationsTable`'s mobile rows show a human "updated 3 hours ago"-style column from `mobile_position_updated_at`. A dead integration (revoked token, unreachable self-hosted server) is visible to the subscriber, not silently going quiet — matching the bar already set by this session's Apprise-unreachable-during-channel-setup handling.

## Testing

- `ListTraccarDevicesTest`: real-shaped `/api/devices` response resolves to `[{id, name}, ...]`; a non-2xx/timeout response resolves to `null`.
- `PollTraccarServerTest`: real-shaped `/api/positions` response resolves to positions keyed by `deviceId`; a failure resolves to `null`.
- `TraccarPollPositionsCommandTest`: a due mobile location gets `lat`/`lng`/`mobile_position_updated_at` updated; a not-yet-due one is left alone; a server with no due locations is never queried; one server's failure doesn't block a second server's successful poll in the same run; a failed poll sets `last_poll_error` without blanking the location's last known position; a subsequent success clears `last_poll_error`.
- `TraccarServerResourceTest`: CRUD; Free is blocked entirely (disabled-button state and `beforeCreate()` halt+notification); Plus capped at 1, Pro at 3.
- `LocationResourceTest` additions: creating a mobile location requires `traccar_server_id` + `traccar_device_id`; per-server device-limit enforcement mirrors `canCreateLocation()`'s existing test shape; the device `Select`'s options come from the chosen server only.
- `MatchWeatherAlertRulesTest` addition: a Location/Polygon-mode rule against a fixless mobile location (`hasFix() === false`) never matches — proves the Null-Island risk is actually closed, not just designed around.
- `DispatchScheduledAlertsTest` addition: a Threshold/DailySummary rule against a fixless mobile location is skipped this cycle, not crashed or marked failed.

No test targets the `encrypted` cast on `api_token` directly — that's framework behavior, not a project decision, consistent with how `notification_channels.apprise_url`'s identical cast isn't separately tested either.

## Out of scope

- A shared "visible but locked, opens an upgrade explainer" nav treatment for gated tools generally — `TraccarServerResource` hides outright for Free instead (see above), matching the one precedent that already exists.
- Native mobile app location tracking (a second `mobile_source` value and its own update mechanism — a webhook the app posts to, not a poller) — deferred to its own future design when Phase 6 native apps are actually built.
- Traccar geofencing-powered automation ("only alert me if I'm not already home") — Phase 5 smart automation, not this feature.
- The webhook-forwarding alternative to polling (self-hosted Traccar servers pushing updates instead of being polled) — the master plan explicitly designs this as a future option without committing to building it now, and this spec follows that same call.
