<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Ppa\Repository\Fixtures;

use Flytachi\Winter\K2\Ppa\Stereotype\RepositoryView;

final class TypedUsersRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    protected string $entityClassName = UserEntity::class;
    public static string $table = 'users';
}
