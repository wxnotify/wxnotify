# No tiers or limits when self-hosting: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When `WXNOTIFY_MODE=selfhost`, every account is unlimited and sees every feature, and the admin UI shows no tier or threshold controls. Cloud mode is unchanged.

**Architecture:** One static switch, `User::hasTiers()` (false in self-host mode). `User::tier()` returns `Tier::Pro` when it is false, so every existing "is this Free?" gate opens; every limit method returns `null` (unlimited) when it is false. Callers that print or compare a limit learn to handle `null`. The admin hides the tier controls when it is false; the lightning minimum radius, which is not a tier limit, stays editable.

**Tech Stack:** Laravel 13, Filament v5, spatie/laravel-settings, Pest (run inside the dev container: `docker compose exec -T front-end php artisan test --compact <file>`), Pint.

**Spec:** `docs/superpowers/specs/2026-11-23-selfhost-no-tiers-design.md`

## Global Constraints

- Mode switch: `config('wxnotify.mode') === 'selfhost'` (config key `wxnotify.mode`, default `cloud`). Tests set it with `config(['wxnotify.mode' => 'selfhost'])`.
- Cloud mode is bit-for-bit unchanged: every existing test passes untouched except where a signature widens (`alertRuleLimit(): ?int`).
- In self-host mode these are unlimited: saved locations, phone locations, alert rules, Traccar servers, Traccar devices per server, free no-cost channel slots; and these open: custom subjects and templates, message styles, alert templates, Traccar servers, the one-Outlook-Report cap, channel catalog `min_tier`.
- Tier overrides and threshold settings stay in the database untouched and are ignored while self-hosted.
- `/api/v1/me` reports `tier` `{value: "pro", label: "Pro"}` in self-host mode (falls out of `tier()`; `MeResource` needs no change).
- The lightning minimum radius setting (`lightning_min_radius_miles`, 5-15) stays visible and editable in both modes; it is a detection-accuracy floor.
- No new dependencies, no new base folders. Follow `.ai/rules` for the paths touched; Pint after PHP edits.

## Review Focus

- A cloud Free user is unchanged (a cloud-mode regression test per gate group: alert limit, location limit, Traccar, channel slots).
- Saving the admin App settings in self-host mode must not error or alter the hidden tier threshold values (hidden fields are absent from the form state; the settings are only filled from keys present).
- A self-host install whose users have `tier_override` rows still treats them as unlimited, and the rows are untouched (switch back to cloud mode: the override applies again).
- The API options payload in self-host mode: `alert_limit` is `null`, `can_create_alert` and `can_create_outlook_report` are true; the My Alerts page description shows no "N of M".
- The create paths (dashboard, API, model guard) for a self-host user who already has more alerts than any cloud limit.

---

### Task 1: The switch, unlimited limits and null-safe callers

**Files:**
- Modify: `app/Models/User.php`, `app/Models/AlertRule.php`, `app/Actions/Alerts/SaveAlertRule.php`, `app/Actions/Alerts/BuildAlertRuleOptions.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/ListAlertRules.php`, `app/Exceptions/AlertRuleLimitReached.php` only if it needs a nullable-free signature (keep `limit(int $limit)`)
- Test: `tests/Feature/SelfHostNoTiersTest.php` (new, `php artisan make:test --pest SelfHostNoTiersTest`)

**Interfaces:**
- Produces: `User::hasTiers(): bool` (static; true unless `config('wxnotify.mode') === 'selfhost'`). `User::tier(): Tier` returns `Tier::Pro` when `! static::hasTiers()`, else `$this->tier_override ?? Tier::Free`. `User::alertRuleLimit(): ?int` and the existing `locationLimit()`, `phoneLocationLimit()`, `traccarServerLimit()`, `traccarDeviceLimitPerServer()` return `null` when `! static::hasTiers()`. `canCreateAlertRule(): bool` is true when the limit is `null`. `canAddChannel()` needs no change (it already opens for any tier other than Free).
- Consumes: Task-2 of the alert-limits work (`AlertRuleLimitReached::limit(int)`, the model guard in `AlertRule::guardPlanLimits()`, `SaveAlertRule::attributes()`, the options payload keys `alert_limit`, `alert_count`, `can_create_alert`, `can_create_outlook_report`).

- [ ] **Step 1: Write the failing tests** in `SelfHostNoTiersTest.php` (factories only):
  - `in self-host mode tier() is Pro whatever the override`: a user with `tier_override` Free and one with null both report `Tier::Pro`; `User::hasTiers()` is false.
  - `in cloud mode tier() is unchanged`: Free by default, the override when set.
  - `every limit is null in self-host mode`: the five limit methods return null, `canCreateAlertRule()`, `canCreateLocation()`, `canCreatePhoneLocation()`, `canCreateTraccarServer()` are true even for a user with 50 alert rules / many locations created with the cloud limits lowered to 1.
  - `a self-host user can create alert rules past the cloud limit and a second Outlook Report` (create 5 rules incl. two `outlookReport()` factory rules, no exception).
  - `the options payload says unlimited in self-host mode`: `data.alert_limit` null, `can_create_alert` true, `can_create_outlook_report` true (use the options-call helper from `tests/Feature/ApiAlertRuleOptionsTest.php` as a model).
  - `the My Alerts page shows no limit in self-host mode`: Livewire `ListAlertRules`, `assertDontSee` "of" count text ("You're using") and the New Alert action is enabled.
  - `switching back to cloud mode restores the override`: a user with `tier_override` Plus is Pro-by-mode in self-host, and Plus again in cloud mode.
  - one cloud-mode regression each for the alert limit and the location limit (Free user at the limit is refused).
- [ ] **Step 2: Run, expect FAIL** (`php artisan test --compact tests/Feature/SelfHostNoTiersTest.php`).
- [ ] **Step 3: Implement** as in Interfaces. `alertRuleLimit()` returns `null` first when `! static::hasTiers()`; widen its return type to `?int` and its callers: the model guard in `AlertRule` (a null limit means no refusal), `SaveAlertRule::attributes()`, `BuildAlertRuleOptions` (`alert_limit` null), `CreateAlertRule::beforeValidate()`, `ListAlertRules` (the tooltip is only built when `canCreateAlertRule()` is false; `pageDescription()` omits the "You're using N of M alerts." sentence when the limit is null). Update the docblocks that say "tier allows". Do not touch `MeResource`.
- [ ] **Step 4: Run, expect PASS**; then run the affected existing files: `AlertLimitSettingsTest`, `AlertRuleLimitTest`, `AlertRuleLimitUiTest`, `ApiAlertRuleOptionsTest`, `TierThresholdWiringTest`, `PhoneLocationClassGuardTest`, `ApiLocationSharingTest`, `LocationResourceTest`, `OutlookReportRuleTest`.
- [ ] **Step 5: Pint and commit** (`Self-host: no tiers, every limit unlimited`).

---

### Task 2: Hide the tier controls in the admin

**Files:**
- Modify: `app/Filament/Admin/Pages/AppSettings.php`, `app/Filament/Admin/Resources/Users/Schemas/UserForm.php`, `app/Filament/Admin/Resources/Users/Tables/UsersTable.php`, `app/Filament/Admin/Resources/Announcements/Schemas/AnnouncementForm.php`
- Test: `tests/Feature/SelfHostAdminUiTest.php` (new)

**Interfaces:**
- Consumes: `User::hasTiers()` (Task 1).
- Produces: when `! User::hasTiers()`: the Tier override select and Reason textarea on the user form are hidden; the Tier column and the `tier_override` filter on the users table are hidden; the "Tier & threshold defaults" tab on App settings is hidden except that the lightning radius lives in a visible "Thresholds" `Section` placed on the "General" tab (or another always-visible tab) in both modes; the announcement scope select omits `AnnouncementScope::SpecificTiers` and the `tiers` checklist is hidden.

- [ ] **Step 1: Write the failing tests** (Livewire as in `AppSettingsPageTest`, `UserResourceTest`, and the announcement resource tests; set `config(['wxnotify.mode' => 'selfhost'])` per test): the App settings page does not show "Tier & threshold defaults" or the plan limit fields in self-host mode and does in cloud mode; the lightning radius field is present in both modes; saving the App settings in self-host mode succeeds and leaves `free_alerts`, `plus_locations` etc. at their stored values (set them to non-default values first and assert unchanged); the user edit form has no `tier_override` / `tier_override_reason` fields in self-host mode (`assertFormFieldIsHidden` or `assertFormFieldDoesNotExist`) and has them in cloud mode; the users table has no tier column in self-host mode; the announcement form scope options exclude "Specific tiers" in self-host mode and include it in cloud mode.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** with `->visible(fn (): bool => User::hasTiers())` on the tab, fields and column, and a conditional options list for the scope select. Move the lightning radius `Section::make('Thresholds')` out of the tier tab to an always-visible tab, keeping its field name, helper text and rule (`count('lightning_min_radius_miles', ..., 5, 15)`).
- [ ] **Step 4: Run, expect PASS**, plus `AppSettingsPageTest`, `UserResourceTest` and the announcement resource tests.
- [ ] **Step 5: Pint and commit** (`Self-host: hide tier controls in the admin`).

---

### Task 3: Docs, project rule and the full run

**Files:**
- Modify: `deploy/README.md` (section "Self-hosted mode and tiers"), `.ai/rules/models.md` via `mcp__laravel-boost__record-rule` (glob `app/Models/**`, title "Self-hosted mode has no tiers: User::tier() is Pro and the limits are null"), `.ai/rules/alerts.md` only if a statement is now wrong (grep for "Free" / "paid")
- Test: none new

- [ ] **Step 1:** Rewrite the `deploy/README.md` section: rename to "Self-hosted mode"; drop the **Tiers.** paragraph and the "By default 3 alerts…" sentence and say self-hosted instances have no tiers, plans or limits (every account has every feature; Billing stays hidden); mention that existing tier overrides and threshold values are kept but ignored while `WXNOTIFY_MODE=selfhost`. Fix the in-page anchor links (`#self-hosted-mode-and-tiers` is referenced in the README: grep and update every link) and run `bash deploy/verify.sh`.
- [ ] **Step 2:** Record the model rule (what `hasTiers()`/`tier()`/null limits do, that cloud is unchanged, that new limits must return `null` when `! User::hasTiers()` and new Free-only gates must go through `tier()` so self-hosters get them for free, and that the lightning radius is not a tier limit).
- [ ] **Step 3:** Run the full suite in parallel in the app container per the project memory ("Run the full suite in parallel": 6 processes), read the tail, report any failure by name.
- [ ] **Step 4:** Commit docs and rules (`Self-host: docs and project rule`).
