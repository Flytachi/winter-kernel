<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Migration;

use PHPUnit\Framework\Attributes\Group;

/**
 * MariaDB Migration E2E — inherits all test methods from MysqlMigrationE2ETest;
 * only overrides driverFlavour() so PpaConnectionPool routes to the MariaDB DSN
 * and IntegrationTestCase opens/closes the per-class database against MariaDB.
 *
 * Identifies framework-level divergence between MySQL 8 and MariaDB 10.11 —
 * both report PDO driver 'mysql' so the unit layer cannot distinguish them.
 */
#[Group('integration')]
final class MariadbMigrationE2ETest extends MysqlMigrationE2ETest
{
    protected static function driverFlavour(): string
    {
        return 'mariadb';
    }
}
