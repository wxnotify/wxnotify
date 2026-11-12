# WxNotify

WxNotify turns weather feeds into alerts that reach people. It ingests National Weather Service text products (NWWS-OI), EMWIN and GOES lightning (GLM) data, matches them against the alert rules and places each subscriber has set up, and delivers the result through the channels they chose.

## What is in it

- **Feeds.** Separate containers for NWWS-OI, EMWIN (ByteBlaster and HTTP) and GLM post products to an ingestion API, which stores them and queues the matching work.
- **Alert rules and places.** Subscribers choose products, areas and places; matching and delivery run on database-backed queue workers.
- **Channels.** Email, SMS and voice (Twilio, BulkVS, HTTP SMS), web push and mobile push, Slack, Discord, Microsoft Teams, Telegram, Matrix, Mattermost, Rocket.Chat, Google Chat, Bluesky, ntfy, Pushover, PagerDuty, IFTTT and webhooks. See `app/Support/ChannelTypes` for the full list and what each needs configured.
- **Dashboard and admin.** Filament panels for subscribers (`/dashboard`) and owners (`/admin`), including a live products page streamed over WebSockets (Laravel Reverb), a map, and pipeline and storage tools.
- **API.** A versioned JSON API under `/api/v1` for the mobile app.

The stack is Laravel and Filament on PHP 8.4, PostgreSQL with PostGIS, Laravel Reverb, and Python feed clients, all run with Docker Compose.

## Run it yourself

Published Docker images and ready-made Compose files mean you do not need to clone this repository. Start with [deploy/README.md](deploy/README.md): a single-server quick start, HTTPS, upgrades, and the four-server layout. [deploy/single-server](deploy/single-server) and [deploy/multi-server](deploy/multi-server) hold the Compose files and `.env.example` templates.

## Develop locally

The development stack is the `compose.yaml` in this folder. It bind-mounts the checkout into the containers, so there is nothing to build for the PHP code itself.

```bash
cp .env.example .env
docker compose up -d
docker compose exec front-end composer install
docker compose exec front-end php artisan key:generate
docker compose exec front-end php artisan migrate --seed
docker compose exec front-end npm install
docker compose exec front-end npm run dev
```

Composer and npm are always run inside the `front-end` container. Run the tests with:

```bash
docker compose exec front-end php artisan test --compact
```

The test suite uses Pest. `CLAUDE.md` and the rules under `.ai/rules` describe the coding conventions this project follows.

## Documentation

- [deploy/README.md](deploy/README.md): self-hosting from the published images.
- [docs/multi-server-deployment.md](docs/multi-server-deployment.md): the four-server layout, firewall, sizing and operations.
- [docs/superpowers/specs](docs/superpowers/specs) and [docs/superpowers/plans](docs/superpowers/plans): design specifications and implementation plans.

## Licence

WxNotify is free software, licensed under the GNU General Public License, version 3 or (at your option) any later version (GPL-3.0-or-later). See [LICENSE](LICENSE).
