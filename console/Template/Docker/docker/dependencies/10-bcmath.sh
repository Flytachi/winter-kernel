#!/bin/sh
set -e

# bcmath — arbitrary-precision arithmetic (framework Number / money math).
if php -m | grep -qi '^bcmath$'; then
    echo "bcmath already present — skip"
else
    docker-php-ext-install -j"$(nproc)" bcmath
fi
