# Running WxNotify across four servers

`docker compose up -d` runs everything on one machine. This guide splits the same stack across four servers using the `server-a`, `server-b`, `server-c` and `server-d` Compose profiles. It is written for our own test and production environments and for anyone self-hosting WxNotify.

> **Status.** The compose layering in this guide was checked with `docker compose config` (each profile resolves on its own and starts exactly the services listed below). A full four-host deployment has not been run end to end yet, so treat the first rollout as a shakedown and see [Known limits](#known-limits).

## The four servers

| Server | Profile | Services | Role |
| --- | --- | --- | --- |
| **A** | `server-a` | `front-end`, `reverb` | The dashboard, the subscriber API and the live WebSocket server. The only server users reach. |
| **B** | `server-b` | `pgsql`, `redis` | Data. PostgreSQL holds the database, the queue, the cache and sessions. |
| **C** | `server-c` | `pipeline-api`, `nwws`, `emwin-byteblaster`, `emwin-http`, `glm-decoder`, `queue-long`, `docker-socket-proxy` | Ingestion. Receives the weather feeds and stores products. |
| **D** | `server-d` | `queue`, `queue-heartbeat`, `scheduler`, `apprise` | Processing and delivery. Matches products to alert rules and sends the alerts. |
| (optional) | `support` | `healthchecks` | Monitoring. Run it anywhere, or use a hosted Healthchecks account instead. |

`server-d` is defined by `compose.production.yaml`; plain `compose.yaml` keeps those four services in `server-c` for single-host development.

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
- **Docker Engine and Docker Compose 2.24 or newer** on each. `compose.production.yaml` uses the `!reset` and `!override` tags, which older Compose versions reject.
- **A way to terminate TLS for server A** (Caddy, nginx, a load balancer or a Cloudflare tunnel). Compose does not include one. See [TLS and the reverse proxy](#tls-and-the-reverse-proxy).
- **There is no production image yet.** `docker/8.4/Dockerfile` defines `web-production` and `worker-production` targets, but they are empty aliases and contain no application code. Every server therefore runs the repository checked out on disk and bind-mounted into the containers, as in development. Each application server (A, C, D) needs its own clone and its own `composer install`. B only needs the compose files.

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

Start every server from `.env.example`, then set the values below. Only what differs per server is listed. Anything not listed keeps the `.env.example` value or your normal production value (`APP_URL`, Stripe, and so on).

**On every server:**

```ini
COMPOSE_FILE=compose.yaml:compose.production.yaml
USER_ID=1000      # the uid/gid of the account that owns the checkout:
GROUP_ID=1000     #   `id -u` and `id -g`. These are baked in at build time.
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wxnotify.example.com
```

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

- **`REVERB_HOST`/`REVERB_PORT` are where the application's own servers send broadcasts**, not where browsers connect. On A that is the `reverb` container on the same host. On D it is A's private address and the published port 8085. C never broadcasts: the live-product event is queued and sent by the workers on D.
- **`VITE_REVERB_*` is where the browser connects** and is set on A only: the public hostname, port 443 and `https` (see below).
- **`APPRISE_API_URL` differs by server.** On D the workers reach Apprise on their own host. On A the dashboard reaches it across the network.
- The `*_BIND_ADDRESS` variables decide which interface each published port listens on. They default to `127.0.0.1`, which is safe but unreachable from other servers, so set them to the server's private address where the table says to. Never use `0.0.0.0` or a public address.
- Every other published port in `compose.production.yaml` (`pipeline-api`, `emwin-byteblaster`) is pinned to `127.0.0.1`, because nothing outside its own host needs it.

Browser-facing Reverb settings, on A:

```ini
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=wxnotify.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=wxnotify.example.com
```

`VITE_*` values are compiled into the JavaScript, so run `npm run build` after changing them (step 3 below).

## Set up, in this order

Bring the servers up B, A, D, then C. The database must exist before anything else, and the workers on D should be running before the feeds on C start delivering products.

### 0. Servers A, C and D: get the code

```bash
git clone <your-repository-url> wxnotify && cd wxnotify
cp .env.example .env     # then edit it as described above
```

Copy `auth.json` (it is git-ignored) if Composer needs credentials for a private package. Server B needs only `compose.yaml`, `compose.production.yaml`, `docker/pgsql/` and a `.env`, so a clone is the easiest way to get them.

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

```bash
docker compose build front-end
docker compose run --rm --no-deps front-end composer install --no-dev --optimize-autoloader
docker compose run --rm --no-deps front-end php artisan key:generate --show
```

`key:generate --show` only prints a key. Paste it into `APP_KEY` in `.env` on **A, C and D**. Generate it once and never change it. Then:

```bash
docker compose up -d
docker compose exec front-end php artisan migrate --force
```

`docker compose up -d` starts only `front-end` and `reverb` here, because `COMPOSE_PROFILES=server-a`.

### 3. Server A: build the front end

```bash
docker compose exec front-end npm ci
docker compose exec front-end npm run build
```

Only the web image has Node. Do this on A after every change to a `VITE_*` value or to the JavaScript and CSS.

### 4. Server D: processing and delivery

```bash
docker compose build
docker compose run --rm --no-deps queue composer install --no-dev --optimize-autoloader
docker compose up -d
docker compose ps
```

### 5. Server C: ingestion

```bash
docker compose build
docker compose run --rm --no-deps queue-long composer install --no-dev --optimize-autoloader
docker compose up -d
docker compose ps
```

If B were unreachable, the workers would fail and restart under `restart: unless-stopped`, which is why B goes first.

### 6. Create the first owner

Register an account through the dashboard's sign-up page, then promote it from A:

```bash
docker compose exec front-end php artisan app:grant-owner you@example.com
```

## TLS and the reverse proxy

Put a reverse proxy in front of A. It terminates TLS and forwards two things: the application, and Reverb's WebSocket paths (`/app` and `/apps`) with the Upgrade headers intact. Example Caddyfile (an example, not shipped with the repository):

```
wxnotify.example.com {
    @reverb path /app/* /apps/*
    reverse_proxy @reverb 10.0.0.1:8085
    reverse_proxy 127.0.0.1:80
}
```

If the proxy sits in front of the application, set `WXNOTIFY_TRUSTED_PROXIES` (and `WXNOTIFY_CLIENT_IP_HEADER` for Cloudflare) as described in `.env.example`. Without that, every request looks like it came from the proxy.

## The Docker socket proxy

Admin tooling on A starts, stops and restarts the feed containers by calling `DOCKER_PROXY_URL`, which points at C.

`tecnativa/docker-socket-proxy` restricts the Docker API to container start, stop, restart and inspection. **It does not authenticate callers and does not speak TLS.** On a plain port 2375, anyone who can reach it can restart any container on C, and the traffic is unencrypted. So:

- Bind it to C's private address only (`DOCKER_PROXY_BIND_ADDRESS`).
- Firewall it to A's address alone.
- Keep the private network itself trusted (or carry it over WireGuard).

The architecture plan called for TLS client-certificate authentication here; that has not been built. If the private network is not one you trust, front the proxy with something that provides it (an mTLS-terminating nginx or Caddy on C) and point `DOCKER_PROXY_URL` at the `https://` address.

Apprise on D is similarly unauthenticated and is protected the same way: a private bind address and a firewall rule for A.

## Checks after the first rollout

1. **A to B:** log in to the dashboard. A working login proves the database and sessions.
2. **D to B:** `docker compose ps` on D shows `queue` and `queue-heartbeat` as `healthy`. On C, `queue-long` should be `healthy` too.
3. **C to B to D:** after a few minutes, `docker compose logs --tail 50 nwws` on C shows products being posted, and new rows appear under Products in the dashboard. If products arrive but nothing is matched or delivered, the jobs are waiting for D: check the workers there.
4. **D to A (Reverb):** open Live Weather > Products in a browser, then send a test product. It should appear without a refresh. If it only appears after a reload, D cannot reach Reverb: check `REVERB_HOST`, `REVERB_PORT` and the firewall rule for 8085.
5. **A to C (Docker control):** open the pipeline control page in the dashboard. The NWWS and EMWIN feeds show their real state. If they show "not found", `DOCKER_PROXY_URL` or the firewall is wrong.
6. **A to D (Apprise):** send a confirmation code to a test channel from the dashboard. If it fails, check `APPRISE_API_URL` on A and the firewall rule for 8026.

## Deploying an update

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

Finally, from A:

```bash
docker compose exec front-end php artisan queue:restart
docker compose exec front-end php artisan reverb:restart
```

`queue:restart` and `reverb:restart` signal through the shared cache (the database, in the default configuration), so running them once on A restarts the workers on C and D as well. The workers keep running the code they booted with until that signal arrives, so never skip it. Docker's restart policy brings them back.

Run `migrate` before restarting the workers so they never run new code against an old schema. For a change that is not backward compatible, stop `queue` on D first.

## Operating notes

- **Backups.** Everything that matters is in PostgreSQL on B. Schedule `pg_dump` to somewhere off B (`docker compose exec -T pgsql pg_dump -U "$DB_USERNAME" "$DB_DATABASE" | gzip > ...`) and test a restore. The product files on C can be rebuilt from the feeds and are not backed up.
- **If A is down:** C and D keep ingesting, matching and delivering, because delivery needs only the database and the outbound providers. Live pushes to browsers and Docker control are unavailable, and broadcasts queue up on D until A is back.
- **If B is down:** nothing works. Workers fail and keep restarting, which is expected; they recover on their own when B returns.
- **If C is down:** the dashboard keeps working but no new products arrive, so no new alerts are produced. Alerts already queued still deliver.
- **If D is down:** products still arrive and are stored, but nothing is matched or delivered. The jobs wait in the database queue and are processed when D returns, so alerts can arrive late. D is the server to monitor most closely: point Healthchecks at `queue-heartbeat` (see the heartbeat settings in the dashboard), and watch C's feed health separately.
- **Logs:** `docker compose logs -f <service>` on the server that runs the service.

## Known limits

- **No production images.** Each server runs a checkout in development-style images (see [Before you start](#before-you-start)). Expect this guide to get simpler once the `*-production` Docker targets copy the application in.
- **Product storage gauges on A.** The product volumes (`nwws-products`, `emwin-products`) live on C. `compose.production.yaml` removes them from the `front-end` mount, so the disk gauges on the Product Storage page read an empty path and show as unavailable on A. The scan results come from the database and are unaffected. The disk gauge needs a different source if you want it back.
- **Outlook map images are not shared between servers.** The delivery job on D writes them to `storage/app/private/outlook-maps/` and the dashboard and mobile API on A read them. They are separate checkouts on separate hosts, so A cannot see what D wrote, and outlook maps in the mobile app and API return 404. Nothing else crosses servers on disk (shapefile imports download and clean up within one job; the dashboard wallpapers are repository files). Until this is fixed (see `docs/superpowers/specs/2026-11-11-production-images-design.md`), share `storage/app/private/outlook-maps/` between A and D, for example with an NFS mount.
- **Docker control and Apprise are unauthenticated.** See [the Docker socket proxy](#the-docker-socket-proxy).
- **One server per role.** Splitting by profile does not make any role redundant. Running more than one `reverb` needs `REVERB_SCALING_ENABLED=true` and Redis, and is outside this guide.
- **Single-server use is unchanged.** Without `COMPOSE_FILE` pointing at `compose.production.yaml`, `docker compose up -d` behaves as it always has, including the Mailpit catcher, the Vite port and the original `server-c` grouping.
