# Running WxNotify across four servers

> **Published images, no checkout.** This guide runs WxNotify from the published Docker images with one Compose file, [`deploy/multi-server/compose.yaml`](../deploy/multi-server/compose.yaml), and one `.env` per server. You do not clone the repository, and there is no Composer, npm or build step: Compose pulls the images, exactly as in [Quick start: one server](../deploy/README.md#quick-start-one-server). Running from a git checkout instead (development-style images, with the repository bind-mounted) is described in the [appendix](#appendix-running-from-a-git-checkout), for people working on WxNotify itself.

`docker compose up -d` runs everything on one machine. This guide splits the same stack across four servers using the `server-a`, `server-b`, `server-c` and `server-d` Compose profiles. It is written for our own test and production environments and for anyone self-hosting WxNotify.

> **Status.** Each profile of the multi-server Compose file resolves on its own and starts exactly the services listed below (`deploy/verify.sh` checks this). A full four-host deployment has not been run end to end yet, so treat the first rollout as a shakedown and see [Known limits](#known-limits).

## The four servers

| Server | Profile | Services | Role |
| --- | --- | --- | --- |
| **A** | `server-a` | `front-end`, `reverb` | The dashboard, the subscriber API and the live WebSocket server. The only server users reach. |
| **B** | `server-b` | `pgsql`, `redis` | Data. PostgreSQL holds the database, the queue, the cache and sessions. |
| **C** | `server-c` | `pipeline-api`, `nwws`, `emwin-byteblaster`, `emwin-http`, `glm-decoder`, `queue-long`, `docker-socket-proxy` | Ingestion. Receives the weather feeds and stores products. |
| **D** | `server-d` | `queue`, `queue-heartbeat`, `scheduler`, `apprise` | Processing and delivery. Matches products to alert rules and sends the alerts. |

The profile for each server is in [`deploy/multi-server/compose.yaml`](../deploy/multi-server/compose.yaml); `COMPOSE_PROFILES` in a server's `.env` selects it.

Two services sit on C rather than D, because on D they would not work:

- **`docker-socket-proxy`** is how the dashboard starts and stops the feed containers (`wxnotify-nwws`, `wxnotify-emwin-byteblaster`, `wxnotify-emwin-http`). It controls the Docker daemon of the host it runs on, so it has to be on C, where those containers are. On D it would report every feed as "not found".
- **`queue-long`** runs the hourly Product Storage scan, which walks the NWWS and EMWIN product files. Those files are Docker volumes on C. On D the scan would see empty volumes and record a failed scan. (To put it on D anyway you would share C's volumes over NFS. That is possible but not covered here.)

Redis is not used by the default configuration (sessions, cache and queue are all `database`). Run it only if you switch one of those to `redis` or turn on `REVERB_SCALING_ENABLED`.

### Who talks to whom

```
         browsers ──https──▶ reverse proxy ──▶ A: front-end (APP_PORT)
                                         └──▶ A: reverb (/app, /apps)

A ── 5432 ──▶ B: pgsql        C ── 5432 ──▶ B: pgsql        D ── 5432 ──▶ B: pgsql
D ── 8085 ──▶ A: reverb       (the broadcasts queue pushes live events)
A ── 8026 ──▶ D: apprise      (channel confirmation codes sent from the dashboard)
A ── 2375 ──▶ C: docker-socket-proxy   (admin tools start/stop feed containers)
C ──▶ NWWS, EMWIN and GOES feeds (internet)
D ──▶ every outbound channel provider: email, SMS, push, webhooks (internet)
```

Ingestion hands work to D through the database: `pipeline-api` stores the product and queues a job, and the workers on D pick it up. C therefore needs the database and nothing else, and no inbound traffic from the internet at all.

## Before you start

- **Four Linux hosts on a private network** (Hetzner Cloud private network, WireGuard, or a VLAN). Everything between servers below travels over it.
- **Docker Engine and Docker Compose 2.24 or newer** on each, as on one server.
- **A way to terminate TLS for server A** (Caddy, nginx, a load balancer or a Cloudflare tunnel). Compose does not include one. See [TLS and the reverse proxy](#tls-and-the-reverse-proxy).
- **Nothing else.** No git checkout, Composer or npm. Each server needs only the Compose file and its own `.env`; Compose pulls the images from Docker Hub.

### Addresses used below

Substitute your own private addresses:

| Server | Private address |
| --- | --- |
| A | `10.0.0.1` |
| B | `10.0.0.2` |
| C | `10.0.0.3` |
| D | `10.0.0.4` |

## Sizing: 2 vCPU / 2 GB (Hetzner CPX11)

These are memory figures read from the running development stack with `docker stats`, while it was idle. They are a floor, not a forecast: PHP-FPM, PostgreSQL's cache and the Python feed clients all grow under load.

| Server | Containers (idle) | Idle total |
| --- | --- | --- |
| A | `front-end` 25 MB, `reverb` 78 MB | about 100 MB |
| B | `pgsql` 285 MB, `redis` 10 MB | about 300 MB |
| C | `pipeline-api` 345, `emwin-byteblaster` 300, `emwin-http` 170, `nwws` 128, `queue-long` 77, `glm-decoder` 61, `docker-socket-proxy` 22 | about 1.1 GB |
| D | `apprise` 405, `queue` 90, `queue-heartbeat` 82, `scheduler` 78 | about 650 MB |

What this means for 2 GB hosts:

- **C is the tight one.** It starts about half full before any burst of products. Add swap and watch it. If it runs short, the levers are: lower PHP-FPM's child count in `pipeline-api`, or move `queue-long` to D (see above for the NFS requirement).
- **D's biggest process is Apprise** (405 MB). Its workers are small, but a large alert fan-out adds PHP memory per job (`queue` restarts itself at 192 MB).
- **A and B have the most headroom.** B's PostgreSQL will use whatever is left as file cache, which is what you want. Do not set `shared_buffers` above roughly 25% of RAM (about 512 MB).
- **Add 2 GB of swap on every server.** A 2 GB host with no swap goes straight to the OOM killer. Swap on NVMe is a reasonable safety net, not a plan.
- **Disk.** The plan document puts a 2 vCPU / 2 GB host at 40 GB. On C, product files fill it: `NWWS_RETENTION_DAYS` and `EMWIN_RETENTION_DAYS` (7 by default) cap that, so check the Product Storage page after the first week.

## Firewall

Default-deny inbound everywhere, then allow only what is listed. The private network is not a substitute for these rules.

| Server | Port | Allow from | Why |
| --- | --- | --- | --- |
| A | 80, 443 | the internet | Reverse proxy. |
| A | 8085 | D's address, and the reverse proxy | Reverb. Never the internet directly. |
| B | 5432 | A, C and D | PostgreSQL. |
| B | 6379 | A, C and D | Redis, only if you use it. |
| C | 2375 | A's address only | Docker control. See [the Docker socket proxy](#the-docker-socket-proxy). |
| D | 8026 | A's address only | Apprise, called by the dashboard. |
| all | 22 | your admin address | SSH. |

## What must be identical across servers

These are shared secrets. If they differ the application breaks in ways that are hard to trace.

| Variable | Servers | Why |
| --- | --- | --- |
| `APP_KEY` | A, C, D | Channel credentials are encrypted at rest. A saves them and D's workers decrypt them to deliver. A mismatch makes every encrypted value unreadable. Generate it once and copy it. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | A, C, D | Same database. |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | A, D | D signs broadcast requests, A's Reverb verifies them. |
| `PIPELINE_WEBHOOK_TOKEN` | A, C | Authenticates the feed clients to `pipeline-api` on C. |

Mail settings (`MAIL_*`) belong on A and D, which both send email. NWWS credentials belong on C only.

## Per-server `.env`

Every server starts from the same file, [`deploy/multi-server/.env.example`](../deploy/multi-server/.env.example), whose comments say which servers each setting is for. Set the values below. Only what differs per server is listed. Anything not listed keeps the `.env.example` value or your normal production value (mail, Turnstile and so on).

**On every server:**

```ini
WXNOTIFY_VERSION=1.2.3     # the exact release, the same on every server
APP_URL=https://wxnotify.example.com
```

`APP_ENV=production` and `APP_DEBUG=false` are already set in the example.

| Setting | A | B | C | D |
| --- | --- | --- | --- | --- |
| `COMPOSE_PROFILES` | `server-a` | `server-b` | `server-c` | `server-d` |
| `DB_HOST` | `10.0.0.2` | n/a | `10.0.0.2` | `10.0.0.2` |
| `REDIS_HOST` | `10.0.0.2`, only if used | n/a | `10.0.0.2`, only if used | `10.0.0.2`, only if used |
| `REVERB_HOST` | `reverb` | n/a | not used | `10.0.0.1` |
| `REVERB_PORT` | `8080` | n/a | not used | `8085` |
| `REVERB_SCHEME` | `http` | n/a | not used | `http` |
| `REVERB_BIND_ADDRESS` | `10.0.0.1` | n/a | n/a | n/a |
| `DB_BIND_ADDRESS` | n/a | `10.0.0.2` | n/a | n/a |
| `REDIS_BIND_ADDRESS` | n/a | `10.0.0.2` | n/a | n/a |
| `DOCKER_PROXY_BIND_ADDRESS` | n/a | n/a | `10.0.0.3` | n/a |
| `DOCKER_PROXY_URL` | `http://10.0.0.3:2375` | n/a | n/a | n/a |
| `APPRISE_BIND_ADDRESS` | n/a | n/a | n/a | `10.0.0.4` |
| `APPRISE_API_URL` | `http://10.0.0.4:8026` | n/a | not used | `http://apprise:8000` (the default) |
| `NWWS_USERNAME`, `NWWS_PASSWORD` | n/a | n/a | your NWWS account | n/a |

Notes on the table:

- **`REVERB_HOST`/`REVERB_PORT` are where the application's own servers send broadcasts**, not where browsers connect. On A that is the `reverb` container on the same host. On D it is A's private address and the published port 8085 (`REVERB_PUBLISHED_PORT`). C never broadcasts: the live-product event is queued and sent by the workers on D.
- **`REVERB_PUBLIC_*` is where the browser connects** and is set on A only: the public hostname, port 443 and `https` (see below).
- **`APPRISE_API_URL` differs by server.** On D the workers reach Apprise on their own host. On A the dashboard reaches it across the network.
- The `*_BIND_ADDRESS` variables decide which interface each published port listens on. They default to `127.0.0.1`, which is safe but unreachable from other servers, so set them to the server's private address where the table says to. Never use `0.0.0.0` or a public address.
- Every other published port in the Compose file (`pipeline-api`, `emwin-byteblaster`) is pinned to `127.0.0.1`, because nothing outside its own host needs it.

Browser-facing Reverb settings, on A:

```ini
REVERB_PUBLIC_HOST=wxnotify.example.com
REVERB_PUBLIC_PORT=443
REVERB_PUBLIC_SCHEME=https
REVERB_ALLOWED_ORIGINS=wxnotify.example.com
```

The application reads these when it starts, so after changing one run `docker compose up -d` on A to recreate the containers. There is nothing to build.

Also set, before anyone signs up: the `MAIL_*` values on A and D (sign-up sends a verification email), the Turnstile keys on A if you want the sign-up captcha, and on A and D the `OUTLOOK_MAPS_DISK` and `AWS_*` values for the shared bucket (see [Known limits](#known-limits)). [deploy/README.md](../deploy/README.md#quick-start-one-server) explains the mail and Turnstile choices.

## Set up, in this order

Bring the servers up B, A, D, then C. The database must exist before anything else, and the workers on D should be running before the feeds on C start delivering products.

### 0. Every server: the Compose file and a `.env`

```bash
mkdir wxnotify && cd wxnotify
curl -fsSLO https://github.com/wxnotify/wxnotify/raw/main/deploy/multi-server/compose.yaml
curl -fsSLO https://github.com/wxnotify/wxnotify/raw/main/deploy/multi-server/.env.example
cp .env.example .env     # then edit it as described above
```

Set `COMPOSE_PROFILES` for that server and `WXNOTIFY_VERSION` to an exact release (the same everywhere; see [Version tags](../deploy/README.md#version-tags)). Generate each secret **once**, on any machine, with the commands in the comments of `.env.example`, and copy it to every server in the table above: `APP_KEY` (A, C, D), `DB_PASSWORD` (A, B, C, D), `REVERB_APP_KEY` and `REVERB_APP_SECRET` (A, D), `PIPELINE_WEBHOOK_TOKEN` (A, C). Never change `APP_KEY` afterwards: channel credentials are encrypted with it. Server B needs only the Compose file and a `.env`.

### 1. Server B: the database

```bash
docker compose up -d pgsql          # add `redis` only if you use it
docker compose ps
```

Check the database is reachable from A, C and D before moving on:

```bash
# from A, C and D
nc -vz 10.0.0.2 5432
```

### 2. Server A: the dashboard

No startup automation runs the migrations here, so run them yourself, and before `reverb` starts: a Reverb started against an empty database cannot read its restart signal and then ignores every later `reverb:restart`.

```bash
docker compose up -d front-end
docker compose ps                  # wait until front-end is healthy
docker compose exec front-end php artisan migrate --force
docker compose up -d
```

The final `docker compose up -d` starts only `reverb` in addition, because `COMPOSE_PROFILES=server-a`.

### 3. Server D: processing and delivery

```bash
docker compose up -d
docker compose ps
```

### 4. Server C: ingestion

```bash
docker compose up -d
docker compose ps
```

If B were unreachable, the workers would fail and restart under `restart: unless-stopped`, which is why B goes first.

### 5. Create the first owner and run Setup

Register an account through the dashboard's sign-up page, then promote it from A:

```bash
docker compose exec front-end php artisan app:grant-owner you@example.com
```

Then do what a fresh install needs, exactly as on one server: [Create the first owner](../deploy/README.md#create-the-first-owner) covers the admin **Setup** page (install the reference data) and importing the boundaries. In this layout the boundary scan runs on the long-queue worker, which is on C, so C must be up before you press **Check for shapefile updates**; or run `docker compose exec front-end php artisan shapefile:scan -v` on A, which imports in that process and needs no worker.

## TLS and the reverse proxy

Put a reverse proxy in front of A. It terminates TLS and forwards two things: the application, and Reverb's WebSocket paths (`/app` and `/apps`) with the Upgrade headers intact. Example Caddyfile (an example, not shipped with the repository):

```
wxnotify.example.com {
    @reverb path /app/* /apps/*
    reverse_proxy @reverb 10.0.0.1:8085
    reverse_proxy 127.0.0.1:8080
}
```

Behind the proxy, set `APP_URL` to the public `https://` address (the application then generates every URL as https; with an `http://` value the browser blocks the dashboard's assets as mixed content), `SESSION_SECURE_COOKIE=true`, and `WXNOTIFY_TRUSTED_PROXIES` (and `WXNOTIFY_CLIENT_IP_HEADER` for Cloudflare). Trusting the proxy is required, not optional: without it every request looks like it came from the proxy (one shared sign-in rate limit, wrong addresses in logs and activity alerts), and signed links such as email verification are checked against `http://` and refused. `*` is safe only while the application port is reachable from nothing but the proxy (bound to `127.0.0.1` with the proxy on A); otherwise list the proxy's address. [deploy/README.md](../deploy/README.md#putting-it-behind-https) explains each setting.

## The Docker socket proxy

Admin tooling on A starts, stops and restarts the feed containers by calling `DOCKER_PROXY_URL`, which points at C.

`tecnativa/docker-socket-proxy` (v0.5.0) filters the Docker API by path, and the settings the dashboard needs open far more than start, stop and restart. With `CONTAINERS=1` and `POST=1`, its rule for `/containers` is matched before any narrower rule, so every container call is allowed, including `POST /containers/create` and then `start`. A caller can therefore create a privileged container with the host's filesystem mounted and start it: **anyone who can reach port 2375 on C is root on C.** (`EXEC=0` still blocks `/exec`, and the non-container APIs such as images, networks and volumes stay blocked; `POST=0` is not an option, because the POST deny rule comes before the allow rules and would also block start and stop.) **It does not authenticate callers and does not speak TLS**, and the traffic is unencrypted. So:

- Bind it to C's private address only (`DOCKER_PROXY_BIND_ADDRESS`).
- Firewall it to A's address alone.
- Keep the private network itself trusted (or carry it over WireGuard).

The architecture plan called for TLS client-certificate authentication here; that has not been built. If the private network is not one you trust, front the proxy with something that provides it (an mTLS-terminating nginx or Caddy on C) and point `DOCKER_PROXY_URL` at the `https://` address. A narrower proxy that allows only inspecting and starting, stopping and restarting the three feed containers would remove the root exposure; that hardening is not built yet.

On one server the same proxy sits on an internal network that only `front-end` joins and is not published, so the exposure there is a compromise of the application itself.

Apprise on D is similarly unauthenticated and is protected the same way: a private bind address and a firewall rule for A.

## Checks after the first rollout

1. **A to B:** log in to the dashboard. A working login proves the database and sessions.
2. **D to B:** `docker compose ps` on D shows `queue` and `queue-heartbeat` as `healthy`. On C, `queue-long` should be `healthy` too.
3. **C to B to D:** after a few minutes, `docker compose logs --tail 50 nwws` on C shows products being posted, and new rows appear under Products in the dashboard. If products arrive but nothing is matched or delivered, the jobs are waiting for D: check the workers there.
4. **D to A (Reverb):** open Live Weather > Products in a browser, then send a test product. It should appear without a refresh. If it only appears after a reload, D cannot reach Reverb: check `REVERB_HOST`, `REVERB_PORT` and the firewall rule for 8085.
5. **A to C (Docker control):** open the pipeline control page in the dashboard. The NWWS and EMWIN feeds show their real state. If they show "not found", `DOCKER_PROXY_URL` or the firewall is wrong.
6. **A to D (Apprise):** send a confirmation code to a test channel from the dashboard. If it fails, check `APPRISE_API_URL` on A and the firewall rule for 8026.

## Deploying an update

An update is a maintenance window, as on one server. Back up PostgreSQL on B (see [Operating notes](#operating-notes)) and the `app-storage` volume on A first. Migrations only go forward, so you cannot step back to an older version without restoring a backup.

Set the new `WXNOTIFY_VERSION` in `.env` on **every** server, then:

On A, in the same order as a first start, so `reverb` boots after the migration:

```bash
docker compose pull
docker compose up -d front-end
docker compose exec front-end php artisan view:clear
docker compose exec front-end php artisan migrate --force
docker compose up -d
```

Then on D and on C:

```bash
docker compose pull
docker compose up -d
```

Finally, from A:

```bash
docker compose exec front-end php artisan queue:restart
docker compose exec front-end php artisan reverb:restart
```

Run `view:clear` after every version change, upgrade or rollback: A keeps `storage/` (including the compiled Blade views) in the `app-storage` volume and has no startup automation, so without it A can keep serving views compiled by the other version. Migrate before the workers on C and D restart. `queue:restart` and `reverb:restart` signal through the shared cache (the database, in the default configuration), so running them once on A reaches the workers on C and D as well. Docker's restart policy brings them back.

If the migration fails, set the previous `WXNOTIFY_VERSION` on A, run `docker compose up -d` there and then `docker compose exec front-end php artisan view:clear`, and do not update C and D. For a change that is not backward compatible, stop `queue` on D first. This order follows the first-start order that we ran; a full multi-server update has not been run end to end yet.

## Operating notes

- **Backups.** Almost everything that matters is in PostgreSQL on B. The exception is uploaded files (service-desk attachments, bug-report screenshots, onboarding files) in the `app-storage` volume on A ([deploy/README.md](../deploy/README.md#backups-and-day-to-day-operation) has the archive and restore commands); back it up as well. Schedule `pg_dump` to somewhere off B (`docker compose exec -T pgsql sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > ...`) and test a restore. The product files on C can be rebuilt from the feeds and are not backed up. Keep each server's `.env` somewhere safe: without `APP_KEY` the encrypted channel credentials cannot be read.
- **If A is down:** C and D keep ingesting, matching and delivering, because delivery needs only the database and the outbound providers. Live pushes to browsers and Docker control are unavailable, and broadcasts queue up on D until A is back.
- **If B is down:** nothing works. Workers fail and keep restarting, which is expected; they recover on their own when B returns.
- **If C is down:** the dashboard keeps working but no new products arrive, so no new alerts are produced. Alerts already queued still deliver.
- **If D is down:** products still arrive and are stored, but nothing is matched or delivered. The jobs wait in the database queue and are processed when D returns, so alerts can arrive late. D is the server to monitor most closely: point Healthchecks at `queue-heartbeat` (see the heartbeat settings in the dashboard), and watch C's feed health separately.
- **Logs:** `docker compose logs -f <service>` on the server that runs the service.

## Known limits

- **Product storage gauges on A.** The product volumes (`nwws-products`, `emwin-products`) live on C and are not mounted on A, so the disk gauges on the Product Storage page read an empty path and show as unavailable on A. The scan results come from the database and are unaffected. The disk gauge needs a different source if you want it back.
- **Outlook map images need shared storage.** The delivery job on D writes them and the dashboard and mobile API on A read them, and they are separate hosts, so the default `local` disk would leave A with outlook maps returning 404. On both A and D set `OUTLOOK_MAPS_DISK=s3` and the `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT` and `AWS_USE_PATH_STYLE_ENDPOINT` values for the same S3-compatible bucket (see `.env.example` for a Backblaze B2 example). If the disk is unreachable the app treats the map as missing rather than failing. Nothing else crosses servers on disk (shapefile imports download and clean up within one job; the dashboard wallpapers are repository files).
- **Docker control and Apprise are unauthenticated, and Docker control is root on C.** Anyone who can reach C's port 2375 can create and start a privileged container there. See [the Docker socket proxy](#the-docker-socket-proxy).
- **One server per role.** Splitting by profile does not make any role redundant. Running more than one `reverb` needs `REVERB_SCALING_ENABLED=true` and Redis, and is outside this guide.

## Appendix: running from a git checkout

This is the route for people working on WxNotify, and the one our own test servers started on. `compose.yaml` builds the development-style `web-development` and `worker-development` targets of `docker/8.4/Dockerfile`, and the repository on disk is bind-mounted into the containers, so each application server (A, C, D) needs its own clone and its own `composer install` and `npm run build`; B only needs the compose files. For production use the published images above instead. Everything in this guide except the steps below (the network layout, firewall, sizing, shared secrets, TLS, the Docker socket proxy, the checks and the operating notes) applies to both routes.

What differs in the checkout route:

- **Compose layering.** Every server sets `COMPOSE_FILE=compose.yaml:compose.production.yaml`, which uses the `!reset` and `!override` tags, so it needs Docker Compose 2.24 or newer. `server-d` is defined by `compose.production.yaml`; plain `compose.yaml` keeps those four services in `server-c` for single-host development, and without `COMPOSE_FILE` pointing at `compose.production.yaml`, `docker compose up -d` behaves as it always has, including the Mailpit catcher, the Vite port and that original `server-c` grouping.
- **Monitoring.** `compose.yaml` also has a `support` profile with a `healthchecks` service. Run it anywhere, or use a hosted Healthchecks account instead.
- **Build user.** Set `USER_ID` and `GROUP_ID` to the uid and gid of the account that owns the checkout (`id -u`, `id -g`); they are baked in at build time.
- **Browser WebSocket settings** are compiled into the JavaScript as `VITE_REVERB_*` on A (`VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https`) plus `REVERB_ALLOWED_ORIGINS`, so run `npm run build` after changing them.
- **Reverse proxy.** The application listens on port 80 on A, so the Caddyfile's last line is `reverse_proxy 127.0.0.1:80`.
- **Product storage gauges.** `compose.production.yaml` removes the product volumes from the `front-end` mount.
- **Per-server `.env`** otherwise follows the table above, with `COMPOSE_FILE`, `USER_ID` and `GROUP_ID` added on every server.

### Setting up from a checkout

Bring the servers up in the same order: B, A, D, then C.

On A, C and D, get the code (B needs only `compose.yaml`, `compose.production.yaml`, `docker/pgsql/` and a `.env`, so a clone is the easiest way to get them):

```bash
git clone <your-repository-url> wxnotify && cd wxnotify
cp .env.example .env     # then edit it
```

Copy `auth.json` (it is git-ignored) if Composer needs credentials for a private package. Then start B as in step 1 above, and:

On A:

```bash
docker compose build front-end
docker compose run --rm --no-deps front-end composer install --no-dev --optimize-autoloader
docker compose run --rm --no-deps front-end php artisan key:generate --show
```

`key:generate --show` only prints a key. Paste it into `APP_KEY` in `.env` on **A, C and D**. Generate it once and never change it. Then:

```bash
docker compose up -d front-end
docker compose exec front-end php artisan migrate --force
docker compose up -d
docker compose exec front-end npm ci
docker compose exec front-end npm run build
```

Only the web image has Node. Run the two `npm` commands on A after every change to a `VITE_*` value or to the JavaScript and CSS.

On D, then on C (use `queue-long` instead of `queue` on C):

```bash
docker compose build
docker compose run --rm --no-deps queue composer install --no-dev --optimize-autoloader
docker compose up -d
docker compose ps
```

Create the first owner as in step 5 above.

### Updating from a checkout

Run `git pull` on A, C and D (and on B if the compose files changed). Then, in this order:

On A:

```bash
docker compose run --rm --no-deps front-end composer install --no-dev --optimize-autoloader
docker compose exec front-end npm ci && docker compose exec front-end npm run build
docker compose exec front-end php artisan migrate --force
```

On D and on C:

```bash
docker compose run --rm --no-deps queue composer install --no-dev --optimize-autoloader   # on C use queue-long
docker compose up -d --build
```

Finally, from A, run `queue:restart` and `reverb:restart` as in [Deploying an update](#deploying-an-update). Run `migrate` before restarting the workers so they never run new code against an old schema.
