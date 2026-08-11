#!/bin/sh
# Swoole entrypoint. su-exec exec-replaces itself, so the Swoole master becomes
# PID 1 and receives SIGTERM directly → graceful shutdown (Swoole reaps its own
# workers, no zombies). App logs go straight to stdout, so `docker logs` shows
# them without any syslog relay.

# If you set up a crontab in a docker/dependencies/ script, start crond here:
# crond -l 8

# The port arrives from compose's `environment:`, holding the same anchor it published
# with `ports:`. Passing it as a flag keeps the two in step and leaves the application
# unaware it is running in a container — the framework has no Docker-specific setting.
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

    # Warm the class-list cache before the server exists. Without it the very first
    # boot walks the whole tree itself, `require_once`-ing every .php it meets — and
    # that happens in the master, which every worker is forked from. Measured on 304
    # files: 40 ms and 10 MB in the master without the warm-up, 12 ms and 8 MB with it.
    # The two megabytes are per worker, so the saving is not only at startup.
    #
    # A failed build is a broken application, not a slow one: `di build` exits non-zero
    # and names the file, so refusing to start turns a crash-looping container into one
    # clear line in `docker logs`. This script has no `set -e` on purpose — the check is
    # explicit so the reason is in the log, not just in the exit code.
    if ! su-exec winter php /var/www/html/call di build; then
        echo "entrypoint: 'call di build' failed — refusing to start" >&2
        exit 1
    fi

    exec su-exec winter php /var/www/html/call run --port="$PORT"
fi
