# Read and unread alerts in the mobile app — Design

Source: owner conversation, 2026-10-20.

## Intent and why

The mobile app's Alerts screen lists every alert sent to the subscriber on any channel (push, email, Discord, ...), and the app icon badge counts pushes since the app was last opened. Neither says which alerts the subscriber has actually looked at. The owner wants real read/unread state: the Alerts screen shows what is unread, tapping an alert reads it, and the icon badge is the number of unread alerts. Because other channels (email, Discord, ...) each handle "read" differently and the owner does not want to track them, the Alerts screen narrows to alerts that were pushed to a phone, which are the only ones the app can know the subscriber has seen.

## Goals

- The Alerts screen lists only alerts that were pushed to one of the subscriber's phones and were delivered (a failed push does not count). Email, Discord and other channels no longer appear there; the "Sent via ..." line goes away.
- Each listed alert shows whether it is read or unread: a dot and a bolder title for unread (the owner approved "read vs unread distinguishable"; the exact styling is the builder's).
- Tapping an alert to open it marks it read. A **Mark all as read** action marks every unread alert read.
- Read state is shared across all of the subscriber's phones: reading on one phone marks it read everywhere, and every phone shows the same unread count.
- The iOS icon badge is the number of unread alerts: each push carries the current unread count (including the alert being pushed), and the app sets the icon number to the new count whenever it changes the read state. Android keeps its own OS-managed dot.
- Existing alerts at rollout start as read.

## Non-goals

- No read/unread tracking for email, Discord or any non-push channel, and no web dashboard read/unread (the dashboard does not mark anything read and its alert views are unchanged).
- No per-device read state, no "mark as unread", no per-alert swipe actions, no sync of Android tray notifications with read state (the tray is left to the OS).
- No change to the Current watches, warnings and advisories panel.
- No new push types: the pushes that exist today (alerts, daily summaries, tests, etc.) keep being sent as they are; only the badge number in the iOS payload changes meaning.

## Anti-goals

- A subscriber must never see an alert both read and unread depending on the phone, or a badge that disagrees with the list for more than a moment.
- A failed or offline "mark read" must never lose the subscriber's intent silently or block opening the alert: opening an alert always works; the read state catches up.
- Rollout must not show a huge unread backlog or a huge badge.
- Retiring the old badge machinery must not leave dead code, routes or columns behind, and must not break the push payload or the app between the backend and app deploys.

## What was decided (with the owner)

- "Read" means tapping the alert open (not scrolling past it).
- The badge corresponds to unread pushed alerts only; the feed narrows to pushed alerts to match.
- Read state is shared across phones; the feed is "pushed to any of the subscriber's phones" (not tied to one phone's channel, which is recreated at every sign-in).
- A small custom NativePHP plugin sets the iOS badge number (the installed firebase and local-notifications plugins can only clear it).
- The old badge machinery is retired (see below), which also changes the earlier rule "opening the app clears the badge": the badge now stays until alerts are read.

## Design

**Unit and state.** An alert is the existing app alert (`App\Support\AppAlert`: all delivery rows of one subscriber for one rule and product; a row without a product stands alone). Read state is stored on the delivery rows that were mobile pushes (`channel_type = 'mobile_push'`, `status = 'success'`): a nullable `read_at` timestamp. An alert is **in the app feed** when it has at least one such row. It is **unread** when any of its push rows has `read_at` null, so a new push in an alert's group after it was read makes it unread again. Marking an alert read sets `read_at` on all its push rows; marking all read does it for every unread push row of the subscriber. The unread count is the number of unread alerts (groups), not rows. A backfill migration sets `read_at` on every existing push row.

**API (app token, scoped to the user).** `GET /api/v1/alerts` returns only in-feed alerts, each with its `read` state; the response also carries the subscriber's unread count (meta). `GET /api/v1/alerts/{id}` for an alert not in the feed is a 404 as for any alert that is not the user's. New endpoints (names left to the builder): mark one alert read, mark all read; both idempotent, return the new unread count, never accept another user's alert, answer the same for already-read alerts. Resources never expose channel names for the feed beyond what the app needs (the "Sent via" text is no longer shown).

**Push badge.** `MobilePushTransport` stops using the per-phone counter: for iOS channels it sends `aps.badge` = the subscriber's unread alert count at send time, which includes the alert being pushed (compute after the delivery row exists or add one: the builder decides the ordering, noting that `DeliverWeatherAlert` writes its log row around the send). Android's payload keeps no badge.

**The app.** The Alerts screen shows the dot/bold treatment, the unread count, a **Mark all as read** action (a toolbar or header action: the builder's choice, sized for a thumb), and opening an alert marks it read optimistically (local state first, server call best-effort with a retry so a failed call is not lost). After any read-state change the app sets the icon badge to the unread count through the new plugin. Local cache of the feed (`CachedAlert`) carries the read flag so the list is right offline.

**The plugin.** A small custom NativePHP plugin (created with `native:plugin:create`, registered with `native:plugin:register`) exposing `BadgeCount::set(int $count)`: iOS sets `UIApplication.shared.applicationIconBadgeNumber`; Android is a no-op that returns success. It never throws into a screen. A native rebuild is required; tell the owner which platform to build for and never run `native:run`.

**Retired.** The per-phone unread push counter (`notification_channels.unread_push_count` and `NotificationChannel` methods), the badge-reset endpoint `POST /api/v1/push-token/badge/clear`, `ForegroundPushReceived` (event, listener, the `event`/`payload` FCM data keys, the cache flag) and the 5-second clearing check and badge service in the mobile app (`ClearsPushBadge`, `PushBadge`, the `PushState` flags and their tests and rule notes). `clearBadge()` stays only where it is still meaningful: sign-out (the badge goes with the session). The retirement is part of this project, split into its own task so the app and backend can be deployed in a safe order (see Constraints).

## Settled after the builder check (my calls, for the owner to check)

- **Identity and ordering stay as today:** the feed is the existing grouped alert list (same group key, same stable id = lowest row id of the group, same cursor) filtered to groups that contain at least one successful `mobile_push` row. The mark-read endpoint and `GET /alerts/{id}` resolve through the group (an id whose group has no successful push row is a 404). Detail and display ignore non-push rows and failed rows: the app never shows a "failed" marker or other channels for a feed alert (a failed push beside a delivered one shows as an ordinary alert).
- **Which pushes are feed alerts:** anything that writes a `mobile_push` success delivery row is an alert in the feed and counts toward unread: weather alerts, scheduled summaries and the like. The "Send test notification" push writes no delivery row, so it never appears in the feed and does not count; its iOS badge number is the plain current unread count (not +1).
- **Exact enough, self-correcting:** the badge number in a push is the unread count including the alert being pushed (current unread groups, plus one when the pushed alert's group is currently read or new). A race between two near-simultaneous pushes may be off by one briefly; the app corrects the icon from the `unread_count` in the feed response on every feed load and after every read change, so no lock is required.
- **Marking read is by group at server time:** it sets `read_at` on every successful push row in the alert's group that exists when the request is handled (including rows that arrived between the list and the tap). Any new successful push in a group after it was read makes the whole alert unread, even from a different phone.
- **Backfill:** a batched `UPDATE` by id ranges (no single table-locking statement), no column default (rows created between the migration and the code deploy start unread, which is acceptable for a few minutes). No delivery-log pruning exists today, so none is handled.
- **Transition:** the old reset endpoint `POST /api/v1/push-token/badge/clear` stays as a 204 no-op for one release, and the `event`/`payload` FCM data keys stay harmless (an old app build still understands them); both are removed in a later release.

## Constraints

- Deploy order: backend first (`read_at` column and backfill, new endpoints, new payload number), then the app; an old app build must keep working against the new backend (it ignores the new fields, calls the old reset endpoint, which must not 404 until the retirement ships in a later release). The builder decides whether the old endpoint stays for one release or answers 204 as a no-op.
- The feed query must stay as cheap as today's (grouped, cursor-paged, per-user); unread counting must be indexed (a `read_at`/`channel_type` partial or composite index on `delivery_logs` as measured).
- The old machinery's rule notes in `.ai/rules/delivery.md` and `api.md` must be rewritten, not just removed.
- Mobile: NativePHP v4 conventions (EDGE markup, theme tokens, typed icons, no emoji), `native:validate` passes, tests with `Native::test`, never run `native:run`/`native:jump`/`native:watch`.
- The delivery-log retention/pruning (if any) must be checked: a pruned row must not resurrect or break unread counts.

## Left to the builder

Column and index details, the exact endpoint names and response shapes, how unread is computed in SQL, how the push count is computed relative to the delivery row's creation, the visual treatment of read vs unread and the Mark all as read control, the plugin's name and structure, how the app retries failed read calls, the cache shape for the offline feed, the retirement sequencing, tests, and the on-device checks.

## Device checks owed

- iOS (physical): two pushes show badge 1 then 2; opening one alert leaves 1; Mark all as read clears the icon; a push after reading one more shows the right number; reading on a second phone updates the first phone's list and (on its next sync or push) its badge.
- Android: the OS dot behaves as before; the list shows read state; Mark all as read works.
- Offline: opening an alert offline marks it read locally and the server catches up on reconnect.
- Rollout: after the backend deploy, existing alerts all show read and the badge number on the next push is 1.

## My calls (for the owner to check)

- A new push to an already-read alert makes it unread again.
- The Android tray is not synced with read state.
- Existing alerts start as read.
- The old badge machinery is fully retired, and opening the app no longer clears the badge.
