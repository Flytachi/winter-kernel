# syntax=docker/dockerfile:1
# =============================================================================
# Winter — Swoole image.
# -----------------------------------------------------------------------------
# One runtime (Swoole HTTP server). You should not need to edit this file:
#   - DB drivers / PHP extensions / cron → docker/dependencies/*.sh
#     (delete the ones you don't need; they run in filename order)
#   - dev vs prod                        → DEV in docker-compose.yml
# =============================================================================

# ─────────────────────────────────────────────────────────────────────────────
# builder — composer install, no dev deps. vendor is copied into the runtime
# stage; composer itself never ships in the final image.
# ─────────────────────────────────────────────────────────────────────────────
FROM alpine:3.23.3 AS builder
WORKDIR /var/www/html

RUN apk add --no-cache php85 php85-phar php85-mbstring php85-openssl php85-curl curl \
    && rm -rf /var/cache/apk/* \
    && ln -s /usr/bin/php85 /usr/bin/php

COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php85 -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --no-plugins --no-interaction --optimize-autoloader --ignore-platform-reqs \
    && rm /usr/local/bin/composer

# ─────────────────────────────────────────────────────────────────────────────
# final — maintained phpswoole base (swoole + opcache precompiled).
# ─────────────────────────────────────────────────────────────────────────────
FROM phpswoole/swoole:php8.5-alpine AS final
WORKDIR /var/www/html

RUN apk add --no-cache su-exec procps \
    && rm -rf /var/cache/apk/* \
    && adduser -D -H -s /sbin/nologin winter

RUN docker-php-ext-install -j"$(nproc)" pcntl

# Opcache config staged for the entrypoint to activate at runtime (no rebuild to
# switch): prod → on (tuned, enable_cli=1); dev (DEV=true) → left off so mounted
# code is always live.
COPY docker/php-opcache.ini /opt/winter/php-opcache.ini

# User hook: DB drivers / PHP extensions / cron. Modular scripts in
# docker/dependencies/ — delete what you don't need; they run in filename order
# (numeric prefixes). Placed BEFORE the app code copy so this rarely-changing
# layer stays cached across code edits.
COPY docker/dependencies/ /tmp/dependencies/
RUN for f in /tmp/dependencies/*.sh; do \
        [ -e "$f" ] || continue; \
        echo "→ deps: $f"; sh "$f"; \
    done \
    && rm -rf /tmp/dependencies /var/cache/apk/* /tmp/pear

# vendor from builder (composer not included in the runtime image)
COPY --from=builder /var/www/html/vendor ./vendor

# Application code
COPY . /var/www/html

# chown covers the whole tree; storage additionally needs to be group-writable.
# No separate mode for web assets: Swoole serves them from the worker itself, so
# there is no second process that has to be able to read them.
RUN chown -R winter:winter /var/www/html \
    && chmod -R 775 /var/www/html/storage

# Warm shell completion (best-effort; never fails the build)
RUN php call cfg completion -if || true

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Documentation only — EXPOSE publishes nothing. The host binding is done by `ports:`
# in docker-compose.yml, and the app binds where SERVER_PORT says. Kept in step with
# both so `docker image inspect` and `docker run -P` report the truth.
ARG SERVER_PORT=8000
EXPOSE ${SERVER_PORT}

ENTRYPOINT ["/entrypoint.sh"]
