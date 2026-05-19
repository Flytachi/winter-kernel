<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Pool;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\MariadbTestDbConfig;
use PHPUnit\Framework\Attributes\Group;

#[Group('pool')]
final class MariadbPoolConnectionTest extends MysqlPoolConnectionTest
{
    protected static function driverFlavour(): string
    {
        return 'mariadb';
    }

    protected static function dbConfigClass(): string
    {
        return MariadbTestDbConfig::class;
    }
}
