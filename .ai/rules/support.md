---
paths:
  - 'app/Support/**'
  - app/Support/Integrations.php
---

# Support

## Boot-time config mutators must skip config:cache and optimize
Anything that overrides config() from AppServiceProvider::boot() (see ApplyEmailSettings) is captured by `php artisan config:cache`, which boots a full app and dumps config()->all() into bootstrap/cache/config.php. A settings-derived value is then baked in and a later null/"keep .env" override can no longer reveal the .env value. Guard the top of such an action with `app()->runningConsoleCommand('config:cache', 'optimize')` and return. The read itself must also be fail-safe (catch Throwable around the settings read only) so migrate/fresh installs never break.

## Integration endpoints are staff-controlled: SSRF guard belongs in the consuming backend
IntegrationSettings endpoints (translation, short URLs, heartbeat, archival, status page) accept any http/https URL including localhost, private IPs and credentials-in-URL, because internal Docker hosts are legitimate and only staff can edit them. Nothing dereferences them yet. The first backend that calls Integrations::endpoint() must apply its own outbound-request safety (see app/Support/Delivery/WebhookUrlGuard for the pattern) rather than assume the value is safe.

## Heartbeat pings
Healthchecks ping URLs (IntegrationSettings heartbeat_*_url) are staff-set and requested from SendHeartbeatPing once per accepted delivery of that feed (including duplicates). The job re-reads the URL at run time, refuses redirects, checks HeartbeatUrlGuard (http/https, no credentials, no link-local/cloud-metadata; private hosts are allowed on purpose), and must never log the URL — it contains the check's UUID. Failures log only the feed key, throttled to one per feed per five minutes. Dispatch is best-effort (DispatchHeartbeatPing never throws) so a ping can never fail an ingest request.
