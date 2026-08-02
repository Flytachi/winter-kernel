<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\Mariadb;

use Flytachi\Winter\Kernel\Ppa\Stereotype\RepositoryView;
use Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\MigrEntity;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\MariadbTestDbConfig;

final class MigrMariadbRepo extends RepositoryView
{
    protected string $dbConfigClassName = MariadbTestDbConfig::class;
    protected string $entityClassName = MigrEntity::class;
    public static string $table = 'migr_widgets';
}
