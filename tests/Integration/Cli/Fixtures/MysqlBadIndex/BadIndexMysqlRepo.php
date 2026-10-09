<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\MysqlBadIndex;

use Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\BadIndexEntity;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\MysqlTestDbConfig;
use Flytachi\Winter\Ppa\Stereotype\RepositoryView;

final class BadIndexMysqlRepo extends RepositoryView
{
    protected string $dbConfigClassName = MysqlTestDbConfig::class;
    protected string $entityClassName = BadIndexEntity::class;
    public static string $table = 'migr_badix';
}
