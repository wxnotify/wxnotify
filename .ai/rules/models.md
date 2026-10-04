---
paths:
  - 'app/Models/**'
  - app/Models/Product.php
  - app/Models/ActiveWwa.php
---

# Models

## spatie/activitylog: logOnlyDirty() alone logs nothing — call logAll() or logOnly() first
`LogOptions::defaults()->logOnlyDirty()->dontLogEmptyChanges()` (no `logAll()`/`logOnly()`) silently logs NOTHING — `$logAttributes` defaults to an empty array, and `logOnlyDirty()` only narrows an already-nonempty list. No error, no warning — the model just never produces activity rows. Always call `->logAll()` (full CRUD) or `->logOnly([...])` (restricted fields) before `->logOnlyDirty()`.

Also: the method is `dontLogEmptyChanges()`, not `dontSubmitEmptyLogs()` (easy to guess wrong). Correct namespaces: `Spatie\Activitylog\Models\Concerns\LogsActivity`, `Spatie\Activitylog\Support\LogOptions` (verified by reading vendor source, not the docs — the Traits\ and top-level namespace variants don't exist in this version).

Found while wiring AlertRule/Location for the Admin Tools audit log — both were completely silent until caught by a manual browser test.

## Product::family vs Product::category are different, non-overlapping axes
`family` (warning/watch/advisory/statement/spc_wpc/forecast_discussion/informational/administrative/aviation) is a severity/structure tier and is live-wired into the subscriber Alert Rule wizard's product filtering (`AlertRuleForm::whereIn('family', [...])`). `category` (ProductCategory, 11 hazard-type groups like Marine, Fire Weather) is a separate hazard-domain taxonomy imported from the legacy `app_product_category` table. Never conflate them — a product's category does not determine its family, and vice versa. See database/seeders/ProductCatalogSeeder.php for the full legacy-catalog crosswalk and reasoning.

## ActiveWwa::forProduct() is is_test-scoped
scopeForProduct() matches the VTEC key AND is_test, so synthetic (Test Product Generator) events never resolve for real products or vice versa, even when the VTEC key collides. Any new lookup of active_wwas from an IngestedProduct must go through forProduct() rather than re-deriving the VTEC key.

## Location position times: poll time, fix time and creation date are three different things
`locations.mobile_position_updated_at` is when WxNotify last polled a mobile location successfully: it drives the poll schedule (`traccar:poll-positions` due check) and the Locations map's stale (gray) pin, so it must stay a poll time. `locations.mobile_position_fixed_at` is Traccar's own `fixTime` for the position, stored by the poller only when a position carries a usable one (a missing or unparseable fixTime keeps the old value). The Locations list "Position updated" column shows `Location::positionLocatedAt()`: the fix time for mobile (blank until the first fix), `created_at` for stationary. Both timestamps are excluded from the activity log and cleared when a location is saved as stationary. A mobile location linked to a Traccar device ID that no longer exists on the server (demo servers reset and re-issue IDs) used to be silently never updated; now `traccar:poll-positions` fetches `/api/devices` only for due locations that got no position (never when every one did), and sets `traccar_device_missing_at` for those whose device is absent (keeping the first-noticed time), clears it when the device is listed or a position arrives, and changes nothing if the devices call fails. The `Location` saving hook also clears it when `traccar_device_id` changes or the location becomes stationary, and it is kept out of the activity log. It is shown as a red 'Device not found on server' cell in the Locations list, a warning Callout on the Edit page (until another device is chosen) and a `location_device_missing` row in My Alerts' Needs attention (which replaces `location_no_position` for that location).

## Alert subjects name the product that fired; the Day 1 outlook is "SPC Day 1 Convective Outlook"
`RenderAlertSubject` without a template titles a Weather Alerts or SPC & WPC alert with the fired product's own name (`Product::$name`), not `AlertRule::displayName()`, which joins every product the rule watches; a name the subscriber gave the rule still wins, a template still wins over both, and the scheduled categories (no product) keep `displayName()`. Day 1 outlooks (SWODY1 and its PTSDY1 areal outline) share the single `SPC.OUTLOOK` product, renamed by a data migration from "SPC Convective Outlook" to "SPC Day 1 Convective Outlook" so it matches DY2/DY3/D48; the migration renames only a name still at the shipped value (staff may have edited it) and never touches the code. A product rename in a migration also needs `database/data/shipped_product_catalog.php` updated by hand (ShippedProductCatalogTest, FactoryResetActionsTest), because the seeder leaves the names of matched products alone.

## SPC & WPC products use their own categories; risk levels are matched per product
The SPC & WPC products are grouped Flooding and Hydrological (`WPC.MPD`), Fire Weather (`SPC.FIRE`, `FD2`, `F38`), Convective Outlooks (`SPC.OUTLOOK` = Day 1, `DY2`, `DY3`, `D48`) and Other Discussions, Reports, and Outlooks (`SPC.MD`, `STA`, `PWO`). The old `other_forecasts_outlooks_discussions_and_reports` category now holds only Weather Alerts products. The category lives in four places that must change together: the `ProductCategory` enum, a data migration (moves a product only while it is still in the old category), `ProductCatalogSeeder` (its matched-product pass re-applies categories on every run, so a stale seeder silently undoes a migration) and the hand-edited `database/data/shipped_product_catalog.php` snapshot. `MatchWeatherAlertRules::matchesRiskLevel()` counts only the rule's levels that appear in the product's own `risk_level_options` (none means no filter), because the rule's `risk_levels` is one flat list across every category it watches.
