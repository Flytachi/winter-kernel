#!/usr/bin/env bash
#
# Local-development convenience: brings up Postgres/MySQL/MariaDB containers,
# exports the DSNs that IntegrationTestCase reads, runs the requested phpunit
# groups, then tears the containers down.
#
# Usage:
#   tests/run-integration.sh                       # --group integration (default)
#   tests/run-integration.sh --group cdo-diagnostic
#   tests/run-integration.sh --group pool
#   tests/run-integration.sh --group integration --filter PgCrudTest
#
# Anything you pass after the script name is forwarded to phpunit verbatim.

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

echo "[3/3] Running PHPUnit (default: --group integration; pass any phpunit args to override)…"

# IMPORTANT: phpunit reads phpunit.xml from CWD. Run from project root so the
# configuration (suites, bootstrap, group excludes) is picked up correctly.
cd "${ROOT_DIR}"

if [ "$#" -eq 0 ]; then
    set -- --group integration
fi

"${ROOT_DIR}/vendor/bin/phpunit" "$@"
