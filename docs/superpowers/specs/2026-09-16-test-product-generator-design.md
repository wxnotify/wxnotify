# Test/Synthetic Product Generator — Design

## Context

The master plan's `FEAT` section describes a **Test Product Generator**: an admin tool that lets staff fabricate a synthetic NWS product (a Tornado Warning, an SPC Mesoscale Discussion, an Area Forecast Discussion, etc.), feed it into the pipeline exactly like a real one, and see the whole chain — parsing, VTEC/geography matching, alert dispatch, formatted notification — actually run, without waiting for NWS to issue a real example of a rare product type.

This is buildable now because the underlying pipeline already exists end-to-end:

- The real intake point is `IngestNwwsProductController`, which parses a product's raw text (`ExtractProductMetadata` / `ExtractCapMetadata`, plus `ParseVtecString` and `ParseNarrativeSections` under the hood) and creates an `IngestedProduct` row.
- `IngestedProductObserver::created()` fires on that row's creation and dispatches into `SyncActiveWwa` / `MatchWeatherAlertRules`, which in turn dispatches `DeliverWeatherAlert` jobs per matched rule × channel.
- `database/product_examples/*.txt` already holds real captured NWS bulletins for nearly every product code, used as Pest fixtures today (e.g. `SVW.txt`, `TOW.txt`, `SWO.txt`, `AFD.txt`, `SPS.txt`) — these serve as the reference format for the templates this feature adds.
- `Product` (the admin-managed catalog) already has a `status` enum including `draft`, with no real tool yet for an admin to validate a draft product's parser support before flipping it to `active` — this generator is exactly that tool.

**Scope for v1** (matching the master plan's own scoping): warning-family products (VTEC, UGC line, optional polygon, optional damage tags), discussion-family products (SPC MD / WPC MPD, with an embedded area/LAT...LON line), and plain-text products (Area Forecast Discussions, Special Weather Statements). Outlook-family products (Convective/Fire Weather/ERO Outlooks) are GIS-shapefile-based, a fundamentally different generation mechanism, and stay out of scope. CAP-format synthetic products are also out of scope — the generator only ever produces plain NWWS text.

## Safety Model

Two independent gates keep a synthetic product from ever reaching a real subscriber:

1. **Environment gate**: the tool (its nav item and its Filament page/resource routes) is hidden entirely — not merely disabled — whenever `app()->environment('production')`. There is no code path by which a generated product can enter a live environment.
2. **Test-account gate**: a new `is_test_account` boolean on `users` designates one shared fixture account. When the triggering `IngestedProduct->is_test` is `true`, `MatchWeatherAlertRules` (and both of `SyncActiveWwa`'s call sites into it) scope the `AlertRule` query to that account only, instead of every active subscriber. This holds even if a staging environment gets seeded with a copy of real subscriber data — the account scoping is a second, independent gate on top of the environment gate, not a replacement for it.

Staff designate the fixture account via a toggle on the existing admin `UserResource` form, then use the existing impersonate action (`stechstudio/filament-impersonate`, already wired into `UsersTable`) to log into `/dashboard` as it and configure real alert rules and notification channels on it, exactly as a real subscriber would. No new UI is needed for that part — it's the existing subscriber-facing forms, just used by staff against the fixture account.

Every synthetic `IngestedProduct` row carries `is_test = true`. This is what both the matching-scope gate and the review-screen filter (see below) key off of.

## Data Model Changes

- **`users`**: `+ is_test_account` (boolean, default `false`).
- **`products`**:
  - `+ has_ugc` (boolean, default `false`) — true for warning-family products and UGC-bearing plain-text products (Special Weather Statements carry a UGC/zone line in real text despite having no VTEC). Backfilled in the migration for existing seeded products wherever derivable from `family` (Warning/Watch/Advisory/Statement) or a small explicit code list (e.g. SPS), rather than left for staff to re-enter manually.
  - `+ damage_tag_options` (nullable array, `TagsInput` on `ProductForm` — same pattern as the existing `risk_level_options`). E.g. Tornado Warning → `["Radar Indicated", "Radar Confirmed", "Observed"]`; Severe Thunderstorm Warning → `["Considerable", "Destructive"]`; null/empty for products with no damage tags.
- **`ingested_products`**:
  - `+ is_test` (boolean, default `false`).
  - `+ test_product_scenario_id` (nullable FK to `test_product_scenarios`, `nullOnDelete` — matches the existing `alert_template_id` convention).
- **`active_wwas`**:
  - `+ is_test` (boolean, default `false`), set by `SyncActiveWwa` whenever the triggering product's `is_test` is `true`. `ActiveWwa` is a current-snapshot table (upserted per VTEC key, overwritten on each CON/EXP/CAN), so it needs its own flag to answer "which open events are synthetic" without joining back through `ingested_products` history — that's what powers Step 1's "continue existing event" list below.
- **`test_product_scenarios`** (new table): `id`, `name` (nullable string — an optional admin-facing label), `product_id` (FK to `products`), `continues_scenario_id` (nullable, self-referencing FK, `nullOnDelete` — set when this scenario is a CON/EXP/CAN follow-up to another scenario's event), `input` (JSON — the full wizard state described below), timestamps.

Synthetic warning-family events draw their VTEC event number from a reserved high range (e.g. `9000`–`9999`) never used by real NWS sequential numbering, so a synthetic event's VTEC key can never collide with a real active one sharing the same office/phenomenon/significance/year.

`test_product_scenarios` is the reusable-scenario library described in "Repeat/Reuse" below. It stores original wizard *inputs* (e.g. the radius-in-miles used to derive a polygon box, which damage tags were checked) rather than only the parsed/derived output already captured on `IngestedProduct` — some inputs don't survive being converted into final output fields, and reusing/editing a scenario needs the inputs, not just their derived result.

## Repeat & Reuse

A saved `TestProductScenario` is a first-class, reusable, mutable definition — not just a byproduct of one generation. This is built as a proper Filament Resource (`TestProductScenarioResource`), not the single one-shot admin Page originally sketched, because a reusable scenario needs its own list/edit surface:

- **Create and Edit share the same 4-step wizard** (detailed below). Create starts blank; Edit opens pre-filled from the scenario's stored `input`.
- Both end in a **Generate** button, not a "Save" button — persisting the scenario's current field values and creating a new `IngestedProduct` from them (through the real template + parser pipeline) happen together as one action. A scenario that has never actually generated a product isn't a meaningfully complete save in this tool.
- **"Send again"**: a one-click row action on the scenarios list. Re-runs Generate against the scenario's stored input completely unchanged — no wizard walk. Covers "use an existing test product as-is."
- **"Edit & resend"**: open the scenario (its Edit page), change any field, hit Generate. This updates the scenario's stored `input` in place *and* creates a new `IngestedProduct` from the updated definition. Covers "modify one of its attributes before sending."
- **"Duplicate"**: a row action that clones a scenario into a new, unsent copy (new row, `input` copied, no linked products), for branching a variant without disturbing the original.
- **Issuance timestamp always redefaults to "now"** whenever a scenario is opened to send or resend, regardless of what's stored — a test dispatch is meant to look freshly issued each time, not replay a now-stale original timestamp. Every other field stays exactly as stored until explicitly changed.

`ingested_products.test_product_scenario_id` links every generated product back to the scenario that produced it, so a scenario's own page can show its full send history, and a generated product in the Ingested Products review screen can link back to "send again"/"edit & resend" on its originating scenario.

## Wizard Steps & Fields

The wizard has four steps. Field visibility within each step is driven by the selected product's actual traits (`family`, `has_ugc`, `has_polygon`, `damage_tag_options`), not a single coarse "shape" switch, since real product formats don't split cleanly along family lines alone (Special Weather Statements carry UGC despite no VTEC).

**Step 1 — Product & Timing**

- Radio: **New event** / **Continue existing event**. The "Continue" option only appears once at least one open (`is_test = true`, not yet expired/cancelled) row exists in `active_wwas`.
  - *New*: Product select (grouped by family; `draft`-status products are included — this is the concrete use case that status was built for), WFO, issuance timestamp (defaults to now), expiration/duration.
  - *Continue*: a searchable select of open synthetic events (e.g. "Severe Thunderstorm Warning — KTWC — #0042 — issued 12 min ago"), then a VTEC action to apply — **CON / EXP / CAN** (UPG is out of scope for v1; see below). WFO, phenomenon, significance, and event number are inherited from the selected event and not editable. Issuance timestamp still defaults to now.
  - Choosing "Continue" always creates a **new** `TestProductScenario` (its `continues_scenario_id` pointing at the scenario behind the selected event) rather than mutating the original NEW scenario — a CON/EXP/CAN follow-up is its own distinct definition (different VTEC action, likely different body text), and the original NEW scenario should stay exactly re-sendable on its own via "Send again."

**Step 2 — Geography**

- `has_ugc` → county/zone multi-select, reusing `AlertRuleForm`'s existing state-filtered UGC picker pattern (`countyZoneField`/`countyZoneStateField`), but filtered by the WFO chosen in Step 1 instead of by state: options are scoped to `public_zones`/`counties` rows whose `cwa` column matches the chosen WFO (via a `LIKE` match, since a handful of zones/counties straddle two CWAs and store both codes concatenated in that column).
- `has_polygon` → a point-on-map picker (reusing the existing `Pinpoint` component already used for Locations) plus a radius-in-miles input; the generator computes a simple box/diamond polygon around that point server-side. An "Advanced: paste raw coordinates" toggle swaps in a `lat,lon`-per-line textarea for an exact/irregular shape instead.
  - **WFO sanity check**: once a point/polygon is set, its centroid is checked against `cwa_boundaries.boundary` for the chosen WFO via `ST_Contains`. If it falls outside, the step shows a non-blocking warning (e.g. "This point falls outside KTWC's forecast area") — Generate still works, since a warning legitimately spanning multiple CWAs is a common, realistic scenario worth being able to test, not something to hard-block.
- Neither flag set (e.g. Area Forecast Discussion) → the step shows a plain note that this product is WFO-wide with no additional geography to fill in.

**Step 3 — Content**

- **Warning-shaped** (has VTEC): `hazard`, `hazard_source`, `impact`, `locations_impacted`, `call_to_action` — the exact field set `ParseNarrativeSections` already produces and Custom Alert Templates already consumes. Damage-tag checkboxes appear only when the selected product's `damage_tag_options` is non-empty. A motion vector (direction/speed, optional reference point) is always optional.
- **Discussion-shaped** (SPC MD/WPC MPD): a single freeform "Discussion text" textarea (matches how these products actually read, rather than forcing them into hazard/source/impact fields that don't fit), plus a risk-level select sourced from the product's own `risk_level_options`.
- **Plain-text-shaped** (AFD, SPS): a single freeform "Body text" textarea.

When continuing an existing event, Steps 2 and 3 prefill from the scenario behind the most recent product sent for that event (geography and content both commonly carry forward with small updates on a real CON), fully editable from there.

**Step 4 — Review & Generate**

- Read-only rendered raw text in a code block (re-renders as earlier steps change), with a copy button.
- **Generate** button: saves the scenario's `input` and creates the new `IngestedProduct`.

## Templating & Pipeline Integration

- **Three Blade templates**, one per shape, each modeled directly on a real fixture already in `database/product_examples/`: `resources/views/filament/admin/test-products/warning.blade.php` (modeled on `SVW.txt`/`TOW.txt`), `discussion.blade.php` (modeled on `SWO.txt`, a real captured SPC MCD), `plain-text.blade.php` (modeled on `AFD.txt`/`SPS.txt`). Each renders a realistic WMO header + body from the wizard's field values.
- **Shared ingestion action**: `IngestNwwsProductController`'s core logic (parse metadata, `IngestedProduct::updateOrCreate`) is extracted into a small `IngestProductText` action, called by both the real controller and the generator. This is what makes "exercises the parser identically to production" literally true — both paths run the same code, not just similarly-shaped code.
- The generator constructs `IngestedProduct`'s identifying fields directly rather than reusing `IngestedProduct::parseFilename()` (which expects a real NWWS archive filename that doesn't exist here): `cccc` derived from the chosen WFO, `awipsid`/`ttaaii` synthesized from the product's code, `wmo_ddhhmm` from the issuance timestamp, `nwws_id` a generated marker, and `file_path` a synthetic path (e.g. `test-generated/{scenario_id}/{uuid}.txt`) that can never collide with or be mistaken for a real archived product's path.
- The rendered text is run through `ExtractProductMetadata` (the `ExtractCapMetadata` path is never exercised — v1 only covers plain NWWS text). The resulting `IngestedProduct` row is created with `is_test = true` and `test_product_scenario_id` set, firing the same `IngestedProductObserver` → `SyncActiveWwa`/`MatchWeatherAlertRules` → `DeliverWeatherAlert` chain real products go through, scoped to the fixture test account per the Safety Model above.

## Ingested Products Review Screen

- New `TernaryFilter` on `is_test` on the existing `IngestedProductResource`'s table, so staff can isolate test runs from real archived products — this is the review screen the master plan describes, not a second viewer built from scratch.
- For `is_test` rows, the list/view links back to the originating `TestProductScenario` (via `test_product_scenario_id`), so reviewing a test dispatch leads straight to "Send again"/"Edit & resend" on the scenario that produced it.

## Explicitly Out of Scope for v1

- **Outlook-family products** (Convective/Fire Weather/ERO) — GIS-shapefile-based, not text; synthesizing one means building a shapefile/KML generator, a different mechanism entirely from the Blade-template approach here.
- **CAP-format synthetic products** — the generator only produces plain NWWS text.
- **VTEC action = UPG** — real UPG semantics involve a cross-reference to a separately-issued new product for the upgraded hazard type, meaningfully more complex than CON/EXP/CAN. The "continue existing event" flow supports CON/EXP/CAN only.
- **One-click fixture-file writes** — the review screen offers copyable raw text only; folding a generated product into `database/product_examples/` as a permanent regression fixture stays a manual, human-reviewed step via a normal commit. The app never writes into its own source tree at runtime.
