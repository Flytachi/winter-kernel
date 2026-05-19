<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Pool;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\MariadbTestDbConfig;
use PHPUnit\Framework\Attributes\Group;

#[Group('cdo-diagnostic')]
final class CdoMariadbSegfaultDiagnosticTest extends CdoSegfaultDiagnosticTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mariadb';
    }

    protected static function dsnTriplet(): array
    {
        return [
            (string) getenv('MARIADB_TEST_DSN'),
            (string) (getenv('MARIADB_TEST_USER') ?: 'root'),
            (string) (getenv('MARIADB_TEST_PASS') ?: ''),
        ];
    }

    protected static function dbConfigClass(): string
    {
        return MariadbTestDbConfig::class;
    }
}
