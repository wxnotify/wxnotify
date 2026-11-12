# syntax=docker/dockerfile:1
#
# Two kinds of target live here:
#
# Development (used by compose.yaml, built with ./docker/8.4 as the context and
# the application bind-mounted from the host; nothing is copied in):
#   - web-development    (front-end, pipeline-api): fpm-nginx, real PHP-FPM +
#     nginx, plus Node and the CLI tooling the dev workflow needs.
#   - worker-development (queue, queue-long, queue-heartbeat, scheduler,
#     reverb): cli, no web server, no Node.
#   Both match the container's www-data uid/gid to the host developer's via
#   serversideup's docker-php-serversideup-set-id, at BUILD time only (PUID/
#   PGID env vars were removed from these images for the security reasons their
#   own docs give). compose.yaml supplies USER_ID/GROUP_ID as build args, read
#   from .env.
#
# Production (the published image, built with the REPOSITORY ROOT as context:
#   docker build -f docker/8.4/Dockerfile --target production .):
#   - production: fpm-nginx with the application baked in. One image serves
#     every role: the default command (S6: nginx + PHP-FPM) is the web role,
#     and `command: php artisan ...` replaces S6 for the queue workers, the
#     scheduler and Reverb. It keeps the image's default 33:33 www-data user,
#     carries no .env, auth.json, Node or dev dependencies, and copies only the
#     paths the application needs at runtime (the .dockerignore is shared with
#     the feed images' root-context builds, so it cannot exclude pipeline/,
#     docker/ etc.; a wholesale COPY would ship them). docker/8.4/smoke-test.sh
#     checks the result.
#   Built from production-base (the runtime PHP extensions), app-vendor
#   (composer install --no-dev, with no credentials: the only package from
#   Filament's licensed repository, filament/blueprint, is dev-only) and
#   app-assets (the Vite build).
#
# AUTORUN_* (migrations, config cache, etc., see
# https://serversideup.net/open-source/docker-php/docs/laravel/laravel-automations)
# stays at its default (disabled) in every target. In development, enabling it
# would config:cache on every container start, which stops the app from ever
# seeing a local .env edit. In production it is switched on per service by the
# compose file (only the one container that should migrate), never in the image.
#
# Stable v4.5.1 line, not the v5.0.0-beta line that added PHP 8.5 -- 8.4 has
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

# ============================================================================
# worker (queue, queue-long, queue-heartbeat, scheduler, reverb) — no nginx,
# no Node: it never serves HTTP or builds frontend assets.
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

# ============================================================================
# production: the published image (repository root as the build context).
# ============================================================================

# The runtime PHP extensions: web-base's list minus the dev-only xdebug and pcov.
# Shared by app-vendor, so Composer checks the platform it will run on, and by
# the final image, so the extension build is done once.
FROM serversideup/php:${PHP_VERSION}-fpm-nginx-bookworm-${SERVERSIDEUP_VERSION} AS production-base
USER root
RUN install-php-extensions \
        pgsql pdo_pgsql sqlite3 mongodb imap soap ldap msgpack igbinary \
        memcached imagick bcmath intl redis dbase
USER www-data

# Production Composer dependencies and the application code, with an
# authoritative classmap and the package manifest. No auth.json and no
# credentials of any kind: every non-dev package downloads anonymously.
# Composer scripts are skipped (they include dev-only boost:update and
# filament:upgrade, whose published assets are already committed under public/).
FROM production-base AS app-vendor
USER root
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist \
    && rm -rf /composer/cache
# Exactly what the application reads at runtime. database/ carries migrations,
# settings migrations, seeders and their data/product_examples (the catalog
# seeder and factory reset read them); lang/ the vendor translations; public/
# the committed Filament assets, images and front controller.
COPY app app
COPY bootstrap bootstrap
COPY config config
COPY database database
COPY lang lang
COPY public public
COPY resources resources
COPY routes routes
COPY artisan LICENSE ./
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts --no-interaction \
    && php artisan package:discover --ansi \
    && rm -rf storage

# The Vite build. The Filament theme imports CSS from vendor/ and scans app/
# and resources/views for classes, so both come in; .gitignore keeps
# Tailwind's automatic source detection to what it scans in development. No
# .env reaches this stage, so no VITE_REVERB_* value is baked in (the page
# supplies Reverb settings at request time).
FROM node:${NODE_VERSION}-slim AS app-assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY .gitignore vite.config.js ./
COPY resources resources
COPY public public
COPY app app
COPY --from=app-vendor /var/www/html/vendor vendor
RUN npm run build

# The application is owned by root and read-only to www-data; www-data owns
# only what it writes: storage/ (where the compose files mount a named volume,
# which Docker initialises from this directory's contents and ownership) and
# bootstrap/cache (config, route, view and package caches). public/storage is
# the link `php artisan storage:link` would make.
FROM production-base AS production
USER root
COPY --from=app-vendor /var/www/html /var/www/html
COPY --from=app-assets /app/public/build /var/www/html/public/build
RUN mkdir -p storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && ln -s /var/www/html/storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chown root:root /var/www/html
ENV PHP_OPCACHE_ENABLE=1 \
    HEALTHCHECK_PATH=/up
LABEL org.opencontainers.image.title="WxNotify" \
      org.opencontainers.image.description="WxNotify web, queue, scheduler and Reverb image (one image, role chosen by command)" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.vendor="WxNotify" \
      org.opencontainers.image.authors="WxNotify"
USER www-data
