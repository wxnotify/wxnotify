# Mobile app Weather card (WeatherKit) — design

## Why

The mobile Alerts tab is text-heavy: active watches/warnings/advisories at the top, then the "Alerts sent to you" list. On a quiet day there is nothing to look at. The owner wants a glanceable weather block between the two: the current conditions and a short forecast for the subscriber's saved places. They want the data from Apple WeatherKit.

## What the owner decided

- **Placement:** a new "Weather" section on the Alerts screen between the "Current watches, warnings and advisories" section and the "Alerts sent to you" section.
- **Layout (their pick, "option C"):** one card, with a row of place chips above it that switches which place the card shows. One place visible at a time. Not a swipe row, not stacked cards.
- **Card content:** current temperature and a short description, feels-like, wind, humidity, UV; an hourly strip (the mockup showed 4 slots); a daily strip (the mockup showed 4 days); chance of precipitation for each hourly and daily slot; and today's sunrise and sunset for the selected place.
- **Data source:** Apple WeatherKit, called through its REST API from the Laravel backend (a signed JWT with a WeatherKit key). Not on the device: Apple's on-device framework is iOS-only and the app must work on **iOS and Android**.
- **Who sees it:** every tier, Free included. Free accounts simply have fewer places, so fewer chips.
- **Apple attribution:** wherever WeatherKit data is shown the card carries the Apple Weather mark and a link to Apple's legal attribution page, as WeatherKit's terms require.

## My calls (not yet confirmed by the owner)

- **One source for the whole card.** Current conditions, hourly, daily, precipitation chance and sunrise/sunset all come from WeatherKit, so the card has a single consistent source and a single attribution. The existing backend `GetCurrentConditions` / `GetHourlyForecast` / `GetSevenDayForecast` actions (National Weather Service) stay as they are for the Daily Summary and the dashboard.
- **Which places:** the subscriber's located saved places in the same order and with the same cap as the existing active-alerts endpoint (`GetActiveAlertsForUser`: first 25 located places by id), with the phone's own location first when it is shared (the map and active-alerts code already do this). The first chip is selected on load.
- **Units** follow the account's unit system (imperial/metric) already used by the app's alerts; temperatures, wind and distances are formatted on the server.
- **Caching:** the backend caches WeatherKit responses per place (rounded coordinates) for 10 minutes, shared across subscribers, so call volume stays far below WeatherKit's free allowance (500,000 calls per month with the Apple Developer Program).
- **One endpoint:** `GET /api/v1/weather` returns, for the user's places, either all places' cards or one place by id (`?location=`), so the app can fetch the selected chip's data on demand and the response stays small. Eloquent API Resource, versioned like the other `/api/v1` routes.
- **Refresh:** the app fetches when the section mounts and when a different chip is chosen, and re-fetches with the Alerts screen's existing poll/pull-to-refresh when its copy is older than 10 minutes. It keeps the last good copy for offline use (like the active-alerts section).
- **Failure states:** a place whose weather could not be fetched shows "Weather unavailable right now" in the card (with the last copy and its age if the app has one); no places at all shows the section's empty line or hides the section (builder's call, but never an error).

## More calls, settled after the builder check (mine, not the owner's)

- **No fallback to NWS.** If WeatherKit is down or unconfigured the card says "Weather unavailable right now"; it never silently switches source. The owner already has an Apple Developer team (the iOS build uses a Team ID); the WeatherKit capability and key are theirs to create. The first milestone builds and tests against a fake WeatherKit client, so nothing blocks on the key.
- **The endpoint's default answer is small.** `GET /api/v1/weather` with no parameter returns the list of chips (`id`, `label`, `is_phone`) plus the full card for the FIRST place only. `GET /api/v1/weather?location={id}` returns one place's full card (404 if it is not the user's or has no coordinates). WeatherKit is called for at most one place per request.
- **Place ids are `Location` ids.** The phone's own location is already a `Location` row (`type = mobile`, `mobile_source = app`; it is never deleted), so it is just another id, listed first. A moved phone location has new coordinates, and the cache key is the rounded coordinates, so it gets a fresh lookup by construction.
- **Units:** the shared cache stores units-neutral (metric) values; formatting to the account's unit system happens after the cache, per request. The response carries display strings plus the raw metric numbers the app needs for icons and ordering.
- **Attribution comes from WeatherKit's own attribution endpoint.** The backend fetches it (cached for a day) and returns `attribution` (`name`, `logo_light_url`, `logo_dark_url`, `legal_url`) with every card; the app shows the logo that matches the theme plus the legal link. No Apple artwork is bundled in the repo.
- **Rollout order:** backend first, then the app. An app talking to an older backend (404 on `/api/v1/weather`) hides the Weather section.
- **No located places** (a Free account with no saved places and phone sharing off): the section is hidden.

## Goals

1. A subscriber sees current weather and a short forecast for each of their places without leaving the Alerts tab, on iOS and Android.
2. The backend never calls WeatherKit more than needed; a WeatherKit outage degrades the card, never the Alerts screen.
3. The Apple Weather attribution is always visible with the data.

## Non-goals and anti-goals

- No weather maps, radar, or alerts text from WeatherKit (WWAs stay ours, from NWS/the pipeline).
- No change to the Daily Summary, thresholds or any alert matching; they keep using NWS.
- Not a replacement for the Map tab.
- No on-device WeatherKit, no new native plugin.
- Must not make the Alerts screen slower to appear or fail when WeatherKit is down.

## Setup the owner must do (a dependency of the build)

An Apple Developer Program account with WeatherKit enabled: a WeatherKit service ID/App ID capability, a key (.p8) with its Key ID, and the Team ID. The backend takes them from configuration (env), never from the repo. The plan must say where each value goes and the build must run (against a fake) without them.

## Left to the builder

The JWT signing library/approach (Laravel has `firebase/php-jwt` or `lcobucci/jwt` if already installed; do not add a dependency without asking), the WeatherKit dataSets and country/language parameters, the exact response shape, the card's visual styling using the app's existing theme tokens, the poll/refresh details, the number of hourly/daily slots (mockup: 4 and 4), and test fakes.

## Edge cases the design must cover

- WeatherKit credentials missing or invalid: the endpoint answers with a clean "unavailable" per place, logged once, never a 500.
- WeatherKit returns partial data (for example no UV or no precipitation chance): missing fields are omitted from the card, not shown as 0.
- A place outside WeatherKit's coverage or with no coordinates: skipped.
- Time zones: hours, days and sunrise/sunset are shown in the place's own timezone (the location's, else the account's).
- A new place or a moved phone location: a fresh lookup, not a stale cached card for old coordinates.
- Many places: only the selected place's data is fetched when the user taps a chip; chips themselves need only the place names.
