# Custom Alert Templates (Section-Picker Builder) — Design

## Context

The master plan's "Custom alert templates" feature (FEAT) lets a subscriber build their own alert message by choosing which sections of a product to include — hazard, source, impact, affected locations, expiration, motion vector for a warning — plus an optional subject line, Plus/Pro-gated. This was deferred twice already this session: once when Alert Templates v1 scoped down to just a subject line and message length, and again when CAP ingestion was built, since CAP's `<description>` still bundled hazard/source/impact as unsplit prose.

What's changed since then: [`ParseNarrativeSections`](../../../app/Actions/Pipeline/ParseNarrativeSections.php) now splits `hazard`/`hazard_source`/`impact`/`locations_impacted`/`call_to_action` onto `ingested_products` for both text and CAP rows, and `active_wwas` (built alongside CAP ingestion) provides `expires` and a motion vector (`motion_direction_degrees`/`motion_speed_kt`/`motion_reference_lat`/`motion_reference_lon`). The backend data the section picker needs now exists — for warning-shaped products.

**Today's "Alert Templates v1"** lives directly on `AlertRule`: `custom_subject_template` (a `strtr()`-rendered string with `{product}`/`{location}`/`{category}` placeholders, via [`RenderAlertSubject`](../../../app/Actions/Weather/RenderAlertSubject.php)) and `message_body_style` (a `Full`/`Brief` enum truncating the raw product text to 1500 or 280 characters in [`DeliverWeatherAlert`](../../../app/Jobs/DeliverWeatherAlert.php)). Both are Plus/Pro-gated via `effectiveCustomSubjectTemplate()`/`effectiveMessageBodyStyle()`. This design replaces both with a real section-picker template, built as a reusable resource.

## Scope

**In scope:** a new `AlertTemplate` resource a subscriber builds once and attaches to one or more of their `AlertRule`s; a rich section-picker for `WeatherAlerts`-category rules (the true VTEC warnings, which have the full `hazard`/`hazard_source`/`impact`/`locations_impacted`/`expiration`/`motion`/`call_to_action` field set); a subject-line-only template type for `SpcWpc`-category rules; dropping `custom_subject_template`/`message_body_style` from `AlertRule` with a data migration into generated `AlertTemplate` rows.

**Out of scope, deliberately:**
- **Daily Summary, Threshold Alerts, Lightning Alerts, and Forecast Discussion categories.** None of these have a comparable structured field set today. Daily Summary's [`BuildDailySummaryMessage`](../../../app/Actions/Weather/BuildDailySummaryMessage.php) composes a single fixed-format string, not per-field data a template could pick from — restructuring it into that shape is its own separate piece of work, not bundled here. These four categories keep using `AlertRule::displayName()` for their subject and their existing message composition unchanged; they simply have no `AlertTemplate` option in this round.
- **A rich section picker for `SpcWpc`.** SPC/WPC products (convective outlooks, mesoscale discussions) are not VTEC-class: `ParseNarrativeSections` never populates `hazard`/`hazard_source`/`impact`/`locations_impacted`/`call_to_action` for them (no `HAZARD.../SOURCE.../IMPACT...` bullets in that product family), and they never get an `active_wwas` row (`SyncActiveWwa` only fires for VTEC-class products), so there's no `expires`/motion data either. `SpcWpc` templates get exactly the subject-line capability v1 already has — just relocated onto the new resource instead of `AlertRule` directly.
- **A live preview of the composed message in the template builder UI.** A natural future enhancement, not required for this round.
- **Free-tier access.** Templates stay Plus/Pro-gated, identical to v1's existing tier check.

## Data Model

New `alert_templates` table:

| Column | Notes |
|---|---|
| `user_id` | FK, cascade delete |
| `name` | string, required — subscriber's own label (e.g. "Detailed Severe Weather") |
| `category` | string, matches `AlertCategory::WeatherAlerts` or `AlertCategory::SpcWpc` — determines which fields apply and which rules can attach it |
| `subject_template` | nullable text — same `{product}`/`{location}`/`{category}` placeholders `custom_subject_template` already supports |
| `sections` | nullable json array, `WeatherAlerts` templates only (e.g. `['hazard', 'hazard_source', 'impact', 'expiration']`); ignored for `SpcWpc` templates |
| timestamps | |

`alert_rules` changes:
- Add `alert_template_id` (nullable FK to `alert_templates`, **restrict-on-delete**) — matching the exact pattern the master plan already specifies for Location/NotificationChannel ("a subscriber can't delete a Location or Notification channel that's currently referenced by an existing alert... enforced twice: a restrict-on-delete foreign key at the database layer... with a Filament-level pre-check in front of it"). Same treatment here, enforced twice: the DB-level restrict-on-delete FK as the actual safety net, plus a Filament-level pre-check on `AlertTemplateResource`'s delete action that turns the raw constraint violation into a specific, named error listing which rule(s) still reference it — not silently nulled out, and not a bare SQL error surfaced to the subscriber.
- Drop `custom_subject_template` and `message_body_style`.

**The 7 selectable `WeatherAlerts` sections**, always rendered in this fixed canonical order regardless of check order (never subscriber-reorderable — see Approaches below):

1. **Hazard** — `ingested_products.hazard`
2. **Source** — `ingested_products.hazard_source`
3. **Impact** — `ingested_products.impact`
4. **Locations impacted** — `ingested_products.locations_impacted`
5. **Expiration** — `active_wwas.expires` (via the rule's matched `IngestedProduct`'s VTEC key)
6. **Motion** — a new formatted line from `active_wwas.motion_direction_degrees`/`motion_speed_kt` (e.g. "Moving ESE at 20 mph")
7. **Call to action** — `ingested_products.call_to_action`

**`SpcWpc` templates** have only `subject_template` — `sections` is always null/ignored for this category, matching v1's existing capability with nothing new.

## Rendering Pipeline

Two actions, mirroring the codebase's existing `Action`-class convention:

**`RenderAlertSubject`** (existing action, modified): swaps `$rule->effectiveCustomSubjectTemplate()` for `$rule->alertTemplate?->subject_template`, keeping the identical `strtr()` placeholder substitution and the identical fallback to `AlertRule::displayName()` when null (no template attached, or the attached template has no subject set).

**`ComposeTemplatedAlertBody`** (new action): `__invoke(AlertRule $rule, IngestedProduct $product): ?string`. Returns a labeled-lines body (`"Hazard: ...\nSource: ...\n..."`, one populated section per line, in the fixed canonical order) when the rule has a `WeatherAlerts`-category template attached with a non-empty `sections` array; returns `null` in every other case — no template attached, an `SpcWpc` template attached, or a `WeatherAlerts` template with zero sections checked. A section present in `sections` but `null` on the specific `IngestedProduct`/`ActiveWwa` (locations_impacted and call_to_action in particular are best-effort per `ParseNarrativeSections`' own documented coverage gaps) is silently skipped — never a placeholder line, matching this codebase's established never-guess convention.

**`DeliverWeatherAlert`** changes: call `ComposeTemplatedAlertBody`; when it returns non-null, use that as `$bodySource`; when null, fall back to exactly today's existing raw-text path (`$product->format === 'cap' ? ($product->description ?? $product->text) : $product->text`). Either way, the result still passes through the same `Str::limit($bodySource, 1500, preserveWords: true)` safety-net truncation already in place today — this is now a fixed ceiling (no more Brief/280 option), protecting against provider message-length limits on the rare event where every section is checked and populated with unusually long text, not a subscriber-facing control (that's what section selection is for).

**Motion formatter** (new, self-contained — no existing compass-direction utility exists in this codebase to reuse): converts `motion_direction_degrees` (0–359) to a 16-point compass label (N/NNE/NE/.../NW/NNW) and `motion_speed_kt` to a rounded mph figure, composing e.g. "Moving ESE at 20 mph". Returns `null` (section skipped) when either underlying column is null, same treatment as every other section.

## Free/Plus/Pro Behavior Summary

- **Free tier:** never has `alert_template_id` set (no UI to set it, matching today's tier gate). `ComposeTemplatedAlertBody` always returns `null`. Body is always the raw product text truncated to 1500 chars, subject is always `displayName()`. Zero behavior change from today.
- **Plus/Pro, no template attached on a given rule:** identical to Free tier's behavior on that rule — attaching a template is optional, never forced, exactly as `custom_subject_template`/`message_body_style` are optional today. A subscriber can freely mix templated and non-templated rules.
- **Plus/Pro, template attached:** `WeatherAlerts` rules get the composed labeled-lines body (when `sections` is non-empty) and/or the custom subject; `SpcWpc` rules get only the custom subject (raw text body, same as Free).

## Filament UI

- **New `AlertTemplateResource`** (`app/Filament/Dashboard/Resources/AlertTemplates/`): List/Create/Edit, Plus/Pro nav-gated the same way `AlertRuleForm`'s existing tier-gated fields are. Create/Edit form: `name`, `category` select (`WeatherAlerts`/`SpcWpc`, live), `subject_template` text input (placeholder hints unchanged from today's), and — visible only when `category === WeatherAlerts` — a `CheckboxList` of the 7 sections in their fixed display order (per the chosen Option A: checkboxes only, no reordering).
- **`AlertRuleForm`'s "Finishing touches" step**: replace the existing `message_body_style` Select and `custom_subject_template` TextInput with a single `alert_template_id` Select, scoped to `auth()->user()->alertTemplates()->where('category', $rule->category)` (only same-category templates offered — this is what prevents a `SpcWpc` rule from ever seeing a `WeatherAlerts` template, no separate validation rule needed), visible only for `WeatherAlerts`/`SpcWpc` categories and only for Plus/Pro tier. Uses the same create-option-in-modal pattern already established for Location/Channel selects elsewhere in this wizard, so a subscriber without an existing template isn't forced to leave the wizard to build one.

## Data Migration

A one-time migration converting existing `custom_subject_template`/`message_body_style` values into generated `AlertTemplate` rows: for each distinct `(user_id, custom_subject_template, message_body_style)` combination currently in use across `alert_rules` where either column is non-null, create one `AlertTemplate` (name auto-generated and numbered per subscriber — the first converted template for a given user is "Migrated template 1", the second "Migrated template 2", and so on; `category` taken from the rule's own category; `subject_template` copied verbatim; `sections` set to a reasonable default approximating the old Brief/Full split — Brief maps to `['hazard']` only, Full maps to all 7 sections — for `WeatherAlerts` rules, `null`/ignored for `SpcWpc` rows), then point the originating rule(s) at it via `alert_template_id` before the old columns are dropped in a later migration step.

## Testing

- **`ComposeTemplatedAlertBody`**: section inclusion/exclusion, canonical ordering regardless of stored array order, null-section skipping, no-template/no-sections/`SpcWpc`-template all returning `null`, the 1500-char safety-net cap.
- **Motion formatter**: compass conversion at all 16 points and the 0°/360° boundary, knot-to-mph rounding, `null` on missing columns.
- **`RenderAlertSubject`** (updated): template-attached vs. no-template vs. template-with-no-subject, all three falling back correctly.
- **`AlertTemplateResource`**: CRUD, `sections` field visibility toggling on `category`, tier gating.
- **`AlertRuleForm`**: the `alert_template_id` Select only offers same-category templates; inline create-in-modal works without losing wizard state.
- **Data migration**: existing `custom_subject_template`/`message_body_style` combinations convert into correctly-shaped `AlertTemplate` rows and get attached to the right rules.
- **`DeliverWeatherAlert`**: end-to-end, a `WeatherAlerts` rule with an attached template composes the labeled-lines body; a rule with none falls back to raw text; the fallback and the composed path both still respect the 1500-char cap.

## Out of Scope (restated)

- Daily Summary, Threshold, Lightning, and Forecast Discussion template types (no backing structured data yet).
- A rich section picker for `SpcWpc` (no backing structured data — not VTEC-class).
- Live message preview in the builder UI.
- Free-tier access to templates.
- Subscriber-controlled section ordering (fixed canonical order only, per the chosen Option A).
