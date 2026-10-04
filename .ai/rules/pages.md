---
paths:
  - 'app/Filament/Admin/Pages/**'
---

# Pages

## Reset to Factory Defaults is owner-only and never wipes staff or the audit log
The page's canAccess() requires users.is_owner (no environment gate; intentionally available in production). WipeSubscriberData deletes only is_staff=false users; no toggle may touch activity_log. If a table gains a foreign key to ingested_products/active_wwas/delivery_logs, add it to WipePipelineData's TRUNCATE and count. The committed database/data/shipped_product_catalog.php snapshot must be regenerated when ProductCatalogSeeder or product migrations change (ShippedProductCatalogTest fails otherwise). Re-authentication state lives in the session (factory_reset.reauthenticated_at), never in a public Livewire property.

## Owner-only pages inherit their safety from User::canBeImpersonated()
The Secrets tab on AppSettings and ResetToFactoryDefaults both gate on `auth()->user()->is_owner`. Impersonation swaps auth()->user(), so these gates are only safe because `User::canBeImpersonated()` returns `! $this->is_staff` (owners are always staff and can never be impersonated). Relaxing that to allow impersonating staff would silently hand non-owner staff both surfaces. If it ever changes, re-check every is_owner gate. Also: never pre-fill a secret into Filament form state; the value would be serialized to the browser in the Livewire snapshot.

## Pipeline Control logs and deliveries
Container logs are untrusted text: DockerEngineClient::logs strips ANSI/control characters and caps lines; the view renders escaped text, and logs are fetched only when the slide-over opens, never on the 10s poll. Fetched log lines live briefly (2 minutes) in the database cache table, keyed per component and feed. pipeline_deliveries is best-effort telemetry (RecordPipelineDelivery never throws and throttles its failure warning, PipelineActivity degrades to empty without the table) and is deliberately not in WipePipelineData: it holds no subscriber data and is pruned after 48 hours. Feed stale thresholds live in config wxnotify.pipeline.stale_minutes. Heartbeat freshness is recorded before path validation while delivery rows are only recorded after a successful ingest, so a feed posting only bad paths can show 'Healthy / Last product seconds ago' beside a flat sparkline; this is by design (the heartbeat stays the freshness signal).

## The Heartbeat "Send test ping" buttons send a real, pinned GET and never show the URL
Each heartbeat ping URL field on AppSettings has a suffix action (`testHeartbeat_<field>`) that tests the value currently typed (saved or not) through `App\Actions\Pipeline\SendTestHeartbeatPing`: the same `HeartbeatUrlGuard::requestOptions()` pin, no redirects, 5 s timeout as `SendHeartbeatPing`, after `forget()`-ing the guard's cached verdict so a retest after fixing DNS is not blocked for 5 minutes. A Healthchecks ping URL carries a secret UUID, so the notification is a fixed string plus the numeric HTTP status (or the guard's own message): never the URL, exception text or body, and nothing is logged. A successful test counts as a real check-in. The button is added only to the heartbeat fields (`endpointField(..., testPing: true)`).
