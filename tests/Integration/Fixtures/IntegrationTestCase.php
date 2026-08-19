<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Fixtures;

use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Base test case for integration tests against a real database.
 *
 * Subclasses pick a driver flavour (pgsql / mysql / mariadb) and the
 * lifecycle methods open a per-class schema or database so that
 * concurrent test classes never see each other's state.
 *
 * Conventions:
 * - The required `<DRIVER>_TEST_DSN` env var must be set. Otherwise the
 *   whole test class is skipped (markTestSkipped in setUpBeforeClass).
 * - PpaConnectionPool's static caches are wiped before each class to
 *   guarantee a fresh config instance is created with the per-class schema.
 * - The schema name is `wk_<12 hex chars of md5(class)>` — stable per class,
 *   short enough to fit inside identifier length limits on every driver.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Cached, per-class. Set in setUpBeforeClass(), read by DbConfig subclasses
     * during PpaConnectionPool::getConfigDb() → setUp().
     *
     * Public because PgTestDbConfig / MysqlTestDbConfig / MariadbTestDbConfig
     * live outside this class hierarchy and need read access.
     */
    public static string $schemaName = '';

    /**
     * Returns 'pgsql' | 'mysql' | 'mariadb'. The base class reads this to
     * decide which env vars and skip rule apply.
     */
    abstract protected static function driverFlavour(): string;

    public static function setUpBeforeClass(): void
    {
        self::resetConnectionPool();
        self::skipIfDriverNotConfigured();
        self::$schemaName = self::computeSchemaName();
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$schemaName !== '') {
            self::dropSchema();
        }
        self::resetConnectionPool();
        self::$schemaName = '';
    }

    // ── Skip logic ───────────────────────────────────────────────────────────

    protected static function skipIfDriverNotConfigured(): void
    {
        $envVar = match (static::driverFlavour()) {
            'pgsql'   => 'PG_TEST_DSN',
            'mysql'   => 'MYSQL_TEST_DSN',
            'mariadb' => 'MARIADB_TEST_DSN',
            default   => throw new \LogicException('Unknown flavour: ' . static::driverFlavour()),
        };
        if (getenv($envVar) === false) {
            self::markTestSkipped("Integration test skipped — set {$envVar} to enable.");
        }
    }

    // ── Schema lifecycle ─────────────────────────────────────────────────────

    protected static function computeSchemaName(): string
    {
        return 'wk_' . substr(md5(static::class), 0, 12);
    }

    protected static function createSchema(): void
    {
        $pdo = self::rawPdo();
        $name = self::quoteSchemaName(self::$schemaName);
        // IF NOT EXISTS — PHPUnit re-invokes setUpBeforeClass inside each
        // RunInSeparateProcess child, so the second call to createSchema()
        // must be a no-op rather than fail.
        $stmt = match (static::driverFlavour()) {
            'pgsql' => "CREATE SCHEMA IF NOT EXISTS {$name}",
            'mysql', 'mariadb' => "CREATE DATABASE IF NOT EXISTS {$name} DEFAULT CHARACTER SET utf8mb4",
        };
        $pdo->exec($stmt);
    }

    protected static function dropSchema(): void
    {
        try {
            $pdo = self::rawPdo();
            $name = self::quoteSchemaName(self::$schemaName);
            $stmt = match (static::driverFlavour()) {
                'pgsql' => "DROP SCHEMA IF EXISTS {$name} CASCADE",
                'mysql', 'mariadb' => "DROP DATABASE IF EXISTS {$name}",
            };
            $pdo->exec($stmt);
        } catch (\Throwable) {
            // best-effort teardown — if connection is broken, leave it.
        }
    }

    protected static function quoteSchemaName(string $name): string
    {
        return match (static::driverFlavour()) {
            'pgsql' => '"' . $name . '"',
            'mysql', 'mariadb' => '`' . $name . '`',
        };
    }

    // ── PDO helpers ──────────────────────────────────────────────────────────

    /**
     * Opens a raw PDO scoped to the per-class schema/database.
     *
     * - pgsql: connects to the base DB and sets `search_path` to the test schema,
     *   so unqualified table names resolve there.
     * - mysql/mariadb: connects to the base DB then `USE <test_db>`, so unqualified
     *   table DDL lands inside the per-class database (MySQL "schema" ≡ "database").
     *
     * Used by integration tests that need to run DDL/DML without prefixing every
     * statement with the per-class schema name.
     */
    protected static function pdoOnTestSchema(): \PDO
    {
        $pdo = self::rawPdo();
        if (self::$schemaName === '') {
            return $pdo;
        }
        $stmt = match (static::driverFlavour()) {
            'pgsql' => 'SET search_path TO "' . self::$schemaName . '"',
            'mysql', 'mariadb' => 'USE `' . self::$schemaName . '`',
        };
        $pdo->exec($stmt);
        return $pdo;
    }

    /**
     * Opens a raw PDO connection bypassing the framework's pool.
     * Used for schema lifecycle DDL (CREATE/DROP database) and for introspection
     * queries against `information_schema` (which is reachable from any DB).
     */
    protected static function rawPdo(): \PDO
    {
        [$dsn, $user, $pass] = match (static::driverFlavour()) {
            'pgsql' => [
                (string) getenv('PG_TEST_DSN'),
                (string) (getenv('PG_TEST_USER') ?: 'postgres'),
                (string) (getenv('PG_TEST_PASS') ?: ''),
            ],
            'mysql' => [
                (string) getenv('MYSQL_TEST_DSN'),
                (string) (getenv('MYSQL_TEST_USER') ?: 'root'),
                (string) (getenv('MYSQL_TEST_PASS') ?: ''),
            ],
            'mariadb' => [
                (string) getenv('MARIADB_TEST_DSN'),
                (string) (getenv('MARIADB_TEST_USER') ?: 'root'),
                (string) (getenv('MARIADB_TEST_PASS') ?: ''),
            ],
        };

        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    // ── Pool reset between test classes ──────────────────────────────────────

    /**
     * Wipes PpaConnectionPool's static caches so the next test class instantiates
     * a fresh config with its own per-class schema.
     */
    protected static function resetConnectionPool(): void
    {
        $ref = new ReflectionClass(PpaConnectionPool::class);
        foreach (['pools', 'configs', 'static'] as $prop) {
            $p = $ref->getProperty($prop);
            $p->setValue(null, []);
        }
    }
}
