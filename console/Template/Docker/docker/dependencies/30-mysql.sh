#!/bin/sh
set -e

# MySQL / MariaDB — pdo_mysql is the driver CDO uses (PDO). mysqli is optional;
# uncomment the block below if your code needs it.
if php -m | grep -qi '^pdo_mysql$'; then
    echo "pdo_mysql already present — skip"
else
    docker-php-ext-install -j"$(nproc)" pdo_mysql
fi

# if ! php -m | grep -qi '^mysqli$'; then
#     docker-php-ext-install -j"$(nproc)" mysqli
# fi
