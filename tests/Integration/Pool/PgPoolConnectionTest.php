<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Pool;

use Flytachi\Winter\Kernel\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\IntegrationTestCase;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\PgTestDbConfig;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies the framework's PpaConnectionPool → CDO path against a real
 * PostgreSQL. Tagged with the `pool` group (excluded by default) so any
 * crash here doesn't abort the whole integration run.
 */
#[Group('pool')]
final class PgPoolConnectionTest extends IntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'pgsql';
    }

    public function test_pool_returns_real_pgsql_connection(): void
    {
        $cdo = PpaConnectionPool::db(PgTestDbConfig::class);
        self::assertSame('pgsql', $cdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame(1, (int) $cdo->query('SELECT 1')->fetchColumn());
    }

    public function test_config_reports_per_class_schema(): void
    {
        $cfg = PpaConnectionPool::getConfigDb(PgTestDbConfig::class);
        self::assertSame(self::$schemaName, $cfg->getSchema());
    }

    public function test_pool_returns_same_cdo_on_repeated_calls(): void
    {
        $a = PpaConnectionPool::db(PgTestDbConfig::class);
        $b = PpaConnectionPool::db(PgTestDbConfig::class);
        self::assertSame($a, $b, 'FPM path must singleton the CDO per config class');
    }
}
