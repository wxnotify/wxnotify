# Mobile Push (Firebase) — Design

Source: owner request — Phase 6, sub-project 2 of the mobile app (foundation shipped in `2026-10-04-mobile-app-foundation-design.md`). Direct server-side Firebase delivery from the Alert Engine, to phones signed in to the app, with the delivery recorded in `delivery_logs`.

## Goal

A subscriber who signs in to the mobile app can turn on push, pick that phone in their alert rules on the web dashboard like any other channel, and receive each matching alert as a system notification. Tapping the notification opens the app on the Recent alerts feed.

## Decisions (user-approved, from brainstorming)

- **One channel per device.** Registering a phone creates a `NotificationChannel` of a new type `mobile_push` ("Mobile app"), labelled with the device name. Rejected: one channel per user fanning out to every phone (no per-device choice, a log cannot say which phone failed).
- **Sender is in-house, no new package.** Service-account JWT signed with `openssl_sign`, FCM v1 HTTP API through Laravel's `Http` client. Rejected: `kreait/firebase-php` / `google/auth` (new large dependency, harder to fake).
- **Enrollment**: after the first successful sign-in the app shows its own explainer (Enable / Not now) before the system dialog; Account has a toggle for later. The device channel exists only while push is enabled. Rejected: Account-only (almost no phone would ever enroll).
- **No automatic rule attach.** A new device channel is attached to no rule; the subscriber chooses it per rule on the dashboard. Rejected: attach to rules that already email, attach to every rule. Consequence: the Account screen must say where to choose (rules are web-only for now).
- **Tap target is the feed** (as the foundation spec already decided), via `data.url = "/"`. A delivery log row does not exist yet when the push is sent (`DeliverWeatherAlert` records it after `send()`), so there is no alert id to deep-link to.
- **Tier**: `min_tier = free`, like Web Push.

## Backend

### Channel type and transport

- New channel type `mobile_push` (`App\Support\ChannelTypes\MobilePushChannelType`), registered through a `channel_types` migration AND a `ChannelTypeSeeder::SHIPPED` entry in matching sort order (per `.ai/rules/delivery.md`). `label()` "Mobile app"; `destinationLabel()` a fixed caption ("Mobile device"); `displaySummary()` returns only the platform ("Android device" / "iPhone"), never the token.
- Mobile channels are **not creatable by hand**: hidden from the "Add channel" picker through `isAvailable()` returning false (no new contract method), editable by renaming, deletable. They are created verified, and `countsTowardFreeSlot()` is false so they do not use Free's no-cost channel slot.
- New `ChannelTransport::MobilePush`, wired in `ChannelDispatcher::transportFor()` to `MobilePushTransport`.
- `notification_channels` gains nullable `personal_access_token_id` (FK to `personal_access_tokens`, `cascadeOnDelete`). Sign-out, the "Signed-in devices" revoke action and `sanctum:prune-expired` therefore remove the channel and its rule links. `config` (already `encrypted:array`) holds `{fcm_token, platform}`; `device_token_hash` (SHA-256 of the token) is stored beside it so a token held by another channel can be found with a query. `NotificationChannel`'s activity-log options already exclude `config`.

### Registration API (`/api/v1`, ability `app`, same middleware stack as the existing app routes)

- `PUT /push-token` — body `{token: string (required, max 4096), platform: in:ios,android}`. Idempotent: creates or updates the channel tied to the calling access token (label = the token's name, i.e. the device). Any other channel (any user) that already holds the same FCM token is deleted first, so a phone handed to another account stops receiving the old account's alerts. Returns 204.
- `DELETE /push-token` — deletes the calling token's channel. Returns 204 (also when none exists).
- The FCM token never appears in any response, log, activity entry or exception message. Both endpoints get an Action class under `app/Actions/` per `.ai/rules/actions.md`, a Form Request, and follow `.ai/rules/api.md`.

### Sending

- Credentials: `config('services.firebase.service_account')` (env `FIREBASE_SERVICE_ACCOUNT`) is the path to the service-account JSON. Missing or unreadable → failure "Push is not configured"; nothing else in the pipeline is affected.
- `App\Support\Delivery\FcmClient`: builds an RS256 JWT, exchanges it at Google's token endpoint, caches the access token ~55 minutes, POSTs to `https://fcm.googleapis.com/v1/projects/{project_id}/messages:send`. Hosts are fixed (no subscriber-controlled URL, so no `WebhookUrlGuard`), redirects off, 10 s timeout, one attempt (the outcome is the delivery-log row, per the existing delivery design).
- `MobilePushTransport` sends one message per channel: `notification{title = alert subject, body = message truncated to ~200 chars}`, `data{url: "/"}`, Android `priority: high` on the plugin's own notification channel (no `channel_id` is sent), iOS default sound. Plain text only (`supportsRichContent()` false).
- Every message expires after one hour (`android.ttl` of 3600 s and an `apns-expiration` header), the owner's ruling: a delayed weather alert is worse than none. FCM's default is four weeks.
- Results use fixed strings plus the numeric HTTP status, never a response body, token or credential in a `DeliveryResult`:
  - 200 → success.
  - FCM "token no longer valid" (`UNREGISTERED`, or `INVALID_ARGUMENT` naming the token) → failure with a fixed message, plus a queued `PruneMobilePushChannel` job, delayed 60 s so the delivery-log row is written first, that deletes the channel only if it still holds the failed token (it carries a hash of that token), so a phone that re-registered in the meantime keeps its channel.
  - Any other non-2xx or timeout → plain failure.

## App (`mobile/`)

- **Permission prompt**: after the first successful sign-in, if the OS permission is `not_determined`, `granted` or `provisional` and the user has not chosen "Not now" and push is not already on, show a short explainer screen (Enable / Not now). Enable calls `PushNotifications::enroll()`. Not now is stored on the device and the app never prompts on its own again.
- **Token registration**: on device the plugin's `TokenGenerated` event reaches only the MOUNTED SCREEN's `#[On]` handler, so the trait `App\Support\Concerns\HandlesPushToken` (on the feed, Account and prompt screens) calls `PUT /api/v1/push-token` through `WxNotifyApi`. The plain Laravel listener `RegisterPushToken` is kept only as a background fallback. `register()` does not check the OS permission: Android reports `denied` until the dialog answer is recorded, which happens after the token event; it only requires that push is on. While push is on, the app also re-registers once a day on launch, which recreates a pruned channel.
- **Account screen**: a "Push notifications" row — On, Off, or "Blocked in system settings". On enrolls; Off calls `DELETE /push-token` and remembers the choice; Blocked explains where to change it. Underneath: "Choose which alerts go to this phone in your alert rules at wxnotify.com."
- **Sign-out**: already revokes the token server-side (which now removes the channel); the app also clears its local push flags.
- **Tap** opens the feed; the feed refreshes on resume. While the app is in the foreground no banner is shown (OS behaviour); the alert appears at the next refresh.
- `NativePluginsTest` lists the Firebase plugin's provider behind `PushNotifications`.

## Configuration

- Dev Firebase project `wxnotify-dev-b16a9`; `google-services.json` and `GoogleService-Info.plist` are in `mobile/` (untracked) and are registered for the app id **`com.wxnotify.dev.app`**. The current dev build uses `com.wxnotify.app` (`NATIVEPHP_APP_ID` in `mobile/.env`), which FCM would reject: the dev build must use `com.wxnotify.dev.app` (a fresh install; the old install and its sign-in are abandoned). Production will need its own Firebase project, app id and config files before the first Bifrost build.
- Server: `FIREBASE_SERVICE_ACCOUNT=/var/www/html/storage/app/private/firebase-service-account.json` (git-ignored by `storage/app/private/.gitignore`); the account belongs to the same project.
- The Firebase config files must not be committed to the public mirror; decide at plan time whether to git-ignore them in `mobile/` (recommended) so each build supplies its own.
- iOS additionally needs an APNs auth key uploaded to Firebase (Apple Developer account).

## Testing

Pest feature tests, Firebase always faked with `Http::fake()` (no real calls):

- **Registration API**: creates the device channel tied to the token; PUT twice updates one row; a token already held by another account's channel is removed; validation (missing/oversized token, bad platform); DELETE removes it and is idempotent; unauthenticated and non-`app` tokens are refused; the channel is removed when its access token is revoked or the app signs out; no response, log or activity row contains the FCM token.
- **Transport / client**: JWT shape and token caching (one token request across several sends); payload content and truncation; 200 success; invalid-token reply fails and queues the prune job, which deletes the channel after the log row exists; other errors fail with fixed strings; "not configured" path; no token or credential in any result.
- **Channel type**: caption and summary never contain the token; hidden from the Add-channel picker; rename and delete work.
- **App** (`mobile/`): the prompt appears once after first sign-in and not after Not now; Account row states and actions; a token handed to the mounted screen's handler is registered (also while the permission still reads `denied`); sign-out clears local flags; plugin guard test.
- **Device check** (controller, after execution): Android emulator with a Google Play image — enroll, see the channel on the dashboard, attach it to a rule, send an alert, receive the notification, tap it, revoke the device and confirm the channel disappears. iOS needs a physical device and is reported unverified until then.

## Rules to record

- `.ai/rules/delivery.md`: `MobilePushTransport` / `FcmClient` — fixed strings plus status only, never the FCM token, response body or credentials; fixed hosts, no redirects; the invalid-token prune runs after the log row is written.
- `.ai/rules/api.md`: `/push-token` endpoints never return or log the FCM token.

## Out of scope

Per-category or quiet-hours preferences, badge counts, notification actions, location, in-app rule management, a notification-history screen, a foreground in-app banner.
