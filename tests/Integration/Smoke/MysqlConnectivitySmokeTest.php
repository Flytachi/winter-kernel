<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Smoke;

use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\MysqlTestDbConfig;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MysqlConnectivitySmokeTest extends IntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mysql';
    }

    public function test_database_was_created_for_this_class(): void
    {
        $pdo = self::rawPdo();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.schemata WHERE schema_name = :n'
        );
        $stmt->execute([':n' => self::$schemaName]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_pool_returns_real_mysql_connection(): void
    {
        $cdo = PpaConnectionPool::db(MysqlTestDbConfig::class);
        self::assertSame('mysql', $cdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame(1, (int) $cdo->query('SELECT 1')->fetchColumn());
    }

    public function test_pool_uses_per_class_database(): void
    {
        $cdo = PpaConnectionPool::db(MysqlTestDbConfig::class);
        $current = (string) $cdo->query('SELECT DATABASE()')->fetchColumn();
        self::assertSame(self::$schemaName, $current);
    }
}
