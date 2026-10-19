# App Icon Badge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the number of alert pushes a subscriber has not yet seen on the iOS app icon, and clear it (and the Android tray) when the app is opened.

**Architecture:** Each `mobile_push` channel keeps an unread-push count. `MobilePushTransport` increments it per push to an iOS phone and `FcmClient` puts the new total in `apns.payload.aps.badge`. A new app-token endpoint resets the calling phone's count. In the mobile app a shared trait on every tab screen clears the badge (`PushNotifications::clearBadge()` plus the reset call) on mount, when a push arrives in the foreground, and on a 5-second `#[Poll]` that detects a return from the background.

**Tech Stack:** Laravel 12, Pest, Postgres; NativePHP v4 mobile app (`mobile/`, `nativephp/mobile-firebase` 1.1.1 with `clearBadge()`).

**Spec:** `docs/superpowers/specs/2026-10-19-app-icon-badge-design.md`

## Global Constraints

- FCM data values are strings; `aps.badge` is an integer inside `message.apns.payload.aps` next to `sound`. The Android payload does not change.
- One `mobile_push` channel per app token (`personal_access_token_id`); the platform is `config['platform']` (`ios` or `android`); `config` is encrypted and holds the FCM token, so the count lives in its own column on `notification_channels`, never inside `config`.
- Increment the count atomically in one SQL statement that returns the new total (several alerts can push to one phone at once). Use the query builder, not `$channel->save()`, so no activity-log row is written for a counter change.
- A push that fails still counts (no rollback). Only iOS channels increment (Android has no badge number); a reset on an Android channel is a no-op that still answers 204.
- The reset endpoint takes no id: it resets the channel of `$request->user()->currentAccessToken()`; 204 even when the phone has no channel; behind `auth:sanctum` + `ability:app` like `/push-token`; never returns or logs a token.
- The app never lets a badge/reset failure reach a screen: swallow and log (class name only, no token). A failed server reset sets a local "reset pending" flag in `PushState` and is retried on the next tick; the local `clearBadge()` always runs.
- Mobile: partial views cannot use `$this`; plugin events reach only the mounted screen's `#[On(...)]` handlers; never run `native:run`/`native:jump`/`native:watch`.
- Before editing read `.ai/rules/index.md` and the matching rule files (`app/Support/Delivery/**` → delivery.md; `routes/api.php`, `app/Http/Controllers/Api/**` → api.md; `app/Models/**` → models.md; `tests/Feature/**` → feature.md) in the repo root and in `mobile/`. Backend tests run in the container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact <paths>'`; mobile tests: `cd mobile && vendor/bin/pest --compact`, plus `php artisan native:validate` there for the last task. Pint on touched PHP files (`vendor/bin/pint --dirty --format agent`). Commit each task on `main` with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; never push.

## Review Focus

1. A push to an iOS phone carries `aps.badge` equal to its running total; the second push says 2, not 1, even when two pushes are sent back to back (Task 2).
2. One phone's reset never changes another phone's (or another subscriber's) count, and an Android channel's payload has no `badge` (Tasks 2–3).
3. A failed or offline reset never throws into a screen, is retried on the next tick, and the next successful reset leaves the server at 0 (Task 4).
4. The repeating check does not hit the server on every tick (Task 5).
5. A push received while the app is open leaves no badge and no server count (Task 5).

---

### Task 1: The count column and the badge in the FCM payload

**Files:**
- Create: migration `database/migrations/2026_10_19_100001_add_unread_push_count_to_notification_channels_table.php`
- Modify: `app/Models/NotificationChannel.php`, `app/Support/Delivery/FcmClient.php`
- Test: `tests/Feature/FcmClientTest.php`, `tests/Feature/NotificationChannelUnreadPushCountTest.php`

**Interfaces:**
- Produces: column `notification_channels.unread_push_count` (unsigned integer, not null, default 0); `NotificationChannel::incrementUnreadPushCount(): int` (atomic, returns the new total); `NotificationChannel::resetUnreadPushCount(): void`; `FcmClient::send(string $deviceToken, string $title, string $body, array $data = [], ?int $badge = null): FcmResult` — when `$badge` is not null the APNs block is `['aps' => ['sound' => 'default', 'badge' => $badge]]`, otherwise unchanged.

- [ ] **Step 1: Write the failing tests:** `send puts the badge in the apns aps block when one is given` and `send without a badge leaves the apns block with only the sound` (extend `FcmClientTest.php`, same fake helpers as its neighbours); `incrementUnreadPushCount returns 1, 2, 3 on successive calls`, `two channels count independently`, `resetUnreadPushCount sets it to 0`, `a counter change writes no activity-log row` (new file).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the migration, the two model methods (one `UPDATE ... SET unread_push_count = unread_push_count + 1 ... RETURNING unread_push_count` through the query builder; the model must not log this column — check `getActivitylogOptions` and exclude it) and the optional `$badge` parameter.
- [ ] **Step 4: Run; expect PASS** (FcmClient and NotificationChannel tests). Pint.
- [ ] **Step 5: Commit** `Push: a per-phone unread count and a badge in the iOS payload`.

---

### Task 2: Count the push and send the total

**Files:**
- Modify: `app/Support/Delivery/Transports/MobilePushTransport.php`, `.ai/rules/delivery.md` (a few lines)
- Test: `tests/Feature/MobilePushTransportTest.php`

**Interfaces:**
- Consumes: Task 1's `incrementUnreadPushCount()` and `FcmClient::send(..., badge:)`.
- Produces: for a channel whose `config['platform']` is `ios`, every `send()` increments first and passes the new total as `$badge`; any other platform sends no badge and does not increment.

- [ ] **Step 1: Write the failing tests** (`fcmFakeGoogle()` helpers as in the file): `an iOS push carries the running badge total` (two sends → `aps.badge` 1 then 2, column 2), `an Android push has no badge and the count stays 0`, `a failed push still counts` (FCM fake returns 500 → count 1), `the badge is never a data field` (data values stay strings).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** in `MobilePushTransport::send` (increment after the blank-token check and before calling FCM; the factory state `mobilePush()` may need a platform argument — extend it rather than hand-building channels); add the rule note to `delivery.md` (increment-before-send, iOS only, no rollback, the reset endpoint).
- [ ] **Step 4: Run; expect PASS** (the whole transport and dispatcher tests). Pint.
- [ ] **Step 5: Commit** `Push: send each iOS phone its unread count as the app icon badge`.

---

### Task 3: The reset endpoint

**Files:**
- Create: `app/Http/Controllers/Api/V1/PushBadgeController.php` (or a method on `PushTokenController` if it stays small — follow its style)
- Modify: `routes/api.php`, `.ai/rules/api.md`
- Test: `tests/Feature/ApiPushBadgeTest.php`

**Interfaces:**
- Consumes: `NotificationChannel::resetUnreadPushCount()`.
- Produces: `POST /api/v1/push-token/badge/clear`, route name `api.v1.push-token.badge.clear`, inside the same `ability:app` group as `/push-token`; 204, no body.

- [ ] **Step 1: Write the failing tests:** `it needs an app token` (401 without; 403 for a `location` beacon token); `clearing resets the caller's phone only` (two phones of one user and one of another user, all with counts, only the caller's goes to 0); `a phone with no channel answers 204`; `an Android channel answers 204 and is untouched`; `the response has no body`.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the route and action: find the channel as `NotificationChannel::query()->where('personal_access_token_id', $token->id)->where('channel_type', 'mobile_push')`; add the api.md note (no id accepted, 204 always).
- [ ] **Step 4: Run; expect PASS.** Pint.
- [ ] **Step 5: Commit** `API: a phone can reset its unread push count`.

---

### Task 4: The app's badge service

**Files:**
- Create: `mobile/app/Services/PushBadge.php`
- Modify: `mobile/app/Services/WxNotifyApi.php`, `mobile/app/Support/PushState.php`
- Test: `mobile/tests/Feature/PushBadgeTest.php`

**Interfaces:**
- Produces: `WxNotifyApi::clearPushBadge(): void` (`POST /api/v1/push-token/badge/clear`, same error handling as `unregisterPushToken`); `PushState::resetPending(): bool`, `markResetPending()`, `clearResetPending()` (cache keys like the existing ones, forgotten on sign-out like the rest of `PushState`); `PushBadge::clear(bool $server = true): void` — always calls `PushNotifications::clearBadge()` first; when `$server` is true or a reset is pending, calls `WxNotifyApi::clearPushBadge()`: success → `clearResetPending()`, any failure (including `Unauthenticated`, which is not this service's to handle) → `markResetPending()` and a log line with the exception class only; never throws; does nothing off a device or when signed out (no token) except the local clear.

- [ ] **Step 1: Write the failing tests** (`Http::fake`, `PushNotifications::shouldReceive('clearBadge')`): `clear calls the plugin and the server`, `a failed server call is swallowed and marks a reset pending`, `the next clear retries and clears the pending flag`, `with server false and nothing pending only the local clear runs`, `signed out: the local clear only, no request`, `signing out forgets the pending flag` (existing sign-out path).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the API method, the three `PushState` methods and `PushBadge`; register nothing new if the container auto-resolves it.
- [ ] **Step 4: Run; expect PASS** (`vendor/bin/pest --compact` in `mobile/`). Pint.
- [ ] **Step 5: Commit** `App: a service that clears the badge locally and on the server`.

---

### Task 5: Clear the badge on every tab screen

**Files:**
- Create: `mobile/app/Support/Concerns/ClearsPushBadge.php`
- Modify: every tab screen's `mount()` in `mobile/app/NativeComponents/` (find the bottom-nav tab screens in the routes/chrome — Alerts, Map and Products at minimum; the others only if they are tabs), `mobile/.ai/rules/*` or its `CLAUDE.md` note equivalent, memory note
- Test: `mobile/tests/Feature/PushBadgeScreensTest.php`

**Interfaces:**
- Consumes: `PushBadge::clear(bool $server = true)`, `Native\Mobile\Events\PushNotification\PushNotificationReceived`.
- Produces: trait `ClearsPushBadge` with (a) `clearPushBadgeOnOpen(): void` called from each tab screen's `mount()`, which also stores the time of the last tick in the component; (b) `#[On(PushNotificationReceived::class)] clearPushBadgeOnForegroundPush(): void` → `PushBadge::clear()`; (c) `#[Poll(5000)] pollPushBadge(): void` — a local clear every tick (`PushBadge::clear(server: false)`, which also retries a pending reset), and a full `PushBadge::clear()` when the time since the previous tick is more than three poll intervals (the screen was not ticking, so the app was in the background). Existing `#[Poll]` methods on a screen (Alerts has `pollActive`) are untouched.

- [ ] **Step 1: Write the failing tests** (`Native::test(Screen::class)`, `PushBadge` bound to a spy or `PushNotifications`/`Http` fakes): `mounting a tab screen clears the badge and resets the server count`; `a push received while the screen is mounted clears the badge and resets the count`; `a poll tick clears locally and does not call the server`; `a tick long after the previous one (travel in time) also resets the server count`; `a pending reset is retried on the next tick`; `a failing server never throws into the screen`; datasets over every screen that gets the trait.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the trait and wire each tab screen (call in `mount()`, `use ClearsPushBadge`); watch that the poll does not make a tab screen re-render needlessly (a tick that changes no state should not rebuild a heavy list — if the framework re-renders on every poll, say so in the report). Add the mobile rule note (the trait, the gap trick, the 5-second interval, why the server is not called per tick) and update the memory note `mobile-push-state.md`.
- [ ] **Step 4: Run the new tests, then the whole mobile suite** and `php artisan native:validate`; expect PASS. Pint.
- [ ] **Step 5: Commit** `App: clear the icon badge when the app opens, on a foreground push and after a return from the background`.

---

## Device checks owed to the owner (after a rebuild; ask which platform, never run the build)

iOS (physical device): two closed-app pushes show 2; opening clears it; a push while the app is open leaves no badge; backgrounding then reopening clears within about 5 seconds; the next push after a clear says 1. Android: a push while closed shows the OS dot and notification; opening removes it; a foreground push's banner does not misbehave (if it vanishes too abruptly, skip the tray clear for foreground pushes, per the spec).
