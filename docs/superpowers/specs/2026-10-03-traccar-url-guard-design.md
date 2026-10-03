# Traccar URL Guard (SSRF protection) — Design

Source: the owner asked what `TraccarUrlGuard` was for, then (a) pointed out that self-hosters commonly run WxNotify and Traccar on the same internal network, so the guard must not break that, and (b) chose "allowed by default, like the heartbeat guard" for private addresses in self-host mode.

## Goal

A subscriber enters a Traccar server URL and an API token, and WxNotify's server then calls that URL (`/api/devices`, `/api/positions`) with the token as a Bearer credential. Nothing currently restricts the URL, so in the hosted product a subscriber could aim it at `localhost`, a private/Docker address, or the cloud metadata service, and the app would request it from inside the network (SSRF) and send the token with it. Add `App\Support\TraccarUrlGuard`, checked **when a server is saved** and **again immediately before every Traccar request**, with behavior that depends on `config('wxnotify.mode')` (`cloud` | `selfhost`; default `cloud`):

- **cloud**: the strict guard — no private, loopback, reserved or metadata targets.
- **selfhost**: private/LAN targets are **allowed by default** (the expected setup is a Traccar on the same network); only what could reach a cloud-metadata service or a link-local address is refused, exactly as `HeartbeatUrlGuard` does.

## Decisions (user-approved, from brainstorming)

- **Mode-dependent, private allowed by default in selfhost** (no opt-in setting, unlike `WebhookUrlGuard`'s `allow_private_networks`). Rationale: Traccar is naturally an internal service; an opt-in would make the common self-hosted case fail until configured.
- **http and https are both allowed** (many self-hosted Traccar servers are plain http, default port 8082). **No credentials in the URL** in either mode.
- **No redirects** on any Traccar request (a public URL must not be able to 302 the app to an internal address, and the Bearer token must not follow a redirect).
- **Compose the existing guards, do not copy their IP logic**:
  - selfhost delegates to `HeartbeatUrlGuard::violation()` unchanged.
  - cloud reuses `WebhookUrlGuard`'s address classification. That requires making `WebhookUrlGuard::hostRisk()` (currently `private`) `public`; its behavior and existing tests are unchanged. `TraccarUrlGuard` supplies its own user-facing wording (the guard's `proxyViolation()` messages say "proxy", which would be wrong here).
- **Accepted gap**: the guard's DNS lookup and the HTTP client's own lookup are separate (DNS-rebinding/TOCTOU), the same known open item recorded for webhooks in `.ai/rules/delivery.md`; pinning via `CURLOPT_RESOLVE` stays a follow-up. Not introduced by this work.
- **No port restriction** (Traccar defaults to 8082 and operators choose ports freely; private targets are already blocked in cloud, so a port rule adds little).
- **Existing servers that now violate the guard** are simply refused at request time (they behave like an unreachable server) — no migration, no notification.

## The guard

`App\Support\TraccarUrlGuard` (final class; constructor accepts optional `HeartbeatUrlGuard` and `WebhookUrlGuard` instances for tests, defaulting to new ones; the resolver closure those classes already accept is how tests avoid real DNS):

`violation(string $url): ?string` — a human-readable, **fixed-string** reason the URL must not be called, or null when it is safe. It never repeats the URL, host or any part of it back.

Order of checks, both modes: `parse_url` must succeed with a scheme and host (else "Enter a full http:// or https:// URL."); scheme must be http or https; no username/password in the URL.

- **selfhost** (`config('wxnotify.mode') === 'selfhost'`): after the shared checks, return `HeartbeatUrlGuard::violation($url)` (which also rejects numeric-shorthand hosts, zone identifiers, metadata hosts, and link-local/metadata addresses).
- **anything else (cloud, the default)**: after the shared checks, `WebhookUrlGuard::hostRisk($host)` maps to: `'name'` → "That host name points at a private network address."; `'unresolved'` → "That host name could not be resolved."; `'private'` → "That address resolves to a private or reserved network, which is not allowed."; null → safe.

Only the mode string `selfhost` relaxes anything; an unknown or missing mode is treated as cloud (strict).

## Where it is applied

- **Save time**: the `base_url` field in `TraccarServerForm::fields()` gains a validation rule that calls the guard and fails with its message; `fields()` is the one definition used by both Create and Edit, so both are covered. The existing `->url()` rule stays.
- **Request time**, in all three Traccar paths, before any HTTP call:
  - `ListTraccarDevices::__invoke`: on a violation log `Log::warning('Traccar server URL refused', ['traccar_server_id' => $server->id])` (fixed message, no URL) and return null.
  - `PollTraccarServer::__invoke`: same, return null (the poll command already records null as a server failure).
  - `GetTraccarDeviceMap`: in `fetch()`'s per-server pre-flight (next to `isConfigured()`), a violating server gets the same warning and `$results[$server->id] = null`, so it is excluded from the pool and cannot affect other servers.
- **Redirects**: `withoutRedirecting()` (or the equivalent `allow_redirects => false` option the map pool already uses) on the `ListTraccarDevices` and `PollTraccarServer` requests, so all Traccar requests refuse redirects; a 3xx response is treated as a failed (non-successful) response, as the actions already do for any non-2xx.

## Non-goals

- DNS pinning (`CURLOPT_RESOLVE`) or any other TOCTOU mitigation.
- Port restrictions, allow-lists, or an admin override/opt-in setting.
- Notifying subscribers whose existing server URL now violates the guard; migrating or deleting such servers.
- Applying the guard to other integrations (staff endpoints are covered by their own guards).
- Changing `WebhookUrlGuard` / `HeartbeatUrlGuard` behavior (the only change is `hostRisk()` visibility).

## Testing

Pest feature tests, container run as for other features. Never perform real DNS or network I/O: pass a resolver closure to the guard and use `Http::fake()`.

- `TraccarUrlGuard` in **cloud** mode (`config(['wxnotify.mode' => 'cloud'])`): allows a public host over http and https, including `:8082`; blocks `localhost`, `10.x`, `172.16.x`, `192.168.x`, `127.0.0.1`, `169.254.169.254`, a hostname resolving to a private address, `.internal`/`.lan`/`.local` names, an unresolvable host, credentials in the URL, a non-http scheme (`ftp://`, `file://`), a malformed URL; and a mapped/embedded-IPv4 IPv6 address (`::ffff:10.0.0.1`).
- `TraccarUrlGuard` in **selfhost** mode: allows `10.x`, `192.168.x`, `localhost`, and a private-resolving hostname; still blocks `169.254.169.254`, `metadata.google.internal`, a link-local address, credentials, and non-http schemes.
- An unknown/missing mode behaves as cloud.
- No violation message contains the host or URL (assert against a distinctive host string).
- `TraccarServerForm`/resource: creating and editing a server with a violating URL fails validation on `base_url` in cloud mode and succeeds for a public URL; in selfhost mode a private URL is accepted (extend `TraccarServerResourceTest`).
- `ListTraccarDevices`, `PollTraccarServer`, `GetTraccarDeviceMap`: a violating `base_url` makes **no** HTTP request (`Http::assertNothingSent()`) and returns null / excludes the server; an allowed URL still works as before (existing tests keep passing). In the map test, a violating server alongside a good one leaves the good server's data intact.
- Redirects: with `Http::fake` returning a 302 for `/api/devices` and `/api/positions`, the corresponding action returns null (does not follow the `Location`) — for `ListTraccarDevices` and `PollTraccarServer`.
- `WebhookUrlGuard`'s existing tests continue to pass unchanged (the visibility change is behavior-neutral).
- Browser check (controller, after execution): in cloud mode saving a Traccar server with `http://localhost:8082` shows the guard's message; a public-looking URL saves; with `WXNOTIFY_MODE=selfhost` a private URL saves.

## Rules to record

Add a "Traccar URLs" section to `.ai/rules/support.md`: `TraccarUrlGuard` must pass at save and before every Traccar request; Traccar requests never follow redirects and carry the Bearer token; messages are fixed strings that never echo the URL; cloud is strict and only `selfhost` relaxes private ranges (metadata/link-local stay blocked in both); the DNS-vs-client TOCTOU is the same accepted gap as webhooks. Any new Traccar call must go through the guard.
