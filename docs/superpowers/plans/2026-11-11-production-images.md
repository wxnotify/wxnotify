# Production Images and Self-Hosting Quick Start Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish ready-made multi-architecture WxNotify images to Docker Hub, with single-server and multi-server compose files and instructions, so a self-hoster runs WxNotify without cloning the repository or building anything.

**Architecture:** One ServerSideUp-based PHP image (application baked in) serves every PHP role by `command:`; four feed-client images are built from the existing Dockerfiles, with `npemwin` built from source. Compose files under `deploy/` pin every image to one `WXNOTIFY_VERSION`. A GitHub Actions workflow builds amd64 and arm64 natively and publishes on a version tag. Two small application changes make a prebuilt image portable: Reverb settings are rendered at request time, and the Outlook map disk is configurable.

**Tech Stack:** Laravel 13 / PHP 8.4, Pest, ServerSideUp `fpm-nginx` v4.5.1, Docker Compose 2.24+, Docker Buildx, GitHub Actions, PostgreSQL (PostGIS), Vite.

**Spec:** `docs/superpowers/specs/2026-11-11-production-images-design.md` (read it first; it holds the decisions and the reasoning).

## Global Constraints

- Image names: `${WXNOTIFY_REGISTRY}/wxnotify` (PHP), `/nwws`, `/emwin-byteblaster`, `/emwin-http`, `/glm-decoder`. `WXNOTIFY_REGISTRY` defaults to `wxnotify` in compose files; in CI it is the repository variable `DOCKERHUB_NAMESPACE`. The namespace is not chosen yet; nothing may hard-code it.
- Every WxNotify image in a compose file uses `:${WXNOTIFY_VERSION}`. Release tags: `MAJOR.MINOR.PATCH`, `MAJOR.MINOR`, `MAJOR`, `latest`, from git tags `vMAJOR.MINOR.PATCH`.
- Platforms: `linux/amd64` and `linux/arm64`, built natively (`ubuntu-24.04` and `ubuntu-24.04-arm`), merged into one manifest per image.
- PHP base: `serversideup/php:8.4-fpm-nginx-bookworm-v4.5.1`, kept as build args `PHP_VERSION` and `SERVERSIDEUP_VERSION` as in `docker/8.4/Dockerfile`.
- Container names stay `wxnotify-nwws`, `wxnotify-emwin-byteblaster`, `wxnotify-emwin-http` (the dashboard's Docker control addresses them by name, `app/Enums/PipelineFeed.php`).
- `AUTORUN_ENABLED=true` is set only on the single-server `front-end` service (ServerSideUp runs automations in any container that has it, whatever its command). `AUTORUN_LARAVEL_MIGRATION_ISOLATION` is NOT set (superseded after the end-to-end run: `migrate --isolated` needs the `cache_locks` table before any migration has created it, so a fresh install could not start). Everywhere else AUTORUN is unset. A failed migration stops the container before the server starts; with `restart: unless-stopped` it retries and never becomes healthy.
- Worker `command:`, `stop_grace_period`, and `healthcheck:` come from the table in Task 6; the image's own HEALTHCHECK (nginx curl) is wrong for workers and must be overridden on each.
- No image contains `.env`, `auth.json`, `tests/`, `docs/`, `.git`, `.claude/`, `.ai/`, `node_modules/` or dev dependencies, and no build step receives credentials.
- Env names introduced: `OUTLOOK_MAPS_DISK` (default `local`), `REVERB_PUBLIC_HOST` (optional), `REVERB_PUBLIC_PORT` (default `8085`), `REVERB_PUBLIC_SCHEME` (default `http`).
- Licence: GPL-3.0-or-later.
- Before editing any file, open `.ai/rules/index.md` and read every rule file whose globs cover it. After editing PHP run `vendor/bin/pint --dirty --format agent`. Run only the narrowest affected tests (`php artisan test --compact <file>`); the owner runs the full suite.
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. The repository's git identity is already `WxNotify <215090122+wxnotify@users.noreply.github.com>`; do not change it.
- Do not push anything, and do not publish any image. The owner pushes.

## Review Focus

Spec-implied failure modes that no single task's feature tests would otherwise cover; each is pinned by the task named.

1. **Workers starting before the first migration finishes** on a fresh install (they would crash-loop or run against an empty schema). Pinned in Task 6 (`verify.sh` asserts every worker waits on `front-end: service_healthy`) and Task 9 (a fresh install shows zero restarts).
2. **Fresh named volumes owned by root while the app runs as uid 33** (`www-data`), so the first write fails. Pinned in Task 5 (smoke test writes under `storage/` as the container user) and Task 9.
3. **`env()` calls outside `config/`** return `null` once ServerSideUp's automation caches config, silently breaking settings in production. Pinned in Task 5 (`NoEnvOutsideConfigTest`).
4. **Secrets in the page or the image:** the Reverb secret in the Products page, or `.env`/`auth.json` in an image layer. Pinned in Task 1 and Task 5.
5. **An unreachable or misconfigured Outlook map disk** must read as "no map", not a 500, and a misnamed disk must fail with a clear error. Pinned in Task 2.

---

### Task 1: Reverb settings rendered at request time

**Files:**
- Modify: `config/reverb.php`, `resources/js/echo.js`, `resources/views/filament/dashboard/pages/live-weather/products.blade.php`, `.env.example`
- Test: `tests/Feature/LiveWeatherProductsPageTest.php`, `tests/Feature/BroadcastingConfigTest.php`

**Interfaces:**
- Produces: `config('reverb.public')` as `array{key: ?string, host: ?string, port: int, scheme: 'http'|'https'}`, built from `reverb.apps.apps.0.key` and `REVERB_PUBLIC_HOST` / `REVERB_PUBLIC_PORT` (default 8085) / `REVERB_PUBLIC_SCHEME` (default `http`). `env()` is read only in the config file (config caching).
- Produces: a `window.wxReverbConfig = {key, host, port, scheme}` object written into the Products page before `@vite('resources/js/echo.js')`; `host` is `null` when unset.

- [ ] **Step 1: Write the failing tests.** In `LiveWeatherProductsPageTest.php` add, using that file's existing page-render helpers and `$this->withoutVite()`:
  - `'the page carries the Reverb settings for the browser and no secret'`: set `config(['reverb.public' => ['key' => 'pub-key', 'host' => 'wx.example.com', 'port' => 443, 'scheme' => 'https'], 'reverb.apps.apps.0.secret' => 'sekret-value-for-test'])`; render; assert the HTML contains `wxReverbConfig` with `"key":"pub-key"`, `"host":"wx.example.com"`, `"port":443`, `"scheme":"https"`, and does **not** contain `sekret-value-for-test`.
  - `'an unset public host is rendered as null so the browser uses its own host'`: `host => null`; assert `"host":null`.
  In `BroadcastingConfigTest.php` add `'the public reverb settings default to the published port over plain ws and use the app key'`: assert `config('reverb.public.key')` equals `config('reverb.apps.apps.0.key')`, `port` is an `int`, `scheme` is `http` or `https`.

- [ ] **Step 2: Run to verify they fail.** `php artisan test --compact tests/Feature/LiveWeatherProductsPageTest.php tests/Feature/BroadcastingConfigTest.php` → the three new tests fail.

- [ ] **Step 3: Implement.** Add the `public` block to `config/reverb.php`; emit `window.wxReverbConfig` with `@js(config('reverb.public'))` in the Blade view; in `echo.js` read `window.wxReverbConfig` first (`host` falls back to `location.hostname`, `forceTLS` is `scheme === 'https'`) and keep the existing `import.meta.env.VITE_REVERB_*` path as the fallback when it is absent. Keep the try/catch and the `wxEchoUnavailable` behaviour unchanged. Document the three new variables in `.env.example` beside the existing Reverb block, noting `VITE_REVERB_*` is now only a development fallback.

- [ ] **Step 4: Run to verify they pass**, together with the whole of `LiveWeatherProductsPageTest.php` and `BroadcastingConfigTest.php`. Expected: all pass. Then `npm run build` completes with no errors.

- [ ] **Step 5: Browser check.** With the dev stack running, open the Live Weather Products page in the built-in browser; read the console and `window.Echo`. Expected: no errors, the WebSocket connects to `ws://<page host>:8085`, and the page chip reads Live. Then set `REVERB_PUBLIC_PORT=9` and reload: the page reads Offline and keeps polling.

- [ ] **Step 6: Pint and commit.** `git add` the six files; `git commit -m "Live Weather Products: render the Reverb settings at request time"`.

### Task 2: Configurable Outlook map disk

**Files:**
- Create: `app/Support/OutlookMapDisk.php`
- Modify: `config/filesystems.php`, `app/Actions/Alerts/StoreOutlookReportRun.php`, `app/Http/Controllers/Api/V1/OutlookMapController.php`, `app/Actions/Alerts/PresentAppOutlookReport.php`, `app/Console/Commands/PruneOutlookMaps.php`, `.env.example`, `docs/multi-server-deployment.md`
- Test: `tests/Feature/StoreOutlookReportRunTest.php`, `tests/Feature/PruneOutlookMapsTest.php`, `tests/Feature/ApiAlertOutlookReportTest.php`

**Interfaces:**
- Produces: `App\Support\OutlookMapDisk::disk(): Illuminate\Contracts\Filesystem\Filesystem`, resolving `Storage::disk(config('filesystems.outlook_maps_disk'))`; `config('filesystems.outlook_maps_disk')` from `OUTLOOK_MAPS_DISK` (default `local`). A disk name with no definition throws `InvalidArgumentException` naming the setting. All four callers use it and never `Storage::disk('local')` directly for maps.

- [ ] **Step 1: Write the failing tests.** Add, keeping every existing test untouched (they use the default `local` disk and must still pass):
  - `StoreOutlookReportRunTest`: `'stores the maps on the configured disk and not on local'`: `config(['filesystems.outlook_maps_disk' => 'maps']); Storage::fake('maps'); Storage::fake('local');` store a run; assert the files exist on `maps` and `Storage::disk('local')->assertMissing(...)`.
  - `PruneOutlookMapsTest`: `'prunes and sweeps on the configured disk'` (old run's directory deleted and `maps` cleared; orphan folder swept) with the same setup.
  - `ApiAlertOutlookReportTest`: `'serves a stored map from the configured disk through the signed link'` and `'an unreachable disk reads as no map, not an error'` (configure a disk whose `exists()` returns false for everything, e.g. a `local` disk with `throw => false` pointing at an empty directory; assert the report has a null `url` and the signed route returns 404, never 500).
  - A unit-style test in `StoreOutlookReportRunTest`: `'an undefined outlook map disk fails with a message naming OUTLOOK_MAPS_DISK'` → `expect(fn () => OutlookMapDisk::disk())->toThrow(InvalidArgumentException::class, 'OUTLOOK_MAPS_DISK')`.

- [ ] **Step 2: Run to verify they fail.** `php artisan test --compact tests/Feature/StoreOutlookReportRunTest.php tests/Feature/PruneOutlookMapsTest.php tests/Feature/ApiAlertOutlookReportTest.php`.

- [ ] **Step 3: Implement** `OutlookMapDisk::disk()`, the config key (a top-level `outlook_maps_disk` in `config/filesystems.php`, `env()` only there), and replace the five `Storage::disk('local')` map calls (store, controller `exists`/`get`, presenter `exists`, prune `deleteDirectory` and the sweep's `directories`/`allFiles`/`lastModified`). The controller keeps its response headers exactly.

- [ ] **Step 4: Run to verify they pass** (the three files). Expected: all pass, including every existing test.

- [ ] **Step 5: Documentation.** In `.env.example` document `OUTLOOK_MAPS_DISK` and the standard `AWS_*` settings (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`) with a Backblaze B2 example. In `docs/multi-server-deployment.md` replace the "Outlook map images are not shared" limit with the instruction to set `OUTLOOK_MAPS_DISK=s3` and the `AWS_*` values on servers A and D.

- [ ] **Step 6: Record the rule** with the Boost `record-rule` tool for glob `app/Actions/Alerts/**`: outlook map files always go through `OutlookMapDisk`, never a named disk.

- [ ] **Step 7: Pint and commit.** `git commit -m "Outlook maps: store them on a configurable disk"`.

### Task 3: GPL licence

**Files:**
- Create: `LICENSE`
- Modify: `composer.json`

**Interfaces:** none.

- [ ] **Step 1:** Download the licence text verbatim: `curl -fsSL https://www.gnu.org/licenses/gpl-3.0.txt -o LICENSE`. Verify it is the genuine GPL-3.0 text (the canonical file's MD5 is `d32239bcb673463ab874e80d47fae504`; if it differs, compare with the `GPL-3.0` text shipped in `vendor/` before accepting).
- [ ] **Step 2:** Set `composer.json` `"license": "GPL-3.0-or-later"`. Run `composer validate --no-check-publish`. Expected: valid, and `composer.lock` reports no content-hash warning.
- [ ] **Step 3: Check vendored components' licences are GPL-compatible** and report each in your task report: `pipeline/nwws-go-client/LICENSE` (MIT), `pipeline/nwws-go-client/third_party/gosrc.io-xmpp` (its own `LICENSE`), the committed `docker/emwin-byteblaster` content, and `public/js/jbuitt/filament-toggle-list`. Any incompatible component is a blocker: stop and report it, do not commit.
- [ ] **Step 4: Commit.** `git commit -m "Licence the project under GPL-3.0-or-later"`.

### Task 4: Build npemwin from source

**Files:**
- Modify: `docker/emwin-byteblaster/Dockerfile`
- Delete: `docker/emwin-byteblaster/packages/` (only after Step 6 passes)

**Interfaces:**
- Produces: the same `emwin-byteblaster` image behaviour as today, with `npemwin` installed from a pinned upstream commit; build args `NPEMWIN_COMMIT` and `NPEMWIN_SHA256`.

- [ ] **Step 1: Pin a source.** Using Bitbucket's API (`https://api.bitbucket.org/2.0/repositories/noaaport/npemwin/commits?path=VERSION`, then `.../src/<commit>/VERSION`) find the commit whose `VERSION` is `3.0.1p1`. If none exists, pin the newest `3.1.1p1` commit and record a ruling in your report. Download `https://bitbucket.org/noaaport/npemwin/get/<commit>.tar.gz` and record its SHA-256.
- [ ] **Step 2: Learn how it builds** from the tarball's `README.rst`, `configure.sh` and `Makefile.in` (it has no `debian/` directory). Determine the configure prefix (the `.deb` installs under `/usr/local`) and the build dependencies.
- [ ] **Step 3: Compare with the packaged layout.** In a throwaway `debian:12-slim` container, list the files of the vendored amd64 `.deb` (`dpkg-deb -c`). The source build's staged install must provide the same files, and what the `.deb`'s post-install script did (the `noaaport` user and `/var/npemwin/*`, `/var/log/npemwin` directories and ownership) must be done in the Dockerfile.
- [ ] **Step 4: Rewrite the Dockerfile** as a build stage (compiler and `-dev` packages, download with the checksum verified, build, install to a staging directory) and a final stage on `debian:12-slim` with only the runtime packages the `.deb` needed (`tcl`, `tcllib`, `libdb5.3`, `libwrap0`, `gnuplot-nox`, plus those already installed), `COPY --from=build`, the ISC `LICENSE` copied to `/usr/share/doc/npemwin/`, and the existing entrypoint, healthcheck and scripts unchanged.
- [ ] **Step 5: Build both architectures.** `docker buildx build --platform linux/arm64 -f docker/emwin-byteblaster/Dockerfile -t emwin-src:arm64 --load .` and the same for `linux/amd64` (emulated is acceptable here). Expected: both build.
- [ ] **Step 6: Acceptance test.** Run the arm64 image for at least 7 minutes against the real servers (as `docs/superpowers/specs/2026-09-19-emwin-byteblaster-container-design.md` did). Expected, in your report with evidence: it connects; products appear as `/var/npemwin/files/txt/<cccc>/<prodname>.<md5>.txt`; `panfilter` fires once per new file and the webhook is called; the container healthcheck passes; it runs as `noaaport`. Any difference from the recorded behaviour is a defect, not a note.
- [ ] **Step 7:** Delete `docker/emwin-byteblaster/packages/` (including `SHA256SUMS`) and any reference to it. `git commit -m "emwin-byteblaster: build npemwin from source"`.

### Task 5: The production PHP image

**Files:**
- Modify: `docker/8.4/Dockerfile` (add stages; remove the empty `web-production` and `worker-production` aliases and the comment that describes them as future), `.dockerignore`
- Create: `docker/8.4/smoke-test.sh`, `tests/Feature/NoEnvOutsideConfigTest.php`

**Interfaces:**
- Produces: Dockerfile target `production`, built with the repository root as context (`docker build -f docker/8.4/Dockerfile --target production .`). The dev targets and `compose.yaml` (context `./docker/8.4`) are unchanged.
- Produces: `docker/8.4/smoke-test.sh IMAGE` exiting 0 only if every assertion below holds.

- [ ] **Step 1: Write the failing test.** `NoEnvOutsideConfigTest`: `'no env() call exists outside config/'` scans `app/`, `bootstrap/`, `routes/`, `resources/` and `database/migrations/` for `\benv\(` and expects none (config caching returns `null` for them). It passes today; it is the guard.
- [ ] **Step 2: Write `smoke-test.sh`** (and watch it fail before the target exists). Assertions, each printing a clear failure: (a) the container started with `APP_KEY` and `DB_*` set and the default command becomes healthy and `curl -fsS localhost:8080/up` returns 200; (b) `docker run IMAGE php artisan --version` runs (command override works and nginx does not start); (c) none of `.env`, `auth.json`, `tests`, `docs`, `.git`, `.claude`, `.ai`, `node_modules` exist under `/var/www/html`; (d) `composer show --no-dev` has no `pestphp/pest`, `filament/blueprint` or `laravel/boost`; (e) `public/build/manifest.json` exists; (f) as the container user, a file can be created under `storage/app/private` and `storage/logs` on a **fresh named volume mounted at `/var/www/html/storage`**; (g) `uname -m` matches the requested platform.
- [ ] **Step 3: Implement the stages.** `app-vendor` (from the existing `web-base` stage: copy `composer.json` and `composer.lock`, `composer install --no-dev --no-scripts --no-autoloader`, copy the application, `composer dump-autoload --optimize --classmap-authoritative`, `php artisan package:discover`; no `auth.json`). `app-assets` (from `node:24-slim`: `npm ci`, copy `resources`, `vite.config.js`, `public` and the `vendor` directory from `app-vendor` because the Filament theme CSS imports from it, `npm run build`). `production` (from the `serversideup/php:...-fpm-nginx` base with the same `install-php-extensions` list as `web-base` minus `xdebug` and `pcov`; copy application, `vendor` and `public/build` with `--chown=www-data:www-data`; `ENV PHP_OPCACHE_ENABLE=1 HEALTHCHECK_PATH=/up`; `storage/{app/private,app/public,framework/{cache,sessions,views},logs}` created and owned by `www-data`; default user unchanged). Extend `.dockerignore` with `auth.json`, `.ai`, `.env.*`, `storage/app`, `compose*.yaml`, `deploy`, `.github`.
- [ ] **Step 4: Build and run the smoke test.** `docker build -f docker/8.4/Dockerfile --target production -t wxnotify-app:smoke .` then `docker/8.4/smoke-test.sh wxnotify-app:smoke`. Expected: exit 0. Record the final image size in the report.
- [ ] **Step 5: Run `php artisan test --compact tests/Feature/NoEnvOutsideConfigTest.php`** → passes. Commit: `git commit -m "Add the production PHP image"`.

### Task 6: Compose files and their verification

**Files:**
- Create: `deploy/single-server/compose.yaml`, `deploy/single-server/.env.example`, `deploy/multi-server/compose.yaml`, `deploy/multi-server/.env.example`, `deploy/verify.sh`

**Interfaces:**
- Consumes: the image names and tags in Global Constraints; the commands, `stop_grace_period` and healthchecks below.
- Produces: `deploy/verify.sh` exiting 0 only if every assertion below holds.

Worker services (same PHP image, `command:` overrides; `restart: unless-stopped`, `stop_signal: SIGTERM`):

| Service | `command:` | `stop_grace_period` | `healthcheck:` test |
| --- | --- | --- | --- |
| `queue` | `php artisan queue:work --queue=deliveries,default,broadcasts --tries=1 --sleep=1 --timeout=60 --memory=192 --max-time=3600` | 70s | `healthcheck-queue` |
| `queue-long` | `php artisan queue:work database_long --queue=long --tries=1 --sleep=3 --timeout=900 --memory=192 --max-time=3600` | 930s | `healthcheck-queue` |
| `queue-heartbeat` | `php artisan queue:work --queue=heartbeat --tries=1` | default | `healthcheck-queue` |
| `scheduler` | `php artisan schedule:work` | default | `healthcheck-schedule` |
| `reverb` | `php artisan reverb:start --host=0.0.0.0 --port=8080` | default | `healthcheck-reverb` |

`pipeline-api` is the same image with the default command, no published port, no automation. The four feeds, `apprise` (`caronc/apprise:2.0.0`), `docker-socket-proxy` (`ghcr.io/tecnativa/docker-socket-proxy:v0.5.0`, same flags as `compose.yaml`), PostgreSQL (`imresamu/postgis:18-3.6`, which publishes amd64 and arm64) and their environment, volumes and container names are copied from `compose.yaml`, with the dev bind mounts, `*-development` targets and `build:` sections replaced by `image:`.

- [ ] **Step 1: Write `deploy/verify.sh` first** (it fails: the files do not exist). For each compose file, with its `.env.example` copied to a temporary `.env`: `docker compose config -q` succeeds; every `wxnotify`-image line uses `${WXNOTIFY_VERSION}`; every worker above waits on `front-end` (`condition: service_healthy`) in the single-server file; no service other than `front-end` sets `AUTORUN_ENABLED`; the multi-server file has `AUTORUN_ENABLED` nowhere; for each of `server-a`..`server-d`, `docker compose --profile <p> config --services` equals exactly the set from `docs/multi-server-deployment.md` (a: `front-end`, `reverb`; b: `pgsql`, `redis`; c: `pipeline-api`, `nwws`, `emwin-byteblaster`, `emwin-http`, `glm-decoder`, `queue-long`, `docker-socket-proxy`; d: `queue`, `queue-heartbeat`, `scheduler`, `apprise`); every `${VAR}` a compose file references appears in its `.env.example`.
- [ ] **Step 2: Single-server `compose.yaml`.** Services `pgsql`, `front-end`, `pipeline-api`, `queue`, `queue-long`, `queue-heartbeat`, `scheduler`, `reverb`, `apprise`, `docker-socket-proxy`, the four feeds. `front-end` publishes `${APP_PORT:-8080}:8080`, sets `AUTORUN_ENABLED=true` (and not the isolation option; see Global Constraints), and depends on `pgsql` healthy. All other PHP services depend on `front-end` healthy. Named volumes: `pgsql-data`, `app-storage` (mounted at `/var/www/html/storage` on `front-end`, `queue` and `scheduler`, which share outlook maps), and the four feed volumes. `reverb` publishes `${REVERB_PUBLISHED_PORT:-8085}:8080`. Service-to-service settings (`DB_HOST=pgsql`, `REVERB_HOST=reverb`, `REVERB_PORT=8080`, `APPRISE_API_URL=http://apprise:8000`, `DOCKER_PROXY_URL=http://docker-socket-proxy:2375`) are set in `environment:` so the self-hoster never edits them. Redis is omitted.
- [ ] **Step 3: Multi-server `compose.yaml`.** One file, profiles `server-a`..`server-d` and the service names of `docs/multi-server-deployment.md` (the dashboard service stays `front-end`), no `depends_on` across profiles, bind addresses and `depends_on`-free ports as `compose.production.yaml` does today, `AUTORUN_ENABLED` absent, `queue-long` and `docker-socket-proxy` in `server-c`.
- [ ] **Step 4: The two `.env.example` files** list only what a self-hoster must set, in the order they meet it (`WXNOTIFY_VERSION` first; `APP_URL`; `APP_KEY`; `DB_PASSWORD`; `REVERB_APP_KEY`/`SECRET`; `PIPELINE_WEBHOOK_TOKEN`; mail; optional `NWWS_USERNAME`/`NWWS_PASSWORD`; the Outlook and Reverb public settings; multi-server adds the address variables), each with the command that generates it.
- [ ] **Step 5: Run `deploy/verify.sh`.** Expected: exit 0. Commit: `git commit -m "Add the self-hosting compose files"`.

### Task 7: Release pipeline

**Files:**
- Create: `.github/workflows/release.yml`

**Interfaces:**
- Consumes: Task 5's `smoke-test.sh`; Dockerfiles `docker/8.4/Dockerfile` (target `production`), `docker/nwws/Dockerfile`, `docker/emwin-byteblaster/Dockerfile`, `docker/emwin-http/Dockerfile`, `docker/glm-decoder/Dockerfile`, all with context `.`.
- Produces: on tag `v*.*.*`, five multi-arch images on Docker Hub with the four tags; repository variable `DOCKERHUB_NAMESPACE`, secrets `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN`.

- [ ] **Step 1: Decide how CI installs dev dependencies without credentials.** `filament/blueprint` is a dev dependency from `packages.filamentphp.com`, which needs HTTP-basic credentials; public CI and contributors have none. In a clean checkout with no `auth.json`, find a sequence that leaves `vendor/bin/pest` runnable: recommended is `composer config --unset repositories.filament && composer remove --dev filament/blueprint --no-interaction` in the CI workspace only, then `composer install`. Prove it locally (temporarily move `auth.json` aside) and put the proven commands in the workflow. If it cannot work, use a `COMPOSER_AUTH` repository secret and say so in the report.
- [ ] **Step 2: Write the workflow** with three jobs: `test` (PostGIS started with `docker run -p 5432:5432 ... postgres -c max_locks_per_transaction=256`, the Step 1 commands, `php artisan test --parallel`); `build` (matrix of the five images × two platforms on `ubuntu-24.04` / `ubuntu-24.04-arm`, `needs: test`, builds and loads the image, runs `smoke-test.sh` for the PHP image, then `push-by-digest` only on tag pushes); `merge` (one job per image: `docker buildx imagetools create` with tags from `docker/metadata-action` semver patterns). Registry login only on tag pushes, never on pull requests, using the secrets above. Pin every third-party action to a full commit SHA with a version comment.
- [ ] **Step 3: Verify.** `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest` exits 0. The tag-only login and push conditions are present. Nothing is pushed.
- [ ] **Step 4: Commit.** `git commit -m "Add the release workflow"`.

### Task 8: Self-hoster documentation

**Files:**
- Create: `deploy/README.md`
- Modify: `README.md` (replace the Laravel boilerplate with a WxNotify introduction and a link to `deploy/README.md`), `docs/multi-server-deployment.md` (header: it describes running from a git checkout; point to `deploy/README.md` for the image-based route), `deploy/verify.sh` (extend)

**Interfaces:** none.

- [ ] **Step 1: Extend `deploy/verify.sh`** with a failing check: every environment variable named in `deploy/README.md`'s command blocks exists in the matching `.env.example`, and the README mentions each of: `WXNOTIFY_VERSION`, `OUTLOOK_MAPS_DISK`, `REVERB_PUBLIC_SCHEME`, `app:grant-owner`.
- [ ] **Step 2: Write `deploy/README.md`**, readable as the Docker Hub overview: what WxNotify is and the images; single-server quick start (download the two files, copy to `.env`, generate `APP_KEY` with `docker run --rm <image> php artisan key:generate --show` (confirm this works without a `.env`; if not, document the working form) and the other secrets, `docker compose up -d`, register in the dashboard, `docker compose exec front-end php artisan app:grant-owner <email>`); TLS left to the self-hoster with a Caddy and an nginx example forwarding `/app` and `/apps` to port 8085 and setting `REVERB_PUBLIC_SCHEME=https` and `REVERB_PUBLIC_PORT=443`; the live page works without it by polling; upgrading (change `WXNOTIFY_VERSION`, `pull`, `up -d`; back up first; migrations are forward-only); the multi-server route (one file with profiles, the explicit migrate step, `queue:restart`/`reverb:restart`, `OUTLOOK_MAPS_DISK=s3` on A and D); backups; where to report problems.
- [ ] **Step 3: Replace `README.md`** and update the header of `docs/multi-server-deployment.md`.
- [ ] **Step 4: Run `deploy/verify.sh`** → exit 0. Commit: `git commit -m "Document the image-based self-hosting route"`.

### Task 9: End-to-end verification

**Files:** none planned; fixes found here go back to the task that owns them.

- [ ] **Step 1: Build every image locally** for the host architecture, tagged `local`, and run `docker/8.4/smoke-test.sh` on the PHP image.
- [ ] **Step 2: Fresh single-server install.** In an empty temporary directory containing only `deploy/single-server/compose.yaml` and a `.env` filled from `.env.example` with `WXNOTIFY_VERSION=local`, run `docker compose up -d`. Expected: `pgsql` and `front-end` become healthy; the workers then start; `docker compose ps` shows every service running or healthy with **zero restarts** (Review Focus 1); `front-end` logs show the migrations ran; `/up` returns 200; the login page renders.
- [ ] **Step 3: First owner and live page.** Register an account, run `app:grant-owner`, open Live Weather Products in the built-in browser. Expected: connects over `ws://<host>:8085` with the default settings; reads Live. Storage writes succeed on the fresh volume (Review Focus 2): trigger something that writes `storage/logs`.
- [ ] **Step 4: Upgrade.** Rebuild a second image tagged `local2` containing a trivial new migration, change `WXNOTIFY_VERSION`, `pull`/`up -d`. Expected: data kept, migration applied by `front-end`, workers restarted.
- [ ] **Step 5: Failure path.** Make a migration fail (a throwaway broken migration in a third build). Expected: `front-end` exits, retries, never turns healthy, workers never start, and the error is in `docker compose logs front-end` (the behaviour the docs promise).
- [ ] **Step 6: Multi-server.** Run `docker compose --profile server-b up -d` then server-a and server-d files on one host with distinct bind addresses (or confirm by `config` plus a two-profile run) and the explicit migrate command. Expected: the documented sequence works.
- [ ] **Step 7:** Record results and every defect found in the task report. No commit unless a fix was needed (committed to the owning task's files).

### Task 10: Pre-publication sweep

**Files:** whatever the sweep finds.

- [ ] **Step 1: Remove local paths.** `git grep -n "/Users/jimmy"` and change each to a neutral form.
- [ ] **Step 2: Run a second secret scanner** over the history and the working tree: `docker run --rm -v "$PWD":/repo zricethezav/gitleaks:latest detect --source /repo --redact`. Triage every finding; test fixtures and documentation placeholders are expected.
- [ ] **Step 3: Skim `docs/superpowers/` and `.ai/rules/`** for anything private (server names, personal details, credentials) and report, do not delete, what you find.
- [ ] **Step 4: Report the optional history purge.** The vendored `npemwin` `.deb` files remain in history; report the one-line `git filter-repo --path docker/emwin-byteblaster/packages --invert-paths` option for the owner to run just before publishing. Do not run it.
- [ ] **Step 5: Commit** any path fixes: `git commit -m "Remove local paths before publication"`.
