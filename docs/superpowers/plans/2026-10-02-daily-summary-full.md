# Daily Summary "Full" Message Length Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Daily Weather Summary rule whose stored `message_body_style` is `full` delivers, to Email channels only, a rich HTML email (header with station + sun times, alerts in effect, current conditions, radar snapshot, next-12-hours, 7-day forecast); every other channel keeps getting the unchanged Brief text.

**Architecture:** Structured, queue-serializable report data (`BuildDailySummaryReport`) built once per rule by `DispatchScheduledAlerts`, carried on `DeliverScheduledAlert`, and rendered by a dedicated `DailySummaryMail` only when the destination channel type declares `supportsRichContent()`. Each optional section degrades independently. The radar image is fetched at send time and embedded inline (CID); it is never queued.

**Tech Stack:** Laravel 13 / PHP 8.4, Pest, Laravel Markdown mail, NWS `api.weather.gov` + `radar.weather.gov`, PostgreSQL/PostGIS (`active_wwas`).

**Spec:** `docs/superpowers/specs/2026-10-02-daily-summary-full-design.md` (binding authority; this plan argues from it).

## Global Constraints

- **Rich channels = Email only.** `ChannelType::supportsRichContent()` is true only for `EmailChannelType`; delivery code never switches on a channel slug.
- **A rule is Full only when `$rule->effectiveMessageBodyStyle() === MessageBodyStyle::Full`** (explicit comparison). `null` (Free tier, or a paid rule created before the option existed) is Brief.
- **Brief is unchanged**: `BuildDailySummaryMessage` is not modified; non-rich channels keep today's exact path (including the existing 280-character cap for non-email Brief).
- **Forecast unavailable → no send, `last_scheduled_run_on` stays unset** (unchanged). Every other section (alerts, current conditions, radar, hourly, sun) is built inside its own guard; its failure omits only that section, logged with fixed strings and status codes only — never response bodies, exception messages, or URLs with secrets.
- **Report is plain data**: nested arrays of strings/ints/floats/bools/null only — no models, `Carbon`, or closures — because it is queued. Timestamps are ISO-8601 strings carrying the location timezone offset.
- **Radar bytes never enter the queue.** They are fetched inside `DeliverScheduledAlert::handle()` and handed to the in-process dispatcher via `$meta`.
- **External input never builds a URL unvalidated**: radar site `^[A-Z0-9]{3,4}$`, station id `^[A-Z0-9]{3,5}$`; hosts are fixed in code; the radar request does not follow redirects.
- **Queue compatibility**: `DeliverScheduledAlert`'s new promoted `readonly` property is read once via `$this->report ?? null` (isset semantics), never dereferenced directly — a job serialized before deploy has it uninitialized.
- **Escape everything** in the mail HTML (`{{ }}`, never `{!! !!}` for external strings). The shared Markdown mail layout is kept (`.ai/rules/delivery.md`).
- **Units imperial**; icons from a self-hosted copy of NWS's icon set (`public/images/weather/{day,night}/{code}.png`, fallback `na.png`).
- **Section order in the email**: header (location, station, sunrise/sunset) → Alerts in Effect → Current Conditions → Radar → Next 12 Hours → 7-Day Forecast.
- Project conventions: Pest feature tests; `vendor/bin/pint --dirty --format agent` before each commit; commit directly to `main` (no push); commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## How to run tests (read this once)

The host cannot resolve the `pgsql` hostname and runs out of memory under `php artisan test`. Run Pest inside the app container:

```bash
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact tests/Feature/SomeTest.php'
```

Every "Run:" line below means that command with the stated test file. Pint runs on the host: `vendor/bin/pint --dirty --format agent`.

## File Structure

| Path | Responsibility |
| --- | --- |
| `app/Support/ChannelTypes/Contracts/ChannelType.php` + 9 type classes | `supportsRichContent(): bool` |
| `app/Support/WeatherIcon.php` | NWS icon URL → `{period, code}`; code → absolute local icon URL with fallback |
| `public/images/weather/{day,night}/*.png`, `public/images/weather/na.png` | Self-hosted public-domain icon set + generic fallback |
| `app/Actions/Weather/GetNwsPoint.php` | One cached `/points` lookup shared by the new actions |
| `app/Actions/Weather/GetCurrentConditions.php` | Nearest usable station's latest observation, normalized |
| `app/Actions/Weather/GetHourlyForecast.php` | Next 12 hourly periods |
| `app/Support/SunTimes.php` | Sunrise/sunset via `date_sun_info()` |
| `app/Actions/Weather/GetActiveAlertsForLocation.php` | Alerts in effect from `active_wwas` |
| `app/Actions/Weather/GetRadarSnapshot.php` | Validated radar still frame |
| `app/Actions/Weather/BuildDailySummaryReport.php` | Assembles the queue-safe report |
| `app/Support/DailySummaryPresenter.php` | Report → display strings (HTML view-model + plain text) |
| `app/Mail/DailySummaryMail.php`, `resources/views/mail/daily-summary*.blade.php`, `resources/views/mail/partials/daily-summary.blade.php` | The rich email |
| `app/Support/Delivery/Transports/MailTransport.php` | Picks `DailySummaryMail` when `$meta['daily_summary']` is set |
| `app/Console/Commands/DispatchScheduledAlerts.php`, `app/Jobs/DeliverScheduledAlert.php` | Build/carry/route the report |
| `tests/Pest.php` | Shared `nws*` HTTP fixtures |

---

### Task 1: `ChannelType::supportsRichContent()`

**Files:**
- Modify: `app/Support/ChannelTypes/Contracts/ChannelType.php`
- Modify: `app/Support/ChannelTypes/{Email,Ntfy,Discord,Slack,Telegram,Pushover,Teams,Webhook,WebPush}ChannelType.php`
- Test: `tests/Feature/ChannelTypesTest.php`

**Interfaces:**
- Produces: `ChannelType::supportsRichContent(): bool` — Tasks 9 relies on it.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ChannelTypesTest.php` (add `use App\Support\ChannelTypes\ChannelTypeRegistry;` and `use App\Support\ChannelTypes\Contracts\ChannelType;` to its imports if not already present):

```php
test('only the email channel type supports rich content', function () {
    $rich = app(ChannelTypeRegistry::class)->all()
        ->filter(fn (ChannelType $type): bool => $type->supportsRichContent())
        ->map(fn (ChannelType $type): string => $type->slug())
        ->values()
        ->all();

    expect($rich)->toBe(['email']);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/ChannelTypesTest.php --filter="supports rich content"`
Expected: FAIL — `Call to undefined method ...::supportsRichContent()`.

- [ ] **Step 3: Implement**

In `app/Support/ChannelTypes/Contracts/ChannelType.php`, add after `isAvailable()`:

```php
    /**
     * Whether this channel can render a rich (HTML, inline images) message.
     * Only such channels receive the Daily Weather Summary's Full format;
     * every other type receives the Brief text.
     */
    public function supportsRichContent(): bool;
```

In **each** of the nine type classes, add a method directly after the existing `isAvailable()`. In `EmailChannelType`:

```php
    public function supportsRichContent(): bool
    {
        return true;
    }
```

In the other eight (`Ntfy`, `Discord`, `Slack`, `Telegram`, `Pushover`, `Teams`, `Webhook`, `WebPush`):

```php
    public function supportsRichContent(): bool
    {
        return false;
    }
```

- [ ] **Step 4: Run to verify it passes, then the type tests**

Run: `tests/Feature/ChannelTypesTest.php`
Expected: all PASS.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/ChannelTypes tests/Feature/ChannelTypesTest.php
git commit -m "Add ChannelType::supportsRichContent(), true only for Email

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `WeatherIcon` and the self-hosted icon set

**Files:**
- Create: `app/Support/WeatherIcon.php`
- Create: `public/images/weather/day/*.png`, `public/images/weather/night/*.png`, `public/images/weather/na.png`
- Test: `tests/Feature/WeatherIconTest.php`

**Interfaces:**
- Produces:
  - `WeatherIcon::fromUrl(?string $url): array{period: string, code: string}` — `period` is `day`|`night`, `code` is lowercase `[a-z0-9_]+`; unparseable → `['period' => 'day', 'code' => 'na']`.
  - `WeatherIcon::url(string $period, string $code): string` — absolute URL of the local icon, or of `na.png` when the file is missing or the code is unsafe.

- [ ] **Step 1: Download the icon set (one-off; the images ship, the commands do not)**

Run from the repo root, as one subshell so the working directory is not changed:

```bash
(
  mkdir -p public/images/weather/day public/images/weather/night
  cd public/images/weather
  UA='User-Agent: (WxNotify, https://wxnotify.test)'
  codes=$(curl -sf -H "$UA" https://api.weather.gov/icons | python3 -c 'import json,sys; print(" ".join(sorted(json.load(sys.stdin)["icons"].keys())))')
  echo "$codes" | wc -w
  for p in day night; do
    for c in $codes; do
      curl -sf -H "$UA" -o "$p/$c.png" "https://api.weather.gov/icons/land/$p/$c?size=medium" || { echo "missing $p/$c"; rm -f "$p/$c.png"; }
    done
  done
  file day/skc.png night/skc.png
  ls day | wc -l; ls night | wc -l
)
```

Expected: the code count is > 30; most files exist; `file` reports `PNG image data`. Any "missing" line is acceptable only if it is a code NWS lists but does not serve; report it in the commit message. If `file` reports anything other than PNG for any file, delete that file.

- [ ] **Step 2: Create the generic fallback icon `na.png` (50×50 transparent PNG, no dependencies)**

Run from the repo root:

```bash
python3 - <<'EOF'
import zlib, struct
def chunk(t, d):
    c = struct.pack('>I', len(d)) + t + d
    return c + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
w = h = 50
raw = b''.join(b'\x00' + b'\x00\x00\x00\x00' * w for _ in range(h))
png = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 6, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')
open('public/images/weather/na.png', 'wb').write(png)
EOF
file public/images/weather/na.png
```

Expected: `PNG image data, 50 x 50`.

- [ ] **Step 3: Write the failing test**

Create `tests/Feature/WeatherIconTest.php`:

```php
<?php

use App\Support\WeatherIcon;

test('it parses the period and code out of an NWS icon url', function () {
    expect(WeatherIcon::fromUrl('https://api.weather.gov/icons/land/day/skc?size=medium'))
        ->toBe(['period' => 'day', 'code' => 'skc'])
        ->and(WeatherIcon::fromUrl('https://api.weather.gov/icons/land/night/few?size=small'))
        ->toBe(['period' => 'night', 'code' => 'few']);
});

test('a probability suffix and a dual-condition url keep only the first code', function () {
    expect(WeatherIcon::fromUrl('https://api.weather.gov/icons/land/night/tsra_hi,40/rain,20?size=small'))
        ->toBe(['period' => 'night', 'code' => 'tsra_hi'])
        ->and(WeatherIcon::fromUrl('https://api.weather.gov/icons/land/day/rain_showers,30?size=medium'))
        ->toBe(['period' => 'day', 'code' => 'rain_showers']);
});

test('an unparseable or missing url falls back to the generic icon', function () {
    expect(WeatherIcon::fromUrl(null))->toBe(['period' => 'day', 'code' => 'na'])
        ->and(WeatherIcon::fromUrl('not a url'))->toBe(['period' => 'day', 'code' => 'na'])
        ->and(WeatherIcon::fromUrl('https://api.weather.gov/icons/marine/day/skc'))->toBe(['period' => 'day', 'code' => 'na']);
});

test('url() returns the absolute local file url when the icon exists', function () {
    config(['app.url' => 'https://alerts.example.test']);

    expect(WeatherIcon::url('day', 'skc'))->toBe('https://alerts.example.test/images/weather/day/skc.png')
        ->and(WeatherIcon::url('night', 'few'))->toBe('https://alerts.example.test/images/weather/night/few.png');
});

test('url() falls back to the generic icon for a missing file or an unsafe code', function () {
    config(['app.url' => 'https://alerts.example.test']);
    $fallback = 'https://alerts.example.test/images/weather/na.png';

    expect(WeatherIcon::url('day', 'definitely_not_a_code'))->toBe($fallback)
        ->and(WeatherIcon::url('day', '../logo'))->toBe($fallback)
        ->and(WeatherIcon::url('sideways', 'skc'))->toBe('https://alerts.example.test/images/weather/day/skc.png');
});

test('the shipped icon set covers the common sky conditions for both day and night', function () {
    foreach (['skc', 'few', 'sct', 'bkn', 'ovc', 'rain', 'snow', 'fog'] as $code) {
        expect(public_path("images/weather/day/{$code}.png"))->toBeFile()
            ->and(public_path("images/weather/night/{$code}.png"))->toBeFile();
    }
});
```

- [ ] **Step 4: Run to verify it fails**

Run: `tests/Feature/WeatherIconTest.php`
Expected: FAIL — class `App\Support\WeatherIcon` not found.

- [ ] **Step 5: Implement `app/Support/WeatherIcon.php`**

```php
<?php

namespace App\Support;

/**
 * Maps NWS's (deprecated, but still served) icon URLs to a self-hosted copy of
 * the same public-domain icon set, so nothing at runtime depends on NWS
 * continuing to serve them. The code is the first path segment after
 * /icons/land/{day|night}/ — NWS appends ",{probability}" and may join two
 * conditions with "/", both of which this ignores.
 */
class WeatherIcon
{
    private const FALLBACK = 'na';

    /**
     * @return array{period: string, code: string}
     */
    public static function fromUrl(?string $url): array
    {
        if ($url !== null && preg_match('#/icons/land/(day|night)/([a-z0-9_]+)#i', $url, $matches) === 1) {
            return ['period' => strtolower($matches[1]), 'code' => strtolower($matches[2])];
        }

        return ['period' => 'day', 'code' => self::FALLBACK];
    }

    public static function url(string $period, string $code): string
    {
        $period = $period === 'night' ? 'night' : 'day';

        if (preg_match('/^[a-z0-9_]+$/', $code) === 1 && $code !== self::FALLBACK) {
            $relative = "images/weather/{$period}/{$code}.png";

            if (is_file(public_path($relative))) {
                return url($relative);
            }
        }

        return url('images/weather/'.self::FALLBACK.'.png');
    }
}
```

- [ ] **Step 6: Run to verify it passes**

Run: `tests/Feature/WeatherIconTest.php`
Expected: PASS. (If `definitely_not_a_code` or a code in the last test is flagged missing, the Step 1 download was incomplete — redo it rather than weakening the test.)

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/WeatherIcon.php public/images/weather tests/Feature/WeatherIconTest.php
git commit -m "Add WeatherIcon and a self-hosted copy of the NWS icon set

NWS marks its icon property deprecated; mail icons are served from our own
public/images/weather instead.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `GetNwsPoint` and `GetCurrentConditions`

**Files:**
- Create: `app/Actions/Weather/GetNwsPoint.php`, `app/Actions/Weather/GetCurrentConditions.php`
- Modify: `tests/Pest.php` (shared `nws*` fixtures — append at the end)
- Test: `tests/Feature/GetCurrentConditionsTest.php`

**Interfaces:**
- Consumes: `WeatherIcon::fromUrl()` (Task 2).
- Produces:
  - `GetNwsPoint::__invoke(Location $location): ?array{forecast: ?string, forecastHourly: ?string, observationStations: ?string, radarStation: ?string, timeZone: ?string}`
  - `GetCurrentConditions::__invoke(Location $location): ?array` returning:
    ```php
    [
        'station' => ['id' => string, 'name' => ?string, 'lat' => ?float, 'lng' => ?float, 'elevation_ft' => ?float],
        'observed_at' => string,            // ISO-8601 in the location's timezone
        'description' => ?string,
        'icon' => ['period' => string, 'code' => string],
        'temperature_f' => float,
        'feels_like_f' => float,            // windChill ?? heatIndex ?? temperature
        'humidity_pct' => ?float,
        'wind' => ['direction' => ?string, 'speed_mph' => ?float, 'gust_mph' => ?float],
        'barometer_inhg' => ?float,
        'dewpoint_f' => ?float,
        'visibility_mi' => ?float,
    ]
    ```
- Shared fixtures added to `tests/Pest.php` (used again in Tasks 4, 7, 9):
  - `nwsPoint(array $overrides = []): array` — decoded `/points` body.
  - `nwsStations(array $stations): array` — decoded stations-list body from `[['id' => 'KEIK', 'name' => ..., 'lng' => ..., 'lat' => ..., 'elevation_m' => ...], ...]`.
  - `nwsObservation(array $overrides = []): array` — decoded `observations/latest` body (recursive override).

- [ ] **Step 1: Add the shared fixtures to the end of `tests/Pest.php`**

```php
/**
 * Http::fake() calls STACK -- the first matching stub wins, so faking the same
 * URL a second time in one test is silently ignored. Any test that re-fakes
 * (e.g. to try a second scenario after Cache::flush()) must start from a fresh
 * client; every nws* fake helper below does this itself.
 */
function nwsFreshHttp(): void
{
    Illuminate\Support\Facades\Http::swap(new Illuminate\Http\Client\Factory);
}

/**
 * Decoded body of an NWS /points response. Overrides replace top-level
 * `properties` keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nwsPoint(array $overrides = []): array
{
    return ['properties' => array_replace([
        'forecast' => 'https://api.weather.gov/gridpoints/BOU/64,73/forecast',
        'forecastHourly' => 'https://api.weather.gov/gridpoints/BOU/64,73/forecast/hourly',
        'forecastGridData' => 'https://api.weather.gov/gridpoints/BOU/64,73',
        'observationStations' => 'https://api.weather.gov/gridpoints/BOU/64,73/stations',
        'radarStation' => 'KFTG',
        'timeZone' => 'America/Denver',
    ], $overrides)];
}

/**
 * @param  array<int, array{id: string, name?: string, lng?: float, lat?: float, elevation_m?: float}>  $stations
 * @return array<string, mixed>
 */
function nwsStations(array $stations): array
{
    return ['features' => array_map(fn (array $station): array => [
        'geometry' => ['type' => 'Point', 'coordinates' => [$station['lng'] ?? -105.05033, $station['lat'] ?? 40.01169]],
        'properties' => [
            'stationIdentifier' => $station['id'],
            'name' => $station['name'] ?? 'Erie Municipal Airport',
            'elevation' => ['unitCode' => 'wmoUnit:m', 'value' => $station['elevation_m'] ?? 1549.908],
        ],
    ], $stations)];
}

/**
 * Decoded body of an NWS observations/latest response (recursive overrides,
 * so ['temperature' => ['value' => null]] nulls just that value).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nwsObservation(array $overrides = []): array
{
    return ['properties' => array_replace_recursive([
        'timestamp' => '2026-10-02T14:35:00+00:00',
        'textDescription' => 'Clear',
        'icon' => 'https://api.weather.gov/icons/land/day/skc?size=medium',
        'temperature' => ['value' => 12.0, 'unitCode' => 'wmoUnit:degC'],
        'dewpoint' => ['value' => 8.0, 'unitCode' => 'wmoUnit:degC'],
        'windDirection' => ['value' => 0, 'unitCode' => 'wmoUnit:degree_(angle)'],
        'windSpeed' => ['value' => 0.0, 'unitCode' => 'wmoUnit:km_h-1'],
        'windGust' => ['value' => null, 'unitCode' => 'wmoUnit:km_h-1'],
        'barometricPressure' => ['value' => 102510, 'unitCode' => 'wmoUnit:Pa'],
        'seaLevelPressure' => ['value' => null, 'unitCode' => 'wmoUnit:Pa'],
        'relativeHumidity' => ['value' => 76.5, 'unitCode' => 'wmoUnit:percent'],
        'windChill' => ['value' => null, 'unitCode' => 'wmoUnit:degC'],
        'heatIndex' => ['value' => null, 'unitCode' => 'wmoUnit:degC'],
        'visibility' => ['value' => 16090, 'unitCode' => 'wmoUnit:m'],
    ], $overrides)];
}
```

- [ ] **Step 2: Write the failing test `tests/Feature/GetCurrentConditionsTest.php`**

```php
<?php

use App\Actions\Weather\GetCurrentConditions;
use App\Actions\Weather\GetNwsPoint;
use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 09:00 America/Denver

    $this->location = Location::factory()->create([
        'label' => 'Home', 'lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => 'America/Denver',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $observations  station id => decoded body or [status]
 */
function ccFake(array $stationIds, array $observations): void
{
    nwsFreshHttp();

    $fakes = [
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Http::response(nwsStations(array_map(fn (string $id): array => ['id' => $id], $stationIds))),
    ];

    foreach ($observations as $id => $body) {
        $fakes["api.weather.gov/stations/{$id}/observations/latest"] = is_int($body) ? Http::response([], $body) : Http::response($body);
    }

    Http::fake($fakes);
}

test('GetNwsPoint returns the five properties the summary needs and caches per location', function () {
    Http::fake(['api.weather.gov/points/*' => Http::response(nwsPoint())]);

    $first = (new GetNwsPoint)($this->location);
    $second = (new GetNwsPoint)($this->location);

    expect($first)->toBe([
        'forecast' => 'https://api.weather.gov/gridpoints/BOU/64,73/forecast',
        'forecastHourly' => 'https://api.weather.gov/gridpoints/BOU/64,73/forecast/hourly',
        'observationStations' => 'https://api.weather.gov/gridpoints/BOU/64,73/stations',
        'radarStation' => 'KFTG',
        'timeZone' => 'America/Denver',
    ])->and($second)->toBe($first);
    Http::assertSentCount(1);
});

test('GetNwsPoint rounds coordinates to four decimals for the request, and returns null on failure without caching it', function () {
    Http::fake(['api.weather.gov/points/*' => Http::response([], 503)]);

    expect((new GetNwsPoint)($this->location))->toBeNull();
    expect((new GetNwsPoint)($this->location))->toBeNull();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.weather.gov/points/40.0032,-104.9970');
});

test('GetNwsPoint returns null for a location with no fix and sends nothing', function () {
    Http::fake();

    expect((new GetNwsPoint)(Location::factory()->mobile()->create()))->toBeNull();
    Http::assertNothingSent();
});

test('it normalizes the nearest station\'s observation into imperial display values', function () {
    ccFake(['KEIK', 'KBJC'], ['KEIK' => nwsObservation(), 'KBJC' => nwsObservation()]);

    $current = (new GetCurrentConditions)($this->location);

    expect($current['station'])->toMatchArray(['id' => 'KEIK', 'name' => 'Erie Municipal Airport', 'lat' => 40.01169, 'lng' => -105.05033])
        ->and($current['station']['elevation_ft'])->toEqualWithDelta(5085.0, 0.5)
        ->and($current['observed_at'])->toBe('2026-10-02T08:35:00-06:00')
        ->and($current['description'])->toBe('Clear')
        ->and($current['icon'])->toBe(['period' => 'day', 'code' => 'skc'])
        ->and($current['temperature_f'])->toEqualWithDelta(53.6, 0.01)
        ->and($current['dewpoint_f'])->toEqualWithDelta(46.4, 0.01)
        ->and($current['humidity_pct'])->toEqualWithDelta(76.5, 0.01)
        ->and($current['barometer_inhg'])->toEqualWithDelta(30.27, 0.01)
        ->and($current['visibility_mi'])->toEqualWithDelta(10.0, 0.01);
});

test('feels-like prefers wind chill, then heat index, then the plain temperature', function () {
    ccFake(['KEIK'], ['KEIK' => nwsObservation(['windChill' => ['value' => 0.0], 'heatIndex' => ['value' => 30.0]])]);
    expect((new GetCurrentConditions)($this->location)['feels_like_f'])->toEqualWithDelta(32.0, 0.01);

    Illuminate\Support\Facades\Cache::flush();
    ccFake(['KEIK'], ['KEIK' => nwsObservation(['heatIndex' => ['value' => 30.0]])]);
    expect((new GetCurrentConditions)($this->location)['feels_like_f'])->toEqualWithDelta(86.0, 0.01);

    Illuminate\Support\Facades\Cache::flush();
    ccFake(['KEIK'], ['KEIK' => nwsObservation()]);
    expect((new GetCurrentConditions)($this->location)['feels_like_f'])->toEqualWithDelta(53.6, 0.01);
});

test('wind is a compass direction with speed, gust only when reported, and calm has no direction', function () {
    ccFake(['KEIK'], ['KEIK' => nwsObservation([
        'windDirection' => ['value' => 45], 'windSpeed' => ['value' => 16.0934], 'windGust' => ['value' => 32.1868],
    ])]);
    $windy = (new GetCurrentConditions)($this->location)['wind'];

    expect($windy['direction'])->toBe('NE')
        ->and($windy['speed_mph'])->toEqualWithDelta(10.0, 0.01)
        ->and($windy['gust_mph'])->toEqualWithDelta(20.0, 0.01);

    Illuminate\Support\Facades\Cache::flush();
    ccFake(['KEIK'], ['KEIK' => nwsObservation()]);
    $calm = (new GetCurrentConditions)($this->location)['wind'];

    expect($calm['direction'])->toBeNull()
        ->and($calm['speed_mph'])->toEqualWithDelta(0.0, 0.01)
        ->and($calm['gust_mph'])->toBeNull();
});

test('missing optional readings come back as null, never zero', function () {
    ccFake(['KEIK'], ['KEIK' => nwsObservation([
        'relativeHumidity' => ['value' => null], 'barometricPressure' => ['value' => null],
        'dewpoint' => ['value' => null], 'visibility' => ['value' => null],
    ])]);

    $current = (new GetCurrentConditions)($this->location);

    expect($current['humidity_pct'])->toBeNull()
        ->and($current['barometer_inhg'])->toBeNull()
        ->and($current['dewpoint_f'])->toBeNull()
        ->and($current['visibility_mi'])->toBeNull();
});

test('a station reporting no temperature, or a stale observation, is skipped for the next nearest', function () {
    ccFake(['KEIK', 'KBJC', 'KBDU'], [
        'KEIK' => nwsObservation(['temperature' => ['value' => null]]),
        'KBJC' => nwsObservation(['timestamp' => '2026-10-02T09:00:00+00:00']), // 6h old
        'KBDU' => nwsObservation(['textDescription' => 'Fog']),
    ]);

    $current = (new GetCurrentConditions)($this->location);

    expect($current['station']['id'])->toBe('KBDU')
        ->and($current['description'])->toBe('Fog');
});

test('a station whose observation request fails is skipped', function () {
    ccFake(['KEIK', 'KBJC'], ['KEIK' => 500, 'KBJC' => nwsObservation()]);

    expect((new GetCurrentConditions)($this->location)['station']['id'])->toBe('KBJC');
});

test('only the first five stations are tried, and null comes back when none qualifies', function () {
    $ids = ['KAAA', 'KBBB', 'KCCC', 'KDDD', 'KEEE', 'KFFF'];
    ccFake($ids, array_fill_keys($ids, nwsObservation(['temperature' => ['value' => null]])));

    expect((new GetCurrentConditions)($this->location))->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/stations/KFFF/'));
});

test('a station identifier that is not a plain code never reaches a url', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Http::response(nwsStations([['id' => '../evil'], ['id' => 'KBJC']])),
        'api.weather.gov/stations/KBJC/observations/latest' => Http::response(nwsObservation()),
    ]);

    expect((new GetCurrentConditions)($this->location)['station']['id'])->toBe('KBJC');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil'));
});

test('it returns null when the point lookup or the station list fails', function () {
    Http::fake(['api.weather.gov/points/*' => Http::response([], 503)]);
    expect((new GetCurrentConditions)($this->location))->toBeNull();

    Illuminate\Support\Facades\Cache::flush();
    nwsFreshHttp();
    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Http::response([], 500),
    ]);
    expect((new GetCurrentConditions)($this->location))->toBeNull();
});

test('the result is cached for the location', function () {
    ccFake(['KEIK'], ['KEIK' => nwsObservation()]);

    (new GetCurrentConditions)($this->location);
    (new GetCurrentConditions)($this->location);

    Http::assertSentCount(3); // point + station list + one observation, once
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `tests/Feature/GetCurrentConditionsTest.php`
Expected: FAIL — `GetNwsPoint` / `GetCurrentConditions` classes not found.

- [ ] **Step 4: Implement `app/Actions/Weather/GetNwsPoint.php`**

```php
<?php

namespace App\Actions\Weather;

use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One cached api.weather.gov /points lookup shared by the Full Daily Summary's
 * actions. NWS redirects any coordinate with more than four decimals to the
 * four-decimal URL, so the request is built rounded. The 7-day TTL lets an NWS
 * gridpoint remap eventually refresh; a failed lookup is never cached.
 */
class GetNwsPoint
{
    /**
     * @return array{forecast: ?string, forecastHourly: ?string, observationStations: ?string, radarStation: ?string, timeZone: ?string}|null
     */
    public function __invoke(Location $location): ?array
    {
        if (! $location->hasFix()) {
            return null;
        }

        $lat = number_format((float) $location->lat, 4, '.', '');
        $lng = number_format((float) $location->lng, 4, '.', '');

        return Cache::remember("nws-point:{$lat},{$lng}", now()->addDays(7), function () use ($lat, $lng): ?array {
            $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout(10)
                ->get("https://api.weather.gov/points/{$lat},{$lng}");

            if (! $response->successful()) {
                Log::warning('NWS point lookup failed', ['status' => $response->status()]);

                return null;
            }

            $properties = $response->json('properties');

            if (! is_array($properties)) {
                return null;
            }

            return [
                'forecast' => $properties['forecast'] ?? null,
                'forecastHourly' => $properties['forecastHourly'] ?? null,
                'observationStations' => $properties['observationStations'] ?? null,
                'radarStation' => $properties['radarStation'] ?? null,
                'timeZone' => $properties['timeZone'] ?? null,
            ];
        });
    }

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}
```

- [ ] **Step 5: Implement `app/Actions/Weather/GetCurrentConditions.php`**

```php
<?php

namespace App\Actions\Weather;

use App\Models\Location;
use App\Support\WeatherIcon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The latest observation from the nearest station that has a usable one.
 * NWS lists a point's stations nearest-first, but the nearest is not always
 * the best: some report nulls or stop reporting, so up to five are tried and
 * the first with a temperature no older than three hours wins.
 *
 * Station ids come from an external response and are interpolated into a URL,
 * so they must match a plain station-code pattern first.
 */
class GetCurrentConditions
{
    private const MAX_STATIONS = 5;

    private const MAX_AGE_HOURS = 3;

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(Location $location): ?array
    {
        $point = app(GetNwsPoint::class)($location);
        $stationsUrl = $point['observationStations'] ?? null;

        if (! is_string($stationsUrl)) {
            return null;
        }

        $lat = number_format((float) $location->lat, 4, '.', '');
        $lng = number_format((float) $location->lng, 4, '.', '');

        return Cache::remember(
            "nws-conditions:{$lat},{$lng}",
            now()->addMinutes(10),
            fn (): ?array => $this->resolve($stationsUrl, $location),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(string $stationsUrl, Location $location): ?array
    {
        $stations = $this->get($stationsUrl);

        if ($stations === null || ! $stations->successful()) {
            Log::warning('NWS station list lookup failed', ['status' => $stations?->status()]);

            return null;
        }

        foreach (array_slice($stations->json('features') ?? [], 0, self::MAX_STATIONS) as $feature) {
            $id = $feature['properties']['stationIdentifier'] ?? null;

            if (! is_string($id) || preg_match('/^[A-Z0-9]{3,5}$/', $id) !== 1) {
                continue;
            }

            $observation = $this->get("https://api.weather.gov/stations/{$id}/observations/latest");

            if ($observation === null || ! $observation->successful()) {
                continue;
            }

            $properties = $observation->json('properties');

            if (is_array($properties) && $this->isUsable($properties)) {
                return $this->normalize($feature, $properties, $location);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function isUsable(array $properties): bool
    {
        if ($this->value($properties, 'temperature') === null || ! is_string($properties['timestamp'] ?? null)) {
            return false;
        }

        return Carbon::parse($properties['timestamp'])->gte(now()->subHours(self::MAX_AGE_HOURS));
    }

    /**
     * @param  array<string, mixed>  $feature
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function normalize(array $feature, array $properties, Location $location): array
    {
        $timezone = $location->timezone ?? config('app.timezone');
        $temperatureF = $this->celsiusToFahrenheit($this->value($properties, 'temperature'));
        $feelsLikeC = $this->value($properties, 'windChill') ?? $this->value($properties, 'heatIndex');
        $windSpeed = $this->kmhToMph($this->value($properties, 'windSpeed'));
        $windDegrees = $this->value($properties, 'windDirection');
        $pressurePa = $this->value($properties, 'barometricPressure') ?? $this->value($properties, 'seaLevelPressure');
        $visibilityM = $this->value($properties, 'visibility');
        $elevationM = data_get($feature, 'properties.elevation.value');
        $coordinates = data_get($feature, 'geometry.coordinates');

        return [
            'station' => [
                'id' => $feature['properties']['stationIdentifier'],
                'name' => $feature['properties']['name'] ?? null,
                'lat' => is_array($coordinates) && isset($coordinates[1]) ? (float) $coordinates[1] : null,
                'lng' => is_array($coordinates) && isset($coordinates[0]) ? (float) $coordinates[0] : null,
                'elevation_ft' => is_numeric($elevationM) ? (float) $elevationM * 3.28084 : null,
            ],
            'observed_at' => Carbon::parse($properties['timestamp'])->setTimezone($timezone)->toIso8601String(),
            'description' => $properties['textDescription'] ?? null,
            'icon' => WeatherIcon::fromUrl($properties['icon'] ?? null),
            'temperature_f' => $temperatureF,
            'feels_like_f' => $feelsLikeC !== null ? $this->celsiusToFahrenheit($feelsLikeC) : $temperatureF,
            'humidity_pct' => $this->value($properties, 'relativeHumidity'),
            'wind' => [
                'direction' => $windSpeed !== null && $windSpeed >= 0.5 && $windDegrees !== null ? $this->compass($windDegrees) : null,
                'speed_mph' => $windSpeed,
                'gust_mph' => $this->kmhToMph($this->value($properties, 'windGust')),
            ],
            'barometer_inhg' => $pressurePa !== null ? $pressurePa / 3386.389 : null,
            'dewpoint_f' => $this->celsiusToFahrenheit($this->value($properties, 'dewpoint')),
            'visibility_mi' => $visibilityM !== null ? $visibilityM / 1609.344 : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function value(array $properties, string $key): ?float
    {
        $value = data_get($properties, "{$key}.value");

        return is_numeric($value) ? (float) $value : null;
    }

    private function celsiusToFahrenheit(?float $celsius): ?float
    {
        return $celsius === null ? null : $celsius * 9 / 5 + 32;
    }

    private function kmhToMph(?float $kmh): ?float
    {
        return $kmh === null ? null : $kmh / 1.609344;
    }

    private function compass(float $degrees): string
    {
        $points = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        $normalized = fmod(fmod($degrees, 360) + 360, 360);

        return $points[(int) round($normalized / 22.5) % 16];
    }

    private function get(string $url): ?Response
    {
        try {
            return Http::withHeaders(['User-Agent' => sprintf('(%s, %s)', config('app.name'), config('app.url'))])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }
    }
}
```

- [ ] **Step 6: Run to verify it passes**

Run: `tests/Feature/GetCurrentConditionsTest.php`
Expected: all PASS. If a unit-conversion assertion misses by a hair, fix the code, not the expected value (the expected values are hand-computed: 12°C → 53.6°F, 102510 Pa → 30.27 inHg, 16090 m → 10.0 mi, 1549.908 m → 5085 ft, 16.0934 km/h → 10.0 mph).

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Weather/GetNwsPoint.php app/Actions/Weather/GetCurrentConditions.php tests/Pest.php tests/Feature/GetCurrentConditionsTest.php
git commit -m "Add GetNwsPoint and GetCurrentConditions for the Full Daily Summary

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `GetHourlyForecast` and `SunTimes`

**Files:**
- Create: `app/Actions/Weather/GetHourlyForecast.php`, `app/Support/SunTimes.php`
- Test: `tests/Feature/GetHourlyForecastTest.php`, `tests/Feature/SunTimesTest.php`

**Interfaces:**
- Consumes: `GetNwsPoint` (`forecastHourly`), `WeatherIcon::fromUrl()`, `nwsPoint()` fixture.
- Produces:
  - `GetHourlyForecast::__invoke(Location $location): ?array` — list of up to 12 entries:
    ```php
    ['time' => string /* ISO in location tz */, 'icon' => ['period' => string, 'code' => string], 'temperature' => int, 'unit' => string, 'precip_chance' => ?int, 'wind' => string, 'summary' => string]
    ```
  - `SunTimes::forLocation(Location $location): ?array{sunrise: string, sunset: string}` (ISO strings in the location's timezone).

- [ ] **Step 1: Write `tests/Feature/GetHourlyForecastTest.php` (failing)**

```php
<?php

use App\Actions\Weather\GetHourlyForecast;
use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 09:00 America/Denver
    $this->location = Location::factory()->create(['lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => 'America/Denver']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array<int, array<string, mixed>>
 */
function hourlyPeriods(int $count, string $firstStart = '2026-10-02T07:00:00-06:00'): array
{
    return collect(range(0, $count - 1))->map(function (int $i) use ($firstStart): array {
        $start = Carbon::parse($firstStart)->addHours($i);

        return [
            'startTime' => $start->toIso8601String(),
            'endTime' => $start->copy()->addHour()->toIso8601String(),
            'isDaytime' => true,
            'temperature' => 50 + $i,
            'temperatureUnit' => 'F',
            'probabilityOfPrecipitation' => ['unitCode' => 'wmoUnit:percent', 'value' => $i === 0 ? null : 10 * $i],
            'windSpeed' => '5 to 10 mph',
            'windDirection' => 'NW',
            'icon' => 'https://api.weather.gov/icons/land/day/few?size=small',
            'shortForecast' => 'Sunny',
        ];
    })->all();
}

function fakeHourly(array $periods): void
{
    nwsFreshHttp();

    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => Http::response(['properties' => ['periods' => $periods]]),
    ]);
}

test('it drops periods that have ended and returns the next twelve in order', function () {
    fakeHourly(hourlyPeriods(16)); // 07:00 .. 22:00 local; 07:00 and 08:00 have ended by 09:00

    $hourly = (new GetHourlyForecast)($this->location);

    expect($hourly)->toHaveCount(12)
        ->and(Carbon::parse($hourly[0]['time'])->format('H:i'))->toBe('09:00')
        ->and(Carbon::parse($hourly[11]['time'])->format('H:i'))->toBe('20:00');
});

test('each entry carries the display fields, with a null precip chance kept null', function () {
    fakeHourly(hourlyPeriods(16, '2026-10-02T09:00:00-06:00'));

    $first = (new GetHourlyForecast)($this->location)[0];

    expect($first)->toBe([
        'time' => '2026-10-02T09:00:00-06:00',
        'icon' => ['period' => 'day', 'code' => 'few'],
        'temperature' => 50,
        'unit' => 'F',
        'precip_chance' => null,
        'wind' => '5 to 10 mph NW',
        'summary' => 'Sunny',
    ]);
});

test('it returns what exists when fewer than twelve periods remain', function () {
    fakeHourly(hourlyPeriods(5, '2026-10-02T09:00:00-06:00'));

    expect((new GetHourlyForecast)($this->location))->toHaveCount(5);
});

test('it returns null when nothing remains, the lookup fails, or there is no hourly url', function () {
    fakeHourly(hourlyPeriods(2, '2026-10-02T05:00:00-06:00')); // both ended
    expect((new GetHourlyForecast)($this->location))->toBeNull();

    Illuminate\Support\Facades\Cache::flush();
    nwsFreshHttp();
    Http::fake([
        'api.weather.gov/points/*' => Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => Http::response([], 500),
    ]);
    expect((new GetHourlyForecast)($this->location))->toBeNull();

    Illuminate\Support\Facades\Cache::flush();
    nwsFreshHttp();
    Http::fake(['api.weather.gov/points/*' => Http::response(nwsPoint(['forecastHourly' => null]))]);
    expect((new GetHourlyForecast)($this->location))->toBeNull();
});
```

- [ ] **Step 2: Write `tests/Feature/SunTimesTest.php` (failing)**

```php
<?php

use App\Models\Location;
use App\Support\SunTimes;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('it returns today\'s sunrise and sunset in the location\'s timezone', function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 09:00 America/Denver
    $location = Location::factory()->create(['lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => 'America/Denver']);

    $sun = SunTimes::forLocation($location);

    expect(Carbon::parse($sun['sunrise'])->format('Y-m-d H:i'))->toBe('2026-10-02 06:57')
        ->and(Carbon::parse($sun['sunset'])->format('Y-m-d H:i'))->toBe('2026-10-02 18:40')
        ->and($sun['sunrise'])->toEndWith('-06:00');
});

test('a timezone east of UTC still gets its own local date, not the previous day\'s', function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 2026-10-03 04:00 Pacific/Auckland
    $location = Location::factory()->create(['lat' => -36.85, 'lng' => 174.76, 'timezone' => 'Pacific/Auckland']);

    $sun = SunTimes::forLocation($location);

    expect(Carbon::parse($sun['sunrise'])->format('Y-m-d'))->toBe('2026-10-03')
        ->and(Carbon::parse($sun['sunset'])->format('Y-m-d'))->toBe('2026-10-03');
});

test('polar day, where the sun never sets, returns null', function () {
    Carbon::setTestNow('2026-06-21 12:00:00');
    $location = Location::factory()->create(['lat' => 80.0, 'lng' => 0.0, 'timezone' => 'UTC']);

    expect(SunTimes::forLocation($location))->toBeNull();
});

test('a location with no fix returns null', function () {
    expect(SunTimes::forLocation(Location::factory()->mobile()->create()))->toBeNull();
});
```

- [ ] **Step 3: Run both to verify they fail**

Run: `tests/Feature/GetHourlyForecastTest.php` and `tests/Feature/SunTimesTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 4: Implement `app/Actions/Weather/GetHourlyForecast.php`**

```php
<?php

namespace App\Actions\Weather;

use App\Models\Location;
use App\Support\WeatherIcon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The next twelve hours of NWS's hourly forecast. The raw period list is
 * cached for an hour (like the 7-day forecast) and elapsed periods are
 * dropped at read time, so a cached list never serves stale hours.
 */
class GetHourlyForecast
{
    private const HOURS = 12;

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function __invoke(Location $location): ?array
    {
        $url = app(GetNwsPoint::class)($location)['forecastHourly'] ?? null;

        if (! is_string($url)) {
            return null;
        }

        $periods = Cache::remember("nws-hourly:{$url}", now()->addHour(), fn (): array => $this->fetch($url));
        $timezone = $location->timezone ?? config('app.timezone');

        $upcoming = collect($periods)
            ->filter(fn (array $period): bool => isset($period['startTime'], $period['endTime']) && Carbon::parse($period['endTime'])->gt(now()))
            ->take(self::HOURS)
            ->map(fn (array $period): array => [
                'time' => Carbon::parse($period['startTime'])->setTimezone($timezone)->toIso8601String(),
                'icon' => WeatherIcon::fromUrl($period['icon'] ?? null),
                'temperature' => $period['temperature'] ?? null,
                'unit' => $period['temperatureUnit'] ?? 'F',
                'precip_chance' => is_numeric($chance = data_get($period, 'probabilityOfPrecipitation.value')) ? (int) round($chance) : null,
                'wind' => trim(($period['windSpeed'] ?? '').' '.($period['windDirection'] ?? '')),
                'summary' => $period['shortForecast'] ?? '',
            ])
            ->values()
            ->all();

        return $upcoming === [] ? null : $upcoming;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch(string $url): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => sprintf('(%s, %s)', config('app.name'), config('app.url'))])
                ->timeout(10)
                ->get($url);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            Log::warning('NWS hourly forecast fetch failed', ['status' => $response->status()]);

            return [];
        }

        return $response->json('properties.periods') ?? [];
    }
}
```

- [ ] **Step 5: Implement `app/Support/SunTimes.php`**

```php
<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Support\Carbon;

/**
 * Sunrise/sunset for today in the location's timezone, computed locally by
 * PHP's date_sun_info() (NWS publishes neither). date_sun_info() resolves "the
 * day" from the timestamp's UTC date, so a zone far east of UTC (Pacific/
 * Auckland) given its own local noon would land on the previous calendar day;
 * anchoring on UTC noon of the *local* calendar date returns the right day
 * everywhere (verified for Denver, Honolulu and Auckland).
 */
class SunTimes
{
    /**
     * @return array{sunrise: string, sunset: string}|null
     */
    public static function forLocation(Location $location): ?array
    {
        if (! $location->hasFix()) {
            return null;
        }

        $timezone = $location->timezone ?? config('app.timezone');
        $localToday = Carbon::now($timezone);
        $anchor = Carbon::create($localToday->year, $localToday->month, $localToday->day, 12, 0, 0, 'UTC');

        $info = date_sun_info($anchor->getTimestamp(), (float) $location->lat, (float) $location->lng);

        if (! is_int($info['sunrise'] ?? null) || ! is_int($info['sunset'] ?? null)) {
            return null;
        }

        return [
            'sunrise' => Carbon::createFromTimestamp($info['sunrise'], $timezone)->toIso8601String(),
            'sunset' => Carbon::createFromTimestamp($info['sunset'], $timezone)->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 6: Run to verify they pass**

Run: `tests/Feature/GetHourlyForecastTest.php` then `tests/Feature/SunTimesTest.php`
Expected: all PASS.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Weather/GetHourlyForecast.php app/Support/SunTimes.php tests/Feature/GetHourlyForecastTest.php tests/Feature/SunTimesTest.php
git commit -m "Add GetHourlyForecast and SunTimes for the Full Daily Summary

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `GetActiveAlertsForLocation`

**Files:**
- Create: `app/Actions/Weather/GetActiveAlertsForLocation.php`
- Test: `tests/Feature/GetActiveAlertsForLocationTest.php`

**Interfaces:**
- Consumes: `ActiveWwa`, `Product`, `ResolveLocationZones`, `ProductFamily`.
- Produces: `GetActiveAlertsForLocation::__invoke(Location $location, bool $includeTest = false): array` — list of `['event' => string, 'headline' => ?string, 'expires' => ?string /* ISO in location tz */, 'family' => ?string]`, ordered by family severity (warning, watch, advisory, statement, other) then soonest expiry.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00');

    $this->location = Location::factory()->create([
        'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver',
        'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function alertFor(string $name, string $family, array $attributes = []): ActiveWwa
{
    return ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => $name, 'family' => $family])->id,
        ...$attributes,
    ]);
}

function setAlertPolygon(ActiveWwa $wwa, string $wkt): void
{
    DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $wwa->id]);
}

const INSIDE_POLYGON = 'POLYGON((-105.5 39.5, -105.5 40.5, -104.5 40.5, -104.5 39.5, -105.5 39.5))';
const OUTSIDE_POLYGON = 'POLYGON((-100.5 35.5, -100.5 36.5, -99.5 36.5, -99.5 35.5, -100.5 35.5))';

test('a polygon alert is included only when the polygon contains the location', function () {
    $inside = alertFor('Tornado Warning', 'warning', ['ugc_codes' => ['COC999'], 'headline' => 'Tornado Warning issued']);
    setAlertPolygon($inside, INSIDE_POLYGON);
    $outside = alertFor('Severe Thunderstorm Warning', 'warning', ['ugc_codes' => ['COC999']]);
    setAlertPolygon($outside, OUTSIDE_POLYGON);

    $alerts = (new GetActiveAlertsForLocation)($this->location);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0])->toMatchArray(['event' => 'Tornado Warning', 'headline' => 'Tornado Warning issued', 'family' => 'warning']);
});

test('the polygon wins over UGC overlap: a polygon that excludes the location is excluded even if its zone codes overlap', function () {
    $wwa = alertFor('Flash Flood Warning', 'warning', ['ugc_codes' => ['COZ040', 'COC014']]);
    setAlertPolygon($wwa, OUTSIDE_POLYGON);

    expect((new GetActiveAlertsForLocation)($this->location))->toBe([]);
});

test('a polygon-less alert matches by forecast zone or county code', function () {
    alertFor('Winter Weather Advisory', 'advisory', ['ugc_codes' => ['COZ040']]);
    alertFor('Red Flag Warning', 'warning', ['ugc_codes' => ['COC014']]);
    alertFor('Wind Advisory', 'advisory', ['ugc_codes' => ['COZ099']]);

    $events = collect((new GetActiveAlertsForLocation)($this->location))->pluck('event')->all();

    expect($events)->toContain('Winter Weather Advisory', 'Red Flag Warning')
        ->and($events)->not->toContain('Wind Advisory');
});

test('expired, cancelled and past-expiry alerts are excluded; a null expiry stays in effect', function () {
    alertFor('Expired One', 'warning', ['ugc_codes' => ['COZ040'], 'status' => 'Expired']);
    alertFor('Cancelled One', 'warning', ['ugc_codes' => ['COZ040'], 'status' => 'Cancelled']);
    alertFor('Lapsed One', 'warning', ['ugc_codes' => ['COZ040'], 'expires' => now()->subMinute()]);
    alertFor('Open Ended', 'statement', ['ugc_codes' => ['COZ040'], 'expires' => null]);

    expect(collect((new GetActiveAlertsForLocation)($this->location))->pluck('event')->all())->toBe(['Open Ended']);
});

test('test alerts appear only when the owner is a test account', function () {
    alertFor('Synthetic Warning', 'warning', ['ugc_codes' => ['COZ040'], 'is_test' => true]);
    alertFor('Real Advisory', 'advisory', ['ugc_codes' => ['COZ040'], 'is_test' => false]);

    expect(collect((new GetActiveAlertsForLocation)($this->location, false))->pluck('event')->all())->toBe(['Real Advisory'])
        ->and(collect((new GetActiveAlertsForLocation)($this->location, true))->pluck('event')->all())->toBe(['Synthetic Warning', 'Real Advisory']);
});

test('alerts are ordered by family severity, then soonest expiry', function () {
    alertFor('Advisory Late', 'advisory', ['ugc_codes' => ['COZ040'], 'expires' => now()->addHours(5)]);
    alertFor('Watch', 'watch', ['ugc_codes' => ['COZ040'], 'expires' => now()->addHours(2)]);
    alertFor('Warning Late', 'warning', ['ugc_codes' => ['COZ040'], 'expires' => now()->addHours(4)]);
    alertFor('Warning Soon', 'warning', ['ugc_codes' => ['COZ040'], 'expires' => now()->addHour()]);
    alertFor('Statement', 'statement', ['ugc_codes' => ['COZ040'], 'expires' => now()->addMinutes(30)]);

    expect(collect((new GetActiveAlertsForLocation)($this->location))->pluck('event')->all())
        ->toBe(['Warning Soon', 'Warning Late', 'Watch', 'Advisory Late', 'Statement']);
});

test('expiry is an ISO string in the location\'s timezone', function () {
    alertFor('Warning', 'warning', ['ugc_codes' => ['COZ040'], 'expires' => Carbon::parse('2026-10-02 21:00:00')]); // 15:00 MDT

    expect((new GetActiveAlertsForLocation)($this->location)[0]['expires'])->toBe('2026-10-02T15:00:00-06:00');
});

test('a location with no fix returns an empty list', function () {
    alertFor('Warning', 'warning', ['ugc_codes' => ['COZ040']]);

    expect((new GetActiveAlertsForLocation)(Location::factory()->mobile()->create()))->toBe([]);
});

test('a location whose zones cannot be resolved matches only by polygon and never errors', function () {
    $location = Location::factory()->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => null, 'county_ugc' => null]);
    alertFor('Zone Only', 'advisory', ['ugc_codes' => ['COZ040']]);
    $polygon = alertFor('Polygon One', 'warning', ['ugc_codes' => ['COZ040']]);
    setAlertPolygon($polygon, INSIDE_POLYGON);

    expect(collect((new GetActiveAlertsForLocation)($location))->pluck('event')->all())->toBe(['Polygon One']);
});
```

(`ActiveWwa::factory()` exists via the `HasFactory` trait and `ActiveWwaFactory`; `Product::factory()->create(['family' => 'warning'])` is cast to `ProductFamily`.)

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/GetActiveAlertsForLocationTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `app/Actions/Weather/GetActiveAlertsForLocation.php`**

```php
<?php

namespace App\Actions\Weather;

use App\Models\ActiveWwa;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;

/**
 * Watches/warnings/advisories/statements currently in effect at a location,
 * read from our own active_wwas table (the same live data alert rules match
 * against), independent of any subscriber's alert rules.
 *
 * Coverage mirrors MatchWeatherAlertRules: when an alert has a polygon, the
 * polygon is the authoritative extent (so a polygon that excludes the point
 * excludes the alert even if its zone codes overlap); only a polygon-less
 * alert falls back to UGC zone/county overlap.
 */
class GetActiveAlertsForLocation
{
    /**
     * Lower sorts first. Anything not listed (SPC/WPC, informational, etc.)
     * sorts after statements.
     */
    private const FAMILY_RANK = ['warning' => 0, 'watch' => 1, 'advisory' => 2, 'statement' => 3];

    /**
     * @return array<int, array{event: string, headline: ?string, expires: ?string, family: ?string}>
     */
    public function __invoke(Location $location, bool $includeTest = false): array
    {
        if (! $location->hasFix()) {
            return [];
        }

        $zones = $this->zonesFor($location);
        $lng = (float) $location->lng;
        $lat = (float) $location->lat;
        $timezone = $location->timezone ?? config('app.timezone');

        $alerts = ActiveWwa::query()
            ->with('product')
            ->where('status', 'Active')
            ->where(fn (Builder $query) => $query->whereNull('expires')->orWhere('expires', '>', now()))
            ->when(! $includeTest, fn (Builder $query) => $query->where('is_test', false))
            ->where(function (Builder $query) use ($zones, $lng, $lat): void {
                $query->whereRaw('(polygon IS NOT NULL AND ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)))', [$lng, $lat]);

                if ($zones !== []) {
                    $query->orWhere(function (Builder $query) use ($zones): void {
                        $query->whereNull('polygon')->where(function (Builder $query) use ($zones): void {
                            foreach ($zones as $zone) {
                                $query->orWhereJsonContains('ugc_codes', $zone);
                            }
                        });
                    });
                }
            })
            ->get();

        return $alerts
            ->sortBy([
                fn (ActiveWwa $a, ActiveWwa $b): int => $this->rank($a) <=> $this->rank($b),
                fn (ActiveWwa $a, ActiveWwa $b): int => ($a->expires?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->expires?->getTimestamp() ?? PHP_INT_MAX),
            ])
            ->map(fn (ActiveWwa $wwa): array => [
                'event' => $wwa->product?->name ?? 'Weather alert',
                'headline' => $wwa->headline,
                'expires' => $wwa->expires?->copy()->setTimezone($timezone)->toIso8601String(),
                'family' => $wwa->product?->family?->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function zonesFor(Location $location): array
    {
        if ($location->forecast_zone_ugc === null && $location->county_ugc === null) {
            app(ResolveLocationZones::class)($location);
            $location->refresh();
        }

        return array_values(array_filter([$location->forecast_zone_ugc, $location->county_ugc]));
    }

    private function rank(ActiveWwa $wwa): int
    {
        return self::FAMILY_RANK[$wwa->product?->family?->value] ?? 99;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `tests/Feature/GetActiveAlertsForLocationTest.php`
Expected: all PASS. If `orWhereJsonContains('ugc_codes', 'COZ040')` fails against this `json` column, use `->orWhereRaw('ugc_codes::jsonb @> ?::jsonb', [json_encode([$zone])])` instead (array-containment form) and keep the tests unchanged.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Weather/GetActiveAlertsForLocation.php tests/Feature/GetActiveAlertsForLocationTest.php
git commit -m "Add GetActiveAlertsForLocation for the Full Daily Summary

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `GetRadarSnapshot`

**Files:**
- Create: `app/Actions/Weather/GetRadarSnapshot.php`
- Test: `tests/Feature/GetRadarSnapshotTest.php`

**Interfaces:**
- Produces: `GetRadarSnapshot::__invoke(string $site): ?array{bytes: string, mime: string}` — `mime` is `image/gif` or `image/png`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Actions\Weather\GetRadarSnapshot;
use Illuminate\Support\Facades\Http;

const RADAR_GIF = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";
const RADAR_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x00IEND\xaeB`\x82";

test('a valid gif is returned with its bytes and mime type', function () {
    Http::fake(['radar.weather.gov/ridge/standard/KFTG_0.gif' => Http::response(RADAR_GIF, 200, ['Content-Type' => 'image/gif'])]);

    expect((new GetRadarSnapshot)('KFTG'))->toBe(['bytes' => RADAR_GIF, 'mime' => 'image/gif']);
});

test('a valid png is accepted too', function () {
    Http::fake(['radar.weather.gov/*' => Http::response(RADAR_PNG, 200, ['Content-Type' => 'image/png'])]);

    expect((new GetRadarSnapshot)('KFTG')['mime'])->toBe('image/png');
});

test('anything that is not a 200 valid image of an allowed type, within the size cap, is rejected', function (int $status, string $body, string $contentType) {
    Http::fake(['radar.weather.gov/*' => Http::response($body, $status, ['Content-Type' => $contentType])]);

    expect((new GetRadarSnapshot)('KFTG'))->toBeNull();
})->with([
    'not found' => [404, '<html>nope</html>', 'text/html'],
    'a redirect' => [302, '', 'text/html'],
    'html with a 200' => [200, '<html>hi</html>', 'text/html'],
    'a gif content type but html bytes' => [200, '<html>hi</html>', 'image/gif'],
    'a png content type but gif bytes' => [200, RADAR_GIF, 'image/png'],
    'an unsupported image type' => [200, "\xff\xd8\xff\xe0JFIF", 'image/jpeg'],
    'an empty body' => [200, '', 'image/gif'],
]);

test('a response over one megabyte is rejected', function () {
    Http::fake(['radar.weather.gov/*' => Http::response('GIF89a'.str_repeat("\0", 1_048_577), 200, ['Content-Type' => 'image/gif'])]);

    expect((new GetRadarSnapshot)('KFTG'))->toBeNull();
});

test('a site code that is not three or four uppercase letters/digits never makes a request', function (string $site) {
    Http::fake();

    expect((new GetRadarSnapshot)($site))->toBeNull();
    Http::assertNothingSent();
})->with(['../etc', 'kftg', 'KFTGX', 'KF', 'KF/TG', '']);

test('the request goes to the fixed radar host and does not follow redirects', function () {
    Http::fake(['radar.weather.gov/*' => Http::response(RADAR_GIF, 200, ['Content-Type' => 'image/gif'])]);

    (new GetRadarSnapshot)('KFTG');

    Http::assertSent(fn ($request) => $request->url() === 'https://radar.weather.gov/ridge/standard/KFTG_0.gif');
});

test('a connection failure returns null', function () {
    Http::fake(['radar.weather.gov/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout')]);

    expect((new GetRadarSnapshot)('KFTG'))->toBeNull();
});

test('the image is cached per site so several emails share one fetch', function () {
    Http::fake(['radar.weather.gov/*' => Http::response(RADAR_GIF, 200, ['Content-Type' => 'image/gif'])]);

    (new GetRadarSnapshot)('KFTG');
    (new GetRadarSnapshot)('KFTG');

    Http::assertSentCount(1);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/GetRadarSnapshotTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `app/Actions/Weather/GetRadarSnapshot.php`**

```php
<?php

namespace App\Actions\Weather;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The latest still radar frame for a radar site, fetched at send time so the
 * Full Daily Summary email can embed it inline as a true snapshot.
 *
 * The site code comes from an external response and goes into a URL, so it is
 * validated before any request; the host is fixed; redirects are not followed;
 * and the body is accepted only when status, content type, magic bytes and
 * size all check out (nothing else is ever embedded in an email).
 *
 * Cached as base64: the cache store may be a text column, which cannot hold
 * arbitrary binary.
 */
class GetRadarSnapshot
{
    private const MAX_BYTES = 1_048_576;

    private const MAGIC = [
        'image/gif' => ['GIF87a', 'GIF89a'],
        'image/png' => ["\x89PNG\r\n\x1a\n"],
    ];

    /**
     * @return array{bytes: string, mime: string}|null
     */
    public function __invoke(string $site): ?array
    {
        if (preg_match('/^[A-Z0-9]{3,4}$/', $site) !== 1) {
            return null;
        }

        $cached = Cache::remember("nws-radar:{$site}", now()->addMinutes(2), fn (): ?array => $this->fetch($site));

        if ($cached === null) {
            return null;
        }

        $bytes = base64_decode($cached['base64'], true);

        return $bytes === false ? null : ['bytes' => $bytes, 'mime' => $cached['mime']];
    }

    /**
     * @return array{base64: string, mime: string}|null
     */
    private function fetch(string $site): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => sprintf('(%s, %s)', config('app.name'), config('app.url'))])
                ->timeout(5)
                ->withOptions(['allow_redirects' => false])
                ->get("https://radar.weather.gov/ridge/standard/{$site}_0.gif");
        } catch (ConnectionException) {
            Log::warning('NWS radar snapshot fetch failed to connect');

            return null;
        }

        if ($response->status() !== 200) {
            Log::warning('NWS radar snapshot fetch failed', ['status' => $response->status()]);

            return null;
        }

        $bytes = $response->body();
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || ! isset(self::MAGIC[$mime])) {
            return null;
        }

        foreach (self::MAGIC[$mime] as $signature) {
            if (str_starts_with($bytes, $signature)) {
                return ['base64' => base64_encode($bytes), 'mime' => $mime];
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `tests/Feature/GetRadarSnapshotTest.php`
Expected: all PASS.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Weather/GetRadarSnapshot.php tests/Feature/GetRadarSnapshotTest.php
git commit -m "Add GetRadarSnapshot for the Full Daily Summary

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: `BuildDailySummaryReport`

**Files:**
- Create: `app/Actions/Weather/BuildDailySummaryReport.php`
- Modify: `tests/Pest.php` (append `nwsForecastPeriods()` and `nwsFakeEverything()` fixtures)
- Test: `tests/Feature/BuildDailySummaryReportTest.php`

**Interfaces:**
- Consumes: `GetSevenDayForecast`, `GetNwsPoint`, `GetCurrentConditions`, `GetHourlyForecast`, `GetActiveAlertsForLocation`, `SunTimes`, `WeatherIcon`.
- Produces: `BuildDailySummaryReport::__invoke(Location $location, bool $includeTestAlerts = false): ?array`:
  ```php
  [
      'location' => string,                 // Location::label
      'timezone' => string,                 // IANA name, used by the presenter for "as of now"
      'sun' => ?array{sunrise: string, sunset: string},
      'alerts' => array,                    // [] when none or on failure
      'current' => ?array,                  // GetCurrentConditions shape
      'radar_site' => ?string,              // ^[A-Z0-9]{3,4}$ or null
      'hourly' => ?array,                   // GetHourlyForecast shape
      'periods' => array<int, array{name: string, date: ?string, summary: string, label: 'High'|'Low', temperature: mixed, unit: string, icon: array{period: string, code: string}}>,
  ]
  ```
  Returns `null` when the 7-day forecast is unavailable (no periods).
  (Spec delta: `timezone` is added to the report so the renderer can stamp "as of {now}" in the location's zone without a model.)
- Fixtures added to `tests/Pest.php`:
  - `nwsForecastPeriods(): array` — three periods (Today day, Tonight night, Saturday day).
  - `nwsFakeEverything(array $overrides = []): void` — registers `Http::fake()` for point, forecast, hourly, station list, one observation and radar; `$overrides` is keyed by URL pattern and replaces that entry.

- [ ] **Step 1: Append the fixtures to `tests/Pest.php`**

```php
/**
 * @return array<int, array<string, mixed>>
 */
function nwsForecastPeriods(): array
{
    return [
        ['name' => 'Today', 'startTime' => '2026-10-02T09:00:00-06:00', 'isDaytime' => true, 'temperature' => 79, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny', 'icon' => 'https://api.weather.gov/icons/land/day/few?size=medium'],
        ['name' => 'Tonight', 'startTime' => '2026-10-02T18:00:00-06:00', 'isDaytime' => false, 'temperature' => 50, 'temperatureUnit' => 'F', 'shortForecast' => 'Mostly Clear', 'icon' => 'https://api.weather.gov/icons/land/night/few?size=medium'],
        ['name' => 'Saturday', 'startTime' => '2026-10-03T06:00:00-06:00', 'isDaytime' => true, 'temperature' => 84, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny', 'icon' => 'https://api.weather.gov/icons/land/day/few?size=medium'],
    ];
}

/**
 * Fakes every NWS endpoint the Full Daily Summary touches. $overrides is keyed
 * by the same URL pattern used below and replaces that entry's response.
 *
 * @param  array<string, mixed>  $overrides
 */
function nwsFakeEverything(array $overrides = []): void
{
    nwsFreshHttp();

    $hourly = collect(range(0, 15))->map(function (int $i): array {
        $start = Illuminate\Support\Carbon::parse('2026-10-02T09:00:00-06:00')->addHours($i);

        return [
            'startTime' => $start->toIso8601String(), 'endTime' => $start->copy()->addHour()->toIso8601String(),
            'temperature' => 50 + $i, 'temperatureUnit' => 'F',
            'probabilityOfPrecipitation' => ['value' => 10], 'windSpeed' => '5 mph', 'windDirection' => 'NW',
            'icon' => 'https://api.weather.gov/icons/land/day/few?size=small', 'shortForecast' => 'Sunny',
        ];
    })->all();

    Illuminate\Support\Facades\Http::fake(array_replace([
        'api.weather.gov/points/*' => Illuminate\Support\Facades\Http::response(nwsPoint()),
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => Illuminate\Support\Facades\Http::response(['properties' => ['periods' => $hourly]]),
        'api.weather.gov/gridpoints/BOU/64,73/forecast' => Illuminate\Support\Facades\Http::response(['properties' => ['periods' => nwsForecastPeriods()]]),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Illuminate\Support\Facades\Http::response(nwsStations([['id' => 'KEIK']])),
        'api.weather.gov/stations/KEIK/observations/latest' => Illuminate\Support\Facades\Http::response(nwsObservation()),
        'radar.weather.gov/*' => Illuminate\Support\Facades\Http::response("GIF89a\x01\x00\x01\x00\x00\x00\x00;", 200, ['Content-Type' => 'image/gif']),
    ], $overrides));
}
```

(The `hourly` entry is listed before `forecast` because Laravel's `Http::fake()` matches URL patterns in registration order and `.../forecast` is not a wildcard, so each exact URL still resolves correctly.)

- [ ] **Step 2: Write the failing test `tests/Feature/BuildDailySummaryReportTest.php`**

```php
<?php

use App\Actions\Weather\BuildDailySummaryReport;
use App\Models\ActiveWwa;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 09:00 America/Denver
    $this->location = Location::factory()->create([
        'label' => 'Home', 'lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => 'America/Denver',
        'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('it assembles every section for a location', function () {
    nwsFakeEverything();
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Wind Advisory', 'family' => 'advisory'])->id,
        'ugc_codes' => ['COZ040'],
    ]);

    $report = (new BuildDailySummaryReport)($this->location);

    expect($report['location'])->toBe('Home')
        ->and($report['timezone'])->toBe('America/Denver')
        ->and($report['sun'])->toHaveKeys(['sunrise', 'sunset'])
        ->and($report['alerts'])->toHaveCount(1)
        ->and($report['alerts'][0]['event'])->toBe('Wind Advisory')
        ->and($report['current']['station']['id'])->toBe('KEIK')
        ->and($report['radar_site'])->toBe('KFTG')
        ->and($report['hourly'])->toHaveCount(12)
        ->and($report['periods'])->toHaveCount(3);
});

test('periods are labelled High for daytime and Low for nighttime with icon codes and a local date', function () {
    nwsFakeEverything();

    $periods = (new BuildDailySummaryReport)($this->location)['periods'];

    expect($periods[0])->toBe([
        'name' => 'Today', 'date' => '2026-10-02T09:00:00-06:00', 'summary' => 'Sunny', 'label' => 'High',
        'temperature' => 79, 'unit' => 'F', 'icon' => ['period' => 'day', 'code' => 'few'],
    ])->and($periods[1]['label'])->toBe('Low')
        ->and($periods[1]['icon'])->toBe(['period' => 'night', 'code' => 'few']);
});

test('it returns null when the seven-day forecast is unavailable', function () {
    nwsFakeEverything(['api.weather.gov/gridpoints/BOU/64,73/forecast' => Http::response([], 503)]);

    expect((new BuildDailySummaryReport)($this->location))->toBeNull();
});

test('a failing optional section is omitted without losing the rest of the report', function () {
    nwsFakeEverything([
        'api.weather.gov/gridpoints/BOU/64,73/forecast/hourly' => Http::response([], 500),
        'api.weather.gov/gridpoints/BOU/64,73/stations' => Http::response([], 500),
    ]);

    $report = (new BuildDailySummaryReport)($this->location);

    expect($report['hourly'])->toBeNull()
        ->and($report['current'])->toBeNull()
        ->and($report['periods'])->toHaveCount(3)
        ->and($report['sun'])->not->toBeNull()
        ->and($report['alerts'])->toBe([]);
});

test('an exception thrown inside a section is swallowed into that section being empty', function () {
    nwsFakeEverything([
        'api.weather.gov/gridpoints/BOU/64,73/stations' => fn () => throw new RuntimeException('boom'),
    ]);

    $report = (new BuildDailySummaryReport)($this->location);

    expect($report['current'])->toBeNull()
        ->and($report['periods'])->toHaveCount(3);
});

test('an invalid or missing radar station is dropped, never passed on', function () {
    nwsFakeEverything(['api.weather.gov/points/*' => Http::response(nwsPoint(['radarStation' => '../evil']))]);
    expect((new BuildDailySummaryReport)($this->location)['radar_site'])->toBeNull();

    Illuminate\Support\Facades\Cache::flush();
    nwsFakeEverything(['api.weather.gov/points/*' => Http::response(nwsPoint(['radarStation' => null]))]);
    expect((new BuildDailySummaryReport)($this->location)['radar_site'])->toBeNull();
});

test('the report is plain data that survives serialization, with no objects in it', function () {
    nwsFakeEverything();

    $report = (new BuildDailySummaryReport)($this->location);

    array_walk_recursive($report, function ($value): void {
        expect(is_scalar($value) || $value === null)->toBeTrue();
    });
    expect(unserialize(serialize($report)))->toBe($report)
        ->and(json_decode(json_encode($report), true))->toBe($report);
});

test('test alerts are included only when the caller says the owner is a test account', function () {
    nwsFakeEverything();
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Synthetic Warning', 'family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'], 'is_test' => true,
    ]);

    expect((new BuildDailySummaryReport)($this->location)['alerts'])->toBe([])
        ->and((new BuildDailySummaryReport)($this->location, includeTestAlerts: true)['alerts'])->toHaveCount(1);
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `tests/Feature/BuildDailySummaryReportTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement `app/Actions/Weather/BuildDailySummaryReport.php`**

```php
<?php

namespace App\Actions\Weather;

use App\Models\Location;
use App\Support\SunTimes;
use App\Support\WeatherIcon;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the queue-serializable report the Full Daily Summary email renders:
 * plain nested arrays only (no models, Carbon instances or closures), with
 * timestamps as ISO-8601 strings in the location's timezone.
 *
 * The 7-day forecast is the one required section: without it there is nothing
 * to send (null), exactly like Brief. Every other section is built inside its
 * own guard so a failure omits just that section.
 */
class BuildDailySummaryReport
{
    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(Location $location, bool $includeTestAlerts = false): ?array
    {
        $forecast = app(GetSevenDayForecast::class)($location);

        if ($forecast === []) {
            return null;
        }

        $timezone = $location->timezone ?? config('app.timezone');

        return [
            'location' => $location->label,
            'timezone' => $timezone,
            'sun' => $this->guard('sun', fn () => SunTimes::forLocation($location)),
            'alerts' => $this->guard('alerts', fn (): array => app(GetActiveAlertsForLocation::class)($location, $includeTestAlerts)) ?? [],
            'current' => $this->guard('current conditions', fn () => app(GetCurrentConditions::class)($location)),
            'radar_site' => $this->guard('radar site', fn (): ?string => $this->radarSite($location)),
            'hourly' => $this->guard('hourly forecast', fn () => app(GetHourlyForecast::class)($location)),
            'periods' => $this->periods($forecast, $timezone),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $forecast
     * @return array<int, array<string, mixed>>
     */
    private function periods(array $forecast, string $timezone): array
    {
        return array_values(array_map(fn (array $period): array => [
            'name' => (string) ($period['name'] ?? ''),
            'date' => isset($period['startTime']) ? Carbon::parse($period['startTime'])->setTimezone($timezone)->toIso8601String() : null,
            'summary' => (string) ($period['shortForecast'] ?? ''),
            'label' => ($period['isDaytime'] ?? true) ? 'High' : 'Low',
            'temperature' => $period['temperature'] ?? null,
            'unit' => (string) ($period['temperatureUnit'] ?? 'F'),
            'icon' => WeatherIcon::fromUrl($period['icon'] ?? null),
        ], $forecast));
    }

    private function radarSite(Location $location): ?string
    {
        $site = app(GetNwsPoint::class)($location)['radarStation'] ?? null;

        return is_string($site) && preg_match('/^[A-Z0-9]{3,4}$/', $site) === 1 ? $site : null;
    }

    /**
     * Fixed strings only in the log: an exception message can carry URLs or
     * response fragments.
     *
     * @template T
     *
     * @param  Closure(): T  $section
     * @return T|null
     */
    private function guard(string $name, Closure $section): mixed
    {
        try {
            return $section();
        } catch (Throwable $e) {
            Log::warning("Daily summary {$name} section failed", ['exception' => $e::class]);

            return null;
        }
    }
}
```

- [ ] **Step 5: Run to verify it passes**

Run: `tests/Feature/BuildDailySummaryReportTest.php`
Expected: all PASS.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Weather/BuildDailySummaryReport.php tests/Pest.php tests/Feature/BuildDailySummaryReportTest.php
git commit -m "Add BuildDailySummaryReport, the queue-safe Full summary payload

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: `DailySummaryPresenter`, `DailySummaryMail`, and the `MailTransport` branch

**Files:**
- Create: `app/Support/DailySummaryPresenter.php`, `app/Mail/DailySummaryMail.php`
- Create: `resources/views/mail/daily-summary.blade.php`, `resources/views/mail/daily-summary-text.blade.php`, `resources/views/mail/partials/daily-summary.blade.php`
- Modify: `app/Support/Delivery/Transports/MailTransport.php`
- Test: `tests/Feature/DailySummaryPresenterTest.php`, `tests/Feature/DailySummaryMailTest.php`, modify `tests/Feature/MailTransportTest.php`

**Interfaces:**
- Consumes: the report shape from Task 7; `WeatherIcon::url()`.
- Produces:
  - `DailySummaryPresenter::present(array $report): array` — display view-model:
    ```php
    [
        'location' => string,
        'station_line' => ?string,      // "Reporting station: NAME (ID) — LAT, LNG — elev. N ft"
        'sun_line' => ?string,          // "Sunrise 6:57 AM · Sunset 6:40 PM"
        'alerts' => array<int, array{event: string, headline: ?string, until: ?string}>,
        'current' => ?array{icon_url: string, description: string, rows: array<int, array{0: string, 1: string}>, updated: string},
        'radar_alt' => ?string, 'radar_caption' => ?string,    // null when the report has no radar site
        'hourly' => ?array<int, array{time: string, icon_url: string, temp: string, precip: string, wind: string, summary: string}>,
        'periods' => array<int, array{icon_url: string, name: string, date: string, summary: string, temp: string}>,
    ]
    ```
  - `DailySummaryPresenter::text(array $view, bool $hasRadar): string` — plain-text rendering of that view-model.
  - `new DailySummaryMail(?string $messageTitle, array $report, string $briefText, ?array $radar = null)` with public readonly properties of the same names; `$radar` is `['bytes' => string, 'mime' => string]|null`.
  - `MailTransport::send()` sends `DailySummaryMail` when `$meta['daily_summary']` is an array (radar from `$meta['daily_summary_radar']`), else unchanged `ChannelMessageMail`.

- [ ] **Step 1: Add the shared `presenterReport()` fixture to `tests/Pest.php`, then write the failing presenter test**

First append this to the end of `tests/Pest.php` (shared by the presenter, mail, `MailTransport`, and job tests, so every one of those files runs on its own):

```php
/**
 * A representative BuildDailySummaryReport array. Overrides replace top-level keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function presenterReport(array $overrides = []): array
{
    return array_replace([
        'location' => 'Home',
        'timezone' => 'America/Denver',
        'sun' => ['sunrise' => '2026-10-02T06:57:34-06:00', 'sunset' => '2026-10-02T18:40:53-06:00'],
        'alerts' => [['event' => 'Wind Advisory', 'headline' => 'Wind Advisory issued', 'expires' => '2026-10-02T15:00:00-06:00', 'family' => 'advisory']],
        'current' => [
            'station' => ['id' => 'KEIK', 'name' => 'Erie Municipal Airport', 'lat' => 40.01169, 'lng' => -105.05033, 'elevation_ft' => 5085.0],
            'observed_at' => '2026-10-02T08:35:00-06:00', 'description' => 'Clear',
            'icon' => ['period' => 'day', 'code' => 'skc'],
            'temperature_f' => 53.6, 'feels_like_f' => 50.0, 'humidity_pct' => 76.5,
            'wind' => ['direction' => 'NE', 'speed_mph' => 10.0, 'gust_mph' => 20.0],
            'barometer_inhg' => 30.2712, 'dewpoint_f' => 46.4, 'visibility_mi' => 10.0,
        ],
        'radar_site' => 'KFTG',
        'hourly' => [['time' => '2026-10-02T09:00:00-06:00', 'icon' => ['period' => 'day', 'code' => 'few'], 'temperature' => 54, 'unit' => 'F', 'precip_chance' => 10, 'wind' => '5 mph NW', 'summary' => 'Sunny']],
        'periods' => [['name' => 'Today', 'date' => '2026-10-02T09:00:00-06:00', 'summary' => 'Sunny', 'label' => 'High', 'temperature' => 79, 'unit' => 'F', 'icon' => ['period' => 'day', 'code' => 'few']]],
    ], $overrides);
}
```

Then create `tests/Feature/DailySummaryPresenterTest.php`:

```php
<?php

use App\Support\DailySummaryPresenter;

test('the header lines carry the station with coordinates and elevation, and the sun times', function () {
    $view = DailySummaryPresenter::present(presenterReport());

    expect($view['location'])->toBe('Home')
        ->and($view['station_line'])->toBe('Reporting station: Erie Municipal Airport (KEIK) — 40.0117, -105.0503 — elev. 5,085 ft')
        ->and($view['sun_line'])->toBe('Sunrise 6:57 AM · Sunset 6:40 PM');
});

test('current conditions rows are formatted for display', function () {
    config(['app.url' => 'https://alerts.example.test']);
    $current = DailySummaryPresenter::present(presenterReport())['current'];

    expect($current['description'])->toBe('Clear')
        ->and($current['icon_url'])->toBe('https://alerts.example.test/images/weather/day/skc.png')
        ->and($current['rows'])->toBe([
            ['Temperature', '54°F'],
            ['Feels like', '50°F'],
            ['Humidity', '77%'],
            ['Wind', 'NE 10 mph, gusting 20 mph'],
            ['Barometer', '30.27 inHg'],
            ['Dewpoint', '46°F'],
            ['Visibility', '10 mi'],
        ])
        ->and($current['updated'])->toBe('Last updated Fri, Oct 2 at 8:35 AM');
});

test('wind shows calm, omits direction and gust when absent, and missing readings are an em dash', function () {
    $report = presenterReport();
    $report['current']['wind'] = ['direction' => null, 'speed_mph' => 0.2, 'gust_mph' => null];
    $report['current']['humidity_pct'] = null;
    $report['current']['barometer_inhg'] = null;
    $report['current']['dewpoint_f'] = null;
    $report['current']['visibility_mi'] = null;

    $rows = collect(DailySummaryPresenter::present($report)['current']['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);

    expect($rows['Wind'])->toBe('Calm')
        ->and($rows['Humidity'])->toBe('—')
        ->and($rows['Barometer'])->toBe('—')
        ->and($rows['Dewpoint'])->toBe('—')
        ->and($rows['Visibility'])->toBe('—');

    $report['current']['wind'] = ['direction' => null, 'speed_mph' => 7.4, 'gust_mph' => null];
    $windRow = collect(DailySummaryPresenter::present($report)['current']['rows'])->firstWhere(0, 'Wind');
    expect($windRow[1])->toBe('7 mph');
});

test('the current section and station line are absent without current conditions, and the sun line without sun times', function () {
    $view = DailySummaryPresenter::present(presenterReport(['current' => null, 'sun' => null]));

    expect($view['current'])->toBeNull()
        ->and($view['station_line'])->toBeNull()
        ->and($view['sun_line'])->toBeNull();
});

test('alerts show the event, headline and a local expiry, and an open-ended alert has no until', function () {
    $view = DailySummaryPresenter::present(presenterReport([
        'alerts' => [
            ['event' => 'Wind Advisory', 'headline' => 'Wind Advisory issued', 'expires' => '2026-10-02T15:00:00-06:00', 'family' => 'advisory'],
            ['event' => 'Statement', 'headline' => null, 'expires' => null, 'family' => 'statement'],
        ],
    ]));

    expect($view['alerts'])->toBe([
        ['event' => 'Wind Advisory', 'headline' => 'Wind Advisory issued', 'until' => 'until Fri 3:00 PM'],
        ['event' => 'Statement', 'headline' => null, 'until' => null],
    ]);
});

test('hourly and 7-day rows are formatted, with a missing precip chance shown as an em dash', function () {
    config(['app.url' => 'https://alerts.example.test']);
    $report = presenterReport();
    $report['hourly'][] = ['time' => '2026-10-02T10:00:00-06:00', 'icon' => ['period' => 'day', 'code' => 'few'], 'temperature' => 55, 'unit' => 'F', 'precip_chance' => null, 'wind' => '', 'summary' => 'Sunny'];

    $view = DailySummaryPresenter::present($report);

    expect($view['hourly'][0])->toBe(['time' => '9 AM', 'icon_url' => 'https://alerts.example.test/images/weather/day/few.png', 'temp' => '54°F', 'precip' => '10%', 'wind' => '5 mph NW', 'summary' => 'Sunny'])
        ->and($view['hourly'][1]['precip'])->toBe('—')
        ->and($view['hourly'][1]['wind'])->toBe('—')
        ->and($view['periods'][0])->toBe(['icon_url' => 'https://alerts.example.test/images/weather/day/few.png', 'name' => 'Today', 'date' => 'Fri, Oct 2', 'summary' => 'Sunny', 'temp' => 'High 79°F']);
});

test('radar alt and caption exist only when the report has a radar site', function () {
    Illuminate\Support\Carbon::setTestNow('2026-10-02 15:00:00');

    $with = DailySummaryPresenter::present(presenterReport());
    $without = DailySummaryPresenter::present(presenterReport(['radar_site' => null]));

    expect($with['radar_alt'])->toBe('Radar near Home from KFTG')
        ->and($with['radar_caption'])->toBe('Radar as of Fri, Oct 2 at 9:00 AM')
        ->and($without['radar_alt'])->toBeNull()
        ->and($without['radar_caption'])->toBeNull();

    Illuminate\Support\Carbon::setTestNow();
});

test('the plain text rendering carries every section and omits the radar line when there is no image', function () {
    Illuminate\Support\Carbon::setTestNow('2026-10-02 15:00:00');
    $view = DailySummaryPresenter::present(presenterReport());

    $text = DailySummaryPresenter::text($view, hasRadar: false);

    expect($text)->toContain('Home')
        ->and($text)->toContain('Reporting station: Erie Municipal Airport (KEIK)')
        ->and($text)->toContain('Sunrise 6:57 AM · Sunset 6:40 PM')
        ->and($text)->toContain('ALERTS IN EFFECT')
        ->and($text)->toContain('Wind Advisory — Wind Advisory issued (until Fri 3:00 PM)')
        ->and($text)->toContain('CURRENT CONDITIONS')
        ->and($text)->toContain('Temperature: 54°F')
        ->and($text)->toContain('NEXT 12 HOURS')
        ->and($text)->toContain('9 AM — 54°F — 10% — 5 mph NW — Sunny')
        ->and($text)->toContain('7-DAY FORECAST')
        ->and($text)->toContain('Today (Fri, Oct 2): Sunny — High 79°F')
        ->and($text)->not->toContain('Radar');

    Illuminate\Support\Carbon::setTestNow();
});

test('the plain text says there are no active alerts when the list is empty', function () {
    $text = DailySummaryPresenter::text(DailySummaryPresenter::present(presenterReport(['alerts' => []])), hasRadar: false);

    expect($text)->toContain("ALERTS IN EFFECT\nNo active alerts.");
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `tests/Feature/DailySummaryPresenterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `app/Support/DailySummaryPresenter.php`**

```php
<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Turns a BuildDailySummaryReport array into display strings. All formatting
 * lives here so the mail views stay logic-free and everything is unit-testable
 * without rendering mail. ISO timestamps keep their embedded offset when
 * parsed, so every time shown is in the location's timezone.
 */
class DailySummaryPresenter
{
    private const DASH = '—';

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function present(array $report): array
    {
        $current = $report['current'] ?? null;
        $radarSite = $report['radar_site'] ?? null;
        $timezone = $report['timezone'] ?? config('app.timezone');

        return [
            'location' => (string) ($report['location'] ?? ''),
            'station_line' => $current !== null ? self::stationLine($current['station']) : null,
            'sun_line' => self::sunLine($report['sun'] ?? null),
            'alerts' => array_map(fn (array $alert): array => [
                'event' => (string) $alert['event'],
                'headline' => $alert['headline'] ?? null,
                'until' => isset($alert['expires']) ? 'until '.Carbon::parse($alert['expires'])->format('D g:i A') : null,
            ], $report['alerts'] ?? []),
            'current' => $current !== null ? self::currentSection($current) : null,
            'radar_alt' => is_string($radarSite) ? "Radar near {$report['location']} from {$radarSite}" : null,
            'radar_caption' => is_string($radarSite) ? 'Radar as of '.Carbon::now($timezone)->format('D, M j \a\t g:i A') : null,
            'hourly' => isset($report['hourly']) ? array_map(fn (array $hour): array => [
                'time' => Carbon::parse($hour['time'])->format('g A'),
                'icon_url' => WeatherIcon::url($hour['icon']['period'], $hour['icon']['code']),
                'temp' => self::temperature($hour['temperature'], $hour['unit']),
                'precip' => isset($hour['precip_chance']) ? $hour['precip_chance'].'%' : self::DASH,
                'wind' => filled($hour['wind']) ? $hour['wind'] : self::DASH,
                'summary' => (string) $hour['summary'],
            ], $report['hourly']) : null,
            'periods' => array_map(fn (array $period): array => [
                'icon_url' => WeatherIcon::url($period['icon']['period'], $period['icon']['code']),
                'name' => (string) $period['name'],
                'date' => isset($period['date']) ? Carbon::parse($period['date'])->format('D, M j') : '',
                'summary' => (string) $period['summary'],
                'temp' => $period['label'].' '.self::temperature($period['temperature'], $period['unit']),
            ], $report['periods'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $view  the output of present()
     */
    public static function text(array $view, bool $hasRadar): string
    {
        $lines = [$view['location']];

        foreach (['station_line', 'sun_line'] as $key) {
            if ($view[$key] !== null) {
                $lines[] = $view[$key];
            }
        }

        $lines[] = '';
        $lines[] = 'ALERTS IN EFFECT';

        if ($view['alerts'] === []) {
            $lines[] = 'No active alerts.';
        }

        foreach ($view['alerts'] as $alert) {
            $detail = $alert['headline'] !== null ? " — {$alert['headline']}" : '';
            $until = $alert['until'] !== null ? " ({$alert['until']})" : '';
            $lines[] = "{$alert['event']}{$detail}{$until}";
        }

        if ($view['current'] !== null) {
            $lines[] = '';
            $lines[] = 'CURRENT CONDITIONS';
            $lines[] = $view['current']['description'];

            foreach ($view['current']['rows'] as [$label, $value]) {
                $lines[] = "{$label}: {$value}";
            }

            $lines[] = $view['current']['updated'];
        }

        if ($hasRadar && $view['radar_caption'] !== null) {
            $lines[] = '';
            $lines[] = 'RADAR';
            $lines[] = $view['radar_caption'];
        }

        if ($view['hourly'] !== null) {
            $lines[] = '';
            $lines[] = 'NEXT 12 HOURS';

            foreach ($view['hourly'] as $hour) {
                $lines[] = "{$hour['time']} — {$hour['temp']} — {$hour['precip']} — {$hour['wind']} — {$hour['summary']}";
            }
        }

        $lines[] = '';
        $lines[] = '7-DAY FORECAST';

        foreach ($view['periods'] as $period) {
            $lines[] = "{$period['name']} ({$period['date']}): {$period['summary']} — {$period['temp']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $station
     */
    private static function stationLine(array $station): string
    {
        $line = "Reporting station: {$station['name']} ({$station['id']})";

        if ($station['lat'] !== null && $station['lng'] !== null) {
            $line .= ' — '.number_format($station['lat'], 4, '.', '').', '.number_format($station['lng'], 4, '.', '');
        }

        if ($station['elevation_ft'] !== null) {
            $line .= ' — elev. '.number_format($station['elevation_ft']).' ft';
        }

        return $line;
    }

    /**
     * @param  array{sunrise: string, sunset: string}|null  $sun
     */
    private static function sunLine(?array $sun): ?string
    {
        return $sun === null ? null : 'Sunrise '.Carbon::parse($sun['sunrise'])->format('g:i A').' · Sunset '.Carbon::parse($sun['sunset'])->format('g:i A');
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private static function currentSection(array $current): array
    {
        return [
            'icon_url' => WeatherIcon::url($current['icon']['period'], $current['icon']['code']),
            'description' => (string) ($current['description'] ?? ''),
            'rows' => [
                ['Temperature', self::degrees($current['temperature_f'])],
                ['Feels like', self::degrees($current['feels_like_f'])],
                ['Humidity', $current['humidity_pct'] !== null ? (int) round($current['humidity_pct']).'%' : self::DASH],
                ['Wind', self::wind($current['wind'])],
                ['Barometer', $current['barometer_inhg'] !== null ? number_format($current['barometer_inhg'], 2).' inHg' : self::DASH],
                ['Dewpoint', self::degrees($current['dewpoint_f'])],
                ['Visibility', $current['visibility_mi'] !== null ? round($current['visibility_mi'], 1).' mi' : self::DASH],
            ],
            'updated' => 'Last updated '.Carbon::parse($current['observed_at'])->format('D, M j \a\t g:i A'),
        ];
    }

    /**
     * @param  array{direction: ?string, speed_mph: ?float, gust_mph: ?float}  $wind
     */
    private static function wind(array $wind): string
    {
        if ($wind['speed_mph'] === null) {
            return self::DASH;
        }

        if ($wind['speed_mph'] < 0.5) {
            return 'Calm';
        }

        $text = trim(($wind['direction'] ?? '').' '.(int) round($wind['speed_mph']).' mph');

        return $wind['gust_mph'] !== null ? $text.', gusting '.(int) round($wind['gust_mph']).' mph' : $text;
    }

    private static function degrees(?float $value): string
    {
        return $value === null ? self::DASH : (int) round($value).'°F';
    }

    private static function temperature(mixed $value, string $unit): string
    {
        return is_numeric($value) ? (int) round((float) $value)."°{$unit}" : self::DASH;
    }
}
```

- [ ] **Step 4: Run to verify the presenter passes**

Run: `tests/Feature/DailySummaryPresenterTest.php`
Expected: all PASS. (If an expectation about `'Calm'`/`'7 mph'` fails, the wind ordering in `wind()` is the thing to fix — speed under 0.5 is calm even when a direction exists.)

- [ ] **Step 5: Write the failing mail test `tests/Feature/DailySummaryMailTest.php`**

```php
<?php

use App\Mail\DailySummaryMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00');
    config(['app.name' => 'WxNotify Test', 'app.url' => 'https://alerts.example.test']);
});

afterEach(function () {
    Carbon::setTestNow();
});

const MAIL_GIF = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";

test('the subject is the title, falling back to the app name', function () {
    expect((new DailySummaryMail('Daily summary for Home', presenterReport(), 'brief'))->envelope()->subject)->toBe('Daily summary for Home')
        ->and((new DailySummaryMail(null, presenterReport(), 'brief'))->envelope()->subject)->toBe('WxNotify Test');
});

test('the html renders every section in order under the branded header', function () {
    $html = (new DailySummaryMail('Title', presenterReport(), 'brief', ['bytes' => MAIL_GIF, 'mime' => 'image/gif']))->render();

    expect($html)->toContain('src="https://alerts.example.test/images/logo.png"')
        ->and($html)->toContain('Home')
        ->and($html)->toContain('Reporting station: Erie Municipal Airport (KEIK) — 40.0117, -105.0503 — elev. 5,085 ft')
        ->and($html)->toContain('Sunrise 6:57 AM · Sunset 6:40 PM')
        ->and($html)->toContain('Wind Advisory')
        ->and($html)->toContain('until Fri 3:00 PM')
        ->and($html)->toContain('Feels like')
        ->and($html)->toContain('NE 10 mph, gusting 20 mph')
        ->and($html)->toContain('30.27 inHg')
        ->and($html)->toContain('Last updated Fri, Oct 2 at 8:35 AM')
        ->and($html)->toContain('cid:radar-snapshot')
        ->and($html)->toContain('Radar near Home from KFTG')
        ->and($html)->toContain('Today')
        ->and($html)->toContain('High 79°F')
        ->and($html)->toContain('/images/weather/day/few.png');

    $positions = array_map(fn (string $needle): int => strpos($html, $needle), [
        'Reporting station', 'Wind Advisory', 'Feels like', 'cid:radar-snapshot', '9 AM', 'High 79',
    ]);
    expect($positions)->toBe(collect($positions)->sort()->values()->all());
});

test('the radar section is absent when no image was fetched', function () {
    $html = (new DailySummaryMail('Title', presenterReport(), 'brief'))->render();

    expect($html)->not->toContain('cid:radar-snapshot')
        ->and($html)->not->toContain('Radar near');
});

test('the alerts section shows an empty state, and current conditions disappear when absent', function () {
    $html = (new DailySummaryMail('Title', presenterReport(['alerts' => [], 'current' => null, 'hourly' => null]), 'brief'))->render();

    expect($html)->toContain('No active alerts.')
        ->and($html)->not->toContain('Feels like')
        ->and($html)->not->toContain('Reporting station')
        ->and($html)->not->toContain('Next 12 Hours');
});

test('values from the external api are escaped', function () {
    $report = presenterReport(['alerts' => [['event' => '<script>alert(1)</script>', 'headline' => 'a & b', 'expires' => null, 'family' => 'warning']]]);
    $report['current']['station']['name'] = '<b>Evil</b> Airport';

    $html = (new DailySummaryMail('Title', $report, 'brief'))->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<b>Evil</b>')
        ->and($html)->toContain('&lt;b&gt;Evil&lt;/b&gt; Airport');
});

test('the plain text alternative carries the content without html and without the radar', function () {
    $mail = new DailySummaryMail('Title', presenterReport(), 'brief', ['bytes' => MAIL_GIF, 'mime' => 'image/gif']);

    $mail->assertSeeInText('WxNotify Test')
        ->assertSeeInText('ALERTS IN EFFECT')
        ->assertSeeInText('Today (Fri, Oct 2): Sunny — High 79°F')
        ->assertDontSeeInText('<table');
});

test('a sent message embeds the radar image inline', function () {
    Mail::to('subscriber@example.com')->send(new DailySummaryMail('Title', presenterReport(), 'brief', ['bytes' => MAIL_GIF, 'mime' => 'image/gif']));

    $email = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getMediaType())->toBe('image')
        ->and($email->getHtmlBody())->toContain('cid:radar-snapshot');
});

test('no radar means no embedded attachment', function () {
    Mail::to('subscriber@example.com')->send(new DailySummaryMail('Title', presenterReport(), 'brief'));

    $email = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($email->getAttachments())->toHaveCount(0);
});
```

(`presenterReport()` comes from `tests/Pest.php`, added in Step 1.)

- [ ] **Step 6: Run to verify it fails**

Run: `tests/Feature/DailySummaryMailTest.php`
Expected: FAIL — class `App\Mail\DailySummaryMail` not found.

- [ ] **Step 7: Implement the mailable `app/Mail/DailySummaryMail.php`**

```php
<?php

namespace App\Mail;

use App\Support\DailySummaryPresenter;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

/**
 * The Full Daily Weather Summary: header, alerts, current conditions, radar,
 * hourly and 7-day sections. Rendered inside the shared Markdown mail layout
 * so it inherits the branded header (.ai/rules/delivery.md).
 *
 * Markdown (CommonMark) would mangle indented HTML and treat blank lines inside
 * an HTML block as the end of that block, so the section HTML is rendered from
 * a plain partial and collapsed to a single unbroken line before it is placed
 * in the Markdown view.
 *
 * The radar is attached via a Symfony inline embed referenced as
 * cid:radar-snapshot; Laravel's $message->embed() is not available in
 * Markdown mail views.
 */
class DailySummaryMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $report
     * @param  array{bytes: string, mime: string}|null  $radar
     */
    public function __construct(
        public readonly ?string $messageTitle,
        public readonly array $report,
        public readonly string $briefText,
        public readonly ?array $radar = null,
    ) {
        if ($radar !== null) {
            $this->withSymfonyMessage(fn (Email $message) => $message->embed($radar['bytes'], 'radar-snapshot', $radar['mime']));
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: filled($this->messageTitle) ? $this->messageTitle : (string) config('app.name'),
        );
    }

    public function content(): Content
    {
        $view = DailySummaryPresenter::present($this->report);
        $hasRadar = $this->radar !== null;

        return new Content(
            markdown: 'mail.daily-summary',
            text: 'mail.daily-summary-text',
            with: [
                'summaryHtml' => new HtmlString($this->collapse(view('mail.partials.daily-summary', ['s' => $view, 'hasRadar' => $hasRadar])->render())),
                'summaryText' => DailySummaryPresenter::text($view, $hasRadar),
            ],
        );
    }

    private function collapse(string $html): string
    {
        $html = preg_replace('/\s*\n\s*/', ' ', $html) ?? $html;

        return trim(preg_replace('/>\s+</', '><', $html) ?? $html);
    }
}
```

- [ ] **Step 8: Create the three views**

`resources/views/mail/daily-summary.blade.php`:

```blade
<x-mail::message>
{!! $summaryHtml !!}
</x-mail::message>
```

`resources/views/mail/daily-summary-text.blade.php`:

```blade
{{ config('app.name') }}

{!! $summaryText !!}
```

`resources/views/mail/partials/daily-summary.blade.php` (every dynamic value is escaped with `{{ }}`; the mailable collapses the whitespace):

```blade
<div style="font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color: #1f2937;">
<h2 style="margin: 0 0 4px; font-size: 20px;">{{ $s['location'] }}</h2>
@if ($s['station_line'] !== null)
<p style="margin: 0; font-size: 13px; color: #6b7280;">{{ $s['station_line'] }}</p>
@endif
@if ($s['sun_line'] !== null)
<p style="margin: 2px 0 0; font-size: 13px; color: #6b7280;">{{ $s['sun_line'] }}</p>
@endif

<h3 style="margin: 20px 0 6px; font-size: 16px;">Alerts in Effect</h3>
@if ($s['alerts'] === [])
<p style="margin: 0; font-size: 14px;">No active alerts.</p>
@else
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; border-collapse: collapse;">
@foreach ($s['alerts'] as $alert)
<tr><td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb;"><strong>{{ $alert['event'] }}</strong>@if ($alert['until'] !== null) <span style="color: #6b7280;">· {{ $alert['until'] }}</span>@endif @if ($alert['headline'] !== null)<br><span style="color: #4b5563;">{{ $alert['headline'] }}</span>@endif</td></tr>
@endforeach
</table>
@endif

@if ($s['current'] !== null)
<h3 style="margin: 20px 0 6px; font-size: 16px;">Current Conditions</h3>
<table role="presentation" cellpadding="0" cellspacing="0" style="font-size: 14px; border-collapse: collapse;">
<tr>
<td style="padding: 0 12px 0 0; vertical-align: top;"><img src="{{ $s['current']['icon_url'] }}" alt="{{ $s['current']['description'] }}" width="50" height="50" style="display: block;"></td>
<td style="vertical-align: top;"><div style="font-size: 16px; font-weight: 600;">{{ $s['current']['description'] }}</div>
<table role="presentation" cellpadding="0" cellspacing="0" style="font-size: 14px;">
@foreach ($s['current']['rows'] as [$label, $value])
<tr><td style="padding: 1px 12px 1px 0; color: #6b7280;">{{ $label }}</td><td style="padding: 1px 0;">{{ $value }}</td></tr>
@endforeach
</table>
<div style="margin-top: 4px; font-size: 12px; color: #6b7280;">{{ $s['current']['updated'] }}</div></td>
</tr>
</table>
@endif

@if ($hasRadar && $s['radar_alt'] !== null)
<h3 style="margin: 20px 0 6px; font-size: 16px;">Radar</h3>
<img src="cid:radar-snapshot" alt="{{ $s['radar_alt'] }}" style="max-width: 100%; height: auto; display: block;">
<div style="margin-top: 4px; font-size: 12px; color: #6b7280;">{{ $s['radar_caption'] }}</div>
@endif

@if ($s['hourly'] !== null)
<h3 style="margin: 20px 0 6px; font-size: 16px;">Next 12 Hours</h3>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 13px; border-collapse: collapse;">
@foreach ($s['hourly'] as $hour)
<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 4px 8px 4px 0; white-space: nowrap;">{{ $hour['time'] }}</td><td style="padding: 4px 8px 4px 0;"><img src="{{ $hour['icon_url'] }}" alt="{{ $hour['summary'] }}" width="28" height="28" style="display: block;"></td><td style="padding: 4px 8px 4px 0; white-space: nowrap;">{{ $hour['temp'] }}</td><td style="padding: 4px 8px 4px 0; white-space: nowrap;">{{ $hour['precip'] }}</td><td style="padding: 4px 8px 4px 0; white-space: nowrap;">{{ $hour['wind'] }}</td><td style="padding: 4px 0;">{{ $hour['summary'] }}</td></tr>
@endforeach
</table>
@endif

<h3 style="margin: 20px 0 6px; font-size: 16px;">7-Day Forecast</h3>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; border-collapse: collapse;">
@foreach ($s['periods'] as $period)
<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 6px 10px 6px 0; vertical-align: top;"><img src="{{ $period['icon_url'] }}" alt="{{ $period['summary'] }}" width="40" height="40" style="display: block;"></td><td style="padding: 6px 10px 6px 0; vertical-align: top;"><strong>{{ $period['name'] }}</strong><br><span style="color: #6b7280; font-size: 12px;">{{ $period['date'] }}</span></td><td style="padding: 6px 10px 6px 0; vertical-align: top;">{{ $period['summary'] }}</td><td style="padding: 6px 0; vertical-align: top; white-space: nowrap; font-weight: 600;">{{ $period['temp'] }}</td></tr>
@endforeach
</table>
</div>
```

- [ ] **Step 9: Run the mail test**

Run: `tests/Feature/DailySummaryMailTest.php` and `tests/Feature/DailySummaryPresenterTest.php`
Expected: all PASS. If the two `getSymfonyTransport()->messages()` assertions fail on an API mismatch, inspect `Mail::mailer()` in this Laravel version and adapt only how the sent `Symfony\Component\Mime\Email` is fetched from the array transport — the assertions about attachment count/type and `cid:radar-snapshot` must stay. If the ordering assertion in the render test fails because a needle text appears earlier than its section (e.g. `9 AM`), choose a more specific needle from that section; do not reorder the sections.

- [ ] **Step 10: Write the failing `MailTransport` tests (append to `tests/Feature/MailTransportTest.php`)**

```php
test('a daily_summary payload in the meta sends the rich daily summary mail, with the radar', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();
    $radar = ['bytes' => "GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'mime' => 'image/gif'];

    $result = app(MailTransport::class)->send($channel, 'Brief text', 'Daily summary for Home', [
        'daily_summary' => presenterReport(),
        'daily_summary_radar' => $radar,
    ]);

    expect($result->success)->toBeTrue();
    Mail::assertSent(DailySummaryMail::class, fn (DailySummaryMail $mail) => $mail->hasTo('subscriber@example.com')
        && $mail->messageTitle === 'Daily summary for Home'
        && $mail->briefText === 'Brief text'
        && $mail->radar === $radar);
    Mail::assertNotSent(ChannelMessageMail::class);
});

test('without a daily_summary payload the plain channel message is still sent', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    app(MailTransport::class)->send($channel, 'Brief text', 'Title', ['daily_summary_radar' => null]);

    Mail::assertSent(ChannelMessageMail::class);
    Mail::assertNotSent(DailySummaryMail::class);
});
```

Add `use App\Mail\DailySummaryMail;` to the file's imports.

- [ ] **Step 11: Run to verify they fail**

Run: `tests/Feature/MailTransportTest.php`
Expected: the first new test FAILS (a `ChannelMessageMail` is sent, not `DailySummaryMail`).

- [ ] **Step 12: Implement the `MailTransport` branch**

In `app/Support/Delivery/Transports/MailTransport.php`, add `use App\Mail\DailySummaryMail;` and replace the send line inside the `try`:

```php
            $summary = $meta['daily_summary'] ?? null;

            Mail::to($to)->send(is_array($summary)
                ? new DailySummaryMail($title, $summary, $body, $meta['daily_summary_radar'] ?? null)
                : new ChannelMessageMail($title, $body, $meta['url'] ?? null));
```

- [ ] **Step 13: Run all three suites**

Run: `tests/Feature/MailTransportTest.php`, `tests/Feature/DailySummaryMailTest.php`, `tests/Feature/DailySummaryPresenterTest.php`, `tests/Feature/ChannelMessageMailTest.php`
Expected: all PASS.

- [ ] **Step 14: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/DailySummaryPresenter.php app/Mail/DailySummaryMail.php resources/views/mail app/Support/Delivery/Transports/MailTransport.php tests/Pest.php tests/Feature/DailySummaryPresenterTest.php tests/Feature/DailySummaryMailTest.php tests/Feature/MailTransportTest.php
git commit -m "Add the rich Daily Summary email and route it through MailTransport

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Wire it into `DispatchScheduledAlerts` and `DeliverScheduledAlert`

**Files:**
- Modify: `app/Console/Commands/DispatchScheduledAlerts.php`
- Modify: `app/Jobs/DeliverScheduledAlert.php`
- Modify: `.ai/rules/jobs.md` (append one short rule)
- Test: `tests/Feature/DispatchScheduledAlertsTest.php`, `tests/Feature/DeliverScheduledAlertTest.php`

**Interfaces:**
- Consumes: `BuildDailySummaryReport` (Task 7), `GetRadarSnapshot` (Task 6), `ChannelType::supportsRichContent()` (Task 1), `MailTransport` meta keys (Task 8), `MessageBodyStyle`.
- Produces: `DeliverScheduledAlert::__construct(int $ruleId, int $channelId, string $title, string $body, ?array $report = null)`.

- [ ] **Step 1: Write the failing dispatch tests (append to `tests/Feature/DispatchScheduledAlertsTest.php`)**

```php
function summaryRule(string $style, array $channels): AlertRule
{
    $user = User::factory()->create(['tier_override' => App\Enums\Tier::Plus]);
    $location = Location::factory()->for($user)->create(['label' => 'Home', 'timezone' => 'America/Denver', 'lat' => 40.0031694, 'lng' => -104.997011]);
    $rule = AlertRule::factory()->dailySummary()->for($user)->for($location)->create(['delivery_time' => '06:00', 'message_body_style' => $style]);

    foreach ($channels as $channel) {
        $rule->notificationChannels()->attach($channel->for($user)->create());
    }

    return $rule;
}

test('a Full daily summary rule carries the structured report on each dispatched job', function () {
    Bus::fake();
    nwsFakeEverything();
    $rule = summaryRule('full', [NotificationChannel::factory()->email('a@example.com'), NotificationChannel::factory()]);

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatchedTimes(DeliverScheduledAlert::class, 2);
    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->ruleId === $rule->id
        && is_array($job->report)
        && $job->report['location'] === 'Home'
        && count($job->report['periods']) === 3
        && str_contains($job->body, 'Sunny'));
});

test('a Brief daily summary rule dispatches with no report', function () {
    Bus::fake();
    nwsFakeEverything();
    summaryRule('brief', [NotificationChannel::factory()->email('a@example.com')]);

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null);
});

test('a rule with no stored message style (Free, or created before the option) dispatches with no report', function () {
    Bus::fake();
    nwsFakeEverything();
    $rule = summaryRule('full', [NotificationChannel::factory()->email('a@example.com')]);
    $rule->user->forceFill(['tier_override' => App\Enums\Tier::Free])->save();

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null);
});

test('the report is not built when no attached channel can render it', function () {
    Bus::fake();
    nwsFakeEverything();
    summaryRule('full', [NotificationChannel::factory()]); // an apprise channel only

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/observations/latest') || str_contains($request->url(), 'radar.weather.gov'));
});

test('a failure while building the report still delivers the Brief summary', function () {
    Bus::fake();
    nwsFakeEverything();
    app()->bind(App\Actions\Weather\BuildDailySummaryReport::class, fn () => new class extends App\Actions\Weather\BuildDailySummaryReport
    {
        public function __invoke(Location $location, bool $includeTestAlerts = false): ?array
        {
            throw new RuntimeException('boom');
        }
    });
    $rule = summaryRule('full', [NotificationChannel::factory()->email('a@example.com')]);

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null && str_contains($job->body, 'Sunny'));
    expect($rule->fresh()->last_scheduled_run_on)->not->toBeNull();
});

test('a Threshold rule never gets a report', function () {
    Bus::fake();
    fakeGridDataForThreshold(overnightLow: 27.0);
    $location = Location::factory()->for($user = User::factory()->create(['tier_override' => App\Enums\Tier::Plus]))->create(['timezone' => 'America/Chicago']);
    $rule = AlertRule::factory()->for($user)->for($location)->create(['threshold_value' => 32, 'delivery_time' => '06:00', 'message_body_style' => 'full']);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->email('a@example.com')->create());

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null);
});
```

Note: the test file's `beforeEach` sets `Carbon::setTestNow('2026-09-14 12:00:00')` (12:00 UTC = 06:00 CDT / 06:00 MDT is 06:00 — in MDT 12:00 UTC is 06:00 local, so a `06:00` delivery time is due). The new tests use `America/Denver`, where 12:00 UTC on 2026-09-14 is 06:00 MDT: due. The shared NWS fixtures' timestamps are 2026-10-02 but nothing in these tests asserts time-sensitive fields (observation staleness would drop `current`, which these tests do not assert; alerts/hourly sections are not asserted either).

- [ ] **Step 2: Run to verify they fail**

Run: `tests/Feature/DispatchScheduledAlertsTest.php`
Expected: the new tests FAIL (`$job->report` is an undefined property / dispatched jobs carry no report); existing tests still PASS.

- [ ] **Step 3: Write the failing job tests (append to `tests/Feature/DeliverScheduledAlertTest.php`)**

Add imports: `use App\Mail\DailySummaryMail;`, `use App\Models\AlertRule;` (already present), and `use Illuminate\Support\Carbon;` if absent.

```php
function dsReport(): array
{
    return presenterReport(['radar_site' => 'KFTG']);
}

test('a Full summary with a report sends the rich mail with the radar image to an email channel', function () {
    Mail::fake();
    Http::fake(['radar.weather.gov/*' => Http::response("GIF89a\x01\x00\x01\x00\x00\x00\x00;", 200, ['Content-Type' => 'image/gif'])]);
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny, high 79.', dsReport()))
        ->handle(app(ChannelDispatcher::class));

    Mail::assertSent(DailySummaryMail::class, fn (DailySummaryMail $mail) => $mail->hasTo('subscriber@example.com')
        && $mail->briefText === 'Today: Sunny, high 79.'
        && $mail->radar !== null
        && $mail->radar['mime'] === 'image/gif');
    Mail::assertNotSent(ChannelMessageMail::class);
    expect(DeliveryLog::sole()->status)->toBe('success');
});

test('the rich mail is still sent, without radar, when the radar fetch fails', function () {
    Mail::fake();
    Http::fake(['radar.weather.gov/*' => Http::response('', 500)]);
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Brief.', dsReport()))
        ->handle(app(ChannelDispatcher::class));

    Mail::assertSent(DailySummaryMail::class, fn (DailySummaryMail $mail) => $mail->radar === null);
});

test('a report with no radar site never requests radar', function () {
    Mail::fake();
    Http::fake();
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Brief.', presenterReport(['radar_site' => null])))
        ->handle(app(ChannelDispatcher::class));

    Mail::assertSent(DailySummaryMail::class);
    Http::assertNothingSent();
});

test('a Full summary report sent to a non-rich channel delivers the Brief text and fetches no radar', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->create(['apprise_url' => 'discord://token/id']);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny, high 79.', dsReport()))
        ->handle(app(ChannelDispatcher::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Today: Sunny, high 79.');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'radar.weather.gov'));
    expect(DeliveryLog::sole()->status)->toBe('success');
});

test('with no report an email channel gets the plain channel message as before', function () {
    Mail::fake();
    Http::fake();
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Brief.'))
        ->handle(app(ChannelDispatcher::class));

    Mail::assertSent(ChannelMessageMail::class);
    Mail::assertNotSent(DailySummaryMail::class);
});

test('a job queued before the report property existed unserializes without it and still delivers', function () {
    Mail::fake();
    Http::fake();
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    // Reproduces a job serialized by the pre-deploy code: native unserialize()
    // bypasses the constructor, so the new typed readonly `?array $report` is
    // left genuinely uninitialized (not null).
    $reflection = new ReflectionClass(DeliverScheduledAlert::class);
    $job = $reflection->newInstanceWithoutConstructor();
    foreach (['ruleId' => $rule->id, 'channelId' => $channel->id, 'title' => 'Daily summary', 'body' => 'Brief.'] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    $job->handle(app(ChannelDispatcher::class));

    Mail::assertSent(ChannelMessageMail::class);
    expect(DeliveryLog::sole()->status)->toBe('success');
});
```

- [ ] **Step 4: Run to verify they fail**

Run: `tests/Feature/DeliverScheduledAlertTest.php`
Expected: new tests FAIL (the constructor takes four arguments; no `DailySummaryMail` is sent).

- [ ] **Step 5: Implement `DeliverScheduledAlert` changes**

In `app/Jobs/DeliverScheduledAlert.php`:

1. Add imports: `use App\Actions\Weather\GetRadarSnapshot;`.
2. Extend the constructor:

```php
    /**
     * @param  array<string, mixed>|null  $report  the Full Daily Summary's structured payload; null for Brief, Free, Threshold, and for jobs queued before this property existed
     */
    public function __construct(
        public readonly int $ruleId,
        public readonly int $channelId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?array $report = null,
    ) {}
```

3. Replace the section of `handle()` from `$title = app(RenderAlertSubject::class)($rule);` through the `$dispatcher->send(...)` call with:

```php
        $title = app(RenderAlertSubject::class)($rule);
        $channelType = app(ChannelTypeRegistry::class)->find($channel->channel_type);
        // Str::limit's word-preserving mode collapses line breaks to spaces, so it must never
        // touch a body bound for a real inbox — see DeliverWeatherAlert for the same rule.
        $isEmailChannel = $channelType?->transport() === ChannelTransport::Mail;
        $body = (! $isEmailChannel && $rule->effectiveMessageBodyStyle() === MessageBodyStyle::Brief)
            ? Str::limit($this->body, 280, preserveWords: true)
            : $this->body;

        $meta = [
            'type' => 'alert',
            'rule' => $rule->name,
        ];

        // A job serialized before $report existed (SerializesModels' native
        // unserialize() bypasses the constructor) has this typed readonly property
        // genuinely uninitialized, not null. `??` has isset() semantics, which is
        // false for an uninitialized property instead of throwing, so it is read
        // exactly once here and never dereferenced again.
        $report = $this->report ?? null;

        if ($report !== null && $channelType?->supportsRichContent() === true) {
            $radarSite = $report['radar_site'] ?? null;

            $meta['daily_summary'] = $report;
            $meta['daily_summary_radar'] = is_string($radarSite) ? app(GetRadarSnapshot::class)($radarSite) : null;
        }

        $result = $dispatcher->send($channel, $body, $title, $meta);
```

(Everything after — the `DeliveryLog::create(...)` — is unchanged.)

- [ ] **Step 6: Implement `DispatchScheduledAlerts` changes**

In `app/Console/Commands/DispatchScheduledAlerts.php`:

1. Add imports: `use App\Actions\Weather\BuildDailySummaryReport;`, `use App\Enums\MessageBodyStyle;`, `use App\Support\ChannelTypes\ChannelTypeRegistry;`, `use Illuminate\Support\Facades\Log;`, `use Throwable;`.
2. Add `BuildDailySummaryReport $buildReport` as the fourth `handle()` parameter (after `$buildSummary`).
3. Replace the dispatch loop at the end of `handle()`:

```php
            $report = $this->reportFor($rule, $buildReport);

            foreach ($rule->notificationChannels as $channel) {
                DeliverScheduledAlert::dispatch($rule->id, $channel->id, $rule->displayName(), $message, $report);
                $dispatched++;
            }
```

4. Add this method to the class:

```php
    /**
     * The Full Daily Summary's structured payload, built once per rule (not per
     * channel). Null for everything else: Threshold rules, Brief/Free/unset
     * message styles (only an explicit Full counts), and a Full rule with no
     * attached channel that could render it — building the report costs several
     * NWS requests, so it is skipped when nothing could use it. A build failure
     * degrades to "Brief only", never to "no delivery".
     *
     * @return array<string, mixed>|null
     */
    private function reportFor(AlertRule $rule, BuildDailySummaryReport $buildReport): ?array
    {
        if ($rule->category !== AlertCategory::DailySummary || $rule->effectiveMessageBodyStyle() !== MessageBodyStyle::Full) {
            return null;
        }

        $registry = app(ChannelTypeRegistry::class);

        if (! $rule->notificationChannels->contains(fn ($channel): bool => $registry->find($channel->channel_type)?->supportsRichContent() === true)) {
            return null;
        }

        try {
            return $buildReport($rule->location, (bool) $rule->user->is_test_account);
        } catch (Throwable $e) {
            Log::warning('Daily summary report build failed', ['rule_id' => $rule->id, 'exception' => $e::class]);

            return null;
        }
    }
```

- [ ] **Step 7: Run both files to verify they pass**

Run: `tests/Feature/DispatchScheduledAlertsTest.php` then `tests/Feature/DeliverScheduledAlertTest.php`
Expected: all PASS, including every pre-existing test. If the "report is not built when no attached channel can render it" test fails because the Brief message itself still calls the forecast endpoint — that is expected and fine; the assertion only forbids observation and radar requests.

- [ ] **Step 8: Record the project rule**

Append to `.ai/rules/jobs.md`:

```markdown

## DeliverScheduledAlert's report is optional queue state, and radar bytes never go on the queue
`DeliverScheduledAlert::$report` (the Full Daily Summary's structured payload) is a trailing promoted `readonly` constructor property. A job serialized before it existed comes back with it *uninitialized*, not null, because `SerializesModels`' native `unserialize()` bypasses the constructor — reading it directly throws. `handle()` reads it once via `$this->report ?? null` (isset semantics) and never dereferences the property again; keep that pattern for any property added to a queued job (see also `DeliverWeatherAlert::$locationId`). The payload must stay plain data (no models, `Carbon`, closures). The radar image is fetched inside `handle()` (`GetRadarSnapshot`) and handed to the in-process dispatcher through `$meta['daily_summary_radar']`, never stored in the report: binary bytes would bloat the queue payload and can't live in a text cache column un-encoded. Only a channel type whose `supportsRichContent()` is true (Email) gets the rich mail; every other channel gets the Brief text.
```

- [ ] **Step 9: Pint, run the affected area, then the full suite**

```bash
vendor/bin/pint --dirty --format agent
docker exec wxnotify-nextgen-front-end-1 sh -c 'cd /var/www/html && DB_HOST=pgsql php -d memory_limit=-1 vendor/bin/pest --compact' | sed 's/\x1b\[[0-9;]*m//g' | tail -8
```

Expected: the full suite passes (baseline before this plan: 1442 tests; the new tests add to that count).

- [ ] **Step 10: Commit**

```bash
git add app/Console/Commands/DispatchScheduledAlerts.php app/Jobs/DeliverScheduledAlert.php .ai/rules/jobs.md tests/Feature/DispatchScheduledAlertsTest.php tests/Feature/DeliverScheduledAlertTest.php
git commit -m "Deliver the Full Daily Summary to email channels, Brief to everything else

DispatchScheduledAlerts builds the structured report once per Full rule;
DeliverScheduledAlert renders it only for channel types that support rich
content and fetches the radar image at send time.

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-Review (run against the spec)

**Spec coverage**
- Email-only rich channels → Task 1 + Task 9. Self-hosted icons → Task 2. Structured data + dedicated mail → Tasks 7–8. Observations-fail → still Full minus section → Task 7 (`guard`) + Task 8 (`current` null renders). Free/null → Brief → Task 9 (`!== Full`). Radar fetched at send time, embedded CID, omitted on failure → Tasks 6, 8, 9. Hourly 12 → Task 4. Alerts in effect from `active_wwas`, polygon-wins, test routing, ordering → Task 5. Sun via `date_sun_info` → Task 4. Section order → Task 8 view + ordering assertion. Optional sections independent → Task 7. Header incl. station lat/lon/elevation → Tasks 3 + 8. Feels-like fallback chain, compass wind, gust-only-when-present, null → em dash → Tasks 3 + 8. Station fallback (5 stations, 3 h) → Task 3. Radar safety (site regex, fixed host, no redirects, mime/magic/size, base64 cache) → Task 6. Queue-compat `?? null` guard → Task 9. Escaping → Task 8. Open items resolved: skip-building optimization included (Task 9 `reportFor`); zone resolution reuses `ResolveLocationZones` as-is (Task 5).
- Spec delta recorded: the report gains a `timezone` key (Task 7) — needed by the presenter's "Radar as of {now}" caption; no other spec change.

**Placeholder scan:** no TBD/TODO; every step has runnable code or an exact command. The two "adapt if the API differs" notes (Task 5 JSON containment fallback, Task 8 array-transport accessor) each give the concrete alternative and fix what must stay constant.

**Type consistency:** `GetNwsPoint` keys (`forecast`, `forecastHourly`, `observationStations`, `radarStation`, `timeZone`) match every consumer. Report keys (`location`, `timezone`, `sun`, `alerts`, `current`, `radar_site`, `hourly`, `periods`) match `BuildDailySummaryReport`, `DailySummaryPresenter::present()`, `presenterReport()` and `DeliverScheduledAlert`. Current-conditions keys (`station`, `observed_at`, `description`, `icon`, `temperature_f`, `feels_like_f`, `humidity_pct`, `wind{direction,speed_mph,gust_mph}`, `barometer_inhg`, `dewpoint_f`, `visibility_mi`) match Task 3's output and Task 8's presenter. Meta keys `daily_summary` / `daily_summary_radar` match Tasks 8 and 9. `DailySummaryMail` property names (`messageTitle`, `report`, `briefText`, `radar`) match its tests and `MailTransport`. `WeatherIcon::{fromUrl,url}` signatures match all callers.
