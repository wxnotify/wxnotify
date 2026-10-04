---
paths:
  - 'routes/api.php'
  - 'app/Http/Controllers/Api/**'
  - 'app/Http/Resources/**'
---

# API

## The mobile API is versioned, bearer-only and scoped to the token's user
Routes live under `/api/v1` in `routes/api.php` and return Eloquent API Resources (Laravel's default `data` wrapper). They authenticate with `auth:sanctum` and `config/sanctum.php` has `guard => []`, so a dashboard session cookie never reaches them. App tokens carry only the ability `app` (`ability:app`) and must never be accepted as, or confused with, the Public API's personal keys (a later, Plus/Pro-only feature with its own abilities). Every query is scoped to `$request->user()`; another user's id is a 404. Responses never include `error_detail`, a channel destination or URL, or any token.

## The sign-in handoff code is single-use and consumed before it is verified
`POST /api/v1/auth/token` pulls the authorization code from the cache first (under a lock), then checks the PKCE verifier, so a wrong verifier still burns the code and a replay always fails. Every failure answers with the same generic `invalid_grant`. The token is named from the device name stored with the code, never from the request. The deep-link callback carries `code` and `state` as URI path segments, because NativePHP route params come only from the path.

## ability:app also admits a token with `*`
Sanctum treats `*` as granting every ability, and `createToken()` defaults to `['*']`, so a default token passes `ability:app`. Always call `createToken()` with explicit abilities (app tokens: `['app']`), never the default. `ability:app` runs before `ExtendAppTokenExpiry` so a refused token is never extended.
