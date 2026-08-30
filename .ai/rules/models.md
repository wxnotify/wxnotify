---
paths:
  - 'app/Models/**'
  - app/Models/Product.php
---

# Models

## spatie/activitylog: logOnlyDirty() alone logs nothing — call logAll() or logOnly() first
`LogOptions::defaults()->logOnlyDirty()->dontLogEmptyChanges()` (no `logAll()`/`logOnly()`) silently logs NOTHING — `$logAttributes` defaults to an empty array, and `logOnlyDirty()` only narrows an already-nonempty list. No error, no warning — the model just never produces activity rows. Always call `->logAll()` (full CRUD) or `->logOnly([...])` (restricted fields) before `->logOnlyDirty()`.

Also: the method is `dontLogEmptyChanges()`, not `dontSubmitEmptyLogs()` (easy to guess wrong). Correct namespaces: `Spatie\Activitylog\Models\Concerns\LogsActivity`, `Spatie\Activitylog\Support\LogOptions` (verified by reading vendor source, not the docs — the Traits\ and top-level namespace variants don't exist in this version).

Found while wiring AlertRule/Location for the Admin Tools audit log — both were completely silent until caught by a manual browser test.

## Product::family vs Product::category are different, non-overlapping axes
`family` (warning/watch/advisory/statement/spc_wpc/forecast_discussion/informational/administrative/aviation) is a severity/structure tier and is live-wired into the subscriber Alert Rule wizard's product filtering (`AlertRuleForm::whereIn('family', [...])`). `category` (ProductCategory, 11 hazard-type groups like Marine, Fire Weather) is a separate hazard-domain taxonomy imported from the legacy `app_product_category` table. Never conflate them — a product's category does not determine its family, and vice versa. See database/seeders/ProductCatalogSeeder.php for the full legacy-catalog crosswalk and reasoning.
