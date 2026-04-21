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

FROM phpswoole/swoole:php8.5-alpine
WORKDIR /var/www/html

# Included in base: swoole, opcache, dom, xml, xmlwriter, tokenizer, ctype,
#   session, pcntl, posix, mbstring, simplexml, iconv, pdo, pdo_mysql,
#   openssl, sockets, curl, redis

RUN apk add --no-cache su-exec procps \
    && rm -rf /var/cache/apk/*

#  =>  PostgreSql
#RUN apk add --no-cache postgresql-dev && docker-php-ext-install pdo_pgsql
#  =>  MongoDB
#RUN apk add --no-cache $PHPIZE_DEPS && pecl install mongodb && docker-php-ext-enable mongodb

# Create application user
RUN adduser -D -H -s /sbin/nologin winter

# Opcache: enable CLI (required — Swoole is a long-lived CLI process)
ARG DISABLE_OPCACHE=false
COPY ./docker/php-opcache.ini /tmp/php-opcache.ini
RUN if [ "$DISABLE_OPCACHE" = "false" ]; then \
        cp /tmp/php-opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini; \
    fi && rm -f /tmp/php-opcache.ini

# Copy vendor from builder (composer not included)
COPY --from=builder /var/www/html/vendor ./vendor

# Copy application code
COPY . /var/www/html

# Adjust permissions
RUN chown -R winter:winter /var/www/html \
    && chmod -R 755 /var/www/html/public \
    && chmod -R 775 /var/www/html/storage

EXPOSE 80
ENTRYPOINT ["su-exec", "winter", "php", "/var/www/html/call", "run", "--port=80"]