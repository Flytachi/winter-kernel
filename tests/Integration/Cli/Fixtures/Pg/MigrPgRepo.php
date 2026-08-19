<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\Pg;

use Flytachi\Winter\Ppa\Stereotype\RepositoryView;
use Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\MigrEntity;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\PgTestDbConfig;

final class MigrPgRepo extends RepositoryView
{
    protected string $dbConfigClassName = PgTestDbConfig::class;
    protected string $entityClassName = MigrEntity::class;
    public static string $table = 'migr_widgets';
}
