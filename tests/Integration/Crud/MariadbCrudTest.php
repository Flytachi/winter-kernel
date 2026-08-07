<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Crud;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\ProductMariadbRepo;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MariadbCrudTest extends CrudIntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mariadb';
    }

    protected static function repoClass(): string
    {
        return ProductMariadbRepo::class;
    }
}
