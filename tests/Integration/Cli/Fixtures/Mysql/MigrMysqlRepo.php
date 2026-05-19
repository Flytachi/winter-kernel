<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Integration\Cli\Fixtures\Mysql;

use Flytachi\Winter\K2\Ppa\Stereotype\RepositoryView;
use Flytachi\Winter\K2\Tests\Integration\Cli\Fixtures\MigrEntity;
use Flytachi\Winter\K2\Tests\Integration\Fixtures\MysqlTestDbConfig;

final class MigrMysqlRepo extends RepositoryView
{
    protected string $dbConfigClassName = MysqlTestDbConfig::class;
    protected string $entityClassName = MigrEntity::class;
    public static string $table = 'migr_widgets';
}
