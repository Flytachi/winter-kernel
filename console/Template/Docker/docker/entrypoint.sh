#!/bin/sh
# Swoole entrypoint. su-exec exec-replaces itself, so the Swoole master becomes
# PID 1 and receives SIGTERM directly → graceful shutdown (Swoole reaps its own
# workers, no zombies). App logs go straight to stdout, so `docker logs` shows
# them without any syslog relay.

# If you set up a crontab in a docker/dependencies/ script, start crond here:
# crond -l 8

# The port comes from compose, which read it from .env — the same value it published
# with `ports:`. Passing it as a flag keeps the two in step without the application
# needing to know it is running in a container.
PORT="${SERVER_PORT:-8000}"

# Opcache is toggled here (runtime, as root before su-exec), so switching dev/prod
# needs no rebuild. Idempotent: safe on a fresh or a restarted container.
OPCACHE_CONF=/usr/local/etc/php/conf.d/10-opcache.ini

if [ "${DEV:-false}" = "true" ]; then
    # Development: opcache off (mounted code always live) + DevWatcher hot-reload.
    rm -f "$OPCACHE_CONF"
    exec su-exec winter php /var/www/html/call run dev --port="$PORT"
else
    # Production: tuned opcache on.
    cp /opt/winter/php-opcache.ini "$OPCACHE_CONF"
    exec su-exec winter php /var/www/html/call run --port="$PORT"
fi
