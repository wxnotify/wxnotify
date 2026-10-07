# Per-zone coverage for active events (alert pipeline, project 2 of 2) — Design

Source: owner report, 2026-10-07 — a Hurricane Watch in effect at Destin, FL was missing from the Active now widget. Project 1 (`2026-10-07-bulletin-sections-and-matching-design.md`) makes alert rules see every section of a bulletin; this project fixes the **active events list** the widget and the daily summary read, and depends on project 1's segments.

## Problem

`active_wwas` holds one row per VTEC event, and every incoming bulletin for that event does `updateOrCreate(vtecKey, [... 'ugc_codes' => <this bulletin's zones>, polygon replaced ...])` (`SyncActiveWwa::syncFromText()` and `syncFromCap()`). The last bulletin to arrive overwrites the geography of every earlier one. An event is routinely issued as many bulletins: KMOB's Hurricane Watch #1009 arrived as about 30 CAP messages, one per zone, and the row ended holding only `["FLZ203"]`; Destin's zone FLZ206 arrived mid-batch and was overwritten seconds later. Measured on 2026-10-07: **51 of 239 active events (21%) have lost zones, 284 zones in total**; Small Craft Advisories are worst (21 events), and the Hurricane Watch loses up to 25 zones.

Related flaws in the same code:

- The event's **status** is set from the last bulletin's action, so one zone's `CAN` or `EXP` marks the whole event Cancelled/Expired while other zones remain active.
- `UPG` (an upgrade, which ends the old event) is treated as Active (`resolveStatus()` only maps `CAN` and `EXP`).
- Only the first VTEC line of a bulletin ever creates an event row (project 1 makes every VTEC line a segment).

## Decisions (user-approved, from brainstorming)

- Per-zone coverage in a new table; the event row stays one per VTEC event.
- A cancel, expire or upgrade section removes only its own zones; the event's status is derived from its zones.
- Rebuild current events from stored segments after deploy, so Destin's Hurricane Watch reappears at once.

## Design

### Schema

`active_wwa_areas`: `id`, `active_wwa_id` (FK, cascade), `ugc_code` (string), `polygon` (PostGIS `geometry(Polygon, 4269)`, nullable, GiST-indexed), `status` (`Active`, `Cancelled`, `Expired`), `effective` and `expires` (nullable timestamps), `latest_segment_id` (nullable FK to `ingested_product_segments`, null on delete), `applied_at` (the receiving bulletin's `received_at`, to ignore a late, older bulletin), timestamps. Unique on (`active_wwa_id`, `ugc_code`); an index on `ugc_code`. Model `ActiveWwaArea`; `ActiveWwa::areas()`.

`active_wwas` is unchanged and remains the event row: product, derived status, `effective`, derived `expires`, headline, motion, source, `latest_ingested_product_id`, `is_test`. Its `ugc_codes` and `polygon` columns keep being set from the latest segment (the Test Product Generator and admin tools read them) but **nothing reads them for coverage** any more, except as the fallback for an event that has no area rows (legacy rows, an odd bulletin).

### Applying a segment

`SyncActiveWwa` loops over the bulletin's segments (project 1) in order and applies each one with a new `ApplySegmentToCoverage`:

1. **Find or create the event row** by the segment's VTEC key (`ActiveWwa::vtecKeyFor()` semantics, `is_test`-scoped as `scopeForProduct()` requires). Every VTEC line now has its event row. `product_id` comes from the segment; text rows also set `latest_ingested_product_id`, CAP rows their `headline` and the paired text row's id as today; `source` is `text` or `cap`. Motion is parsed from the bulletin text and applied to the **first segment's** event only, as today (per-section motion is out of scope).
2. **By the segment's action:**
   - `NEW`, `CON`, `EXT`, `EXA`, `EXB`, `COR`, `ROU`: for each zone in the segment, upsert its area row as `Active` with the segment's `effective`/`expires` and polygon. A segment with a polygon sets the polygon on all its zones; a CAP polygon is attached to a zone whose text section had none, and never replaces a text section's own polygon.
   - `CAN`, `UPG`: mark the segment's zones `Cancelled`. `EXP`: mark them `Expired`. Other zones of the event are untouched. (`UPG` ending the old event is a deliberate change: today it leaves the event Active until it expires.)
   - A segment older than a row's `applied_at` is ignored for that row.
3. **Derive the event row:** `status` is `Active` while any area is `Active`; otherwise `Cancelled` or `Expired` by the last terminal action (an event that never had an area takes the action's status as today). `expires` is the latest end time among active areas, or `null` if any active area has none (until further notice). `effective` is kept as the earliest. The existing "demote to Expired once the recorded end time has passed" check keeps working per area through the lookup's expiry filter.

Text and CAP bulletins go through the same function, so they never conflict: a CAP message re-applies zones the text section already created. Matching dispatch is unchanged (text always; CAP only when no text pair exists), and `MatchWeatherAlertRules` is called once per bulletin as now. Replays still never write `active_wwas`.

### Readers

- `GetActiveAlertsForLocation::activeFor()` finds events with at least one `Active`, unexpired area that **covers the location**: a row with a polygon covers a point only if the polygon contains it (polygon is authoritative, as now); a row without one covers it when its zone is one of the location's zone or county codes. An event with no area rows falls back to its own `polygon`/`ugc_codes` (the existing test, kept). Each event appears once, with the expiry of its covering row (the latest if several, `null` if open-ended). Sorting, the family colours, the VTEC-derived names and the `is_test` option are unchanged. This feeds the Active now widget (`GetActiveNowAtMyLocations`) and the daily summary.
- `MatchWeatherAlertRules::matchesActiveWwaPolygon()` (a polygon-less follow-up statement reuses its event's polygon) reads the event's area polygons: covered if any row's polygon contains the point; `null` if the event has none.
- `ComposeTemplatedAlertBody` and `FormatMotionVector` still read the event row.

### Rollout

- The migration creates `active_wwa_areas` and fills it from every existing active event's `ugc_codes` and `polygon` (one row per zone, the event's polygon on each, status mapped from the event), so the lookup is correct-as-before the moment it deploys.
- `active-wwas:rebuild {--days=2}` then re-derives the true state: for each event touched in the window it deletes the area rows and replays the stored segments (oldest bulletin first) through `ApplySegmentToCoverage`, then recomputes the event row. It needs project 1's `bulletins:parse-segments` run first. It is idempotent, sends nothing and writes no delivery. After it runs, Destin's Hurricane Watch reappears because all 30 zone messages are still stored. Expired and cancelled events are left alone.
- Restart the ingest container (`wxnotify-pipeline-api`) and the dashboard container, as with earlier fixes.
- `WipePipelineData` and `PreviewFactoryReset` include `active_wwa_areas` (`.ai/rules/pages.md`).

## Testing

Pest feature tests, with fixtures from project 1:

- **Sync:** the Mobile Hurricane Watch replayed zone by zone ends with every zone; a `CAN` for one zone removes only it and the event stays Active; a `CAN` for the last zone sets the event Cancelled; `UPG` ends its zones; `EXP` sets Expired; a late older bulletin does not undo a newer state; a CAP message after its text section adds nothing wrong, fills a missing polygon and never replaces a text polygon; the event's `expires` and `status` derive correctly (open-ended, mixed ends); a second VTEC line (`SC.Y`) in a section creates its own event row; the existing `SyncActiveWwa` tests still pass (a single-segment bulletin behaves as before).
- **Readers:** Destin is covered by the Hurricane Watch (zone match) and a location in a cancelled zone is not; a location outside a storm-based polygon but inside a listed zone stays excluded; an event with no area rows falls back to its own columns; the widget and the daily summary agree; the covering row's expiry is shown; sorting unchanged.
- **Follow-up statements:** a polygon-less SVS/FFS uses the event's area polygons.
- **Rollout:** the migration back-fills areas; the rebuild restores a wrongly collapsed event, is idempotent (run twice, same result) and dispatches nothing.

## Rules to record

- `.ai/rules/pipeline.md`: an event is many zones; `active_wwas.ugc_codes`/`polygon` are no longer coverage, `active_wwa_areas` is; a terminal action ends only its own zones; `SyncActiveWwa` applies segments, never a bulletin's first VTEC line.
- `.ai/rules/models.md`: `ActiveWwa` coverage lookups go through the areas, and any new lookup from a bulletin still goes through `forProduct()`/the segment's VTEC key.

## Out of scope

Per-zone motion; dropping the legacy `ugc_codes`/`polygon` columns on `active_wwas` (a later cleanup once nothing needs the fallback); adding Storm Surge, Rip Current, Gale and other uncatalogued events to the alert catalog; a scheduled sweep that expires events with no new bulletin (the lookup's expiry filter already hides lapsed zones).
