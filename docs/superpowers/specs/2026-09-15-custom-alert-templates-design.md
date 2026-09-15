# Custom Alert Templates (Section-Picker Builder) — Design

## Context

The master plan's "Custom alert templates" feature (FEAT) lets a subscriber build their own alert message by choosing which sections of a product to include — hazard, source, impact, affected locations, expiration, motion vector for a warning — plus an optional subject line, Plus/Pro-gated. This was deferred twice already this session: once when Alert Templates v1 scoped down to just a subject line and message length, and again when CAP ingestion was built, since CAP's `<description>` still bundled hazard/source/impact as unsplit prose.

What's changed since then: [`ParseNarrativeSections`](../../../app/Actions/Pipeline/ParseNarrativeSections.php) now splits `hazard`/`hazard_source`/`impact`/`locations_impacted`/`call_to_action` onto `ingested_products` for both text and CAP rows, and `active_wwas` (built alongside CAP ingestion) provides `expires` and a motion vector (`motion_direction_degrees`/`motion_speed_kt`/`motion_reference_lat`/`motion_reference_lon`). The backend data the section picker needs now exists — for warning-shaped products.

**Today's "Alert Templates v1"** lives directly on `AlertRule`: `custom_subject_template` (a `strtr()`-rendered string with `{product}`/`{location}`/`{category}` placeholders, via [`RenderAlertSubject`](../../../app/Actions/Weather/RenderAlertSubject.php)) and `message_body_style` (a `Full`/`Brief` enum truncating the raw product text to 1500 or 280 characters in [`DeliverWeatherAlert`](../../../app/Jobs/DeliverWeatherAlert.php)). Both are Plus/Pro-gated via `effectiveCustomSubjectTemplate()`/`effectiveMessageBodyStyle()`.

**Correction during implementation-context-gathering:** these two columns are not exclusive to `WeatherAlerts`/`SpcWpc` — [`DeliverScheduledAlert`](../../../app/Jobs/DeliverScheduledAlert.php) (the delivery job for Daily Summary and Threshold Alerts) also reads them via the same `RenderAlertSubject`/`effectiveMessageBodyStyle()` calls. An earlier draft of this design called for dropping both columns outright ("fully replace"); that would have silently regressed Daily Summary/Threshold subscribers' existing custom-subject and Brief/Full capability, since those categories are explicitly out of scope for the new section-picker (no structured data to build one from — see Scope below). **This design keeps `custom_subject_template`/`message_body_style` on `AlertRule` completely untouched**, and adds the new `AlertTemplate` mechanism purely additively, scoped to `WeatherAlerts`/`SpcWpc` only. No column drop, no data migration — two mechanisms coexist, one per category group, matching where the underlying structured data actually exists.

## Scope

**In scope:** a new `AlertTemplate` resource a subscriber builds once and attaches to one or more of their `WeatherAlerts`/`SpcWpc` `AlertRule`s; a rich section-picker for `WeatherAlerts`-category rules (the true VTEC warnings, which have the full `hazard`/`hazard_source`/`impact`/`locations_impacted`/`expiration`/`motion`/`call_to_action` field set); a subject-line-only template type for `SpcWpc`-category rules, additive alongside (not replacing) `custom_subject_template`.

**Out of scope, deliberately:**
- **Daily Summary, Threshold Alerts, Lightning Alerts, and Forecast Discussion categories.** None of these have a comparable structured field set today. Daily Summary's [`BuildDailySummaryMessage`](../../../app/Actions/Weather/BuildDailySummaryMessage.php) composes a single fixed-format string, not per-field data a template could pick from — restructuring it into that shape is its own separate piece of work, not bundled here. These four categories keep using exactly today's `custom_subject_template`/`message_body_style` mechanism, completely untouched by this round; they simply have no `AlertTemplate` option.
- **A rich section picker for `SpcWpc`.** SPC/WPC products (convective outlooks, mesoscale discussions) are not VTEC-class: `ParseNarrativeSections` never populates `hazard`/`hazard_source`/`impact`/`locations_impacted`/`call_to_action` for them (no `HAZARD.../SOURCE.../IMPACT...` bullets in that product family), and they never get an `active_wwas` row (`SyncActiveWwa` only fires for VTEC-class products), so there's no `expires`/motion data either. `SpcWpc` templates get exactly a subject-line capability — the new resource's answer for that category, coexisting with (not replacing) the rule's own `custom_subject_template` if it still has one set.
- **A live preview of the composed message in the template builder UI.** A natural future enhancement, not required for this round.
- **Free-tier access.** Templates stay Plus/Pro-gated, identical to v1's existing tier check.
- **Dropping or migrating `custom_subject_template`/`message_body_style`.** See the Context correction above — these stay exactly as they are, serving every category including (as a fallback) `WeatherAlerts`/`SpcWpc`.

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
- Add `alert_template_id` (nullable FK to `alert_templates`, **null-on-delete**). Note: the master plan's prose describes a restrict-on-delete referential guard for Location/NotificationChannel ("a subscriber can't delete a Location or Notification channel that's currently referenced by an existing alert"), but that pattern was never actually implemented — `alert_rules.location_id` is `nullOnDelete()` and the `alert_rule_notification_channel` pivot is `cascadeOnDelete()` in the real schema. `alert_template_id` follows the schema's real precedent (null-on-delete) rather than the master plan's unimplemented aspiration, which also fits the field better: unlike a Location a rule structurally needs to function, a template is a pure rendering nicety — a rule whose template gets deleted just falls back to the next tier down (see Rendering Pipeline), a fully working state, not a broken one. No Filament-level pre-check needed; a plain confirmation on the delete action is sufficient.
- **No other changes.** `custom_subject_template`/`message_body_style` stay exactly as they are today — same columns, same casts, same `effectiveCustomSubjectTemplate()`/`effectiveMessageBodyStyle()` methods, same wizard visibility for every category.

**The 7 selectable `WeatherAlerts` sections**, always rendered in this fixed canonical order regardless of check order (never subscriber-reorderable — see Approaches below):

1. **Hazard** — `ingested_products.hazard`
2. **Source** — `ingested_products.hazard_source`
3. **Impact** — `ingested_products.impact`
4. **Locations impacted** — `ingested_products.locations_impacted`
5. **Expiration** — `active_wwas.expires` (via the rule's matched `IngestedProduct`'s VTEC key)
6. **Motion** — a new formatted line from `active_wwas.motion_direction_degrees`/`motion_speed_kt` (e.g. "Moving ESE at 20 mph")
7. **Call to action** — `ingested_products.call_to_action`

**`SpcWpc` templates** have only `subject_template` — `sections` is always null/ignored for this category.

## Rendering Pipeline

Two actions, mirroring the codebase's existing `Action`-class convention. Both are strictly additive on top of today's behavior — a rule with no `AlertTemplate` attached (every rule today, and every non-`WeatherAlerts`/`SpcWpc` rule forever) behaves identically to before this feature shipped.

**`RenderAlertSubject`** (existing action, modified): now a three-tier fallback — `$rule->alertTemplate?->subject_template` first (only ever non-null for `WeatherAlerts`/`SpcWpc` rules with a template attached), then `$rule->effectiveCustomSubjectTemplate()` (today's existing v1 mechanism, unchanged, still reachable for every category including `WeatherAlerts`/`SpcWpc` rules that haven't attached a new template), then `AlertRule::displayName()`. `DeliverScheduledAlert` (Daily Summary/Threshold) calls this same action and is unaffected, since those rules never have an `alert_template_id`.

**`ComposeTemplatedAlertBody`** (new action): `__invoke(AlertRule $rule, IngestedProduct $product): ?string`. Returns a labeled-lines body (`"Hazard: ...\nSource: ...\n..."`, one populated section per line, in the fixed canonical order) when the rule has a `WeatherAlerts`-category template attached with a non-empty `sections` array; returns `null` in every other case — no template attached, an `SpcWpc` template attached, or a `WeatherAlerts` template with zero sections checked. A section present in `sections` but `null` on the specific `IngestedProduct`/`ActiveWwa` (locations_impacted and call_to_action in particular are best-effort per `ParseNarrativeSections`' own documented coverage gaps) is silently skipped — never a placeholder line, matching this codebase's established never-guess convention.

**`DeliverWeatherAlert`** changes: call `ComposeTemplatedAlertBody` first. When it returns non-null, use that as the body, capped at a fixed 1500 characters (`Str::limit(..., 1500, preserveWords: true)` — a safety net against provider message-length limits, not a subscriber control, since section selection is what controls length now). When it returns null, fall back to **exactly today's existing code, completely unchanged**: the raw product text (`$product->format === 'cap' ? ($product->description ?? $product->text) : $product->text`), capped at 280 or 1500 depending on `$rule->effectiveMessageBodyStyle()`. This means a `WeatherAlerts` rule that has an old `message_body_style`/`custom_subject_template` set but no new `AlertTemplate` keeps behaving exactly as it does today — the new mechanism only ever takes over when a subscriber explicitly attaches a template.

**Motion formatter** (new, self-contained — no existing compass-direction utility exists in this codebase to reuse): converts `motion_direction_degrees` (0–359) to a 16-point compass label (N/NNE/NE/.../NW/NNW) and `motion_speed_kt` to a rounded mph figure, composing e.g. "Moving ESE at 20 mph". Returns `null` (section skipped) when either underlying column is null, same treatment as every other section.

## Free/Plus/Pro Behavior Summary

- **Free tier:** never has `alert_template_id` set, same as it never has `custom_subject_template` set today. Behavior is completely unchanged by this feature.
- **Plus/Pro, no `AlertTemplate` attached on a given `WeatherAlerts`/`SpcWpc` rule:** falls through to today's existing `custom_subject_template`/`message_body_style` behavior unchanged (which itself falls back to `displayName()`/raw-text-at-1500 if those are also unset). Attaching a template is purely additive and optional.
- **Plus/Pro, `AlertTemplate` attached:** `WeatherAlerts` rules get the composed labeled-lines body (when `sections` is non-empty) and/or the template's subject, taking priority over any old `custom_subject_template`/`message_body_style` still set on that same rule; `SpcWpc` rules get only the template's subject (raw text body via the unchanged fallback path).
- **Daily Summary, Threshold, Lightning, Forecast Discussion (any tier):** entirely unaffected — these categories never have an `alert_template_id`, and `DeliverScheduledAlert` doesn't change at all.

## Filament UI

- **New `AlertTemplateResource`** (`app/Filament/Dashboard/Resources/AlertTemplates/`): List/Create/Edit, Plus/Pro nav-gated the same way `TraccarServerResource` already gates itself (`shouldRegisterNavigation()`/`canAccess()` both checking `tier() !== Free`). Create/Edit form: `name`, `category` select (`WeatherAlerts`/`SpcWpc`, live), `subject_template` text input (same placeholder hints as today's `custom_subject_template`), and — visible only when `category === WeatherAlerts` — a `CheckboxList` of the 7 sections in their fixed display order (per the chosen Option A: checkboxes only, no reordering).
- **`AlertRuleForm`'s "Finishing touches" step**: the existing `message_body_style` Select and `custom_subject_template` TextInput stay exactly as they are, for every category. A new `alert_template_id` Select is added alongside them, visible only for `WeatherAlerts`/`SpcWpc` categories and only for Plus/Pro tier, scoped to `auth()->user()->alertTemplates()->where('category', $rule->category)` (only same-category templates offered — this is what prevents a `SpcWpc` rule from ever seeing a `WeatherAlerts` template, no separate validation rule needed). Uses the same create-option-in-modal pattern already established for Location/Channel selects elsewhere in this wizard, so a subscriber without an existing template isn't forced to leave the wizard to build one. Helper text on the new field clarifies it takes priority over the subject/length fields above when set, for the two categories where both are visible at once.

## Testing

- **`ComposeTemplatedAlertBody`**: section inclusion/exclusion, canonical ordering regardless of stored array order, null-section skipping, no-template/no-sections/`SpcWpc`-template all returning `null`.
- **Motion formatter**: compass conversion at all 16 points and the 0°/360° boundary, knot-to-mph rounding, `null` on missing columns.
- **`RenderAlertSubject`** (updated): template-attached takes priority over an old `custom_subject_template`; template-attached-but-blank falls through to the old field; neither set falls through to `displayName()`.
- **`AlertTemplateResource`**: CRUD, `sections` field visibility toggling on `category`, tier gating.
- **`AlertRuleForm`**: the `alert_template_id` Select only offers same-category templates; inline create-in-modal works without losing wizard state.
- **`DeliverWeatherAlert`**: a `WeatherAlerts` rule with an attached template composes the labeled-lines body (capped at 1500); a rule with none, but with an old `message_body_style`/`custom_subject_template` set, behaves exactly as it does today (unchanged regression coverage on the existing tests); the composed-body cap and the raw-text cap are both independently verified.

## Out of Scope (restated)

- Daily Summary, Threshold, Lightning, and Forecast Discussion template types (no backing structured data yet) — and no changes to their existing `custom_subject_template`/`message_body_style` behavior.
- A rich section picker for `SpcWpc` (no backing structured data — not VTEC-class).
- Live message preview in the builder UI.
- Free-tier access to templates.
- Subscriber-controlled section ordering (fixed canonical order only, per the chosen Option A).
- Dropping or migrating the existing `custom_subject_template`/`message_body_style` columns.
