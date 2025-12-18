FROM alpine:3.21.3
WORKDIR /var/www/html

# Installing required packages
RUN apk update && apk add --no-cache php83 \
    php83-dom php83-xml php83-xmlwriter \
    php83-tokenizer php83-ctype php83-session \
    php83-common php83-phar php83-pcntl \
    php83-posix php83-mbstring php83-simplexml \
    php83-iconv php83-pdo php83-fpm php83-curl \
    php83-openssl php83-sockets php83-opcache \
    curl nginx runit procps

#  =>  PostgreSql
RUN apk add --no-cache php83-pgsql php83-pdo_pgsql
#  =>  Mysql / MariaDb
RUN apk add --no-cache php83-mysqli php83-pdo_mysql php83-mysqlnd
#  =>  Redis
RUN apk add --no-cache php83-redis

RUN rm -rf /var/cache/apk/*

# Creating the necessary files and directories
RUN mkdir -p /var/run/ && touch /run/php8.3-fpm.pid

# Setting up PHP and Nginx
RUN test -f /usr/bin/php || ln -s /usr/bin/php83 /usr/bin/php
RUN echo "variables_order = 'EGPCS'" > /etc/php83/conf.d/99-custom.ini

COPY . /var/www/html
COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/php-fpm.conf /etc/php83/php-fpm.d/www.conf
COPY ./docker/php-opcache.ini /etc/php83/conf.d/10-opcache.ini

# Create service directories and scripts
RUN mkdir -p /etc/service/php-fpm /etc/service/nginx /etc/service/logs
RUN printf '#!/bin/sh\nexec php-fpm83 -F --allow-to-run-as-root\n' > /etc/service/php-fpm/run \
    && chmod +x /etc/service/php-fpm/run
RUN printf '#!/bin/sh\nexec nginx -g "daemon off;"\n' > /etc/service/nginx/run \
    && chmod +x /etc/service/nginx/run
RUN printf '#!/bin/sh\nwhile true; do\n    ls /var/www/html/storage/logs/frame*.log 1>/dev/null 2>/dev/null && tail -F /var/www/html/storage/logs/frame*.log 2>/dev/null || inotifywait -e create /var/www/html/storage/logs >/dev/null 2>&1; sleep 1;\ndone\n' > /etc/service/logs/run \
    && chmod +x /etc/service/logs/run

# Installing Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --no-plugins --no-interaction --optimize-autoloader

# Adjust permissions for directories
RUN chown -R nginx:nginx /var/www/html \
    && chmod -R 755 /var/www/html/public \
    && chmod -R 775 /var/www/html/storage

EXPOSE 80
ENTRYPOINT ["runsvdir", "/etc/service"]
