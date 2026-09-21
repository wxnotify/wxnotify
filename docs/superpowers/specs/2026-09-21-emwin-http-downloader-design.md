# EMWIN HTTP Product Downloader — Design

Source: the current WxNotify system's EMWIN HTTP backup (public NOAA server, no credentials) and the master plan's EMWIN feed section. The EMWIN Byteblaster container (`docker/emwin/`) is the primary EMWIN feed; this downloader is the backup, fed through the same alert pipeline with the existing cross-feed dedup.

## Goal

A Docker container, started and stopped from the admin Pipeline Control like the NWWS and EMWIN Byteblaster containers, that polls the most frequently updated EMWIN zip batch on NOAA's public HTTP server at that file's own cadence, unzips new products, and sends them through the alert pipeline's EMWIN endpoint where dedup prevents duplicate alerts.

## Scope

In: the Go client and its container/compose entry, its JSON config, the ingest endpoint's `feed` parameter and heartbeat, the Pipeline Control row, tests.

Out: image product ingest (image products are downloaded and unzipped only, nothing is posted), any change to the Byteblaster container, other zip cadences beyond what the file-name rule supports, and the cross-feed dedup time-window fix (a separate known bug; recommended before go-live, first exposure about 2026-10-19).

## Decisions (user-approved)

- Client language: **Go**, a small static binary (matches the NWWS client's language; native JSON, HTTP conditional requests, zip handling, testable core).
- Default text file: **`txtmin02.zip`** (the fastest text batch that exists on the server; the user's `txtmin01.zip` is not in the listing).
- Ingest path: **reuse the existing EMWIN endpoint** (`POST /api/pipeline/emwin-products`); products stay `source = emwin`.
- Pacing: derived from the zip file name, with conditional requests.
- Only files not seen before are sent each cycle.
- With the image flag on, images are downloaded and unzipped only, never posted.
- The config gains a `server_path` field beside the six the user listed.

## The client (`docker/emwin-http/`)

A Go module (source, unit tests, multi-stage Dockerfile producing a static binary, `go test` run in the build stage). Container name `wxnotify-emwin-http`, compose service `emwin-http` mirroring the other pipeline services (network, restart policy, `depends_on: laravel.test`, the shared `emwin-products` volume). Logs to stdout; `PIPELINE_WEBHOOK_TOKEN` and `EMWIN_WEBHOOK_URL` come from the environment, not the config file.

### Config file (JSON)

| Key | Meaning | Default |
|---|---|---|
| `server_host` | NOAA server hostname | `tgftp.nws.noaa.gov` |
| `server_path` | Directory on the server | `/SL.us008001/CU.EMWIN/DF.xt/DC.gsatR/OPS/` |
| `storage_dir` | Directory downloaded files are stored in | `/emwin-products/http` |
| `download_text` | Download text products | `true` |
| `download_images` | Download image products | `false` |
| `text_zip` | Text zip file to poll | `txtmin02.zip` |
| `image_zip` | Image zip file to poll | `imgmin15.zip` |

The file is validated at startup (host and path well-formed, zip names match `^[a-z0-9]+\.zip$`, cadence resolvable, storage dir writable); the client refuses to start on an invalid config and logs why. `download_text` and `download_images` gate whether each kind is downloaded at all.

### Pacing

Cadence comes from the file name: `txtmin02` → 2 minutes, `txtmin06` → 6, `txtmin20` → 20, `imgmin15` → 15, `*hrs01` → 60, `*hrs03` → 180. An unrecognized name is a config error. Each file has its own loop. Requests send `If-Modified-Since` / `If-None-Match` from the last stored `Last-Modified` / `ETag`; a `304` costs no download. After a failure the client backs off (capped at the file's cadence) rather than hammering the server.

### Each cycle (text)

1. Conditional GET to a temp file with a size cap (default 20 MB; text zips are ~0.3–4 MB).
2. Safe unzip: reject entries with path separators or `..`, sanitize names to `[A-Za-z0-9._-]`, cap member count and total uncompressed bytes (defaults mirroring the Byteblaster hook: 5 MiB per member, 50 members per *new* file batch is not required here — HTTP batches are larger, so use a per-member cap and a generous total cap, configurable by env).
3. For each member not in the seen-set: write it into `storage_dir` and POST `{"path": "http/<name>", "feed": "http"}` with the bearer token. A file is added to the seen-set only after a 2xx.
4. If every POST in the cycle succeeded, persist the zip's `Last-Modified` / `ETag`; if any failed, do not, so the next cycle re-fetches and posts only the unseen files.
5. Delete the temp zip. Files in `storage_dir` older than the retention period (env, default 7 days) are removed.

### State

A small JSON state file in `storage_dir`'s parent (per zip file): last `Last-Modified` / `ETag` and the bounded seen-set of processed names (keeps the most recent ~50,000 names). Written atomically. A restart neither re-downloads an unchanged zip nor re-posts seen files.

### Images

When `download_images` is on, the image zip is fetched on its cadence (conditional GET), unzipped into an images directory beside `storage_dir`, with the same safety limits and retention, and nothing is POSTed. When the flag is off the image loop does not run at all.

### Health

`--healthcheck` exits 0 only if a poll of every enabled file succeeded within three cadences (a `304` counts as success). Docker `HEALTHCHECK` calls it.

## Laravel side

- `IngestEmwinProductController` accepts an optional `feed` value (`byteblaster` default, or `http`), validated. Products still ingest as `source = emwin` through the same `IngestOncePerWmoHeader` dedup, and the file path is still validated against the shared root exactly as today. The `feed` value only selects which heartbeat is recorded (`PipelineHeartbeat::record('emwin')` for `byteblaster`, `'emwin_http'` for `http`), so the backup can never mask a dead Byteblaster feed.
- Pipeline Control gets a third row for EMWIN HTTP: status badge, start / stop / restart (container `wxnotify-emwin-http`, through the existing Docker proxy client), and the last-product time from the `emwin_http` heartbeat.
- The factory-reset page's list of pipeline services to stop mentions EMWIN HTTP.

## Testing

Go unit tests: cadence parsing (every supported name, an unknown name); config validation (defaults, each invalid field); conditional GET against an `httptest` server (200 stores validators, 304 skips, 5xx backs off); safe unzip (path traversal, absolute paths, name sanitizing, member/size limits); the seen-set and state persistence across a restart; POST failure semantics (a failed POST leaves the file unseen and the validators unrecorded, a later cycle posts only the unseen files); the image loop writes but never POSTs; the healthcheck.
Pest: the `feed` parameter is accepted/rejected and selects the heartbeat, Byteblaster behavior is unchanged, dedup still skips a product both feeds deliver, the Pipeline Control EMWIN HTTP row and actions, the factory-reset service list.
