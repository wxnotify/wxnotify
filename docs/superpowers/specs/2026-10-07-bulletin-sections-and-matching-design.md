# Section-aware bulletins and matching (alert pipeline, project 1 of 2) — Design

Source: owner report, 2026-10-07 — a Hurricane Watch in effect at Destin, FL (a saved location) was missing from the Active now widget. Investigation found the cause is not only the widget: the pipeline reads only the first zone block and the first VTEC line of a text bulletin, so alert rules can miss events too. This project fixes alert matching and delivery; project 2 (`2026-10-07-active-event-zone-coverage-design.md`) fixes the active-events list and builds on it.

## Problem

A VTEC text bulletin is a list of **sections** separated by `$$`. Each section has its own zone line (UGC), one or more VTEC lines (one per event it covers), its own polygon and its own text. The pipeline stores one `ingested_products` row per bulletin and records only the **first** zone block and the **first** VTEC line (`ExtractProductMetadata::parseUgcCodes()` takes the first match; `resolveProduct()` takes the first VTEC match). Evidence from the live database, 2026-10-07:

- **40% of VTEC text bulletins** (300 of 740 in three days) have several zone sections or several VTEC lines. By product: Hurricane Local Statement (TCV) 12 of 14, Marine Weather Message (MWW) 180 of 255, Coastal Flood (CFW) 32 of 69, Non-Precipitation Weather (NPW) 52 of 139, Flood Statement (FLS) 21 of 155.
- The Mobile Hurricane Local Statement for the Hurricane Watch (KMOB HU.A #1009) has 12 zone sections (ALZ266, ALZ265 … FLZ206 … FLZ203 …) but `ugc_codes` is `["ALZ266"]`.
- One Mobile marine section carries two events (`HU.A` NEW and `SC.Y` CON, same zones); only `HU.A` is recognised.
- The CAP feed delivers the same event as about 30 messages, one per zone, but a CAP message is skipped for matching whenever a paired text bulletin exists (`SyncActiveWwa::syncFromCap()`), on the assumption the text bulletin already covered it. It did not: Destin's zone (FLZ206) is in no text-derived zone list, so a "By my location" rule at Destin never matches this Hurricane Watch by either path.
- **A parse defect on top:** the UGC grammar accepts a range only on a bare number (`070>075`), not on a full code (`CAZ110>114`, standard NWS form). 63 of 737 VTEC text bulletins (8.5%) therefore yield **no zones at all**, mostly NPW and MWW, and a Location-mode rule cannot match them.

## Decisions (user-approved, from brainstorming)

- **Approach A: section-aware bulletins.** Split a bulletin into sections at ingest and match rules against sections. Rejected: patching by unioning every zone and VTEC line (cannot say which zones belong to which event; would label a Tropical Storm Watch zone a Hurricane Watch) and using CAP as the source of truth (arrives seconds later, not every product has one, and 30 messages per event would need delivery re-keyed by event).
- **Two projects**: this one (sections, matching, delivery) and project 2 (per-zone coverage for the active list). Project 2 depends on this one.
- **One notification per bulletin, naming every matching event.** Same volume as today (one per rule and channel per bulletin); the default subject lists each matched event once, in bulletin order, joined with " + " (for example "Hurricane Watch + Small Craft Advisory").

## Terms

- **Bulletin**: one `IngestedProduct` row (a text product or one CAP message).
- **Section**: the text from a zone line to the next `$$`.
- **Segment**: one row of the new table, i.e. one (section × VTEC line). A section with two VTEC lines gives two segments sharing the section's zones and polygon.

## Design

### Schema

`ingested_product_segments`: `id`, `ingested_product_id` (FK, cascade on delete, indexed), `position` (0-based, bulletin order of section then VTEC line), `vtec_office`, `vtec_phenomenon`, `vtec_significance`, `vtec_event_number`, `vtec_year`, `vtec_action`, `vtec_begin`, `vtec_end` (nullable), `product_id` (nullable FK to `products`, null on delete), `ugc_codes` (JSON, nullable), `polygon` (JSON, nullable, the same point-list shape as `ingested_products.polygon`), timestamps. An index on the five VTEC key columns (the same key as `ActiveWwa::vtecKeyFor()`). Model `IngestedProductSegment` with `ingestedProduct()` and `product()`; `IngestedProduct::segments()` ordered by `position`; a `vtecKey()` helper.

`delivery_log_segment`: `delivery_log_id` (FK, cascade), `ingested_product_segment_id` (FK, cascade), unique pair. Records which events a delivery covered.

`ingested_products` is **unchanged**: its columns keep being filled from the first section (with the UGC grammar fix below), so the product page, replays, the Test Product Generator, delivery grouping (`AppAlert` groups by rule and bulletin) and every other reader keep working. Non-VTEC products (AFD, forecasts) get no segments.

### Building segments

- `BuildBulletinSegments` runs as the **first step of `ProcessIngestedProduct::handle()`** for every bulletin that has `vtec_office`, including replays, before replay/PTSDY1/sync/match branching. It runs there, not inside `IngestProductText`, because the observer queues that job from the model's `created` event, before the ingest action returns; building at the start of the job guarantees segments exist when matching reads them and keeps a parse failure out of the ingest request. It is idempotent: it deletes the bulletin's segments and rebuilds them.
- A **text** bulletin is parsed by `ParseBulletinSegments`; a **CAP** bulletin gets exactly one segment from its already-stored `vtec_*`, `ugc_codes`, `polygon` and `product_id`.
- `ParseBulletinSegments` splits the text on `$$`, finds each section's zone line, reads **all** its VTEC lines, and its `LAT...LON` polygon, and resolves each VTEC line's catalog product (`Product::where('code', "{phenomenon}.{significance}")`). A section with no VTEC line yields no segment.
- **Fallback:** if no section with a VTEC line can be found, one segment is built from the bulletin-level columns (today's behaviour), so an odd bulletin never loses its alert.
- **UGC grammar fix** (a shared `ParseUgcLine` used by `ExtractProductMetadata` and the segment parser): a range may follow a full code (`CAZ110>114`, `ALZ261>266`); a zone line may wrap across lines (continuation lines end in `-`, the last ends in the `DDHHMM-` purge time); prefixes switch within a line (`MSZ086-LAZ070-076-`). The existing behaviours and tests are kept.
- The existing VTEC line and polygon parsing is reused; where it is private to `ExtractProductMetadata` it is extracted into shared classes with the existing tests moved or kept.

### Matching

`MatchWeatherAlertRules` is unchanged for a bulletin with no segments (non-VTEC products, an unparsable bulletin, factory-built test rows). For a bulletin with segments, each rule is checked **segment by segment in `position` order**:

- A segment matches when its catalog product is attached to the rule and its geography matches the rule's mode **using the segment's own zones and polygon** in place of the bulletin's: Location (zone/county overlap, plus the office rule for office-wide products), Polygon (the segment's polygon, falling back to its zones), County/Zone, WFO (the bulletin's office), Nationwide. The SPC/WPC risk-level filter, the replay and test-account routing, quiet hours, the `is_test` rules and the one-delivery-per-(rule, channel, bulletin) guard are unchanged.
- The rule's first matching location (by location id) is still the matched location.
- A rule with at least one matching segment sends **one** delivery per channel, carrying the ids of all its matching segments.
- The statement-follow-up lookup that reuses an event's stored polygon (`matchesActiveWwaPolygon`) is unchanged here; project 2 moves it to per-zone rows.
- CAP messages with a paired text bulletin are still skipped for matching: the text bulletin's sections now cover every zone. A CAP message with no text pair matches through its single segment.
- `SyncActiveWwa` is not changed by this project.

### Delivery

- `DeliverWeatherAlert` takes the matched segment ids (`segmentIds`, default empty, which keeps today's behaviour for queued jobs and non-segment bulletins).
- The **event name** of a segment is its catalog product's name, else `VtecEvent::name(phenomenon, significance)`, else "Weather alert". The default subject is the unique event names in bulletin order joined with " + ". A rule the subscriber has named keeps its name as the subject (unchanged); a custom subject's `{product}` becomes the same joined list; a message template's subject likewise. `{location}` and `{category}` are unchanged. `RenderAlertSubject` therefore receives the list of event names instead of a single product.
- The body is the full bulletin text, as now. `ComposeTemplatedAlertBody`'s Expiration and Motion sections read the active event of the **first matched segment** (found by its VTEC key) rather than the bulletin's first VTEC line.
- `DeliverWeatherAlert` writes the `delivery_log_segment` rows for the delivery log it creates, including `suppressed` and `failure` rows.

### Readers

`GetActiveNowAtMyLocations`'s "covered by your alerts" evidence joins `delivery_logs` to the active event through `delivery_log_segment` and the segment's VTEC key. Delivery logs written before this project have no links and keep the existing lookup through the bulletin's VTEC columns. The mobile API's alert grouping (rule and bulletin) and the dashboard's delivery log are unchanged.

### Rollout

- One migration creates the two tables. Alerts work for newly ingested bulletins immediately; nothing needs backfilling for that.
- `bulletins:parse-segments {--days=2}` re-runs `BuildBulletinSegments` over stored VTEC bulletins. It sends nothing. Run once after deploy so currently active events have segments (project 2's rebuild reads them).
- Restart the ingest container (`wxnotify-pipeline-api`) and the queue worker is already per-job.
- The factory-reset wipe (`WipePipelineData`, `PreviewFactoryReset`) includes both new tables, as `.ai/rules/pages.md` requires for any table that references `ingested_products`/`delivery_logs`.

## Testing

Real bulletins are saved as fixtures in `database/product_examples/` (a Mobile Hurricane Local Statement with 12 zone sections, a Mobile Marine Weather Message with `HU.A` and `SC.Y` in one section, an NPW with `CAZ110>114`-style ranges, a Coastal Flood bulletin) and used by Pest feature tests:

- **Parser:** sections and `$$`; several VTEC lines per section; ranges on a full code; wrapped zone lines; prefix switches; sections with no VTEC; the fall-back to one segment; CAP gives one segment; rebuilding is idempotent.
- **UGC fix:** the 63-of-737 class of bulletin now yields zones; every existing `ExtractProductMetadataTest` UGC case still passes.
- **Matching:** the Destin case end to end (a Location rule at FLZ206 matches the Hurricane Watch; a location in no section does not); a rule that watches only the second event of a section; a rule that watches two events of one section gets one delivery; each matching mode against segment data; the office rule for AFD still works; CAP with and without a text pair; bulletins with no segments behave exactly as before.
- **Delivery:** the joined subject; a named rule keeps its name; `{product}` in a custom subject; the duplicate guard; suppressed rows get their links; a delivery for the second event of a bulletin shows as "alerted" in Active now.
- **Rollout:** `bulletins:parse-segments` is idempotent and dispatches nothing.

## Rules to record

- `.ai/rules/pipeline.md`: a bulletin is a list of sections and rules match segments, never the bulletin's first VTEC line or first zone block; `BuildBulletinSegments` runs first in `ProcessIngestedProduct`; the CAP-with-text-pair skip is only safe because text segments are complete; the segment-less path must keep working.

## Out of scope

Splitting non-VTEC multi-section products (zone forecasts, coastal waters forecasts, hazardous weather outlooks); per-section narrative fields (hazard, impact stay from the first section); per-location notifications (the first matching location is still used); the active-events list and per-zone coverage (project 2); adding Storm Surge, Rip Current, Gale and other uncatalogued events to the alert catalog.
