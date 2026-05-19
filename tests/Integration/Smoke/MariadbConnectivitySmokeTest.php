<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Smoke;

use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\MariadbTestDbConfig;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MariadbConnectivitySmokeTest extends IntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mariadb';
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

    public function test_pool_returns_real_mariadb_connection(): void
    {
        $cdo = PpaConnectionPool::db(MariadbTestDbConfig::class);
        // PDO reports 'mysql' for MariaDB — framework parity.
        self::assertSame('mysql', $cdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame(1, (int) $cdo->query('SELECT 1')->fetchColumn());
    }

    public function test_server_version_signature_contains_mariadb(): void
    {
        $cdo = PpaConnectionPool::db(MariadbTestDbConfig::class);
        $version = (string) $cdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        // MariaDB encodes "MariaDB" or "-MariaDB-" in its version string.
        self::assertStringContainsStringIgnoringCase('mariadb', $version);
    }
}
