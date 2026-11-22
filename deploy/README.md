# WxNotify: run it from Docker images

WxNotify is a self-hosted weather alert service. It receives National Weather Service text products (NWWS-OI), EMWIN and GOES lightning (GLM) data, matches them against the alert rules people set up, and delivers alerts through channels such as email, SMS, push, chat services and webhooks. It has a dashboard for subscribers and owners, a live products page, and an API for the mobile app.

You do not need the source code. This page takes you from nothing to a running server using the published images and one Compose file.

## The images

| Image | What it is |
| --- | --- |
| `wxnotify/wxnotify` | The application: dashboard, API, queue workers, scheduler and Reverb (WebSockets). One image, started with different commands. |
| `wxnotify/nwws` | NWWS-OI client (needs your own NWWS-OI account). |
| `wxnotify/emwin-byteblaster` | EMWIN ByteBlaster client. |
| `wxnotify/emwin-http` | EMWIN over HTTP. |
| `wxnotify/glm-decoder` | GOES lightning data decoder. |

All of them are published for `linux/amd64` and `linux/arm64`, and every image in a release carries the same version tag. The Compose files also use PostgreSQL (with PostGIS), Apprise and a Docker socket proxy from their own upstream images.

The Compose files read the registry from `WXNOTIFY_REGISTRY`, which defaults to `wxnotify`. Set it in `.env` if you pull through a mirror.

## Choose a route

- **One server** (this page, below): everything on one machine. The right choice for almost everyone.
- **Four servers**: the same stack split by role across four machines. See [Running on four servers](#running-on-four-servers).

You need Docker Engine with Docker Compose **2.24 or newer**.

## What hardware you need

Both `linux/amd64` and `linux/arm64` machines work. The figures below are starting points, not guarantees: they come from the memory the stack used while idle on a development install (every container started, no alert traffic), plus headroom. Feeds, alert fan-out and PostgreSQL's cache all grow under load, so watch your own server for the first week (`docker stats`, and the Product Storage page in the admin panel) and adjust.

| | CPU | Memory | Disk (SSD) |
| --- | --- | --- | --- |
| **One server, minimum** | 2 vCPU | 4 GB | 40 GB |
| **One server, comfortable** | 4 vCPU | 8 GB | 80 GB |

- **Memory is the limit that matters.** The whole stack idles at roughly 2 to 2.5 GB (PostgreSQL, Apprise, the feed clients, the queue workers and the web processes), and the operating system needs several hundred megabytes more. 4 GB runs it with some room; 8 GB leaves space for PostgreSQL to cache and for bursts of products or a large alert fan-out.
- **CPU is mostly idle** and spikes when a batch of products arrives or many alerts are sent at once. Two cores are enough for a small install; add cores before adding memory if deliveries lag during storms.
- **Add swap** (2 GB is a sensible size) so a memory spike does not go straight to the out-of-memory killer. Swap is a safety net, not a substitute for enough RAM.
- **Disk grows in two places.** Received weather products are kept for `NWWS_RETENTION_DAYS` and `EMWIN_RETENTION_DAYS` (7 days by default, which caps them), and the PostgreSQL database grows with the delivery history. Use SSD or NVMe storage. After the first week, check the Product Storage page and your volume sizes, then size the disk with room to grow.
- **Four servers:** each machine of two vCPU and 2 GB can host its role, with swap on every one. The ingestion server (C) is the tightest, since it runs the feed clients and starts about half full. The per-server figures are in [Running on four servers](#running-on-four-servers) and in the [multi-server guide's sizing table](../docs/multi-server-deployment.md#sizing-2-vcpu--2-gb-hetzner-cpx11).

## Quick start: one server

1. Make a folder and download the two files into it. The files live in [`deploy/single-server`](single-server) in the repository:

   ```bash
   mkdir wxnotify && cd wxnotify
   curl -fsSLO https://github.com/wxnotify/wxnotify/raw/main/deploy/single-server/compose.yaml
   curl -fsSLO https://github.com/wxnotify/wxnotify/raw/main/deploy/single-server/.env.example
   cp .env.example .env
   ```

2. Open `.env` and set `WXNOTIFY_VERSION` to an exact release. It is left blank on purpose and Compose will not start without it. Find the current release on the repository's releases page and write it as `WXNOTIFY_VERSION=<version>` (for example `1.2.3`, once that exists). Pinning means upgrades happen when you change it; `latest` and the shorter `MAJOR.MINOR` and `MAJOR` tags exist for people who choose to follow along (see [Version tags](#version-tags)).

3. Fill in every blank secret. Each one has a generating command in a comment above it:

   ```bash
   # DB_PASSWORD
   openssl rand -base64 24 | tr -d '/+='
   # REVERB_APP_KEY
   openssl rand -hex 16
   # REVERB_APP_SECRET and PIPELINE_WEBHOOK_TOKEN (two different values)
   openssl rand -hex 32
   # APP_KEY (the image prints a banner first, so keep only the last line)
   docker run --rm wxnotify/wxnotify:<version> php artisan key:generate --show | tail -n 1
   ```

   Decide about mail before real subscribers sign up, and about the sign-up captcha:

   - **Mail.** Signing up sends a verification email, and a new subscriber cannot use the dashboard until they follow its link. The example starts with `MAIL_MAILER=mailpit`: the stack includes a [Mailpit](https://mailpit.axllent.org) container that catches every message instead of delivering it, so you can sign up and follow verification links before any real mail service exists. Read the caught messages at <http://localhost:8025>. That page has no password and shows every email in full (password-reset links too), so it listens on the server's loopback address only; from another machine, tunnel to it with `ssh -L 8025:localhost:8025 you@your-server`. Messages are kept in memory and disappear when the container is recreated. Mailpit delivers nothing to real inboxes, so switch to real mail before anyone else signs up: set `MAIL_MAILER=smtp` and the `MAIL_*` values for your SMTP provider (uncomment the host, port and login lines in the example), then run `docker compose up -d` again. For example:

     ```ini
     MAIL_MAILER=smtp
     MAIL_HOST=smtp.example.com
     MAIL_PORT=587
     MAIL_USERNAME=your-smtp-user
     MAIL_PASSWORD=your-smtp-password
     MAIL_FROM_ADDRESS="alerts@example.com"
     ```

   - **Cloudflare Turnstile (optional, recommended for a public site).** If both `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` are blank, sign-up works without a captcha, because the example sets `WXNOTIFY_MODE=selfhost` (in `cloud` mode the captcha is always required). To protect a site anyone can reach from automated sign-ups, create a free Cloudflare account, open Turnstile, add a site for your hostname, and put its keys in those two variables. Set both or neither: with only one of them set the captcha still applies and sign-up fails until the other is filled in, so a half-configured install is noticed instead of silently running unprotected.

   Generate `APP_KEY` once and never change it: channel credentials are encrypted with it. Also set `APP_URL` to the address people will use. `NWWS_USERNAME` and `NWWS_PASSWORD` are optional; leave them empty to run without the NWWS feed (the `nwws` container then stays up, logs a notice and does nothing). Leave `WXNOTIFY_MODE=selfhost` as the example sets it (see [Self-hosted mode and tiers](#self-hosted-mode-and-tiers)).

4. Start it:

   ```bash
   docker compose up -d
   ```

   Compose refuses to start if a required value is missing. A blank secret stops it with a line like `set APP_KEY in .env`, and a missing version stops it with `set WXNOTIFY_VERSION in .env`. Fill in the named variable and run the command again.

   The `front-end` service runs the database migrations when it starts, and every other WxNotify service waits for it to become healthy, so a first start never runs workers against an empty database. The first start takes a minute or two. Watch it with `docker compose ps`; if anything stays unhealthy, read `docker compose logs front-end`.

5. Check that the sign-in page loads at `APP_URL` plus `/dashboard/login` (by default <http://localhost:8080/dashboard/login>). Then create the first owner, below; the owner works in the admin panel at `/admin`.

### Create the first owner

1. Register an account on the dashboard sign-up page, `/dashboard/register` (with the default Mailpit mailer, the verification email is in Mailpit at <http://localhost:8025>; the Turnstile captcha appears only if you set its keys).
2. Promote it to owner:

   ```bash
   docker compose exec front-end php artisan app:grant-owner you@example.com
   ```

3. Sign in to the admin panel at `/admin`. Owners are also staff, and the admin panel does not require email verification (only the dashboard does), so you can go straight there even before mail works. There is no command that creates a user: registration is the only way to make the first one.

   Staff and owners **cannot use the subscriber dashboard**: every `/dashboard` page redirects them to `/admin`, by design. To see what subscribers see, use a second account that is not staff. The simplest way is **Admin > Users > Create**: an account made there is already verified (no Turnstile check and no verification email), and you either set its password there or have a reset link emailed. Signing up a second account at `/dashboard/register` works too.

   In the admin panel, open **Setup** (in the **System** group). A fresh install is not complete until you do this. The page lists the reference data sets with their counts and whether each is installed: the product catalog (categories and descriptions), the "Did you know" tips and the tracked shapefile layers. Press **Install reference data** and confirm. It only adds what is missing and changes nothing that already exists. Read the confirmation text: when the product catalog is not yet installed, the install also removes a few legacy products that have no counterpart in the catalog.

   Then import the boundaries. WxNotify works out which places a warning covers from NWS boundary shapefiles (counties, zones and more), and no alert can match a place until they are imported and active. Open **Shapefile Layers** (admin panel) and press **Check for shapefile updates**: the scan runs on the long-queue worker (`queue-long`), so that container must be up, and the first scan is slow. Then press **Activate all imported layers** (or open a layer, review it and press **Activate**). The all-layers button replaces each layer's current version, so on a server that already runs, review first. A scan also runs by itself every day, but it only stages new versions: nothing goes live until you activate it. To run the scan from a shell instead, use `docker compose exec front-end php artisan shapefile:scan -v`; it imports in that process and needs no queue worker. The Setup checklist's "Import the boundaries" step ticks once every layer has an active version.

   The **Setup checklist** page (same group) lists what else a server needs. Its "Set up outgoing email" step stays open while the mailer is `mailpit`, and ticks once you switch to a real one.

Do not run `db:seed`; the seeder creates a test user (`test@example.com`) and is for development only.

## Putting it behind HTTPS

TLS is your job: the stack does not include a certificate or a proxy. Without it the stack still works over plain HTTP, and the browser connects to the live WebSocket server on the page's own host at port 8085.

For real use, run a reverse proxy on the same host (the examples below) that does two things:

1. Proxies the application to the `front-end` port (`APP_PORT`, default 8080) at `127.0.0.1`.
2. Forwards the WebSocket paths `/app` and `/apps` to Reverb on its published port (`REVERB_PUBLISHED_PORT`, default 8085) at `127.0.0.1`.

Then set **all** of these in `.env` (each one is there, commented, in `.env.example`):

```ini
APP_URL=https://wxnotify.example.com
REVERB_PUBLIC_SCHEME=https
REVERB_PUBLIC_PORT=443
APP_BIND_ADDRESS=127.0.0.1
REVERB_BIND_ADDRESS=127.0.0.1
SESSION_SECURE_COOKIE=true
WXNOTIFY_TRUSTED_PROXIES=*
```

What each one does, and why none is optional:

- `APP_URL` must be the public `https://` address. When it starts with `https://`, the application generates every link, stylesheet, script and Livewire URL as https, even though the proxy talks to it over plain HTTP. With an `http://` value behind an HTTPS proxy, browsers block those URLs as mixed content and the dashboard loads unstyled and does not respond.
- Passkeys (an optional second factor on the profile page) are bound to the host in `APP_URL`. Choose the final address before people register passkeys: changing the domain later makes every existing passkey unusable (recovery codes still work).
- `APP_BIND_ADDRESS` and `REVERB_BIND_ADDRESS` publish 8080 and 8085 on the loopback address only. By default they are published on every interface, and Docker's published ports bypass host firewalls such as ufw and firewalld, so plain HTTP would stay reachable from the internet around your proxy.
- `SESSION_SECURE_COOKIE=true` sends the session cookie over HTTPS only.
- `WXNOTIFY_TRUSTED_PROXIES` tells the application to believe the proxy's `X-Forwarded-For` and `X-Forwarded-Proto` headers. Without it, every request seems to come from one address (with the proxy on the host, that is the Docker network's gateway, such as `172.18.0.1`), so all visitors share one sign-in rate limit and logs and activity alerts show that address; and signed links, such as the email verification link, are checked against `http://` and are refused. `*` trusts whatever connects, which is safe **only** while both ports are bound to `127.0.0.1`: then the only thing that can connect is a process on this host, your proxy. If you publish the ports on another address, do not use `*`; list the address the proxy's connections arrive from instead (for a proxy on the host, the gateway of the stack's network, shown by `docker network inspect <folder>_default --format '{{(index .IPAM.Config 0).Gateway}}'`; for a proxy container on that network, its container address). Set `WXNOTIFY_CLIENT_IP_HEADER` to `CF-Connecting-IP` as well if you are behind Cloudflare.

Recreate the containers with `docker compose up -d` after changing them. If you skip the proxy part for WebSockets (or get it wrong), the live Products page still works: it falls back to polling, which refreshes about once a minute, instead of streaming new products as they arrive.

`REVERB_ALLOWED_ORIGINS` lists the hostnames (no scheme or port) that may open a WebSocket. It defaults to the host in `APP_URL`, so you only set it if people reach the site under other names.

**Caddy** (certificates are automatic):

```
wxnotify.example.com {
    @reverb path /app /app/* /apps /apps/*
    reverse_proxy @reverb 127.0.0.1:8085
    reverse_proxy 127.0.0.1:8080
}
```

**nginx** (bring your own certificate, for example from certbot):

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 443 ssl;
    server_name wxnotify.example.com;
    ssl_certificate     /etc/letsencrypt/live/wxnotify.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/wxnotify.example.com/privkey.pem;

    location ~ ^/apps?(/|$) {
        proxy_pass http://127.0.0.1:8085;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_read_timeout 3600s;
    }

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Upgrading

**An upgrade is a maintenance window.** `docker compose up -d` stops and recreates every WxNotify service at once. The workers, the pipeline API and the feed clients are then held back until the new `front-end` has migrated the database and turned healthy. While migrations run, nothing ingests products or delivers alerts, and the dashboard is down. Pick a quiet time.

1. Back up PostgreSQL and the `app-storage` volume (see [Backups](#backups-and-day-to-day-operation)). Migrations only go forward, so you cannot step back to an older version without restoring a backup:

   ```bash
   docker compose exec -T pgsql sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > wxnotify-$(date +%F).sql.gz
   ```

2. Set the new version in `.env` (`WXNOTIFY_VERSION=<version>`), then:

   ```bash
   docker compose pull
   docker compose up -d
   ```

`front-end` migrates the database first and the workers start after it is healthy; recreating the containers also replaces the old workers, so there is nothing else to restart.

`front-end` gets five minutes (`start_period: 300s` on its `healthcheck` in `compose.yaml`) to finish starting before failed health checks count. If an upgrade has an unusually long migration, raise that value. Never run a second `front-end`, or a manual `migrate`, while one is starting: only one container may migrate at a time.

**If a migration fails**, `front-end` keeps restarting and never becomes healthy, `up -d` exits with `dependency failed to start`, and the other services stay stopped (the old version is not running either): a full outage until you act. `docker compose logs front-end` shows the failing migration. To go back, set `WXNOTIFY_VERSION` to the version you were running and run `docker compose up -d` again. On PostgreSQL a failed migration is rolled back, so the previous version starts against the schema it knows. Then report the failure before trying the upgrade again.

A gentler variant, not tested by us: run `docker compose up -d front-end` first, wait until `docker compose ps` shows it healthy, then run `docker compose up -d`. The old workers keep running while the migrations run (briefly against the new schema), and if the migration fails they are still up; go back as above.

Restoring a backup: stop the stack with `docker compose down`, remove the `pgsql-data` volume (`docker volume ls` shows its full name, which starts with your folder name), start only the database with `docker compose up -d pgsql`, load the dump with `gunzip -c wxnotify-<date>.sql.gz | docker compose exec -T pgsql sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"'`, restore `app-storage` from its archive if you need its files back (below), then set `WXNOTIFY_VERSION` back to the version the dump came from and run `docker compose up -d`.

### Version tags

Pin an exact version (`1.2.3`) so upgrades happen when you decide. If you would rather follow along, releases are also tagged `MAJOR.MINOR` (`1.2`, the latest patch of that line), `MAJOR` (`1`) and `latest`. There is no `0` tag for 0.x versions, and pre-release versions never move `latest`, `MAJOR` or `MAJOR.MINOR`. The same version must be used for every WxNotify image, which is why one variable sets them all.

## Running on four servers

[`deploy/multi-server`](multi-server) is one Compose file with four profiles. The same file and a per-server `.env` go on every machine; `COMPOSE_PROFILES` in that `.env` decides what runs there:

| Server | `COMPOSE_PROFILES` | Runs |
| --- | --- | --- |
| A | `server-a` | `front-end`, `reverb` (dashboard, API, WebSockets) |
| B | `server-b` | `pgsql`, `redis` (data) |
| C | `server-c` | feed clients, `pipeline-api`, `queue-long`, `docker-socket-proxy` (ingestion) |
| D | `server-d` | `queue`, `queue-heartbeat`, `scheduler`, `apprise` (processing and delivery) |

Read [the multi-server guide](../docs/multi-server-deployment.md) for the network layout, firewall rules, sizing, the ordered setup steps and operations; it describes this route. You do not clone the repository, and there is no `composer install`, `npm ci`, `npm run build` or `compose.production.yaml` (those belong to the guide's checkout appendix). You need only the compose file and a `.env` on each server, and Compose pulls the images.

What is different with images:

- **Setup.** On every server download `deploy/multi-server/compose.yaml` and `.env.example`, copy the example to `.env`, set `COMPOSE_PROFILES` and the per-server values the example marks. Use the same `WXNOTIFY_VERSION` everywhere. Set the `MAIL_*` values (A and D) and, if you want the sign-up captcha, the Turnstile keys (A) before anyone signs up, as in the single-server steps. Generate `APP_KEY` once and copy it to A, C and D.
- **Secrets that must match across servers:** `APP_KEY` (A, C, D), `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` (A, C, D, plus B which creates the database), `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` (A and D), and `PIPELINE_WEBHOOK_TOKEN` (A and C). The guide's table explains why.
- **Order for a first start:** B, then A, D, C. On A, run the migrations yourself, because no startup automation runs them here. Start `front-end` alone first and `reverb` only after migrating: a Reverb started against an empty database cannot read its restart signal, and would then ignore every later `reverb:restart`.

  ```bash
  docker compose up -d front-end
  docker compose exec front-end php artisan migrate --force
  docker compose up -d
  ```

  Then create the first owner and run **Setup** exactly as in the single-server steps above, from A.
- **HTTPS on A.** The example is already set for a TLS proxy on A itself: `APP_URL` is `https://`, `APP_BIND_ADDRESS=127.0.0.1`, `SESSION_SECURE_COOKIE=true` and `WXNOTIFY_TRUSTED_PROXIES=*`. The reasons are in [Putting it behind HTTPS](#putting-it-behind-https). If the proxy is another machine, bind `APP_BIND_ADDRESS` to A's private address and list the proxy's address in `WXNOTIFY_TRUSTED_PROXIES` instead of `*`.
- **Ports and bind addresses.** Cross-server ports (`REVERB_BIND_ADDRESS` with `REVERB_PUBLISHED_PORT`, `DB_BIND_ADDRESS`, `DOCKER_PROXY_BIND_ADDRESS`, `APPRISE_BIND_ADDRESS`) default to `127.0.0.1`. Set each to that server's private address where the example says so, never to a public address, and firewall them as the guide describes. B's host ports are `5432` and `6379` unless you set `FORWARD_DB_PORT` and `FORWARD_REDIS_PORT`. The Docker control and Apprise services have no authentication, so keep them on the private network. The Docker socket proxy on C is effectively root on C for anyone who can reach port 2375 (see below).
- **Outlook map images.** Server D writes them and server A serves them, so they need shared storage. On A and D set `OUTLOOK_MAPS_DISK=s3` and the `AWS_*` values for the same bucket; any S3-compatible storage works (the example uses Backblaze B2). Without that, outlook maps return 404 on A.
- **Upgrading.** This is a maintenance window, as on one server. Back up PostgreSQL on B and `app-storage` on A first. Set the new `WXNOTIFY_VERSION` on every server, then:

  ```bash
  # on A: the same order as a first start, so reverb boots after the migration
  docker compose pull
  docker compose up -d front-end
  docker compose exec front-end php artisan view:clear
  docker compose exec front-end php artisan migrate --force
  docker compose up -d

  # on D and C
  docker compose pull
  docker compose up -d

  # then from A
  docker compose exec front-end php artisan queue:restart
  docker compose exec front-end php artisan reverb:restart
  ```

  Clear the compiled views on A after every version change, upgrade or rollback: A keeps `storage/` (including the compiled Blade views) in the `app-storage` volume and has no startup automation, so without `view:clear` it can keep serving views compiled by the other version. Migrate before the workers on C and D restart. `queue:restart` and `reverb:restart` signal through the shared database cache, so running them on A reaches the workers on C and D. Use the `pg_dump` command above on B. This upgrade order follows the first-start order that we ran; a full multi-server upgrade has not been run end to end yet. If the migration fails, set the previous `WXNOTIFY_VERSION` on A, run `docker compose up -d` there and then `docker compose exec front-end php artisan view:clear`, and do not upgrade C and D.

## Self-hosted mode and tiers

`WXNOTIFY_MODE=selfhost` (set in both examples) marks the instance as self-hosted. Compared with `cloud`, the value used by the hosted service, it:

- hides the subscriber **Billing** page, which needs Stripe settings to work;
- hides the billing and support-chat steps of the owner's setup checklist (the Chatwoot support widget is cloud-only as well);
- lets a Traccar server on your own network be used (in cloud mode private addresses are refused);
- allows the opt-in `WXNOTIFY_WEBHOOKS_ALLOW_PRIVATE=true`, which lets webhook and chat-server channels deliver to private-network addresses.

It does not change sign-up: email verification applies in both modes, and the Turnstile captcha is used in both whenever its keys are set.

**Tiers.** Every account is on the **Free** tier, with its limits (for example, the Severe Weather Outlook report is for paid tiers), unless an owner or staff member gives it another one: **Admin > Users**, edit the user, choose **Tier override** (Plus or Pro) and write a **Reason** (required). Clearing the override puts the account back on Free. There is no other way to change a tier on a self-hosted instance.

## Backups and day-to-day operation

- **What to back up:**
  - PostgreSQL (Compose volume `pgsql-data`). It holds accounts, alert rules, channels, the queue and delivery history. Use the `pg_dump` command in [Upgrading](#upgrading).
  - The `app-storage` volume (on server A in the four-server layout). It holds uploaded files: service-desk ticket attachments, bug-report screenshots, onboarding files, and on one server the Outlook map images. Compose prefixes volume names with the project name, which is the folder name unless you set one, so a stack in `~/wxnotify` has `wxnotify_app-storage`; `docker volume ls --filter name=app-storage` shows yours. To archive it into the current folder:

    ```bash
    docker run --rm -v wxnotify_app-storage:/data:ro -v "$PWD":/backup alpine tar czf /backup/app-storage-$(date +%F).tar.gz -C /data .
    ```

    To restore it, stop the stack (`docker compose down`), then unpack the archive into the volume (it keeps the files' owner, `www-data`, uid 33) and start again:

    ```bash
    docker run --rm -v wxnotify_app-storage:/data -v "$PWD":/backup alpine tar xzf /backup/app-storage-<date>.tar.gz -C /data
    docker compose up -d
    ```

  Product files on the `nwws-products` and `emwin-products` volumes are rebuilt from the feeds, and the Product Storage page shows their size. Keep your `.env` somewhere safe too: without `APP_KEY` the encrypted channel credentials cannot be read.
- **Logs:** `docker compose logs -f <service>` (for example `front-end`, `queue`, `nwws`).
- **Health:** `docker compose ps` shows each service's health. The queue workers, scheduler and Reverb have their own health checks.
- **Docker control:** the dashboard's pipeline page starts and stops the feed containers by name through the Docker socket proxy. The proxy allows far more than that: with the settings the dashboard needs (`CONTAINERS=1`, `POST=1`) it lets a caller create and start any container, including a privileged one with the host's filesystem mounted, which is root on the host. Only `exec` and the non-container APIs are blocked. On one server it is reachable only from `front-end` (an internal network, not published), so the exposure is a compromise of the application itself. In the four-server layout anyone who can reach C's port 2375 has root on C: bind it to the private address and firewall it to A alone, as the guide says.

## Problems and licence

Report issues at `https://github.com/wxnotify/wxnotify/issues`. WxNotify is free software, licensed GPL-3.0-or-later; see [LICENSE](../LICENSE).
