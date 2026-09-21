# Pipeline Control Redesign — Design

## Goal

Turn the Pipeline Control admin page from three plain rows into a live operations view: one health verdict per feed that combines Docker state with data freshness, a summary banner, a 24-hour throughput sparkline, recent products (including a shared EMWIN table that verifies Byteblaster and HTTP deliver the same products), and on-demand container logs.

## Scope

In: the Pipeline Control page and view, `DockerEngineClient` (health + logs), a `pipeline_deliveries` table with recording and pruning, a `FeedHealth` class, config thresholds, tests.

Out: any change to the feeds themselves, the Docker proxy allowlist (`CONTAINERS: 1` already permits reads), alerting/notifications on unhealthy feeds, and fixing the dedup-window bug (separate known item).

## Decisions (user-approved)

- All seven ideas: health cards, summary banner, throughput sparkline, auto-refresh, primary/backup grouping, recent products and logs, Docker health.
- Stale thresholds: NWWS 15 min, EMWIN Byteblaster 15 min, EMWIN HTTP 30 min.
- One shared EMWIN recent-products table with a column per feed (changed from per-feed lists at the user's request), to verify the same products arrive on both feeds.

## Data: `pipeline_deliveries`

`ingested_products` cannot back per-feed views: both EMWIN feeds are `source = emwin`, and dedup drops whichever feed's copy arrives second, so it never records that the second feed delivered it. A small table records every delivery attempt that reached ingest.

| Column | Notes |
|---|---|
| `id` | |
| `feed` | `nwws`, `emwin` (Byteblaster), `emwin_http` — same keys as `PipelineHeartbeat` |
| `wmo_header_key` | nullable, indexed; null when the header is unparseable or the product is CAP |
| `file_name` | basename only, never a path |
| `outcome` | `ingested` or `duplicate` (the boolean `IngestOncePerWmoHeader` already returns) |
| `received_at` | indexed with `feed` |

Recorded by `IngestNwwsProductController` and `IngestEmwinProductController` right after the ingest attempt (so only paths that passed validation are recorded). The heartbeat stays exactly where it is today and remains the freshness signal. A scheduled command `pipeline:prune-deliveries` deletes rows older than 48 hours (config, default 48). A failure to record a delivery must never fail the ingest request.

This replaces the "hourly cache counters" idea floated in brainstorming: the same table gives the sparkline, recent lists and cross-feed check, and survives a cache flush.

## Health model (`App\Support\Pipeline\FeedHealth`)

Inputs: Docker `State.Status`, `State.Health.Status` (may be absent), the feed's last heartbeat, the feed's stale threshold. Output: a state, a color, a label. First match wins:

| State | Condition | Color |
|---|---|---|
| Unknown | Docker unreachable | warning |
| Not found | container does not exist | gray |
| Stopped | `exited` | gray |
| Down | `dead` | danger |
| Restarting | `restarting`, `paused`, `created` | warning |
| Unhealthy | running, Docker health `unhealthy` | danger |
| Starting | running, Docker health `starting` | warning |
| Stale | running, no heartbeat ever or older than the threshold | warning |
| Healthy | running, fresh | success |

Thresholds live in `config/wxnotify.php` (`pipeline.stale_minutes.{nwws,emwin,emwin_http}`, env-overridable).

## Page

- **Summary banner.** "All feeds healthy", or a count and names of feeds needing attention, plus products ingested today (`outcome = ingested`, since midnight). Red if any feed is Unhealthy/Down or a primary feed is Stopped; amber if any feed is Unknown/Restarting/Starting/Stale; green otherwise. A stopped backup feed is shown as an informational note and does not change the color.
- **Groups.** Primary (NWWS, EMWIN Byteblaster), Backup (EMWIN HTTP). Each feed is a card.
- **Card.** Icon, one-line role, health pill, large "Last product N ago", 24-hour sparkline (deliveries per hour from `pipeline_deliveries`, labelled "received"), start/stop/restart actions (unchanged behaviour and confirmations), "Recent products", "Logs".
- **Recent products.** NWWS card: its last 8 deliveries (header key, else file name; time; outcome badge). EMWIN uses one shared "Recent EMWIN products" panel below the Backup group, not a per-card list: one row per product (grouped by `wmo_header_key` across the `emwin` and `emwin_http` feeds), showing the last 10 products by earliest delivery, with a Byteblaster column and an HTTP column each showing the delivery time or "—". A product missing from a feed shows "Waiting" while it is younger than 10 minutes and "Not received" after that; this is the verification that both feeds deliver the same products. Rows with no `wmo_header_key` cannot be matched and are listed individually, marked "No header to compare", under the feed that delivered them.
- **Auto-refresh.** `wire:poll.10s` on the cards and banner. Docker status is read once per container per render (health comes from the same `/containers/{name}/json` call).
- **Logs.** A slide-over action per card showing the last 200 lines from `GET /containers/{name}/logs?stdout=1&stderr=1&tail=200`. The response is a multiplexed stream (8-byte frame headers) and is demultiplexed; each line has control characters/ANSI stripped and is truncated to 500 characters; the view renders it as escaped text. Fetched only when the slide-over opens, never on poll.

## Security and safety

- The page stays staff-only (existing `canAccessPanel`/page rules); no new routes.
- Logs are untrusted text: escaped on output, sanitized and size-capped. Implementation must check what each service actually prints and confirm none of them logs `PIPELINE_WEBHOOK_TOKEN` or other secrets; a test asserts the sanitizer strips control characters and caps size.
- Delivery rows store basenames and header keys only.
- No changes to Docker proxy permissions; only GETs on containers are added.

## Migration note

Adds one migration (`pipeline_deliveries`). Per project constraints it is not run on the dev DB by us; tests use `RefreshDatabase`. Until the user migrates, the page must still render (recording is best-effort and the recent/sparkline queries fail soft to empty).

## Testing

- `FeedHealth`: every row of the table, precedence (e.g. unhealthy beats stale), threshold boundaries.
- `DockerEngineClient`: health extraction with and without a `Health` block; log demultiplexing, sanitizing, truncation and error handling (`Http::fake`).
- Ingest controllers: a delivery row is recorded with the right feed/outcome for ingested and duplicate cases; ingest still succeeds if recording throws; the heartbeat behaviour is unchanged.
- Prune command: removes rows past retention, keeps newer ones.
- Page (Livewire): banner colors/text for representative combinations; sparkline data; NWWS recent list; the shared EMWIN table (product on both feeds, on one feed only while waiting, on one feed only past 10 minutes, unmatched no-header rows, ordering and the 10-row cap); existing start/stop/restart action tests keep passing; logs slide-over shows sanitized lines and escapes HTML; page renders when the table is empty.
