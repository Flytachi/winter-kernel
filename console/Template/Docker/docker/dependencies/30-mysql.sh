#!/bin/sh
set -e

# MySQL / MariaDB — pdo_mysql is the driver CDO uses (PDO). No client library to
# install: it builds against mysqlnd, bundled with PHP. mysqli is optional —
# uncomment the block below if your code needs it.
if php -m | grep -qi '^pdo_mysql$'; then
    echo "pdo_mysql already present — skip"
else
    apk add --no-cache --virtual .mysql-deps $PHPIZE_DEPS \
        && docker-php-ext-install -j"$(nproc)" pdo_mysql \
        && apk del .mysql-deps
fi

# if ! php -m | grep -qi '^mysqli$'; then
#     apk add --no-cache --virtual .mysqli-deps $PHPIZE_DEPS \
#         && docker-php-ext-install -j"$(nproc)" mysqli \
#         && apk del .mysqli-deps
# fi
