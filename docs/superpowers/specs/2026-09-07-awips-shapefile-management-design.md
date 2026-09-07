# AWIPS Basemap Shapefile Management — Design

## Context

The `wxnotify-nextgen-plan.html` master plan (DATA §"Reference geography: AWIPS Shapefiles", FEAT §"Admin tooling" → Geography) specifies a prerequisite for the Weather Alert Engine: real NWS zone/county/CWA/marine/fire-zone/state boundary geometry, imported from NOAA's [AWIPS Shapefile distribution](https://www.weather.gov/gis/AWIPSShapefiles), reviewed and activated by an admin rather than auto-applied, with rollback and per-record attribute overrides that survive re-import. This spec turns that plan text into a concrete, verified design for this codebase.

**Explicit prerequisite relationship**: yesterday's Weather Alert Engine design (`2026-09-06-weather-alert-engine-design.md`) assumed no PostGIS — plain UGC-string matching and manual polygon math ported from a dead legacy library. This feature supersedes that approach for geography: with real PostGIS geometry available, `MatchingMode::Location`/`Polygon`/`CountyZone`/`Wfo` become real spatial queries (`ST_Contains`, `ST_DWithin`) against authoritative NOAA boundaries instead of approximations. The Alert Engine design will need revisiting once this ships — noted here, not re-designed.

## Scope decision: all 7 layers, one plan

Confirmed with the user: build the full pipeline against all 7 layers now, not a phased subset. The pipeline pattern is genuinely identical in shape across layers; only source format and target schema differ.

## Foundational infrastructure change: adopting PostGIS

**Verified, not assumed**: this app's current `pgsql` service is plain `postgres:18-alpine` — `SELECT * FROM pg_available_extensions WHERE name LIKE '%postgis%'` returns **zero rows**. PostGIS isn't just uninstalled, it isn't even available to install on this image. No spatial Eloquent package is installed either. Confirmed local-only (`APP_ENV=local`, no evidence of a deployed instance) — safe to adopt now via a clean re-migrate, no production data migration story needed.

**Adoption plan**, confirmed real and current via direct verification (not guessed):
- Switch `compose.yaml`'s `pgsql` service image from `postgres:18-alpine` to **`postgis/postgis:18-3.6`** — same Postgres major version (18), so no version-skip risk; verified as a real, current, stable tag (Docker Hub, pushed within the last week as of this writing).
- `composer require clickbar/laravel-magellan` — verified current release **v2.2.0** (May 2026), requires `php: ^8.2`, `laravel: ^11.0|^12.0|^13.0`. This is the actively-maintained successor to the now-deprecated `clickbar/laravel-eloquent-spatial` (confirmed the old package name 404s; Magellan is the real current one — don't let a plan or memory reference to the old name resurface during implementation).
- A migration enabling the extension: `DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');`.
- Geometry columns use Magellan's `Geometry`/`MultiPolygon` cast types, not raw PostGIS SQL strings, matching this project's Eloquent-native conventions elsewhere.

## The 7 layers are 8 downloads, not 7 identical shapes

**Verified by fetching each NOAA page's actual HTML directly** (not summarized/assumed):

| Layer (page) | Format | File(s) | Checksum published? |
|---|---|---|---|
| Public Zones (`/gis/PublicZones`) | ESRI shapefile `.zip` | 1 | Yes (MD5) |
| Counties (`/gis/Counties`) | ESRI shapefile `.zip` | 1 | Yes (MD5) |
| CWA Boundaries (`/gis/CWABounds`) | ESRI shapefile `.zip` | 1 | Yes (MD5) |
| Marine Zones (`/gis/MarineZones`) | ESRI shapefile `.zip` | **2** — Coastal Marine Zones + Offshore Marine Zones, both on one page | Yes (MD5, one per file) |
| Fire Zones (`/gis/FireZones`) | ESRI shapefile `.zip` | 1 | Yes (MD5) |
| US States (`/gis/USStates`) | ESRI shapefile `.zip` | 1 | Yes (MD5) |
| Zone–County Correlation (`/gis/ZoneCounty`) | **Pipe-delimited flat text**, `.dbx` extension | 1 | **No checksum published** — filename + "date last upload" only |

Every shapefile page follows the identical table structure: name / effective date / zip link / record count / checksum + upload-date text / change-log link / metadata link — confirmed across all 6 shapefile pages (label wording varies slightly — "Date last upload" vs. "Date Last Updated" vs. "Date Last Update" — but the column position is consistent). **A single generic scraper, parameterized per layer, handles all 6 shapefile pages.** The Zone–County Correlation page needs its own scraper (different structure entirely) and its own parser (pipe-delimited text, not a shapefile) — it's the one genuinely different pipeline in this feature, not a variant of the others.

**A real NOAA operational quirk, confirmed on multiple pages, not a one-off**: pages often list *two* rows — the currently-active version and a future-dated version NOAA has pre-published ahead of its effective date (the Zone–County Correlation page spells this out directly: *"Due to technical issues, the implementation date for zone changes has been moved to April 16, 2026. New files will be available by March 4, 2026, and the files will be locked down on March 24, 2026."*). **Design implication**: track the *latest listed* checksum/filename per layer, not "the" checksum — a page can legitimately show a pending future version before its lockdown date, and the polling job should treat that as available-to-stage the moment it appears, not wait for the date to arrive (the admin's own review-and-activate step is what actually gates when it goes live).

## Schema

```
shapefile_layers                       — one row per layer (8 rows total: 5 single-file
                                          shapefile layers — Public Zones, Counties, CWA,
                                          Fire Zones, US States — + Marine Zones' 2
                                          constituent files (Coastal, Offshore) = 7
                                          shapefile-format rows, + 1 correlation-file row)
  id
  key                     — string, unique, e.g. 'public_zones', 'marine_zones_coastal',
                            'marine_zones_offshore', 'zone_county_correlation'
  name                    — display name
  source_url              — the NOAA page to poll
  last_seen_filename      — nullable string (e.g. 'z_18mr25.zip', 'bp18mr25.dbx')
  last_seen_checksum      — nullable string (null for zone_county_correlation, which
                            publishes none)
  last_seen_effective_at  — nullable date
  active_version_id       — nullable, points at the currently-active shapefile_layer_versions row
  timestamps

shapefile_layer_versions               — append-only history per layer: every version ever
                                          staged, whether or not it was activated
  id
  shapefile_layer_id
  filename
  checksum                — nullable
  effective_at
  status                  — 'staged' | 'active' | 'superseded' | 'rejected'
  record_count            — from NOAA's own published count, for a sanity check against
                            what the parser actually produced
  diff_summary            — json: {added: int, removed: int, changed: int}
  activated_at            — nullable
  timestamps
```

**Per-layer live + staging table pairs** (one pair per actual geography table, e.g. `public_zones`/`public_zones_staging`, `counties`/`counties_staging`, `cwa_boundaries`/`cwa_boundaries_staging`, `marine_zones`/`marine_zones_staging` — Marine's two files load into the same live table, distinguished by a `zone_type` column, since they're the same conceptual entity type — `offshore`/`coastal`; `fire_zones`/`fire_zones_staging`; `us_states`/`us_states_staging`; `zone_county_correlations`/`zone_county_correlations_staging`). Each live table's natural key (zone UGC, county FIPS, WFO id, etc.) is what the diff computation and override matching key on.

**Activation** is a single-transaction atomic swap (matching the plan's explicit requirement — "a matching query in flight sees either the fully-old or fully-new geometry, never a half-updated mix"):
```sql
BEGIN;
ALTER TABLE public_zones RENAME TO public_zones_previous;
ALTER TABLE public_zones_staging RENAME TO public_zones;
ALTER TABLE public_zones_previous RENAME TO public_zones_staging;
COMMIT;
```
This is also exactly how **rollback** works — it's the same swap run again, restoring the previous version into the live slot. `shapefile_layer_versions.status` tracks which row is `active` vs. `superseded` so the admin UI knows which one a rollback would restore.

```
shapefile_record_overrides             — admin-entered attribute corrections, survive reimport
  id
  shapefile_layer_id
  record_key              — the natural key value this override applies to (e.g. a zone UGC)
  field                   — which column is overridden (name, wfo, etc. — never geometry)
  value
  created_by (user_id)
  timestamps
```
Applied as a final pass after each activation (or after staging, before diff — worth pinning down at planning time whether the diff should show NOAA's raw value or the post-override value; the plan's own text implies the diff should flag when NOAA's new value disagrees with a standing override, which means the diff needs to see both).

## Ingestion pipeline (per shapefile layer)

```
ScanShapefileLayer(ShapefileLayer $layer)      — the poller, run on a schedule
  1. Fetch $layer->source_url, parse the table row(s) for this layer's key
     (a per-layer selector — CSS-position-based, e.g. "second <td>'s <a>" —
     since there's no id/class to hook on; verified fragile-but-consistent
     across all 6 shapefile pages, needs a real per-layer parser regardless)
  2. Compare the latest listed checksum (or filename, for the correlation
     file) against $layer->last_seen_checksum / last_seen_filename
  3. If changed: download the zip/file, create a new ShapefileLayerVersion
     (status: staged), update $layer->last_seen_*

ImportShapefileVersion(ShapefileLayerVersion $version)
  1. Unzip (for the 7 shapefile-format rows) — phpmyadmin/shapefile v4.0.1
     (verified current, actively maintained, PHP ^8.2) parses .shp/.dbf into
     PHP records; a small purpose-built pipe-delimited parser handles the
     correlation file instead
  2. Truncate and repopulate this layer's *_staging table (real PostGIS
     geometry columns via clickbar/laravel-magellan, for the 7
     shapefile-format rows; plain columns for the correlation file, which
     carries no geometry of its own)
  3. Compute the diff against the live table (added/removed/changed, keyed
     on the layer's natural code), store it on the version row
  4. Send a database notification to staff (reusing the same channel
     already used for product retirement) — "Layer X has a pending update:
     N added, N removed, N changed — review and activate"

ActivateShapefileVersion(ShapefileLayerVersion $version)   — admin-triggered
  Atomic table-rename swap (above), apply standing overrides, mark this
  version 'active' and the previous one 'superseded'

RollbackShapefileLayer(ShapefileLayer $layer)              — admin-triggered
  Same swap, reversed

DismissShapefileVersion(ShapefileLayerVersion $version)    — admin-triggered
  Marks a staged version 'rejected' (e.g. the diff looks wrong) without
  activating it — clears the "pending review" badge without promoting
  anything. Since $layer->last_seen_checksum was already updated when this
  version was staged, the next scan won't re-flag the same NOAA file as new;
  a genuinely later NOAA update is still detected and staged normally.
```

## Admin UI

One `ShapefileLayerResource`:
- **List page**: 8 rows (the 7 named layers, with Marine Zones split into its 2 constituent files), each showing the active version's effective date and a "pending review" badge when a staged version is waiting.
- **View page** (per layer): the diff (added/removed/changed records, and any standing override NOAA's new data now disagrees with — flagged per the plan's explicit requirement), **Activate**/**Dismiss**/**Rollback** actions, a table of the layer's live records with inline attribute-override editing (name/WFO-assignment-style fields only, never geometry — matching the plan's explicit scope line: *"redrawing a boundary itself stays out of scope"*), and a read-only map view for spatial lookup (Leaflet, matching this app's existing `fahiem/filament-pinpoint` convention from the Locations feature, rendering the layer's `MultiPolygon` geometries as a GeoJSON layer).

## Testing plan

- `ScanShapefileLayerTest` (one per file-format, not per layer — all 7 shapefile-format rows share one parser, the correlation file has its own): `Http::fake()` a real captured page fixture, confirm a checksum change is detected, confirm no-op when unchanged, confirm the "two rows, take the latest" case.
- `ImportShapefileVersionTest`: a small real/representative shapefile fixture (a handful of records, not the full 4000+-record national file) imports into the staging table with correct geometry and attributes; the diff correctly identifies added/removed/changed against a seeded live table.
- `ActivateShapefileVersionTest` / rollback: confirms the atomic swap actually swaps (staging becomes live, live becomes staging), overrides are reapplied, `shapefile_layer_versions.status` transitions correctly.
- `ShapefileRecordOverrideTest`: an override survives a reimport of that layer; a new NOAA value that disagrees with a standing override is flagged in the diff, not silently overwritten or silently kept.

## Known gaps / open items for the planning pass

1. **Exact per-page CSS/positional selector for each of the 6 shapefile pages** — verified the table *shape* is consistent, but the actual scraping code needs the real selector for each page written and tested against a real fixture, not assumed uniform from one example.
2. **Diff-vs-override ordering** — whether the diff computation sees NOAA's raw incoming value or the value post-override-application; the plan implies raw (so a disagreement is visible), needs pinning down at planning time.
3. **Marine Zones' two files sharing one live table** — confirmed as the right shape conceptually (`zone_type` discriminator column) but the exact schema (shared columns vs. any per-type-only fields) needs checking against both files' actual `.dbf` attribute schemas before writing the migration.
4. **Zone–County Correlation's lack of a published checksum** — change detection must key on filename alone for this one layer; worth deciding whether to also hash the downloaded file ourselves for a more reliable second signal, given NOAA doesn't provide one.
5. **Scheduling cadence** — the plan notes layers move on very different schedules (CWA "barely changes year to year" vs. others shifting "every few months to about a year"); a single daily check across all layers is cheap enough not to need per-layer scheduling, but worth confirming that's the intended cadence rather than something more differentiated.
