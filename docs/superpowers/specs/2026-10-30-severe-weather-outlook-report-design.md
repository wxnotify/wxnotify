# Severe Weather Outlook Report: design

Date: 2026-10-30. Status: draft for the owner's review.

## Intent

Subscribers want a glance at the week's severe weather chances without opening SPC's site. Once a day at a time
the subscriber chooses, default 14:30 UTC (late enough to include the 1300 UTC Day 1 update) WxNotify sends a report built from the SPC
Convective Outlooks: Days 1, 2, 3 and 4-8, across the US and for the subscriber's chosen saved locations. It is
worth opening when severe weather is forecast; a subscriber who does not want quiet days can choose to receive it
only when something is forecast.

It is a new option under SPC & WPC Alerts: "Severe Weather Outlook Report".

## Goals

- One daily report per rule per channel, at a time the subscriber chooses (default 14:30 UTC).
- Two lengths, as other rules have: Brief (the mobile channel) and Full (every other channel).
- Each rule chooses: channels, send time (UTC), which saved locations to include (zero or more), and "every day" or "only when
  something is forecast".
- Built from SPC's published outlook files, fetched at send time.

## Non-goals

- No ingestion of new bulletins (`ptsdy2`, `ptsdy3`, `ptsd48`, probability products). The existing pipeline is not
  touched.
- No per-location send trigger: "only when something is forecast" looks at the whole US (decided).
- No Day 4-8 hail, wind or tornado detail (SPC does not issue it).
- No adjustable threshold for "something is forecast" in this version.

## Anti-goals (a failure even if it technically works)

- A noisy report: sending on quiet days to someone who chose "only when something is forecast".
- A report that silently omits Day 1 because SPC's 1300 UTC update was late.
- A long email when the subscriber chose many locations (the per-location part must stay compact).
- Any secret, user location or user data sent to SPC. Fetches are plain GETs of public files.

## What the subscriber gets

### Color scale (circle emoji, used in both lengths)

No storms ⚪ (white), General thunderstorms 🟤 (brown), Marginal 🟢 (green), Slight 🟡 (yellow),
Enhanced 🟠 (orange), Moderate 🔴 (red), High 🟣 (magenta; the owner's word is magenta, the circle emoji is purple).

Days 4-8 have no categories, only a 15% or 30% "any severe" area. My call: 15% shows 🟡, 30% shows 🟠, none ⚪.
Per-day circles for D4 to D8 appear in the Full subject; the Brief shows one circle for the worst of D4-D8.

### Brief

For the mobile channel. Max category of the US for Day 1, Day 2, Day 3 and Days 4-8 as circles, e.g.
`D1 🟠  D2 🟡  D3 🟤  D4-8 🟡`. Tapping the notification opens the app on the Alerts page (the existing
no-product alert behaviour: a `batch_key` groups the sends, and the notification carries no product link).

### Full

For every other channel (email, and any long-form channel). Subject, e.g.
`Severe Wx Report - 🟠 D1 | 🟡 D2 | 🟤 D3 | ⚪ D4 | 🟡 D5 | 🟠 D6 | ⚪ D7 | ⚪ D8`. Body:

- For each of Days 1, 2, 3: the SPC outlook graphic, the max category, and the max hail, wind and tornado
  probability levels (Day 3 has only an "any severe" probability, so it shows that); and, for each chosen
  location, the category at that location (and its hail/wind/tornado levels where SPC issues them).
- For Days 4-8: the day-by-day line; any day with a 15% or higher area is highlighted, with whether any chosen
  location is inside that area. A Days 4-8 overview graphic.
- When Day 1 is Moderate or High and a PWO (SPC Public Severe Weather Outlook) is active: a PWO section. My call:
  the text comes from the PWO bulletin the pipeline already ingests; "active" is the newest PWO issued on or after
  the current Day 1 outlook's issue day. If none, the section is left out.
- The existing mail layout, inline images and plain-text alternative follow `DailySummaryMail` and its
  presenter.

A location's risk is "the highest category/level whose polygon contains the point" (point-in-polygon on SPC's
GeoJSON).

## Data source (decided: fetch SPC's files at send time)

Public files under `https://www.spc.noaa.gov/products/`, all verified reachable 2026-10-30:

- Day 1: `outlook/day1otlk_cat.nolyr.geojson`, `_torn`, `_hail`, `_wind` (same suffix pattern). Day 2: the same
  with `day2otlk_`. Day 3: `outlook/day3otlk_cat.nolyr.geojson` and `day3otlk_prob.nolyr.geojson`.
- Days 4-8: `exper/day4-8/day{4..8}prob.nolyr.geojson` (the `outlook/` folder does not have them).
- Features carry `LABEL` (`TSTM`, `MRGL`, `SLGT`, `ENH`, `MDT`, `HIGH` for categories; a decimal like `0.05`,
  `0.15`, `0.30` for probabilities), `VALID_ISO`, `EXPIRE_ISO`, `ISSUE_ISO` and `FORECASTER`. The hail file
  showed `0.05`; tornado showed `Less Than 2% All Areas`, which means none.
- Graphics: `outlook/day1otlk.png` (full size, ~380 KB) and `outlook/day{1,2,3}otlk_sm.png` (~28 KB);
  `exper/day4-8/day48prob_small.gif` (overview) and `exper/day4-8/day{4..8}prob.gif`. The builder confirms which
  size is used in email, and whether to embed or link.

An empty or "no areas" file is a valid answer ("no storms"). The files change when SPC issues an update; the CDN
caches for about 2 minutes.

### Late or unavailable files

The report is always sent. From the chosen send time, each 15-minute scheduler tick retries while Day 1 is not
current (see Freshness below) or a fetch fails; after 60 minutes it sends with what it has and marks the missing
part ("Day 1 outlook not yet available"). A day that cannot be fetched at all is shown as missing, never as "no
storms". Owner decision: a missing Day 1 never stops the report, including for "only when something is
forecast": that setting then sends, because a missing Day 1 is not known to be quiet. The 60 minute grace is my
call.

## Rule behaviour

- A new SPC & WPC option, not a product-triggered rule: it fires on the clock, like Daily Summary and Threshold
  rules, not on an incoming bulletin. It reuses `alerts:dispatch-scheduled`'s once-a-day stamp
  (`last_scheduled_run_on`), but the send time and the day boundary are UTC, not the rule's own timezone, and it
  needs no `location_id` (it has a set of chosen locations, possibly empty).
- Send time: chosen in UTC (a time field labelled UTC, default 14:30), 15-minute granularity because the scheduler
  ticks every 15 minutes. My call: UTC, not local time, so the time does not drift with daylight saving; the form
  shows the local equivalent as a hint. A rule edited to a time still ahead today runs again today, as
  `AlertRule`'s existing `delivery_time` change handling does.
- Per rule: `message_body_style` (Brief/Full), send time, "send" mode (`every_day` or `only_when_forecast`), and the chosen
  locations.
- "Only when something is forecast": any Marginal or higher category on Days 1-3, or a 15% or higher area on
  Days 4-8, anywhere in the US. Checked after the files are fetched; a quiet day is a final answer for the day.
- Delivery uses `DeliverScheduledAlert` on the `deliveries` queue, one job per channel, one shared `batch_key`,
  through `ChannelDispatcher::send()` like every other send (tier and unconfirmed-channel refusals apply
  unchanged). The report is built once per rule, not per channel.
- The fetches and parsing run in the scheduler command (as the Daily Summary report's NWS calls do), so there
  must be a time and request budget; the 15-minute tick must not hang on SPC. The builder sets timeouts.

## Constraints

- Follow `.ai/rules` (actions, delivery, jobs, pipeline, models, filament, pages, feature).
- Pest feature tests, fakes for HTTP (`Http::fake`), no real network in tests.
- No new composer dependencies without approval (point-in-polygon, if not already available, is written
  in-project; the app already does point-in-polygon for rule matching, so reuse it).
- Tier/plan: no new gate. My call: the same limits as other rules and channels (channel `min_tier`, the
  rule-count limits already enforced).
- The mobile app (separate repo `wxnotify-mobile`) needs no change if the existing no-product alert rendering is
  enough for a Brief message; the builder confirms.

## Left to the builder

- Schema: where the "send" mode and chosen locations are stored (new columns or a pivot), the rule-form UI,
  the API resource/request for the mobile app's rule screens, and whether the category enum gains a case or the
  product-triggered SPC rule gains a type.
- The report payload shape and presenter, the email view, and the Brief/Full text.
- Timeouts, retries and caching of the fetched files; the choice of graphic sizes.
- Exact "active PWO" query.
- Test strategy for the send-time window and the 60 minute grace.

## Calls made after the builder check (mine, to be reviewed)

- **Which channels get Full.** Same rule the Daily Summary uses: only a channel that supports rich content (email)
  gets the Full report with graphics; every other channel gets the Brief text, as do SMS, voice and mobile.
  So "Full" in practice means the rich email. A plain-text Full for chat channels is a later option.
- **Freshness.** Only Day 1 is waited for (the 1300 UTC update is what the 14:30 default exists for). Days 2, 3 and
  4-8 are accepted as fetched. Day 1 is "current" when its features' `ISSUE_ISO` is from the latest SPC Day 1 cycle that was due at
  least 15 minutes before the send time (the 1300 UTC cycle for the 14:30 default; an earlier cycle for an earlier
  send time) or later; when Day 1 has no features (a valid quiet day), the HTTP `Last-Modified` header of the file is the
  freshness signal, falling back to the companion hail/wind/tornado files' `ISSUE_ISO`. If no signal can be read,
  treat the file as current, so a quiet day never retries for an hour.
- **Brief ignores the chosen locations** (it shows only the US maximum per period).
- **Scheduling.** A separate branch/command beside `alerts:dispatch-scheduled`'s existing query, which is not
  changed; it shares only the `last_scheduled_run_on` once-a-day stamp. Per-channel failures stay per job.
- **Location tests.** In-project PHP point-in-polygon on the fetched GeoJSON: handles MultiPolygon and holes, a
  point on an edge counts as inside. The PWO text is shown from the stored product text; the builder finds its
  product code in the catalog (`PWO`).

## Open questions for the owner

None outstanding.
