<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Crud;

use Flytachi\Winter\K2\Tests\Integration\Fixtures\ProductMariadbRepo;
use PHPUnit\Framework\Attributes\Group;



/**
 * MariaDB CRUD — tagged `pool` (non-blocking) until the framework-level
 * CDO/MariaDB segfault is resolved. CRUD operations exercise the same
 * CDO::__construct path that crashes the MariaDB pool smoke test, so for
 * now this class is run via `--group pool` (continue-on-error in CI).
 *
 * Once the CDO bug is fixed: change the group tag to `integration` to
 * make MariaDB CRUD blocking like the others.
 */
#[Group('pool')]
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
