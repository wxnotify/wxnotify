---
paths:
  - 'app/Actions/Alerts/**'
  - app/Actions/Alerts/GetActiveAlertsForUser.php
  - app/Actions/Alerts/SaveAlertRule.php
  - app/Actions/Alerts/FindAppAlert.php
---

# Alerts

## Needs attention: an unverified channel is unconfirmed, never failing
`GetAlertsNeedingAttention` never lists an unverified channel as failing: the dispatcher logs its refusal of an unconfirmed channel as a `failure` row, so `failingChannels()` excludes channels with a null `verified_at` and the unverified row (which tells the subscriber to confirm it and that alerts will not be sent until then) is the only one shown.

## The outlook report is paid-only, enforced in SaveAlertRule
`SaveAlertRule` refuses to create or save an `outlook_report` rule for a Free owner with a ValidationException on `category` (`SaveAlertRule::PAID_ONLY_MESSAGE`); the dashboard also disables the category card and re-keys that error to `data.category` through `AlertRuleForm::saveForForm()`. An existing rule of a downgraded owner is kept (Active toggle and delete still work) but `DispatchOutlookReports` skips it. The API refuses the category on write regardless of tier.

## Active alerts carry place_ids; cache key is v2
/api/v1/active-alerts events carry place_ids (Location ids, same order as the places labels) so the app can filter events by the selected place. Cache key is app-active-alerts:v2:{user}; bump it whenever the cached shape changes so a deploy never serves old shapes. If two locations share a label, places collapses them but place_ids lists both ids.

## Active alerts use the weather place list; cache key is v3 per token
GetActiveAlertsForUser takes its places from GetUserWeatherPlaces (this device's location first, then other phones, then id, cap 25) so the app's per-place filter never hides a warning for the selected chip. Cache key is app-active-alerts:v3:{user}:{tokenId|none} and supersedes the earlier v2 note; bump it whenever the cached shape changes.

## Per-product subjects: absent key keeps, explicit empty clears
`alert_rule_product.custom_subject_template` is a product's own subject. SaveAlertRule::productSettings() carries the key ONLY when the input entry had it (trimmed; blank = null) and syncRelations() reads the stored pivot subjects BEFORE `sync()` replaces the rows: an entry with the key writes it (null/empty clears), an entry without it, and the flat `product_ids` shape (the mobile app's), keep the stored value for every product that stays; a newly added product starts with none; a removed product loses it with its pivot row. The dashboard always sends the key for a paid owner and OMITS it for a Free owner (ProductMatchingTable::toSaveInput(), so a downgrade never wipes stored subjects); the API validates `product_settings.*.custom_subject_template` as nullable|string|max:255 in the paid-tier block only. Never make an omitted key clear a subject: the app would erase them on every save. Pivot subject changes are not activity-logged (same as risk_levels).

## A push tap's alert reference is resolved by GET /api/v1/alerts/by-ref/{ref}
A push for an alert with no product page carries `wxnotify://alert/{ref}` (AlertRef: `p.{rule}.{product}` or `b.{batch key}`) because the delivery row, and so the alert id, does not exist at send time. `FindAppAlert::byRef($user, $ref)` finds the subscriber's own group for it (same group identity as `inAlertGroup()`/`AppAlert::keyFor()`, lowest row id, needs a successful mobile push row) and returns null for a malformed, unknown, not-yet-written or someone else's reference; the route `GET /api/v1/alerts/by-ref/{ref}` answers the usual AlertDetailResource or a 404 and must stay registered before `/alerts/{id}`. The app's AlertOpen queues the read, then looks the alert up (3 s) and opens its detail; every failure falls back to the Inbox. The lookup is deliberately a 404, never an error, for a tap that beats the delivery-log write.

## Outlook map files always go through OutlookMapDisk
Every read, write, exists and delete of the stored Outlook map images (outlook-maps/{batch_key}/...) uses App\Support\OutlookMapDisk::disk(), never Storage::disk('local') or any named disk. The disk comes from OUTLOOK_MAPS_DISK (config filesystems.outlook_maps_disk, default local); multi-server deployments set s3 so the delivery server and API server share the files. An unreachable disk must read as no map (null url, 404), never a 500; an undefined disk name throws InvalidArgumentException naming OUTLOOK_MAPS_DISK.
