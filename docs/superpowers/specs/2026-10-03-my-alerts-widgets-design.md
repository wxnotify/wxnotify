# My Alerts Page Widgets — Design

Source: owner request — add widgets above the table on the My Alerts page that are useful to subscribers. After brainstorming, the owner chose three: **Needs attention** (with four specific checks), **At-a-glance stats**, and **Active now at your locations**. "No channels" and "no locations" checks were dropped because the subscriber UI can no longer produce those states (the alert form requires them, and the location and channel delete guards, commits `44d5b2f` and `319a7d2`, prevent removing them from under an alert).

## Goal

Three widgets in the header of the My Alerts list page, in this order: a stats strip across the top, then **Needs attention** and **Active now at your locations** side by side. Together they answer: *are my alerts working, what has been happening, and is anything dangerous going on that my alerts did or didn't tell me about?* Every number and row is scoped to the signed-in subscriber; every tier sees all three.

## Decisions (user-approved scope; the definitions below are my defaults — see "Judgment calls")

### 1. Needs attention
A list of things that stop or may stop an alert from doing its job, each row with a one-line explanation and a link to the fix. Four checks (the owner's choice):

| Check | A row appears when | Severity | Link goes to |
| --- | --- | --- | --- |
| **Channel failing** | The channel's most recent delivery attempt in the last **7 days** (a `delivery_logs` row with status `success` or `failure`) is a `failure`. Row text: "*My Discord* — last delivery failed 3 hours ago (4 failures in 7 days)". It clears itself after the next success. | danger | the Notification Channels page |
| **Channel not verified** | A channel attached to at least one of the subscriber's alerts has `verified_at` null (it cannot deliver until confirmed). Row text: "*Work email* isn't verified yet — alerts using it can't deliver" naming up to 3 affected alerts. | warning | the Notification Channels page |
| **Mobile location has no position** | An **active** Daily Summary, Threshold or Lightning alert whose single location is a mobile location with no fix yet (`! hasFix()`). Row text: "*Morning summary* is waiting for *Truck* to report a position". | warning | that location's edit page |
| **Alert is inactive** | An alert with `is_active = false`. Row text: "*Storm warnings* is turned off". | gray | that alert's edit page |

- A channel appears at most once: **failing** wins over **not verified**. An inactive alert appears only as "inactive" (the position and channel-verification checks consider **active** alerts only, so one alert does not produce several rows).
- Ordering: failing channels, unverified channels, positionless mobile locations, inactive alerts; within a group by name. Show up to **8** rows, then "and N more".
- Empty state: a calm "All your alerts look healthy." line with a check icon, so a subscriber learns what the widget is for.
- "Recent" = a rolling 7-day window (no day bucketing, so no timezone dependence).
- Failure text never repeats `error_detail` or any channel destination: only the channel label, a relative time and a count.

### 2. At-a-glance stats
A Filament stats row (four stats), computed from the subscriber's own data:

1. **Active alerts** — "3 of 5" (active count, total as the description).
2. **Deliveries, last 7 days** — successful `delivery_logs` rows (one row per channel, so it is labelled "deliveries", not "alerts sent"); description compares with the previous 7 days ("12 more than last week" / "same as last week"); a 7-point sparkline of daily successes in the subscriber's timezone (reuse `GetAlertDeliveryStats::perDay`).
3. **Failed deliveries, last 7 days** — `status = 'failure'`; colored danger when above 0, success when 0.
4. **Held for quiet hours, last 7 days** — `status = 'suppressed'`, muted color, description "Not sent because of quiet hours" (suppressed alerts are not re-sent later, so the wording must not suggest they will be).
- Rolling 7-day windows for counts (`dispatched_at >= now() - 7 days`); the sparkline uses the same chart-day bucketing as the dashboard charts.
- **Not reactive to the table's search/filters** in this version (see Non-goals).

### 3. Active now at your locations
The warnings, watches, advisories and statements currently in effect at any of the subscriber's locations that have a position (stationary, plus mobile with a fix), **grouped by event**, each with whether the subscriber's alerts told them:

- Row: event name (with a severity-coloured badge from the product family), the location names it affects, "until 4:15 PM" (in the first affected location's timezone, as the existing active-alerts action already does), and a **status**:
  - **Alerted** — "Alerted via *Storm warnings* · 2:14 PM" (a `success` delivery to this subscriber for any issuance of this warning).
  - **Held** — "Held during quiet hours" (only `suppressed` rows).
  - **Failed** — "Delivery failed" (only `failure` rows).
  - **No alert sent** — shown in an attention colour: "None of your alerts matched this warning".
- **Coverage is evidence-based, not predicted.** It is computed from the subscriber's `delivery_logs` rows whose `ingested_product_id` belongs to the same VTEC event (the five `vtec_*` columns that `ActiveWwa::vtecKeyFor()` and `IngestedProduct` share, non-test only), so it reuses the real matching result instead of duplicating `MatchWeatherAlertRules`. Consequence, stated in the UI hint: a warning issued before the subscriber created the alert shows "No alert sent" even though updates to it may be delivered. Precedence when an event has several rows: Alerted over Held over Failed over None.
- Sorted by family rank (warning, watch, advisory, statement, other), then expiry soonest first; show up to **6** events, then "and N more".
- Empty states: no locations → "Add a location to see active weather alerts here."; locations but nothing active → "No active weather alerts at your locations."
- The widget refreshes every **60 seconds** (the other two widgets only on page load / table interaction). The result is cached for 60 seconds per subscriber so several open tabs share one lookup.
- Reads only our own `active_wwas` / `delivery_logs`; no external HTTP call.

## Implementation notes

- **Placement**: three Filament `Widget` classes under `app/Filament/Dashboard/Resources/AlertRules/Widgets/` (`AlertStatsWidget` extending `StatsOverviewWidget`, `NeedsAttentionWidget`, `ActiveNowWidget`), returned from `AlertRuleResource::getWidgets()` and `ListAlertRules::getHeaderWidgets()` in the order above; the header grid is 2 columns: stats `columnSpan = 'full'`, the other two `1` each. `canView()` mirrors `AlertRuleResource::canAccess()`, but the protection that matters is that every query uses `auth()->user()` (the resource gate allows everyone, as `.ai/rules/filament.md` records for the maps).
- **Data actions** (database only, easy to test, no Filament in them): `App\Actions\Alerts\GetAlertsNeedingAttention`, `GetAlertStats`, `GetActiveNowAtMyLocations`, each taking the `User`.
  - `GetAlertsNeedingAttention` returns an ordered list of `{kind, severity, text parts, url}` rows. URLs are built by the widget (resource `getUrl()`), not by the action.
  - `GetActiveNowAtMyLocations` needs the underlying `ActiveWwa` rows, not the mapped arrays: extract the query in `GetActiveAlertsForLocation::__invoke()` into a public `activeFor(Location): Collection<ActiveWwa>` and have `__invoke()` map it exactly as today (its existing output and tests must not change).
- **Eager loading and queries**: each widget must run a bounded number of queries independent of the number of alerts/channels/locations (except `ActiveNow`, which makes one active-warnings query per location with a position — cached 60 s per subscriber, and capped at the subscriber's first 25 such locations; the cap is noted in the widget footnote when hit).
- **Escaping/security**: alert, channel and location names are subscriber-supplied; render them through Blade escaping (never `{!! !!}`). The failure rows expose no destination, URL, token or `error_detail`.
- **Staleness caveat**: delivery counts and coverage come from `delivery_logs`, which has no pruning; windows are bounded so the existing `(user_id, status, dispatched_at)` index serves them.

## Non-goals

- Reacting to the alerts table's search/filters (a possible later addition via Filament's table-exposing widgets).
- Per-alert drill-down, a delivery history page, or showing failure messages.
- "No channels" / "no locations" checks (no longer reachable from the UI), and flagging weather-alert rules whose *listed* locations lack a position (only the single-location scheduled alerts are covered).
- Predicting whether a current warning *would* match an alert (coverage is only what was actually delivered).
- Scheduled-delivery times ("next summary at 7:00 AM") and the other brainstormed ideas (coverage by location, noisy/silent alerts, suggestions).
- Admin-panel versions.

## Testing

Pest feature tests (container run; Livewire for the widgets), clock pinned with `Carbon::setTestNow`:

- `GetAlertsNeedingAttention`:
  - failing channel: last attempt failed within 7 days → row; success after the failure → no row; failure older than 7 days → no row; counts and "last failed" time correct.
  - unverified channel attached to an alert → row naming up to 3 alerts; an unverified channel attached to nothing → no row.
  - failing wins over unverified (one row per channel).
  - mobile location without a fix on an active Daily Summary / Threshold / Lightning alert → row; with a fix → none; on an inactive alert → only the "inactive" row.
  - inactive alert → row; ordering and the 8-row cap with "and N more"; empty when all healthy.
  - another subscriber's data never appears; row text exposes no destination or `error_detail`.
- `GetAlertStats`: active-of-total; deliveries / failures / quiet-hours counts over 7 days with boundaries just inside and outside; previous-7-day comparison; sparkline length 7 and timezone bucketing (reuse the dashboard fixtures); only the subscriber's rows.
- `GetActiveNowAtMyLocations`: groups one event across two locations; Alerted / Held / Failed / No alert sent and the precedence rule; evidence only from the subscriber's own delivery logs; warnings for test products (`is_test`) excluded; locations without a fix skipped; sort order and the 6-event cap; the 60-second cache; the 25-location cap; empty states.
- `GetActiveAlertsForLocation` still returns exactly what it did (existing tests untouched and passing) after extracting `activeFor()`.
- Widgets: each renders on `ListAlertRules` (`assertSeeLivewire`) for a subscriber with data and with none; `canView()`; names containing HTML are escaped in the rendered output; query count does not grow with the number of rows (compare 1 vs many).
- Browser check (controller, after execution): a throwaway subscriber with a failing channel, an unverified channel, an active scheduled alert on a mobile location with no position, an inactive alert, seeded delivery logs across 14 days, and active warnings at two locations (one alerted, one not): confirm the stats strip and sparkline, the Needs attention rows and links, the Active now statuses and the 60-second refresh, empty states, and layout at 1280px and phone width. Delete the throwaway data afterwards.

## Judgment calls (flagged for the owner's review)

The brainstorm fixed the widgets and the four Needs-attention checks; these details are my defaults and easy to change:
1. **"Recent" = 7 days**, and "failing" means the channel's *latest* attempt failed (it clears after a success) rather than "any failure in 7 days".
2. **Stats are not reactive to the table's filters** in this version.
3. **Coverage in "Active now" is evidence-based** (what was actually delivered), so a warning issued before an alert existed shows "No alert sent"; the alternative (predicting matches) would duplicate the matching rules and can be wrong.
4. **Layout**: stats full width on top, Needs attention and Active now side by side; Active now refreshes every 60 s, the others on load.
5. **Row/event caps** (8 / 6 / 25 locations) keep the page light for large accounts.

## Rules to record

Add a short "My Alerts widgets" note to `.ai/rules/filament.md`: these widgets read only the database and scope every query to `auth()->user()` (the resource's `canView()` allows everyone); coverage comes from `delivery_logs` evidence, never from re-implementing the matching rules; names are rendered escaped; and `GetActiveAlertsForLocation::activeFor()` is the shared source for active warnings.

## Revisions after the final review

The shipped code differs from the sections above in these ways:

- **Refresh event (point 4 of the judgment calls changes)**: the stats and Needs attention widgets (and, harmlessly, Active now) re-render on a `my-alerts-changed` Livewire event, which the table's Active toggle, row delete and bulk delete dispatch, so they no longer go stale after an inline change. The stats widget is no longer lazy (no empty placeholder flash).
- **Needs attention wording**: delivery does not check `verified_at`, so the copy no longer claims an unverified channel "can't deliver". It reads "isn't verified yet — confirm it so alerts using it are sure to reach you (A, B, C and N more)"; a failing channel reads "its last delivery failed 3 hours ago (4 failures in 7 days)". Failing-channel rows sort by label without regard to case. Whether delivery should require verification is an open owner decision.
- **Active now wording**: "Not sent (quiet hours)" and "No alert sent for this warning"; the footnote says an earlier-issued warning may show "No alert sent" even though updates to it may reach the subscriber.
- **Dates in times**: a time that is not on the current calendar day (judged in the location's timezone) shows the weekday, e.g. "Sat 4:15 PM".
- **Empty state**: the action result carries `has_position`; when locations exist but none has a position the widget says "None of your locations has a position yet, so there's nothing to check." instead of "No active weather alerts at your locations."
- **Failed stat**: always shows a description and icon (a danger warning triangle above zero, a success check at zero) alongside its color.
- **Queries**: the coverage lookup is bounded to deliveries from one day before the event's first ingest and to the success/suppressed/failure statuses; the stats aggregate filters the same three statuses; Active now fetches only locations with a position in SQL.
- **Read path writes**: `ResolveLocationZones` saves quietly, so rendering Active now never trips maintenance mode or writes an activity row.
