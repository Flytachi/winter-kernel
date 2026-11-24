<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\MysqlGinIndex;

use Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\GinIndexEntity;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\MysqlTestDbConfig;
use Flytachi\Winter\Ppa\Stereotype\RepositoryView;

final class GinIndexMysqlRepo extends RepositoryView
{
    protected string $dbConfigClassName = MysqlTestDbConfig::class;
    protected string $entityClassName = GinIndexEntity::class;
    public static string $table = 'migr_ginix';
}
