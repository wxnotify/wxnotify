---
paths:
  - app/Filament/Dashboard/Widgets/BrowserSessionsTable.php
---

# Widgets

## Where you're signed in: browser sessions list the sessions table, with plain confirmations
The SignedInDevices page (slug signed-in-devices, titled "Where you're signed in") shows BrowserSessionsTable as a header widget above the app-device table. The widget lists the subscriber's own rows of the `sessions` table through App\Models\UserSession (scoped with ownedBy(), never discovered onto the dashboard: $isDiscovered = false). The current browser (session()->getId()) is marked and cannot be signed out from the list; "Sign out" and "Sign out all other browsers" delete rows directly, log `revoked`, and are disabled while impersonating. They use a plain confirmation, not a password, because social-login accounts have a random password the subscriber never knew. App-device (Sanctum token) revocation is unchanged and the two lists never mix: phones use tokens, not sessions.
