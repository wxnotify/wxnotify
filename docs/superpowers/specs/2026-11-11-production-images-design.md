# Production images and self-hosting quick start: design

Date: 2026-11-11. Status: owner decisions recorded; ready for an implementation plan.

## Intent

WxNotify is going open source on the model Healthchecks uses: free to self-host, income from a paid hosted service. Self-hosters get the fastest possible route to a working install: download one compose file, edit a short `.env`, run a few commands. They never clone the repository, run Composer or npm, or build an image.

To make that true, the project publishes ready-made container images to Docker Hub and the compose files and instructions to GitHub (and the Docker Hub overview page). There is no licence key, no registry login and no phone-home. The hosted service runs the same images.

Today there is no production image. `docker/8.4/Dockerfile` defines `web-production` and `worker-production` targets, but they are empty aliases of the base stage with no application code. All current deployment bind-mounts a git checkout into development-style images (see `docs/multi-server-deployment.md`).

## Goals

- One page of instructions takes a person with Docker and a domain from nothing to a running WxNotify, single server, in minutes.
- A multi-server path (the four-server A/B/C/D split in `docs/multi-server-deployment.md`) from the same images, with no git checkout on any server.
- Images are built from public source in public CI, for amd64 and arm64.
- Upgrading is one version change plus pull and up.
- Nothing secret, personal or owner-specific is baked into any image.

## Non-goals

- Licensing, Keygen, or any licence check.
- Shipping a TLS reverse proxy. Self-hosters bring their own; the docs give an example.
- Kubernetes, Helm, Swarm or other orchestrators.
- High availability. One server per role stays the model.
- Backup tooling (documented only), and monitoring services (Healthchecks, Bugsink, Uptime Kuma).
- The mobile app's server configuration.
- Changing how the development stack (`compose.yaml`, `compose.dev-listen.yaml`) works. It stays as is.

## Anti-goals

- A quick start that works only until the first upgrade.
- An image that behaves differently on the hosted service and for self-hosters beyond configuration.
- A quick start that silently loses data (migrations that run twice at once, volumes that vanish on `down`).

## What the owner has decided

1. **Where things live.** Images on Docker Hub. The whole repository public on GitHub. Instructions on both, with one or more compose files depending on single or multi-server, the required `.env` changes, and the commands to bring the system up.
2. **Base images.** ServerSideUp's PHP images, as in the next-gen plan.
3. **Image set.** One PHP image serves every PHP role (web, `pipeline-api`, `queue`, `queue-long`, `queue-heartbeat`, `scheduler`, `reverb`), chosen by `command:`. Four feed-client images: `nwws`, `emwin-byteblaster`, `emwin-http`, `glm-decoder`. PostgreSQL (PostGIS), Redis (optional) and Apprise stay stock third-party images.
4. **TLS** is the self-hoster's job for now.
5. **Migrations.** Automatic in the single-server file. Explicit (a documented command) in the multi-server set.
6. **Versions.** The compose files pin an exact release through one variable, `WXNOTIFY_VERSION`, used by every WxNotify image so they cannot drift. Releases also carry `MAJOR.MINOR`, `MAJOR` and `latest` tags for people who follow along.
7. **Architectures.** amd64 and arm64.
8. **Live updates (Reverb).** The server hands the browser its Reverb settings at request time, so one prebuilt image works on any domain. Host defaults to the host the browser is on; port and scheme are configurable; the default is the published Reverb port over `ws`, which works on a plain-HTTP quick start. Behind a TLS proxy the self-hoster sets the public scheme and port and forwards `/app` and `/apps` to the Reverb container. Without Reverb the page polls, as it already does.

9. **Compose files** live in a new top-level `deploy/` directory (approved).
10. **Outlook maps in multi-server** use a configurable disk pointed at S3-compatible storage (see "Cross-role application state").
11. **Source licence:** GPL. **`npemwin`:** build it from source during the Docker image build, instead of vendoring `.deb` files.

## The PHP image

Built `FROM serversideup/php:8.4-fpm-nginx-bookworm-<pinned version>` (the repo currently pins v4.5.1).

**Verified by test on the development image:** setting `command:` replaces the image's default `/init`. S6, nginx and php-fpm do not start; the image's startup scripts still run. So one image serves web (default command) or any worker (`command: php artisan ...`). The image's built-in `HEALTHCHECK` curls nginx, so every worker service in the compose files must override `healthcheck:`. The image already ships `healthcheck-queue`, `healthcheck-schedule`, `healthcheck-reverb` and `healthcheck-horizon`.

**Build stages:**
- A composer stage: `composer install --no-dev --optimize-autoloader`. No credentials are needed: the only package from Filament's licensed repository (`filament/blueprint`) is dev-only, and the build must not be given `auth.json`.
- A Node stage: `npm ci && npm run build` for the Vite assets, with no `VITE_REVERB_*` values baked in (see Reverb below).
- A final stage on the ServerSideUp base with the application copied in, `www-data` owning only what must be writable, no `.env`, no Node, no dev tooling, and `PHP_OPCACHE_ENABLE=1`. It runs as the image's default non-root user (uid 33).

**Not in the image:** `.env`, `auth.json`, tests, `node_modules`, `docs/`, `.git`, `.claude/`, `.ai/`. A `.dockerignore` enforces this.

**Application state.** `storage/` and the product volumes are volumes, never image layers. Which parts of `storage/` must be shared between roles is an open item below.

**Startup automation (to confirm against ServerSideUp's docs):** the single-server `front-end` container enables the Laravel automations (migrations with the isolation option, and whichever of config caching and storage linking suit a container whose settings come from environment variables). Everything else leaves automations off. The multi-server set turns them all off.

## The feed images

Reused as they are, built with the repository root as context:
- `nwws`: Go, from `pipeline/nwws-go-client` (a vendored XMPP library is in `third_party`), static binary.
- `emwin-http`: Go, from `docker/emwin-http`; the build runs `go vet` and `go test`.
- `glm-decoder`: Python 3.12 with `boto3`, `h5py`, `requests`.
- `emwin-byteblaster`: Debian plus `npemwin`, **built from source in a build stage** (see "npemwin from source"), replacing the `.deb` files committed under `docker/emwin-byteblaster/packages/`.

All four are published multi-arch under the same version as the PHP image. Container names stay `wxnotify-nwws`, `wxnotify-emwin-byteblaster` and `wxnotify-emwin-http`, because the dashboard's Docker control addresses them by name (`app/Enums/PipelineFeed.php`).

## npemwin from source

`npemwin` is a Tcl/C program from NOAAPort (`https://bitbucket.org/noaaport/npemwin`), ISC-licensed (copyright 2004-2012 Jose F. Nieves): permissive, redistribution allowed with the copyright and permission notice. That means the vendored `.deb` files were never a legal problem if the notice travels with them. Building from source is still preferred: provenance is a pinned upstream commit rather than an unexplained binary, and one build stage gives both architectures.

What the builder must work out, from what was found:
- The repository has `configure.sh`, `Makefile.in`, `Makefile.inc.in` and a `LICENSE`; it has no `debian/` directory and **no release tags**. Pin a specific commit and fetch its tarball from Bitbucket (`https://bitbucket.org/noaaport/npemwin/get/<commit>.tar.gz`), with a checksum check. Bitbucket's TLS works, unlike noaaport.net's, which is why the `.deb` files were vendored in the first place (`2026-09-19-emwin-byteblaster-container-design.md`).
- `master`'s `VERSION` is `3.1.1p1`, a prerelease. The images run `3.0.1p1` today. Either find the commit that matches `3.0.1p1` or validate `3.1.1p1`. The behaviour recorded in the earlier byteblaster spec (output under `/var/npemwin/files/txt/<cccc>/<prodname>.<md5>.txt`, `panfilter` firing once per new file, the `noaaport` user and directories the package pre-creates) is the acceptance test: a source build must reproduce it. This is a live-alert path, so a behaviour change is a defect.
- Runtime dependencies stay as in the `.deb` today (`tcl`, `tcllib`, `libdb5.3`, `libwrap0`, `gnuplot-nox`); the build stage adds the compiler and `-dev` packages and nothing from it reaches the final image.
- The installed files and the licence notice must end up in the image (for example under `/usr/share/doc/npemwin/`).
- The vendored `.deb` files and `SHA256SUMS` stay until the source build passes the acceptance test, then they are removed.

## Reverb runtime configuration (an application change)

Today `resources/js/echo.js` reads `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` and `VITE_REVERB_SCHEME` at build time. It is loaded only by the Live Weather Products page (`resources/views/filament/dashboard/pages/live-weather/products.blade.php`). Change it so that:

- the page writes the settings into the HTML at request time from server configuration (the Reverb app key, a public port, a public scheme, and an optional public host override);
- `echo.js` reads that and falls back to the `VITE_*` values for development;
- with no host set, the host is the page's own hostname;
- the Reverb secret is never exposed to the browser (only the key, which browsers need by design);
- with no key, or a blocked socket, behaviour is unchanged: no `Echo`, polling.

New environment variables are named and documented in `.env.example`. The existing tests for the Products page keep passing, with new tests for the rendered configuration.

## Compose files

Published in the repository (and quoted on Docker Hub), separate from the development `compose.yaml`:

- **Single-server.** PostgreSQL (PostGIS), the PHP image in each role, Apprise, `docker-socket-proxy`, the four feed images, and named volumes for the database, application storage, and each product/state directory. The web port is published for plain HTTP; nothing else is published to the host except what the self-hoster opts into. Redis is left out because the default configuration does not use it. `queue`, `queue-long`, `queue-heartbeat`, `scheduler` and `reverb` wait for the web container to be healthy, so a first install does not start workers against an unmigrated database. Mail is configured through SMTP settings, with no Mailpit.
- **Multi-server.** One compose file used on every server, with the `server-a` to `server-d` profiles and role assignments from `docs/multi-server-deployment.md`. It has no `depends_on` across profiles (the problem `compose.production.yaml` works around today), binds every cross-server port to a self-hoster-set address, and takes image tags from `WXNOTIFY_VERSION`. `queue-long` and `docker-socket-proxy` stay on the feed host (server-c).
- Each worker service carries its own `healthcheck:` (queue-style for the three queue workers, schedule and reverb scripts for those two), `stop_signal` and `stop_grace_period` as in `compose.yaml` today (70 s for `queue`, 930 s for `queue-long`).
- Container names for the three feeds, and `restart: unless-stopped` on every service, as in development.

A production `.env.example` lists only what a self-hoster must set, in the order they will meet it: `WXNOTIFY_VERSION`, `APP_URL`, `APP_KEY`, the database password, the Reverb keys, mail settings, `PIPELINE_WEBHOOK_TOKEN`, and the optional feed credentials (`NWWS_USERNAME`, `NWWS_PASSWORD`). Everything else keeps a safe default.

## First run and upgrades

**First run (single server):** download the compose file and `.env.example`; copy to `.env`; generate `APP_KEY` and the Reverb and webhook secrets with documented one-liners (`APP_KEY` by running the image: to confirm it works without a `.env`); `docker compose up -d`; register an account in the dashboard; promote it with `php artisan app:grant-owner <email>` through `docker compose exec`. Whether a first-owner step simpler than "register, then promote" is worth building is an open item.

**First run (multi-server):** as `docs/multi-server-deployment.md`, with the git-clone, Composer and npm steps removed, and the explicit migration command.

**Upgrade:** change `WXNOTIFY_VERSION`, `docker compose pull`, `docker compose up -d`. Multi-server adds the migration command on A first and `queue:restart` / `reverb:restart` afterwards, as today. Migrations are forward-only; the docs say so and say to back up PostgreSQL before upgrading.

## Release pipeline

GitHub Actions, triggered by a version tag (`vMAJOR.MINOR.PATCH`):

- builds each image for amd64 and arm64 on native runners (GitHub's free arm64 runners for public repositories), then merges them into one multi-arch manifest per image;
- pushes to Docker Hub with the tags `MAJOR.MINOR.PATCH`, `MAJOR.MINOR`, `MAJOR` and `latest`;
- before pushing, runs the application test suite, and a smoke test per PHP role that starts the image and asserts it becomes healthy;
- uses a Docker Hub token stored as an encrypted repository secret, never available to pull-request builds from forks;
- builds with no application secrets and no `auth.json`.

Optional hardening (SBOM, provenance attestation, a vulnerability scan) can follow; they are not required for the first release.

## Before the repository goes public (not image work, but blocking)

- **Licence: GPL (owner's decision).** My call on the version: GPL-3.0-or-later, which is compatible with the one GPL dependency (`phpmyadmin/shapefile`, GPL-2.0-or-later). Add a `LICENSE` file at the repository root and set `composer.json`'s `license` field to match (it says `MIT`, left over from the Laravel skeleton). `pipeline/nwws-go-client` keeps its own MIT `LICENSE`, the vendored XMPP library keeps its own licence, and `npemwin` keeps its ISC notice; the builder checks every vendored component's licence is GPL-compatible. Note for the owner: GPL, unlike AGPL, does not oblige someone who runs a modified copy as a hosted service to publish their changes. The owner chose GPL knowing the hosted-service model; recorded here so it is a conscious choice.
- **Commit identity (owner's decision): rewrite history** so every author and committer is `WxNotify <215090122+wxnotify@users.noreply.github.com>`. Done on 2026-11-11 for this repository's local branches (`main`, `subkit-changes` and the `claude/zealous-mcnulty-64984d` worktree branch; trees and commit counts verified identical), and a backup bundle of the old history was kept outside the repository. The author name `Jim Buitt` was changed to `WxNotify` in a second pass. Two old addresses were mapped (`jbuitt@gmail.com`, and `jbuitt+git@gmail.com` on one commit). It takes a force-push to replace the GitLab copy. The mobile repository has the same address in its history and is not changed.
- **Vendored `npemwin` `.deb` files:** removed once the source build passes (see above). They would still exist in history; with nothing public yet, one more history rewrite just before publishing can drop them (`git filter-repo --path docker/emwin-byteblaster/packages --invert-paths`) if the owner wants the repository free of them.
- Skim `docs/superpowers/` and `.ai/rules/` for anything private; remove `/Users/jimmy` paths. A history scan found no committed secrets (pattern-based; a second scanner pass, such as `gitleaks`, is recommended).

## Cross-role application state (investigated)

Only one thing on disk is written by one role and read by another: **Outlook map images**. `DeliverScheduledAlert` (the `deliveries` queue, server D) stores them through `StoreOutlookReportRun` on `Storage::disk('local')` under `outlook-maps/<batch>/`; the dashboard and mobile API (server A) read them (`OutlookMapController`, `PresentAppOutlookReport`); `outlook:prune-maps` (scheduler, D) deletes them. On one host every container shares the same folder, so it works. Across hosts A cannot see D's files and the mobile API returns 404 for outlook maps. The current multi-server guide has this defect.

Nothing else crosses roles: shapefile imports download a zip, load it into the database and delete their temp directory inside one job; the dashboard wallpapers are repository files under `public/images/` and are baked into the image; sessions, cache and queue are in PostgreSQL; logs are per container.

**Fix:** make the disk for outlook maps configurable (a new setting, default `local`, so single-server and development are unchanged) and have the multi-server docs say to point it at an S3-compatible disk (the plan already names Backblaze B2). `config/filesystems.php` already has an `s3` disk. The alternatives are a shared network volume (no code change, but NFS between A and D to operate) or not supporting outlook maps in multi-server for the first release. **Decided (owner): use the configurable disk with S3.** The setting is `OUTLOOK_MAPS_DISK`, default `local`. `StoreOutlookReportRun`, `OutlookMapController`, `PresentAppOutlookReport` and `PruneOutlookMaps` all go through that one disk. Multi-server documentation tells self-hosters to set it to an S3-compatible disk and supply the standard `AWS_*` credentials, bucket, endpoint and path-style settings; single-server leaves it `local` on the application-storage volume. The bucket needs no public access, because the mobile API streams the images. `OutlookMapController` currently sets response headers around `Storage::disk('local')->get(...)`, and the builder must keep that behaviour and test it against a fake disk. Pruning must work on an S3 disk too (`deleteDirectory`).

## Open items for the builder to resolve by investigation

- The exact ServerSideUp automation variables, and what each does on startup.
- Whether `php artisan key:generate --show` runs from the image with no `.env`.
- That `healthcheck-schedule` and `healthcheck-reverb` work for `schedule:work` and `reverb:start` as run here, or what each needs.
- Image size of the production PHP image once Node and dev tooling are gone.
- The first-owner experience, as above.

## Verification

- `docker compose config` resolves for the single-server file and for each profile of the multi-server file, with no cross-profile errors.
- CI builds all five images for both architectures, and the smoke tests pass for web, `queue`, `scheduler` and `reverb`.
- A fresh machine, using only the published compose file and `.env.example`, reaches a working dashboard with the documented steps. Run once for amd64 and once for arm64.
- Live updates on the Products page work with the default (`ws` on 8085) and behind a TLS proxy with the documented settings, and the page degrades to polling when Reverb is unreachable.
- Upgrade from one tagged version to the next, on the single-server file, keeps the data and migrates automatically.

## Calls made without the owner (change if wrong)

- The Reverb runtime-configuration change is application work, so it is its own first task in the plan and lands before the image work; the images build without it, but live updates do not work on a prebuilt image until it ships.
- CI runs the full test suite against a PostGIS container (the suite does not need Redis or Apprise, and stray HTTP already fails in tests), with `max_locks_per_transaction=256` as in `compose.yaml` for parallel runs. The container is started by a workflow step, since service containers cannot take that argument.
- The Docker Hub namespace and image names are one repository variable, so renaming later is a one-line change.
- If automatic migration fails on the single-server web container, the container exits and `restart: unless-stopped` retries it. It never becomes healthy, so the workers never start, and the failure shows in `docker compose ps` and the logs, which the docs point to.

- The multi-server set is one file with profiles, not four per-server files, to avoid drift.
- Redis is omitted from the single-server file.
- The Docker Hub namespace and image names are not chosen yet. Working names: `wxnotify/wxnotify` for the PHP image and `wxnotify/nwws`, `wxnotify/emwin-byteblaster`, `wxnotify/emwin-http`, `wxnotify/glm-decoder`.
- Tests and the smoke tests run in CI before any push, with a tag build failing closed.
- The outlook-maps setting is named `OUTLOOK_MAPS_DISK`.
