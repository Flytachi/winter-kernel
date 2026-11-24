<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\MariadbGinIndex;

use Flytachi\Winter\Kernel\Tests\Integration\Cli\Fixtures\GinIndexEntity;
use Flytachi\Winter\Kernel\Tests\Integration\Fixtures\MariadbTestDbConfig;
use Flytachi\Winter\Ppa\Stereotype\RepositoryView;

final class GinIndexMariadbRepo extends RepositoryView
{
    protected string $dbConfigClassName = MariadbTestDbConfig::class;
    protected string $entityClassName = GinIndexEntity::class;
    public static string $table = 'migr_ginix';
}
