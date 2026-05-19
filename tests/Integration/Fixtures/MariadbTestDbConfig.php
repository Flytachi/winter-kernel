<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Fixtures;

use Flytachi\Winter\Cdo\Config\MySqlDbConfig;

/**
 * MariaDB configuration — separate from MysqlTestDbConfig only so the pool
 * can cache each independently (keyed by class name) and so a single test
 * matrix can exercise both flavours.
 *
 * Required env: `MARIADB_TEST_DSN`. Optional: `MARIADB_TEST_USER`, `MARIADB_TEST_PASS`.
 */
final class MariadbTestDbConfig extends MySqlDbConfig
{
    public function setUp(): void
    {
        $dsn = (string) getenv('MARIADB_TEST_DSN');
        $parts = self::parseDsn($dsn);

        $this->host     = $parts['host'] ?? 'localhost';
        $this->port     = (int) ($parts['port'] ?? 3306);
        $this->database = IntegrationTestCase::$schemaName !== ''
            ? IntegrationTestCase::$schemaName
            : ($parts['dbname'] ?? 'winter_test');
        $this->username = (string) (getenv('MARIADB_TEST_USER') ?: 'root');
        $this->password = (string) (getenv('MARIADB_TEST_PASS') ?: '');
    }

    final public function getDriver(): string
    {
        return 'mysql'; // PDO returns 'mysql' for MariaDB too — framework parity.
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
