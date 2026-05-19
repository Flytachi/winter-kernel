<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Pool;

use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\MysqlTestDbConfig;
use PHPUnit\Framework\Attributes\Group;

#[Group('pool')]
class MysqlPoolConnectionTest extends IntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mysql';
    }

    /**
     * Returns the DbConfig class to use. Subclasses (MariaDB) override.
     *
     * @return class-string<\Flytachi\Winter\Cdo\Config\Common\DbConfigInterface>
     */
    protected static function dbConfigClass(): string
    {
        return MysqlTestDbConfig::class;
    }

    public function test_pool_returns_real_connection(): void
    {
        $cdo = PpaConnectionPool::db(static::dbConfigClass());
        self::assertSame('mysql', $cdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame(1, (int) $cdo->query('SELECT 1')->fetchColumn());
    }

    public function test_pool_uses_per_class_database(): void
    {
        $cdo = PpaConnectionPool::db(static::dbConfigClass());
        $current = (string) $cdo->query('SELECT DATABASE()')->fetchColumn();
        self::assertSame(self::$schemaName, $current);
    }

    public function test_pool_singleton_per_config_class(): void
    {
        $a = PpaConnectionPool::db(static::dbConfigClass());
        $b = PpaConnectionPool::db(static::dbConfigClass());
        self::assertSame($a, $b);
    }
}
