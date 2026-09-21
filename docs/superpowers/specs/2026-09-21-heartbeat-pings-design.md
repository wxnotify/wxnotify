# Heartbeat Pings (Healthchecks) — Design

Source: the App Settings Integrations tab (`docs/superpowers/specs/2026-09-21-app-settings-integrations-design.md`), where Heartbeat is a single toggle plus one endpoint and has no backend. The operator will self-host Healthchecks, which offers ping URLs that keep a check alive when they receive a plain HTTP GET, and will create one check per ingester.

## Goal

Replace the single Heartbeat endpoint with three ping URLs (NWWS, EMWIN Byteblaster, EMWIN HTTP), and send a GET to a feed's URL each time that feed delivers a product to Laravel, so Healthchecks alerts when a feed goes quiet.

## Scope

In: the three settings properties and their settings migration, the Integrations tab form, `Integrations::heartbeatUrl()`, a queued ping job, an outbound-safety guard for the ping URL, ping dispatch from the three ingest endpoints, tests, a rule note.

Out: any change to the ingester containers, `/fail` or `/start` signals, Healthchecks status display inside WxNotify, retries, throttling, and moving the URLs into the encrypted Secrets group.

## Decisions (user-approved)

- Pings are sent from Laravel's ingest endpoints, not from the containers (the URLs live in App Settings, which the containers cannot read).
- Every accepted delivery pings, including deduplicated ones, with no throttle (so the EMWIN HTTP backup shows alive while Byteblaster wins each race).
- The three URLs are plain settings like the other integration endpoints; they are visible to staff and appear in the settings audit log.

## Settings

`IntegrationSettings` drops `heartbeat_endpoint` and gains `?string` properties `heartbeat_nwws_url`, `heartbeat_emwin_url`, `heartbeat_emwin_http_url` (keys follow the feed keys `nwws`, `emwin`, `emwin_http` that `PipelineHeartbeat` and `App\Enums\PipelineFeed` already use). A new settings migration in `database/settings` adds the three properties (default null) and deletes `heartbeat_endpoint` (it had no consumer). `heartbeat_enabled` stays.

`Integration::Heartbeat->endpointProperty()` returns null (the integration no longer has a single endpoint); every enum case must still map to real properties (existing test).

`Integrations::heartbeatUrl(PipelineFeed $feed): ?string` returns the feed's URL only when Heartbeat is enabled and that URL is filled in; otherwise null. It fails open (null) when the settings cannot be read (`MissingSettings` or any read failure), so ingest and tests without settings rows keep working.

## Form (App settings > Integrations > Heartbeat)

The Heartbeat section keeps its live toggle. When on, three URL fields show: "NWWS ping URL", "EMWIN Byteblaster ping URL", "EMWIN HTTP ping URL". Each is optional, max 255 characters, validated `url:http,https`, and saved as null when blank (add the three properties to `AppSettings::NULLABLE_STRINGS`, remove `heartbeat_endpoint`). The existing `endpointField()` helper marks a field required when its toggle is on; the heartbeat fields must not be required, so add an optional variant (or a parameter) rather than changing the behaviour for the other integrations. Turning Heartbeat off hides the fields but keeps the stored URLs.

## Pinging

- `App\Jobs\SendHeartbeatPing` (queued, `ShouldQueue`, one try, short timeout): constructed with a feed key; when handled it resolves `Integrations::heartbeatUrl()` at run time (so a URL removed after queuing is not called), runs the guard, then performs `GET` with a 5-second timeout and no redirect following (follow the approach of `App\Support\Delivery\NoRedirectCallWebhookJob`). A non-2xx or a transport error is a failure that never throws out of the job.
- A small action `App\Actions\Pipeline\DispatchHeartbeatPing` (invoked as `($action)(string $feed)`) checks `Integrations::heartbeatUrl()` and only then dispatches the job, so nothing is queued when Heartbeat is off or that feed's URL is blank. It never throws (dispatch failures are swallowed and reported without the URL).
- The three ingest controllers call it right after they record the delivery: `IngestNwwsProductController` with `nwws`, `IngestEmwinProductController` with `emwin` (byteblaster) or `emwin_http` (feed `http`). Same key as the heartbeat and delivery recording. A rejected path (404) sends no ping. Nothing about the heartbeat cache, delivery recording or dedup changes.
- Failure logging: a failed ping logs a warning naming only the feed key, throttled to one per feed per five minutes with a cache gate that is itself wrapped so it cannot throw. The URL is never logged (it contains the check's UUID).

## Outbound safety

The consumer of a staff-controlled URL applies its own guard (`.ai/rules/support.md`). `WebhookUrlGuard` is https-only and blocks private networks, which does not fit a self-hosted Healthchecks reached over http on a private network, so add a dedicated `App\Support\Delivery\HeartbeatUrlGuard`: allow only http and https, refuse embedded credentials, refuse link-local and cloud-metadata targets (169.254.0.0/16, `metadata.google.internal`, and the IPv6 equivalents), and allow other private hosts (they are the point). Redirects are never followed. A violation makes the job skip the ping and log the throttled feed-only warning.

## Security and safety

- The page remains staff-only through the existing App settings access rule; no new routes.
- Ping URLs are not secrets by the existing integration-endpoint decision, but never appear in logs, exception messages or job failure output.
- Ingest must never fail or slow down because of a ping: dispatch is queued and wrapped, and the job never rethrows.

## Migration note

Adds one settings migration (not run on the dev DB by us; the user runs `php artisan migrate`, then `php artisan optimize:clear` for the settings cache). The pinger fails open until the new properties exist.

## Testing

- Settings and enum: every `Integration` case maps to real properties; `heartbeat_endpoint` is gone and the three new properties exist with null defaults.
- `Integrations::heartbeatUrl()`: returns the URL per feed when enabled; null when disabled, blank, or when settings are missing.
- Form (Livewire): the three fields appear only while the toggle is on, accept blank, reject a non-http(s) URL, persist per feed, and turning the toggle off keeps the stored values; the other integrations' required behaviour is unchanged.
- Dispatch: each controller queues the matching feed's ping for an ingested delivery and for a deduplicated one; none when Heartbeat is off, the URL is blank, or the path is rejected; an exception from dispatch does not fail the ingest request.
- Job: sends one GET to the right URL (`Http::fake`), refuses redirects, reads the URL at run time, skips a guard violation, swallows a transport error or non-2xx, and logs only the feed key, never the URL.
- Guard: allows a private http host and a public https host; refuses ftp/other schemes, credentials, link-local and metadata addresses.
