# WxNotify

WxNotify is a self-hosted weather alert service. It receives National Weather Service text products (NWWS-OI and EMWIN) and GOES lightning data, matches them against the alert rules people set up, and delivers alerts by email, push, chat services, webhooks and more. It includes a dashboard for subscribers and owners, a live products page, and an API for the mobile app.

This image is the application itself: the dashboard, the API, the queue workers, the scheduler and the WebSocket server for the live page. It is **one image started with different commands**, so a Compose file runs it several times, once per role.

## Quick start

Use the Compose files in the repository, which wire this image to the feed images, PostgreSQL with PostGIS, Apprise and the other pieces:

- **One server** (the right choice for almost everyone): [`deploy/single-server`](https://github.com/wxnotify/wxnotify/tree/main/deploy/single-server)
- **Four servers**, split by role: [`deploy/multi-server`](https://github.com/wxnotify/wxnotify/tree/main/deploy/multi-server)

The step-by-step guide, from nothing to a running server, is in [`deploy/README.md`](https://github.com/wxnotify/wxnotify/tree/main/deploy/README.md). You need Docker Engine with Docker Compose 2.24 or newer.

## Roles

| Role | Command |
| --- | --- |
| Dashboard and API | the image's default command (listens on port 8080; health check at `/up`) |
| Queue workers | `php artisan queue:work ...` (alert delivery, default, long-running and heartbeat queues) |
| Scheduler | `php artisan schedule:work` |
| Live page WebSockets | `php artisan reverb:start --host=0.0.0.0 --port=8080` |

The queue, scheduler and WebSocket roles each have a health check you can use: `healthcheck-queue`, `healthcheck-schedule` and `healthcheck-reverb`.

## What it needs

PostgreSQL with the PostGIS extension, and the secrets the Compose `.env.example` lists (`APP_KEY`, `DB_PASSWORD` and the WebSocket and webhook keys). TLS is left to you: put a reverse proxy in front of the web role. Set `APP_URL` to the public `https://` address before anyone signs up.

## Built on

[ServerSideUp PHP](https://serversideup.net/open-source/docker-php/) 8.4 (FPM-NGINX for the web role, CLI for the workers).

## Tags

Every release publishes all five WxNotify images with the same version tag, for `linux/amd64` and `linux/arm64`. A release such as `v1.2.3` is tagged `1.2.3`, `1.2`, `1` and `latest`; a pre-release such as `v1.2.3-rc.1` gets only its full version. Pin an exact version (`wxnotify/wxnotify:1.2.3`) and change it when you choose to upgrade.

## Source and licence

Source, issues and the full documentation: [https://github.com/wxnotify/wxnotify](https://github.com/wxnotify/wxnotify). WxNotify is licensed under GPL-3.0-or-later.
