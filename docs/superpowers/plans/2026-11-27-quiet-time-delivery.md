# Quiet-time alerts reach the phone quietly: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A weather alert held by quiet time appears unread in the app, and an iPhone's icon badge updates without a notification.

**Architecture:** A held phone row stays `suppressed` and gets a new `in_app` mark; one definition of "in the app's feed" (successful phone push, or marked) replaces the success-only one. For a marked iPhone row, a queued job sends a badge-only push through the delivery gates.

**Tech Stack:** Laravel 13, PostgreSQL, Pest. Backend only. Tests: `docker compose exec -T front-end php artisan test --compact <file>`; Pint: `docker compose exec -T front-end vendor/bin/pint --dirty --format agent`.

**Spec:** `docs/superpowers/specs/2026-11-27-quiet-time-delivery-design.md`

## Global Constraints

- A quiet-time alert must never make a sound, vibrate, show a banner or appear on the Lock Screen. **No push at all to Android or any non-phone channel.**
- Rows held before this change stay out of the app: `in_app` is false for every existing row; no backfill.
- A marked row keeps `status = 'suppressed'`: statistics' "held", delivery logs, "Active now", failure and attention lists and charts do not change.
- A row is marked only for a `mobile_push` channel that could receive a push now: no `ChannelDispatcher` refusal (pending deletion, tier, unverified) and a filled `config['fcm_token']`.
- No network call during matching.
- The badge push is exactly: `message.token`, `message.apns.headers` = `apns-push-type: alert`, `apns-priority: "10"`, `apns-collapse-id: "wx-badge"`, `apns-expiration: now + 86400`; `message.apns.payload.aps` = `{badge}`. No `notification`, `data` or `android` key, no sound. One attempt.
- A failed or refused badge push changes no row and records no failure; only a fixed message or the exception class is logged (never the token or a response body). A dead token is pruned as for a normal push.
- Normal alert pushes are byte-for-byte unchanged and are not delayed: the badge job does not use the `deliveries` queue.
- Follow `.ai/rules` (alerts, delivery, pipeline, models, actions, jobs).

## Review Focus

- A subscriber with an iPhone and an Android phone on one rule: both rows are marked, only the iPhone gets a badge push, and the alert counts once.
- A rule with a phone and an email channel: the email row is never marked and gets nothing.
- The same product matched again (the SPC backfill re-match): no second row and no second badge push.
- The subscriber reads everything before the queued badge job runs: it sends the current count (0), not a stale one.
- The channel is deleted or becomes unverified between matching and the job: nothing is sent and nothing errors.

---

### Task 1: The `in_app` mark and one definition of the app's feed

**Files:**
- Create: two migrations (`php artisan make:migration`): the column, and the index rebuild
- Modify: `app/Models/DeliveryLog.php`, `app/Actions/Alerts/ListAppAlerts.php`, `CountUnreadAppAlerts.php`, `PushBadgeCount.php`, `MarkAppAlertsRead.php`, `app/Support/AppAlert.php`, `app/Http/Resources/Api/V1/AlertResource.php` (doc comment), `database/factories` for DeliveryLog if one exists
- Test: `tests/Feature/QuietAlertFeedTest.php` (new)

**Interfaces:**
- Produces: column `delivery_logs.in_app` boolean not null default false (fillable, cast boolean). `DeliveryLog::IN_APP_FEED_SQL = "channel_type = 'mobile_push' AND (status = 'success' OR in_app)"`; `scopeInAppFeed()` and `isInAppFeed(): bool` replace `scopePushedToPhone()` / `isPushedToPhone()` (no old name remains). `ListAppAlerts::HAS_PUSH` becomes `"bool_or(" . DeliveryLog::IN_APP_FEED_SQL . ")"`. `AppAlert::pushRows()` keeps its name and returns the in-feed rows.

- [ ] **Step 1: Write the failing tests.** With rows built directly: a `suppressed` `mobile_push` row with `in_app` true appears in `GET /api/v1/alerts` (unread, `channels[0].status` = `suppressed`, `has_failure` false), in `GET /alerts/{id}` and by ref; it counts in `CountUnreadAppAlerts` and in `PushBadgeCount` (same group already unread adds nothing); read, read-ref and read-all stamp it. A `suppressed` row with `in_app` false does none of these. A non-phone row with `in_app` true is ignored. A group with a read success row and a newer marked row is unread. `GetAlertStats` counts a marked row in `held` and not in `deliveries`; `GetActiveNowAtMyLocations` reports `held`. `isInAppFeed()` and `scopeInAppFeed()` agree over a dataset of channel type, status and mark.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** The column migration is a plain add. The index migration follows `2026_10_20_100001_add_read_at_to_delivery_logs_table.php` (`$withinTransaction = false`, safe to re-run, `CONCURRENTLY`): create `delivery_logs_app_unread_index` on `(user_id, ingested_product_id, alert_rule_id)` `WHERE channel_type = 'mobile_push' AND (status = 'success' OR in_app) AND read_at IS NULL`, then drop `delivery_logs_unread_push_index`; `down()` restores the old one. The scope's SQL must match the index predicate closely enough for PostgreSQL to use it: verify with `EXPLAIN` on the unread count (with `enable_seqscan` off if the table is small) and report the plan.
- [ ] **Step 4: Run, expect PASS,** plus `ApiAlertsTest`, `ApiAlertsReadStateTest`, `ApiAlertByRefTest`, `ApiAlertTapReadTest`, `ListAppAlertsTest`, `FindAppAlertTest`, `PushBadgeCountTest`, `ApiPushBadgeTest`, `DeliveryLogReadStateTest`. A test that names the old index or scope is updated to the new name, with its intent unchanged.
- [ ] **Step 5: Pint and commit** (`Quiet alerts: an in_app mark puts a held phone alert in the app's feed`).

---

### Task 2: The badge-only push

**Files:**
- Modify: `app/Support/Delivery/FcmClient.php`, `app/Support/Delivery/Transports/MobilePushTransport.php`, `app/Support/Delivery/ChannelDispatcher.php`
- Create: `app/Jobs/SendQuietAlertBadge.php`
- Test: extend `tests/Feature/FcmClientTest.php`; create `tests/Feature/SendQuietAlertBadgeTest.php`

**Interfaces:**
- Consumes: `CountUnreadAppAlerts` (counts marked rows after Task 1).
- Produces: `FcmClient::sendBadge(string $deviceToken, int $badge): FcmResult` (sharing credentials, access token, 401 handling and classification with `send()`); `MobilePushTransport::sendBadge(NotificationChannel $channel, int $badge): DeliveryResult` (missing token fails; a dead token queues `PruneMobilePushChannel` as `send()` does); `ChannelDispatcher::refusalFor(NotificationChannel $channel): ?string` (the same refusals as an alert send, no HTTP) and `ChannelDispatcher::sendAppBadge(NotificationChannel $channel, int $badge): DeliveryResult` (refusals first, then the transport); `SendQuietAlertBadge::__construct(public int $channelId)`, on the `default` queue.

- [ ] **Step 1: Write the failing tests.** `sendBadge` sends exactly the message in Global Constraints (assert the whole decoded body with `toBe`, and the expiry with frozen time), returns delivered / failed / unregistered like `send()`, and the existing `send()` payload tests still pass unchanged. The job: sends the current unread count to an iPhone channel (including 0); sends nothing and does not throw for a deleted channel, an Android channel, a non-phone channel, an unverified, over-tier or pending-deletion owner, or a missing token; a Firebase failure or a thrown exception writes no `DeliveryLog` row, changes none, and logs no token; an unregistered reply queues `PruneMobilePushChannel`; the job's queue is `default`.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** The job catches `Throwable` and logs only the class; it computes the count with `CountUnreadAppAlerts` when it runs.
- [ ] **Step 4: Run, expect PASS,** plus `MobilePushTransportTest`, `ChannelDispatcherTest`.
- [ ] **Step 5: Pint and commit** (`Quiet alerts: a badge-only push for iPhones, through the delivery gates`).

---

### Task 3: Mark held phone alerts at matching and queue the badge

**Files:**
- Modify: `app/Actions/Weather/MatchWeatherAlertRules.php` (the suppressed branch of `dispatchOrSuppress`)
- Create: `app/Actions/Alerts/CanShowHeldAlertInApp.php`
- Test: `tests/Feature/QuietAlertMatchingTest.php` (new)

**Interfaces:**
- Consumes: `delivery_logs.in_app` (Task 1); `ChannelDispatcher::refusalFor()`, `SendQuietAlertBadge` (Task 2).
- Produces: `CanShowHeldAlertInApp::__invoke(NotificationChannel $channel): bool`: true only for `mobile_push` with no refusal and a filled token; any `Throwable` gives false and logs only the class, so the held row is still written.

- [ ] **Step 1: Write the failing tests** (through real matching, as `MatchWeatherAlertRulesTest` line 239 does): the phone row of a quiet match is `suppressed` and `in_app` true with `read_at` null; an iPhone queues one `SendQuietAlertBadge`, an Android channel none; an email channel on the same rule is unmarked with no job; iPhone plus Android gives two marked rows, one job, and an unread count of 1; unverified, over-tier, pending-deletion and tokenless phone channels give an unmarked held row and no job; an eligibility check that throws still writes the held row; a second match of the same product adds no row and no job; `Http::preventStrayRequests()` with a faked queue shows no request during matching; outside quiet time the row is unmarked and `DeliverWeatherAlert` is dispatched as before. One end-to-end test with the sync queue: a quiet match sends exactly one badge-only request with badge 1, and the alert is in the feed.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** Create the row (and attach segments) before dispatching the job, which is dispatched only for `config['platform'] === 'ios'`. A failure to queue must not undo the row.
- [ ] **Step 4: Run, expect PASS,** plus `MatchWeatherAlertRulesTest`.
- [ ] **Step 5: Pint and commit** (`Quiet alerts: held phone alerts reach the app and update the iPhone badge`).

---

### Task 4: Wording, rules and full run

**Files:**
- Modify: `app/Support/DeliveryFailureExplanation.php`, `app/Filament/Dashboard/Widgets/RecentAlertsWidget.php`; any dashboard quiet-hours help text that says held alerts are dropped (check `AlertRuleForm::quietHoursStep()`)
- Test: extend `tests/Feature/DeliveryFailureExplanationTest.php` and the Recent alerts widget's test if there is one

**Interfaces:**
- Produces: `DeliveryFailureExplanation::for(string $status, ?string $errorDetail, bool $inApp = false)`. For `suppressed` with `$inApp`: reason unchanged ("Held back during your quiet hours."), fix "No notification was sent. The alert is in the WxNotify app's Inbox. Change the quiet hours on the alert if you want to be notified then." Without `$inApp`, today's text.

- [ ] **Step 1: Write the failing tests** for both sentences, and that the widget shows the app sentence for a marked row.
- [ ] **Step 2: Run, expect FAIL; implement (every `for()` call in the widget passes the row's mark); expect PASS.**
- [ ] **Step 3: Project rules** with `record-rule`: for `app/Actions/Alerts/**` — "in the app's feed" is `DeliveryLog::IN_APP_FEED_SQL` / `scopeInAppFeed()` / `isInAppFeed()` plus the partial index, which change together; a held row marked `in_app` stays `suppressed`. For `app/Support/Delivery/**` — the badge-only push carries only `aps.badge` and goes through `ChannelDispatcher::sendAppBadge()`; never send Android a silent high-priority message. Correct any existing rule sentence that now says the feed lists only successful pushes.
- [ ] **Step 4: Full backend suite** (project memory: 6 processes in the app container). Report failures by name.
- [ ] **Step 5: Pint and commit** (`Quiet alerts: dashboard wording and project rules`). In the final message give the owner: deploy server A first (it migrates), then D and C; and the one-quiet-time-alert check on a real iPhone and an Android phone.
