<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Smoke;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\IntegrationTestCase;
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

    public function test_server_version_is_postgres(): void
    {
        $version = (string) self::rawPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        self::assertNotEmpty($version);
    }
}
