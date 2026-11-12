<!-- Maintainer: confirm the Docker Hub namespace (the images are written as wxnotify/...) and fill in <repository-url> before publishing this page. -->

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

## Quick start: one server

1. Make a folder and download the two files into it. The files live in [`deploy/single-server`](single-server) in the repository:

   ```bash
   mkdir wxnotify && cd wxnotify
   curl -fsSLO <repository-url>/raw/main/deploy/single-server/compose.yaml
   curl -fsSLO <repository-url>/raw/main/deploy/single-server/.env.example
   cp .env.example .env
   ```

2. Open `.env` and set `WXNOTIFY_VERSION` to the release you want to run (the file ships with `latest`, but pinning a version is better; see [Upgrading](#upgrading)). Find the current release on the repository's releases page, then write it as `WXNOTIFY_VERSION=<version>`, for example `1.0.0` once that exists.

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

   Generate `APP_KEY` once and never change it: channel credentials are encrypted with it. Also set `APP_URL` to the address people will use, and the `MAIL_*` settings if you want sign-up and password-reset emails. `NWWS_USERNAME` and `NWWS_PASSWORD` are optional; leave them empty to run without the NWWS feed.

4. Start it:

   ```bash
   docker compose up -d
   ```

   Compose refuses to start if a required value is missing. A blank secret stops it with a line like `set APP_KEY in .env`, and a missing version stops it with `set WXNOTIFY_VERSION in .env`. Fill in the named variable and run the command again.

   The `front-end` service runs the database migrations when it starts, and every other WxNotify service waits for it to become healthy, so a first start never runs workers against an empty database. The first start takes a minute or two. Watch it with `docker compose ps`; if anything stays unhealthy, read `docker compose logs front-end`.

5. Open the dashboard at `APP_URL` plus `/dashboard` (by default <http://localhost:8080/dashboard>).

### Create the first owner

1. Register an account on the dashboard sign-up page, `/dashboard/register`.
2. Promote it to owner:

   ```bash
   docker compose exec front-end php artisan app:grant-owner you@example.com
   ```

3. Sign in to the admin panel at `/admin` and open **Setup** (in the **System** group). A fresh install is not complete until you do this. The page lists the reference data sets with their counts and whether each is installed: the product catalog (categories and descriptions), the "Did you know" tips and the tracked shapefile layers. Press **Install reference data** and confirm. It only adds what is missing and changes nothing that already exists. Read the confirmation text: when the product catalog is not yet installed, the install also removes a few legacy products that have no counterpart in the catalog.

Do not run `db:seed`; the seeder is for development only and refuses to run in production.

## Putting it behind HTTPS

TLS is your job: the stack does not include a certificate or a proxy. Without it the stack still works over plain HTTP, and the browser connects to the live WebSocket server on the page's own host at port 8085.

For real use, put a reverse proxy in front that does two things:

1. Proxies the application to the `front-end` port (`APP_PORT`, default 8080).
2. Forwards the WebSocket paths `/app` and `/apps` to Reverb on its published port (`REVERB_PUBLISHED_PORT`, default 8085).

Then tell the application where browsers will connect, in `.env`:

```ini
APP_URL=https://wxnotify.example.com
REVERB_PUBLIC_SCHEME=https
REVERB_PUBLIC_PORT=443
```

Recreate the containers with `docker compose up -d` after changing them. If you skip the proxy part for WebSockets (or get it wrong), the live Products page still works: it falls back to polling, which refreshes about once a minute, instead of streaming new products as they arrive.

`REVERB_ALLOWED_ORIGINS` lists the hostnames (no scheme or port) that may open a WebSocket. It defaults to the host in `APP_URL`, so you only set it if people reach the site under other names.

Behind a proxy the application sees the proxy's address for every request unless you tell it whom to trust. Set `WXNOTIFY_TRUSTED_PROXIES` to `*` or a comma list of proxy addresses, and `WXNOTIFY_CLIENT_IP_HEADER` to `CF-Connecting-IP` if you are behind Cloudflare.

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

1. Back up PostgreSQL. Migrations only go forward, so you cannot step back to an older version without restoring a backup:

   ```bash
   docker compose exec -T pgsql sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > wxnotify-$(date +%F).sql.gz
   ```

2. Set the new version in `.env` (`WXNOTIFY_VERSION=<version>`), then:

   ```bash
   docker compose pull
   docker compose up -d
   ```

`front-end` migrates the database first and the workers start after it is healthy; recreating the containers also replaces the old workers, so there is nothing else to restart.

`front-end` gets five minutes (`start_period: 300s` on its `healthcheck` in `compose.yaml`) to finish starting before failed health checks count. If an upgrade has an unusually long migration, raise that value. A migration that fails keeps `front-end` from ever becoming healthy and nothing else starts; `docker compose logs front-end` shows why.

Restoring a backup: stop the stack with `docker compose down`, remove the `pgsql-data` volume (`docker volume ls` shows its full name, which starts with your folder name), start only the database with `docker compose up -d pgsql`, load the dump with `gunzip -c wxnotify-<date>.sql.gz | docker compose exec -T pgsql sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"'`, then set `WXNOTIFY_VERSION` back to the version the dump came from and run `docker compose up -d`.

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

Read [the multi-server guide](../docs/multi-server-deployment.md) for the network layout, firewall rules, sizing and operations; it applies to this route. What does **not** apply to the image route: cloning the repository, `composer install`, `npm ci` and `npm run build`, and `compose.production.yaml`. You need only the compose file and a `.env` on each server, and Compose pulls the images.

What is different with images:

- **Setup.** On every server download `deploy/multi-server/compose.yaml` and `.env.example`, copy the example to `.env`, set `COMPOSE_PROFILES` and the per-server values the example marks. Use the same `WXNOTIFY_VERSION` everywhere. Generate `APP_KEY` once and copy it to A, C and D.
- **Secrets that must match across servers:** `APP_KEY` (A, C, D), `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` (A, C, D, plus B which creates the database), `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` (A and D), and `PIPELINE_WEBHOOK_TOKEN` (A and C). The guide's table explains why.
- **Order for a first start:** B, then A, D, C. On A, run the migrations yourself, because no startup automation runs them here:

  ```bash
  docker compose up -d
  docker compose exec front-end php artisan migrate --force
  ```

  Then create the first owner and run **Setup** exactly as in the single-server steps above, from A.
- **Ports and bind addresses.** Cross-server ports (`REVERB_BIND_ADDRESS` with `REVERB_PUBLISHED_PORT`, `DB_BIND_ADDRESS`, `DOCKER_PROXY_BIND_ADDRESS`, `APPRISE_BIND_ADDRESS`) default to `127.0.0.1`. Set each to that server's private address where the example says so, never to a public address, and firewall them as the guide describes. The Docker control and Apprise services have no authentication, so keep them on the private network.
- **Outlook map images.** Server D writes them and server A serves them, so they need shared storage. On A and D set `OUTLOOK_MAPS_DISK=s3` and the `AWS_*` values for the same bucket; any S3-compatible storage works (the example uses Backblaze B2). Without that, outlook maps return 404 on A.
- **Upgrading.** Set the new `WXNOTIFY_VERSION` on every server, then:

  ```bash
  # on A
  docker compose pull
  docker compose up -d
  docker compose exec front-end php artisan migrate --force

  # on D and C
  docker compose pull
  docker compose up -d

  # then from A
  docker compose exec front-end php artisan queue:restart
  docker compose exec front-end php artisan reverb:restart
  ```

  Migrate before the workers on C and D restart. `queue:restart` and `reverb:restart` signal through the shared database cache, so running them on A reaches the workers on C and D. Back up PostgreSQL on B first, using the `pg_dump` command above on that server.

## Backups and day-to-day operation

- **What to back up:** PostgreSQL (Compose volume `pgsql-data`). It holds accounts, alert rules, channels, the queue and delivery history. Product files on the `nwws-products` and `emwin-products` volumes are rebuilt from the feeds, and the Product Storage page shows their size. Keep your `.env` somewhere safe too: without `APP_KEY` the encrypted channel credentials cannot be read.
- **Logs:** `docker compose logs -f <service>` (for example `front-end`, `queue`, `nwws`).
- **Health:** `docker compose ps` shows each service's health. The queue workers, scheduler and Reverb have their own health checks.
- **Docker control:** the dashboard's pipeline page starts and stops the feed containers by name through the Docker socket proxy.

## Problems and licence

Report issues at `<repository-url>/issues`. WxNotify is free software, licensed GPL-3.0-or-later; see [LICENSE](../LICENSE).
