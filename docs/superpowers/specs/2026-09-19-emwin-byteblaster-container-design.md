# EMWIN Byteblaster Container — Design

Source: master plan (`wxnotify-nextgen-plan.html`: FEAT "Downloader control", DATA "EMWIN ingestion stays two-path", ARCH Pipeline service split). This spec adds the Byteblaster (primary EMWIN) feed client as its own Docker Compose service, alongside the existing `nwws` container. The EMWIN HTTP-backup client is a separate, later piece and stays a static "status unknown" row.

## Goal

A Docker service, `emwin`, built around the open-source **Npemwin** (Tcl) daemon, that downloads EMWIN products from community-run Byteblaster servers and delivers each text product to the Laravel pipeline by API call, where it is ingested exactly like an NWWS product — with cross-feed de-duplication so a product arriving on both feeds is ingested once.

## Decisions (user-approved)

- **Delivery:** shared volume + path, mirroring NWWS. npemwind writes to a volume also mounted in Laravel; the pan script POSTs only the relative file path.
- **Role of EMWIN:** a full second feed with cross-feed dedup (whichever feed delivers first wins), so EMWIN genuinely backs NWWS up.
- **Scope:** container + Laravel endpoint/dedup + Pipeline Control wiring + feed-health heartbeat.
- **Architecture:** the image is multi-arch and selects its `.deb` from BuildKit's `TARGETARCH` (arm64 for local Apple-silicon dev, amd64 for Intel production) — no hard-coded `platform`.
- **Version:** npemwin **3.0.1p1 on both architectures** so dev matches production. Both `.deb` files are vendored in the repo with checksums (noaaport.net currently serves an incomplete TLS chain, so build-time downloads are unreliable): `docker/emwin/packages/npemwin_3.0.1p1-1_arm64.deb` (SHA-256 `006635decab0147de8a786bedce26047da3cc9e49fba7265a89b9954f7923a76`, supplied by the user) and `npemwin_3.0.1p1-1_amd64.deb` (Debian 11 build from `https://www.noaaport.net/software/packages/npemwin-3.0.1p1/packages/debian-11/amd64/`, checksum recorded when vendored) plus a `SHA256SUMS` file the Dockerfile verifies. Bump both together when an arm64 3.1.x exists.
- **Compose service name:** `emwin` (`container_name: wxnotify-emwin`).
- **Product storage:** npemwind stores files under `/var/npemwin/files/text/[cccc]/`. That directory is the shared volume (`emwin-products`), mounted read-only in Laravel.

## Container (`docker/emwin/`)

- Base `debian:12-slim`; installs the vendored `.deb` (its dependencies come from apt: `tcl`, `tcllib`, `libdb5.3`, `libwrap0`, `gnuplot-nox`, `unzip`, `curl`) plus `rsyslog` and `ca-certificates`. The build fails if the `.deb`'s SHA-256 does not match `SHA256SUMS`.
- Runs `npemwind` as the package's unprivileged user (create it if the package doesn't). `/var/npemwin/files/text` is pre-created with that owner so a fresh named volume inherits it; products are world-readable so Laravel can read them.
- Baked-in site configuration under `/usr/local/etc/npemwin/site/`, from the user's own samples:
  - `features.conf` — the user's file (panfilter enabled; the rest at defaults).
  - `panfilter.conf` — the user's file.
  - `panfilter.rc` — calls `/pan-notify.sh $rc(fpath)` for each file received (`lappend cond {1}` + `exec`), with its output going to the console/syslog rather than a file.
  - `/usr/local/etc/npemwin/servers.conf` — a fallback list (the user's sample, one `IP<TAB>port` per line) used until/unless the refresher replaces it.
- **`pan-notify.sh`** (POSIX sh): ignores anything that is not a `.TXT` text product (images/zips/graphics are not alertable yet); strips the `/var/npemwin/files/text/` prefix; POSTs `{"path": "..."}` with the bearer token to `${EMWIN_WEBHOOK_URL}` (default `http://laravel.test/api/pipeline/emwin-products`), `curl -sS -f --max-time 10`; logs one line through `logger` (syslog → console); touches `/tmp/emwin-last-product` on a successful POST. A failed POST is logged and does not abort npemwind.
- **`refresh-servers.sh`** (POSIX sh + curl + awk): fetches `https://weathermessage.net/Support/EMWINInternetStatus.aspx`, parses the static HTML table (columns IP address, server name, port), and writes `IP<TAB>port` per row (using the hostname when a row has no IP). Safety rules: never overwrite `servers.conf` with fewer than 3 valid servers; write atomically (temp file + `mv`); validate each row (IPv4 or hostname, numeric port); on any fetch/parse failure keep the current list and exit non-zero; print a one-line summary. Exit codes distinguish "changed", "unchanged", "failed".
- **`entrypoint.sh`**: starts `rsyslogd` (config `omfile` to `/dev/stdout`, so all syslog output appears in `docker logs`), runs `refresh-servers.sh` once, starts a background loop that re-runs it every `EMWIN_SERVERS_REFRESH_HOURS` (default 6) and — only when it reports "changed" — creates a restart flag and stops `npemwind`, then runs `/usr/local/sbin/npemwind -F` in the foreground. If `npemwind` exits and the restart flag exists, the entrypoint clears the flag and starts it again; any other exit ends the container (Docker's `restart: unless-stopped` handles it).
- **Healthcheck:** `npemwind` process is running and the web interface on port 8016 answers.
- Port `8016` (npemwin's web view of received files) is exposed and mapped in Compose as `${EMWIN_WEB_PORT:-8016}:8016` for admin reference only; nothing in WxNotify uses it.

## Compose (`compose.yaml`)

New service `emwin`: build `context: .`, `dockerfile: docker/emwin/Dockerfile`, `container_name: wxnotify-emwin`; environment `PIPELINE_WEBHOOK_TOKEN`, `EMWIN_WEBHOOK_URL`, `EMWIN_SERVERS_REFRESH_HOURS`; volume `emwin-products:/var/npemwin/files/text`; network `sail`; `restart: unless-stopped`; `depends_on: laravel.test`. The `laravel.test` service also mounts `emwin-products:/emwin-products:ro`. Declare the `emwin-products` named volume.

## Laravel side

- **Route:** `POST /api/pipeline/emwin-products` behind the existing `pipeline.webhook` middleware, handled by `IngestEmwinProductController` (mirrors `IngestNwwsProductController`): validates `path`, resolves it under the new `config('services.pipeline.emwin_products_path')` (`/emwin-products`), rejects traversal/missing files with 404, reads the file, and ingests via the existing `IngestProductText` action with `file_path` = `emwin/<relative path>` (so it can never collide with an NWWS `file_path`).
- **Identity from the product text, not the filename** (EMWIN filenames don't match `IngestedProduct::parseFilename`): a new action `ExtractWmoHeaderIdentity` parses the WMO abbreviated heading (`TTAAII CCCC DDHHMM [BBB]`) and the AWIPS product-ID line from the top of the text, returning `cccc`, `ttaaii`, `awipsid`, `wmo_ddhhmm` and a normalized `wmo_header_key` (`ttaaii cccc ddhhmm [bbb] awipsid`, lowercased, BBB included so amendments/corrections stay distinct). If the header cannot be parsed the product is still ingested (identity fields null, warning logged) but is exempt from dedup.
- **New columns on `ingested_products`:** `source` (string, default `nwws`; `emwin` for this feed) and `wmo_header_key` (nullable, indexed). The NWWS controller is updated to populate `wmo_header_key` from the same action so both feeds are comparable. Existing rows keep null (only new ingests dedup).
- **Cross-feed dedup:** before ingesting, take a short cache lock keyed on the `wmo_header_key`; if a row with that key already exists (from either feed) respond 204 without ingesting (and log at debug). The lock closes the race where both feeds deliver the same product at once. The NWWS endpoint gets the same check so whichever feed is slower is the one skipped.
- **Heartbeat:** every authenticated POST to the EMWIN endpoint, including deduplicated ones, records `last_received_at` for the `emwin` feed in the cache (small `PipelineHeartbeat` helper). Pipeline Control reads it; Pipeline health can reuse it later.

## Pipeline Control

Refactor `App\Filament\Admin\Pages\PipelineControl` from its NWWS-only methods to a small list of managed feeds (NWWS → `wxnotify-nwws`, EMWIN Byteblaster → `wxnotify-emwin`), each with status badge, Start/Stop/Restart via the existing `DockerEngineClient`, and a "last product received" time from the heartbeat. EMWIN HTTP backup remains a static "status unknown" row. Existing NWWS behavior and tests must keep passing.

## Testing

- **Laravel (Pest feature tests):** endpoint requires the bearer token; happy-path ingestion with `source = emwin` and `file_path = emwin/...`; path traversal and missing-file 404; `ExtractWmoHeaderIdentity` against real-shaped fixtures (warning, AFD, plain, with/without BBB, unparseable); dedup both ways (NWWS then EMWIN skipped; EMWIN then NWWS skipped) and that a differing BBB/time is *not* deduped; the concurrent-arrival lock; heartbeat updated on accepted and deduplicated posts; Pipeline Control rows/actions for both feeds with the Docker client faked.
- **Container:** a shell test for `refresh-servers.sh` using a saved copy of the status page as a fixture (parses expected rows; refuses < 3 servers; leaves the file untouched on garbage; atomic write; "unchanged" vs "changed" exit codes), runnable with `sh` without Docker; `pan-notify.sh` tested with a stub `curl` (text file → POST with stripped path; non-TXT ignored; failure doesn't crash). The image is built for both architectures; an integration run confirms npemwind connects to a live community server, logs appear in `docker logs`, and a received product reaches Laravel.

## Verification spike (first implementation step)

Before the Laravel code depends on them, build the image and record from a real run: the exact filenames and directory layout under `/var/npemwin/files/text/[cccc]/` (extension case, name pattern), what the file contents look like (does the text begin with the WMO heading?), the `$rc(...)` variables available in `panfilter.rc`, whether npemwind re-reads `servers.conf` on a signal (else keep the stop-and-restart approach), whether the package creates its own user/dirs, and which syslog facility/ident it uses. Fix the spec/plan where reality differs.

## Out of scope

The EMWIN HTTP-backup client; EMWIN image/graphics products; the plan's "connected to feed" status layer beyond the last-product heartbeat; running npemwind's own outbound Byteblaster/relay/ldm/nntp features; automatic image/package update checks.
