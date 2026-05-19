<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Types;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\SpecimenMysqlRepo;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MysqlTypesTest extends TypesIntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mysql';
    }

    protected static function repoClass(): string
    {
        return SpecimenMysqlRepo::class;
    }
}
