# Severe Weather Outlook report in the mobile app — design

## Why

A subscriber with a mobile channel on a Severe Weather Outlook rule gets a push whose only content is the subject, a line of risk circles ("Severe Wx Outlook - 🟠 D1 | 🟡 D2 | 🟢 D3 | ⚪ D4 …"). The same rule sent to an email channel carries the whole report: a section per day with SPC's map, the hail/wind/tornado maximums, the issued time and a row per chosen place, then a Days 4-8 section. The owner wants a subscriber to read that same report in the app, opened from the report's row in the Inbox.

Today the report cannot be opened in the app because nothing keeps it: `DeliverScheduledAlert` hands the structured payload (`BuildOutlookReport`) and the SPC graphics to rich-content channels only (email), the mobile channel gets the brief text, and the app's alert detail is built from the `delivery_logs` row (subject, category, product link), which has no body for this category.

## What the owner decided

- **Where:** from the report's Inbox row, open a full report screen. The push itself does not change.
- **Content:** the same information as the email (their sample PDF): for each of Days 1-3 the heading with its circle and category, the date, SPC's map, the maximum hail/wind/tornado levels (Day 3: any severe), the issued time, and one row per chosen place with that place's own category and levels; then Days 4-8 with one map and a row per day; then the Public Severe Weather Outlook text when the report carries one.
- **Maps:** show them as they were when the report was sent (a copy is kept), not whatever SPC shows now. Copies are kept for 30 days.

## My calls (not yet confirmed by the owner beyond the playback they approved)

- **Same words as the email.** The app gets the report already turned into display strings by `OutlookReportPresenter::present()`, the presenter the email views use, so "not available", "None of your locations inside", "Below 15%" and the date lines cannot drift between email and app. The app does no weather logic of its own.
- **After 30 days the text stays, the maps go.** The saved report data lives as long as its delivery row; only the map copies are deleted, and the report then shows a short "Maps are no longer stored" line where each map was.
- **Maps are served through signed links.** The app's image element loads a plain URL and cannot send the login token, so the detail response carries short-lived signed URLs to the stored copies (the backend serves the bytes from private storage). A cached detail opened offline may show no maps; the text still shows.
- **The Brief setting is respected.** A rule whose Message length is Brief still sends only the short text everywhere (the existing rule in `.ai/rules/app-jobs.md`), so a Brief rule's phone entry has no report. A Full (or unset) rule keeps the report for the phone in addition to the email, and a rule with only a mobile channel now keeps it for the phone.
- **Nothing else changes:** the push text, the email, the rule editor, the dispatch schedule and the SPC fetching stay as they are.

## More calls, settled after the builder check (mine, not the owner's)

- **A run is one rule and one owner.** `DispatchOutlookReports` makes one `batch_key` per rule per run and queues one job per channel of that rule, so every channel of a run shares the rule's Message length (Brief or Full). There is no case of one run being Brief for one phone and Full for another, and a stored report never holds another subscriber's places. The report is stored once per run, only when the rule is not Brief and the run has a mobile channel.
- **The raw payload is stored, not display strings.** The stored plain-data payload (`BuildOutlookReport`) keeps the facts of the run fixed (categories, levels, places, issue times, map copies); the presenter turns it into wording on every read, so a later wording fix reaches old reports but never changes what was forecast.
- **The Inbox row says whether a report exists.** The alert list and detail carry `has_report` (true when a stored report exists for the alert); the app opens the report screen from a row only when it is true and otherwise opens the plain detail as today. Whether the maps have expired is worked out on read from the run's age, not set by the clean-up, so a missed clean-up never shows an expired map as live.
- **Delivery rows are not pruned today** (no schedule or command prunes `delivery_logs`; the builder re-checks this), so the report text lives as long as the row. If row pruning is ever added it must take the stored report with it.
- **Cached maps:** the app hides an image that fails to load and shows the text; when online it re-requests the detail, which re-issues fresh signed links.

## Goals

1. A subscriber opens an outlook report from the Inbox and reads everything the email shows, on iOS and Android.
2. The report is the one that was sent: its numbers and its maps match, even days later.
3. The email, the push and the dispatch path behave exactly as before.

## Non-goals and anti-goals

- No live or refreshed report, no re-fetch of SPC from the app.
- No new alert category, no change to who gets the report (paid-tier owners only, as today).
- No maps kept past 30 days; no map storage for rules that send Brief.
- Must not make an outlook run slower for email subscribers or fail it when a map cannot be stored (a failed copy leaves that day without a map, never an undelivered report).

## Setup and constraints

- Map copies live on the private disk, never on a public URL. The 30-day clean-up is a scheduled command like the app's other clean-ups.
- The API follows `.ai/rules/api.md` and `v1.md` (versioned `/api/v1`, Eloquent API Resources, no secrets or error detail in responses). The app is a separate repo (`wxnotify-mobile`); rollout is backend first, then the app, and an app talking to an older backend (no report in the detail) falls back to today's detail screen.
- The report payload is plain data (`BuildOutlookReport`'s own rule: no models or Carbon in it), so it can be stored as JSON on or beside the delivery row.

## Left to the builder

Where the report and the map copies are stored (a JSON column on `delivery_logs`, or a table keyed by `batch_key`; one copy per run, not per channel), the signed-URL route and its lifetime, the name and schedule of the 30-day clean-up, the shape of the `outlook_report` block in the alert detail response (it must carry the presenter's `days`, `extended`, `pwo` plus map URLs and a `maps_expired` flag), the app's screen structure and component names (built from the existing alert detail and Home card patterns; remote images already work through `<native:image>` on both platforms), and the tests.

## Edge cases the design must cover

- A day or a single hazard level that SPC could not supply reads "not available", never "none" (the presenter already does this; the app must not hide it).
- A report with no chosen places shows each day's overall levels and no place rows.
- A map that could not be fetched at send time: that day shows no map and no broken image.
- A map older than 30 days, or an alert whose copies were already cleaned: text only, with the "no longer stored" line.
- A report queued before this change (no stored payload): the alert opens the plain detail as today.
- A subscriber opening someone else's alert id or a map link: 404 / invalid signature, like every other app alert.
- Several phones on one rule: the run is one alert with one stored report (the feed already groups by `batch_key`).
- Time: the issued times are UTC strings; the day dates come from the payload's `report_date` in the owner's timezone, as in the email.
