FROM alpine:3.23.3 AS builder
WORKDIR /var/www/html

RUN apk add --no-cache php85 php85-phar php85-mbstring php85-openssl php85-curl curl \
    && rm -rf /var/cache/apk/* \
    && ln -s /usr/bin/php85 /usr/bin/php

COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php85 -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --no-plugins --no-interaction --optimize-autoloader --ignore-platform-reqs \
    && rm /usr/local/bin/composer

# -------------------------------------------------------

FROM alpine:3.23.3
WORKDIR /var/www/html

# Installing required packages
RUN apk update && apk add --no-cache php85 \
    php85-dom php85-xml php85-xmlwriter \
    php85-tokenizer php85-common php85-session \
    php85-ctype php85-phar php85-pcntl php85-fileinfo \
    php85-posix php85-mbstring php85-simplexml \
    php85-iconv php85-pdo php85-fpm php85-curl \
    php85-openssl php85-sockets \
    nginx runit procps bash

#  =>  PostgreSql
#RUN apk add --no-cache php85-pgsql php85-pdo_pgsql
#  =>  Mysql / MariaDb
#RUN apk add --no-cache php85-mysqli php85-pdo_mysql php85-mysqlnd
#  =>  Redis
#RUN apk add --no-cache php85-redis

RUN rm -rf /var/cache/apk/*

# Create application user
RUN adduser -D -H -s /sbin/nologin winter \
    && adduser winter nginx

# Creating the necessary files and directories
RUN mkdir -p /var/run/ && touch /run/php8.5-fpm.pid

# Setting up PHP and Nginx
RUN test -f /usr/bin/php || ln -s /usr/bin/php85 /usr/bin/php
RUN echo "variables_order = 'EGPCS'" > /etc/php85/conf.d/99-custom.ini
RUN sed -i '/^\[global\]/a error_log = syslog' /etc/php85/php-fpm.conf

COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/php-fpm.conf /etc/php85/php-fpm.d/www.conf

ARG DISABLE_OPCACHE=false
COPY ./docker/php-opcache.ini /tmp/php-opcache.ini
RUN if [ "$DISABLE_OPCACHE" = "false" ]; then \
        cp /tmp/php-opcache.ini /etc/php85/conf.d/10-opcache.ini; \
    fi && rm -f /tmp/php-opcache.ini


# Create service directories and scripts
RUN mkdir -p /etc/service/syslog /etc/service/php-fpm /etc/service/nginx
RUN printf '#!/bin/sh\nwhile [ ! -S /dev/log ]; do sleep 1; done\nexec php-fpm85 -F\n' > /etc/service/php-fpm/run \
    && chmod +x /etc/service/php-fpm/run
RUN printf '#!/bin/sh\nwhile [ ! -S /dev/log ]; do sleep 1; done\nexec nginx -g "daemon off;"\n' > /etc/service/nginx/run \
    && chmod +x /etc/service/nginx/run
# Logger - syslog
RUN printf '#!/bin/sh\nexec syslogd -n -O /dev/stdout\n' > /etc/service/syslog/run \
    && chmod +x /etc/service/syslog/run

# Copy vendor from builder (composer not included)
COPY --from=builder /var/www/html/vendor ./vendor

# Copy application code
COPY . /var/www/html

# Adjust permissions for directories
RUN chown -R winter:nginx /var/www/html \
    && chmod -R 755 /var/www/html/public \
    && chmod -R 775 /var/www/html/storage

# bash
RUN ./call cfg completion -if

EXPOSE 80

# Entrypoint: re-own the volatile store to the runtime user (winter) with an
# owner-only mode (0700) on every container start — before any service boots.
# `chown -R` neutralizes root-owned artifacts left by build-time or operator
# `./call` runs (winter owns the dir → may replace any file inside, ownership of
# individual files is irrelevant for unlink in a non-sticky dir it owns).
# 0700 (not 0777) is deliberate: the volatile store holds the DI code cache
# (di.php is require()d PHP) — a world-writable path in shared /tmp would let any
# other UID inject code that runs as winter. 0700 locks out every non-winter UID.
# Path = /tmp/flytachi.winter.volatile.<basename WORKDIR>.
RUN printf '#!/bin/sh\nVOL=/tmp/flytachi.winter.volatile.html\nmkdir -p "$VOL"\nchown -R winter:winter "$VOL"\nchmod 0700 "$VOL"\nexec runsvdir /etc/service\n' > /entrypoint.sh \
    && chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]