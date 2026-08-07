#!/bin/sh
set -e

# phpredis (\Redis) — the framework's Redis client (winter-cache). Many bases
if php -m | grep -qi '^redis$'; then
    echo "redis already present — skip"
else
    apk add --no-cache --virtual .redis-deps $PHPIZE_DEPS \
        && pecl install redis \
        && docker-php-ext-enable redis \
        && apk del .redis-deps
fi
