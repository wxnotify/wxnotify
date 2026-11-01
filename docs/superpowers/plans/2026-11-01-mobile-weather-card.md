# Mobile Weather Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Weather" section on the mobile Alerts tab, between the active watches/warnings/advisories panel and "Alerts sent to you": one card with place chips, showing current conditions, an hourly strip, a daily strip (with chance of precipitation), and sunrise/sunset, from Apple WeatherKit.

**Architecture:** The Laravel backend signs a WeatherKit JWT, calls the WeatherKit REST API, caches a units-neutral (metric) result per rounded coordinate for 10 minutes, and serves it formatted to the account's unit system at `GET /api/v1/weather`. The NativePHP app (a separate repo) fetches that endpoint, caches the last copy, and renders the section inside `AlertsScreen`.

**Tech Stack:** Laravel 13 / PHP 8.4 / Pest (backend, in the Docker container); NativePHP v4 SuperNative screens + `nativephp/mobile-ui` / Pest (app, `wxnotify-mobile`); `firebase/php-jwt` (already installed, 7.1.0) for ES256.

**Spec:** `docs/superpowers/specs/2026-11-01-mobile-weather-card-design.md`

## Two repos

- Tasks 1-5 are the BACKEND, in `/Users/jimmy/Documents/WxNotify/wxnotify-nextgen`. Run tests in the container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && php artisan test --compact <file>'`; Pint: `vendor/bin/pint --dirty --format agent` there too.
- Tasks 6-8 are the APP, in `/Users/jimmy/Documents/WxNotify/wxnotify-mobile` (its own git repo, no Docker). Run tests: `cd /Users/jimmy/Documents/WxNotify/wxnotify-mobile && vendor/bin/pest --compact <file>`; also `php artisan native:validate`; Pint there as well.
- Backend first, then the app. Each repo gets its own commits; neither is pushed.

## Global Constraints

- WeatherKit is the only source for the card (current, hourly, daily, precipitation chance, sunrise/sunset). No fallback to NWS; the existing NWS actions (`GetCurrentConditions`, `GetHourlyForecast`, `GetSevenDayForecast`) are not touched.
- Credentials come from env via `config/services.php` (`services.weatherkit.team_id`, `.service_id`, `.key_id`, `.private_key` (the PEM text, newlines as `\n` allowed) or `.private_key_path`). Nothing secret is committed. The feature must build and test against `Http::fake()` with no real key.
- WeatherKit REST: `https://weatherkit.apple.com/api/v1/weather/{language}/{latitude}/{longitude}?dataSets=currentWeather,forecastHourly,forecastDaily&timezone={tz}` and `https://weatherkit.apple.com/api/v1/attribution/{language}`; bearer ES256 JWT with header `kid` = key id and `id` = `{team_id}.{service_id}`, claims `iss` = team id, `sub` = service id, `iat`, `exp` (at most 1 hour ahead). The implementer must read Apple's current WeatherKit REST documentation for the exact response field names before writing fixtures (units: Celsius, km/h, humidity and precipitationChance as 0-1 fractions).
- The shared cache holds metric values only; formatting to the account's unit system (`App\Support\Units::for($user)`) happens after the cache, per request. Cache key uses coordinates rounded to 2 decimals; TTL 10 minutes. A failed lookup is cached as unavailable for 60 seconds so an outage is not hammered.
- WeatherKit is called for at most ONE place per request. A missing/invalid credential, an HTTP failure or a partial response must never produce a 500: the card says unavailable, missing fields are omitted (never shown as 0).
- Attribution comes from WeatherKit's attribution endpoint (cached 1 day) and is returned with every card: `name`, `logo_light_url`, `logo_dark_url`, `legal_url`. No Apple artwork is bundled.
- Places = the user's `Location`s with `lat` and `lng`, the phone's own location (`mobile_source = app`) first, then by id, capped at 25 (same cap as `GetActiveAlertsForUser`). A `?location=` that is not one of the caller's located places is a 404.
- Routes go in the existing `auth:sanctum` + `ability:app` group in `routes/api.php`; the response is an Eloquent API Resource under `/api/v1`; never include anything about another user.
- App: an old backend (404 on `/api/v1/weather`) hides the section; no located places hides the section; the section never delays or breaks the rest of the Alerts screen (errors are contained). Un-prefixed `<pressable>` where the existing screen uses it (`native:validate` does not know it).
- Commit trailer on every commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Read `.ai/rules/index.md` (backend) and the repo's `CLAUDE.md` (app) before editing.

## Review Focus

- WeatherKit credentials missing/invalid, HTTP 401/429/500, or malformed JSON: endpoint answers 200 with the selected card `available: false`, never a 500; the failure is logged once per few minutes without secrets. (Tasks 2, 3, 5)
- Partial WeatherKit data (no UV, no precipitation chance, fewer hours than expected): the field is omitted, not 0. (Tasks 3, 4)
- Units and time zones: the same cached place formatted for a metric user and an imperial user; sunrise/sunset and hour labels in the place's timezone (location's, else the account's), across a day boundary. (Task 4)
- `?location=` of another user's place, a place with no coordinates, or a non-numeric value: 404/422, never someone else's data; WeatherKit is called at most once per request. (Task 5)
- App: stale copy shown with its age when offline; selecting a chip with a failed fetch keeps the previous card; old backend (404) hides the section; the Alerts screen still renders if the weather call throws. (Tasks 6, 7, 8)

---

### Task 1: WeatherKit configuration and JWT signer (backend)

**Files:**
- Modify: `config/services.php` (add a `weatherkit` array reading `WEATHERKIT_TEAM_ID`, `WEATHERKIT_SERVICE_ID`, `WEATHERKIT_KEY_ID`, `WEATHERKIT_PRIVATE_KEY`, `WEATHERKIT_PRIVATE_KEY_PATH`), `.env.example` (document the five variables, commented, with a one-line note on where each comes from in the Apple Developer portal)
- Create: `app/Support/WeatherKit/WeatherKitToken.php`
- Test: `tests/Feature/WeatherKitTokenTest.php`

**Interfaces:**
- Produces: `final class WeatherKitToken { public function isConfigured(): bool; public function token(): ?string }`. `isConfigured()` is true only when team id, service id, key id and a readable private key (inline PEM, or the file at the path) are all present. `token()` returns an ES256 JWT (header `alg` ES256, `kid`, `id` = `{team}.{service}`; claims `iss`, `sub`, `iat`, `exp` = now + 50 minutes), cached for 45 minutes under a cache key that includes the key id; returns null when not configured or signing fails (never throws).

- [ ] **Step 1: Write the failing test** `WeatherKitTokenTest`: generate an EC P-256 key with `openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])` inside the test and export it to PEM; configure `services.weatherkit.*` with it; assert `isConfigured()` true; decode the token's header and claims (base64url, no signature library needed for the claims) and assert `alg` = `ES256`, `kid`, `id` = `TEAM.SERVICE`, `iss`, `sub`, `exp - iat` <= 3600; verify the signature with `Firebase\JWT\JWT::decode` against the public key; a second call within the TTL returns the same token (cached); missing key id, missing key, an unreadable `private_key_path` and garbage PEM each give `isConfigured()` false or `token()` null without an exception; `\n` escaped newlines in an inline PEM still sign.
- [ ] **Step 2: Run it, expect FAIL** (`WeatherKitTokenTest.php`).
- [ ] **Step 3: Implement** the config block, the env docs and `WeatherKitToken` (use `Firebase\JWT\JWT::encode($payload, $pem, 'ES256', $keyId, ['id' => ...])`).
- [ ] **Step 4: Run it, expect PASS;** run Pint.
- [ ] **Step 5: Commit** `WeatherKit: configuration and the signed token`.

---

### Task 2: WeatherKit HTTP client (backend)

**Files:**
- Create: `app/Support/WeatherKit/WeatherKitClient.php`
- Test: `tests/Feature/WeatherKitClientTest.php`

**Interfaces:**
- Consumes: `WeatherKitToken` (Task 1).
- Produces: `final class WeatherKitClient { public function weather(float $lat, float $lng, string $timezone): ?array; public function attribution(): ?array }`. `weather()` GETs the weather URL (language `en`, `dataSets=currentWeather,forecastHourly,forecastDaily`, `timezone`, 10 second timeout, bearer token) and returns the decoded JSON array, or null when unconfigured, on any non-2xx, a connection error or non-array JSON. `attribution()` GETs the attribution URL and returns the decoded array or null. Failures are logged at warning level at most once per 5 minutes (use `Cache::add('weatherkit.failure-logged', true, 300)` like `RecordPipelineDelivery`), logging only the HTTP status or exception class, never the token, key or coordinates.

- [ ] **Step 1: Write the failing test** (`Http::fake`, `Http::preventStrayRequests()`, a configured key from the Task 1 test technique): the request goes to the exact URL path `/api/v1/weather/en/{lat}/{lng}` with `dataSets` and `timezone` query values and an `Authorization: Bearer` header; a 200 body is returned decoded; 401, 429, 500 and a thrown `ConnectionException` each return null and log once (assert `Log::shouldReceive`/a log spy: a second failure within the window logs nothing, and the log context contains no `Bearer`, key or coordinates); unconfigured returns null with NO HTTP request; `attribution()` hits `/api/v1/attribution/en`.
- [ ] **Step 2: Run it, expect FAIL.**
- [ ] **Step 3: Implement** the client.
- [ ] **Step 4: Run it, expect PASS;** run Pint.
- [ ] **Step 5: Commit** `WeatherKit: client for the weather and attribution endpoints`.

---

### Task 3: GetPlaceWeather — the cached, units-neutral place weather (backend)

**Files:**
- Create: `app/Actions/Weather/GetPlaceWeather.php`, `app/Actions/Weather/GetWeatherAttribution.php`
- Test: `tests/Feature/GetPlaceWeatherTest.php`

**Interfaces:**
- Consumes: `WeatherKitClient` (Task 2), `App\Models\Location`, `App\Support\Timezones::forLocation()/forUserOrDefault()`.
- Produces:
  - `GetPlaceWeather::__invoke(Location $location): ?array` returning null when WeatherKit is unavailable, else a normalised array: `['as_of' => ISO-8601 string, 'timezone' => string, 'current' => ['temperature_c' => float, 'feels_like_c' => ?float, 'condition' => string (humanised, e.g. "Mostly cloudy"), 'condition_code' => string, 'wind_kph' => ?float, 'wind_direction' => ?int degrees, 'humidity' => ?int percent, 'uv_index' => ?int, 'is_daylight' => ?bool], 'hours' => list<['time' => ISO-8601, 'temperature_c' => float, 'precip_chance' => ?int percent, 'condition' => string, 'condition_code' => string]> (the next 6 hours from now), 'days' => list<['date' => 'Y-m-d' in the place's timezone, 'high_c' => ?float, 'low_c' => ?float, 'precip_chance' => ?int percent, 'condition' => string, 'condition_code' => string, 'sunrise' => ?ISO-8601, 'sunset' => ?ISO-8601]> (today plus the next 4 days)]`. A value WeatherKit did not send is null (never 0). Humidity/precipitationChance are converted from 0-1 to whole percent.
  - Caching: key from the coordinates rounded to 2 decimals plus the timezone; success for 10 minutes; a null (unavailable) result cached for 60 seconds. The timezone is the location's, else the owner's profile timezone, else `config('app.timezone')`.
  - `GetWeatherAttribution::__invoke(): ?array` returning `['name' => string, 'logo_light_url' => ?string, 'logo_dark_url' => ?string, 'legal_url' => ?string]` from the attribution response (verify Apple's current field names), cached 1 day; null when the client returns null (cached 60 seconds).
  - Both read only the cached/normalised result; they never expose the token or raw WeatherKit payload.

- [ ] **Step 1: Write the failing tests** with a realistic fixture of a WeatherKit response (build it from Apple's documented shape; include one hourly entry in the past that must be skipped): normalisation of every field above and unit conversions (humidity 0.74 -> 74, precipitationChance 0.3 -> 30); the next-6-hours and 5-days slicing; a response missing `uvIndex`, `precipitationChance` and `sunrise` gives nulls, not zeros; condition humanising (`MostlyCloudy` -> `Mostly cloudy`, `ScatteredThunderstorms` -> `Scattered thunderstorms`); the second call within 10 minutes makes no HTTP request; two Locations 0.001 degrees apart share one cache entry; a failed lookup returns null and the next call within 60 seconds makes no request while one after 60 seconds retries (`Carbon::setTestNow`); timezone resolution order; attribution mapping and its day cache.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** both actions.
- [ ] **Step 4: Run, expect PASS;** run Pint.
- [ ] **Step 5: Commit** `Weather: place weather and attribution from WeatherKit, cached`.

---

### Task 4: Formatting for the subscriber — FormatPlaceWeather and the resource (backend)

**Files:**
- Create: `app/Support/FormatPlaceWeather.php`, `app/Http/Resources/Api/V1/PlaceWeatherResource.php`
- Test: `tests/Feature/FormatPlaceWeatherTest.php`

**Interfaces:**
- Consumes: the array from `GetPlaceWeather` (Task 3), `App\Support\Units` (`temperatureValue()`, `speedValue()`, `temperatureUnit()`, `speedUnit()` take Fahrenheit/mph inputs: convert Celsius -> Fahrenheit and km/h -> mph first, then call them, so metric users get Celsius/km/h back), a `Location`.
- Produces: `FormatPlaceWeather::forUser(array $weather, Units $units, Location $location): array` returning the card the API serves: `['id' => int, 'label' => string, 'available' => true, 'as_of' => ISO, 'timezone' => string, 'current' => ['temperature' => '78°', 'temperature_value' => int, 'condition' => string, 'condition_code' => string, 'feels_like' => ?'82°', 'wind' => ?'9 mph SE' (direction as 8-point compass; omitted when speed is null), 'humidity' => ?'74%', 'uv_index' => ?int, 'is_daylight' => ?bool], 'hours' => list<['label' => '3 PM' in the place's timezone, 'time' => ISO, 'temperature' => '80°', 'precip_chance' => ?'30%', 'condition_code' => string]>, 'days' => list<['label' => 'Mon' (and 'Today' for the first), 'date', 'high' => '81°', 'low' => '69°', 'precip_chance' => ?'40%', 'condition_code', 'sunrise' => ?'6:48 AM', 'sunset' => ?'5:12 PM']>]`, and `FormatPlaceWeather::unavailable(Location $location): array` returning `['id', 'label', 'available' => false]`. `PlaceWeatherResource` wraps either array (`JsonResource` over the array, no extra keys).
- Fields that are null in the input are OMITTED from the output (not rendered as 0, "—" or "null").

- [ ] **Step 1: Write the failing tests:** an imperial and a metric `Units` render the same input as `78°`/`9 mph SE` and `26°`/`14 km/h SE` (compute expected values, whole degrees, `°` suffix); a place in `America/Denver` shows sunrise/sunset and hour labels in Denver time and the `Today` label follows the place's date even when UTC is already tomorrow; a null UV, precipitation chance, sunrise and wind are omitted from the output; the compass conversion at 0, 45, 180, 350 degrees; `unavailable()` shape.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the formatter and resource.
- [ ] **Step 4: Run, expect PASS;** run Pint.
- [ ] **Step 5: Commit** `Weather: format a place's weather for the subscriber's units and timezone`.

---

### Task 5: GET /api/v1/weather (backend)

**Files:**
- Create: `app/Http/Controllers/Api/V1/WeatherController.php`, `app/Actions/Weather/GetUserWeatherPlaces.php`
- Modify: `routes/api.php` (add `Route::get('/weather', WeatherController::class)->name('api.v1.weather');` inside the existing `ability:app` group, next to `/active-alerts`)
- Test: `tests/Feature/ApiWeatherTest.php`
- Rule: after the tests pass the controller will record a rule with `record-rule` (not you); put 4-6 lines of suggested rule text (glob `app/Http/Controllers/Api/V1/WeatherController.php` and `app/Actions/Weather/GetPlaceWeather.php`) in your report.

**Interfaces:**
- Consumes: `GetPlaceWeather`, `GetWeatherAttribution` (Task 3), `FormatPlaceWeather`, `PlaceWeatherResource` (Task 4), `Units::for($user)`.
- Produces: `GetUserWeatherPlaces::__invoke(User $user): Collection<int, Location>` (the located places, phone first, then id, max 25). `GET /api/v1/weather[?location={id}]` answers `{"data": {"places": [{"id": int, "label": string, "is_phone": bool}], "selected": <card from FormatPlaceWeather or the unavailable shape> | null, "attribution": {...} | null}, "meta": {"has_places": bool, "generated_at": ISO}}`. No `location` selects the first place; with no places `selected` is null, `places` empty, `has_places` false. A `location` that is not in the caller's places (another user's, no coordinates, beyond the cap) is 404; a non-integer is 422 (validated as `integer`). Exactly one `GetPlaceWeather` call per request; the whole response never 500s because of WeatherKit.
- The `attribution` key is present whenever `selected` is non-null and `available`.

- [ ] **Step 1: Write the failing tests** (Sanctum app token as the existing API tests do — copy the auth setup of `tests/Feature/ApiActiveAlertsTest.php`; `Http::fake()` for WeatherKit): no places -> `has_places` false and no HTTP request; two places with the phone second by id -> the phone chip first with `is_phone` true, the first card is the phone's; `?location=` of the second place returns its card and exactly one WeatherKit weather call; another user's location id, a location with null coordinates and `?location=abc` -> 404/404/422; WeatherKit returning 500 -> 200 with `selected.available` false and no attribution key problems; metric vs imperial users get different strings from the same cached data (assert one WeatherKit call across both requests); a beacon (`location` ability) token is refused (403); an unauthenticated request is 401.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the action, controller and route.
- [ ] **Step 4: Run, expect PASS;** run the existing `ApiActiveAlertsTest.php` and Pint.
- [ ] **Step 5: Commit** `API: GET /api/v1/weather for the app's Weather card`.

---

### Task 6: WxNotifyApi::weather and the local cache (app)

**Files:**
- Modify: `app/Services/WxNotifyApi.php` (add `weather(?int $locationId = null): ?array`), `app/Support/LocalCache.php` (add `rememberWeather(int $locationId, array $body)`, `weather(int $locationId): ?array`, `rememberWeatherPlaces(array $places)`, `weatherPlaces(): ?array`, and clear them in `wipe()`)
- Test: `tests/Feature/WxNotifyApiWeatherTest.php`, `tests/Feature/LocalCacheWeatherTest.php`

**Interfaces:**
- Consumes: the backend contract from Task 5 (`data.places`, `data.selected`, `data.attribution`, `meta.has_places`, `meta.generated_at`).
- Produces: `WxNotifyApi::weather(?int $locationId): ?array` returns the decoded body (the whole `{data, meta}` array) or `null` when the server answers 404 (an older backend; treat as "feature absent"); other failures throw the same exceptions the other methods throw (`Offline`, `ApiException`, `Unauthenticated`) — follow how `activeAlerts()` and `send()` do it and how a 404 surfaces there. The `locationId` goes as `?location=`. `LocalCache`: one entry per place id (`wxnotify.weather.{id}`) holding that place's last `{data, meta}` body plus `fetched_at`, and one entry for the last chips list; `wipe()` forgets the chips and every place entry (track the ids in the chips entry or one index key, so nothing is orphaned).

- [ ] **Step 1: Write the failing tests** following `LocalCacheActiveAlertsTest.php` and the existing `WxNotifyApi` tests (read them and `tests/Fakes/FakeNetwork.php` first): the request hits `/api/v1/weather` with and without `?location=`; a 404 returns null; offline throws `Offline`; a 401 throws `Unauthenticated`; the cache round-trips per place and the chips list, and `wipe()` removes them all (sign-out leaves nothing behind).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the API method and the cache methods.
- [ ] **Step 4: Run, expect PASS;** run `vendor/bin/pint --dirty --format agent` and `php artisan native:validate`.
- [ ] **Step 5: Commit** `App: fetch and cache the weather card data`.

---

### Task 7: WeatherCardPresenter (app)

**Files:**
- Create: `app/Support/WeatherCardPresenter.php`
- Test: `tests/Feature/WeatherCardPresenterTest.php`

**Interfaces:**
- Consumes: a body from Task 6 (`{data: {places, selected, attribution}, meta}`).
- Produces: pure static helpers the screen's Blade view calls: `chips(array $places, ?int $selectedId): list<['id' => int, 'label' => string, 'selected' => bool]>`; `isAvailable(?array $selected): bool`; `summaryLine(array $current): string` (`"78° · Mostly cloudy"`), `detailLine(array $current): ?string` (feels like, wind, humidity, UV joined with ` · `, skipping missing parts; null when nothing), `hourSlots(array $selected): list<['label','temperature','precip']>` (precip empty string when missing), `daySlots(array $selected): list<['label','range' => '81°/69°','precip']>`, `sunLine(array $selected): ?string` (`"Sunrise 6:48 AM · Sunset 5:12 PM"` using the first day with both, or the parts that exist), `ageLabel(?string $fetchedAt, CarbonInterface $now): ?string` (null under 10 minutes, else `"Updated 25 minutes ago"`), `attributionLegalUrl(?array $attribution): ?string`.

- [ ] **Step 1: Write the failing tests** with fixtures shaped exactly like Task 5's `selected`: every helper above, including missing fields (no UV, no wind, no precipitation chance, no sunrise) and the exact strings quoted here; `chips` marks only the selected id; `ageLabel` boundaries at 9 and 10 minutes; `isAvailable(null)` and `available: false` both false.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the presenter (static methods, no I/O, in the style of `ActiveAlertsPresenter`).
- [ ] **Step 4: Run, expect PASS;** Pint and `native:validate`.
- [ ] **Step 5: Commit** `App: presenter for the Weather card`.

---

### Task 8: The Weather section on the Alerts screen (app)

**Files:**
- Modify: `app/NativeComponents/AlertsScreen.php`, `resources/views/native/alerts-screen.blade.php`
- Create: `resources/views/native/partials/weather-card.blade.php`
- Test: `tests/Feature/AlertsScreenWeatherTest.php`
- Rule/notes: put a short "Suggested notes" section in your report (2-4 lines) for the controller to record in the app's `CLAUDE.md` if appropriate.

**Interfaces:**
- Consumes: `WxNotifyApi::weather()`, `LocalCache` weather methods (Task 6), `WeatherCardPresenter` (Task 7).
- Produces on `AlertsScreen`: public state `weatherPlaces` (array), `weatherSelectedId` (?int), `weatherCard` (?array, the `selected` card), `weatherAttribution` (?array), `weatherFetchedAt` (?string, the phone's clock), `weatherAvailable` (bool, false once the server said 404 or `has_places` is false), `weatherOffline` (bool), `weatherError` (bool); methods `refreshWeather(?int $locationId = null): void` (fetch; on success cache and apply; `Offline`/`ApiException` keep what is shown and set the flag; `Unauthenticated` signs out exactly like `refreshActive()`; 404 -> `weatherAvailable = false`; **any other Throwable is swallowed (logged class name only) so the Alerts screen never breaks**), `selectWeatherPlace(int $id): void` (sets the selection and fetches that place, keeping the previous card if the fetch fails), and `weatherIsStale(): bool` (copy missing or at least 10 minutes old). `mount()` applies the cached chips and the selected place's cached card first, then fetches; `onResume()` and the existing `#[Poll(60000)] pollActive()` also call `refreshWeather($this->weatherSelectedId)` when `weatherIsStale()`.
- View: a "Weather" panel between the active-alerts panel and the "Alerts sent to you" heading, built like the active panel (bordered column, theme tokens; un-prefixed `<pressable @tap=...>` for chips and `native:text` for lines; no plugin elements that `native:validate` rejects): a heading, the chip row (horizontal `native:scroll-view`; hidden when there is a single place), then for an available card the summary line, detail line, sun line, the hour strip, the day strip, the age label when shown, and a footer row with the attribution name and a tappable "Legal" that opens `attribution.legal_url` through the same browser call `openActive()` uses; for an unavailable card the line "Weather unavailable right now" (with the last copy underneath when one exists); the whole section absent when `weatherAvailable` is false or there are no places. The attribution logo is shown with `native:image` using the theme-matching URL if the existing screens already render remote images that way (look at how the app shows images; if there is no remote-image precedent, show the attribution name text instead and say so in your report).

- [ ] **Step 1: Write the failing tests** following `tests/Feature/AlertsScreenTest.php` (read it for how the screen is mounted with faked API/cache): mount with a faked backend returns chips and a card and the rendered tree contains the summary line, an hour label and the "Legal" text; no places and a 404 each leave the tree without the Weather heading; selecting a second chip calls `weather(<id>)` and switches the card, and a failing fetch on selection keeps the previous card; offline mount shows the cached card and the age label; a thrown `RuntimeException` from the weather call does not stop the active-alerts and feed sections from rendering; the 60-second poll refetches only when the copy is 10+ minutes old (use the screen's existing clock approach for `activeRefreshedAt`); `Unauthenticated` signs out and redirects to `/sign-in` like `refreshActive()`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the screen state/methods, the view and the partial.
- [ ] **Step 4: Run** the new test plus `AlertsScreenTest.php` and the whole app suite (`vendor/bin/pest --compact`), `php artisan native:validate` (must be clean), and Pint.
- [ ] **Step 5: Commit** `App: Weather section on the Alerts screen`.
