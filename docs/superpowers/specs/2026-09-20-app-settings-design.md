# App Settings (first pass) — Design

Source: master plan (`wxnotify-nextgen-plan.html`, ARCH "App settings", FEAT Admin tooling "System → App settings", plus the TIER, DATA and FEAT (Heads Up) sections that make values in it admin-editable). The plan describes a general home for admin-editable, runtime-changeable global settings, built ahead of most of what will live in it.

## Goal

A tabbed admin page for global runtime settings, backed by `spatie/laravel-settings`, seeded with the values that have real behavior to control today: maintenance mode and the tier/threshold defaults. Every change is accountable in the audit log.

## Scope

In: the settings foundation, the admin page, `GeneralSettings` (maintenance mode), `TierThresholdSettings` (tier limits + Lightning radius floor), wiring the existing code to read them, the maintenance-mode banner and write guard, the audit-log hook.

Out (each waits for something to control): third-party integration toggles (translation, short URLs, ingest heartbeat, exception management, product archival, status page, email relay), the feature-flags group (Heads Up kill switch), the Secrets group, Heads Up's timing thresholds, and any public API tier (none exists yet; the write guard is model-level so it will cover one when it does).

## Decisions (user-approved)

- Packages named by the plan: `spatie/laravel-settings` and `filament/spatie-laravel-settings-plugin` (new Composer dependencies, approved via the plan; check Laravel 13 / Filament 5 compatibility when installing).
- First-pass groups: **General** (maintenance mode) and **Tier & threshold defaults**.
- Maintenance mode = banner + a write guard on subscriber-owned data; reads and navigation keep working.
- Lightning minimum-radius default **10 miles**, bounds **5–15** (the plan says "on the order of 10–15 miles"; the form currently hardcodes 5, so this raises the minimum for newly created/edited rules).
- Existing Lightning rules keep their stored radius; the floor is enforced only when a rule is created or edited.
- Access is staff-only (`is_staff`), the same flat rule as the other admin tools. Owner-only is reserved for the future Secrets group.

## Foundation

- Install the two packages; publish the settings migration/config. Settings are typed PHP classes grouped by concern, in `app/Settings/`, changed through the package's versioned settings migrations.
- Cache: the package's cache is enabled, using the app's default cache store (Redis where Redis is the cache store; currently `database` in local dev).

## Admin page

- A Filament page "App settings" in the **System** navigation group, one tab per settings class (General, Tier & threshold defaults), built with the plugin's settings-page support.
- Visible to and editable by staff only.

## Settings classes

`GeneralSettings`: `maintenance_mode` (bool, default false), `maintenance_message` (nullable string, shown in the banner; default text if empty).

`TierThresholdSettings` (defaults equal today's hardcoded values, except the Lightning floor):

| Property | Default | Notes |
|---|---|---|
| `free_locations` | 1 | Plus `plus_locations` 5; Pro locations stay unlimited and are not a setting |
| `free_no_cost_channels` | 1 | the Free tier's single no-cost channel slot; Plus/Pro channels stay unlimited |
| `plus_locations` | 5 | |
| `free_traccar_servers` / `plus_traccar_servers` / `pro_traccar_servers` | 0 / 1 / 3 | |
| `free_traccar_devices_per_server` / `plus_…` / `pro_…` | 0 / 5 / 10 | |
| `lightning_min_radius_miles` | 10 | bounds 5–15 |

Each numeric field carries validation bounds in the settings form (integers, sensible minimums, upper caps) so a value can move inside its designed range but not outside it. The exact bounds for the tier counts are set in the plan and written as rules on the form.

## Wiring

- `User::locationLimit()`, `traccarServerLimit()`, `traccarDeviceLimitPerServer()` and `canAddChannel()` read from `TierThresholdSettings`; Pro's locations and channels remain `null` (unlimited).
- The Lightning `radius_miles` input in `AlertRuleForm` takes its minimum from `lightning_min_radius_miles` and enforces it server-side (a form rule, not only the input attribute). Existing rules are untouched.

## Maintenance mode

- **Banner:** shown on the subscriber dashboard (a panel render hook) while `maintenance_mode` is on, with the configured message.
- **Write guard:** a shared trait on the subscriber-owned models (Location, AlertRule, NotificationChannel, AlertTemplate, TraccarServer) rejects creates, updates and deletes while maintenance is on when the request is an HTTP request from an authenticated user who is not staff and not impersonated. The subscriber sees a "we're in maintenance" message rather than an error page.
- **Exempt:** staff, impersonation sessions, queue jobs and console commands (including the Traccar poller and alert dispatch), and the token-authenticated pipeline webhooks (no signed-in user). Ingestion and alert delivery are never stopped.
- **Risk to spike first:** showing a friendly message from inside a model event may not be clean in Filament actions. If it is not, enforce at the action layer for the same models instead; the behavior above stays the same.

## Audit log

`spatie/laravel-activitylog` does not observe settings classes automatically. A listener on the package's settings events logs each change to the activity log (log name `settings`, description "Updated {group} settings") with old and new values and the acting user (the impersonator, when impersonating; the existing causer resolver handles this). Use whichever package event carries both old and new values.

## Testing

Defaults and settings-page behavior: values load, save, validation bounds reject out-of-range input, only staff can open or edit the page. Tier wiring: changing a limit changes `canCreateLocation`, the Traccar checks and the Free channel slot; Pro stays unlimited. Lightning: the form rejects a radius below the floor on create and edit, the floor moves with the setting, existing rules are unchanged. Maintenance: a non-staff web write is blocked with the friendly message; staff, impersonation, console/queue and webhook writes are not; reads still work; the banner shows only while on. Audit log: a settings change creates an activity entry with old/new values and the right causer.
