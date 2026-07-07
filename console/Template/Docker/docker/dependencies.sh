#!/bin/sh
# =============================================================================
# dependencies.sh — extra components & custom build steps.
# -----------------------------------------------------------------------------
# Runs as ROOT during image build (before the app code is copied, so this layer
# stays cached across code edits). Everything here is OPT-IN: uncomment what you
# need. An empty script is a valid no-op.
#
# $RUNTIME tells you which base you are on:
#   fpm    → slim Alpine, classic sync workers. Extensions from apk (php85-<ext>);
#            plain blocking drivers are fine — there is no event loop.
#   swoole → phpswoole base, coroutine workers. The framework runs with
#            `Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL ^ PROC)`, so the
#            STANDARD pdo/phpredis drivers are transparently made non-blocking by
#            Swoole's runtime hooks — you do NOT need special "coroutine" clients.
#            The base already bundles: pdo_mysql, mysqlnd, pdo_sqlite, redis, and
#            swoole compiled WITH coroutine_pgsql (libpq). See notes per-DB below.
#
# After editing, rebuild:  docker compose build   (or: docker build ...)
# =============================================================================
set -e

# -----------------------------------------------------------------------------
# 1) PostgreSQL  (pdo_pgsql + pgsql)
#    swoole: the coroutine PG engine is already compiled into swoole, but the
#    PDO pgsql DRIVER is NOT bundled — install it. Once present, SWOOLE_HOOK_PDO_PGSQL
#    (active in the framework's hook mask) makes it non-blocking automatically.
# -----------------------------------------------------------------------------
# if [ "$RUNTIME" = "fpm" ]; then
#     apk add --no-cache php85-pgsql php85-pdo_pgsql
# else
#     apk add --no-cache libpq \
#         && apk add --no-cache --virtual .pg-deps postgresql-dev \
#         && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql \
#         && apk del .pg-deps
# fi

# -----------------------------------------------------------------------------
# 2) MySQL / MariaDB  (pdo_mysql + mysqli)
#    swoole: pdo_mysql + mysqlnd are ALREADY in the base (coroutine-safe via the
#    mysqlnd/stream hooks) — nothing to install unless you specifically need mysqli.
# -----------------------------------------------------------------------------
# if [ "$RUNTIME" = "fpm" ]; then
#     apk add --no-cache php85-pdo_mysql php85-mysqli php85-mysqlnd
# else
#     docker-php-ext-install -j"$(nproc)" mysqli    # pdo_mysql already present
# fi

# -----------------------------------------------------------------------------
# 3) Redis
#    swoole: phpredis is ALREADY in the base and is coroutinized transparently by
#    Swoole's socket hooks (Swoole 6 dropped the old SWOOLE_HOOK_REDIS constant —
#    the plain phpredis client just works). Nothing to install.
# -----------------------------------------------------------------------------
# if [ "$RUNTIME" = "fpm" ]; then
#     apk add --no-cache php85-pecl-redis
# fi

# -----------------------------------------------------------------------------
# 4) Cron  (busybox crond — ALREADY in both bases, no package to install)
#    crond runs as root and executes each crontab as the user matching its
#    FILENAME, so a `winter` crontab runs jobs as winter — no su-exec/chpst needed.
#    The crontab is identical for both runtimes; only HOW crond starts differs:
#      fpm    → runit service (below, build-time)
#      swoole → a `crond` line in docker/swoole/entrypoint.sh (runtime)
# -----------------------------------------------------------------------------
# mkdir -p /etc/crontabs
# cat > /etc/crontabs/winter <<'CRON'
# 0  1 * * * cd /var/www/html && php call storage clean -c
# 0  1 * * * cd /var/www/html && php call sc io.scripts.orphanFolderGc
# 30 1 * * * cd /var/www/html && php call sc io.scripts.orphanBlobGc
# CRON
#
# if [ "$RUNTIME" = "fpm" ]; then
#     # fpm has runit → supervise crond as a service (auto-starts with the rest).
#     # -f = foreground (required so runit keeps it supervised).
#     mkdir -p /etc/service/cron
#     printf '#!/bin/sh\nexec crond -f -l 8\n' > /etc/service/cron/run
#     chmod +x /etc/service/cron/run
# fi
# # swoole has NO supervisor → uncomment the `crond` line in
# #   docker/swoole/entrypoint.sh   (crond backgrounds itself, then the server execs)

# -----------------------------------------------------------------------------
# 5) Anything else — timezone, CA certs, CLI tools, PHP ini tweaks, ...
# -----------------------------------------------------------------------------
# apk add --no-cache tzdata \
#     && cp /usr/share/zoneinfo/Asia/Tashkent /etc/localtime \
#     && echo "Asia/Tashkent" > /etc/timezone
