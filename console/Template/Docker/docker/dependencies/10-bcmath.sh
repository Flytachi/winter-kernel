#!/bin/sh
set -e

# bcmath — arbitrary-precision arithmetic (framework Number / money math).
if php -m | grep -qi '^bcmath$'; then
    echo "bcmath already present — skip"
else
    apk add --no-cache --virtual .bcmath-deps $PHPIZE_DEPS \
        && docker-php-ext-install -j"$(nproc)" bcmath \
        && apk del .bcmath-deps
fi
