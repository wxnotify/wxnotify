# Daily Weather Summary "Full" Message Length — Design

Source: subscriber/owner request — use the alert's existing **Message length** option (Brief/Full) to change what a Daily Weather Summary contains. Brief stays exactly as it is today. Full becomes a rich summary (current conditions + full 7-day forecast), sent only to channels that can render rich content; every other channel gets the Brief text.

## Goal

A Daily Weather Summary rule whose stored `message_body_style` is `full` delivers, to **Email channels only**, an HTML email with a header (location, reporting station, sunrise/sunset), an Alerts in Effect section, a Current Conditions section, a radar snapshot, a next-12-hours hourly forecast, and a full 7-day forecast. All other channel types receive the unchanged Brief text. Brief rules, Free-tier rules, and Threshold Alerts are unaffected.

## Decisions (user-approved, from brainstorming)

- **Rich channels = Email only.** Email (`ChannelTransport::Mail`) is the only channel with a real HTML renderer. Discord/Slack/Teams/Telegram/ntfy/Pushover (Apprise), Webhook, and Web Push all receive Brief. Extending Full to Apprise chat channels or structured JSON for Webhook is explicitly out of scope.
- **Icons are a self-hosted copy of NWS's icon set.** NWS marks the `icon` property deprecated in its own OpenAPI spec (forecast periods and observations). The URLs work today (PNG), but the plan must not depend on them at runtime. The public-domain icon images are downloaded once, committed under `public/images/weather/{day,night}/`, and referenced through `APP_URL` like the existing mail logo.
- **Structured data, dedicated mail view.** The summary is built as structured data and rendered by a dedicated mailable/view. It is **not** an HTML string passed through the generic `$body` path, and it does **not** reuse `ChannelMessageMail` (whose body is an escaped `<pre>`).
- **Observations unavailable → still send Full, minus Current Conditions.** If the forecast fetched but no usable observation could be found, the email omits the Current Conditions section. If the forecast itself is unavailable, nothing is sent and `last_scheduled_run_on` stays unset so the next tick retries — unchanged from today.
- **Free tier gets Brief.** `AlertRule::effectiveMessageBodyStyle()` returns `null` for Free, and the Message length field is hidden for Free. A rule is "Full" only when `effectiveMessageBodyStyle() === MessageBodyStyle::Full` — an explicit comparison, so `null` (Free, or a paid rule created before the option existed, whose column is null) is Brief. Consequence: only paid rules whose stored style is literally `full` (the wizard's default for non-Free) start receiving the rich email on deploy; pre-option null rules do not change behavior.
- **Radar snapshot is fetched at send time and embedded inline (CID).** When the Full email is built, the latest still frame for the location's nearest radar site is downloaded and attached inside the message. It is a true snapshot of the send moment, shows in clients that block remote images, and costs about 30 KB per email. If the fetch fails or the response is not a valid image, the Radar section is omitted. The animated loop and hotlinking the NWS URL were rejected (not a snapshot / first-frame-only clients).
- **Hourly forecast = the next 12 hours, one row per hour.**
- **Alerts in Effect = everything in effect at the location**, read from our own `active_wwas` table (the same live data the alert rules match against — no extra API call), independent of the subscriber's own alert rules. Warnings, watches, advisories and statements all appear.
- **Sunrise/sunset are computed locally** with PHP's `date_sun_info()` from the location's lat/lng (NWS publishes neither); no network call and nothing to fail.
- **Section order**: header (location, station, sunrise/sunset) → Alerts in Effect → Current Conditions → Radar → Next 12 Hours → 7-Day Forecast. Alerts come first because they are the time-critical item.
- **Every optional section degrades independently.** Alerts, Current Conditions, Radar, Hourly and Sun times each build inside their own guard; a failure omits just that section (logged with fixed strings only) — except Alerts in Effect, whose failure renders the notice "Alert information is unavailable right now." instead of being omitted, because silence there would read as an all-clear. Only the 7-day forecast being unavailable suppresses the Full send, as today.
- **Imperial units**, matching Threshold Alerts. **Period labels** are NWS's own period names ("Today", "Tonight", "Overnight", "Saturday"…) plus the date from the period's `startTime`.

## Verified against the live NWS API (2026-10-02)

- `GET /points/{lat},{lng}` → `properties.observationStations` (`/gridpoints/{office}/{x},{y}/stations`), `properties.forecast`, `properties.timeZone`.
- The stations list is ordered **nearest first** and each feature carries `properties.stationIdentifier`, `properties.name`, `geometry.coordinates` (`[lng, lat]`) and `properties.elevation` (`{value, unitCode: wmoUnit:m}`) — no extra per-station call is needed for header data.
- `GET /stations/{id}/observations/latest` → `properties` with `timestamp`, `textDescription`, `icon`, `temperature` (degC), `dewpoint` (degC), `windDirection` (degrees), `windSpeed` (km/h), `windGust` (km/h, **often null**), `barometricPressure` (Pa), `relativeHumidity` (%), `windChill` and `heatIndex` (degC, **both null in mild weather**), `visibility` (m). Each is `{value, unitCode}`; `value` can be null for any field, and a whole station can report nulls (the second-nearest station in the test sample had a different, older observation than the first).
- `/points` also returns `properties.forecastHourly` (`/gridpoints/{office}/{x},{y}/forecast/hourly`, 156 periods) and `properties.radarStation` (e.g. `KFTG`).
- Hourly periods carry `startTime`/`endTime` (local offset), `temperature` (int, F), `probabilityOfPrecipitation.value` (may be null), `relativeHumidity.value`, `dewpoint`, `windSpeed` (string such as `"2 mph"` or `"5 to 10 mph"`), `windDirection`, `shortForecast`, and `icon` (same URL shape as forecast periods, `size=small`). The first period can already be in progress when fetched.
- NWS radar still frames are served at `https://radar.weather.gov/ridge/standard/{SITE}_0.gif` (about 28 KB, `image/gif`, refreshed every few minutes; the `lite` variant 404s). The animated variant `{SITE}_loop.gif` is about 190 KB and is not used.
- `date_sun_info()` is available in this PHP build and returns `sunrise`/`sunset` timestamps (either can be `false` at polar extremes).
- Forecast periods (`/gridpoints/{office}/{x},{y}/forecast`, 14 periods = 7 days) carry `name`, `startTime` (already in the location's local offset), `isDaytime`, `temperature`, `temperatureUnit`, `shortForecast`, and `icon` (`https://api.weather.gov/icons/land/{day|night}/{code}[,{prob}][/{code2}[,{prob2}]]?size=medium`).

## Data

### `App\Actions\Weather\GetNwsPoint` (new)

`__invoke(Location $location): ?array` — one cached `/points/{lat},{lng}` lookup returning `{forecast, forecastHourly, observationStations, radarStation, timeZone}` (key `nws-point:{lat},{lng}`, **7-day TTL** so a gridpoint remap eventually refreshes; a failed lookup is not cached and logs status only). The new actions below share it. The existing `GetSevenDayForecast` / `GetGridpointForecastData` lookups are **not** refactored in this change.

### `App\Actions\Weather\GetCurrentConditions` (new)

`__invoke(Location $location): ?array` — returns the normalized current-conditions array below, or `null` when no usable observation exists.

1. Resolve the stations URL from `GetNwsPoint`.
2. Fetch the station list; walk the first **5** stations nearest-first. For each, fetch `observations/latest` and accept the first whose `temperature.value` is non-null **and** whose `timestamp` is within **3 hours** of now. This is the explicit fallback for stations reporting nulls or stale data; the nearest station is not always the best.
3. Cache the result for **10 minutes** per location so repeated channels/rules for one location do not refetch.
4. Return `null` if no station qualifies, or the point/stations lookup fails (log a warning with fixed strings and status only — no response bodies).

Normalized shape (a plain, queue-serializable array; numbers are rounded for display at render time, not stored rounded):

```php
[
    'station' => ['id' => 'KEIK', 'name' => 'Erie Municipal Airport', 'lat' => 40.01169, 'lng' => -105.05033, 'elevation_ft' => 5085],
    'observed_at' => '2026-10-02T08:35:00-06:00',   // ISO-8601 string in the location's timezone (not a Carbon: the report is queued)
    'description' => 'Clear',         // textDescription
    'icon' => ['period' => 'day', 'code' => 'skc'],   // see Icons
    'temperature_f' => 54.0,
    'feels_like_f' => 54.0,           // windChill ?? heatIndex ?? temperature
    'humidity_pct' => 77,
    'wind' => ['direction' => 'N', 'speed_mph' => 0, 'gust_mph' => null],
    'barometer_inhg' => 30.27,
    'dewpoint_f' => 46.4,
    'visibility_mi' => 10.0,
]
```

- **Feels-like**: use NWS's `windChill`, else `heatIndex`, else the actual temperature. In mild conditions both are null, so showing the plain temperature matches NWS's own convention rather than an empty field.
- **Wind direction**: 16-point compass from degrees (`N`, `NNE`, …). Calm (0 mph) shows speed `0` and omits direction; gust is shown only when non-null.
- **Conversions**: degC → degF, km/h → mph, Pa → inHg (÷ 3386.389), m → mi (÷ 1609.344), station elevation m → ft. A null optional field (humidity, barometer, dewpoint, visibility) renders as `—`, not as `0`.

### Forecast periods

Reuse `GetSevenDayForecast` unchanged. For each of its periods the Full view needs: `name`, date (`startTime` formatted `D, M j`), `shortForecast`, `temperature`/`temperatureUnit` (labelled "High" when `isDaytime`, "Low" otherwise), and the icon code parsed from `icon`. Brief continues to read only the first day and first night period.

### `App\Actions\Weather\GetHourlyForecast` (new)

`__invoke(Location $location): ?array` — fetches `forecastHourly` from `GetNwsPoint` (cached 1 hour, like the forecast), drops periods whose `endTime` has already passed, and returns the **next 12**. Each entry: `time` (ISO string in the location's timezone, rendered `g A`), `icon` (`{period, code}` via `WeatherIcon`), `temperature`/`unit`, `precip_chance` (int or null), `wind` (the NWS `windSpeed` string plus `windDirection`, passed through as-is), `summary` (`shortForecast`). Returns `null` on lookup failure or an empty list.

### `App\Support\SunTimes` (new)

`forLocation(Location $location): ?array{sunrise: string, sunset: string}` — `date_sun_info()` for **today in the location's timezone** at the location's lat/lng, formatted as ISO strings in that timezone. Returns `null` if either value is `false` (polar day/night) or the location has no fix; the header then omits the sun line rather than printing blanks.

### `App\Actions\Weather\GetActiveAlertsForLocation` (new)

`__invoke(Location $location, bool $includeTest): array` — returns the alerts in effect at the location from `active_wwas`:

- **In effect**: `status = 'Active'` and (`expires` is null or in the future). `is_test = false`, or any `is_test` when `$includeTest` is true (the rule owner is a test account, mirroring `MatchWeatherAlertRules`' test routing).
- **Covers the location**: when the row has a `polygon`, `ST_Contains(polygon, point)` decides (the polygon is the authoritative extent for warnings, as in `MatchWeatherAlertRules::matchesActiveWwaPolygon`); when it has none, the row's `ugc_codes` must intersect the location's `forecast_zone_ugc` / `county_ugc`. Zones are resolved lazily through the existing `ResolveLocationZones` action the matcher uses; a location with no fix returns `[]`. SRID 4269, same as the rest of the pipeline.
- **Each entry**: `event` (the product name, e.g. "Tornado Warning"), `headline`, `expires` (ISO string in the location's timezone), `family`. **Order**: family severity (warning, watch, advisory, statement, then anything else), then soonest `expires`.
- No cap beyond the data itself; an empty array renders "No active alerts."; the action's *failure* is reported by the report builder as `null` (not `[]`) and renders "Alert information is unavailable right now."

### `App\Actions\Weather\GetRadarSnapshot` (new)

`__invoke(string $site): ?array{bytes: string, mime: string}` — downloads `https://radar.weather.gov/ridge/standard/{SITE}_0.gif` with the app User-Agent and a short timeout (5 s). `$site` comes from an external API response, so it must match `^[A-Z0-9]{3,4}$` **before** it is placed in a URL (anything else returns `null`), the host is fixed in code, and redirects are not followed. The response is accepted only when its status is 200, its content type is `image/gif`/`image/png`, its leading bytes match that format, and it is at most 1 MB. Cached 2 minutes by site so several email channels (or rules in the same radar's coverage) share one fetch. The bytes are never stored in the queued report.

### `App\Actions\Weather\BuildDailySummaryReport` (new)

`__invoke(Location $location): ?array` — builds the serializable report the Full email renders and the job carries:

```php
[
    'location' => 'Home',                          // Location::label
    'sun' => <SunTimes array>|null,
    'alerts' => <GetActiveAlertsForLocation array>|null,  // [] renders "No active alerts"; null (lookup failed) renders "Alert information is unavailable right now."
    'current' => <GetCurrentConditions array>|null,
    'radar_site' => 'KFTG'|null,                          // the site code only -- the image is fetched at send time, never queued
    'hourly' => <GetHourlyForecast array>|null,
    'periods' => [ ['name' =>, 'date' =>, 'summary' =>, 'label' => 'High'|'Low', 'temperature' =>, 'unit' =>, 'icon' => ['period' =>, 'code' =>]], … ],
]
```

Returns `null` when the periods array is empty (forecast unavailable). Every other section is built inside its own `try`/`catch (Throwable)` and becomes `null` on failure (including alerts — see Decisions), with a fixed-string warning log. `BuildDailySummaryMessage` (Brief) is not modified. The alert lookup needs the rule owner's `is_test_account`, so the builder takes the rule (or that flag) as a second argument.

Dates/times inside the report are stored as plain strings/ISO timestamps, not Carbon/closures, so the payload survives queue serialization and carries no models.

## Icons

- One-time download of NWS's icon images for every code returned by `GET https://api.weather.gov/icons` × `day`/`night` into `public/images/weather/{day,night}/{code}.png`, committed to the repo (US government works, public domain). No runtime dependency on NWS for images.
- `App\Support\WeatherIcon::fromUrl(string $url): array{period: string, code: string}` parses `/icons/land/(day|night)/<code>` from an NWS icon URL, takes the **first** segment of a `a,40/b,20` pair, and strips the `,NN` probability suffix. An unparseable URL falls back to the generic icon.
- `WeatherIcon::url(period, code)` returns `url('images/weather/{period}/{code}.png')` (absolute via `APP_URL`), falling back to a bundled generic `na.png` when the file does not exist.
- Mail clients that block remote images still show the alt text (the condition description), so every `<img>` carries a meaningful `alt`.

## Capability and delivery

### `ChannelType::supportsRichContent(): bool` (new interface method)

Implemented on every type via the existing pattern. `EmailChannelType` returns `true`; all others return `false`. This is the single source of truth for "can this channel render the Full summary"; delivery code never switches on a channel slug.

### `DispatchScheduledAlerts` (changed)

For a `DailySummary` rule, build the Brief message exactly as today (it is still the required fallback and what Brief/non-rich channels send). When `effectiveMessageBodyStyle() === MessageBodyStyle::Full`, additionally build `BuildDailySummaryReport` **once per rule** (not per channel) and pass it to each dispatched job. Threshold Alerts are untouched. `last_scheduled_run_on` still advances only when the Brief message was successfully produced (forecast data present); report-building failures degrade to "no rich payload", not to "no delivery".

### `DeliverScheduledAlert` (changed)

Gains a nullable trailing constructor parameter `?array $report = null`. Handling per channel:

- `$report !== null` **and** the channel type `supportsRichContent()` → fetch the radar snapshot (`GetRadarSnapshot` for `$report['radar_site']`, skipped when the site is null) and send through the dispatcher with `$meta['daily_summary'] = $report` and `$meta['daily_summary_radar'] = {bytes, mime}|null` (both stay in-process: the dispatcher runs synchronously inside the job, so the bytes are never serialized onto the queue); the string body is the Brief text, used for the plain-text alternative and any failure fallback.
- Otherwise → exactly today's path (Brief text, with the existing 280-character cap for non-email channels).

**Queue-compatibility guard**: as with `DeliverWeatherAlert::$locationId`, a job serialized before this deploy has the new promoted `readonly` property *uninitialized* (the constructor is bypassed on unserialize), and reading it directly throws. Read it once via `$report = $this->report ?? null;` (isset semantics) and never dereference the property again.

### `ChannelDispatcher` / `MailTransport` (changed)

`ChannelDispatcher::send()` and the `Transport` interface are unchanged; the report travels in `$meta['daily_summary']`. `MailTransport::send()`: when `$meta['daily_summary']` is present, send `App\Mail\DailySummaryMail` instead of `ChannelMessageMail`; otherwise unchanged. All existing guarantees stay: the title still passes through `MessagePrefix::apply()` in the dispatcher, failure text stays fixed (never an exception message), and the mail relay switch/missing-address checks run first.

### `App\Mail\DailySummaryMail` (new) and views

A Markdown mailable (so it inherits the shared theme/header logo, per `.ai/rules/delivery.md`), with:

- **Header**: location label; "Reporting station: {name} ({id}) — {lat}, {lng} — elev. {ft} ft" (omitted when there is no current-conditions data); "Sunrise {time} · Sunset {time}" (omitted when `sun` is null).
- **Alerts in Effect**: one row per alert — event name, headline, "until {expires}" in the location's timezone; "No active alerts." when the array is empty. Always present in a Full email: an empty state ("No active alerts.") is information, and a failed lookup shows "Alert information is unavailable right now." rather than disappearing.
- **Current Conditions** (omitted entirely when `current` is null): icon + description, temperature, feels like, humidity, wind (direction, speed, and "gusting {n} mph" only when a gust exists), barometer (inHg), dewpoint, visibility (mi), "Last updated {observed_at}" in the location's timezone.
- **Radar** (omitted when no image was fetched): the embedded image (`$message->embedData(...)`, rendered as a `cid:` `<img>`) with alt text "Radar near {location} from {site}" and a "Radar as of {send time}" caption.
- **Next 12 Hours** (omitted when `hourly` is null): one row per hour — time, icon, temperature, "{n}%" precip chance (or `—`), wind, short summary.
- **7-Day Forecast**: one row per period — icon, "{name} · {date}", short summary, "High|Low {temp}°{unit}".
- A plain-text alternative view carrying the same content line-by-line, minus the radar image (the existing mail already ships a text alternative; this one must too).

All dynamic values are Blade-escaped (`{{ }}`), never `{!! !!}`; the station name, description and summaries come from an external API.

## Non-goals

- Apprise chat channels, Webhook, and Web Push do not get Full (Brief only). No Markdown or JSON rendering of the full summary.
- No change to Brief, to Threshold Alerts, to message templates (`AlertTemplate`s still apply only to Weather Alerts / SPC & WPC), or to the wizard (the Message length field already exists and is already hidden for Free).
- No unit-system setting; imperial only.
- No animated radar, no radar beyond the single nearest-site still, no hourly span beyond 12 hours, no per-alert full text or "view online" link, no filtering of alerts by the subscriber's own rules.
- No cross-container scheduler/deploy work (separately handled: the `scheduler` compose service).

## Edge cases

- **No qualifying station within the first 5 / all stale** → Current Conditions omitted; forecast still sent.
- **Forecast has fewer than 14 periods** (NWS occasionally returns fewer) → render the periods that exist.
- **First period is nighttime** (daily run after sunset) → first row is the night period, labelled "Low"; no special-casing needed.
- **Station elevation or coordinates missing** → omit that part of the station line rather than printing blanks.
- **A rule with Full style but only non-email channels** → every channel gets Brief; the report is still built once but never rendered. (Acceptable cost; building it is two cached HTTP calls. Alternative of skipping the build when no attached channel supports rich content is a trivial optimization the plan may include.)
- **No radar station** in the `/points` response, an invalid site code, a failed/oversized/non-image radar response → Radar section omitted; the rest of the email is unaffected.
- **Polar day/night** (`date_sun_info()` returns `false`) → sunrise/sunset line omitted.
- **Location not covered by any alert, or with unresolved zones** → "No active alerts." (an unresolved location never errors the email).
- **Hourly list shorter than 12 after dropping elapsed periods** → render what exists.
- **Queued jobs created before deploy** → no `report` key; handled by the `?? null` guard; deliver Brief as before.

## Testing

Feature tests with `Http::fake()` (no live NWS calls in tests):

- `GetCurrentConditions`: normalizes units (degC/degF, km/h→mph, Pa→inHg, m→mi/ft); feels-like picks windChill, then heatIndex, then temperature; wind direction compass and gust omitted when null; null optional fields become `—`-ready nulls; nearest-first fallback skips a station with a null temperature and one older than 3 hours; returns null when no station qualifies or the lookup fails; caches per location.
- `BuildDailySummaryReport`: report shape; High/Low labelling by `isDaytime`; returns null when periods are empty; contains no models/closures (serializable round-trip).
- `WeatherIcon`: day/night parsing, probability suffix and dual-condition URLs, unparseable URL → fallback; `url()` falls back when a file is missing.
- `GetNwsPoint`: returns the five properties; caches per location; a failed lookup returns null and is not cached.
- `GetHourlyForecast`: drops periods that ended, returns the next 12 in order, parses icon codes, null `probabilityOfPrecipitation` → null, null on failed lookup.
- `SunTimes`: sunrise/sunset for a known location/date in the location's timezone; `false` values → null.
- `GetActiveAlertsForLocation`: a polygon alert covering the point is included and one whose polygon excludes it is not (even if its UGC would overlap); a polygon-less alert matches by zone/county UGC; `Expired`/`Cancelled` and past-`expires` rows are excluded; `is_test` rows only for test-account owners; ordering by family then expiry; a location with no fix returns `[]`.
- `GetRadarSnapshot`: valid GIF accepted; non-image content type, bad magic bytes, over 1 MB, non-200, and a site code failing `^[A-Z0-9]{3,4}$` (e.g. `../x`) all return null with **no HTTP request made** for the invalid site; cached per site.
- `ChannelType::supportsRichContent()`: true for Email, false for every other registered type (iterate the registry).
- `DispatchScheduledAlerts`: Full rule builds and passes the report; Brief rule and Free-tier rule (null style) pass none; Threshold unaffected; a report-build failure still delivers Brief.
- `DeliverScheduledAlert`: Full + Email channel → `DailySummaryMail` sent with expected sections; Full + non-email channel → Brief text sent and no mail rendering; null report → today's behavior; a job unserialized without the `report` property (reflection-based, as in `DeliverWeatherAlert`'s regression test) still delivers.
- `DailySummaryMail` rendering: header contains location, station with lat/lon/elevation, and sunrise/sunset; alerts rows and the "No active alerts." state; the radar `<img>` uses a `cid:` source with the image embedded, and is absent when no image was provided; 12 hourly rows; Current Conditions omitted when `current` is null; gust text only when present; every forecast period appears with icon `<img>`, name + date, summary, and High/Low; HTML-special characters in API strings are escaped; message prefix is applied to the subject.
- Assertions on icons use a committed fixture file path check (the icon files referenced by the sample report exist under `public/images/weather`).

Run the affected tests, Pint, then the full suite (inside the app container: `docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact'` — the host cannot resolve the `pgsql` hostname and runs out of memory under `php artisan test`).

## Open items for the plan (not design questions)

- Exact download mechanics for the icon set (a throwaway script vs. a documented one-off command); the images, not the script, are what ships.
- Whether the "skip building the report when no attached channel supports rich content" optimization is worth including.
- Whether `ResolveLocationZones`' lazy resolution should be reused as-is by `GetActiveAlertsForLocation` or the matcher's private `ensureZonesResolved()` should be extracted into a shared helper (the plan should pick the smaller change).
