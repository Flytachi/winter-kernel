<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures;

use Flytachi\Winter\Kernel\Ppa\Stereotype\RepositoryView;

/**
 * Plain repo with no typed entity — buildSql() will emit `SELECT *`.
 */
final class UsersRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    public static string $table = 'users';
}
