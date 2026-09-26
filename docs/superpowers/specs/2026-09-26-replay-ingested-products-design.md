# Replay Ingested Products — Design

Source: an admin request for a way to push products that were already ingested through the alert pipeline again, to check that alert notifications actually deliver before the system goes fully live. It sits beside the Test Product Generator (`docs/superpowers/specs/` scenarios) and reuses the same safety model: non-production only, and only ever delivered to dedicated test accounts.

## Goal

An admin can select one or more products on the Ingested Products table and "Replay" them. Each replay is re-ingested as a new test-flagged copy that goes through the normal alert matching and delivery, so it reaches only rules owned by test accounts (`users.is_test_account`). Duplicate alerts are the point. The feature is unavailable in production.

## Scope

In: a `replay_of_id` column, the replay action, an observer tweak, the "Replay" row action and bulk action on the Ingested Products table, the "Replay of" link on the product detail view, tests.

Out: threshold, lightning and daily-summary alerts (not driven by ingested products); re-stamping VTEC times or otherwise editing a replayed product's text; a dedicated replay page with filters or timed playback; any change to `MatchWeatherAlertRules`; writing to `active_wwas`, `pipeline_deliveries`, the heartbeat or heartbeat pings.

## Decisions (user-approved)

- Mechanism: clone as a test product (a new `IngestedProduct` flagged `is_test`, linked back to the original); the original row is untouched. Re-running matching on the original was rejected as riskier.
- UI: a "Replay" action on each row plus a "Replay" bulk action, both on the Ingested Products table.
- A replayed product does not write to `active_wwas`; this tool exists to check that notifications work.
- Gated by the environment: only when `! app()->environment('production')`, like the Test Product Generator.

## Data

Migration adds `ingested_products.replay_of_id` (nullable, foreign key to `ingested_products.id`, `nullOnDelete()`, indexed). `IngestedProduct` gains `replay_of_id` in its `#[Fillable]` list (it is only ever set by the server-side replay action, never from form input), and two relations: `replayOf(): BelongsTo` and `replays(): HasMany`. The self-referencing key needs no change to `WipePipelineData` (`TRUNCATE` of the same table).

## Replay action (`App\Actions\Pipeline\ReplayIngestedProduct`)

`__invoke(IngestedProduct $original): IngestedProduct`. It refuses in production (`RuntimeException`), so a forged Livewire call cannot bypass the UI gate. Otherwise it re-ingests the original's stored `text` through `IngestProductText`, the same code path real intake and the generator use, so a replay exercises the current parser. Arguments:

- identity: the original's `cccc`, `ttaaii`, `awipsid`, `wmo_ddhhmm`; `nwws_id` null.
- `isCap`: `$original->format === 'cap'`.
- `file_path`: `replay/{original id}-{uuid}` (unique per replay; `updateOrCreate` is keyed on it).
- extra columns: `is_test = true`, `source` copied from the original, `replay_of_id = original id`, `test_product_scenario_id` null, and **`wmo_header_key = null`**.

`wmo_header_key` is null on purpose. `ingested_products.wmo_header_key` is read only by `IngestOncePerWmoHeader` (intake dedup); nothing in matching, delivery, templates, slug generation or the View Product page reads it. Keeping the key would make that dedup drop a later real product with the same header as a duplicate of the test copy, because its `exists()` check does not exclude test rows. Null is already normal (the generator's rows and real products with unparseable headers or CAP have it). If ingest returns null (a CAP parse failure) the action throws so the caller reports it.

`IngestedProduct` keeps its `created` hook, so the copy gets its own `view_slug` (suffixed on collision) as usual. `received_at` is now.

## No `active_wwas` write

`IngestedProductObserver::created()` currently sends VTEC products to `SyncActiveWwa` (which writes `active_wwas` and then matches) and everything else straight to `MatchWeatherAlertRules`. Add a first branch: when `replay_of_id !== null`, call `MatchWeatherAlertRules` directly and return, skipping `SyncActiveWwa`. Matching does not need an `active_wwas` row: `matchesActiveWwaPolygon()` returns null when none exists and polygon matching then falls back to the product's own parsed polygon, and a test product only ever looks up `is_test = true` rows, so it never touches real ones. Known trade-offs, accepted: a continuation statement (SVS/FFS) with no polygon of its own falls back to zone matching instead of the stored polygon, and custom alert templates read their Expiration and Motion sections from `active_wwas`, so those two sections are omitted in a replayed alert.

## Routing safety

Unchanged and relied on: `MatchWeatherAlertRules` restricts an `is_test` product to rules whose user has `is_test_account = true` (both the VTEC and non-VTEC paths reach it). The replay adds no routing code. The copy is never recorded in `pipeline_deliveries` (no ingest controller involved), so Pipeline Control is unaffected, and it triggers no heartbeat ping.

## UI (Ingested Products table)

- Row action "Replay" and bulk action "Replay", `visible` only when not in production. Each shows a confirmation modal saying the product will be re-sent through the alert system to test accounts only and will create duplicate alerts.
- The bulk action replays the selected products in their original order (`received_at` ascending, then `id`) and accepts at most 25; more than 25 replays nothing and shows a notification asking for a smaller selection.
- Result notification: "Replayed N product(s) to test accounts", or a warning naming how many could not be replayed. Replay failures never stop the remaining products in a bulk run.
- The product detail view shows a "Replay of" link to the original when `replay_of_id` is set. The existing Test filter finds the copies.

## Security and safety

- Staff-only through the existing admin panel and Ingested Products access; no new routes.
- The production gate is enforced in the action visibility and in the action class itself.
- The copy's `is_test = true` is set by the action, never from user input; `replay_of_id` is not user-editable.

## Migration note

Adds one migration, which is not run on the dev DB by us; the user runs `php artisan migrate`.

## Testing

- Replaying a VTEC product and a non-VTEC product creates an `is_test` copy with the original's text and identity, `source` copied, `wmo_header_key` null, `replay_of_id` set and its own `view_slug`; the original row is unchanged.
- The copy reaches only rules owned by test accounts: a rule owned by a real user matching the same product is not delivered, a test-account rule is, on both the VTEC and non-VTEC paths.
- A replay never creates or updates an `active_wwas` row, and never touches an existing real one.
- A real product with the same header as a replayed one is still ingested (not dropped by dedup).
- The action refuses in production, and the row and bulk actions are hidden there; a forged call in production does nothing.
- The row action and the bulk action work from the table; the bulk action replays oldest first, refuses more than 25, and one failing product does not stop the rest.
- The "Replay of" link renders; the migration's `nullOnDelete()` clears `replay_of_id` when the original is deleted.
