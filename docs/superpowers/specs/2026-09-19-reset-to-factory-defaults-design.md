# Reset to Factory Defaults — Design

Source: master plan (`wxnotify-nextgen-plan.html`, FEAT > Admin tooling > "Reset to factory defaults", and the OPEN item "Admin role granularity"). This spec settles how it lands on the current codebase.

## Goal

An owner-only admin page that wipes chosen groups of data back to a clean state, available in every environment (including production). Safety comes from role restriction and a heavy confirmation flow, not an environment gate.

## Decisions (user-approved)

- **Owner role:** new `users.is_owner` boolean (implies `is_staff`). Editable in the admin User form; audit-logged like `is_staff` (`LogsActivity` fields on `User`). The first owner is created outside the UI (artisan command or tinker).
- **Stopping the pipeline:** a `PipelineStack` contract with a `stop()` method. Today's only implementation cannot stop anything (Pipeline Control is a static placeholder), so the Pipeline toggle requires the admin to tick "I have stopped the pipeline services". When Downloader control ships, its real implementation replaces the default binding and the checkbox is removed. Services are never auto-restarted after a reset.

## Access

- Filament page `ResetToFactoryDefaults` in the admin panel, nav group "System". `canAccess()` requires `is_owner`; hidden from everyone else. No `app()->environment()` check.

## Toggles (all off by default; at least one required)

1. **Pipeline & dispatch data** — one statement: `TRUNCATE ingested_products, active_wwas, delivery_logs RESTART IDENTITY`. Includes test-generated products. Saved `test_product_scenarios` are kept (configuration, not runtime data). The plan's "CAP/VTEC agreement log" has no table yet, so nothing to wipe; add it here when it exists. Requires the pipeline-stopped acknowledgment. B2/S3 archived text is left in place.
2. **Catalogs** — reset in place, not truncated, so product ids survive and subscribers' `alert_rule_product` rows are not cascaded away.
   - Products: shipped products (matched by `code`) revert to seed values via the same source as `ProductCatalogSeeder`; admin-added products (codes not in the shipped set) are deleted. The preview shows how many alert rules reference products that will be deleted.
   - Channel types: the seed data currently lives inline in the `create_channel_types_table` migration. Extract it to a shared source (e.g. `database/seeders/ChannelTypeSeeder`, `updateOrCreate` by slug) used by both the migration and the reset; admin-added channel types are deleted, shipped ones revert.
3. **Subscriber data** — delete every user where `is_staff = false` (cascades to locations, alert rules, channels, rule pivots, delivery logs owned by them, notifications, announcements pivots, etc.). Staff and owner accounts are always preserved. The flagged test-fixture account is not staff, so it is deleted like any subscriber. No grace period, unrecoverable. Deleted via query builder (no per-model events), with one summary audit entry.

## Confirmation flow

1. **Re-authentication** — current password, plus the app-authenticator (TOTP) code when the owner has 2FA enabled.
2. **Preview** — live counts for the chosen toggles only (e.g. "42,318 ingested products, 6 active WWAs, 1.2M delivery log rows"; "14 subscribers, 22 locations, 31 alert rules"; "3 custom products removed, affecting 5 alert rules"). When Pipeline is chosen and real (non-test) subscribers exist, state that their activity/health widgets will read empty until new data arrives.
3. **Typed confirmation** — `RESET` for Pipeline and/or Catalogs; the instance's domain (host of `APP_URL`, case-insensitive) when Subscriber data is checked.
4. **Execute** — one action class inside a single DB transaction (all selected toggles commit or none do); synchronous, not queued.
5. **Audit** — a single `activity()` entry by the owner recording the toggles and the row counts deleted, written after commit. `activity_log` is never touched by any toggle.

## Structure

- `app/Actions/Admin/ResetToFactoryDefaults.php` — orchestrator (`__invoke(array $toggles, User $by): array` returns per-group counts).
- `app/Actions/Admin/FactoryReset/` — `WipePipelineData`, `ResetCatalogs`, `WipeSubscriberData`, `PreviewFactoryReset` (counts only, no writes). Each takes no UI concerns.
- `app/Contracts/PipelineStack.php` + `app/Support/Pipeline/UnmanagedPipelineStack.php` (default binding: `canStop(): bool` false, `stop()` throws).
- Page + Blade view under `app/Filament/Admin/Pages/`, using a multi-step modal/wizard: choose toggles → re-auth → preview + typed confirmation → run.
- Migration `add_is_owner_to_users_table`; `User` fillable/cast/`LogsActivity`; `UserForm` toggle; `UsersTable` indicator.
- Rule note in `.ai/rules` for the `is_owner` gate and "staff are never wiped".

## Testing

Owner/non-owner/staff access (including HTTP 403); each toggle wipes only its own tables; staff and owners survive the subscriber wipe; `activity_log` survives every reset and gains one entry; RESET vs domain confirmation tiers (wrong value rejected); re-auth failure (bad password, bad TOTP); pipeline acknowledgment required; catalogs reset keeps product ids, restores edited seeded rows, deletes custom products, leaves `alert_rule_product` for shipped products intact; preview counts match what is then deleted; a forced mid-run failure rolls back every selected toggle; nothing runs with no toggle selected.

## Out of scope

Automatic service restart; B2/S3 cleanup; shapefile layers/GIS data reset; artisan CLI parity; roles beyond staff/owner.
