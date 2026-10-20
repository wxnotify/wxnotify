# Mobile Read/Unread Alerts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The mobile Alerts screen lists only alerts pushed to the subscriber's phones, shows read/unread, lets the subscriber read by tapping or mark all read, and the iOS icon badge equals the number of unread alerts.

**Architecture:** `delivery_logs.read_at` on successful `mobile_push` rows is the read state (shared across phones); the existing grouped `AppAlert` stays the unit, the feed is filtered to groups with a successful push row, and an alert is unread when any of its push rows is unread. The push payload carries the unread count (computed before the new row exists), a tiny custom plugin sets the icon number from the app, and the old per-phone counter/foreground machinery is retired.

**Tech Stack:** Laravel 12, Pest, Postgres; NativePHP v4 app in `mobile/` with a new local plugin.

**Spec:** `docs/superpowers/specs/2026-10-20-mobile-read-unread-alerts-design.md` (read its "Settled after the builder check" section: it overrides the earlier prose where they differ).

## Global Constraints

- Feed identity is unchanged: group key `COALESCE(alert_rule_id,'none')-ingested_product_id` (or `row-{id}` without a product), stable id = lowest row id of the group, same cursor. An alert is **in the feed** when its group has at least one row with `channel_type = 'mobile_push'` AND `status = 'success'`; it is **unread** when any such row has `read_at IS NULL`. The unread count is the number of unread groups. Mark-read sets `read_at = now()` on every successful push row of the group present at request time; mark-all does it for every unread successful push row of the user. Idempotent, user-scoped, other users' ids are 404.
- Old app builds must keep working against the new backend: `AlertResource` keeps its keys (`channels` now lists only the group's successful push rows, `has_failure` is computed over push rows only, new keys `read` (bool) and, in the collection's `meta`, `unread_count`); the old `POST /api/v1/push-token/badge/clear` stays and answers 204 as a no-op; the `event`/`payload` FCM data keys stay (harmless). The column `notification_channels.unread_push_count` stays this release (unused).
- Backfill: set `read_at = created_at` (or now) on every existing successful push row, in batched id ranges (no single table-locking `UPDATE`); no column default; add the index the unread/feed queries need (check with `EXPLAIN` on the dev DB).
- Push badge (iOS only; Android has none): `aps.badge` = unread groups of the user, plus one when the pushed alert's group is not already unread (a read group, a new group, or a row without a product). The group identity (`alert_rule_id`, `ingested_product_id`, or none) reaches `MobilePushTransport` through the `$meta` array that `DeliverWeatherAlert`/`DeliverScheduledAlert` already pass; the "Send test notification" push has no group meta and carries the plain unread count. The delivery row is written AFTER the send, so the count is computed from the database before the row exists.
- The app corrects the icon from `unread_count` on every feed load and after every read change; a failed read call is retried and never blocks opening an alert; opening works offline; the plugin never throws into a screen.
- Mobile: NativePHP v4 conventions (EDGE markup, theme tokens, typed icon enums, no emoji, partials without `$this`), `native:validate` passes; never run `native:run`/`native:jump`/`native:watch` (tell the owner which platform to build, the plugin needs a native rebuild).
- Not in this slice: marking read when a push is tapped open from the notification (it opens the product page, not the list item), the web dashboard, Android tray syncing, removal of the unused counter column and the `event`/`payload` keys (a later release).
- Before editing read `.ai/rules/index.md` and the matching rule files (delivery.md, api.md, models.md, actions.md, feature.md); in `mobile/` read `mobile/CLAUDE.md`, and activate the `nativephp-mobile` and `mobile:testing-best-practices` skills. Backend tests: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact <paths>'`; mobile tests: `cd mobile && vendor/bin/pest --compact` plus `php artisan native:validate`. Pint on touched PHP (`vendor/bin/pint --dirty --format agent`). Commit each task on `main` with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`, committing explicit paths; never push.

## Review Focus

1. A subscriber with email-only alerts sees none of them in the app, and an alert pushed to two phones appears once and reads once (Tasks 1–2).
2. A new push to an already-read alert makes it unread again and the badge number in that push counts it (Tasks 1, 3).
3. Marking read is idempotent, user-scoped, and a failed/offline mark never blocks opening or loses the intent (Tasks 2, 5).
4. The icon number always equals the unread count the server last reported; an old app build still works against the new backend (Tasks 2, 3, 6).
5. Rollout: existing alerts start read, and no single statement locks `delivery_logs` (Task 1).

---

### Task 1: Read state, the unread count and the feed filter

**Files:**
- Create: migration `database/migrations/2026_10_20_100001_add_read_at_to_delivery_logs_table.php` (column, index, batched backfill), `app/Actions/Alerts/CountUnreadAppAlerts.php`
- Modify: `app/Models/DeliveryLog.php` (cast, scope helpers `scopePushedToPhone()`, `scopeUnread()`), `app/Support/AppAlert.php` (`isUnread()`, `pushRows()`), `app/Actions/Alerts/ListAppAlerts.php`, `app/Actions/Alerts/FindAppAlert.php`, `database/factories/DeliveryLogFactory.php` (states `pushed()`, `read()`)
- Test: `tests/Feature/DeliveryLogReadStateTest.php`, `tests/Feature/ListAppAlertsTest.php` (extend), `tests/Feature/FindAppAlertTest.php` (extend)

**Interfaces:**
- Produces: `DeliveryLog::scopePushedToPhone()` (`channel_type = mobile_push` and `status = success`), `scopeUnread()`; `AppAlert::isUnread(): bool`; `AppAlert::pushRows(): Collection`; `CountUnreadAppAlerts::__invoke(User $user): int` (number of unread groups); `ListAppAlerts` returns only groups that have a successful push row (same id, same cursor, `GROUP_KEY` unchanged); `FindAppAlert` returns null when the group has no successful push row.

- [ ] **Step 1: Write the failing tests:** `an email-only alert is not in the feed`; `an alert with a successful push appears once even with email and Discord rows in the group`; `a group whose only push failed is not in the feed`; `the same alert pushed to two phones is one feed entry`; `an alert is unread while any push row is unread and read when all are read`; `a new push row after read makes the group unread again`; `CountUnreadAppAlerts counts groups not rows, per user, and ignores other users`; `FindAppAlert is null for a group without a successful push row`; `the migration backfill marks existing push rows read in batches` (run the migration's backfill method on seeded rows).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the migration (nullable `read_at`, composite index suited to "user + push + success + unread", batched backfill in `up()` with a documented chunk size), the model scopes and cast, the `AppAlert` helpers, the narrowed `ListAppAlerts`/`FindAppAlert`, the count action (one indexed query), factory states.
- [ ] **Step 4: Run; expect PASS** (new tests plus the existing alert-list/find tests). `EXPLAIN` the feed and count queries on the dev DB and note the plan in the report. Pint.
- [ ] **Step 5: Commit** `Alerts: read state on pushed deliveries and a feed of pushed alerts`.

---

### Task 2: The app API: read flag, unread count, mark read

**Files:**
- Create: `app/Actions/Alerts/MarkAppAlertsRead.php`
- Modify: `routes/api.php`, `app/Http/Controllers/Api/V1/AlertController.php`, `app/Http/Resources/Api/V1/AlertResource.php`, `app/Http/Resources/Api/V1/AlertDetailResource.php`, `.ai/rules/api.md`
- Test: `tests/Feature/ApiAlertsReadStateTest.php`, plus the existing `ApiAlerts*Test.php` files stay green

**Interfaces:**
- Consumes: Task 1's scopes, `CountUnreadAppAlerts`, `AppAlert::isUnread()/pushRows()`.
- Produces: `GET /api/v1/alerts` entries gain `read` (bool); the collection `meta` gains `unread_count`; `channels`/`has_failure` computed from push rows only (old app builds keep reading them); `GET /alerts/{id}` same shape plus `read`; `POST /api/v1/alerts/{id}/read` (route name `api.v1.alerts.read`) and `POST /api/v1/alerts/read-all` (`api.v1.alerts.read-all`), both behind `auth:sanctum` + `ability:app`, answering `200 {data: {unread_count: int}}`; `MarkAppAlertsRead::one(User, int $id): ?int` (null when not the user's / not in the feed) and `::all(User): int`, each returning the new unread count.

- [ ] **Step 1: Write the failing tests:** `the feed lists read and unread with the count in meta`; `opening reads one alert and returns the new count`; `marking an alert that arrived between list and tap reads its new rows too` (rows added to the group before the call); `reading twice is idempotent`; `another user's or an email-only alert is a 404`; `mark all reads every unread alert of the caller only`; `channels and has_failure ignore email and failed rows`; `a beacon (location) token is refused`; `no body or secret leaks`.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the action (group lookup, one `UPDATE ... WHERE id IN (...)`), controller methods, resource changes and routes; add the api.md note (feed narrowing, group read semantics, old-build compatibility).
- [ ] **Step 4: Run; expect PASS.** Pint.
- [ ] **Step 5: Commit** `API: read and unread alerts, mark one or all read`.

---

### Task 3: The badge number in pushes

**Files:**
- Create: `app/Actions/Alerts/PushBadgeCount.php`
- Modify: `app/Support/Delivery/Transports/MobilePushTransport.php`, `app/Jobs/DeliverWeatherAlert.php`, `app/Jobs/DeliverScheduledAlert.php` (pass group meta), `app/Support/Delivery/FcmClient.php` only if its signature needs nothing new, `.ai/rules/delivery.md`
- Test: `tests/Feature/PushBadgeCountTest.php`, `tests/Feature/MobilePushTransportTest.php` (update)

**Interfaces:**
- Consumes: `CountUnreadAppAlerts`, `AppAlert` grouping.
- Produces: `PushBadgeCount::__invoke(User $user, ?int $alertRuleId, ?int $ingestedProductId, bool $countsAsAlert): int` — unread groups, plus one when the push is an alert whose group is not already unread (read group, new group, or no product); `MobilePushTransport` passes the result as `FcmClient::send(..., badge:)` for `config['platform'] === 'ios'` and stops incrementing `unread_push_count`; `$meta['alert_rule_id']` and `$meta['ingested_product_id']` carry the group (jobs add them), a push without them (test push) sends the plain unread count.

- [ ] **Step 1: Write the failing tests:** `a push for a new alert counts itself`; `a push for an already-unread alert does not add one`; `a push for a read alert counts it unread again`; `a daily summary (no product) always counts as new`; `the test push carries the plain unread count`; `Android carries no badge`; `the transport no longer touches unread_push_count`; `the jobs pass the group meta to the dispatcher`.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement**, updating the delivery.md note (the badge is now the unread alert count; the old counter is unused).
- [ ] **Step 4: Run; expect PASS** (transport, dispatcher, DeliverWeatherAlert, DeliverScheduledAlert, FcmClient tests). Pint.
- [ ] **Step 5: Commit** `Push: the iOS badge is the number of unread alerts`.

---

### Task 4: The badge plugin

**Files:**
- Create: the local plugin created with `php artisan native:plugin:create` in `mobile/` (use the suggested name `wxnotify/badge-count` unless the generator dictates otherwise; its PHP facade `BadgeCount::set(int $count)`, Swift bridge function (iOS: `UIApplication.shared.applicationIconBadgeNumber = count` on the main thread; returns success), Kotlin bridge function (Android: no-op returning success)), `mobile/app/Services/AppBadge.php`
- Modify: `mobile/app/Providers/NativeServiceProvider.php` (registration), `mobile/composer.json` (path repository if the generator requires it)
- Test: `mobile/tests/Feature/AppBadgeTest.php`, `mobile/tests/Feature/NativePluginsTest.php` (extend if it lists plugins)

**Interfaces:**
- Produces: `App\Services\AppBadge::set(int $count): void` — clamps negative to 0, calls the plugin, swallows and logs (class only) any `Throwable`, does nothing off a device; `AppBadge::clear(): void` = `set(0)`.

- [ ] **Step 1: Write the failing tests:** `set passes the count to the plugin`; `a negative count becomes zero`; `a plugin failure is swallowed`; `the plugin is registered` (as `NativePluginsTest` does for the others).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the plugin scaffold (read `mobile/vendor/nativephp/mobile-firebase/nativephp.json` and its Swift/Kotlin functions as the pattern for the manifest and bridge classes), register it (`php artisan native:plugin:register` and `native:plugin:list`), and `AppBadge`.
- [ ] **Step 4: Run the new tests and the whole mobile suite; `php artisan native:validate`; expect PASS.** Pint. Tell the owner a native rebuild is needed (never run it).
- [ ] **Step 5: Commit** `App: a plugin that sets the icon badge number`.

---

### Task 5: Unread in the Alerts screen

**Files:**
- Modify: `mobile/app/Services/WxNotifyApi.php` (`markAlertRead(int)`, `markAllAlertsRead()`, feed meta `unread_count`), `mobile/app/Support/LocalCache.php` and `mobile/app/Models/CachedAlert.php` (+ migration) (`read` flag per cached alert, stored `unread_count`), `mobile/app/NativeComponents/AlertsScreen.php` and `mobile/resources/views/native/alerts-screen.blade.php`, `mobile/app/NativeComponents/AlertDetail.php`, `mobile/app/Support/AlertPresenter.php`, a small `mobile/app/Services/AlertReadState.php`
- Test: `mobile/tests/Feature/AlertsScreenTest.php`, `AlertDetailTest.php`, `AlertPresenterTest.php`, `WxNotifyApiAlertsTest.php` (extend), `mobile/tests/Feature/AlertReadStateTest.php`

**Interfaces:**
- Consumes: Task 2's `read`, `unread_count`, the two POST endpoints; Task 4's `AppBadge`.
- Produces: `AlertReadState::open(int $id): void` (marks the cached alert read at once, decrements the cached unread count, calls the server best-effort, on failure queues the id for a retry on the next feed load or app mount, then `AppBadge::set(unread)`); `AlertReadState::markAll(): void` (same pattern); `AlertReadState::syncFromServer(int $unread): void` (stores the server count and sets the badge); the list row shows an unread dot and bolder title, drops the "Sent via ..." line, and the screen has a **Mark all as read** action (disabled when nothing is unread); `AlertDetail::mount()` calls `AlertReadState::open($id)`.

- [ ] **Step 1: Write the failing tests:** `an unread alert shows the dot and a read one does not`; `the Sent via line is gone`; `opening an alert marks it read, lowers the count and sets the badge`; `a failed read call is queued and retried on the next feed load`; `offline opening still works and marks locally`; `Mark all as read clears every dot and sets the badge to 0`; `the badge is set from the server's unread_count on every feed load`; `the cached feed keeps the read flag offline`; `AlertDetail opens and reads from the feed`.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the API client methods, cache changes (with the `CachedAlert` migration, which must keep old cached rows working), the service, the view and presenter changes, and the Mark all control (a toolbar/header action sized for a thumb).
- [ ] **Step 4: Run the new tests and the whole mobile suite; `php artisan native:validate`; expect PASS.** Pint.
- [ ] **Step 5: Commit** `App: read and unread alerts, with the icon badge following the unread count`.

---

### Task 6: Retire the old badge machinery

**Files:**
- Delete (mobile): `mobile/app/Support/Concerns/ClearsPushBadge.php`, `mobile/app/Services/PushBadge.php`, `mobile/app/Events/ForegroundPushReceived.php`, `mobile/app/Listeners/MarkForegroundPush.php`, the foreground/badge flags in `mobile/app/Support/PushState.php`, their tests (`PushBadgeTest`, `PushBadgeScreensTest`, the foreground tests, `tests/Fakes/NullPushBadge` and its binding in `tests/Pest.php`) and every use of the trait on the four tab screens and in `SignOut` (keep a local badge clear at sign-out through `AppBadge::clear()`)
- Modify (backend): `app/Http/Controllers/Api/V1/PushTokenController.php` (the badge-clear route answers 204 as a documented no-op for old builds), `app/Models/NotificationChannel.php` (remove the unused counter methods; the column stays), `.ai/rules/delivery.md` and `.ai/rules/api.md` (rewrite the badge notes), the spec/plan docs only if they contradict the new behaviour
- Test: the surviving backend and mobile suites; add `ApiPushBadgeTest` expectations for the no-op

**Interfaces:**
- Consumes: Tasks 3 and 5 (the badge now comes from the unread count; nothing else sets it).
- Produces: no per-phone counter code, no reset or foreground machinery; `POST /api/v1/push-token/badge/clear` still 204 with no effect; opening the app no longer clears the badge.

- [ ] **Step 1: Write the failing tests:** the badge-clear endpoint answers 204 and changes nothing; signing out clears the icon (`AppBadge::clear()`); the four tab screens no longer reference the removed classes (a grep-style architecture test is not needed: the suite passing after deletion is the proof); opening a tab screen does not touch the badge.
- [ ] **Step 2: Run to verify they fail** (before the deletions).
- [ ] **Step 3: Implement** the deletions and rewrites; grep for leftover references (`ClearsPushBadge`, `PushBadge`, `ForegroundPush`, `unread_push_count`, `markForegroundPush`, `badge/clear`) and fix docblocks and notes.
- [ ] **Step 4: Run the whole backend suite in parallel and the whole mobile suite; `php artisan native:validate`; expect PASS.** Pint.
- [ ] **Step 5: Commit** `Retire the per-phone badge counter and the foreground-push clearing`.

---

## Device checks owed to the owner (after a rebuild; ask which platform, never run the build)

iOS (physical): two pushes show badge 1 then 2; opening one alert leaves 1; Mark all as read clears the icon; reading on a second phone updates the first phone's list on its next feed load; a push after reading shows the right number; a new push to an alert already read counts it again. Android: the OS dot behaves as before and the list shows read state. Offline: opening an alert marks it read locally and the server catches up. Rollout: after the backend deploy every existing alert shows read and the next push badge is 1. Deploy order: backend first (migration, then code), then the app build.
