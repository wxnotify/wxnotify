# Mobile Outlook Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A subscriber opens a Severe Weather Outlook alert in the WxNotify app and reads the same report the email carries (per-day maps, levels, place rows, Days 4-8, PWO), with the maps as they were when the report was sent, kept 30 days.

**Architecture:** The backend stores each outlook run's plain-data payload once per `batch_key` (new `outlook_report_runs` table) plus a private-disk copy of each SPC map, when the rule is not Brief and has a mobile channel. The alert detail API returns the report as display strings from the existing `OutlookReportPresenter`, with short-lived signed map URLs served by a new unauthenticated-but-signed route. A daily command deletes map copies older than 30 days. The app's `AlertDetail` screen renders the report block when the detail carries one.

**Tech Stack:** Laravel 13 / PHP 8.4 / Pest (backend, repo `wxnotify-nextgen`); NativePHP v4 SuperNative / Pest (app, repo `../wxnotify-mobile`).

**Spec:** `docs/superpowers/specs/2026-11-02-mobile-outlook-report-design.md`

## Global Constraints

- Backend work follows `.ai/rules/index.md` (read the rule file whose globs cover each path: `alerts.md`, `api.md`, `v1.md`, `app-jobs.md`, `commands.md`, `delivery.md`, `models.md`, `support.md`). `.ai/rules` is written only by the controller through `record-rule`; implementers never edit it.
- Backend tests run in the app container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && php artisan test --compact <files>'`. If that container is not running, report BLOCKED. Run `vendor/bin/pint --dirty --format agent` (in the container for the backend, in the repo for the app) before finishing.
- Create files with `php artisan make:* --no-interaction` (migration, model with `-mf`, command, controller, test with `--pest`). Pest helper functions in test files must have file-prefixed names (they collide across files).
- Commit directly on `main`, one commit per task, message ending with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never push.
- The email, the push text/body, the rule editor, `FetchSpcOutlooks` and the dispatch schedule keep their behaviour. A Brief rule (`AlertRule::effectiveMessageBodyStyle() === MessageBodyStyle::Brief`) stores no report and no maps.
- Map copies live on the `local` disk (private, `storage/app/private`) under `outlook-maps/{batch_key}/{day1|day2|day3|day48}.{png|gif}`; only `image/png` and `image/gif` are stored. `OutlookReportRun::MAP_DAYS = 30`.
- Responses never carry a file path, `error_detail`, a destination, or another user's data. Log exception classes only, never messages that could carry content.
- App repo: Pest `vendor/bin/pest --compact`, `php artisan native:validate`, `vendor/bin/pint --dirty --format agent`; read `nativephp-mobile` skill before touching components; use `<native:image>` for maps (remote URLs work on both platforms).

## Review Focus

The inputs the spec implies that no task's happy-path test would cover, most likely first (each has a test in the owning task):

1. A Brief rule with a mobile channel stores no run and its detail has `outlook_report: null` (Task 2, Task 3).
2. One run sent to two phones stores one run row and one set of map files (Task 1, Task 2).
3. A graphic that is `null` or has an unexpected mime leaves that day without `map_url`, and nothing throws (Task 1).
4. A signed map link with a wrong or missing signature, or for a key that was never stored, or past 30 days, never returns image bytes (Task 3).
5. Someone else's alert id returns 404 and an alert of another category has no `outlook_report` block (Task 3).

---

### Task 1: Store an outlook run and its maps

**Files:**
- Create: `app/Models/OutlookReportRun.php`, `database/migrations/*_create_outlook_report_runs_table.php`, `database/factories/OutlookReportRunFactory.php`, `app/Actions/Alerts/StoreOutlookReportRun.php`
- Test: `tests/Feature/StoreOutlookReportRunTest.php`

**Interfaces:**
- Consumes: the `BuildOutlookReport` payload (plain array) and the `DeliverScheduledAlert::outlookGraphics()` shape: `array<'day1'|'day2'|'day3'|'day48', array{bytes: string, mime: string}|null>`.
- Produces:
  - Table `outlook_report_runs`: `id`, `user_id` (foreign, cascade on delete), `alert_rule_id` (nullable, null on delete), `batch_key` (string 36, unique), `payload` (json), `maps` (json nullable: `{key: {path: string, mime: string}}`), timestamps.
  - `OutlookReportRun` (casts `payload` and `maps` to array; `user()` and `alertRule()` relations; `const MAP_DAYS = 30`; `mapsExpired(): bool` true when `created_at` is `MAP_DAYS` days or more ago; `mapPath(string $key): ?array{path: string, mime: string}` from `maps`).
  - `StoreOutlookReportRun::__invoke(AlertRule $rule, string $batchKey, array $payload, array $graphics): OutlookReportRun`.

- [ ] **Step 1: Write failing tests** in `StoreOutlookReportRunTest.php` (use `Storage::fake('local')`): it stores one row with the payload and the rule's user and id; it writes `outlook-maps/{batchKey}/day1.png` and `day48.gif` with the bytes and records `{path, mime}` per key; a `null` graphic and a graphic with mime `text/html` are not stored and not listed; calling it twice for the same `batchKey` leaves one row and does not rewrite files (a second call with different bytes keeps the first bytes); a disk write that throws is swallowed (the run is still saved, the key is left out) — fake with `Storage::shouldReceive('disk')` or a throwing disk and assert via `Log::spy()` that only the exception class is logged.
- [ ] **Step 2: Run them** and see them fail (class not found).
- [ ] **Step 3: Create the migration, model, factory** with the schema above (factory default: a minimal valid payload from `BuildOutlookReport`'s shape with one available Day 1 and empty `maps`; states `withMaps()` and `old()` setting `created_at` 31 days ago are useful to later tasks). Implement `StoreOutlookReportRun`: `firstOrCreate` on `batch_key` (on a unique-violation race, re-select), then write each allowed graphic once and save `maps`; a row that already has `maps` is left alone.
- [ ] **Step 4: Run the tests and Pint**; expected PASS.
- [ ] **Step 5: Commit** `Outlook report: store each run and its map copies`.

### Task 2: Keep the run when a report goes to a phone

**Files:**
- Modify: `app/Console/Commands/DispatchOutlookReports.php` (`wantsFullReport()`), `app/Jobs/DeliverScheduledAlert.php`
- Test: `tests/Feature/DispatchOutlookReportsTest.php` (update), `tests/Feature/OutlookReportMobileBriefTest.php` (add cases) or a new `tests/Feature/OutlookReportRunStoredTest.php`

**Interfaces:**
- Consumes: `StoreOutlookReportRun::__invoke(AlertRule, string $batchKey, array $payload, array $graphics)` (Task 1); `DeliverScheduledAlert::outlookGraphics(array $report)` (existing private method); `$this->batchKey`, `$this->report`.
- Produces: after this task a non-Brief outlook rule that has a `mobile_push` channel queues its jobs with the report payload, and each mobile job that delivers successfully leaves one `outlook_report_runs` row for the run.

- [ ] **Step 1: Write failing tests.** `wantsFullReport` is true for a non-Brief rule whose only channel is `mobile_push` (the job still sends the brief text as the push body, unchanged), still true for email, still false for Brief and for a rule with neither a rich nor a mobile channel. A successful mobile delivery of an outlook run creates exactly one `OutlookReportRun` with that `batch_key` (fake the SPC graphics like `ombFakeSpc()` does; two phones on one rule still give one row and each map file once); a failed push stores nothing; a Brief rule stores nothing; the email channel alone stores nothing; a job queued before this change (`report` null) stores nothing and does not throw; a storage failure does not fail the job or change the DeliveryLog status.
- [ ] **Step 2: Run them** and see them fail.
- [ ] **Step 3: Implement.** In `wantsFullReport()` also accept a channel whose `channel_type` is `mobile_push`. In `DeliverScheduledAlert::handle()`, after `$dispatcher->send()` and before the `DeliveryLog::create`, when `$isOutlookReport && $report !== null && $result->success && $channel->channel_type === 'mobile_push'` call the store action with the graphics (reuse `$meta['outlook_report_images']` when already fetched for this job, else `outlookGraphics($report)`); wrap in `try/catch (Throwable)` logging only the class.
- [ ] **Step 4: Run the new tests and the existing outlook tests** (`DispatchOutlookReportsTest`, `OutlookReportMobileBriefTest`, `OutlookReportMailTest`, `DeliverScheduledAlertTest`); expected PASS.
- [ ] **Step 5: Commit** `Outlook report: keep the run when it is delivered to a phone`.

### Task 3: Alert detail carries the report; signed map route

**Files:**
- Create: `app/Actions/Alerts/PresentAppOutlookReport.php`, `app/Http/Controllers/Api/V1/OutlookMapController.php`
- Modify: `app/Http/Resources/Api/V1/AlertDetailResource.php`, `routes/api.php`
- Test: `tests/Feature/ApiAlertOutlookReportTest.php`

**Interfaces:**
- Consumes: `OutlookReportRun` (Task 1), `OutlookReportPresenter::present(array $payload)` and its `NOTE`, `SPC_URL` constants.
- Produces:
  - `PresentAppOutlookReport::__invoke(OutlookReportRun $run): array` returning the presenter's `days`, `extended`, `extended_available`, `extended_dates`, `extended_link`, `pwo`, plus `note`, `source_url`, `maps_expired` (bool, from `mapsExpired()`), each day gaining `map_url` (string|null) and the result gaining `extended_map_url` (string|null). A `map_url` is `URL::temporarySignedRoute('api.v1.outlook-maps.show', now()->addHour(), ['batchKey' => $run->batch_key, 'key' => 'day1'])` only when maps are not expired, the key is stored, and the file exists on the disk; otherwise null.
  - `AlertDetailResource` adds `outlook_report` (the array above, or `null`): non-null only when the alert's category is `outlook_report` and an `OutlookReportRun` with the alert's first row `batch_key` and `user_id` equal to the requesting user exists.
  - Route `GET /api/v1/outlook-maps/{batchKey}/{key}` named `api.v1.outlook-maps.show`, middleware `signed` and `throttle:60,1`, outside the `auth:sanctum` group, `key` limited to `day1|day2|day3|day48`; answers the stored bytes with the stored mime, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=3600`; 404 when the run is unknown, the key was never stored, the file is missing, or the maps are expired.

- [ ] **Step 1: Write failing tests** (`ApiAlertOutlookReportTest.php`, built on `FindAppAlert`/`ApiAlertsTest` fixtures: a mobile_push DeliveryLog with `category` `outlook_report` and `batch_key`, plus an `OutlookReportRun::factory()->withMaps()`): detail returns `outlook_report` whose day headings, `levels` strings and place rows equal `OutlookReportPresenter::present($run->payload)`, with signed `map_url`s that fetch the stored bytes (`getJson` on the URL returns 200 with the right mime and `nosniff`); an unsigned or tampered URL is 403 and a link past its hour is 403; a key never stored, a missing file and an `old()` run all 404 on the route; an old run's detail has `maps_expired: true` and every `map_url` null while the text is still there; a Brief or pre-change alert (no run) and an alert of another category have `outlook_report: null`; another user's alert id is 404; a run belonging to another user with the same `batch_key` is not shown; the response never contains `path`, a storage path or `error_detail`.
- [ ] **Step 2: Run them** and see them fail.
- [ ] **Step 3: Implement** the action, the resource addition (one extra query per detail, no list change), the controller (read the bytes through `Storage::disk('local')`) and the route.
- [ ] **Step 4: Run the new tests plus `ApiAlertsTest`, `FindAppAlertTest`**; expected PASS.
- [ ] **Step 5: Commit** `API: alert detail carries the outlook report; signed map route`.

### Task 4: Delete map copies after 30 days

**Files:**
- Create: `app/Console/Commands/PruneOutlookMaps.php`
- Modify: `bootstrap/app.php` (schedule)
- Test: `tests/Feature/PruneOutlookMapsTest.php`

**Interfaces:**
- Consumes: `OutlookReportRun::MAP_DAYS`, `maps`, `batch_key`; the storage layout in Global Constraints.
- Produces: command `outlook:prune-maps` (scheduled daily next to `sanctum:prune-expired`) that, for each run with non-null `maps` created at least `MAP_DAYS` days ago, deletes the directory `outlook-maps/{batch_key}` and sets `maps` to null; the payload row is kept; younger runs and runs without maps are untouched; it prints the number pruned.

- [ ] **Step 1: Write failing tests** (`Storage::fake('local')`, `travelTo`): a 31-day-old run loses its files and `maps`, keeps `payload`; a 29-day-old run keeps both; a run with null `maps` is skipped; running twice is harmless; one run whose directory is already gone is still nulled and does not stop the others; the schedule lists the command daily (`Schedule` events contain `outlook:prune-maps`).
- [ ] **Step 2: Run** and see them fail; **Step 3: implement** (chunk by id, isolate each run, log class only); **Step 4: run** and Pint, expected PASS.
- [ ] **Step 5: Commit** `Outlook report: prune map copies after 30 days`.

### Task 5: Show the report in the app (repo `../wxnotify-mobile`)

**Files:**
- Create: `resources/views/native/partials/outlook-report.blade.php`
- Modify: `app/NativeComponents/AlertDetail.php`, `resources/views/native/alert-detail.blade.php`
- Test: `tests/Feature/AlertDetailTest.php` (add cases)

**Interfaces:**
- Consumes: the detail's `outlook_report` block exactly as Task 3 produces it: `days[]` each with `heading`, `circle`, `label`, `date`, `available`, `unavailable`, `levels`, `issued`, `alt`, `link`, `map_url`, `locations[]` (`label`, `circle`, `category`, `levels`); `extended[]` (`heading`, `circle`, `summary`, `inside`); `extended_available`, `extended_dates`, `extended_link`, `extended_map_url`; `pwo` (`title`, `issued`, `text`) or null; `note`, `maps_expired`.
- Produces: `AlertDetail::hasReport(): bool` (true when `alert['outlook_report']` is an array) and `AlertDetail::openReportLink(string $key): void` (`$key` one of `day1|day2|day3|day48`; opens the matching `link`/`extended_link` with `Browser::inApp` only if it is https, never a URL passed in by the view).

- [ ] **Step 1: Write failing tests** in `AlertDetailTest.php` with a prefixed helper `outlookDetailPayload()`: the report renders each available day's heading with circle and label, date, levels, issued line and place rows ("Home", "None"); a day with `available` false shows its `unavailable` text and no map; `map_url` renders as an image (assert the URL is in the tree) and a null one shows none; `maps_expired` shows "Maps are no longer stored." once; Days 4-8 show the dates, the summary rows and the `inside` line; a `pwo` shows its title and text; `note` shows once; tapping a day's link opens only its https link (`$this->browser->inAppUrls`, as the existing `openProduct` test does); a non-report alert still shows the old channel rows and the "View full product" button; a cached report opens offline with the offline note.
- [ ] **Step 2: Run** `vendor/bin/pest --compact tests/Feature/AlertDetailTest.php` and see them fail.
- [ ] **Step 3: Implement** the partial (a `native:column` per day, `<native:image :src :fit="0" :alt>` full width, text rows in the theme colours used elsewhere in the app; place rows as `native:row` with label left and category/levels right) and include it from `alert-detail.blade.php` when `hasReport()`; the report replaces the channel rows and keeps the title, category line and rule line. Check every new tag with `php artisan native:validate`.
- [ ] **Step 4: Run** the new tests, the full app suite, `native:validate` and Pint; expected PASS.
- [ ] **Step 5: Commit** (in the app repo) `Alert detail: show the Severe Weather Outlook report`.

## Controller steps after the tasks (not for implementers)

- Final whole-branch review of both repos; then `record-rule` entries for `outlook_report_runs`/signed map route (api.md/alerts.md), the 30-day prune (commands.md) and the job's store-after-success order (app-jobs.md).
- Rollout: backend first (migration, then `outlook:prune-maps` appears in the schedule), then the app. Check on a device: the report opens from the Inbox row and from the push tap, maps render in light and dark, an alert from before the deploy still opens the plain detail.
