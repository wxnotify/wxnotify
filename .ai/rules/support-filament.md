---
paths:
  - 'app/Support/Filament/**'
---

# Support Filament

## Passkeys are a second factor only, sharing one recovery-code set with app 2FA
Passkeys (happenv-com/filament-passkeys on laravel/passkeys) are registered as a second factor beside app authentication in DashboardPanelProvider; the plugin's passwordless "Sign in with a passkey" button is deliberately NOT enabled, so the password always stays. Both factors use ONE set of recovery codes (users.app_authentication_recovery_codes, hashed, Filament's own column): RecoverablePasskeyAuthentication adds a "use a recovery code" path to the passkey challenge, generates codes when the first passkey is the account's first factor, and regenerates them behind the password; RecoverableAppAuthentication refuses to delete the codes while a passkey remains, and turning off the last passkey (with no app 2FA) clears them. config/filament-passkeys.php keeps hide_challenge_confirm_button and auto_start_challenge false so the recovery-code form can be submitted. The passkey relying party id is derived from APP_URL, so it must match the URL the panel is served from; changing the domain orphans existing passkeys. The WebAuthn ceremony itself is not covered by tests (it needs a real authenticator); verify on a device after touching this.
