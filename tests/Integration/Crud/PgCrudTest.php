<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Crud;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\ProductPgRepo;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class PgCrudTest extends CrudIntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'pgsql';
    }

    protected static function repoClass(): string
    {
        return ProductPgRepo::class;
    }
}
