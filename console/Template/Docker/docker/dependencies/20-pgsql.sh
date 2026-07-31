#!/bin/sh
set -e

# PostgreSQL (pdo_pgsql + pgsql).
# swoole ships the coroutine PG engine, but NOT the PDO pgsql driver — install it.
# Once present, SWOOLE_HOOK_PDO_PGSQL (in the framework's hook mask) makes it
# non-blocking automatically.
if php -m | grep -qi '^pdo_pgsql$'; then
    echo "pdo_pgsql already present — skip"
else
    apk add --no-cache libpq \
        && apk add --no-cache --virtual .pg-deps postgresql-dev \
        && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql \
        && apk del .pg-deps
fi
