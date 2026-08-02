<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures;

use Flytachi\Winter\Kernel\Ppa\Stereotype\RepositoryView;

final class TypedUsersRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    protected string $entityClassName = UserEntity::class;
    public static string $table = 'users';
}
