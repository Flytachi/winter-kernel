<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Smoke;

use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\PgTestDbConfig;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class PgConnectivitySmokeTest extends IntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'pgsql';
    }

    public function test_schema_was_created_for_this_class(): void
    {
        $pdo = self::rawPdo();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.schemata WHERE schema_name = :n'
        );
        $stmt->execute([':n' => self::$schemaName]);
        self::assertSame(1, (int) $stmt->fetchColumn());
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
}
