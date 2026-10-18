# Mobile Current Alerts (a "Current watches, warnings and advisories" section on Alerts) — Design

Source: owner request, 2026-10-12. On the app's Alerts screen, above the alerts that were sent, a section listing the watches, warnings and advisories currently in effect at the subscriber's places.

## Goal

A subscriber opening the app sees at once what is in effect where they care about: any of their saved places and their phone's position, whether or not one of their alert rules told them. Each event names the places it affects and when it ends, and opens its product page.

## Decisions (user-approved, from brainstorming)

- **Places: all of the subscriber's locations with a position, the phone included** (up to 25), the same coverage as the dashboard's "Active now at my locations". Rejected: only the phone's position (empty when sharing is off); saved places only.
- **Events: warnings, watches and advisories only,** ordered warnings first, then watches, then advisories, then by soonest end. Statements, SPC/WPC outlooks and informational products are left out.
- **Each row shows** the event name, which of the subscriber's places it affects, and when it ends. Not shown: whether the subscriber's own alerts told them (the dashboard's "alerted / held / failed / none"); that costs a delivery-log lookup per event and was not asked for.
- **Tapping a row opens the event's product page** (the latest bulletin's public View Product page) in the in-app browser, as an alert row does. A row with no page is not tappable. Rejected: showing the event on the Map (the Map cannot be told where to centre); display only.
- **Length: the first five, then "Show all (N)";** the section says "Nothing in effect at your places." when there is nothing, rather than disappearing.
- **Offline: the last copy, marked with when it was fetched,** with events whose end has passed dropped. Rejected: hiding the section offline (the alerts below already work offline).
- **A new endpoint, not part of the alerts feed.** `GET /api/v1/active-alerts` is separate from the cursor-paged `GET /alerts`, so paging and the section cannot interfere. Rejected: adding it to the alerts response (it would ride on every page); reusing `GetActiveNowAtMyLocations` as it is (it caps at 6, includes statements and does the alerted-status lookup).

## Backend

One read-only endpoint in the existing `/api/v1` app-token group (`auth:sanctum`, `ability:app`, `ExtendAppTokenExpiry`, `throttle:app-api`), returning an Eloquent API Resource, scoped to the token's user. The action is `GetActiveAlertsForUser` (`app/Actions/Alerts/`).

### `GET /api/v1/active-alerts`

No parameters. The user's locations that have a position (`lat` and `lng`), ordered by id, the first 25. For each, `GetActiveAlertsForLocation::activeFor()` gives the events in effect there (never an event's own `ugc_codes` or polygon: `.ai/rules/pipeline.md`). An event found at several places is returned once, with those places combined.

```
data: [ { id, event, family, places: [label…], expires, view_url } ],
meta: { has_places, generated_at }
```

- `id` is the active event's id. `event` is `eventName()`. `family` is `eventFamily()`, one of `warning`, `watch`, `advisory`: other families are excluded. Test products (`is_test`) are excluded.
- `places` is the labels of the affected places, sorted, without duplicates.
- `expires` is the latest end of the zone rows that cover the affected places (null if any has none), as an ISO 8601 string. It is the same value `GetActiveAlertsForLocation` computes for each place; across places the latest wins, none if any is none.
- `view_url` is the page of the newest bulletin that last wrote a zone row covering one of the affected places (via `active_wwa_areas.latest_segment_id`), else the event's latest ingested product's `viewUrl()` (null when it has none). Amended 2026-10-18: an event issued as many bulletins can have its latest bulletin cancel zones elsewhere while the subscriber's own zones are still in effect (seen on the dev data: a Wind Advisory at Destin whose latest bulletin cancelled Alabama zones).
- Order: family rank (warning, watch, advisory), then `expires` ascending (none last), then id. There is no server-side cap on the list.
- `meta.has_places` is false when the user has no location with a position (the app then asks for a place or location sharing instead of "Nothing in effect"). `meta.generated_at` is when the response was built.
- Cached 60 seconds per user, as `GetActiveNowAtMyLocations` is. No zone codes, polygons, bulletin text, coordinates or other users' places leave the action.

## App (`mobile/`)

`AlertsScreen`'s list gets the section as its first rows; the existing alert rows follow under their own header.

### Screen and behaviour

- **Headers:** a header row "Current watches, warnings and advisories", then the events, then a header row "Alerts sent to you" above the existing alert rows (native `list-section` headers: the list is `plain`, so the headers are flat and sticky).
- **Event rows:** small text above (`"Warning · Ends in 3 hours"`, `"Watch · No end time given"`), the event name as the headline, the affected places below (`"Home, Cabin"`). A row with a `view_url` opens it with `Browser::inApp()`; a row without one has no tap.
- **Show all:** the first five rows, then a row "Show all (N)" when there are more; tapping it reveals the rest and the row becomes "Show fewer". The expanded state lasts for the visit.
- **Empty states:** no events and places exist: "Nothing in effect at your places."; `has_places` false: "Add a place or turn on location sharing to see what's in effect near you."
- **Loading:** fetched when the screen opens (after painting the cached copy), on pull-to-refresh, and on the stale refresh when the screen is resumed from a pushed screen, always independently of the alerts feed: a failure of one does not affect the other. A section failure other than offline shows "Couldn't load current alerts." in quiet text in place of the rows; the feed's own error message is unchanged.
- **Offline:** the last saved copy with the line "As of 2:14 PM. You're offline." (the fetch time in the subscriber's timezone when the profile has one), and events whose `expires` has passed are dropped. With no saved copy: "Current alerts need a connection."
- **Cache:** `LocalCache` keeps the last response (the events and `generated_at`), replaced on every successful fetch and wiped at sign-out with everything else. The 5/Show-all state is not cached.

### Safety

Event names and place labels are shown as native text. The section says "in effect" only for what the server returned in the last fetch; offline copies are always labelled with when they were fetched, and an event whose end has passed is never shown.

## Testing

- **Backend** (`GetActiveAlertsForUser` and the endpoint): events at the phone's position and at saved places, each event once with the sorted labels of every place it affects; the latest end across places, and none when any has none; expired, test and cancelled events excluded; statements, SPC/WPC and informational excluded; ordering (family, then end, then id); a user with places and nothing in effect gets an empty list and `has_places` true; no located place gives `has_places` false; only the first 25 located places are used; another user's places never appear; no polygons, zone codes or bulletin text in the payload; `view_url` is the latest bulletin's page or null; 401 without a token and 403 for a beacon token; the per-user cache.
- **App:** the section's rows and both headers; the first five and Show all / Show fewer; the empty and no-places states; a row opening its page and a row without a link; offline with and without a saved copy (including dropping an ended event and the "As of" line); a failed section load leaving the feed working and the reverse; the stale refresh; the sign-out wipe; `native:validate` clean; a render check for the new elements.
- **Device:** the Android emulator and the iOS simulator against the dev server: the section above the list, a row opening its page, Show all, pull-to-refresh, offline.

## Rules to record

In `.ai/rules/api.md`: `GET /active-alerts` returns public event data only, scoped to the caller's own places, never polygons or zone codes, and looks at the first 25 located places with a 60-second cache. In `.ai/rules/actions.md`: `GetActiveAlertsForUser` reads events through `GetActiveAlertsForLocation::activeFor()`, never an event's own `ugc_codes` or polygon.

## Out of scope

Whether the subscriber's alerts told them about an event; statements, outlooks and informational products; showing an event on the Map; a notification when a new event starts; colour-coded rows; the section on other tabs; a nationwide list; filtering the section; an app badge.

## Risks to settle in the plan

- **Cost:** `activeFor()` runs a spatial query per place (up to 25). The plan's first task measures it on the dev data and keeps, or adjusts, the cache and cap.
- **Stale phone position:** the phone's last fix may be old; rows say which places an event affects, not how old a position is. Left as it is unless it proves misleading on a device.
- **Native list sections:** the headers are the list's own `list-section` headers (resolved while planning; the first idea, fake header rows, is not needed). The risk left is whether pull-to-refresh and loading older alerts still behave inside a sectioned `plain` list; the device check decides, and the fallback is header-looking list items.
- **Many events:** a large outbreak could make "Show all" dozens of rows; rows are light and the list is native, so this is expected to be fine.
