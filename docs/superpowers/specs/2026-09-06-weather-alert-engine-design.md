# Weather Alert Engine (NWWS-driven) — Design

## Context

Phase 1's subscriber-facing config (`AlertRuleResource`, `LocationResource`, `NotificationChannelResource`) is fully built, but nothing actually *fires* an alert — no code matches an ingested NWWS product against a subscriber's `AlertRule` and delivers a notification. This is that engine, for the three `AlertCategory` cases it can actually serve today.

**Scope decision**: `AlertCategory` has 6 cases with genuinely different triggering mechanisms:
- **`WeatherAlerts`, `SpcWpc`, `ForecastDiscussion`** — event-driven, triggered by a new `IngestedProduct` row from the already-flowing NWWS pipe. **This spec covers these three.**
- **`Threshold`, `DailySummary`** — scheduled/periodic, driven by the NWS forecast API (`App\Actions\Weather\GetSevenDayForecast` already exists and works). A separate, later design.
- **`Lightning`** — has no data source of any kind in this app yet. Needs its own ingestion project (comparable in scope to the NWWS pipe) before any matching logic is meaningful. Not touched here.

**Revision note (2026-09-07)**: this spec originally assumed no local geometry existed anywhere in the app, so `Location`/`Polygon`/`CountyZone` matching was designed entirely around UGC-string overlap sourced from the external `api.weather.gov/points` NWS API. The AWIPS Shapefile Management feature has since shipped: real PostGIS geometry for `public_zones`, `counties`, `fire_zones`, `cwa_boundaries`, and the marine zone layers is now live in this database. The sections below (`Location & timezone resolution`, `MatchWeatherAlertRules`'s geography rules, the `CountyZone` wizard step) have been revised to use that data directly. Everything else in this document — ingest-time enrichment, delivery/`DeliveryLog`, the two known gaps — is unaffected and unchanged.

## Architecture

```
IngestedProduct row written (IngestNwwsProductController, already exists)
        │
        ▼
ExtractProductMetadata (new Action, called from the controller before the row is saved)
  - VTEC phenomena+significance → resolve Product.code → product_id
  - Non-VTEC products (AFD, SPC.*, WPC.*) → AWIPS-ID prefix → same product_id
  - UGC zone/county codes → ugc_codes
  - LAT...LON polygon (for point-precision warnings) → polygon
        │
        ▼  (all fields already on the row at INSERT time — one write, not two)
IngestedProduct::created  (new observer, mirrors ActivityLogObserver's role)
        │
        ▼
MatchWeatherAlertRules(IngestedProduct)   [mirrors MatchActivityAlertRules's shape]
  - AlertRule::whereIn('category', [WeatherAlerts, SpcWpc, ForecastDiscussion])
  - ->whereHas('products', ...)                          [WeatherAlerts/SpcWpc only]
  - + per matching_mode: Nationwide / Wfo / Location & Polygon / CountyZone
  - + risk_levels filter (SpcWpc only)
  - + quiet-hours check (suppress, still log)
        │
        ▼
DeliverWeatherAlert (queued job, one per matched rule × attached channel) [mirrors SendActivityAlert]
  - AppriseApiClient::notify() via the subscriber's own NotificationChannel
  - Writes a DeliveryLog row every time (success / failure / suppressed)
```

This deliberately reuses two patterns already proven in this codebase (the Visibility dispatch engine): a `MatchX` action that queries and dispatches, and a queued per-recipient delivery job that writes its own audit row. The differences from that engine: enrichment happens at ingest time (not observer time) since it needs to be stored as real columns before matching can query them; delivery fans out to the *subscriber's own* channels (a rule can have several, via `alert_rule_notification_channel`), not one fixed admin-configured target.

## Ingest-time enrichment

### VTEC → `product_id`

Real NWS VTEC format: `/k.aaa.cccc.pp.s.####.yymmddThhnnZ-yymmddThhnnZ/` (e.g. `/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/`). `pp` (2-letter phenomena) + `.` + `s` (1-letter significance) is exactly this app's `Product.code` format (`TO.W`, `SV.W`, `FF.W`, …) — no crosswalk table needed for these, just a regex extracting `pp` and `s` from any VTEC line(s) present, then `Product::where('code', "{$pp}.{$s}")->first()`.

A product can carry **multiple VTEC lines** (e.g., canceling one warning while issuing another for a different zone/event). Extract every distinct `(pp, s)` pair present; if more than one resolves to a different `Product`, the enrichment stores the **first** match as `product_id` for the FK-based matching path — but see "Known gap" below on whether this is precise enough long-term. Any action code (`NEW`/`CON`/`CAN`/`EXP`/etc.) counts as alert-worthy for v1; a cancellation is still useful information to a subscriber ("your tornado warning has ended"), so it isn't filtered out.

### Non-VTEC products → `product_id` via AWIPS-ID prefix

A handful of catalog codes aren't VTEC-tagged at all: `AFD`, `SPC.OUTLOOK`, `SPC.MD`, `SPC.FIRE`, `WPC.MPD`. These need matching on `IngestedProduct.awipsid`'s leading characters instead. **Verified with real live NWWS traffic** (queried the `ingested_products` table directly): `AFD` products really do carry `awipsid` like `afdbmx`, `afdddc`, `afdgjt` — a fixed 3-letter prefix (`afd`) + WFO suffix, confirming the prefix-matching approach for at least this one.

**Could not confirm** `SPC.OUTLOOK`/`SPC.MD`/`SPC.FIRE`/`WPC.MPD`'s real `awipsid` prefixes — a ~600-row live sample had zero matches for `swo`/`mcd` (SPC products are infrequent; the sample window was short). **This needs a real check before implementation** — either a longer live sample once `nwws` has run for a day, or NOAA's own AWIPS ID directory — not a guessed prefix. The crosswalk table (a small `const` array, mirroring `ProductCatalogSeeder::MATCHED`'s shape) should be written once these are confirmed, not before.

Product families outside these categories (e.g. plain river/water-resources `RWR*` chatter, confirmed abundant in the live sample) simply won't resolve to any `product_id` — that's correct, they're not in the catalog and shouldn't match any rule.

### UGC zone/county codes → `ugc_codes`

Standard format: a line (or wrapped lines) of dash-separated codes immediately before the VTEC line, e.g. `NYZ069-070>075-206-` terminated by a 6-digit purge timestamp (`DDHHMM-`). Parse into a flat array of zone/county UGC strings (expanding `>` ranges, e.g. `070>075` → `070, 071, 072, 073, 074, 075`). This is a well-documented, stable NWS text-product convention — written fresh rather than adapting the legacy library's EMWIN-specific `<UGC+####>` purge-marker parsing, which represents the same concept differently for broadcast delivery and isn't a clean fit for NWWS's raw text.

### Polygon coordinates → `polygon`

Reuses the approach (not a direct code copy — see "the dead legacy library" below) from `App\Libraries\AwipsProduct::getAffectedAreaCoords()`: finds a `LAT...LON` line, collects wrapped continuation lines until a blank line or `TIME...` line, and decodes each 4-or-5-digit coordinate pair into a real `{lat, lon}` (the encoding drops the decimal point and assumes a fixed number of digits — e.g. `3982` → `39.82`, `8066` → `-80.66`). Only applies to point-precision severe-weather product types (`SVR`, `TOR`, `FFW`, `SVS`, etc., matching that method's own type filter) — most VTEC products don't carry a polygon at all, so this column stays `null` far more often than not, and `MatchingMode::Polygon` naturally can't match those.

### The dead legacy library

`app/Libraries/AwipsProduct.php` (1665 lines, carried over from the current-gen app) already implements VTEC parsing and polygon extraction — but it depends on 13 reference tables (`vtec_phenomena`, `counties`, `wfos`, etc.) that **do not exist in this database** (confirmed: zero of them exist, no migrations for any of them). Its VTEC *full-name* lookups (`VtecPhenomena`/`VtecSignificance`) aren't needed here — the `Product` catalog already has real names — so there's no reason to resurrect that reference-data schema. The polygon-coordinate decoding math is pure PHP with no DB dependency and is worth using as a direct reference when writing `ExtractProductMetadata`, but the new code should be a clean, purpose-built extractor living in `app/Actions/Pipeline/`, not a rehabilitated dependency on a 1665-line file mixing EMWIN-specific and DB-dependent concerns it doesn't need.

### New columns

```
ingested_products:
  product_id    — nullable FK to products, nullOnDelete
  ugc_codes     — nullable JSON array of strings
  polygon       — nullable JSON array of {lat, lon}
```

## Location & timezone resolution

This splits into two independent resolutions, sourced differently:

### Zone/county codes — resolved locally via PostGIS

`counties`, `public_zones`, and `fire_zones` are now real, populated tables (from the AWIPS Shapefile Management feature), each with real geometry and a natural-key UGC-shaped column (`state_zone` / `fips`). A subscriber's `Location.lat`/`Location.lng` can be tested directly against these with `ST_Contains`, replacing the external API for this specific purpose — no HTTP call, no forever-cache staleness risk if a zone boundary is ever redrawn, and it stays correct automatically as the AWIPS feature's own scan/import pipeline keeps these tables current.

**SRID note**: shapefile geometry is stored SRID 4269 (NAD83, confirmed via the AWIPS feature's real `.prj` files); `Location.lat`/`lng` have no SRID of their own (plain `decimal(10,7)` columns). Construct the query point directly as SRID 4269 (e.g. `ST::point($lng, $lat)->setSrid(4269)` via the `Clickbar\Magellan` facade, or raw `ST_SetSRID(ST_MakePoint(lng, lat), 4269)`) rather than transforming from 4326 — the NAD83/WGS84 datum difference is sub-meter, irrelevant at the scale of a zone polygon boundary, and skipping `ST_Transform` keeps the query simpler.

**New Action**: `App\Actions\Weather\ResolveLocationZones` — given a `Location`, runs one `ST_Contains` query per layer (`public_zones`, `counties`, `fire_zones`) and writes the results onto three new nullable columns on `locations`:

```
locations:
  county_ugc              — nullable string (e.g. "NYC061")
  forecast_zone_ugc       — nullable string (e.g. "NYZ072")
  fire_weather_zone_ugc   — nullable string (e.g. "NYZ072")
```

Called lazily — the first time a location's zones are needed for matching or quiet-hours — same trigger point as the original design's cached API call, not from a `Location`-creation hook.

### `timeZone` / `forecastUrl` — still the external API

NOAA's local `time_zone` shapefile column is an abbreviated code (`E`/`C`/`M`/`P`), not an IANA identifier — it can't feed quiet-hours delivery logic, which needs a real timezone for time-of-day comparison. `App\Actions\Weather\GetSevenDayForecast::resolveForecastUrl()` already calls `https://api.weather.gov/points/{lat},{lng}` and caches the result forever, keyed by coordinate; this stays, narrowed to extracting only `properties.forecast` and `properties.timeZone` (e.g. `"America/New_York"`) — `properties.county`/`forecastZone`/`fireWeatherZone` are no longer needed from this response now that `ResolveLocationZones` provides the same codes locally. Add `locations.timezone` (nullable string), populated from this call the same lazily-triggered way as before.

### WFO auto-suggestion (`ForecastDiscussion` category)

Not part of the original design but now trivial: `ST_Contains` against `cwa_boundaries` for the subscriber's `Location` resolves a suggested WFO (`cwa` column) to pre-fill `forecastDiscussionFields()`'s `wfo` `TextInput` — a small UX addition, not a matching-engine change (the subscriber can still override it).

## `MatchWeatherAlertRules`

```php
public function __invoke(IngestedProduct $product): void
```

Query shape (mirrors `MatchActivityAlertRules`'s structure):

```php
AlertRule::query()
    ->where('is_active', true)
    ->whereIn('category', [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc, AlertCategory::ForecastDiscussion])
    ->where(function ($query) use ($product) {
        // WeatherAlerts/SpcWpc: must reference this product
        $query->where(fn ($q) => $q->whereIn('category', [...])->whereHas('products', fn ($q) => $q->whereKey($product->product_id)))
            // ForecastDiscussion: matches by WFO only, no product filter
            ->orWhere('category', AlertCategory::ForecastDiscussion);
    })
    ->get()
    ->filter(fn (AlertRule $rule) => $this->matchesGeography($rule, $product) && $this->matchesRiskLevel($rule, $product))
    ->each(function (AlertRule $rule) use ($product) {
        if ($this->isWithinQuietHours($rule)) {
            $this->logSuppressed($rule, $product);
            return;
        }
        $rule->notificationChannels->each(fn ($channel) => DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id));
    });
```

**`ForecastDiscussion` is a special case, not part of the `MatchingMode` switch below**: its wizard step (`forecastDiscussionFields()`) is just a bare `wfo` `TextInput` — that category never sets `matching_mode` at all. Its geography check is always the direct WFO comparison (`substr($product->cccc, 1) === strtoupper($rule->wfo)`), regardless of any `matching_mode` value on the row (there won't be one).

**`WeatherAlerts`/`SpcWpc` geography matching, per `MatchingMode`:**
- `Nationwide` — always matches (the product filter already narrowed it).
- `Wfo` — same direct comparison as `ForecastDiscussion` above. No new lookup needed; `cccc` is already captured at ingestion.
- `Location` — check the rule's `Location.forecast_zone_ugc`/`county_ugc` (resolved locally by `ResolveLocationZones`, see above) for overlap with `$product->ugc_codes`.
- `Polygon` — if `$product->polygon` is set, a real `ST_Contains`/Magellan point-in-polygon test between the parsed polygon (as WKT) and the rule's `Location` point (SRID 4269, per the SRID note above) — no more hand-rolled ray-casting math; **falls back to the `Location` behavior above if the product has no polygon** (most VTEC products don't carry one), so a subscriber in `Polygon` mode isn't silently missed on a warning type that's UGC-only.
- `CountyZone` — direct overlap between the rule's stored `county_zone_codes` and `$product->ugc_codes`. The codes themselves now come from a real State → County/Zone picker (see below) instead of hand-typed text, but the comparison logic is unchanged.

**Risk level** (`SpcWpc` only): if the rule has `risk_levels` set, the ingested product's text must be checked for a matching risk level string — this needs its own small extractor (SPC outlook/MD text has a documented risk-level line) not detailed further here; flagged as an implementation-time detail, not a design blocker.

## `CountyZone` wizard step — real picker, not free text

`AlertRuleForm.php`'s `countyZoneField()` is currently a plain `TextInput::make('county_zone_codes')` — a subscriber hand-types comma-separated UGC codes (e.g. `TXZ211, TXC201`), validated only by a regex, with no guarantee the code actually exists. This was a reasonable placeholder before any zone data existed in the app; `counties` and `public_zones` are now real, populated tables, so this step is revised to a real picker:

- A `Select::make('state')` (US state abbreviations), then a `Select::make('county_zone_codes')->multiple()` populated live (via `->options(fn (Get $get) => ...)`, keyed on the chosen state) from `counties`/`public_zones` — one merged option list, county and forecast-zone entries both shown (labeled to distinguish them, e.g. `"NYC061 — Suffolk County"` vs `"NYZ072 — Suffolk"`), matching what a real VTEC product's `ugc_codes` can contain.
- The column and stored format are unchanged — `county_zone_codes` stays the same comma-joined string on `alert_rules`, dehydrated the same way. Only the input mechanism changes, so `MatchWeatherAlertRules`'s comparison logic (above) needs no changes at all for this.

## Delivery & `DeliveryLog`

`DeliverWeatherAlert` (queued job, mirrors `SendActivityAlert`'s shape — takes ids, not models):

```php
public function __construct(
    public readonly int $ruleId,
    public readonly int $channelId,
    public readonly int $ingestedProductId,
) {}
```

`handle()` re-fetches all three, no-ops if any are gone, calls `AppriseApiClient::notify()` via the channel's `apprise_url`, and writes a `DeliveryLog` row (`status`: `success`/`failure`, `error_detail` on failure) — `channel_type`/`user_id` denormalized onto the log row exactly as its existing schema already expects (it was built with this in mind, sitting empty until now).

**Quiet hours**: suppressed matches **also** write a `DeliveryLog` row, with `status = 'suppressed'` — real audit value ("did my rule fire but get held back?"), and `DeliveryLog.status` is a plain string column with no DB-level enum constraint, so this needs no migration. `DeliveryLogsTable` (admin) may want a third badge color for it — a small, non-blocking implementation detail.

Quiet hours themselves: `quiet_hours_start`/`quiet_hours_end` are plain `TimePicker` values with no explicit timezone captured today. Resolve against the rule's `Location.timezone` (new column, see above). **Decision**: `Nationwide`/`Wfo` rules have no `Location` at all — "quiet hours" implicitly means "my nighttime," which doesn't have a well-defined answer without one, and guessing a timezone (UTC, or the issuing office's) would silently apply a rule the subscriber never actually configured for that timezone. Simplest, honest behavior: quiet hours only apply to `Location`/`Polygon`/`CountyZone`-scoped rules; a `Nationwide`/`Wfo` rule with quiet hours set always delivers regardless (the fields are hidden for those matching modes in a future form pass, or a helper note is added — not designed further here since it's a form-UI detail, not an engine one).

## Testing plan

- `ExtractProductMetadataTest`: real sample product texts (a tornado warning with a polygon, a severe thunderstorm warning with UGC codes only, an AFD, a product with two VTEC lines) each resolve to the right `product_id`/`ugc_codes`/`polygon`.
- `ResolveLocationZonesTest`: a real coordinate known to fall inside a specific `public_zones`/`counties`/`fire_zones` polygon resolves to the correct UGC code on each of the three new `Location` columns; a coordinate outside US coverage resolves all three to `null` without erroring.
- `MatchWeatherAlertRulesTest`: one test per `MatchingMode` confirming a match and a non-match (`Polygon`'s real point-in-polygon test needs both a point clearly inside and clearly outside the product's polygon, plus the no-polygon fallback-to-`Location` case); `SpcWpc` risk-level filtering; `ForecastDiscussion`'s WFO-only matching; quiet-hours suppression writing a `suppressed` `DeliveryLog` row instead of dispatching.
- `DeliverWeatherAlertTest`: mirrors `SendActivityAlertTest`'s shape — success writes a `success` log row, a failed Apprise call writes `failure` with `error_detail`, a deleted rule/channel/product no-ops.
- `AlertRuleForm`'s `CountyZone` step (Filament test): selecting a state populates the county/zone options from real data; selecting one or more stores the expected comma-joined `county_zone_codes` string, unchanged from the current schema-level test coverage.

## Known gaps / open items for the planning pass

1. **SPC/WPC AWIPS-ID prefixes unconfirmed** — needs a longer live sample or NOAA documentation before the non-VTEC crosswalk table can include them. `AFD`'s prefix (`afd`) is confirmed with real data.
2. **SPC risk-level extraction from product text** — needs its own small parser; the exact text format isn't pinned down in this doc.

Everything else raised during design (multiple VTEC lines → first match; `Nationwide`/`Wfo` quiet hours → always deliver) has a concrete v1 decision above, not left open.
