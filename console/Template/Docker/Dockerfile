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
    php85-tokenizer php85-ctype php85-session \
    php85-common php85-phar php85-pcntl \
    php85-posix php85-mbstring php85-simplexml \
    php85-iconv php85-pdo php85-fpm php85-curl \
    php85-openssl php85-sockets inotify-tools \
    nginx runit procps

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

COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/php-fpm.conf /etc/php85/php-fpm.d/www.conf
COPY ./docker/php-opcache.ini /etc/php85/conf.d/10-opcache.ini

# Create service directories and scripts
RUN mkdir -p /etc/service/php-fpm /etc/service/nginx /etc/service/logs
RUN printf '#!/bin/sh\nexec php-fpm85 -F\n' > /etc/service/php-fpm/run \
    && chmod +x /etc/service/php-fpm/run
RUN printf '#!/bin/sh\nexec nginx -g "daemon off;"\n' > /etc/service/nginx/run \
    && chmod +x /etc/service/nginx/run
RUN printf '#!/bin/sh\nwhile true; do\n    ls /var/www/html/storage/logs/frame*.log 1>/dev/null 2>/dev/null && tail -F /var/www/html/storage/logs/frame*.log 2>/dev/null || inotifywait -e create /var/www/html/storage/logs >/dev/null 2>&1; sleep 1;\ndone\n' > /etc/service/logs/run \
    && chmod +x /etc/service/logs/run

# Copy vendor from builder (composer not included)
COPY --from=builder /var/www/html/vendor ./vendor

# Copy application code
COPY . /var/www/html

# Adjust permissions for directories
RUN chown -R winter:nginx /var/www/html \
    && chmod -R 755 /var/www/html/public \
    && chmod -R 775 /var/www/html/storage

EXPOSE 80
ENTRYPOINT ["runsvdir", "/etc/service"]
