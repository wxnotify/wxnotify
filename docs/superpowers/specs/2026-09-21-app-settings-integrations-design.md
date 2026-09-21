# App Settings: Integration Toggles — Design

Source: master plan (`wxnotify-nextgen-plan.html`, ARCH "App settings" → the third-party integration toggles table, and FEAT Admin tooling "System → App settings"). Builds on the first pass in `docs/superpowers/specs/2026-09-20-app-settings-design.md` (foundation, General, Tier & threshold defaults) and the feature-flags group.

## Goal

An "Integrations" tab on the App settings page giving staff runtime control over the third-party integrations the plan lists: an enabled switch per integration, plus its provider and non-secret endpoint where it has one, each with the plan's defined fallback when off. Email relaying is wired to real behavior now; the other integrations' backends do not exist yet, so their settings are stored and exposed through a helper until those backends ship.

## Scope

In: `IntegrationSettings`, its settings migration, the Integrations tab, the `Integration` enum and `Integrations` helper, boot-time application of the email settings, tests.

Out: credentials in the database (API keys, tokens, DSNs stay in `.env` until the Secrets group), the actual LibreTranslate, YOURLS, Healthchecks, Bugsink/Sentry, B2/S3 archival and Uptime Kuma backends, and a Chatwoot toggle.

## Decisions (user-approved)

- Depth: toggles + provider + non-secret endpoints for all seven integrations; credentials stay in `.env`.
- Integrations whose backend does not exist yet default to **off**, so the documented "when off" behavior is what the app does today. Email relaying defaults to **on**, using the existing `.env` mail configuration.
- One `IntegrationSettings` class (group `integrations`), one section per integration on the tab.
- Email is the only integration wired to behavior now.

## Settings

`IntegrationSettings` (`app/Settings/IntegrationSettings.php`, group `integrations`), seeded by its own settings migration:

| Integration | Properties (default) | When off |
|---|---|---|
| Product translation (LibreTranslate) | `translation_enabled` (false), `translation_endpoint` (?string) | Alerts stay English-only; the language selector hides non-English options |
| Short URLs (YOURLS) | `short_urls_enabled` (false), `short_urls_endpoint` (?string) | Alerts link directly to the full View Product URL |
| Ingest heartbeat (Healthchecks) | `heartbeat_enabled` (false), `heartbeat_endpoint` (?string) | No dead-man's-switch pings |
| Exception management (Bugsink or Sentry) | `exception_reporting_enabled` (false) | Laravel's normal log channels only; the DSN stays in `.env` |
| Product archival (B2 or S3-compatible) | `archival_enabled` (false), `archival_provider` (?string: `b2`, `s3`, `other`), `archival_endpoint` (?string), `archival_bucket` (?string) | Local-disk retention only (about two weeks) |
| Service status page (Uptime Kuma) | `status_page_enabled` (false), `status_page_url` (?string) | The public status page goes dark |
| Email relaying | `email_relay_enabled` (true), `email_mailer` (?string, null = keep `.env`), `email_from_address` (?string), `email_from_name` (?string) | Falls back to the `log` mailer |

## Helper

`App\Enums\Integration` (cases Translation, ShortUrls, Heartbeat, ExceptionReporting, Archival, StatusPage, EmailRelay) and `App\Support\Integrations` with `enabled(Integration): bool` and `endpoint(Integration): ?string` (null for integrations without an endpoint), mirroring `FeatureFlags`. Future code for each integration calls this rather than reading settings directly. A test checks every enum case maps to real properties.

## Email relaying (the wired integration)

- Applied at boot: when relaying is disabled, `mail.default` becomes `log`. Otherwise, if `email_mailer` is set and is a mailer defined in `config('mail.mailers')`, `mail.default` becomes it; if `email_from_address` / `email_from_name` are set they override `mail.from`. A null value means "keep the `.env` value", which is the default, so nothing changes until an admin sets something.
- The boot-time read is fail-safe: if the settings cannot be read (table or rows missing, e.g. a fresh install or during `php artisan migrate`), the `.env` configuration is used and nothing throws.
- The mailer choices on the page are limited to mailers defined in the app's mail config, excluding `array`, `log`, `failover` and `roundrobin`; the on/off switch covers the log fallback. Credentials for the chosen mailer stay in `.env`.
- The page warns that turning email relaying off in production stops password resets and email verification.
- Queue workers run under `queue:listen` in this stack, which re-boots per job, so a change takes effect without a restart; a long-lived `queue:work` would need one (noted on the page).

## Admin page

A fourth tab "Integrations" on `AppSettings`: a section per integration with the enabled toggle (live) and, while enabled, its fields. Endpoints and URLs must be valid `http` or `https` URLs and are required only while their integration is enabled. Values hidden by a toggle are kept, not cleared (a hidden field is absent from the saved state, so the stored value is left alone). `email_from_address` must be a valid email address. Staff only; changes are audit-logged by the existing settings listener with no new code.

## Testing

Defaults for every property. The helper follows the settings, and every enum case maps to a real property. The page loads and saves each section; endpoint and URL validation (rejects non-http schemes, required only while enabled, kept when disabled); the email address rule. Email application: no override with defaults; an override applied when a mailer/from is set; `log` when relaying is off; an unknown mailer is not applied (and rejected by the form); a failing or missing settings read leaves the `.env` configuration untouched; an audit-log entry for a change.
