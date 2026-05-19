<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Fixtures;

use Flytachi\Winter\K2\Ppa\Stereotype\Repository;

final class ProductMysqlRepo extends Repository
{
    protected string $dbConfigClassName = MysqlTestDbConfig::class;
    protected string $entityClassName = ProductEntity::class;
    public static string $table = 'products';
}
