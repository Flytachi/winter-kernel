<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Fixtures;

use Flytachi\Winter\Ppa\Stereotype\Repository;

final class ProductPgRepo extends Repository
{
    protected string $dbConfigClassName = PgTestDbConfig::class;
    protected string $entityClassName = ProductEntity::class;
    public static string $table = 'products';
}
