<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Cli\Fixtures\Mariadb;

use Flytachi\Winter\K2\Ppa\Stereotype\RepositoryView;
use Flytachi\Winter\K2\Tests\Integration\Cli\Fixtures\MigrEntity;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\MariadbTestDbConfig;

final class MigrMariadbRepo extends RepositoryView
{
    protected string $dbConfigClassName = MariadbTestDbConfig::class;
    protected string $entityClassName = MigrEntity::class;
    public static string $table = 'migr_widgets';
}
