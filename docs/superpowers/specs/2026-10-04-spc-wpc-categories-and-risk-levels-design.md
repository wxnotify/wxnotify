# SPC & WPC Alerts: Categories and Per-Category Risk Levels — Design

Source: owner request — split the SPC & WPC product categories further, and split the risk-level choices by category, so a subscriber who selects several SPC & WPC products no longer sees convective and fire risk levels mixed in one list.

## Goal

1. Regroup the SPC & WPC products into four categories: **Flooding and Hydrological**, **Fire Weather**, **Convective Outlooks** and **Other Discussions, Reports, and Outlooks**.
2. Give **Fire Weather** and **Convective Outlooks** each their own "Risk levels" choices, shown inside their own section of the SPC & WPC alert form.
3. Make rule matching apply each category's chosen risk levels only to that category's products.

The single **Matching mode** section stays at the bottom of the form, unchanged.

## Decisions (user-approved, from brainstorming)

- **Categories and their products**

  | Category | Products |
  | --- | --- |
  | Flooding and Hydrological | WPC Mesoscale Precipitation Discussion (unchanged) |
  | Fire Weather | SPC Fire Weather Outlook, SPC Day 2 Fire Weather Outlook Discussion, SPC Day 3-8 Fire Weather Outlook Discussion (unchanged) |
  | Convective Outlooks (new) | SPC Day 1 Convective Outlook (`SPC.OUTLOOK`), SPC Day 2 Convective Outlook (`DY2`), SPC Day 3 Convective Outlook (`DY3`), SPC Day 4-8 Convective Outlook (`D48`) |
  | Other Discussions, Reports, and Outlooks (new) | SPC Mesoscale Discussion (`SPC.MD`), SPC Tornado and Severe Thunderstorm Reports (`STA`), SPC Public Severe Weather Outlook (Statewide) (`PWO`) |

  Flooding and Hydrological, and Other Discussions, Reports, and Outlooks, have no risk levels.
- **Fire Weather risk choices**: all five of the product's existing options — Elevated, Critical, Extremely Critical, Isolated Dry T-Storms, Scattered Dry T-Storms.
- **Convective Outlooks risk choices**: Marginal, Slight, Enhanced, Moderate, High.
- **Fire matching is out of scope.** Fire Weather Outlook risk levels are not extracted from product text today (a documented gap in `ExtractProductMetadata`), so a fire risk selection does not filter yet; the form says so. Extracting them is a separate follow-up.
- **Layout A**: each category's Risk levels checklist sits inside that category's section, directly below its products. Rejected: one combined risk block under the product sections (disconnected from the products); changing the stored format to a per-category structure (needs a data migration and buys nothing).
- **Matching mode** stays one section at the bottom; nothing about how it chooses its options changes.

### Naming note

The existing catch-all category "Other Forecasts, Outlooks, Discussions, and Reports" is **kept** for the Weather Alerts products (Area Forecast Discussion, Hazardous Weather Outlook and the like). The new category "Other Discussions, Reports, and Outlooks" holds only SPC & WPC products, and the two never appear in the same form (Weather Alerts and SPC & WPC have separate product pickers), but the names are close. The new name is the owner's choice and is kept as given.

## Data

- `App\Enums\ProductCategory` gets two cases, placed after `OtherForecastsOutlooksDiscussionsAndReports` so the SPC & WPC form lists sections in the owner's order (Flooding, Fire Weather, Convective Outlooks, Other…):
  - `ConvectiveOutlooks = 'convective_outlooks'`, label "Convective Outlooks", icon `fas-cloud-bolt`;
  - `OtherDiscussionsReportsAndOutlooks = 'other_discussions_reports_and_outlooks'`, label "Other Discussions, Reports, and Outlooks", icon `fas-file-lines`.
- A data migration moves the seven products (`SPC.OUTLOOK`, `DY2`, `DY3`, `D48` to convective_outlooks; `SPC.MD`, `STA`, `PWO` to other_discussions_reports_and_outlooks). It moves a product only while its category is still `other_forecasts_outlooks_discussions_and_reports`, so a category staff changed in the admin catalog is left alone. `down()` moves them back by the same rule.
- `ProductCatalogSeeder` is updated to the new categories for those seven codes. This is required, not cosmetic: its matched-product pass re-applies each product's category on every run, so an un-updated seeder would silently undo the migration.
- `database/data/shipped_product_catalog.php` (the factory-reset snapshot) is updated by hand for the same seven rows. `ShippedProductCatalogTest` and `FactoryResetActionsTest` guard it.
- The admin product form's category select is built from the enum and needs no change.
- No `alert_rules` change and no data migration for risk levels: `alert_rules.risk_levels` stays one flat JSON array of level names.

## Form

`AlertRuleForm::spcWpcFields()` and `productSections()`:

- For each category section that has at least one product with `risk_level_options`, add a `CheckboxList` named `risk_levels.{category}` (nested per category, like the existing `product_ids.{category}`) below the section's product list.
  - Options: the union of `risk_level_options` of the products **selected in that category**.
  - Visible only while a selected product in that category has risk options. So Convective Outlooks shows its list only when the Day 1 outlook is selected (Day 2, Day 3 and Day 4-8 have no risk levels), and Fire Weather shows its list only when the Fire Weather Outlook is selected.
  - Helper text: "Leave all unchecked to receive every risk level for the products above." Fire Weather's list adds "Fire risk levels are saved, but they don't filter alerts yet."; Convective Outlooks' list adds "Risk levels apply to the Day 1 Convective Outlook."
- The old combined `risk_levels` CheckboxList is removed.
- `hasRiskLevelProducts()` stays: it still decides the SPC & WPC matching-mode option set (RiskArea only when a selected product has risk levels) and is unchanged.
- Saving: `CreateAlertRule` and `EditAlertRule` flatten the nested `risk_levels.{category}` value into the flat `risk_levels` array (unique, in category order) before it reaches the model. A category's checklist that is hidden (its products deselected) contributes nothing, so stale ticks are dropped.
- Editing: `EditAlertRule` splits the stored flat array back by category: each category receives the stored levels that appear in the union of `risk_level_options` of **all** that category's products (not just the selected ones), so the checklist restores correctly.
- A rule created before this change stores a flat list already; it loads and saves through the same split and flatten with no migration.

## Matching

`MatchWeatherAlertRules`, `matchesRiskLevel()` and the risk-area threshold:

- For a given ingested product, the rule's **relevant** levels are `array_intersect(rule.risk_levels, product.product.risk_level_options)`.
- If the relevant set is empty, the rule applies no risk filter to that product (matches at every level), exactly as an empty list does today.
- Otherwise `matchesRiskLevel()` requires the product's extracted `risk_levels` to intersect the relevant set (null extraction still means "match on product and geography alone", unchanged), and the risk-area threshold is the lowest-ranked level in the relevant set, ranked by position in the product's own options.
- Effect: ticking only "Enhanced" while also watching the Fire Weather Outlook no longer stops fire alerts (previously the flat list made every fire alert fail the filter).
- Rules with no `risk_levels`, and products with no `risk_level_options`, behave exactly as before.

## Not included

- Extracting fire risk levels from product text, and any change to how fire products match.
- Risk levels for Day 2, Day 3 and Day 4-8 convective outlooks (they have no extraction), or any change to the Day 1 areal-outline merge.
- Changing the stored `risk_levels` format, the Matching mode section, the Weather Alerts form, or the Alert Templates.
- Renaming or restyling the old catch-all category.

## Testing

Pest feature tests (container; the full suite in parallel with 6 processes):

- **Data**: the migration moves exactly the seven products, leaves a product whose category staff changed, and reverses; a freshly migrated and seeded catalog equals the snapshot; the category enum has unique values and non-empty labels and icons.
- **Form**: the SPC & WPC form lists sections in the owner's order; Fire Weather shows its five choices only with the Fire Weather Outlook selected; Convective Outlooks shows its five only with the Day 1 outlook selected and not with only Day 2, Day 3 or Day 4-8; Flooding and Other… never show a list; both lists together never mix options; the helper text notes appear; create saves the flattened list; edit restores each category's ticks, including for a legacy flat list; deselecting a category's products drops its ticks.
- **Matching**: ticks for one category do not filter another category's products; an empty selection per category means every level; the "Enhanced ticked, fire product watched" case now matches; the risk-area threshold ignores levels from another category; existing risk-level tests keep passing.
- **Browser check** (controller, after execution): a throwaway subscriber creating and editing an SPC & WPC alert with the Day 1 outlook and the Fire Weather Outlook; confirm the section order, the per-category lists, the notes, the round trip, and that the Matching mode section is unchanged at the bottom. Delete the throwaway data afterwards.

## Rules to record

- `.ai/rules/filament.md`: SPC & WPC risk levels are nested per category in the form and flattened for storage; matching uses a product's own options.
- `.ai/rules/models.md` (or pipeline.md): the category split and the seeder/snapshot coupling.
