<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Types;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\SpecimenMariadbRepo;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MariadbTypesTest extends TypesIntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mariadb';
    }

    protected static function repoClass(): string
    {
        return SpecimenMariadbRepo::class;
    }
}
