<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Fixtures;

use Flytachi\Winter\Cdo\Config\MySqlDbConfig;

/**
 * MySQL configuration driven by env vars.
 *
 * `database` is overridden with {@see IntegrationTestCase::$schemaName} so
 * each test class points at its own per-class database (created by the base
 * class). MySQL "schema" ≡ "database" in PDO; framework treats both as 'mysql'
 * via getDriver().
 *
 * Required env: `MYSQL_TEST_DSN`. Optional: `MYSQL_TEST_USER`, `MYSQL_TEST_PASS`.
 */
final class MysqlTestDbConfig extends MySqlDbConfig
{
    public function setUp(): void
    {
        $dsn = (string) getenv('MYSQL_TEST_DSN');
        $parts = self::parseDsn($dsn);

        $this->host     = $parts['host'] ?? 'localhost';
        $this->port     = (int) ($parts['port'] ?? 3306);
        $this->database = IntegrationTestCase::$schemaName !== ''
            ? IntegrationTestCase::$schemaName
            : ($parts['dbname'] ?? 'winter_test');
        $this->username = (string) (getenv('MYSQL_TEST_USER') ?: 'root');
        $this->password = (string) (getenv('MYSQL_TEST_PASS') ?: '');
    }
    // getDriver() is inherited as `final` from MySqlDbConfig — returns 'mysql'.

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
