<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Repository\Fixtures;

use Flytachi\Winter\Kernel\Ppa\Stereotype\RepositoryView;

final class OrdersRepo extends RepositoryView
{
    protected string $dbConfigClassName = RepoTestDbConfig::class;
    public static string $table = 'orders';
}
