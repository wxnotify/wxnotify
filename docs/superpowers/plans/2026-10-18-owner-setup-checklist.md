# Owner Setup Checklist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An owner-only setup checklist in the Admin panel (on the Onboarding plugin) that explains and self-ticks what a new WxNotify server needs, plus the two owner-pressed actions it needs: Install reference data and Check for shapefile updates.

**Architecture:** The checklist is a plugin "flow" (database rows) shipped by a data migration; steps tick themselves through small condition classes in `app/Onboarding/Conditions`. A new owner-only Setup page holds the install action (an add-only action class). The shapefile scan loop is extracted from its console command into an action, run by a queued job behind a new button. A tiny scheduled queue-heartbeat job proves the scheduler and queue worker.

**Tech Stack:** Laravel 12, Filament v5.7, `wallacemartinss/filament-onboarding` ^2.1 (already installed; its migrations and config are already published), Pest, Postgres.

**Spec:** `docs/superpowers/specs/2026-10-18-owner-setup-checklist-design.md`

## Global Constraints

- Owner means `users.is_owner`; the mode is `config('wxnotify.mode')` (`cloud` or self-hosted); the admin panel id is `admin`.
- The checklist shows only to owners, in both modes. Cloud-only steps carry visibility condition `is_cloud_mode`; the whole flow carries `is_owner`.
- Plugin text columns (`title`, `description`, `cta_label`) are JSON keyed by locale: write `['en' => '...']`.
- The shipped journey is created by a data migration, idempotently by key (`firstOrCreate`): it must never overwrite or duplicate rows an owner has edited.
- "Install reference data" is add-only: it never deletes, never overwrites admin edits, never creates a user (not the `DatabaseSeeder` test user). Seeders are not idempotent for edits (`ProductCatalogSeeder` rewrites category/description/example/sort_order on every run; `DidYouKnowSeeder` resets `is_active`/`sort_order`), so each set is installed only when it is not yet installed.
- Conditions live in `app/Onboarding/Conditions`, are named `{Name}Condition` (found automatically; the key is the snake-case of `{Name}`), implement `Wallacemartinss\FilamentOnboarding\Contracts\OnboardingCondition` (`isCompleted(Model $subject, ?Model $scope = null): bool`) and `HasConditionLabel` (`static label(): string`), are cheap, and answer false instead of throwing.
- Before editing, read `.ai/rules/index.md` and the matching rule files (`app/Actions/**`, `app/Filament/**`, `app/Models/**`, `tests/Feature/**`). Run tests in the app container: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact <paths>'`; Pint with `vendor/bin/pint --dirty --format agent`. Commit each task on `main` with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; never push.

## Review Focus

1. A subscriber, or a staff user who is not an owner, never sees the checklist, its launcher or its progress page (Task 6).
2. "Install reference data" on a catalog an admin has already customised changes nothing, and a second press installs nothing (Task 1).
3. A condition whose data is missing (no shapefile layers yet, no feed heartbeat, no cache entry) answers "not done" and never errors the panel (Task 5).
4. Pressing "Check for shapefile updates" with no queue worker running leaves a queued job, so the page says that a queue worker is required (Task 3).
5. The data migration run again, or after an owner edited a step, neither duplicates nor overwrites anything (Task 6).

---

### Task 1: Reference-data status and the install action

**Files:**
- Create: `app/Support/Setup/ReferenceDataStatus.php`, `app/Actions/Setup/InstallReferenceData.php`
- Test: `tests/Feature/InstallReferenceDataTest.php`

**Interfaces:**
- Produces: `ReferenceDataStatus::sets(): list<array{key: string, label: string, count: int, installed: bool}>` in this order and with these keys: `product_catalog` (installed when any product has a non-null `category`; count = products), `channel_types` (installed when any exist; count only: migrations install them and the action never touches them), `tips` ("Did you know" tips: installed when any exist), `shapefile_layers` (installed when every key in `ShapefileLayerDefinitions::all()` exists in `shapefile_layers`; count = rows). `ReferenceDataStatus::allInstalled(): bool` is true when `product_catalog`, `tips` and `shapefile_layers` are installed. `InstallReferenceData::__invoke(): list<string>` returns the keys it installed (a subset of `product_catalog`, `tips`, `shapefile_layers`), using `ProductCatalogSeeder`, `DidYouKnowSeeder`, `ShapefileLayerSeeder` only for a set that is not installed.

- [ ] **Step 1: Write the failing tests** in `InstallReferenceDataTest.php` (Pest, `RefreshDatabase` as the suite does; products exist from migrations, uncategorised, tips and layers empty):
  - `it installs the product catalog, the tips and the tracked layers on a fresh database` — returns `['product_catalog', 'tips', 'shapefile_layers']`; afterwards `ReferenceDataStatus::allInstalled()` is true and `User::count()` is unchanged.
  - `a second run installs nothing` — returns `[]`.
  - `it leaves an installed catalog alone` — after the first run set one product's `description` to `'custom'`, deactivate one tip; run again; the product description is still `'custom'` and the tip is still inactive.
  - `it installs only the sets that are missing` — with tips already present (create one), returns `['product_catalog', 'shapefile_layers']` and the existing tip is untouched.
  - `status lists the four sets in order with their counts and flags`.
- [ ] **Step 2: Run to verify they fail** (classes missing).
- [ ] **Step 3: Implement** `ReferenceDataStatus` (static methods over `Product`, `ChannelType` or the `channel_types` table, `DidYouKnow`, `ShapefileLayer`, `ShapefileLayerDefinitions`) and `InstallReferenceData` as specified; no output, no deletes.
- [ ] **Step 4: Run the tests; expect PASS.** Pint.
- [ ] **Step 5: Commit** `Setup: reference-data status and an add-only install action`.

---

### Task 2: The owner-only Setup page

**Files:**
- Create: `app/Filament/Admin/Pages/Setup.php`, `resources/views/filament/admin/pages/setup.blade.php`
- Test: `tests/Feature/SetupPageTest.php`

**Interfaces:**
- Consumes: `ReferenceDataStatus::sets()`, `::allInstalled()`, `InstallReferenceData` (Task 1).
- Produces: Filament page `App\Filament\Admin\Pages\Setup` (navigation group `System`, label `Setup`, route slug `setup`, so `/admin/setup`), `canAccess()` true only for `auth()->user()?->is_owner === true`; a header action `installReferenceData` (confirmation modal that says what is installed and that nothing existing is changed; disabled when `allInstalled()`), which runs `InstallReferenceData`, then sends a success notification naming what was installed (or "Everything was already installed"); a card per set with its label, count and Installed / Not installed.

- [ ] **Step 1: Write the failing tests** (mirror `ResetToFactoryDefaultsTest`: `Filament::setCurrentPanel('admin')`, `livewire(Setup::class)`):
  - `a non-owner, including staff, cannot open the setup page` (403 / `canAccess()` false for a staff non-owner).
  - `the owner sees a card for each set with its count and state`.
  - `the install action installs the missing sets and says what it installed` — `callAction('installReferenceData')`, assert `ReferenceDataStatus::allInstalled()` and `assertNotified()`.
  - `the install action is disabled once everything is installed`.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement the page and view** following `ResetToFactoryDefaults` and the other Admin pages for structure and authorization.
- [ ] **Step 4: Run the tests; expect PASS.** Pint.
- [ ] **Step 5: Commit** `Admin: a Setup page with an Install reference data action`.

---

### Task 3: Check for shapefile updates (action, job, button)

**Files:**
- Create: `app/Actions/Shapefiles/ScanAllShapefileLayers.php`, `app/Jobs/ScanShapefileLayersJob.php`
- Modify: `app/Console/Commands/ScanShapefileLayers.php`, `app/Filament/Admin/Resources/ShapefileLayers/Pages/ListShapefileLayers.php`
- Test: `tests/Feature/ScanShapefileLayersJobTest.php`, plus the existing scan command and layer tests stay green

**Interfaces:**
- Produces: `ScanAllShapefileLayers::__invoke(?Closure $onLine = null): int` — the command's loop (scan every `ShapefileLayer`, import a staged version, keep going on an import failure), returning the number of layers that staged a new version; every message the command printed (`Scanning ...`, `New version staged and imported for ...`, the import-failure line, the verbose steps) goes through `$onLine(string $message, string $level)` with `$level` `info` or `error` so the command's output stays byte-for-byte what it is now. `ScanShapefileLayersJob(public readonly int $userId)` (queued) runs the action and then sends the user a Filament database notification (`Notification::make()->...->sendToDatabase($user)`): title "Shapefile scan finished", body "{n} layer(s) staged a new version. Review and activate them under Shapefile Layers." The list page gets a header action `scanForUpdates` labelled "Check for shapefile updates" (same visibility as the page), which dispatches the job for the current user and shows a notification "Scan started" whose body says it runs on the queue and needs the queue worker to be running.

- [ ] **Step 1: Write the failing tests** (`Http::fake()` for NOAA as the existing scan tests do; `Queue::fake()` where noted):
  - `the scan job runs every layer and notifies the user how many staged` (run `handle()` directly; assert a database notification with the count).
  - `an import failure for one layer does not stop the others` (action level).
  - `the header action queues the job for the current user and says a queue worker is needed` (Livewire `callAction('scanForUpdates')`, `Queue::assertPushed(ScanShapefileLayersJob::class, fn ($job) => $job->userId === $user->id)`, notification body contains "queue worker").
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the action (move the loop, keep behaviour), make the command call it printing through `$this->info/error/line`, add the job and the header action.
- [ ] **Step 4: Run the new tests and the existing shapefile tests** (`ScanShapefile*`, `ShapefileLayer*`); expect PASS. Pint.
- [ ] **Step 5: Commit** `Shapefiles: scan from the admin panel through a queued job`.

---

### Task 4: Queue heartbeat

**Files:**
- Create: `app/Jobs/RecordQueueHeartbeat.php`, `app/Support/Setup/QueueHeartbeat.php`
- Modify: `bootstrap/app.php` (add to `withSchedule`)
- Test: `tests/Feature/QueueHeartbeatTest.php`

**Interfaces:**
- Produces: `QueueHeartbeat::record(): void` (stores `now()` ISO string under cache key `setup.queue-heartbeat`, forever), `QueueHeartbeat::lastRanAt(): ?Carbon`; queued job `RecordQueueHeartbeat` (default queue) whose `handle()` calls `QueueHeartbeat::record()`; scheduled `->everyMinute()`. Together they prove the scheduler dispatched it and a queue worker ran it.

- [ ] **Step 1: Write the failing tests:** `the job records the time it ran`; `lastRanAt is null before the first run`; `the job is scheduled every minute` (resolve `Schedule` and find the event by the job class).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** both classes and the schedule entry.
- [ ] **Step 4: Run; expect PASS.** Pint.
- [ ] **Step 5: Commit** `Setup: a scheduled queue heartbeat`.

---

### Task 5: The conditions

**Files:**
- Create in `app/Onboarding/Conditions/`: `IsOwnerCondition`, `IsCloudModeCondition`, `FeedProductReceivedCondition`, `ReferenceDataInstalledCondition`, `BoundariesActiveCondition`, `SchedulerAndQueueRunningCondition`, `MailConfiguredCondition`, `PushConfiguredCondition`, `BillingConfiguredCondition`, `SupportWidgetConfiguredCondition`
- Test: `tests/Feature/SetupConditionsTest.php`

**Interfaces:**
- Consumes: `ReferenceDataStatus::allInstalled()` (Task 1), `QueueHeartbeat::lastRanAt()` (Task 4), `PipelineHeartbeat::lastReceivedAt('nwws')`.
- Produces: condition keys (also what Task 6's steps use) `is_owner`, `is_cloud_mode`, `feed_product_received`, `reference_data_installed`, `boundaries_active`, `scheduler_and_queue_running`, `mail_configured`, `push_configured`, `billing_configured`, `support_widget_configured`. Each answers `isCompleted(Model $subject, ?Model $scope = null): bool`:
  - `is_owner`: `$subject->is_owner === true`. `is_cloud_mode`: `config('wxnotify.mode') === 'cloud'` (ignores the subject).
  - `feed_product_received`: a NWWS heartbeat exists. `reference_data_installed`: `ReferenceDataStatus::allInstalled()`.
  - `boundaries_active`: at least one `ShapefileLayer` exists and every one has an `active_version_id`.
  - `scheduler_and_queue_running`: `QueueHeartbeat::lastRanAt()` is within the last 10 minutes.
  - `mail_configured`: `config('mail.default')` is not `log` or `array`.
  - `push_configured`: `config('services.firebase.service_account')` is a readable file AND `config('webpush.vapid.public_key')` and `...private_key` are filled.
  - `billing_configured`: `services.stripe.secret` or `services.paddle.api_key` filled. `support_widget_configured`: `services.chatwoot.base_url` and `.website_token` filled (check the real config keys when implementing).

- [ ] **Step 1: Write the failing tests:** a Pest dataset over every condition with a true case and a false case (set config/cache/rows accordingly), including: no shapefile layers → `boundaries_active` false; a layer without an active version → false; no heartbeat → false; a heartbeat 11 minutes old → false; `config(['mail.default' => 'log'])` → false; each registered key resolves in the plugin's registry (use the plugin's condition registry lookup).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement the ten classes** (each with a `label()` in plain language).
- [ ] **Step 4: Run; expect PASS.** Pint.
- [ ] **Step 5: Commit** `Onboarding: conditions for the owner setup checklist`.

---

### Task 6: The checklist on the Admin panel, shipped by migration

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Create: `database/migrations/2026_10_18_300001_create_owner_setup_journey.php`
- Modify: `.ai/rules/filament.md` (a short note)
- Test: `tests/Feature/OwnerSetupJourneyTest.php`

**Interfaces:**
- Consumes: the condition keys of Task 5; the Setup page URL `/admin/setup` (Task 2); Shapefile Layers at `/admin/shapefile-layers`.
- Produces: the plugin registered on the admin panel (`FilamentOnboardingPlugin::make()->manageFlows()->launcher()->progressPage()->tours(false)->skipWhen(fn (User $user): bool => ! $user->is_owner)`, navigation group `System`); an `OnboardingFlow` with key `owner-setup`, `panel_id` `admin`, `visibility_condition` `is_owner`, dismissible, and `OnboardingStep` rows in this order (key, completion mode, condition key, cta target):
  1. `basics` — manual — Compose/`.env` basics: `APP_KEY`, `APP_URL`, `WXNOTIFY_MODE`, trusted proxies.
  2. `weather-feed` — condition `feed_product_received` — NWWS login in `.env` for the NWWS container.
  3. `reference-data` — condition `reference_data_installed` — cta `/admin/setup`.
  4. `boundaries` — condition `boundaries_active` — cta `/admin/shapefile-layers`; text says the scan runs on the queue (step 5) and imports need review and Activate.
  5. `scheduler-queue` — condition `scheduler_and_queue_running`.
  6. `mail` — condition `mail_configured`.
  7. `push` — condition `push_configured`, `is_required` false — mount the Firebase JSON and set `FIREBASE_SERVICE_ACCOUNT` to its path; generate VAPID keys with `php artisan webpush:vapid`.
  8. `billing` — condition `billing_configured`, `visibility_condition` `is_cloud_mode`.
  9. `support-widget` — condition `support_widget_configured`, `visibility_condition` `is_cloud_mode`.
  Titles and descriptions are plain-language English written by the implementer; each says what WxNotify needs and why. The migration uses `firstOrCreate` by `key` (flow) and by `flow_id` + `key` (steps).

- [ ] **Step 1: Write the failing tests** in `OwnerSetupJourneyTest.php`:
  - `the migration creates the flow and its nine steps in order with their condition keys` (assert keys, `sort_order`, completion modes, that `billing` and `support-widget` carry `is_cloud_mode`, and that every non-null `condition_key` and `visibility_condition` resolves in the condition registry).
  - `running the migration again, or after an owner edited a step title, changes nothing` (re-run `up()`; edited title kept; counts unchanged).
  - `an owner sees the flow, a staff user who is not an owner and a subscriber do not` (`Onboarding::for($user)->flows()` or the plugin's equivalent).
  - `cloud-only steps are hidden when the mode is self-hosted and shown in cloud mode`.
  - `a step ticks itself once its condition is true` (install reference data, then the `reference-data` step reports completed for the owner).
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Register the plugin** in `AdminPanelProvider`, **write the migration**, then migrate the dev database.
- [ ] **Step 4: Run the new tests, then the whole suite in parallel** (`... php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6`); expect PASS. Pint.
- [ ] **Step 5: Browser check** on `http://localhost:8080/admin` as the owner (dev quick-login "Admin (admin@host.com)"; if that account is not an owner, make it one in the local database with `is_owner = true`): the launcher shows, the checklist lists the steps, an untouched step shows its link, the Setup page shows the cards and the Install action works, and as the subscriber ("User") no checklist appears. Report what you saw.
- [ ] **Step 6: Commit** `Admin: an owner setup checklist, shipped by migration`.
