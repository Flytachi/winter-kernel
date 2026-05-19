<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Types;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\SpecimenMariadbRepo;
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
