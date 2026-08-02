<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Pool;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\MysqlTestDbConfig;
use PHPUnit\Framework\Attributes\Group;

#[Group('cdo-diagnostic')]
final class CdoMysqlSegfaultDiagnosticTest extends CdoSegfaultDiagnosticTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mysql';
    }

    protected static function dsnTriplet(): array
    {
        return [
            (string) getenv('MYSQL_TEST_DSN'),
            (string) (getenv('MYSQL_TEST_USER') ?: 'root'),
            (string) (getenv('MYSQL_TEST_PASS') ?: ''),
        ];
    }

    protected static function dbConfigClass(): string
    {
        return MysqlTestDbConfig::class;
    }
}
