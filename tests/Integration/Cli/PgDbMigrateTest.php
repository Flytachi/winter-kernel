<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class PgDbMigrateTest extends DbMigrateTestCase
{
    protected static function driverFlavour(): string
    {
        return 'pgsql';
    }

    protected static function fixturePath(): string
    {
        return __DIR__ . '/Fixtures/Pg';
    }

    public function test_pgsql_extension_pgcrypto_is_installed(): void
    {
        $this->runDbMigrate();

        $stmt = self::pdoOnTestSchema()->prepare('SELECT 1 FROM pg_extension WHERE extname = :n');
        $stmt->execute([':n' => 'pgcrypto']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }
}
