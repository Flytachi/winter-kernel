<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\View;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\ProductPgRepo;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class PgViewTest extends ViewIntegrationTestCase
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
