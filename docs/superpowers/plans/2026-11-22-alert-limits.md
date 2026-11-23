# Alert limits per tier, and the Outlook Report for Free: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cap the number of alert rules per tier (Free 3, Plus 5, Pro 10, editable by self-hosters), and let Free subscribers have one Severe Weather Outlook Report that counts as one of their alerts.

**Architecture:** Mirror the location limit: numbers in `TierThresholdSettings`, `User::alertRuleLimit()` / `canCreateAlertRule()`, a guard in `AlertRule`'s `creating`/`updating` hooks so no path can skip it, a friendly pre-check in `SaveAlertRule::attributes()` (the dashboard form and API both go through it), and dashboard/API affordances. The Free-only gates on the Outlook Report are removed everywhere.

**Tech Stack:** Laravel 13, Filament v5, spatie/laravel-settings, Pest (run inside the dev container: `docker compose exec -T front-end php artisan test --compact <file>`), Pint.

**Spec:** `docs/superpowers/specs/2026-11-22-alert-limits-design.md`

## Global Constraints

- Defaults: Free 3, Plus 5, Pro 10 alert rules. Pro is a number, not unlimited.
- Paused (`is_active = false`) rules count toward the limit.
- Someone over their limit keeps every alert, active and editable; only creating is refused. No data migration, nothing deleted, paused or stopped.
- A Free subscriber may have at most one Outlook Report (`category = outlook_report`), whether by creating one or by switching an existing alert's category to it. Plus and Pro have no Outlook cap.
- Editing an existing Outlook Report (category unchanged) is never refused, for any tier.
- Enforced on the server at every path. The API already refuses the `outlook_report` category on write for everyone (`SaveAlertRuleRequest`); leave that unchanged (the mobile app does not create Outlook Reports).
- The "Available on paid plans." message and `SaveAlertRule::PAID_ONLY_MESSAGE` go away.
- Follow `.ai/rules` (alerts, models, filament, app-jobs/commands), `php artisan make:` for new files, `--no-interaction`, and run `vendor/bin/pint --dirty --format agent` after PHP edits.

## Review Focus

- A Free user with 7 existing alerts can edit, pause/resume and delete them, but cannot create an 8th (guard must look only at creation).
- A Free user with one Outlook Report edits that report (category unchanged) without being refused.
- A Free user with an Outlook Report cannot switch another alert's category to Outlook; a Free user with none can.
- A paused alert still counts toward the limit.
- A downgraded subscriber's two existing Outlook Reports both send (Free skip removed) but they cannot create a third.
- Two simultaneous creates at limit minus one produce only one rule (owner-row lock).

---

### Task 1: Settings, limits on User, and the App settings section

**Files:**
- Create: `database/settings/2026_11_22_200001_add_alert_limits.php` (via `php artisan make:settings-migration` if available, else copy the shape of `database/settings/2026_10_19_100001_add_phone_location_allowance.php`)
- Modify: `app/Settings/TierThresholdSettings.php`, `app/Models/User.php`, `app/Filament/Admin/Pages/AppSettings.php`
- Test: `tests/Feature/AlertLimitSettingsTest.php` (new); extend `tests/Feature/TierThresholdWiringTest.php` only if its helpers fit

**Interfaces:**
- Produces: `TierThresholdSettings::$free_alerts` (3), `$plus_alerts` (5), `$pro_alerts` (10), all `int`. `User::alertRuleLimit(): int`, `User::canCreateAlertRule(): bool` (true when `alertRules()->count() < alertRuleLimit()`, paused rules included), `User::canCreateOutlookReport(): bool` (true for Plus and Pro; for Free true only when the user has no `outlook_report` rule).

- [ ] **Step 1: Write the failing tests** in `AlertLimitSettingsTest.php` (Pest feature tests, factories only, `tier_override` set with `Tier::Free|Plus|Pro`):
  - `defaults are 3, 5 and 10`: `alertRuleLimit()` is 3/5/10 for Free/Plus/Pro.
  - `a changed setting changes the limit`: set `free_alerts = 1`, save, `alertRuleLimit()` is 1.
  - `paused alerts count toward the limit`: Free user with 3 rules, one `is_active = false`, `canCreateAlertRule()` is false.
  - `a Free user with 7 alerts cannot create, has room again under the limit` (delete down to 2 → true).
  - `canCreateOutlookReport`: Free with none → true; Free with one → false; Plus with two → true.
  - `the App settings page saves the alert limits and rejects Plus below Free`: Livewire test of `App\Filament\Admin\Pages\AppSettings` as an owner/staff user as `tests/Feature/AppSettingsPageTest.php` already does; fill `free_alerts`, `plus_alerts`, `pro_alerts`, call `save`, assert settings; and `plus_alerts` < `free_alerts` gives a form error.
- [ ] **Step 2: Run, expect FAIL** (`php artisan test --compact tests/Feature/AlertLimitSettingsTest.php`).
- [ ] **Step 3: Implement.** Settings migration adds the three keys to group `tier_threshold` (down deletes them). Add the three `public int` properties. In `User`, add the three methods next to `locationLimit()`/`canCreateLocation()`, using `match ($this->tier())`. In `AppSettings`, add `Section::make('Alerts')->columns(3)` to the "Tier & threshold defaults" tab with `$this->count('free_alerts', 'Free', 1, 100)`, `plus_alerts` (`->gte('free_alerts')`), `pro_alerts` (`->gte('plus_alerts')`), description "How many alert rules each plan can have. A Free plan can have at most one Severe Weather Outlook Report, which counts toward its limit." Run the settings migration in the test DB the way the other settings migrations are (they run with the suite).
- [ ] **Step 4: Run, expect PASS.** Also run `tests/Feature/AppSettingsPageTest.php` and `tests/Feature/TierThresholdWiringTest.php`.
- [ ] **Step 5: Pint and commit** (`Alert limits: per-tier settings, User limits and the App settings section`).

---

### Task 2: The guard and the save-time messages

**Files:**
- Create: `app/Exceptions/AlertRuleLimitReached.php` (`class AlertRuleLimitReached extends RuntimeException`) with `public static function limit(int $limit): self` ("You have reached your plan's limit of N alerts. Remove one or upgrade to add more.") and `public static function outlook(): self` ("Your plan includes one Severe Weather Outlook Report, and you already have one.")
- Modify: `app/Models/AlertRule.php`, `app/Actions/Alerts/SaveAlertRule.php`
- Test: `tests/Feature/AlertRuleLimitTest.php` (new); update `tests/Feature/OutlookReportRuleTest.php` (the test "SaveAlertRule refuses an outlook report for a Free subscriber…" is replaced)

**Interfaces:**
- Consumes: `User::alertRuleLimit()`, `canCreateAlertRule()`, `canCreateOutlookReport()` (Task 1).
- Produces: `AlertRule` throws `AlertRuleLimitReached` on `creating` when the owner cannot create, or the new rule is an Outlook Report the owner cannot add, and on `updating` when `category` becomes `outlook_report` from another category and the owner cannot add one. `SaveAlertRule::attributes()` throws `ValidationException::withMessages(['category' => <same message>])` under the same conditions, before the model is touched. `SaveAlertRule::PAID_ONLY_MESSAGE` is deleted.

- [ ] **Step 1: Write the failing tests** in `AlertRuleLimitTest.php`:
  - `creating a rule at the limit throws AlertRuleLimitReached` (`AlertRule::factory()->for($user)->create()` ×3 for Free, fourth throws).
  - `a paused rule counts`.
  - `a Free user over the limit can still edit, pause and delete`: 7 rules created with `free_alerts` raised first then lowered (or `forceCreate`/settings juggling), then update `name`, toggle `is_active`, delete: no exception.
  - `a Free user cannot create a second Outlook Report` and `can create their first`; `a Plus user can create several`.
  - `a Free user with an Outlook Report can edit it` (category unchanged).
  - `a Free user with an Outlook Report cannot switch another alert to Outlook` (update `category` throws); `a Free user with none can`.
  - `SaveAlertRule gives a ValidationException on category with the limit message at the limit` and `with the outlook message for a second Free Outlook Report`, through `(new SaveAlertRule)($user, $input)` using the same input style `OutlookReportRuleTest.php` uses.
  - `two creates racing at limit minus one`: not testable in-process; instead assert the guard reads the count after taking `User::lockForUpdate` by wrapping in a transaction and asserting the count query ran after the lock (query log order) — or skip this test and rely on the lock being present (state which in the ledger).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.** In `AlertRule::booted()` add `static::creating` and extend `static::updating` (keep the existing delivery-time logic): both lock the owner (`User::query()->whereKey($rule->user_id)->lockForUpdate()->first()`; the dashboard pages and `SaveAlertRule` already run in a transaction) and throw `AlertRuleLimitReached` per the Interfaces block. In `SaveAlertRule::attributes()` replace the `PAID_ONLY_MESSAGE` block with the same checks (`$stored === null && ! $user->canCreateAlertRule()` → limit message; `$category === Outlook && ($stored === null || $stored->category !== Outlook) && ! $user->canCreateOutlookReport()` → outlook message), and remove the constant and its docblock sentence. Do not add `PAID_ONLY_MESSAGE` replacements elsewhere yet (Task 3 removes its other uses; until then `AlertRuleForm`, `OutlookReportPreview` still reference it, so in this task delete the constant LAST, after Task 3, or keep it until Task 3 finishes: the implementer should do Task 3's reference removal in the same commit if the suite would otherwise break).
- [ ] **Step 4: Run, expect PASS**, plus `tests/Feature/OutlookReportRuleTest.php` (replace the Free-refusal test with the new expectation: Free's first Outlook Report saves; the second throws the outlook message).
- [ ] **Step 5: Pint and commit** (`Alert limits: refuse creating past the limit and a second Free Outlook Report`).

---

### Task 3: Lift the Free-only Outlook gates

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php` (`isPaidOnlyForUser`, `categoryStep`), `app/Filament/Dashboard/Resources/AlertRules/Widgets/OutlookReportPreview.php`, `app/Console/Commands/DispatchOutlookReports.php`, `.ai/rules/alerts.md` (the "outlook report is paid-only" section)
- Test: update `tests/Feature/OutlookReportRuleFormTest.php`, `tests/Feature/OutlookReportPreviewTest.php`, `tests/Feature/DispatchOutlookReportsTest.php`

**Interfaces:**
- Consumes: `User::canCreateOutlookReport()`, `SaveAlertRule` messages (Task 2).
- Produces: the category picker disables the Outlook option only when `! auth()->user()->canCreateOutlookReport()` and the record being edited is not itself an Outlook Report, with the outlook message from `AlertRuleLimitReached::outlook()` as its description. The preview widget no longer returns `paid_only`. `DispatchOutlookReports` no longer filters Free owners.

- [ ] **Step 1: Update the tests to the new behavior (they fail first):**
  - `OutlookReportRuleFormTest`: replace "a Free subscriber sees the outlook report as a paid feature and cannot create one" with "a Free subscriber can pick and create their first Outlook Report", and "a Free subscriber with one sees Outlook disabled with the reason"; replace "a downgraded owner saving a kept outlook report on Edit is told it is a paid feature" with "a Free owner can edit their Outlook Report"; delete the `saveForForm` re-key test's use of the paid message (keep the re-key behavior, use the limit message).
  - `OutlookReportPreviewTest`: replace the Free `paid_only` test with "a Free subscriber's preview works like anyone's".
  - `DispatchOutlookReportsTest`: replace "a rule whose owner is on the Free tier is skipped…" and "a Free-owned rule does not stop a paid rule…" with "a Free owner's due Outlook Report is sent and stamped".
- [ ] **Step 2: Run these three files, expect FAIL.**
- [ ] **Step 3: Implement.** Replace `isPaidOnlyForUser($category)` with a closure-friendly check using the injected `?AlertRule $record`; delete the `validationMessages(['in' => …])` paid override or point it at the outlook message; remove the Free branch in `OutlookReportPreview::getViewData()` (and its view's `paid_only` case if present); remove the `->filter(fn … tier() !== Tier::Free && …)` Free test in `DispatchOutlookReports` (keep `isDue`) and its comment and the unused `Tier` import; update the command docblock. Delete `SaveAlertRule::PAID_ONLY_MESSAGE` once nothing references it (grep). Rewrite the `.ai/rules/alerts.md` section as "Outlook Reports: Free may have one" describing the new rule and that the API still refuses the category.
- [ ] **Step 4: Run the three files plus `tests/Feature/OutlookReportRuleTest.php` and `tests/Feature/ApiAlertOutlookReportTest.php`, expect PASS.**
- [ ] **Step 5: Pint and commit** (`Outlook Report: open to Free, one per Free subscriber`).

---

### Task 4: Dashboard and API affordances

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Pages/ListAlertRules.php`, `app/Filament/Dashboard/Resources/AlertRules/Pages/CreateAlertRule.php`, `app/Actions/Alerts/BuildAlertRuleOptions.php`
- Test: `tests/Feature/AlertRuleLimitUiTest.php` (new); extend `tests/Feature/ApiAlertRuleOptionsTest.php`

**Interfaces:**
- Consumes: Task 1 `User` methods, Task 2 `AlertRuleLimitReached`.
- Produces: the `New Alert` header action is disabled with a tooltip ("You've reached your plan's limit of N alerts — remove one or upgrade to add more.") when `! canCreateAlertRule()`; the list page shows "N of M alerts" (a header `Text` or the existing `pageDescription()` line extended); `CreateAlertRule::beforeCreate()` (or `mount`) refuses with a danger notification and `$this->halt()` at the limit, as `CreateLocation::beforeCreate()` does; the options payload gains `data.alert_limit` (int), `data.alert_count` (int), `data.can_create_alert` (bool) and `data.can_create_outlook_report` (bool).

- [ ] **Step 1: Write the failing tests:** Livewire: `ListAlertRules` action `create` is disabled at the limit and enabled under it (`assertActionDisabled('create')` / `assertActionEnabled`); `CreateAlertRule` at the limit halts with a notification and creates nothing; API: the options call reports the four new fields for a Free user at 0, at 3 alerts, with an Outlook Report (outlook false), and for Plus.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** as in Interfaces; keep `'categories'` in the options payload unchanged (the API still excludes Outlook).
- [ ] **Step 4: Run both files, expect PASS.**
- [ ] **Step 5: Pint and commit** (`Alert limits: dashboard button, create guard and API options`).

---

### Task 5: Rules, docs and the full run

**Files:**
- Modify: `.ai/rules/models.md` (via `mcp__laravel-boost__record-rule`, glob `app/Models/**`, title "Alert limits are enforced in AlertRule's creating/updating hooks"), `.ai/rules/alerts.md` if Task 3 left anything out, `deploy/README.md` one sentence only if it lists tier settings (grep first; skip if it does not)
- Test: none new

- [ ] **Step 1:** Record the model rule (what the hooks guard, the owner-row lock, that paused rules count, that editing is never refused). Record via `record-rule`, not by hand.
- [ ] **Step 2:** Run the full suite in parallel in the app container per the project memory ("Run the full suite in parallel": 6 processes), read the tail, and report any failure by name.
- [ ] **Step 3:** Commit the rule files (`Alert limits: project rules`).
