---
paths:
  - 'routes/api.php'
  - 'app/Http/Controllers/Api/**'
  - 'app/Http/Resources/**'
---

# API

## The mobile API is versioned, bearer-only and scoped to the token's user
Routes live under `/api/v1` in `routes/api.php` and return Eloquent API Resources (Laravel's default `data` wrapper). They authenticate with `auth:sanctum` and `config/sanctum.php` has `guard => []`, so a dashboard session cookie never reaches them. App tokens carry only the ability `app` (`ability:app`) and must never be accepted as, or confused with, the Public API's personal keys (a later, Plus/Pro-only feature with its own abilities). Every query is scoped to `$request->user()`; another user's id is a 404. Responses never include `error_detail`, a channel destination or URL, or any token (except the beacon token returned once by `PUT /location-sharing`).

## The sign-in handoff code is single-use and consumed before it is verified
`POST /api/v1/auth/token` pulls the authorization code from the cache first (under a lock), then checks the PKCE verifier, so a wrong verifier still burns the code and a replay always fails. Every failure answers with the same generic `invalid_grant`. The token is named from the device name stored with the code, never from the request. The deep-link callback carries `code` and `state` as URI path segments, because NativePHP route params come only from the path.

## ability:app also admits a token with `*`
Sanctum treats `*` as granting every ability, and `createToken()` defaults to `['*']`, so a default token passes `ability:app`. Always call `createToken()` with explicit abilities (app tokens: `['app']`), never the default. `ability:app` runs before `ExtendAppTokenExpiry` so a refused token is never extended.

## /push-token never returns, logs or audits the FCM token
`PUT /api/v1/push-token` creates or refreshes the calling app token's `mobile_push` channel (one per `personal_access_token_id`, deleted with the token by the database cascade) and answers 204 with no body; `DELETE` removes it. The FCM token is stored only in the encrypted `config`, plus `device_token_hash` (SHA-256) so a token held by another channel (a phone handed to another account, an older sign-in) is found with a query and that channel is deleted. A registration therefore deletes any OTHER channel, of any account, holding the same token hash: this is intended (a phone that changed hands or was reinstalled) and relies on FCM tokens being high-entropy secrets that only the device itself knows. The stale channel's `deleted` activity row is attributed to its own owner, not to the registering user. Every write pins the activity causer with `withCauser`; a subscriber's rename of the channel label survives a token refresh.

## The location beacon token has one ability and one route, and cascades with its app token
`PUT /api/v1/location-sharing` (app token) mints a Sanctum token with exactly the ability `['location']`, `parent_token_id` = the app token, and is the only response that ever carries it (`GET` never does). It can call only `POST /api/v1/location` (`ability:location`, throttle `app-location`); `ability:app` routes refuse it and that route refuses an app token. It exists because the geolocation plugin keeps its bearer token in plain `SharedPreferences`/`UserDefaults`: never hand the plugin the app token. `personal_access_tokens.parent_token_id` cascades, so revoking or signing out the app token deletes the beacon token at the database level; "Signed-in devices" lists only `['app']` tokens so beacon tokens never appear. `ExtendAppTokenExpiry` slides a beacon token's expiry unchanged (it only extends the token it is given). `PUT` replaces any earlier beacon token of the phone inside one transaction, so a refused `PUT` (tier limit) revokes nothing. Position writes go through `RecordMobilePosition` (ignores a fix older than the stored one, logging off around the save, address lookup queued by `QueueAddressLookup`). `POST /location` rejects the whole batch with 422 if any point is invalid (the plugin sends exactly one point per request), answers 204 even for a stale point because the plugin ignores the body and never retries, and 404 for a phone with no linked Location.

## A query on a token's link column must never pass a possibly-null value
`where('app_token_id', $beacon->parent_token_id)` with a null `parent_token_id` becomes `app_token_id IS NULL` (Laravel turns `where(col, null)` into `IS NULL`) and would match every unlinked Location, so a beacon token with no parent app token would write to someone's unlinked phone. Guard the null first (return the same answer as an unlinked phone) and add `whereNotNull('app_token_id')` to the query, as `RecordBeaconPoints` does.
