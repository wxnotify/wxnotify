# Per-product custom subjects in the alert wizard — design

## Why

A Weather Alerts or SPC & WPC rule can watch many products at once, and the products differ in kind. A subject that suits a warning, such as `{product} for {location} {until}`, reads badly on a discussion: `{until}` has no value there, and `{wfo}` means nothing on a Mesoscale Discussion. Today the rule has one custom subject (`alert_rules.custom_subject_template`, set in the last wizard step), so a subscriber who watches several kinds of product must pick one subject that suits none of them well. The owner wants each product to be able to carry its own subject, written with the placeholders that fit that product. Examples the owner gave:

- Weather Alerts: Short-Fuse warnings `{product} for {location} {until}`; Area Forecast Discussion `{product} by {wfo}`.
- SPC & WPC: SPC Day 1 Convective Outlook `{product}`; SPC Mesoscale Discussion `{product} for {location}`.

## What the owner decided

- **Where:** on the wizard's product step, each switched-on product row shows its subject. A row without its own subject reads "Uses the default subject" with a "Custom subject" link; a row with one shows a short preview with an "Edit" link. Opening it reveals one text box with that product's placeholders listed under it (their option "B"; the table stays compact because the Weather Alerts list has about 143 rows).
- **The rule-wide box stays.** The existing "Custom subject line" in "Finishing touches" remains and becomes the default for any product with no subject of its own (relabelled accordingly). Existing rules and the mobile app's rule editor keep working unchanged.
- **Precedence:** the product's own subject wins over everything else. Full order for one delivery: the product's own subject, then the attached Message template's subject, then the rule-wide subject, then the default name (today: template, rule-wide, default).

## My calls (not yet confirmed by the owner beyond the playback they approved)

- **Placeholders listed per product.** The helper text under a product's box lists only the placeholders that can have a value for that product (for example `{until}` for warning-type products with an end time, not for a discussion). A placeholder the owner types that is not listed still renders exactly as today (empty when it has no value); there is no new validation error for it.
- **A bulletin that matches several products** (for example a Hurricane Watch and a Small Craft Advisory in one message): the subject of the FIRST matched product, in bulletin order, is used, resolved the same way the office lookup already picks the matched product (`RenderAlertSubject::officeOrArea()`: the first matched segment's `product_id`, else the bulletin's). The rendered `{product}` still reads "Hurricane Watch + Small Craft Advisory".
- **Free tier unchanged.** Free accounts cannot set a subject, per product or rule-wide; a stored per-product subject of a downgraded owner is ignored (tier gating in the same `effective…` style as `effectiveCustomSubjectTemplate()`), and kept in case they upgrade.
- **API:** per-product subjects are an addition to the API, as `product_settings[].custom_subject_template` (nullable, string, max 255) in the request and the rule resource; the rule-level `custom_subject_template` is unchanged. The mobile app's editor is not changed by this work and keeps sending only the rule-level field.
- **Scope:** only Weather Alerts and SPC & WPC rules (the categories with a product step). Threshold, Daily Summary and Lightning keep the rule-wide box; Outlook Report keeps ignoring the subject.
- **Storage:** a new nullable string column `custom_subject_template` (255) on the `alert_rule_product` pivot, next to the per-product matching settings and `risk_levels`. A pivot row with null falls back to the template/rule-wide path, so existing rules need no data migration and a rollback loses nothing already working.

## More calls, settled after the builder check (mine, not the owner's)

- **Absent key keeps, explicit empty clears.** A save that carries a `product_settings` entry without the `custom_subject_template` key, or carries only `product_ids` (the mobile app's shape, which rebuilds the pivot with `sync()`), keeps the stored per-product subject of every product that stays on the rule, by the same stored-value fallback `risk_levels` already uses (`SaveAlertRule::riskLevelsByProduct()`); a product newly added starts with none. An entry with an explicit `null`, empty or whitespace-only value clears it. The dashboard always sends the field, so it is always explicit. `AlertRuleParityTest` covers both shapes.
- **"First matched product" means the first matched segment.** `RenderAlertSubject` receives only the segments THIS rule matched (`IngestedProduct::matchedSegments()`), so the first of those, in bulletin order, names the product whose subject is used; a bulletin with no segments uses the bulletin's own product. A product the rule does not watch can never supply the subject.
- **Placeholders per product are a coarse split by product family, from one shared map.** `{product}`, `{location}` and `{category}` are listed for every product; `{until}` and `{states}` only for products whose bulletins carry VTEC events and zone codes (warnings, watches, advisories); `{wfo}` only for Weather Alerts products (issued by a forecast office), not for SPC & WPC products (national centers). The builder defines the map once and both the row's helper text and the tests read it; a product the map does not know lists all of them rather than hiding one.
- **The row's default text is generic** ("Uses the default subject"); it does not spell out the effective template or rule-wide subject.

## Goals

1. A subscriber can give each product of a rule its own subject, using placeholders that fit it, from the product step of the web wizard.
2. A rule with no per-product subjects behaves exactly as today, on every channel and in the API and app.
3. The product step stays fast with the full Weather Alerts list.

## Non-goals and anti-goals

- No per-product subject for the scheduled categories, no per-location or per-channel subjects, no new placeholders.
- No change to the rule-wide default's behaviour, to Message templates, or to the mobile app in this work.
- Must not make the product table heavy: no input rendered for a switched-off row, and no per-row server round trip (the table's rule in `.ai/rules/filament.md`: native inputs with deferred binding, not a Repeater).
- A product's subject must never change which products match or when they fire.

## Constraints

- The per-product table is a custom Blade field (`ProductMatchingTable`, view `filament.dashboard.alert-rules.product-matching-table`) with state `product_settings.{category}`; read `.ai/rules/filament.md` and `pages.md` first. The wizard step is built dynamically, so seed any new state the same way `formState()` seeds the rest (never by field defaults).
- Per-product values follow the existing pattern for `risk_levels`: read and written through `SaveAlertRule`, `ProductMatchingTable::toSaveInput()` and `EditAlertRule`, with the null-pivot fallback; per-product changes on the pivot are not activity-logged (same as `models.md` says for the levels).
- `RenderAlertSubject` stays logic-free `strtr()` substitution; a subscriber-authored template runs on every dispatch, so no new executable syntax.
- The dashboard and the API must stay in parity (`AlertRuleParityTest`); the API follows `.ai/rules/api.md` and `v1.md`.

## Left to the builder

The migration and pivot attribute plumbing, how the placeholder list per product is derived (for example from whether the product's bulletins carry a VTEC end time or UGC codes; keep one source of truth that both the helper text and the tests read), the table row's Alpine markup and preview text, the maximum length handling, naming of the relabelled rule-wide field, the API validation wording, and the tests.

## Edge cases the design must cover

- A switched-off row has no subject box; switching a product off and on again keeps its subject; a product removed from the rule loses its subject with the pivot row.
- A rule saved before this change (pivot subject null everywhere): identical subjects to today.
- A product subject set while a Message template with its own subject is attached: the product's wins; the template still applies to products with none.
- A bulletin matching products with and without subjects: the first matched product decides (see "My calls"); a first product with none falls through to template, rule-wide, default.
- A subject that is only whitespace saves as null. A 256-character subject is rejected with the same message the rule-wide box gives.
- Free owner with stored product subjects: ignored; no error on save or delivery.
- A suppressed (quiet hours) delivery logs the subject in the same way, through the same renderer.
- Dashboard edit of a rule created through the API with product subjects shows them in the rows; the app editing the same rule (rule-level field only) must not erase the per-product subjects.
