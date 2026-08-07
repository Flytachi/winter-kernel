<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Types;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\SpecimenPgRepo;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class PgTypesTest extends TypesIntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'pgsql';
    }

    protected static function repoClass(): string
    {
        return SpecimenPgRepo::class;
    }
}
