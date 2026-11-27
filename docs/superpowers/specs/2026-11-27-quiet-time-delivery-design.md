# Quiet-time alerts reach the phone quietly

## Why

A weather alert matched during a rule's quiet time is recorded as `suppressed` and nothing is sent. The app's Inbox, unread count and iPhone icon badge only know about successful phone pushes, so the subscriber never learns of the alert in the app. Quiet time should mean "do not disturb me", not "do not tell me". After quiet time, an iPhone should show a higher badge number, and both platforms should show the alert in the app.

## Decisions (agreed in conversation, 2026-11-27)

- Android: finding the alerts on opening the app is enough. **No push is sent to Android.**
- iPhone: the icon shows the unread number, including anything already unread. A push carrying **only the badge number** does this.
- **Verified on the owner's iPhone** (2026-11-27, through the current Firebase setup, `apns-push-type: alert`, priority 10, no title, text, sound or data): the badge changed correctly with the app in the background, open, and force-closed, and the owner received no sound or notification.

## Goals

- A quiet-time weather alert appears in the app's Inbox as unread and counts in the unread number on both platforms.
- On iPhone the icon badge updates during quiet time with no banner, sound, vibration or Lock Screen entry.
- Works with the app builds already installed.

## Non-goals

An Android icon dot or notification, data-only (background) messages, any app change, quiet time for scheduled or lightning alerts (they have none), changing email, SMS, web push or any other channel, a setting to turn this off, and refreshing the Inbox when the app returns to the foreground (a separate, already known gap: an app left on the Inbox shows the new alert after a pull to refresh or a tab change).

## Anti-goals (a failure even if it technically works)

- A quiet-time alert makes a sound, vibrates, shows a banner or appears on the Lock Screen.
- Alerts held **before** this change suddenly appear as unread in anyone's Inbox.
- Quiet-time alerts are counted or labelled as delivered in statistics, delivery logs or "Active now".
- A failed badge update removes the alert from the Inbox, is reported as a delivery failure, or affects any other delivery.
- Anything here delays or changes a normal alert push.

## Constraints

- Backend only (`wxnotify-nextgen`). The app already renders a `suppressed` phone channel on an alert's detail as "Held back".
- Quiet time is decided in `MatchWeatherAlertRules`, which runs during ingestion, so it must not make a network call there.
- "In the app's feed" is defined today as a successful `mobile_push` row (`DeliveryLog::scopePushedToPhone()`, `ListAppAlerts::HAS_PUSH`, the partial index `delivery_logs_unread_push_index`, and the in-memory `isPushedToPhone()`). All four must agree.
- Follow `.ai/rules` (alerts, delivery, pipeline, models), including that sends to a subscriber's channel go through the delivery gates.
- Four-server deploys migrate on server A before the workers on C and D run the new code.

## Approaches considered

- **A. Server records the alert for the app and sends iPhones a badge-only push (chosen).** iOS applies the number itself; Android needs nothing; installed apps work unchanged.
- **B. Data-only message handled by app code.** Apple does not guarantee delivery, advises two or three an hour at most, and drops it after a force-quit; the Firebase plugin also reports completion to iOS before its PHP work runs (read in the code, untested). It needs new app code and would not add an Android badge.
- **C. Record only, no push.** Enough for Android, but the iPhone badge would not change until the app is opened.

## Design

### Recording

A quiet-time `mobile_push` row **stays `suppressed`** and is additionally marked as shown in the app (a new column, false for every existing row and every non-phone row). "In the app's feed" becomes: a `mobile_push` row that is successful, or is marked. Everything that reads `status` (statistics' "held", delivery logs, "Active now", failure and attention lists, charts) keeps working unchanged. Unread, mark-read, grouping and the tap-less feed entry follow the existing rules, so a quiet update to an alert already read makes it unread again, and several updates to one rule and product count once.

A row is marked only if the phone could have received a push right then: the same refusals as a real send (account being deleted, plan does not include the channel, unverified channel, no push token). Otherwise it stays held exactly as today.

### The badge push

For a marked row on an iPhone, a queued job sends a push containing only `aps.badge`: the subscriber's unread count at send time (which already includes this alert). No `notification`, no `data`, no sound, nothing for Android. It uses `apns-push-type: alert`, priority 10 (as tested), a fixed `apns-collapse-id` so only the newest pending number is delivered, and an expiry of **24 hours** (normal alerts keep one hour) so a phone offline overnight can still get it. One attempt.

A failure changes nothing about the row or the Inbox: only a fixed message or the exception class is logged. A token Firebase reports as dead is pruned the same way as for a normal push.

### Wording

The dashboard's explanation for a held phone alert says it was saved to the app's Inbox without a notification, instead of "It is not sent later" alone. Held rows on other channels keep today's wording.

## Testing

Pest: a quiet-time match marks only the phone row, and only when the phone could receive; the feed, detail, unread count, read, read-all and badge count include it; unmarked (old) held rows stay out; statistics, "Active now" and delivery logs still count it as held; the FCM request for an iPhone has only the badge, the headers and expiry above; Android and other channels send nothing; a failed or refused badge push leaves the entry in place and records no failure; a dead token is pruned; no network call happens during matching; normal push requests are unchanged. On a real iPhone and Android phone after deploy: one quiet-time alert.

## Left to the builder

The column's name, the job and method names and where they live, how the feed definition is shared between SQL and PHP, rebuilding the partial index without blocking writes, and the exact explanation sentence.

## Calls made without being asked (open to change)

- Only alerts from after the update appear; no backfill.
- A phone that could not be sent to does not get the Inbox entry either.
- 24-hour expiry for the badge push.
- The app keeps showing "Held back" on the alert's detail screen; the Inbox list gets no special mark.
- No setting: every phone channel behaves this way.
