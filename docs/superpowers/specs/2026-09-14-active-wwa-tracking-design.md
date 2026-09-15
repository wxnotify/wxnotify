# CAP Ingestion & Active WWA Tracking — Design

## Context

The Next-Gen master plan (`wxnotify-nextgen-plan.html`) was updated to bring Common Alerting Protocol (CAP) parsing into Phase 0/1 as the primary structured source for VTEC-class Watches, Warnings, and Advisories. NWS already broadcasts a CAP XML alert on NWWS alongside the plain-text product for every VTEC-class W/W/A — this isn't a future dependency to build toward, it's already on the wire today.

This originated from a brainstorm about the master plan's "Custom alert templates" feature, which depends on a "common-structure parser" the plan describes as already producing a per-product field schema (hazard/source/impact/affected-locations/expiration/motion-vector). Investigating what that parser would need surfaced that CAP's real, documented, versioned XML elements are a substantially better source for the *structured* fields (polygon, timing, zones, event type, severity/urgency/certainty) than continuing to scrape them from fixed-column text — and that adopting CAP safely requires a data-model change (Active WWA tracking) that doesn't exist in this codebase yet. That data-model change is what this document specs. **The narrative-text section-splitter (hazard/source/impact bullets) that Custom Alert Templates ultimately needs is explicitly out of scope here** — see "Out of scope" below.

## Real CAP payload structure (verified against live NWWS traffic)

Confirmed by inspecting a real `nwws-go-client` debug log capturing actual NWWS-OI XMPP traffic, not from documentation alone. Two real examples: a cancelled Severe Thunderstorm Warning (`SVSOAX`) and a live Flash Flood Warning (`FFWTWC`).

**Envelope** — CAP arrives as its own NWWS-OI stanza, with the *same* attribute shape as any text product:
```xml
<x xmlns="nwws-oi" id="nwws_processor.9609" issue="2026-09-14T23:54:00Z"
   ttaaii="XOUS53" cccc="KWBC" awipsid="CAPOAX">
```
`cccc` is always `KWBC` (the national telecommunications gateway), **not** the originating WFO — the WFO identity lives in `awipsid` (`CAP` + 3-letter office id) and in the CAP body itself. `awipsid` always starts with `CAP` for these products — a cheap, reliable detection signal before any XML parsing.

**CAP body** (`urn:oasis:names:tc:emergency:cap:1.2`):
```xml
<alert>
  <identifier>urn:oid:2.49.0.1.840.0...</identifier>
  <sender>w-nws.webmaster@noaa.gov</sender>
  <sent>2026-09-14T18:54:00-05:00</sent>
  <msgType>Cancel</msgType>              <!-- Alert | Update | Cancel -->
  <references>...</references>            <!-- OID chain to prior CAP message(s) this supersedes -->
  <info>
    <event>Severe Thunderstorm Warning</event>
    <urgency>Past</urgency>
    <severity>Minor</severity>
    <certainty>Observed</certainty>
    <effective>...</effective><expires>...</expires>
    <headline>The Severe Thunderstorm Warning has been cancelled.</headline>
    <description>...</description>        <!-- narrative body; still contains raw HAZARD/SOURCE/IMPACT
                                                 bullets as plain text for a substantive alert, or a short
                                                 synthesized sentence for a Cancel -->
    <instruction>...</instruction>         <!-- precautionary/call-to-action text, a SEPARATE element from
                                                 description -- this is a real structural win over raw text,
                                                 which only delimits this by convention -->
    <parameter><valueName>AWIPSidentifier</valueName><value>SVSOAX</value></parameter>
    <parameter><valueName>WMOidentifier</valueName><value>WWUS53 KOAX 142354</value></parameter>
    <parameter><valueName>NWSheadline</valueName><value>...</value></parameter>
    <parameter><valueName>eventMotionDescription</valueName>
      <value>2026-09-14T23:53:00-00:00...storm...231DEG...34KT...41.31,-96.18</value></parameter>
    <parameter><valueName>VTEC</valueName>
      <value>/O.CAN.KOAX.SV.W.0413.000000T0000Z-260915T0015Z/</value></parameter>
    <area>
      <areaDesc>Douglas, NE</areaDesc>
      <polygon>41.39,-96.02 41.19,-96.3 ... 41.39,-96.02</polygon>
      <geocode><valueName>UGC</valueName><value>NEC055</value></geocode>
    </area>
  </info>
</alert>
```

Key findings that drove the design below:
- The VTEC string is embedded verbatim as a `<parameter>` — the exact same format a text product's VTEC line uses, so one shared parser extracts it from either source.
- `AWIPSidentifier` + `WMOidentifier` parameters are an exact, deterministic cross-reference to the specific paired text product's own `awipsid`/`cccc`/`ttaaii`/`issue` — not a fuzzy match.
- `<description>` does **not** give hazard/source/impact as separate fields — for a substantive alert it's the same bullet-formatted narrative prose as the raw text bulletin, just wrapped in XML. For a `Cancel`-type message it's a short synthesized sentence with no bullets at all. CAP does not eliminate the narrative-bullet-splitting problem; it does cleanly separate `instruction` from `description`, which raw text does not.
- `eventMotionDescription` gives a semi-structured motion-vector string, only present on products that have one (matches the plan's Heads Up feature needing exactly this).

## Scope

**In scope:**
- Detecting and parsing CAP payloads on the existing PHP ingestion path.
- Persisting CAP's structured fields on `ingested_products`.
- A new `active_wwas` table — one row per logical VTEC event, not per raw issuance — that both the text and CAP parsers upsert into.
- Redirecting match-and-dispatch triggering, for VTEC-class products only, off `IngestedProduct::created()` and onto `active_wwas`'s own upsert logic, with correctness-preserving de-duplication between a text issuance and its paired CAP payload.
- Teaching `DeliverWeatherAlert` to fall back to CAP's `description` as message body content on the rare path where CAP dispatches without a paired text product.

**Explicitly out of scope:**
- Any change to `nwws-go-client` (the Go NWWS client). Confirmed directly against its source: there is no product-type filtering anywhere in its pipeline (`product.go`/`store.go`/`client.go`) — it already writes every message it receives to disk and unconditionally fires the PAN webhook (`pan-notify.sh` → `IngestNwwsProductController`) regardless of content. CAP messages already flow through it end-to-end today with zero code changes.
- The narrative-text section-splitter (hazard/source/impact/call-to-action extraction from `description`/raw text) that Custom Alert Templates needs. CAP gives us everything else that feature's "common-structure parser" needs except this one piece — it's a separate, future brainstorm.
- Non-VTEC-class products (Area Forecast Discussions, SPC/WPC Discussion/Outlook products). The plan is explicit these don't have a CAP equivalent (nothing in NWS's CAP-transition proposal addresses them), and they keep triggering `MatchWeatherAlertRules` directly off `IngestedProduct::created()`, completely unchanged.
- Threshold Alerts, Daily Summary, and Lightning Alerts delivery — none of these read from `IngestedProduct` at all.
- Parsing a motion vector from raw text. Text-only issuances (before CAP arrives, or in CAP's absence) simply have no motion vector, same as today.
- Any admin/Filament UI over `active_wwas`. A natural future consumer (Admin Tools' Insight/Visibility tools, the Active alerts widget, the Public API), not built here.
- The exact SQL/query shape of the CAP→text correlation lookup — left to implementation.

## Architecture

### CAP detection

`IngestNwwsProductController` checks `str_starts_with($parsed['awipsid'], 'CAP')` before choosing which metadata extractor to run. This is an envelope-level check, cheap and made before any body parsing.

### Shared VTEC-string parsing

The VTEC string format (`/k.aaa.cccc.pp.s.####.YYMMDDTHHNNZ-YYMMDDTHHNNZ/`) is identical wherever it's found — a regex match against raw text, or the value of CAP's `<parameter valueName="VTEC">`. A single parsing routine extracts office/action/phenomenon/significance/event-number/begin/end from a VTEC string; `ExtractProductMetadata` (text) and the new `ExtractCapMetadata` (CAP) each locate that string their own way, then call the same routine.

### `ingested_products` — new columns

Populated for any VTEC-class row (text or CAP), shared:
| Column | Notes |
|---|---|
| `vtec_office` | e.g. `KOAX` — from the VTEC string, not `cccc` (unreliable for CAP rows, which are always `KWBC`) |
| `vtec_phenomenon` | e.g. `TO` |
| `vtec_significance` | e.g. `W` |
| `vtec_event_number` | e.g. `0055`, stored as a zero-padded string |
| `vtec_year` | the event-tracking year (VTEC event numbers reset annually per office/phenomenon); needed alongside the other components to build the same composite key `active_wwas` uses |
| `vtec_action` | `NEW`/`CON`/`EXT`/`EXP`/`CAN`/`COR`/... — this specific issuance's action code |
| `vtec_begin` / `vtec_end` | this issuance's validity window; an all-zero begin (`000000T0000Z`, meaning "already in effect") is stored as null |

CAP-only (populated by `ExtractCapMetadata`, null on text rows):
| Column | Notes |
|---|---|
| `format` | `text` or `cap` |
| `msg_type` | CAP's `<msgType>` — `Alert`/`Update`/`Cancel`/etc. |
| `event`, `severity`, `urgency`, `certainty` | CAP's `<info>` fields, verbatim |
| `headline` | CAP's `<headline>` |
| `description` | CAP's `<description>` — used as the delivery-body fallback (see below) |
| `instruction` | CAP's `<instruction>` |
| `awips_identifier`, `wmo_identifier` | raw values of the `AWIPSidentifier`/`WMOidentifier` parameters — used to correlate to the paired text row |

### `active_wwas` — new table

One row per logical event (persistent VTEC key), not per raw issuance:

| Column | Notes |
|---|---|
| `product_id` | FK, resolved phenomenon+significance |
| `vtec_office`, `vtec_phenomenon`, `vtec_significance`, `vtec_event_number`, `vtec_year` | composite unique key — the actual event identity |
| `status` | `Active` / `Cancelled` / `Expired`, derived from the latest applied action + whether `expires` has passed |
| `polygon` | `geometry('POLYGON', 4269)` — same SRID `MatchWeatherAlertRules::matchesPolygon()` already assumes and the same column type `public_zones`/`counties`/etc. already use |
| `ugc_codes` | json — current affected zones |
| `effective`, `expires` | current validity window |
| `headline` | latest known headline |
| `motion_direction_degrees`, `motion_speed_kt`, `motion_reference_lat`, `motion_reference_lon`, `motion_observed_at` | parsed from CAP's `eventMotionDescription`; null until CAP enriches the row (no text-based motion parsing — decided explicitly) |
| `source` | `text` or `cap` — which extraction currently backs the structured fields, for debugging |
| `latest_ingested_product_id` | FK — the text row whose content is the deliverable content for this event; CAP enrichment never changes this pointer |

No history/pivot table between `active_wwas` and `ingested_products`. The full issuance chain for an event is already reconstructable by querying `ingested_products` on the same VTEC-key columns, ordered by `received_at` — a join table would just be a second, driftable copy of that ordering.

### Dispatch-trigger redesign

`IngestedProductObserver::created()` branches on whether a VTEC string resolved at all (regardless of source):

- **Not VTEC-class** (AFD, SPC/WPC) → call `MatchWeatherAlertRules` directly, exactly as today. No change.
- **VTEC-class** → call a new `SyncActiveWwa` action instead.

`SyncActiveWwa`, given the just-created `IngestedProduct`:

- **Text row** → `updateOrCreate` the `active_wwas` row by VTEC key. Always update UGC/status/timing/headline from this issuance. Update `polygon` **only if this row's own `polygon` is non-null** — a continuation statement (SVS/FFS/etc.) typically doesn't repeat `LAT...LON`, and must not clobber a previously-known-good polygon with nothing. Always call `MatchWeatherAlertRules` against this row — this is what preserves today's behavior of dispatching on every text issuance including continuations (confirmed: `SVS`/`FFS`-family "statement" products resolve to the *same* `product_id` as their parent warning via the VTEC-regex path, so today's system already treats every continuation as its own dispatch-worthy event; this design must not regress that).
- **CAP row** → look up the paired text row via `awips_identifier`/`wmo_identifier` against `ingested_products`' own envelope columns.
  - Found (the normal case — text arrives first, CAP a few seconds later) → enrich `active_wwas`'s `polygon`/`ugc_codes`/`motion_*`/`headline`/timing from CAP's data, set `source = 'cap'`. **No re-dispatch** — the paired text row already triggered one moments earlier.
  - Not found (text lost or arrived late — rare) → CAP dispatches on its own, since otherwise this issuance would never notify anyone. `latest_ingested_product_id` points at the CAP row itself in this case.

### Delivery fallback for a CAP-only dispatch

`DeliverWeatherAlert::handle()` picks its body source based on which kind of row it was handed:

```php
$body = $product->format === 'cap' ? $product->description : $product->text;
// existing truncation logic (message_body_style Brief/Full) applies to $body as before
```

Title generation is unaffected — `RenderAlertSubject` never reads `text`/`description`, only `$rule` and `$product->product` (the catalog `Product`, resolved identically regardless of source), so no change is needed there.

### Matching-accuracy side effect

Once `active_wwas.polygon` persists across an event's continuations, `MatchWeatherAlertRules`'s Polygon-mode matching should consult it rather than only the triggering row's own (possibly-null-on-continuation) `polygon`. This fixes a latent gap in the current system: today, `matchesPolygon()` falls back to UGC-zone matching whenever the triggering row has no polygon of its own, which is every SVS-style continuation — meaning Polygon-mode subscribers are currently evaluated by zone, not polygon, on every continuation of an active warning. Persisting the polygon on `active_wwas` and having matching read from there fixes this as a natural consequence of the design, not a separate effort.

## Testing

Per the master plan's own Testing strategy — this pipeline is explicitly called out as the plan's single highest life-safety-risk area — the eventual implementation plan needs fixture-based regression coverage for at least:
- A non-VTEC product (AFD) still dispatches directly off `IngestedProduct::created()`, unchanged.
- A text-only VTEC issuance (no CAP) still dispatches, matching today's behavior exactly.
- The normal case: text dispatches, CAP arrives moments later and enriches `active_wwas` without a second dispatch.
- A continuation (SVS) for an already-active event still dispatches on its own, and does not have its `active_wwas.polygon` cleared by its own lack of a polygon.
- CAP arriving with no matching paired text row dispatches on its own, using `description` as the delivery body.
- Polygon-mode matching against a continuation correctly uses `active_wwas`'s persisted polygon rather than falling back to zone matching.
