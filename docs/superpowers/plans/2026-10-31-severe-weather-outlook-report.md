# Severe Weather Outlook Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A daily Severe Weather Outlook Report: a clock-triggered alert rule, listed beside SPC & WPC Alerts, that fetches SPC's published outlook files and sends a Brief text (mobile and every non-email channel) or a Full email with graphics.

**Architecture:** A new `AlertCategory::OutlookReport` (`outlook_report`) rule with its own columns (`delivery_time` reused as a UTC send time, new `report_mode`, chosen locations in the existing `alert_rule_location` pivot). A new scheduler command `alerts:dispatch-outlook-reports` finds due rules, fetches SPC GeoJSON once per run (`FetchSpcOutlooks`), builds a small plain-data payload per rule (`BuildOutlookReport`), and queues `DeliverScheduledAlert` jobs (one per channel) exactly like the Daily Summary. Email renders the payload as `OutlookReportMail`; every other channel gets the Brief text.

**Tech Stack:** Laravel 13, PHP 8.4, Filament v5, Pest, PostgreSQL with PostGIS (already used), `Http` facade.

**Spec:** `docs/superpowers/specs/2026-10-30-severe-weather-outlook-report-design.md`. This plan makes two calls beyond it, both flagged for the owner: (1) the option is a new category (`outlook_report`) shown next to "SPC & WPC Alerts" in the category picker, not a sub-type of the product-triggered SPC rule; (2) point-in-polygon runs in PostGIS (`ST_Intersects`, one query per GeoJSON feature), not new PHP geometry code.

## Global Constraints

- Follow `.ai/rules/index.md`: read the rule files matching each path you touch before editing (actions, alerts, app-jobs/jobs, models, filament, pages, pipeline, support, delivery, api, feature).
- Pest feature tests; `Http::fake()` for every SPC call (no real network); fakes never mocked inline when a factory/state exists. Run tests in Docker: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql vendor/bin/pest --compact <paths>'`.
- Run `vendor/bin/pint --format agent` on touched PHP files before each commit.
- Commit trailer exactly `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Do not push. Do not edit `.ai/rules` (the controller records rules).
- No new composer/npm dependencies. No new top-level directories.
- Circle emoji scale: none ⚪, general thunderstorms 🟤, Marginal 🟢, Slight 🟡, Enhanced 🟠, Moderate 🔴, High 🟣.
- Days 4-8: no categories; a 15% area shows 🟡 (Slight), 30% or more shows 🟠 (Enhanced), none ⚪.
- Default send time 14:30 UTC, chosen in UTC at 15-minute steps. A missing Day 1 never stops the report: retry on each tick until send time + 60 minutes, then send with "Day 1 outlook not yet available".
- SPC files (all under `https://www.spc.noaa.gov/products/`): `outlook/day{1,2}otlk_{cat,torn,hail,wind}.nolyr.geojson`; `outlook/day3otlk_cat.nolyr.geojson`, `outlook/day3otlk_prob.nolyr.geojson`; `exper/day4-8/day{4..8}prob.nolyr.geojson`. Graphics: `outlook/day{1,2,3}otlk.png`, `exper/day4-8/day48prob.gif` (full size since 2026-10-31; first built with `_sm.png` / `_small.gif`). Feature properties used: `LABEL` (`TSTM`, `MRGL`, `SLGT`, `ENH`, `MDT`, `HIGH`, or a decimal string such as `0.05`, `0.15`; `Less Than 2% All Areas` means none), `ISSUE_ISO`, `VALID_ISO`, `EXPIRE_ISO`.
- Only the rule's own user's locations; a location without a fix (`Location::hasFix()` false) is skipped.

## Review Focus

Failure modes the spec implies that no single task's happy path covers; each has a test in the named task.

1. SPC returns HTTP 200 with an HTML error page, invalid JSON, or an empty `features` list: HTML/invalid = day unavailable (never "no storms"); empty features = a valid quiet day. (Task 3)
2. A rule with zero chosen locations, a chosen location without a fix, or a location outside the outlook area (Alaska, Hawaii, Puerto Rico): the report still sends; no per-location rows for the first two, "none" for the third. (Task 5)
3. A location on a polygon edge or inside a hole of a MultiPolygon. (Task 4)
4. "Only when something is forecast" on a quiet day advances the once-a-day stamp and sends nothing; with Day 1 unavailable after the grace it sends. (Task 6)
5. A rule edited to a send time still ahead today in UTC runs again today; one edited to a past time does not re-send. (Task 1)

---

### Task 1: Category, columns and model

**Files:**
- Create: `app/Enums/OutlookReportMode.php`, `database/migrations/2026_10_31_100001_add_outlook_report_to_alert_rules.php`
- Modify: `app/Enums/AlertCategory.php`, `app/Models/AlertRule.php`, `database/factories/AlertRuleFactory.php`, and every `match`/list over `AlertCategory` that would throw `UnhandledMatchError` or omit the case: grep `AlertCategory::` in `app/` (known files: `Actions/Alerts/BuildAlertRuleOptions.php`, `Actions/Alerts/GetAlertsNeedingAttention.php`, `Actions/Dashboard/GetAlertDeliveryStats.php`, `Actions/Weather/ComposeTemplatedAlertBody.php`, `Actions/Weather/RenderAlertSubject.php`, `Filament/Admin/Widgets/AlertRulesByCategoryChart.php`, `Filament/Dashboard/Resources/AlertRules/Tables/AlertRulesTable.php`, `Filament/Dashboard/Resources/AlertTemplates/Schemas/AlertTemplateForm.php`, `Http/Resources/Api/V1/AlertResource.php`, `Support/ProductMatching.php`)
- Test: `tests/Feature/OutlookReportRuleTest.php`

**Interfaces:**
- Produces: `AlertCategory::OutlookReport` (value `outlook_report`, label `Severe Weather Outlook Report`, description "A daily overview of severe weather chances over the next week from the SPC outlooks.", icon `heroicon-o-cloud-bolt`), placed right after `SpcWpc` in `cases()` order so the picker lists it beside SPC & WPC; `App\Enums\OutlookReportMode` string enum `EveryDay = 'every_day'`, `OnlyWhenForecast = 'only_when_forecast'` with `label()`; column `alert_rules.report_mode` (string, nullable); `AlertRule` cast `report_mode` => `OutlookReportMode::class`, fillable `report_mode`; `AlertRule::outlookSendTime(): string` returning the `H:i` of `delivery_time` (default `14:30`); `AlertRuleFactory::outlookReport()` state (category, `delivery_time` `14:30`, `report_mode` every_day, no `location_id`).

- [ ] **Step 1: Write failing tests** in `OutlookReportRuleTest.php`: (a) `AlertRule::factory()->outlookReport()->create()` stores category and mode, `displayName()` is the label "Severe Weather Outlook Report" when unnamed and the name when set; (b) the `booted` update hook uses UTC for this category: with `Carbon::setTestNow('2026-11-02 15:00:00 UTC')`, a rule stamped `last_scheduled_run_on` today whose `delivery_time` is changed to `16:00` has its stamp cleared, and changed to `09:00` keeps it (use a user whose profile timezone is far from UTC so a local-time implementation would fail); (c) `AlertCategory::cases()` order puts `OutlookReport` immediately after `SpcWpc`; (d) every case of `AlertCategory` renders without exception through `AlertRulesTable`'s category column and `RenderAlertSubject`.
- [ ] **Step 2: Run to confirm failure.**
- [ ] **Step 3: Implement** the enum cases, migration (adds `report_mode` string nullable), cast/fillable, factory state, and `AlertRule::booted()` change: for `OutlookReport` compute `$now` in `UTC` (not `Timezones::forRule`). `displayName()` gets an `OutlookReport` arm. Add the case to every match found by the grep, choosing behaviour by analogy with `DailySummary` (scheduled, no product, no template/subject template: `clearAlertTemplateIfCategoryUnsupported` already clears templates for categories other than the two product ones).
- [ ] **Step 4: Run the new test plus `AlertRule*`, `RenderAlertSubject*`, `GetAlertsNeedingAttention*` tests; all pass.**
- [ ] **Step 5: Pint, commit** "Alerts: an outlook report rule category".

### Task 2: Save path, validation, API gating

**Files:**
- Modify: `app/Actions/Alerts/SaveAlertRule.php`, `app/Http/Requests/SaveAlertRuleRequest.php`, `app/Actions/Alerts/BuildAlertRuleOptions.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/EditAlertRule.php` (hydrate) as needed
- Test: `tests/Feature/OutlookReportRuleTest.php` (extend), `tests/Feature/AlertRuleParityTest.php` (extend)

**Interfaces:**
- Consumes: Task 1 enum/columns.
- Produces: `SaveAlertRule::__invoke` accepts, for category `outlook_report`, keys `delivery_time` (`H:i`), `report_mode`, `location_ids` (list<int>, owner-scoped) and `channel_ids`; it persists `delivery_time`, `report_mode`, clears every other category column, syncs `alert_rule_location` from `location_ids` limited to the owner's locations, and clears `report_mode` for every other category. API v1: `category=outlook_report` is refused on write (422 on `category`) and omitted from the `categories` list of `BuildAlertRuleOptions` (the mobile app has no editor for it yet); reading such a rule through the API still works.

- [ ] **Step 1: Failing tests:** saving through `SaveAlertRule` creates the rule with the time, mode and only the owner's locations (another user's location id is dropped); switching an existing outlook rule to `daily_summary` clears `report_mode` and the locations pivot; `POST /api/v1/alert-rules` with `category=outlook_report` returns 422; `GET /api/v1/alert-rules/options` has no `outlook_report` category; `GET /api/v1/alert-rules` lists a dashboard-made outlook rule with `category` `outlook_report` and a `category_label`; add a `AlertRuleParityTest` case for the new category's persisted columns.
- [ ] **Step 2: Run to confirm failure.**
- [ ] **Step 3: Implement** in `SaveAlertRule`: add `'outlook_report' => ['delivery_time', 'report_mode']` to `CATEGORY_COLUMNS` and `report_mode` to `ALL_CATEGORY_COLUMNS`; in `syncRelations()` write `alert_rule_location` for this category from `location_ids` (the existing code empties that pivot for non-product rules: keep emptying it for every category except this one). `SaveAlertRuleRequest`: `Rule::enum(AlertCategory::class)->except([AlertCategory::OutlookReport])`, so the API refuses it. `BuildAlertRuleOptions`: filter `OutlookReport` out of `categories`.
- [ ] **Step 4: Run the touched tests plus `AlertRule*Api*`, `AlertRuleParityTest`; all pass.**
- [ ] **Step 5: Pint, commit** "Alerts: save an outlook report rule; the API leaves it to the dashboard".

### Task 3: Dashboard form

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` (add `outlookReportFields()` and the `detailsStep()` arm), `app/Filament/Dashboard/Resources/AlertRules/Pages/EditAlertRule.php` and `CreateAlertRule.php` for `location_ids` hydrate/dehydrate if the product-rule pattern needs a counterpart (follow `.ai/rules/filament.md`)
- Test: `tests/Feature/OutlookReportRuleFormTest.php`

**Interfaces:**
- Consumes: Task 2 save keys.
- Produces: `AlertRuleForm::outlookReportFields(): array<Component>`: `TimePicker::make('delivery_time')` labelled "Send time (UTC)", default `14:30`, `seconds(false)`, `minutesStep(15)`, required, helper text saying SPC's 1300 UTC update posts about 13:00 and showing that the report waits up to 60 minutes for it; `Radio::make('report_mode')` options `OutlookReportMode`, default `every_day`; `CheckboxList::make('location_ids')` (native, not a searchable Select: the form comment on `locationSelect()` explains the entangle bug) of the user's own locations that have a fix, optional, helper "Leave empty for a US-wide report".

- [ ] **Step 1: Failing Livewire tests** (`livewire(CreateAlertRule::class)`): choosing the category shows the three fields and not the product table; submitting with defaults creates a rule with `delivery_time` 14:30, `report_mode` every_day and the chosen locations; `EditAlertRule` loads and saves a changed time/mode/locations; another user's location is not offered.
- [ ] **Step 2: Run to confirm failure.**
- [ ] **Step 3: Implement** the fields and the `detailsStep()` match arm; message style / template steps already adapt per category (check `message_body_style` field is hidden or harmless: this category's Brief/Full follows the channel, not the rule).
- [ ] **Step 4: Run the new test plus existing `CreateAlertRule*`/`EditAlertRule*` tests.**
- [ ] **Step 5: Pint, commit** "Dashboard: create and edit an outlook report rule".

### Task 4: Fetching SPC outlooks

**Files:**
- Create: `app/Enums/OutlookCategory.php`, `app/Actions/Weather/FetchSpcOutlooks.php`, `app/Support/Day1OutlookFreshness.php`
- Test: `tests/Feature/FetchSpcOutlooksTest.php`, `tests/Unit/...` not needed

**Interfaces:**
- Produces: `App\Enums\OutlookCategory` (cases `None`, `Thunderstorm`, `Marginal`, `Slight`, `Enhanced`, `Moderate`, `High`; `emoji(): string`, `label(): string`, `rank(): int`; `static fromLabel(string $label): self` for `TSTM|MRGL|SLGT|ENH|MDT|HIGH`, unknown = None; `static fromProbability(float $probability): self` = 0.30+ Enhanced, 0.15+ Slight, else None). `FetchSpcOutlooks::__invoke(): array` returning `array{day_1: ?array, day_2: ?array, day_3: ?array, day_4: ?array, ..., day_8: ?array}`; each present entry is `array{issued_at: ?string, last_modified: ?string, layers: array<string, list<array{label: string, geometry: array<string, mixed>}>>}` with layer keys `cat`, `torn`, `hail`, `wind`, `prob` (only those the day has); a day is `null` when no file for it could be fetched and parsed. Requests go out together with `Http::pool`, `timeout(8)`, no redirects, User-Agent as `GetRadarSnapshot`; the whole result is cached for 2 minutes with `Cache::remember('spc-outlooks', ...)` but a result with any null day is not cached. `Day1OutlookFreshness::isCurrent(?array $day1, CarbonInterface $sendTime): bool`: the expected cycle is the latest of 01:00, 06:00, 13:00, 16:30, 20:00 UTC at least 15 minutes before `$sendTime` (previous day's 20:00 if none today); current when `issued_at` (else `last_modified`, else, when neither is readable, treated as current) is at or after that cycle minus 15 minutes; `null` day 1 is not current.

- [ ] **Step 1: Failing tests** with `Http::fake` URL patterns: a full happy fetch parses categories, hail/wind/tornado and Day 4-8 into the shape above; HTML body with status 200 and invalid JSON make that file's day `null`/layer absent (Review Focus 1); an empty `features` list yields a day with empty layers and a `last_modified` header value; `Less Than 2% All Areas` yields no tornado layer entry; one failed file does not null the whole day's other layers; a result with a null day is not cached (second call refetches), a complete one is; `OutlookCategory::fromLabel/fromProbability` table; `Day1OutlookFreshness` table for send times 14:30 (needs 13:00), 08:00 (needs 06:00), 00:30 (needs previous day 20:00), a quiet day with only `last_modified`, and `null`.
- [ ] **Step 2: Run to confirm failure.**
- [ ] **Step 3: Implement.** Parse with `json_decode`; require `type === 'FeatureCollection'` and an array `features`; keep only `LABEL` and `geometry` per feature; take `issued_at` from the first feature's `ISSUE_ISO` of the day's `cat` (day 3 `prob`, days 4-8 `prob`) layer; read `Last-Modified` from the `cat` response.
- [ ] **Step 4: Run the new tests; pass.**
- [ ] **Step 5: Pint, commit** "Weather: fetch SPC convective outlooks for the report".

### Task 5: Locations in outlook areas and the report payload

**Files:**
- Create: `app/Actions/Weather/LocationsInOutlookAreas.php`, `app/Actions/Weather/BuildOutlookReport.php`, `app/Support/OutlookReportText.php`
- Test: `tests/Feature/BuildOutlookReportTest.php`

**Interfaces:**
- Consumes: `FetchSpcOutlooks` result (Task 4), `OutlookCategory`.
- Produces: `LocationsInOutlookAreas::__invoke(array $geometry, Collection $locations): list<int>`: ids of the locations (models with `lat`/`lng`) inside or on the edge of one GeoJSON geometry, one SQL statement using `ST_Intersects(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ST_SetSRID(ST_MakePoint(lng, lat), 4326))` over a `VALUES`/`unnest` list of the points; handles MultiPolygon and holes (reuse the SQL style of `GetActiveAlertsForLocation`). `BuildOutlookReport::__invoke(array $outlooks, Collection $locations, ?IngestedProduct $pwo = null): array` returning the plain-data payload:

```
['days' => [1 => ['available' => bool, 'issued_at' => ?string, 'category' => 'slgt',
                  'hail' => ?string, 'wind' => ?string, 'tornado' => ?string, 'any_severe' => ?string,
                  'locations' => [['label' => string, 'category' => 'mrgl', 'hail' => ?string, 'wind' => ?string, 'tornado' => ?string]]], 2 => ..., 3 => ...],
 'extended' => [4 => ['available' => bool, 'probability' => ?string, 'category' => 'slgt', 'highlight' => bool, 'locations' => list<string>], ... 8 => ...],
 'pwo' => ?['title' => string, 'text' => string, 'issued_at' => string],
 'something_forecast' => bool, 'day1_missing' => bool]
```
Category/level values are `OutlookCategory` values and strings like `15%`; the max hail/wind/tornado is the highest-numbered layer label present. `something_forecast` is true when any of days 1-3 has category rank >= Marginal or any of days 4-8 has a probability >= 0.15, ignoring unavailable days. `highlight` is true for a day 4-8 with probability >= 0.15. `pwo` is included only when Day 1's category is Moderate or High and the supplied product exists. `OutlookReportText::subject(array $payload): string` ("Severe Wx Report - 🟠 D1 | 🟡 D2 | 🟤 D3 | ⚪ D4 | … | ⚪ D8"; an unavailable day shows `❔`) and `OutlookReportText::brief(array $payload): string` (`D1 🟠  D2 🟡  D3 🟤  D4-8 🟡`, the last being the highest rank among days 4-8; Day 1 unavailable shows `D1 ❔`).

- [ ] **Step 1: Failing tests:** payload for a fixture outlook set (Slight Day 1 with a 15% hail layer and a 2% tornado, Marginal Day 2, TSTM Day 3 prob 5%, a 15% Day 5, rest none) with two locations (one inside Slight, one outside everything, one with no fix skipped): exact categories, levels, per-location rows, highlight on D5 with the location listed when inside; zero chosen locations gives empty `locations` lists; a location in Hawaii gets category `none`; edge and hole cases for `LocationsInOutlookAreas` (Review Focus 3); unavailable Day 1 sets `day1_missing`, category absent and `❔` in subject/brief; `something_forecast` truth table; PWO only for Moderate/High; subject and brief strings exactly as above.
- [ ] **Step 2: Run to confirm failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run; pass.**
- [ ] **Step 5: Pint, commit** "Weather: the outlook report payload and text".

### Task 6: Dispatch command and schedule

**Files:**
- Create: `app/Console/Commands/DispatchOutlookReports.php`, `app/Actions/Weather/FindActivePwo.php`
- Modify: `bootstrap/app.php` (schedule), `app/Jobs/DeliverScheduledAlert.php`
- Test: `tests/Feature/DispatchOutlookReportsTest.php`

**Interfaces:**
- Consumes: Tasks 1, 4, 5.
- Produces: command `alerts:dispatch-outlook-reports`, scheduled `->everyFifteenMinutes()->withoutOverlapping(30)`. `FindActivePwo::__invoke(): ?IngestedProduct`: the newest non-test `IngestedProduct` whose product code is `PWO` (find the catalog `Product` by code) received on or after the current Day 1 outlook's issue day (UTC date of the newest `SPC.OUTLOOK` product), else null. The command, per run: select active `outlook_report` rules with `delivery_time` set, not stamped for today's UTC date, whose send time today (UTC) has passed; if none, exit without fetching; fetch once; for each due rule: if Day 1 is not current (`Day1OutlookFreshness::isCurrent` against that rule's send time) and now is before send time + 60 minutes, skip it unstamped (retried next tick); build the payload with the rule's locations (those with a fix); if `report_mode` is `only_when_forecast` and not `something_forecast` and Day 1 is not missing, stamp `last_scheduled_run_on` (UTC date) and send nothing; otherwise stamp and dispatch one `DeliverScheduledAlert` per channel with `$title` = `OutlookReportText::subject`, `$body` = `OutlookReportText::brief`, `$report` = payload only if any attached channel's type `supportsRichContent()` (as `DispatchScheduledAlerts::reportFor` does), one shared `batchKey`. `DeliverScheduledAlert::handle`: for category `OutlookReport` use `$this->title` as the title (not `RenderAlertSubject`), put the report in `$meta['outlook_report']` (not `daily_summary`) for rich channels, and no Str::limit change needed (the Brief is short).

- [ ] **Step 1: Failing tests** (`Carbon::setTestNow`, `Http::fake` of SPC, real `AlertRule`/channel factories, `Queue::fake()` asserting pushed `DeliverScheduledAlert` args — unique locks are not involved here): sends once at/after the send time and not before; a second run the same UTC day sends nothing; a stamp from yesterday sends; Day 1 stale at 14:35 with send time 14:30 sends nothing and leaves the stamp; same at 15:31 sends with `day1_missing` in the payload (Review Focus 4); `only_when_forecast` on an all-quiet fixture stamps without dispatch, and with Day 1 unavailable after the grace dispatches; inactive rule skipped; no fetch at all when no rule is due (`Http::assertNothingSent`); a rule with a mobile and an email channel gets payload `null` for the mobile job's body only through the job (assert the report is passed for the rich-content channel and the Brief body is the same for both); one batch key across channels; an SPC outage (everything 500) after the grace still sends the "unavailable" report with all days `❔`.
- [ ] **Step 2: Run to confirm failure.**
- [ ] **Step 3: Implement the command, `FindActivePwo`, the schedule line and the `DeliverScheduledAlert` category branch.**
- [ ] **Step 4: Run the new tests plus `DeliverScheduledAlert*`, `DispatchScheduledAlerts*` and `DeliveryQueueTest`; pass.**
- [ ] **Step 5: Pint, commit** "Alerts: dispatch the daily outlook report".

### Task 7: The Full email

**Files:**
- Create: `app/Mail/OutlookReportMail.php`, `app/Support/OutlookReportPresenter.php`, `app/Actions/Weather/GetSpcOutlookGraphic.php`, `resources/views/mail/outlook-report.blade.php`, `resources/views/mail/outlook-report-text.blade.php`, `resources/views/mail/partials/outlook-report.blade.php`
- Modify: `app/Support/Delivery/Transports/MailTransport.php`, `app/Jobs/DeliverScheduledAlert.php`
- Test: `tests/Feature/OutlookReportMailTest.php`

**Interfaces:**
- Consumes: payload (Task 5), `$meta['outlook_report']` (Task 6). Pattern: `DailySummaryMail`, `DailySummaryPresenter`, `GetRadarSnapshot`.
- Produces: `GetSpcOutlookGraphic::__invoke(string $key): ?array{bytes: string, mime: string}` with `$key` one of `day1`, `day2`, `day3`, `day48` mapped to the fixed graphic URLs in Global Constraints (no other input reaches a URL), host fixed, no redirects, status/MIME/magic bytes/size (<= 1 MiB) validated, cached 2 minutes as base64 like `GetRadarSnapshot`. `OutlookReportPresenter::present(array $payload): array` and `::text(array $view): string`. `OutlookReportMail::__construct(?string $messageTitle, array $report, string $briefText, array $images)` with `$images` keyed `day1..day3, day48` embedded as `cid:spc-day1` etc. `DeliverScheduledAlert::handle` fetches the four graphics for rich channels and passes them in `$meta['outlook_report_images']` (bytes never go on the queue). `MailTransport::mailable()` renders `OutlookReportMail` when `$meta['outlook_report']` is an array, falling back to `ChannelMessageMail` with the Brief on any render failure (fixed log text, as the daily summary does).

- [ ] **Step 1: Failing tests:** the rendered HTML has, per day 1-3, the graphic `<img src="cid:spc-dayN">`, the category with its circle, hail/wind/tornado levels, and each chosen location's row; days 4-8 lines with highlighted days and locations inside; a PWO section only when the payload has one; a missing Day 1 shows "Day 1 outlook not yet available"; the plain-text part contains the same facts; a graphic fetch that fails drops the `<img>` but still sends; `GetSpcOutlookGraphic` rejects an unknown key, a non-image body, a redirect and an oversized body; a render error falls back to the Brief mail and logs no exception message; end to end through `DeliverScheduledAlert` with `Mail::fake()` for an email channel and with a push-like channel receiving only the Brief text.
- [ ] **Step 2: Run to confirm failure.**
- [ ] **Step 3: Implement** (collapse the section HTML to one line as `DailySummaryMail::collapse` does).
- [ ] **Step 4: Run the new tests plus `DailySummary*Mail*` and `MailTransport*` tests; pass.**
- [ ] **Step 5: Pint, commit** "Delivery: the Full outlook report email".

### Task 8: Mobile Brief and whole-feature check

**Files:**
- Test: `tests/Feature/OutlookReportMobileBriefTest.php`

**Interfaces:**
- Consumes: Tasks 1-7.
- Produces: no new code unless the test finds a gap.

- [ ] **Step 1: Write the test:** a rule with a verified `mobile_push` channel (fake the FCM transport the way existing `MobilePush` tests do) run through `alerts:dispatch-outlook-reports` and `queue:work --queue=deliveries,default`: the push carries the Brief text and the subject as title, the delivery log row has `category` `outlook_report`, no product, a `batch_key`, and the `GET /api/v1/alerts` feed lists it as an alert whose link opens the Alerts page (follow the existing no-product alert tests for the exact response keys).
- [ ] **Step 2: Run it. If it fails because of a gap, fix that gap in the smallest place and note it in the report.**
- [ ] **Step 3: Run the whole feature's tests together and then the full suite in parallel** (`php -d memory_limit=-1 vendor/bin/pest --parallel --processes=6`); report any failure.
- [ ] **Step 4: Pint, commit** "Tests: the outlook report reaches the mobile feed".

## Follow-ups outside this plan (for the controller to raise with the owner)

- The mobile app (`wxnotify-mobile`) has no editor for `outlook_report`; the API refuses writes of it and hides it from the options list until the app supports it. A rule made on the dashboard still lists in the app (its `category_label` is shown) but cannot be edited there.
- No monitoring of the scheduler for this command beyond the existing heartbeat.
- A plain-text Full for chat channels (Slack, Discord, …) is not built; they get the Brief.
- `.ai/rules` entries for: the category and its UTC schedule (alerts/models), the fetch/payload design (pipeline or support), `DeliverScheduledAlert`'s `outlook_report` meta (app-jobs/jobs).
