# syntax=docker/dockerfile:1

# vendor/ — built apart, so composer never ships in the final image
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --ignore-platform-reqs

# the application — on the Winter runtime image: Swoole, pcntl, bcmath, pgsql, mysql,
# redis, opcache, user winter. https://hub.docker.com/r/flytachi/winter
FROM flytachi/winter:8.5

# An extension the image lacks — installed as root, then back to winter:
# USER root
# RUN apk add --no-cache icu-libs \
#     && apk add --no-cache --virtual .intl-deps $PHPIZE_DEPS icu-dev \
#     && docker-php-ext-install -j"$(nproc)" intl \
#     && apk del .intl-deps
# USER winter

# The files are winter's: the application runs as winter and writes .winter/ (and logs/).
COPY --from=vendor --chown=winter:winter /app/vendor ./vendor
COPY --chown=winter:winter . .

# Starts the application as PID 1: `call application start` (`… dev` with DEV=true),
# after `call di build` in production. Inherited from the image — repeated to be seen.
ENTRYPOINT ["winter-entrypoint"]
