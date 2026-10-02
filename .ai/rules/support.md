---
paths:
  - 'app/Support/**'
  - app/Support/Integrations.php
---

# Support

## Boot-time config mutators must skip config:cache and optimize
Anything that overrides config() from AppServiceProvider::boot() (see ApplyEmailSettings) is captured by `php artisan config:cache`, which boots a full app and dumps config()->all() into bootstrap/cache/config.php. A settings-derived value is then baked in and a later null/"keep .env" override can no longer reveal the .env value. Guard the top of such an action with `app()->runningConsoleCommand('config:cache', 'optimize')` and return. The read itself must also be fail-safe (catch Throwable around the settings read only) so migrate/fresh installs never break.

## Integration endpoints are staff-controlled: SSRF guard belongs in the consuming backend
IntegrationSettings endpoints (translation, short URLs, heartbeat, archival, status page) accept any http/https URL including localhost, private IPs and credentials-in-URL, because internal Docker hosts are legitimate and only staff can edit them. Heartbeat is the first consumer and is covered by HeartbeatUrlGuard (see the Heartbeat pings section). The other endpoints (translation, short URLs, archival, status page) still have no consumer: the first backend that calls Integrations::endpoint() for one of them must apply its own outbound-request safety rather than assume the value is safe. WebhookUrlGuard (app/Support/Delivery) is the pattern for subscriber-facing https targets; HeartbeatUrlGuard is the pattern for a staff-set internal target.

## Heartbeat pings
Healthchecks ping URLs (IntegrationSettings heartbeat_*_url) are staff-set and requested from SendHeartbeatPing once per accepted delivery of that feed (including duplicates). The job re-reads the URL at run time, refuses redirects, checks HeartbeatUrlGuard (http/https, no credentials, no link-local/cloud-metadata; private hosts are allowed on purpose), and must never log the URL — it contains the check's UUID. Failures log only the feed key, throttled to one per feed per five minutes. Dispatch is best-effort (DispatchHeartbeatPing never throws) so a ping can never fail an ingest request.

## Units: imperial is stored, Metric is converted at the edges through App\Support\Units
Every stored and computed measurement is imperial (alert_rules.threshold_value in degF/mph/%, radius_miles in miles; NWS metric data is converted to imperial on ingest). A subscriber's `users.unit_system` only changes how a value is displayed and how form input is converted back, so switching units never changes what an existing alert means. All factors and labels live in `App\Support\Units` (and `ThresholdVariable::{unit,displayValue,canonicalValue,format}` for threshold values) — never add a conversion factor anywhere else (NwsTextUnits' km/h and cm come from Units too), and Imperial output must stay byte-identical. NWS prose (detailedForecast, hourly wind text) goes through `NwsTextUnits::toMetric()` for Metric only, sentence by sentence, reverting a sentence it cannot fully convert. The alert form converts on the Create/Edit PAGES (`AlertRuleFormUnits::toDisplay/toStorage`), not in field hooks, because the value fields sit in a dynamically-built Wizard step; Metric wind thresholds are whole km/h (`ThresholdVariable::displayDecimals`; 0.1 mph storage is coarser than 0.1 km/h), temperatures one decimal. `toStorage` has a no-drift guard (an untouched converted value is never rewritten) — keep it when touching those fields. Stored text (delivery-log subjects, sent notifications) is not rewritten when units change. Raw NWS product text inside alerts stays as issued.
