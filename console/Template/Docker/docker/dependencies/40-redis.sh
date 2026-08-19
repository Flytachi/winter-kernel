#!/bin/sh
set -e

# phpredis (\Redis) — the extension flytachi/winter-redis is built on.
if php -m | grep -qi '^redis$'; then
    echo "redis already present — skip"
else
    apk add --no-cache --virtual .redis-deps $PHPIZE_DEPS \
        && pecl install redis \
        && docker-php-ext-enable redis \
        && apk del .redis-deps
fi
