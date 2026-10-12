---
paths:
  - 'app/Actions/**'
---

# Actions

## Activity causer: causedBy() is ignored; pin with CauserResolver::withCauser
AppServiceProvider's CauserResolver `resolveUsing` closure ignores the `causedBy()` subject: it returns `auth()->user()`, or the impersonator. A session-less request (the app token exchange, queued jobs) therefore logs a null causer unless the call is wrapped: `app(CauserResolver::class)->withCauser($user, fn () => activity()->...->log(...))`.

## Alert rule persistence lives in SaveAlertRule
`CreateAlertRule` and `EditAlertRule` (dashboard) and `AlertRuleController` (API) all persist through `App\Actions\Alerts\SaveAlertRule` (`attributes()` for the columns, `syncRelations()` for the pivots). A change to what a rule save does belongs in that action, not in a Filament page hook or a controller, and needs a case in `AlertRuleParityTest`.

## A channel is created and sent its first code only through BeginChannelConnection
`App\Actions\Channels\BeginChannelConnection` stores the channel unverified, sends the confirmation code and removes a channel it just created when the code cannot be sent. The dashboard's `ConnectChannel` wizard and `POST /api/v1/channels` both call it; a change to what connecting a channel does belongs there, not in the page or the controller. It does not pin the activity causer (the wizard has a session, and impersonation attributes to the impersonator): the API controller wraps the call in `CauserResolver::withCauser`.

## GetMapWarnings selects through active_wwa_areas, never the event row's own columns
`App\Actions\Map\GetMapWarnings` reads an event's extent from its `active_wwa_areas` rows: an area's own polygon, else the boundary of the zone it names (public zones and fire zones by `state_zone`, counties by state and the last three digits of the FIPS, marine zones by `zone_id`). Do not fall back to `active_wwas.ugc_codes` or `polygon` (they only describe the latest bulletin of an event issued as many; see `.ai/rules/pipeline.md`). The expiry comparison binds Carbon's `now()` (not the database clock), so a test with a frozen clock keeps its fixtures consistent. The query caps anchors at 50 (after de-duplicating, keeping the given order) and its SQL was tuned against the dev data (family pre-filter, geometry resolved once in MATERIALIZED CTEs, a per-anchor bounding box before the exact distance), so a change to it should be timed on realistic data. Identical geometries of one event are de-duplicated (a storm-based warning repeats one polygon on every zone row it covers).

## SearchProducts owns the product archive search and mirrors the product page's test-product rule
`App\Actions\Products\SearchProducts` is the only query behind `GET /api/v1/products`. It selects only the listed columns (never `text`), pages by `(received_at, id)` through `AppAlertCursor`, and hides `is_test` products in production like `ViewProductController`. A change to what the archive search matches belongs here.
