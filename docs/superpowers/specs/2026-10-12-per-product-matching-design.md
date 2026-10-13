# Per-product matching (a product table on the alert wizard's "Set it up" step) — Design

Source: owner request, 2026-10-12. On the alert wizard's "Set it up" step, replace the toggle list with a table per category: each product has its own switch, title and description, matching mode (with that mode's inputs), and an example.

## Goal

A subscriber builds one alert that watches several products, each matched the way that suits it: Tornado Warning by polygon at their home, an Area Forecast Discussion by their location's office, a Mesoscale Discussion by polygon, and so on, without making one alert per mode. The channels, quiet hours, template and name stay the alert's own.

## Decisions (user-approved, from brainstorming)

- **Per-product settings live on the rule's pivot.** `alert_rule_product` gains the mode, WFO and zone codes; a new `alert_rule_product_location` table holds each product's saved locations. Rejected: splitting a wizard session into several ordinary rules behind the scenes (My Alerts would show several rules for one session, and editing would have to regroup them); keeping one mode per rule (does not deliver per-product modes).
- **Polygon defaults use the catalog's `has_polygon` flag as it is.** A product with `has_polygon` defaults to "By product polygon"; every other product defaults to "By my location". Both preselect the subscriber's first saved location (lowest id) when they have one. This includes Severe Weather Statement and WPC Mesoscale Precipitation Discussion, which the owner's list left out. Rejected: an exact list of eight (would have changed the flag).
- **A row's inputs sit under its mode select, in the same cell** (locations picker, WFO picker or county/zone picker for the chosen mode, then the mode's helper text). Rejected: an expandable row below the product.
- **The REST API gains a per-product list and keeps today's flat fields; the mobile editor is unchanged for now.** For a rule whose products do not all share one mode, the mobile editor shows "Edit this alert's matching on the web" and does not save product or matching changes. Rejected: building the mobile table in the same change; leaving the API alone (a mobile save would flatten per-product settings).
- **Rows whose switch is off stay visible, dimmed, with defaults pre-filled,** so "Enable all" only flips switches. Rejected: hiding the controls until the product is on.
- **Each category keeps a search box** (filters that category's rows by title or description as typed).
- **SPC & WPC defaults** (owner did not specify; ruled during design): its allowed modes stay as they are today, per product. A product with risk levels offers Nationwide and "By risk area" and defaults to "By risk area"; any other SPC/WPC product offers Nationwide and "By product polygon" and defaults to polygon. "By my location" is not offered there today.

## Data model

- `alert_rule_product` (existing pivot, `alert_rule_id`, `product_id`) gains `matching_mode` (string, not null), `wfo` (nullable), `county_zone_codes` (nullable text, the same comma-separated string `alert_rules` stores today).
- `alert_rule_product_location` (new): `alert_rule_id`, `product_id`, `location_id`; primary key on all three; `alert_rule_id`, `product_id` and `location_id` each reference their table and cascade on delete, like `alert_rule_location`. That a row's product is one the rule watches is kept by `SaveAlertRule`, which replaces the pivot rows and these rows together in one transaction (no composite foreign key).
- **Backfill (the same migration):** for every rule in a product-based category, each of its pivot rows takes the rule's `matching_mode`, `wfo`, `county_zone_codes`, and one `alert_rule_product_location` row per entry of its `alert_rule_location`. Nothing a subscriber receives changes at deploy.
- The rule's own `matching_mode`, `wfo`, `county_zone_codes` and the `alert_rule_location` pivot stay in the schema for one release, no longer read for product-based rules (a rollback net); a later change drops them. Threshold, daily summary and lightning rules keep their own `location_id` and are not touched.
- `risk_levels` stays a per-rule column and a per-category checklist; a "By risk area" product filters on it as today.
- Models: `AlertRule::products()` loads the pivot columns (`->withPivot([...])`); a small value object, `ProductMatchSettings` (mode, locations, WFO, zone codes), is built per product through `AlertRule::settingsFor(int $productId)`. Locations for a product are read through the new table.

## Matching

`MatchWeatherAlertRules` is unchanged in shape: a rule matches a segment when its product is one the rule watches and the segment's geography satisfies the rule's mode. The mode, locations, WFO and zones now come from `settingsFor($segment->product_id)` instead of the rule. A rule with several matching segments still gets one delivery per channel carrying every matching segment id; its matched location is the matching location with the lowest id (now among the matching products' locations).

- A bulletin without segments is matched on its own `product_id`, the same way.
- A product the rule watches but has no settings row for cannot exist: the pivot row is the watch.
- Every per-mode behaviour stays as documented in `.ai/rules/pipeline.md` (Location mode's office fallback for products with no UGC or polygon, Polygon mode's fallback to the location check, County/Zone being UGC-only, SPC and WPC never using the office fallback).

## Saving

`SaveAlertRule` stays the one writer for the dashboard and the API.

- **Input:** a new `product_settings` list, `[{product_id, matching_mode, location_ids, wfo, county_zone_codes}]`. The existing flat input (`product_ids` with `matching_mode`, `location_ids`, `wfo`, `county_zone_codes`) expands to the same list, one entry per product, so an old client behaves as before.
- **Sync:** the pivot rows (mode, WFO, zones) and `alert_rule_product_location` are replaced together in the rule's transaction; settings that a mode does not use are cleared (a product whose mode is not WFO keeps no WFO).
- **Validation (dashboard form and `SaveAlertRuleRequest`):** at least one product enabled; for each enabled product its mode must be one its category allows; location/polygon/risk area need at least one of the subscriber's own locations; WFO needs a known office (`cwa_boundaries`); County/Zone needs codes; locations belong to the subscriber (as `location_ids` does today). The dashboard's per-category "Select at least one product" error stays on the first section.
- The rule's own `matching_mode`, `wfo`, `county_zone_codes` and `alert_rule_location` are no longer written for product-based rules.

## Wizard UI

- Each category keeps its collapsible `Section` (icon and label; Tornado and Severe Thunderstorm expanded on a fresh create; others collapsed unless they hold a switched-on product).
- Inside: a search box; an "Enable all" / "Disable all" switch (flips every row of this category, leaving each row's mode and inputs as they are, and never touches another category); then the table.
- **Columns:** a switch; the product title with its description beneath; the matching mode (a select of the category's allowed modes, the chosen mode's helper text beneath it, and that mode's inputs); an example icon.
- **Inputs by mode:** Nationwide: none. By my location, By product polygon, By risk area: a locations multi-select (with the existing "add a location" action and the "No saved locations yet" helper). By issuing office: the WFO picker. By county/zone: the state filter and the zones multi-select.
- **Example:** an icon that opens a modal with the product's `example` text, fetched when clicked. A product with no example has no icon.
- **New rows' defaults:** as in the decisions above; on edit, the stored settings (and a stored but now-disallowed mode is shown as stored, not silently changed).
- **State shape:** per category, `product_settings.{category}` keyed by product id, each `{enabled, matching_mode, location_ids, wfo, county_zone_codes}`; the Create and Edit pages flatten it for `SaveAlertRule` and split it back on edit (like `product_ids` and `risk_levels` today).

## API

- `AlertRuleResource` returns `product_settings` (per product: id, mode, `location_ids`, `wfo`, `county_zone_codes`) alongside the existing fields. The flat `matching_mode`, `location_ids`, `wfo` and `county_zone_codes` stay: they hold the shared value when every product agrees, and a rule whose products differ returns `matching_mode: "mixed"` with null/empty others.
- `SaveAlertRuleRequest` accepts `product_settings` or the flat fields, not both in one request (422 if both). Validation as above.
- `BuildAlertRuleOptions` (the options the app reads) adds each product's default mode and allowed modes.

## Mobile editor (unchanged this round)

`RuleEditor` and `RuleFormPresenter` keep working for single-mode rules (they save the flat fields, which expand to every product). For a rule the API returns as `mixed`, the editor shows "Edit this alert's matching on the web" in place of the product and matching sections and sends no product or matching fields, so a save of the name, channels or quiet hours cannot change per-product settings.

## My Alerts list

The matching-mode column (`AlertRulesTable`) shows the shared mode when all products agree, and "Mixed" otherwise; its location summary shows the union of the products' location labels.

## Testing

- **Matcher:** each product of one rule matched by its own mode (polygon product by polygon, another by location, another by WFO or zone); a product outside its own locations does not fire while a sibling product does; a bulletin with matching segments of two products with different settings gives one delivery with both; the matched location is the lowest id across matching products; a bulletin without segments; SPC and WPC modes per product.
- **Migration/backfill:** rules of every product-based mode keep delivering identically; Threshold, daily summary and lightning rules untouched.
- **SaveAlertRule:** `product_settings` writes pivot rows and locations; the flat input expands to the same; a mode's unused settings cleared; a stale mode rejected; another subscriber's location rejected; replace-on-edit leaves no orphan rows.
- **Dashboard form:** the table per category; defaults (polygon product vs other; SPC risk-level product vs other; first location preselected; no locations); Enable all / Disable all per category only; search; the example modal (and no icon without an example); validation per mode; edit hydrates stored settings.
- **API:** `product_settings` read and write; flat input still works; `mixed` on read; both forms in one request is a 422.
- **Mobile:** a mixed rule shows the notice and a save sends no product or matching fields; single-mode rules unchanged.
- **Browser:** a real-browser pass of the wizard (create and edit, each mode's inputs, no console entangle errors, Enable all, search, the example modal), since Pest's `fillForm()` cannot detect the dynamic-step entangle bug.

## Rules to record

In `.ai/rules/pipeline.md`: product-based rules match each product by that product's own mode and locations (`AlertRule::settingsFor()`), never by the rule's own columns. In `.ai/rules/actions.md`: `SaveAlertRule` writes the pivot settings; the flat input expands to them. In `.ai/rules/filament.md`: whichever table approach the spike settles on, and why.

## Out of scope

The mobile editor's per-product table; dropping the rule's old columns (a follow-up after one release); per-product channels, quiet hours, subjects or templates; changing what any matching mode matches; the Threshold, Daily Summary and Lightning categories; adding products to the catalog or changing example text.

## Risks to settle in the plan

- **Dynamic-step Filament bug (`.ai/rules/filament.md`).** Selects that are searchable, multiple or relationship-backed inside a dynamically built Wizard step can throw entangle errors and desync state. The plan's first task is a throwaway spike in a real browser: a table-mode `Repeater` (fixed items, no add/delete/reorder) versus a custom Blade field with plain `wire:model` inputs, with a locations multi-select and the county/zone pickers in a row. The table approach is chosen from the result; the rest is built on it.
- **Page weight.** Weather Alerts has about 150 rows, each with a mode select and conditional inputs. Only the open category's rows should be rendered live, and per-row show/hide should not need a server round trip where it can be client-side. The spike measures this.
- **Readers of the rule's own mode and locations.** Each must move to per-product settings or be shown to be safe: `MatchWeatherAlertRules` (`$rule->locations` and the mode), `LocationDeletionGuard`, `AlertRulesTable`, `EditAlertRule`, `AlertRuleResource`, `SaveAlertRuleRequest`, `BuildAlertRuleOptions`, `AlertRule::displayName()` and `AlertNameSummary` if they read the mode, the mobile `RuleEditor`/`RuleFormPresenter`, and the factory and seeders. The plan lists each with its change.
- **Activity log.** `AlertRule` logs changes with Spatie; pivot changes are not logged today. Left as is unless it proves misleading.
- **`has_polygon` accuracy.** The default depends on the flag being right for each product; the spec uses it as it is.

## Amendments made while planning (2026-10-12)

- **A pivot row with no `matching_mode` inherits the rule's own mode, WFO, zones and locations** (`AlertRule::settingsFor()`). The backfill still copies the rule's values onto every pivot row, and `SaveAlertRule` always writes complete rows and nulls the rule's own columns, so after this change every rule saved through the dashboard or API reads only per-product settings. The inherit rule exists so existing tests, factories and seeders that attach products the old way keep working, and as part of the rollback net. This replaces the sentence "no longer read for product-based rules".
- **API keep path:** a PUT of a product-based rule that carries none of `product_ids`, `product_settings`, `matching_mode`, `location_ids`, `wfo`, `county_zone_codes` or `risk_levels` keeps the stored products, per-product settings and `risk_levels`. This is how the mobile editor saves a mixed rule without changing it.
- **Allowed versus offered modes:** the table offers the modes in "SPC & WPC defaults" above; validation accepts the category's full set (Weather Alerts: every mode but "By risk area"; SPC & WPC: Nationwide, "By product polygon" and "By risk area"), so a rule saved earlier with "By risk area" on a plain SPC product keeps saving. A mode already stored for a product is shown as stored even when not offered.
