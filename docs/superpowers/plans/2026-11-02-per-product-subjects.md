# Per-Product Subjects Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each product of a Weather Alerts or SPC & WPC rule can carry its own notification subject, edited on the wizard's product step, overriding the rule-wide subject, which stays as the default.

**Architecture:** A nullable `custom_subject_template` column on the `alert_rule_product` pivot, written through `SaveAlertRule` (with an "absent key keeps the stored value" rule so the mobile app's save shape cannot erase it) and read by `RenderAlertSubject` with the order product, template, rule-wide, default. The product table gets a compact "Custom subject" control per switched-on row, and the API gains `product_settings[].custom_subject_template`.

**Tech Stack:** Laravel 13 / PHP 8.4 / Filament v5 (custom Blade field with Alpine) / Pest. Backend repo `wxnotify-nextgen` only; the mobile app is not changed.

**Spec:** `docs/superpowers/specs/2026-11-02-per-product-subjects-design.md`

## Global Constraints

- Read `.ai/rules/index.md` and every rule file whose globs cover the paths you touch before editing (at least `filament.md`, `pages.md`, `models.md`, `support.md`, `pipeline.md`, `actions.md`, `api.md`, `v1.md`, `feature.md`). `.ai/rules` is written only by the controller through `record-rule`; implementers never edit it.
- Backend tests run in the app container (start it with `docker compose up -d front-end pgsql redis` if it is down): `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php artisan test --compact <files>'`. Pint before finishing: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --dirty --format agent'`.
- Create files with `php artisan make:* --no-interaction` run in the container; test helper functions in Pest files need file-prefixed names; read the `testing-best-practices` skill before writing tests; TDD with RED/GREEN evidence in the report.
- Commit directly on `main`, one commit per task, message ending with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never push.
- Precedence for one delivery, exactly: the product's own subject, then the attached Message template's subject, then the rule-wide `custom_subject_template`, then the default name. Free accounts never use a custom subject of any kind; a stored per-product subject of a downgraded owner is ignored, not deleted.
- `RenderAlertSubject` stays logic-free `strtr()` substitution; no new placeholders, no new executable syntax.
- A per-product subject is trimmed; blank or whitespace-only is null; the maximum is 255 characters (same limit and message style as the rule-wide box).
- "Absent key keeps, explicit empty clears": a save whose `product_settings` entry omits `custom_subject_template`, or that carries only `product_ids` (the flat shape the mobile app sends), keeps the stored per-product subject of every product that stays on the rule; a product newly added starts with none; an explicit `null`, empty or whitespace-only value clears it.
- The product table stays light: no subject input is rendered for a switched-off row, no server round trip per row (native inputs, deferred `wire:model`, Alpine), per `.ai/rules/filament.md`.
- Only Weather Alerts and SPC & WPC rules get per-product subjects; other categories keep the rule-wide box only.

## Review Focus

Failure modes the spec implies that no happy-path test would cover, most likely first (each has a test in the owning task):

1. A `product_ids`-only save (the mobile app's shape) and a `product_settings` entry without the key both keep stored per-product subjects; an explicit `null` clears one (Task 1, Task 5).
2. A Free owner with stored per-product subjects: deliveries ignore them and saves raise no error (Task 2).
3. A bulletin whose matched segments span several products: the first matched segment's product decides, and a product the rule does not watch never supplies the subject (Task 2).
4. A 256-character subject is rejected and a whitespace-only one saves as null, on both the dashboard and the API (Task 4, Task 5).
5. Switching a product off and on keeps its typed subject; removing a product loses it with the pivot row; a switched-off row renders no input (Task 4).

---

### Task 1: Store and save a per-product subject

**Files:**
- Create: `database/migrations/*_add_custom_subject_template_to_alert_rule_product_table.php`
- Modify: `app/Models/AlertRule.php`, `app/Actions/Alerts/SaveAlertRule.php`
- Test: `tests/Feature/SaveAlertRuleTest.php` (add cases)

**Interfaces:**
- Consumes: the existing `SaveAlertRule::productSettings(array $data): ?array` entries (`normaliseEntry()`), `syncRelations()` (rebuilds the pivot with `sync()`), and `AlertRule::products()` (`withPivot([...])`).
- Produces:
  - Column `alert_rule_product.custom_subject_template` (string 255, nullable).
  - `AlertRule::products()` pivot includes `custom_subject_template`.
  - `AlertRule::customSubjectFor(int $productId): ?string`: the product's stored subject, trimmed, null when blank, when the product is not on the rule, or when the owner is Free (use `$this->user->tier() === Tier::Free`, the same gate as `effectiveCustomSubjectTemplate()`).
  - `productSettings()` entries gain `custom_subject_template` ONLY when the input entry had that key (trimmed string, or null for empty/whitespace); an entry without the key has no such key. The flat `product_ids` shape produces entries without it.
  - `syncRelations()` writes each pivot row's `custom_subject_template`: the entry's value when its key is present, else the value stored for that product before the sync (read it before `sync()` replaces the rows), else null.

- [ ] **Step 1: Write failing tests** in `SaveAlertRuleTest.php` (use the file's existing helpers and fixtures for a Weather Alerts rule with two products): saving `product_settings` with `custom_subject_template` on one entry stores it on that pivot row only and trims it; whitespace-only and empty store null; re-saving the same rule with entries that omit the key keeps both stored values; re-saving with only `product_ids` (flat shape) keeps the stored values of products that stay and gives a newly added product none; an entry with an explicit `null` clears just that product; removing a product from the rule drops its row and its subject; `customSubjectFor()` returns the value for a paid owner and null for a Free owner, for a product not on the rule, and when blank.
- [ ] **Step 2: Run them** and see them fail.
- [ ] **Step 3: Implement** the migration (`->string('custom_subject_template')->nullable()` on the pivot, with a `down()` that drops it), the model changes and the `SaveAlertRule` changes above; carry the "key present" distinction through `normaliseEntry()` without changing any existing key's behaviour.
- [ ] **Step 4: Run** `SaveAlertRuleTest`, `AlertRuleParityTest`, `ApiAlertRulesTest`; expected PASS. Pint.
- [ ] **Step 5: Commit** `Alert rules: store a custom subject per product`.

### Task 2: Render the product's subject first

**Files:**
- Modify: `app/Actions/Weather/RenderAlertSubject.php`
- Test: `tests/Feature/RenderAlertSubjectTest.php` (add cases)

**Interfaces:**
- Consumes: `AlertRule::customSubjectFor(int $productId): ?string` (Task 1); the `RenderAlertSubject::__invoke($rule, $product, $location, $bulletin, $segments)` arguments, where `$segments` are the segments THIS rule matched.
- Produces: the subject template is chosen as: the subject of the product named by the first matched segment's `product_id`, else the bulletin's `product_id` (the same pick `officeOrArea()` makes; extract one private helper for the product id so both use it), when `customSubjectFor()` returns a value; otherwise the existing template, rule-wide, default chain unchanged.

- [ ] **Step 1: Write failing tests**: a product subject beats an attached Message template's subject and the rule-wide subject; a product without one still renders the template, then the rule-wide subject, then the default name; placeholders render as before inside a product subject (`{product} for {location} {until}`, `{product} by {wfo}`); with matched segments of two products the first matched segment's product subject is used while `{product}` still reads "A + B"; a product the rule does not watch (its segment first, the watched product second) never supplies the subject; a Free owner's stored product subject is ignored; a rule with no per-product subjects renders exactly as before.
- [ ] **Step 2: Run** and see them fail. **Step 3: Implement.** **Step 4: Run** `RenderAlertSubjectTest`, `DeliverWeatherAlertTest`, `MatchWeatherAlertRulesTest`; expected PASS. Pint.
- [ ] **Step 5: Commit** `Alert subjects: a product's own subject wins`.

### Task 3: Placeholders that fit each product

**Files:**
- Create: `app/Support/SubjectPlaceholders.php`
- Test: `tests/Feature/SubjectPlaceholdersTest.php`

**Interfaces:**
- Consumes: `Product` attributes `has_vtec`, `has_ugc`, `family` (`App\Enums\ProductFamily`).
- Produces: `SubjectPlaceholders::forProduct(?Product $product): list<string>`: `{product}`, `{location}`, `{category}` for every product; `{until}` only when `has_vtec`; `{states}` only when `has_ugc`; `{wfo}` only when the family is not `ProductFamily::SpcWpc`; a null product lists all six; order always `{product} {location} {category} {until} {states} {wfo}` filtered. `SubjectPlaceholders::helperText(?Product $product): string` returning `Placeholders: {product} {location} ...` for that list.

- [ ] **Step 1: Write failing tests** with `Product::factory()` (check its states): a warning-type product (`has_vtec` and `has_ugc` true, not SPC & WPC) lists all six; a `forecast_discussion` product lists no `{until}`; an `spc_wpc` product lists no `{wfo}`; null lists all six; the helper text joins them with spaces.
- [ ] **Step 2-4:** red, implement, green; Pint. **Step 5: Commit** `Alert subjects: placeholders per product kind`.

### Task 4: The subject control on the product step, and the dashboard save

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AlertRules/Schemas/ProductMatchingTable.php`, `resources/views/filament/dashboard/alert-rules/product-matching-table.blade.php`, `app/Filament/Dashboard/Resources/AlertRules/Schemas/AlertRuleForm.php`, `app/Support/ProductMatching.php` (default row)
- Test: `tests/Feature/AlertRuleResourceTest.php` (add cases; read its existing table tests first)

**Interfaces:**
- Consumes: `AlertRule::customSubjectFor()` and the pivot column (Task 1); `SubjectPlaceholders::helperText()` (Task 3); the table row state `product_settings.{category}[]` (`{product_id, enabled, matching_mode, location_ids, wfo, county_zone_codes}`), `formState()`, `storedRow()`, `toSaveInput()`.
- Produces: the row state gains `custom_subject_template` (default `''`; on Edit the stored pivot value or `''`); `toSaveInput()` passes `custom_subject_template` on every enabled row as a trimmed string or null (always present, so the dashboard's save is always explicit); validation rejects an enabled row's subject over 255 characters with the rule-wide box's message style; the rule-wide field in `AlertRuleForm` is relabelled "Default subject line (optional)" with helper text saying a product's own subject overrides it, for Weather Alerts and SPC & WPC rules only (other categories keep "Custom subject line (optional)").
- View: under each product's name cell, for a switched-on row only (`x-show="on"`) and only for a paid owner: the text "Uses the default subject" with a "Custom subject" button when the row's subject is empty, else a one-line preview with an "Edit" button; the button toggles a row-local Alpine flag and reveals one `<input type="text" maxlength="255">` bound with deferred `wire:model="{{ $rowPath }}.custom_subject_template"` and the product's `helperText()` under it. No input is rendered for a switched-off row, and switching a row off and on keeps what was typed.

- [ ] **Step 1: Write failing tests** (Livewire, `actingAs` a paid user, seeded catalog like `AlertRuleParityTest`): creating a Weather Alerts rule with a subject typed on one switched-on row stores it on that pivot row only; the edit page shows the stored subject in the row state and a row with none shows empty; a switched-off row's typed text is not saved; clearing the text saves null; 256 characters fails validation and 255 passes; a Free owner's page does not render the control (assert the markup) and a save raises no error; the rule-wide field reads "Default subject line (optional)" for Weather Alerts and SPC & WPC and keeps its old label for a threshold rule; the rendered row for a switched-off product contains no subject input.
- [ ] **Step 2: Run** and see them fail. **Step 3: Implement** the state, save input, validation, view and label changes; keep every existing key and Alpine behaviour of the table unchanged. **Step 4: Run** `AlertRuleResourceTest`, `AlertRuleParityTest`, `SaveAlertRuleTest`, plus any test that renders the product table; expected PASS. Pint.
- [ ] **Step 5: Commit** `Alert wizard: a custom subject on each switched-on product`.

### Task 5: API field and parity

**Files:**
- Modify: `app/Http/Requests/SaveAlertRuleRequest.php`, `app/Http/Resources/Api/V1/AlertRuleResource.php`
- Test: `tests/Feature/ApiAlertRulesTest.php`, `tests/Feature/AlertRuleParityTest.php` (add cases)

**Interfaces:**
- Consumes: Task 1's keep-or-clear behaviour and pivot column.
- Produces: `product_settings.*.custom_subject_template` accepted as `nullable|string|max:255` (paid tiers only, in the same block as the rule-wide field; a Free token's value is ignored like the rule-wide one); each `product_settings` entry in the rule resource carries `custom_subject_template` (the stored pivot value or null). The rule-level `custom_subject_template` is unchanged.

- [ ] **Step 1: Write failing tests**: a POST/PUT with `product_settings[].custom_subject_template` stores it and the resource returns it per entry; an update whose entries omit the key keeps stored values; an update with only `product_ids` (the app's flat shape) keeps stored values for products that stay; an explicit `null` clears one; 256 characters returns a 422; a Free token's per-product value is ignored without error; the dashboard create and the API create for equivalent subscribers store identical pivot subjects (extend the parity scenario).
- [ ] **Step 2-4:** red, implement, green with `ApiAlertRulesTest`, `AlertRuleParityTest`, `SaveAlertRuleRequest`-related tests; Pint.
- [ ] **Step 5: Commit** `API: per-product custom subjects on alert rules`.

## Controller steps after the tasks (not for implementers)

- Run the full backend suite in parallel (6 processes) after the last task; final whole-branch review on the most capable model.
- Record rules with `record-rule`: the per-product subject precedence and the "absent keeps, explicit empty clears" save rule (`app/Actions/Alerts/**`), the pivot column and Free-tier ignore (`app/Models/AlertRule.php`), and that the product table renders a subject input only for switched-on rows (`app/Filament/**`).
- Rollout: migration, then the dashboard. The mobile app is untouched and keeps working because its flat save keeps stored product subjects.
