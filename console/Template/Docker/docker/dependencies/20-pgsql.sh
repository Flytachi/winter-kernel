#!/bin/sh
set -e

# PostgreSQL (pdo_pgsql + pgsql). Swoole ships the coroutine PG engine but not the
# PDO driver; with it present, SWOOLE_HOOK_PDO_PGSQL makes it non-blocking.
# libpq stays behind — the extension links to it. Headers come from libpq-dev, not
# postgresql-dev: that one is the server package and pulls far more for one header.
if php -m | grep -qi '^pdo_pgsql$'; then
    echo "pdo_pgsql already present — skip"
else
    apk add --no-cache libpq \
        && apk add --no-cache --virtual .pg-deps $PHPIZE_DEPS libpq-dev \
        && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql \
        && apk del .pg-deps
fi
