<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Fixtures;

use Flytachi\Winter\Cdo\Config\PgDbConfig;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Config\Extension;
use Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Config\Migratable;

/**
 * PostgreSQL configuration driven entirely by env vars.
 *
 * The schema is taken from {@see IntegrationTestCase::$schemaName} so each
 * test class lives inside its own PG schema (created by the test base class).
 *
 * `#[Migratable]` + `#[Extension('pgcrypto')]` enable the Cli Db-command
 * E2E tests (Phase C.5). Other test suites that touch this config don't
 * care about these attributes.
 *
 * Required env: `PG_TEST_DSN` — full PDO DSN like
 * `pgsql:host=127.0.0.1;port=55432;dbname=winter_test`.
 * Optional: `PG_TEST_USER`, `PG_TEST_PASS`.
 */
#[Migratable]
#[Extension('pgcrypto')]
final class PgTestDbConfig extends PgDbConfig
{
    public function setUp(): void
    {
        $dsn = (string) getenv('PG_TEST_DSN');

        // Parse host/port/dbname out of the DSN so getDns()'s default builder
        // can re-construct it. Cheaper than overriding getDns().
        $parts = self::parseDsn($dsn);
        $this->host     = $parts['host'] ?? 'localhost';
        $this->port     = (int) ($parts['port'] ?? 5432);
        $this->database = $parts['dbname'] ?? 'postgres';
        $this->username = (string) (getenv('PG_TEST_USER') ?: 'postgres');
        $this->password = (string) (getenv('PG_TEST_PASS') ?: '');
        $this->schema   = IntegrationTestCase::$schemaName !== ''
            ? IntegrationTestCase::$schemaName
            : 'public';
    }

    /** @return array<string, string> */
    private static function parseDsn(string $dsn): array
    {
        $result = [];
        $body = preg_replace('/^[a-z]+:/i', '', $dsn) ?? '';
        foreach (explode(';', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $result[trim($k)] = trim($v);
        }
        return $result;
    }
}
