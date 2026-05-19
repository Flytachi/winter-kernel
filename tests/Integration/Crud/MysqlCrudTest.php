<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Crud;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\ProductMysqlRepo;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tagged `pool` (non-blocking) — MySQL 8.0 + PHP 8.4 + pdo_mysql + CDO::__construct
 * triggers a segfault on the first `PpaConnectionPool::db()` call. Same issue
 * MariaDB exhibits. PgCrudTest stays `integration` (blocking) so PG side of the
 * framework still has a passing-or-failing signal.
 *
 * Once the CDO segfault is fixed → change group to `integration`.
 */
#[Group('pool')]
final class MysqlCrudTest extends CrudIntegrationTestCase
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
