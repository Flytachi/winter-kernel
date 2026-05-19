#!/usr/bin/env bash
#
# Runs the Ppa integration test suite against local Docker containers.
# Brings the containers up, waits for healthchecks, exports DSNs, runs phpunit.
# Tear-down on exit is best-effort — use `docker compose down -v` manually
# if you want a clean slate.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/tests/Integration/docker-compose.test.yml"

cleanup() {
    docker compose -f "${COMPOSE_FILE}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[1/3] Starting database containers…"
docker compose -f "${COMPOSE_FILE}" up -d --wait

echo "[2/3] Containers are healthy. Exporting DSNs."
export PG_TEST_DSN="pgsql:host=127.0.0.1;port=55432;dbname=winter_test"
export PG_TEST_USER="postgres"
export PG_TEST_PASS="test"

export MYSQL_TEST_DSN="mysql:host=127.0.0.1;port=53306;dbname=winter_test"
export MYSQL_TEST_USER="root"
export MYSQL_TEST_PASS="test"

export MARIADB_TEST_DSN="mysql:host=127.0.0.1;port=53307;dbname=winter_test"
export MARIADB_TEST_USER="root"
export MARIADB_TEST_PASS="test"

echo "[3/3] Running Integration suite…"
"${ROOT_DIR}/vendor/bin/phpunit" --group integration "$@"
