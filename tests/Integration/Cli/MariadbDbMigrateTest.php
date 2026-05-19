<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Cli;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MariadbDbMigrateTest extends DbMigrateTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mariadb';
    }

    protected static function fixturePath(): string
    {
        return __DIR__ . '/Fixtures/Mariadb';
    }
}
