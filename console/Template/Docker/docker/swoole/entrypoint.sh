#!/bin/sh
# Swoole runtime entrypoint.
# su-exec exec-replaces itself, so the Swoole master becomes PID 1 and receives
# SIGTERM directly → graceful shutdown. Swoole reaps its own workers (no zombies).

# ── syslog (ENABLED by default — comment the line to DISABLE) ─────────────────
# Runs busybox syslogd in the background so that, IF you set LOG_OUTPUT=syslog in
# .env, app logs surface in `docker logs` (/dev/log → syslogd → stdout). The
# framework picks the log target itself (default: straight to stdout), so with
# stdout output this daemon just sits idle — harmless. Comment to drop it.
syslogd -O /dev/stdout
# ─────────────────────────────────────────────────────────────────────────────

# If you set up a crontab in docker/dependencies.sh, start crond here first.
# busybox crond backgrounds itself, so it runs alongside the server below.
# It stays root and executes the `winter` crontab as winter (no su-exec needed).
# crond -l 8

exec su-exec winter php /var/www/html/call run --port=80
