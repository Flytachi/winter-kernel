<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Smoke;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Smoke check — verifies the test env can reach MariaDB and a per-class
 * database is created. Uses rawPdo only; the framework's CDO/Pool path is
 * isolated to PoolConnectionTest (group 'pool', off by default) so that
 * any segfault there cannot abort the whole smoke pipeline.
 */
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

    public function test_server_version_signature_contains_mariadb(): void
    {
        $version = (string) self::rawPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        self::assertStringContainsStringIgnoringCase('mariadb', $version);
    }
}
