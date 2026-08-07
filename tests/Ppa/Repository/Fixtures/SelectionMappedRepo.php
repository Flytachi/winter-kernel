<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures;

use Flytachi\Winter\Kernel\Ppa\Stereotype\RepositoryView;

final class SelectionMappedRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    protected string $entityClassName = UserWithSelectionEntity::class;
    public static string $table = 'users';
}
