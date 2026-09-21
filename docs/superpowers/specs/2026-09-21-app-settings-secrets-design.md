# App Settings: Secrets Group — Design

Source: master plan (`wxnotify-nextgen-plan.html`, SETUP "Secrets, environment variables & working with Claude Code" and ARCH "App settings"). The plan puts WxNotify's own production credentials (Stripe/Paddle API keys, Firebase credentials, Traccar polling tokens, and whatever joins them later) in a **Secrets** settings group on the same `spatie/laravel-settings` infrastructure, encrypted at rest, Owner-only, write-only in the UI, and audited. Builds on `docs/superpowers/specs/2026-09-20-app-settings-design.md` (foundation) and the integrations spec.

## Goal

A Secrets tab on the App settings page where the Owner can set, rotate and clear WxNotify's own production credentials, stored encrypted, never displayed after entry, never written to the audit log in clear, with a resolver future code uses to read a secret (stored value first, `.env` as fallback).

## Scope

In: `SecretSettings` (encrypted properties) and its migration, the `Secret` enum, the `Secrets` resolver, the `config/services.php` fallback keys, the Owner-only write-only Secrets tab, redaction of encrypted properties in `LogSettingsChange`, tests.

Out: any consumer of these secrets (Spark/Stripe/Paddle, Firebase, a global Traccar poller do not exist yet); mail-provider credentials; `APP_KEY` rotation; per-subscriber secrets (BYO channel credentials already live encrypted in their own columns). `APP_KEY`, database and Redis connections stay in `.env` (bootstrap credentials that cannot live in a settings table).

## Decisions (user-approved)

- First pass = the plan's four credential families as slots, plus the machinery; no boot-time config override.
- Owner-only (`is_owner`); staff who are not owners cannot see or write the tab.
- Write-only: the current value is never sent to the browser.
- The stored value wins over `.env`; `.env` (via `config('services.*')`) is the fallback.

## Storage

`SecretSettings` (`app/Settings/SecretSettings.php`, group `secrets`); every property is `?string`, default null, and flagged encrypted through the package (`public static function encrypted(): array` listing all six, and `addEncrypted(...)` in the settings migration). The package encrypts encrypted properties both in the `settings` table and when the settings object is serialized into the settings cache, so neither holds plaintext. Slots:

| Property | Holds | Fallback config key |
|---|---|---|
| `stripe_secret_key` | Stripe secret API key | `services.stripe.secret` |
| `stripe_webhook_secret` | Stripe webhook signing secret | `services.stripe.webhook_secret` |
| `paddle_api_key` | Paddle API key | `services.paddle.api_key` |
| `paddle_webhook_secret` | Paddle webhook signing secret | `services.paddle.webhook_secret` |
| `firebase_service_account` | Firebase service-account JSON | `services.firebase.service_account` |
| `traccar_polling_token` | WxNotify-wide Traccar polling token | `services.traccar.polling_token` |

Add the matching keys to `config/services.php`, each reading an env var (`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PADDLE_API_KEY`, `PADDLE_WEBHOOK_SECRET`, `FIREBASE_SERVICE_ACCOUNT`, `TRACCAR_POLLING_TOKEN`), defaulting to null, and to `.env.example`.

## Resolver

`App\Enums\Secret` (one case per slot with `label()`, `settingsProperty()`, `configKey()`) and `App\Support\Secrets`:
- `get(Secret): ?string` — the stored value if filled, else `config(configKey)` if filled, else null. If the settings cannot be read (`MissingSettings`, or any read failure) it falls back to config; it never throws.
- `isStored(Secret): bool` — whether a value is stored in the settings (used only for the "Set / Not set" status; never returns the value).

## Admin page — Secrets tab

- A fifth tab "Secrets", `visible` only when the signed-in user `is_owner`. Its fields are part of the form only for owners, so a non-owner's saved state can never contain a secret (Filament excludes hidden fields from `getState()`); `save()` additionally refuses to touch `SecretSettings` for a non-owner.
- Per slot: a blank password input (Firebase: a textarea), never pre-filled, with a "Set" / "Not set" status in the helper text and a "Remove stored value" checkbox. Blank = keep the current value; a typed value replaces it; the checkbox clears it (null). The Firebase slot must be valid JSON containing `client_email` and `private_key`.
- The page never fills `SecretSettings` into the form on mount, and after a successful save it clears any typed secret and the remove-checkboxes from the component state, so nothing typed is retained or echoed back.
- Saving fills only the secret properties that were typed or marked for removal; untouched properties stay as stored.

## Audit log

`LogSettingsChange` must redact encrypted properties: for any property the settings class flags as encrypted, log `[set]` / `[not set]` markers instead of values (a rotation logs `[set]` → `[set]`), with the acting user as causer as today. No secret value, old or new, may appear in any activity row.

## Testing

Encrypted at rest (the raw `settings` row is not plaintext and decrypts to the value); the settings cache entry is not plaintext; the resolver returns the stored value over `.env`, falls back to `.env`, and falls back when the settings rows are missing; blank keeps, typed replaces, the checkbox clears; Firebase JSON validation; non-owner staff cannot see the tab and a tampered request cannot write a secret; owner can; the page state and rendered HTML never contain a stored secret and the typed value is cleared after save; the audit row for a secret change contains only markers, and a scan of the activity table finds no plaintext; the existing audit tests still pass.
