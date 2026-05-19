<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\View;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\ProductMysqlRepo;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MysqlViewTest extends ViewIntegrationTestCase
{
    protected static function driverFlavour(): string
    {
        return 'mysql';
    }

    protected static function repoClass(): string
    {
        return ProductMysqlRepo::class;
    }
}
