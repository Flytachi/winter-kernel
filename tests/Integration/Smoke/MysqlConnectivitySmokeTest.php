<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Smoke;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\IntegrationTestCase;
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

    public function test_server_version_reports_mysql_signature(): void
    {
        $version = (string) self::rawPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        self::assertNotEmpty($version);
        // MySQL versions look like "8.0.34" — no "MariaDB" marker.
        self::assertStringNotContainsStringIgnoringCase('mariadb', $version);
    }
}
