# syntax=docker/dockerfile:1
#
# Replaces docker/8.5 (Laravel Sail's stock Ubuntu image) with serversideup/php's
# maintained images. Two roles, each its own final stage, since a queue worker
# needs neither nginx nor Node:
#   - web       (front-end, pipeline-api): fpm-nginx — real PHP-FPM + nginx,
#     not the artisan-serve stopgap the Sail image ran.
#   - worker    (queue, queue-heartbeat): cli — no web server, no Node.
#
# Both roles get a *-development target that matches the container's
# www-data uid/gid to the host developer's, via serversideup's own
# docker-php-serversideup-set-id — this happens at BUILD time only (PUID/PGID
# env vars were removed from these images for the security reasons their own
# docs give), unlike Sail's runtime WWWUSER. compose.yaml supplies USER_ID/
# GROUP_ID as build args, read from .env.
#
# *-production targets exist for a future real deployment (see ARCH's
# Server A/C split) — they're not used by compose.yaml today, and stay on the
# image's default 33:33 www-data uid/gid. AUTORUN_* (migrations, config
# cache, etc. — see https://serversideup.net/open-source/docker-php/docs/laravel/laravel-automations)
# is deliberately left at its default (disabled) everywhere in this dev
# compose file: enabling it would config:cache on every container start,
# which stops the app from ever seeing a local .env edit without a manual
# artisan command. Wire AUTORUN_ENABLED (and friends) into whatever compose
# file/env actually serves production, not here.
#
# Stable v4.5.1 line, not the v5.0.0-beta line that added PHP 8.5 — 8.4 has
# its own GA release and doesn't need to touch beta at all.

ARG PHP_VERSION=8.4
ARG SERVERSIDEUP_VERSION=v4.5.1
ARG NODE_VERSION=24
ARG POSTGRES_VERSION=18

# ============================================================================
# web (front-end, pipeline-api)
# ============================================================================
FROM serversideup/php:${PHP_VERSION}-fpm-nginx-bookworm-${SERVERSIDEUP_VERSION} AS web-base

USER root

# General CLI utilities Sail's Ubuntu image carried that serversideup's
# minimal Debian base doesn't: git (needed by vendor/bin/pint --dirty and by
# any dev workflow), zip/unzip, ca-certificates, a sqlite3 CLI, plus a few
# Sail also bundled (dnsutils, librsvg2-bin, fswatch, ffmpeg, nano, python3)
# kept for parity rather than re-deriving whether each is still load-bearing.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git zip unzip ca-certificates sqlite3 dnsutils librsvg2-bin fswatch ffmpeg nano python3 \
    && git config --system --add safe.directory /var/www/html \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Extensions beyond this image's own default set. mbstring/xml/zip/curl/gd/
# opcache etc. already ship in the base image ("optimized for Laravel").
# Sail's Ubuntu image also carried php-swoole, but nothing in this app
# actually uses it (no laravel/octane dependency) -- dropped rather than
# chased, since install-php-extensions' from-source swoole build fails here
# on an unrelated Oracle-Instant-Client configure check.
RUN install-php-extensions \
        pgsql pdo_pgsql sqlite3 mongodb imap soap ldap msgpack igbinary \
        memcached pcov imagick xdebug bcmath intl redis dbase

# Node/npm/pnpm/bun/yarn/Playwright deps — the Vite build's whole toolchain.
# serversideup images ship no Node at all, unlike Sail's Ubuntu base.
ARG NODE_VERSION
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm pnpm bun corepack \
    && corepack enable \
    && corepack prepare yarn@stable --activate \
    && npx -y playwright install-deps \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# psql client, for the same ad hoc debugging reason Sail's image carried it.
ARG POSTGRES_VERSION
RUN curl -sS https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor | tee /etc/apt/trusted.gpg.d/pgdg.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/trusted.gpg.d/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y "postgresql-client-${POSTGRES_VERSION}" \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

USER www-data

FROM web-base AS web-development
USER root
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN docker-php-serversideup-set-id www-data "${USER_ID}:${GROUP_ID}" \
    && docker-php-serversideup-set-file-permissions --owner "${USER_ID}:${GROUP_ID}"
USER www-data

FROM web-base AS web-production

# ============================================================================
# worker (queue, queue-heartbeat) — no nginx, no Node: it never serves HTTP
# or builds frontend assets.
# ============================================================================
FROM serversideup/php:${PHP_VERSION}-cli-bookworm-${SERVERSIDEUP_VERSION} AS worker-base

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends git zip unzip ca-certificates sqlite3 \
    && git config --system --add safe.directory /var/www/html \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*
RUN install-php-extensions \
        pgsql pdo_pgsql sqlite3 mongodb imap soap ldap msgpack igbinary \
        memcached pcov imagick xdebug bcmath intl redis dbase
USER www-data

FROM worker-base AS worker-development
USER root
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN docker-php-serversideup-set-id www-data "${USER_ID}:${GROUP_ID}" \
    && docker-php-serversideup-set-file-permissions --owner "${USER_ID}:${GROUP_ID}"
USER www-data

FROM worker-base AS worker-production
