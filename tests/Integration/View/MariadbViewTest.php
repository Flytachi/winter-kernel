<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\View;

use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\ProductMariadbRepo;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MariadbViewTest extends ViewIntegrationTestCase
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
