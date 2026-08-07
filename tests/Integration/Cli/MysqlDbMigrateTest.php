<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MysqlDbMigrateTest extends DbMigrateTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mysql';
    }

    protected static function fixturePath(): string
    {
        return __DIR__ . '/Fixtures/Mysql';
    }
}
