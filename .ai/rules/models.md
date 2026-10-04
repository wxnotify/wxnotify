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
