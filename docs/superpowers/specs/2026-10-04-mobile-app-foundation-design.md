# Mobile App Foundation (Phase 6, sub-project 1) — Design

Source: owner request — start Phase 6 of the Next-Gen plan (native iOS/Android apps on NativePHP v4 / SuperNative). The `mobile/` skeleton (NativePHP plugins for network, Firebase push, geolocation, secure storage, background tasks and local notifications, plus splash and logo assets) is already in the repo, and `artisan install:api` has been run in the main app. After brainstorming, the owner chose to start with the **foundation** only.

## Goal

A subscriber can install the app, sign in through the real WxNotify login (Google, password, 2FA), and see their **Recent alerts** feed with a detail screen, including when offline. Along the way the backend gains the versioned, token-authenticated `/api/v1` API that every later mobile sub-project (and, later, the Public API) builds on.

## Phase 6 decomposition

Phase 6 is too large for one spec. It is split into sub-projects, each with its own spec, plan and build:

1. **Foundation** (this spec): backend API auth + the app shell, ending in one read-only screen.
2. **Push**: direct server-side Firebase delivery from the Alert Engine, deep links, delivery-log entries.
3. **Location**: foreground and background position as a second mobile source beside Traccar (`MobileSource` has only `Traccar` today).
4. **Parity management**: locations, alert rules and channels in the app, on the same API.
5. **Map**: current-location pin plus radar, warnings and outlook layers.
6. **Heads Up** travel-path (already deferred by the plan).

Not in this spec: sub-projects 2-6, biometric lock, a user-editable server URL (self-hosted instances), and universal links / associated domains.

## Decisions (user-approved, from brainstorming)

- **First slice**: foundation only. Rejected: foundation + push, foundation + location.
- **Sign-in**: a browser handoff. The app opens the real WxNotify login in a system authentication session and receives a Sanctum token through a deep link (PKCE-style). Google sign-up creates accounts with a random password the subscriber never sees, and some accounts have TOTP 2FA, so a native email/password form would lock Google-only subscribers out or duplicate the auth stack. Rejected: a native email+password form, and native password + native Google sign-in.
- **Repo home**: the app stays in `mobile/` for now and is extracted into its own private GitHub repo (with history, via `git subtree split`) before the first Bifrost build, as the plan requires. Until then `mobile/` must stay out of the public release mirror — see Follow-ups.
- **First screen**: the Recent alerts feed (backed by `delivery_logs`), which also becomes the deep-link target for push. Rejected: the alert-rules list, an account-status-only screen.
- **Sessions**: one Sanctum token per install, named for the device, with the single ability `app` and a sliding 90-day expiry, plus a "Signed-in devices" page in the dashboard to revoke. Rejected: no dashboard page (a lost phone would stay signed in), and short-lived access + refresh tokens (Sanctum has none; it would mean building rotation or adding Passport).
- **Offline**: the last-loaded feed is cached in the app's SQLite database and shown with an offline banner. Rejected: online-only with an error state.
- **Feed entries**: one entry per *alert*, not per delivery row — deliveries of the same rule and product collapse into one entry that lists its channels. Rejected: one entry per delivery row as in the dashboard's Recent Alerts widget.
- **App ↔ backend**: a thin API client with a small local cache. Rejected: a full local sync with Eloquent models in the app (overkill for a read-only feed; it can grow out of this if parity management needs it).
- **Tiers**: every tier, Free included, gets the app's login token and feed. The Public API's *personal key* (Plus/Pro only, a later feature) is a different token with different abilities and never shares these.

### Trade-off to know about: token lifetime

NativePHP's own security guidance recommends tokens that expire in under 48 hours, with a refresh token, and notes that Sanctum tokens never expire unless configured. The owner chose a sliding 90-day token without refresh. The mitigations are: the token lives only in the Keychain/Keystore, carries only the `app` ability, expires after 90 days of non-use, and can be revoked from the dashboard or by signing out. If this is later judged too long, a refresh-token exchange can be added behind `POST /api/v1/auth/token` without changing the sign-in screens.

## Backend (this repo)

### Sanctum

- Finish what `artisan install:api` started: add `HasApiTokens` to `User`; the currently uncommitted `composer.json`/`composer.lock` change (`laravel/sanctum ^4.0`), `config/sanctum.php` and the `personal_access_tokens` migration are committed with the first task.
- Set `config/sanctum.php` `expiration` to `null`: Sanctum checks that value against `created_at`, which would defeat a sliding window. Expiry is driven only by each token's own `expires_at`.
- Register the Sanctum `ability` middleware alias in `bootstrap/app.php` and apply `ability:app` to every `/api/v1` route except the code exchange.
- App tokens are created with `$user->createToken($deviceName, ['app'], now()->addDays(90))`. A middleware on the authenticated API group pushes `expires_at` back to `now()+90 days` when more than a day has passed since the last extension (at most one write per token per day).
- New settings in `config/wxnotify.php` under `app_auth`: `redirect_uri` (`wxnotify://auth/callback`), `code_ttl_seconds` (120), `token_days` (90).

### Sign-in handoff

All three steps validate their input and answer errors with an explanatory page (web) or a generic `invalid_grant` JSON error (API) that never says which check failed.

1. `GET /app-auth/authorize` (web, `auth` + verified email). Query: `challenge` (base64url SHA-256, 43 characters, `S256` only), `state` (16-128 characters of `[A-Za-z0-9_-]`), `device_name` (1-80 characters, plain text, always rendered escaped), `redirect` (must equal `config('wxnotify.app_auth.redirect_uri')` exactly). A guest is sent to the dashboard login and returned to this URL afterwards. The page asks "Sign the WxNotify app on *{device_name}* in as *{email}*?" with **Allow** and **Cancel**.
2. `POST /app-auth/authorize` (web, CSRF-protected). **Allow** stores a single-use code and redirects to `wxnotify://auth/callback?code=…&state=…`; **Cancel** redirects with `error=access_denied&state=…`. The code is 64 random characters, held in the cache under its SHA-256 hash for 120 seconds with `{user_id, challenge, device_name}`. The page is throttled (20 per minute per user).
3. `POST /api/v1/auth/token` (no auth, throttle `app-auth`: 10 per minute per IP). Body: `code`, `code_verifier` (43-128 characters). The server consumes the code first (`Cache::pull`, so any attempt, right or wrong, burns it), verifies `base64url(sha256(code_verifier)) === challenge` with `hash_equals`, re-checks that the user still exists and still has a verified email, then creates the token (named from the *stored* `device_name`, never from the request) and returns `{token, expires_at, user}` with `user` shaped like `/api/v1/me`.

Blocked cases: while a staff member is impersonating a subscriber, both authorize steps refuse ("Not available while impersonating a subscriber.") and no code is issued — the same rule as the channel URL reveal. Each successful exchange writes an activity-log entry (`event('app-signed-in')`, the device name, never the token).

### Other endpoints (all `auth:sanctum` + `ability:app`, throttle `api`: 120 per minute per token)

- `DELETE /api/v1/auth/token` — revokes the calling token (204).
- `GET /api/v1/me` — `{id, name, email, tier: {value, label}, unit_system, timezone}`.
- `GET /api/v1/alerts?limit=25&before=<cursor>` — the signed-in subscriber's own alerts, newest first, cursor-paginated (`limit` 1-50, default 25): `{data: [...], meta: {next_cursor: string|null}}`.
- `GET /api/v1/alerts/{id}` — one alert's detail; an id belonging to another subscriber, or unknown, is a 404.

### The alert feed

- An **alert** is a group of `delivery_logs` rows for one subscriber with the same `alert_rule_id` and the same non-null `ingested_product_id`. A row with a null product (daily summary, threshold, lightning) is its own alert.
- Identity: the alert's id is the **lowest** `delivery_logs.id` in the group (stable when a later row joins). Sort key: the **latest** `dispatched_at` in the group. The cursor encodes `(sort key, id)`, so pagination is stable when two alerts share a timestamp.
- **List entry**: `id`, `subject` (the stored subject, falling back to the rule's `displayName()` for older rows, as the dashboard widget does, then "Alert"), `category`, `dispatched_at` (ISO 8601 UTC), `channels` (each `{type, status}`, one per delivery row), `has_failure` (true if any row failed).
- **Detail** adds `rule_name` (nullable: the rule may be deleted), each channel's `label` (the subscriber's own channel label, nullable if deleted), and `product_url` (`IngestedProduct::viewUrl()`, null when there is none). Detail never exposes `error_detail`, a destination, or a URL secret — the same rule the My Alerts widgets follow.
- Every query is scoped to the authenticated user. Implemented with Eloquent API Resources and a Filament-free action in `app/Actions/Alerts`, like the existing alert widgets' actions.

### Signed-in devices page

A Dashboard page lists the subscriber's `app` tokens: device name, signed in, last used, expires. A **Revoke** action (with confirmation) deletes the token and writes an activity-log entry (`event('revoked')`, the device name). While staff are impersonating, Revoke is disabled. Scoped to `auth()->user()` in the query itself — the Dashboard panel's access gate allows everyone. Placed alongside the other account pages.

## The app (`mobile/`)

### Structure

Every screen is a native `NativeComponent` built from EDGE elements and registered with `Route::native()` in `routes/mobile.php` (`php artisan native:make`). Styling is Tailwind classes with a WxNotify palette in `config/native-ui.php` (light and dark, matching the splash assets); icons come from `native:icon`. No web-view, Livewire or Inertia screens (see `mobile/CLAUDE.md`). Pest is already installed in `mobile/`.

### Screens

- **Sign in**: the logo and one "Sign in to WxNotify" button.
- **Alerts** (home): the feed, with pull-to-refresh and "Load more" while a `next_cursor` exists. Each row: subject, category, relative time, the channels, and a warning mark when `has_failure`.
- **Alert detail**: subject, rule name, a per-channel status list, and a "View full product" button (the in-app browser) when `product_url` is set.
- **Account**: name, email, tier, app version, and **Sign out**.
- A bottom bar with **Alerts** and **Account**, built to take more tabs (Map, Rules) later.
- States: empty feed ("No alerts yet"), session expired (back to Sign in), server error with a retry.

### Sign-in flow

1. Generate a PKCE verifier and `state`; keep them in the app until the callback returns.
2. Call `Browser::auth()` with `{WXNOTIFY_API_URL}/app-auth/authorize?...&redirect=wxnotify://auth/callback` (`NATIVEPHP_DEEPLINK_SCHEME=wxnotify`). The device name comes from the device's own model name.
3. A native route `/auth/callback` receives the deep link. It checks `state` against the stored value (mismatch: discard and show an error), exchanges `code` + verifier at `POST /api/v1/auth/token`, then stores the token and routes to Alerts. `error=access_denied` returns quietly to Sign in.
4. The token goes **only** into Secure Storage, with the *after-first-unlock* accessibility setting. The default (when-unlocked) would break the background location uploads planned for sub-project 3. It is never written to SQLite, the session or logs. The custom URL scheme can be claimed by another app; the PKCE verifier, which never leaves the app, is what makes an intercepted code useless.

### API client

One `WxNotifyApi` service wraps `Http`. The base URL is `WXNOTIFY_API_URL` (build-time). It sends the bearer token and maps failures to typed exceptions: 401 (clear the token, route to Sign in), 429 and 5xx (retryable), no connection (offline). Connectivity is checked with `Network::status()` before a request.

### Local cache and offline

- A `cached_alerts` table (the latest 50 list entries, plus any opened detail) and a last-synced timestamp, in the app's SQLite database.
- The feed paints from cache first, then refreshes. If the refresh fails, or the network is down, a banner reads "You're offline. Showing alerts from {time}." "Load more" is disabled offline.
- Sign out revokes the server token (best effort: a failed call still clears locally), clears Secure Storage and wipes the cache.
- Times render in the timezone from `/api/v1/me`.

## Testing

Pest feature tests (container; the full suite in parallel with 6 processes).

**Backend**
- Handoff: guest redirected to login and returned; consent page renders the device name escaped; invalid `challenge`/`state`/`device_name`/`redirect` rejected; Cancel returns `access_denied`; Allow redirects with code and state; the exchange succeeds once and returns a token with ability `app` and a 90-day `expires_at`; a replayed code, expired code, wrong verifier (which also burns the code) and tampered `code` all fail with the same generic error; unverified email refused at both steps; impersonation refused and no code issued.
- Tokens: `ability:app` enforced (a token with other abilities is rejected on `/me` and `/alerts`); sliding expiry extends at most once a day; an expired token is rejected; `DELETE /auth/token` revokes only the caller's token; throttles on the exchange and the API.
- Feed: scoping to the user and 404 for another user's alert; grouping of rows for one rule and product; null-product rows stay separate; entry id, ordering and cursor stability with equal timestamps; `has_failure`; fallbacks for old rows; deleted rule or channel; detail omits `error_detail`; tier does not matter.
- Signed-in devices: lists only the user's own `app` tokens; Revoke deletes and logs; disabled while impersonating; another user's token cannot be revoked; activity rows never contain the token.

**App** (`Native::test()` plus `Http::fake`)
- PKCE verifier/challenge generation, `state` mismatch, the deep-link exchange, and token storage in Secure Storage only.
- The 401 path, cache-first rendering, the offline banner, pagination, the sign-out wipe, and the detail screen.

**Verification** (controller, after execution): a throwaway subscriber signs in through the real handoff on the iOS Simulator; the feed, detail, offline banner (network disabled) and sign-out are exercised; the device appears on the Signed-in devices page and is revoked, after which the app is returned to Sign in on its next request. Delete the throwaway data afterwards. Android is a compile check before release.

## Risks and checks for the plan

- **Intended-URL return after login**: confirm the dashboard login (Filament) returns an unauthenticated visitor to `/app-auth/authorize` with its query string intact; if not, the guest redirect needs to carry it.
- **Dev reachability**: the Simulator must reach the dev backend over HTTPS, or an App Transport Security exception is needed for the dev build. Settle in the first task before relying on a login flow.
- **Native test API and EDGE components**: confirm the exact `Native::test()` calls and which EDGE elements give the bottom bar, list and pull-to-refresh, from the NativePHP docs and the installed package.
- **Grouped query**: the alert grouping and cursor must be checked against Postgres with a realistic row count (indexes on `delivery_logs(user_id, dispatched_at)`).
- **Callback delivery**: confirm `Browser::auth()` delivers the `wxnotify://auth/callback` deep link to the native route with its query parameters on both iOS and Android.

## Follow-ups (not part of this spec)

- Exclude `mobile/` from the public release mirror, or move it to its own repo, before the first mirror push or Bifrost build.
- Associated domains / universal links for the callback (the custom scheme is enough for v1).
- A refresh-token exchange if the 90-day sliding window is judged too long.
- Self-hosted instances: a user-editable server URL (plan: Self-hosted edition unlocks).

## Rules to record

- New `.ai/rules/api.md` (globs `routes/api.php`, `app/Http/Controllers/Api/**`): the API is versioned under `/api/v1` and returns Eloquent API Resources; app tokens carry only the `app` ability and must never be accepted as, or confused with, Public API personal keys; every query is scoped to the token's user; resources never expose `error_detail`, destinations or URL secrets; the handoff code is single-use and consumed before verification.
- `.ai/rules/filament.md`: the Signed-in devices page scopes to `auth()->user()` in its query and disables Revoke while impersonating.
- `.ai/rules/index.md`: add the new rule file's row.
