# App icon badge for unread push notifications — Design

Source: owner conversation, 2026-10-19.

## Intent and why

A subscriber with the app closed should see, on the app icon, how many alert pushes have arrived since they last looked. Today the push payload carries no badge, so the icon never changes and nothing tells the subscriber how many alerts are waiting. Opening the app is the signal that they have seen them: the badge disappears.

The Firebase plugin already installed in the mobile app can clear the badge (`PushNotifications::clearBadge()`: on iOS it sets the icon badge to zero; on Android it cancels the notifications showing in the tray, which removes the launcher dot, because Android manages its own dots). iOS takes the number from the push payload (`aps.badge`). So the work is a per-phone running count on the server, the number in the iOS payload, and the app clearing the badge and resetting that count.

## Goals

- Each alert push that reaches a phone while the app is not open adds one to that phone's iOS icon badge; the number shown is the count of pushes not yet seen.
- Opening the app clears the badge on that phone and resets that phone's count on the server, so the next push starts again from 1.
- A push that arrives while the app is open does not count: the app clears the badge itself.
- Each phone counts on its own; a subscriber with two phones gets two independent badges.

## Non-goals

- No numbers on Android. The OS shows its dot (or a launcher-specific count) based on the notifications in the tray; the server sends nothing extra for Android, and the app only clears the tray on open.
- No per-category or per-rule counts; no "unread" list or read/unread state for alerts inside the app.
- No badge changes from the mobile rule editor, the web dashboard, or non-push channels.
- No server-side tracking of whether the app is open or closed.
- Not in this design: the foreground event from the app-lifecycle plugin (blocked on its NativePHP v4 support; see `mobile-alerts-foreground-refresh`). When it works, it replaces the repeating check below.

## Anti-goals

- The badge must never get stuck: a subscriber who opens the app must not be left with a stale number, and a number must never keep growing across launches because a reset was lost.
- No call to the server on every tick of the repeating check; it calls only when there is something to clear.
- The reset endpoint must not let one phone (or one subscriber) change another phone's count.
- A failed or slow badge/reset call must never break the Alerts screen or an incoming push.

## Constraints

- Delivery is the in-house FCM sender (`App\Support\Delivery\FcmClient`, called from `MobilePushTransport`). The iOS payload is currently `apns.payload.aps = ['sound' => 'default']`; `aps.badge` is added there. FCM data values must be strings; `aps.badge` is an integer inside the `apns` block.
- One `mobile_push` `NotificationChannel` exists per signed-in phone, tied to the Sanctum token (`personal_access_token_id`). The push token API is `PUT/DELETE /api/v1/push-token`, authenticated by the app token; the phone's channel is found from the current access token.
- In the NativePHP v4 runtime a plugin event such as `PushNotificationReceived` reaches only the mounted screen's `#[Native\Mobile\Attributes\On(...)]` handlers. NativePHP gives PHP no foreground event, and a screen's `#[Poll]` is the available way to run code periodically while the app is on screen.
- On Android, `clearBadge()` cancels all displayed notifications, including a banner that has just appeared for a push received in the foreground. That is acceptable (the subscriber is already in the app) but must be checked on a device.
- Pushes expire after one hour (existing ruling); the count is of pushes sent, not delivered: FCM does not report delivery to the sender, so an expired or dropped push still counts. The reset on open corrects any drift.
- Rebuilding the mobile app is required only if native code changes; this design uses existing plugin functions, so a normal app build picks up the PHP changes. Never run `native:run`/`native:jump`/`native:watch`; ask the owner which platform.

## What was decided (with the owner)

- Opening the app clears the badge (not "viewing the Alerts screen").
- A push that arrives while the app is open does not count; the app handles this itself by clearing when such a push lands (no server tracking of open/closed).
- Returns from the background are handled with a short repeating check on the Alerts screen, planned now and to be replaced by the app-lifecycle foreground event when that plugin supports v4.

## The design

**Server**
- Each `mobile_push` channel carries an unread count (a small integer; where it lives is left to the builder, with the constraint that it is not inside the encrypted `config` blob that holds the FCM token and that incrementing is race-safe when several alerts push to one phone at once).
- `MobilePushTransport` increments the count for the phone it is sending to, then passes the new total to `FcmClient::send`, which puts it in `apns.payload.aps.badge`. Android's payload does not change. The increment happens for every push the transport sends, including the "Send test notification" push (the owner's guess: tests count like any other).
- A new authenticated app-token endpoint (route name and verb left to the builder, e.g. `POST /api/v1/push-token/badge/reset`) sets the current phone's count to zero. It finds the phone's channel from the current access token, as `PushTokenController` does; it is a no-op (204) when the phone has no channel; it never accepts a channel id or user id from the client. It follows the API conventions in `.ai/rules/api.md`.

**Mobile app**
- A small service (next to `PushRegistration`) wraps "clear the badge": it calls `PushNotifications::clearBadge()` and the reset endpoint, swallowing and logging failures (no exception reaches a screen).
- On mount of the Alerts screen (cold start and after the sign-in flow reaches Alerts), it clears the badge.
- The Alerts screen has a short `#[Poll]` (the owner's guess: about every 5 seconds). On each tick it clears the badge only when there is something to clear. How the app knows (a remembered "badge may be showing" flag set when a push is received or the count is non-zero, or an unconditional cheap local clear with the server call throttled) is left to the builder, within the anti-goal above.
- The Alerts screen handles `PushNotificationReceived` by clearing the badge and resetting the server count (a push in the foreground does not count).
- When the user signs out or turns push off, nothing extra is needed: the channel and its count are removed with the channel.

## Left to the builder

The column or table that holds the count and its migration; how the increment and the "total to send" stay consistent under concurrent sends; the endpoint's route name, verb and response; the exact poll interval and the "something to clear" mechanism; how the mounted-screen event handler is shared with the existing `HandlesPushToken` trait; tests (backend: payload carries `aps.badge` with the running total, a second push is 2, reset returns to 0, reset is limited to the caller's phone, Android payload unchanged; mobile: screen clears on mount, on the push event, and on poll only when needed, failures never throw) and the per-platform device check; the rule notes.

## Device checks owed (cannot be done in tests)

- iOS (physical device; simulators cannot take real pushes): two pushes with the app closed show 2; opening clears; a push while the app is open leaves no badge; a resume from the background clears within the poll interval.
- Android: a push while the app is closed shows the OS dot/notification; opening the app removes it; a foreground push does not leave a stale tray notification.

## My calls (for the owner to check)

- The "Send test notification" push counts toward the badge.
- The poll interval of about 5 seconds.
- Android sends no badge field; only the tray is cleared on open.
- The count is incremented before the push is sent and never rolled back: a push that fails, or goes to a token that is then pruned, still counts. The reset on open corrects any drift, and the phone's channel is removed with an invalid token anyway.
- A phone keeps one channel per app token (`personal_access_token_id`), so refreshing the FCM token keeps the same channel and count; a fresh sign-in is a new token and a new channel that starts at 0.
- The clear logic lives in a shared trait used by every top-level tab screen (Alerts, Map, Products), not only Alerts, so opening the app on any tab clears the badge. The repeating check runs on whichever of those screens is showing.
- A failed reset is retried: the app keeps a local "reset pending" flag and retries on the next tick until the server call succeeds; the local clear (`clearBadge()`) still happens at once.
- On Android the clear (cancelling tray notifications) also runs on the repeating check while the flag says a badge may be showing. If the device check shows that a foreground push's banner disappears too abruptly, the fallback is to skip the tray clear for a push received in the foreground and leave the OS to manage it; that decision is made after the device check.
- `PushNotificationReceived` is treated as foreground-only, as the plugin documents; the device check confirms a push delivered while the app is closed does not fire it on resume.
