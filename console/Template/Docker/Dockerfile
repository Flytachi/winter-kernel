# syntax=docker/dockerfile:1
# =============================================================================
# Winter — Swoole image.
# -----------------------------------------------------------------------------
# One runtime: the Winter application on Swoole. You should not need to edit this file:
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

# Memory ceiling per worker — active in both modes, so it is not staged like opcache.
# PHP's 128M default is invisible until a worker dies of it; this puts the dial in
# sight. Read the file before changing it: the box must hold worker_num × this value.
COPY docker/php-memory.ini /usr/local/etc/php/conf.d/20-memory.ini

# User hook: DB drivers / PHP extensions / cron. Modular scripts in
# docker/dependencies/ — delete what you don't need; they run in filename order
# (numeric prefixes). Placed BEFORE the app code copy so this rarely-changing
# layer stays cached across code edits. Each script is self-contained: it installs
# its own build dependencies and drops them again.
#
# `|| exit 1` is what fails the build on a broken script. Without it the loop keeps
# going and RUN reports the status of the last iteration, so an image can come out
# green with the extension missing — found out at runtime instead.
COPY docker/dependencies/ /tmp/dependencies/
RUN for f in /tmp/dependencies/*.sh; do \
        [ -e "$f" ] || continue; \
        echo "→ deps: $f"; sh "$f" || exit 1; \
    done \
    && rm -rf /tmp/dependencies /var/cache/apk/* /tmp/pear

# vendor from builder (composer not included in the runtime image)
COPY --from=builder /var/www/html/vendor ./vendor

# Application code
COPY . /var/www/html

# The application writes .winter/ (and logs/, with LOG_OUTPUT=file) at runtime as
# `winter`: owning the tree is what lets it create them. No separate mode for web
# assets: Swoole serves them from the worker itself, so there is no second process
# that has to be able to read them.
RUN chown -R winter:winter /var/www/html

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# No EXPOSE on purpose. It publishes nothing — `ports:` in docker-compose.yml does that,
# and the port itself is declared in the code (#[EnableHttp(port:)]). Baking a number in
# here would only add one that can disagree with both. A metadata line that quietly lies
# is worse than no metadata line.
ENTRYPOINT ["/entrypoint.sh"]
